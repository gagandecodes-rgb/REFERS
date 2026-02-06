<?php
error_reporting(0);
ini_set("display_errors",0);

define("TG_CONNECT_TIMEOUT",2);
define("TG_TIMEOUT",6);

/* ================= ENV ================= */
$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_ID  = getenv("ADMIN_ID");
$BOT_USERNAME = getenv("BOT_USERNAME");

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

$API = "https://api.telegram.org/bot{$BOT_TOKEN}";
if(!$BOT_TOKEN){ http_response_code(200); exit; }

/* ================= DB ================= */
try{
  $pdo=new PDO(
    "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;sslmode=require",
    $DB_USER,$DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
  );
}catch(Exception $e){ http_response_code(200); exit; }

/* ================= TG HELPERS ================= */
function tg($m,$d=[]){
  global $API;
  $c=curl_init("$API/$m");
  curl_setopt_array($c,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$d,
    CURLOPT_CONNECTTIMEOUT=>TG_CONNECT_TIMEOUT,
    CURLOPT_TIMEOUT=>TG_TIMEOUT
  ]);
  $r=curl_exec($c);
  curl_close($c);
  return $r?json_decode($r,true):null;
}
function send($cid,$txt,$kb=null){
  $d=["chat_id"=>$cid,"text"=>$txt,"parse_mode"=>"HTML","disable_web_page_preview"=>true];
  if($kb) $d["reply_markup"]=json_encode($kb);
  tg("sendMessage",$d);
}
function answer($id,$t="",$a=false){
  tg("answerCallbackQuery",["callback_query_id"=>$id,"text"=>$t,"show_alert"=>$a?"true":"false"]);
}
function isAdmin($id){ global $ADMIN_ID; return (string)$id===(string)$ADMIN_ID; }

/* ================= FORCE JOIN ================= */
function channels(){
  return array_filter([
    getenv("FORCE_JOIN_1"),
    getenv("FORCE_JOIN_2"),
    getenv("FORCE_JOIN_3"),
    getenv("FORCE_JOIN_4")
  ]);
}
function checkMember($uid,$ch){
  $r=tg("getChatMember",["chat_id"=>$ch,"user_id"=>$uid]);
  $s=$r["result"]["status"]??"";
  return in_array($s,["member","administrator","creator"],true);
}
function allJoined($uid){
  foreach(channels() as $c){
    if(!$c) continue;
    if(!checkMember($uid,$c)) return false;
  }
  return true;
}

/* ================= DB HELPERS ================= */
function user($id){
  global $pdo;
  $s=$pdo->prepare("SELECT * FROM users WHERE tg_id=?");
  $s->execute([$id]);
  return $s->fetch();
}
function ensureUser($id,$ref=null){
  global $pdo;
  if(!user($id)){
    $pdo->prepare("INSERT INTO users (tg_id,referred_by) VALUES (?,?)")->execute([$id,$ref]);
    if($ref){
      $pdo->prepare("UPDATE users SET points=points+1,total_referrals=total_referrals+1 WHERE tg_id=?")
          ->execute([$ref]);
    }
  }
}
function verified($id){
  $u=user($id);
  return $u && $u["verified"];
}
function getWithdrawPoints($amount){
  global $pdo;
  $s=$pdo->prepare("SELECT points FROM withdraw_points WHERE amount=?");
  $s->execute([$amount]);
  $r=$s->fetch();
  return $r?(int)$r["points"]:0;
}

/* ================= MENUS ================= */
function mainMenu($admin=false){
  $r=[
    [["text"=>"📊 Stats","callback_data"=>"stats"],["text"=>"🎁 Withdraw","callback_data"=>"withdraw"]],
    [["text"=>"🔗 My Referral Link","callback_data"=>"reflink"]]
  ];
  if($admin) $r[]=[["text"=>"🛠 Admin Panel","callback_data"=>"admin_panel"]];
  return ["inline_keyboard"=>$r];
}
function adminMenu(){
  return ["inline_keyboard"=>[
    [["text"=>"➕ Add Coupon","callback_data"=>"admin_add_coupon"],["text"=>"📦 Coupon Stock","callback_data"=>"admin_stock"]],
    [["text"=>"🗂 Redeem Logs","callback_data"=>"admin_redeems"]],
    [["text"=>"⚙ Change Withdraw Points","callback_data"=>"admin_points"]],
    [["text"=>"⬅ Back","callback_data"=>"back_main"]]
  ]];
}

/* ================= STATE ================= */
function stateDir(){ $d=__DIR__."/state"; if(!is_dir($d)) mkdir($d,0777,true); return $d; }
function setState($u,$s){ file_put_contents(stateDir()."/$u",$s); }
function getState($u){ $f=stateDir()."/$u"; return file_exists($f)?trim(file_get_contents($f)):""; }
function clearState($u){ $f=stateDir()."/$u"; if(file_exists($f)) unlink($f); }

/* ================= WEBHOOK ================= */
$up=json_decode(file_get_contents("php://input"),true);
if(!$up){ http_response_code(200); exit; }

/* ---------- MESSAGE ---------- */
if(isset($up["message"])){
  $m=$up["message"];
  $cid=$m["chat"]["id"];
  $uid=$m["from"]["id"];
  $txt=trim($m["text"]??"");

  if(strpos($txt,"/start")===0){
    $ref=null;
    $p=explode(" ",$txt);
    if(isset($p[1])&&ctype_digit($p[1])) $ref=(int)$p[1];
    ensureUser($uid,$ref && $ref!=$uid?$ref:null);

    if(verified($uid)) send($cid,"🏠 Main Menu:",mainMenu(isAdmin($uid)));
    else send($cid,"Join channels then verify.",["inline_keyboard"=>[[["text"=>"✅ Check Verification","callback_data"=>"check_join"]]]]);
    exit;
  }

  if(isAdmin($uid) && strpos(getState($uid),"add_coupon_")===0){
    $amount=(int)str_replace("add_coupon_","",getState($uid));
    $codes=preg_split("/\s+|,|\n/",$txt);
    $added=0;
    foreach($codes as $c){
      $c=trim($c);
      if(!$c) continue;
      try{
        $pdo->prepare("INSERT INTO coupons (code,amount,added_by) VALUES (?,?,?)")
            ->execute([$c,$amount,$uid]);
        $added++;
      }catch(Exception $e){}
    }
    clearState($uid);
    send($cid,"✅ Added {$added} coupon(s).",adminMenu());
    exit;
  }

  send($cid,"🏠 Main Menu:",mainMenu(isAdmin($uid)));
  exit;
}

/* ---------- CALLBACK ---------- */
if(isset($up["callback_query"])){
  $cq=$up["callback_query"];
  $uid=$cq["from"]["id"];
  $cid=$cq["message"]["chat"]["id"];
  $d=$cq["data"];
  answer($cq["id"]);

  ensureUser($uid);

  /* USER */
  if($d==="stats"){
    $u=user($uid);
    send($cid,
      "📊 <b>Your Stats</b>\n\n⭐ Points: <b>{$u['points']}</b>\n👥 Referrals: <b>{$u['total_referrals']}</b>",
      mainMenu(isAdmin($uid))
    );
    exit;
  }

  if($d==="reflink"){
    $link="https://t.me/{$BOT_USERNAME}?start={$uid}";
    send($cid,"🔗 <b>Your Referral Link</b>\n<code>{$link}</code>",mainMenu(isAdmin($uid)));
    exit;
  }

  if($d==="withdraw"){
    send($cid,"🎁 <b>Select coupon</b>",[
      "inline_keyboard"=>[
        [["text"=>"500 (".getWithdrawPoints(500)." pts)","callback_data"=>"wd_500"]],
        [["text"=>"1K (".getWithdrawPoints(1000)." pts)","callback_data"=>"wd_1000"]],
        [["text"=>"2K (".getWithdrawPoints(2000)." pts)","callback_data"=>"wd_2000"]],
        [["text"=>"4K (".getWithdrawPoints(4000)." pts)","callback_data"=>"wd_4000"]],
        [["text"=>"⬅ Back","callback_data"=>"back_main"]]
      ]
    ]);
    exit;
  }

  if(preg_match("/wd_(\d+)/",$d,$m)){
    $amount=(int)$m[1];
    $need=getWithdrawPoints($amount);
    $u=user($uid);
    if($u["points"]<$need){ send($cid,"❌ Not enough points.",mainMenu(isAdmin($uid))); exit; }

    $pdo->beginTransaction();
    $c=$pdo->prepare("SELECT id,code FROM coupons WHERE used=false AND amount=? LIMIT 1 FOR UPDATE");
    $c->execute([$amount]);
    $cp=$c->fetch();
    if(!$cp){ $pdo->rollBack(); send($cid,"❌ Out of stock.",mainMenu(isAdmin($uid))); exit; }

    $pdo->prepare("UPDATE users SET points=points-? WHERE tg_id=?")->execute([$need,$uid]);
    $pdo->prepare("UPDATE coupons SET used=true,used_by=?,used_at=NOW() WHERE id=?")->execute([$uid,$cp["id"]]);
    $pdo->prepare("INSERT INTO withdrawals (tg_id,coupon_code,points_deducted) VALUES (?,?,?)")
        ->execute([$uid,$cp["code"],$need]);
    $pdo->commit();

    send($cid,"🎟 <b>Your Coupon</b>\n<code>{$cp['code']}</code>",mainMenu(isAdmin($uid)));
    exit;
  }

  /* ADMIN */
  if($d==="admin_panel" && isAdmin($uid)){
    send($cid,"🛠 <b>Admin Panel</b>",adminMenu());
    exit;
  }

  if($d==="admin_add_coupon" && isAdmin($uid)){
    send($cid,"Select coupon amount:",[
      "inline_keyboard"=>[
        [["text"=>"500","callback_data"=>"addc_500"]],
        [["text"=>"1K","callback_data"=>"addc_1000"]],
        [["text"=>"2K","callback_data"=>"addc_2000"]],
        [["text"=>"4K","callback_data"=>"addc_4000"]],
        [["text"=>"⬅ Back","callback_data"=>"admin_panel"]]
      ]
    ]);
    exit;
  }

  if(preg_match("/addc_(\d+)/",$d,$m) && isAdmin($uid)){
    setState($uid,"add_coupon_".$m[1]);
    send($cid,"Send coupon codes for {$m[1]}:");
    exit;
  }

  if($d==="admin_stock" && isAdmin($uid)){
    $r=$pdo->query("SELECT amount,COUNT(*) c FROM coupons WHERE used=false GROUP BY amount ORDER BY amount")->fetchAll();
    $t="📦 <b>Stock</b>\n\n";
    foreach($r as $x) $t.="{$x['amount']} : <b>{$x['c']}</b>\n";
    send($cid,$t,adminMenu());
    exit;
  }

  if($d==="admin_redeems" && isAdmin($uid)){
    $r=$pdo->query("SELECT tg_id,coupon_code,created_at FROM withdrawals ORDER BY id DESC LIMIT 10")->fetchAll();
    $t="🗂 <b>Last Redeems</b>\n\n";
    foreach($r as $x) $t.="👤 {$x['tg_id']}\n🎟 {$x['coupon_code']}\n🕒 {$x['created_at']}\n\n";
    send($cid,$t,adminMenu());
    exit;
  }

  if($d==="admin_points" && isAdmin($uid)){
    send($cid,"Select amount:",[
      "inline_keyboard"=>[
        [["text"=>"500","callback_data"=>"setp_500"]],
        [["text"=>"1K","callback_data"=>"setp_1000"]],
        [["text"=>"2K","callback_data"=>"setp_2000"]],
        [["text"=>"4K","callback_data"=>"setp_4000"]],
        [["text"=>"⬅ Back","callback_data"=>"admin_panel"]]
      ]
    ]);
    exit;
  }

  if(preg_match("/setp_(\d+)/",$d,$m) && isAdmin($uid)){
    setState($uid,"setp_".$m[1]);
    send($cid,"Send new points for {$m[1]}:");
    exit;
  }

  $st=getState($uid);
  if(isAdmin($uid) && strpos($st,"setp_")===0){
    $amt=(int)str_replace("setp_","",$st);
    $pts=(int)($cq["message"]["text"]??0);
    $pdo->prepare("INSERT INTO withdraw_points (amount,points) VALUES (?,?) ON CONFLICT (amount) DO UPDATE SET points=EXCLUDED.points")
        ->execute([$amt,$pts]);
    clearState($uid);
    send($cid,"✅ Points updated.",adminMenu());
    exit;
  }

  if($d==="back_main"){
    send($cid,"🏠 Main Menu:",mainMenu(isAdmin($uid)));
    exit;
  }
}

http_response_code(200);
