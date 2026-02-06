<?php
error_reporting(0);
ini_set("display_errors", 0);

define("VERIFY_TOKEN_MINUTES", 10);
define("TG_CONNECT_TIMEOUT", 2);
define("TG_TIMEOUT", 6);

/* ================= ENV ================= */
$BOT_TOKEN    = getenv("BOT_TOKEN");
$ADMIN_ID     = getenv("ADMIN_ID");
$BOT_USERNAME = getenv("BOT_USERNAME");

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

if (!$BOT_TOKEN) { http_response_code(200); exit; }
$API = "https://api.telegram.org/bot{$BOT_TOKEN}";

/* ================= DB ================= */
try {
  $pdo = new PDO(
    "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;sslmode=require",
    $DB_USER,
    $DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
  );
} catch (Exception $e) {
  http_response_code(200); exit;
}

/* ================= HELPERS ================= */
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
  return tg("sendMessage",$d);
}
function answer($id,$t="",$a=false){
  return tg("answerCallbackQuery",["callback_query_id"=>$id,"text"=>$t,"show_alert"=>$a?"true":"false"]);
}
function isAdmin($id){ global $ADMIN_ID; return (string)$id===(string)$ADMIN_ID; }

/* ================= FORCE JOIN ================= */
function channels(){
  return array_filter([
    getenv("FORCE_JOIN_1"),
    getenv("FORCE_JOIN_2"),
    getenv("FORCE_JOIN_3"),
    getenv("FORCE_JOIN_4"),
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
function joinMarkup(){
  $rows=[];
  foreach(channels() as $i=>$c){
    if(!$c) continue;
    $rows[]=[[ "text"=>"✅ Join ".($i+1), "url"=>"https://t.me/".ltrim($c,"@") ]];
  }
  $rows[]=[[ "text"=>"✅ Check Verification","callback_data"=>"check_join" ]];
  return ["inline_keyboard"=>$rows];
}

/* ================= UI ================= */
function mainMenu($admin=false){
  $r=[
    [
      ["text"=>"📊 Stats","callback_data"=>"stats"],
      ["text"=>"🎁 Withdraw","callback_data"=>"withdraw"]
    ],
    [[ "text"=>"🔗 My Referral Link","callback_data"=>"reflink" ]]
  ];
  if($admin) $r[]=[[ "text"=>"🛠 Admin Panel","callback_data"=>"admin_panel" ]];
  return ["inline_keyboard"=>$r];
}
function verifyMenu($url){
  return ["inline_keyboard"=>[
    [[ "text"=>"✅ Verify Now","url"=>$url ]],
    [[ "text"=>"✅ Check Verification","callback_data"=>"check_verified" ]]
  ]];
}
function adminMenu(){
  return ["inline_keyboard"=>[
    [
      ["text"=>"➕ Add Coupon","callback_data"=>"admin_add_coupon"],
      ["text"=>"📦 Coupon Stock","callback_data"=>"admin_stock"]
    ],
    [[ "text"=>"🗂 Redeems Log","callback_data"=>"admin_redeems"]],
    [[ "text"=>"⚙ Change Withdraw Points","callback_data"=>"admin_points"]],
    [[ "text"=>"⬅ Back","callback_data"=>"back_main"]]
  ]];
}

/* ================= STATE ================= */
function stateDir(){ $d=__DIR__."/state"; if(!is_dir($d)) mkdir($d,0777,true); return $d; }
function setState($u,$s){ file_put_contents(stateDir()."/$u",$s); }
function getState($u){ $f=stateDir()."/$u"; return file_exists($f)?trim(file_get_contents($f)):""; }
function clearState($u){ $f=stateDir()."/$u"; if(file_exists($f)) unlink($f); }

/* ================= DB HELPERS ================= */
function user($id){ global $pdo; $s=$pdo->prepare("SELECT * FROM users WHERE tg_id=?");$s->execute([$id]);return $s->fetch(); }
function ensureUser($id,$ref=null){
  global $pdo;
  if(!user($id)){
    $pdo->prepare("INSERT INTO users (tg_id,referred_by) VALUES (?,?)")->execute([$id,$ref]);
    if($ref) $pdo->prepare("UPDATE users SET points=points+1,total_referrals=total_referrals+1 WHERE tg_id=?")->execute([$ref]);
  }
}
function verified($id){ $u=user($id); return $u && $u["verified"]; }
function getPointsFor($amt){
  global $pdo;
  $s=$pdo->prepare("SELECT points FROM withdraw_points WHERE amount=?");
  $s->execute([$amt]);
  $r=$s->fetch();
  return $r?(int)$r["points"]:0;
}

/* ================= VERIFY LINK ================= */
function baseUrl(){
  $p=(!empty($_SERVER["HTTP_X_FORWARDED_PROTO"]))?$_SERVER["HTTP_X_FORWARDED_PROTO"]:"https";
  $h=$_SERVER["HTTP_X_FORWARDED_HOST"]??$_SERVER["HTTP_HOST"];
  return "$p://$h".$_SERVER["SCRIPT_NAME"];
}
function makeVerify($uid){
  global $pdo;
  $t=bin2hex(random_bytes(16));
  $pdo->prepare("UPDATE users SET verify_token=?, verify_token_expires=NOW()+INTERVAL '10 minutes' WHERE tg_id=?")
      ->execute([$t,$uid]);
  return baseUrl()."?mode=verify&uid=$uid&token=$t";
}

/* ================= WEBSITE VERIFY ================= */
if($_SERVER["REQUEST_METHOD"]==="GET" && ($_GET["mode"]??"")==="verify"){
  $uid=(int)($_GET["uid"]??0);
  $tok=$_GET["token"]??"";
  $step=$_GET["step"]??"";
  if(!$uid||!$tok){ echo "Invalid"; exit; }

  if($step!=="do"){
    $go=baseUrl()."?mode=verify&uid=$uid&token=$tok&step=do";
    echo "<html><body style='background:#0f172a;color:#fff;font-family:Arial;display:flex;align-items:center;justify-content:center;height:100vh'>
    <a href='$go' style='background:#22c55e;color:#000;padding:14px 20px;border-radius:12px;text-decoration:none;font-weight:700'>✅ Verify Now</a>
    </body></html>";
    exit;
  }

  $u=user($uid);
  if(!$u||$u["verify_token"]!==$tok||strtotime($u["verify_token_expires"])<time()){ echo "Expired"; exit; }

  $dt=$_COOKIE["device_token"]??bin2hex(random_bytes(16));
  setcookie("device_token",$dt,time()+31536000,"/");
  $c=$pdo->prepare("SELECT tg_id FROM device_links WHERE device_token=?");
  $c->execute([$dt]);
  $ex=$c->fetch();
  if($ex && (int)$ex["tg_id"]!==$uid){ echo "Blocked"; exit; }

  $pdo->prepare("INSERT INTO device_links (device_token,tg_id) VALUES (?,?) ON CONFLICT (device_token) DO UPDATE SET tg_id=EXCLUDED.tg_id")
      ->execute([$dt,$uid]);
  $pdo->prepare("UPDATE users SET verified=true,verify_token=NULL,verify_token_expires=NULL WHERE tg_id=?")->execute([$uid]);

  header("Location: https://t.me/".ltrim($BOT_USERNAME,"@"));
  exit;
}

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
    if(verified($uid)) send($cid,"🏠 Menu:",mainMenu(isAdmin($uid)));
    else send($cid,"Join all channels then verify.",joinMarkup());
    exit;
  }

  ensureUser($uid);
  if(verified($uid)) send($cid,"🏠 Menu:",mainMenu(isAdmin($uid)));
  else send($cid,"Join all channels then verify.",joinMarkup());
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

  if($d==="check_join"){
    if(allJoined($uid)){
      send($cid,"Channels verified!");
      send($cid,"Verification:",verifyMenu(makeVerify($uid)));
    } else send($cid,"Join all channels.",joinMarkup());
    exit;
  }

  if($d==="check_verified"){
    if(verified($uid)) send($cid,"✅ Verified!",mainMenu(isAdmin($uid)));
    else send($cid,"Not verified.",verifyMenu(makeVerify($uid)));
    exit;
  }

  if(!verified($uid)){
    send($cid,"Verify first.",joinMarkup());
    exit;
  }

  if($d==="withdraw"){
    send($cid,"Choose coupon:",[
      "inline_keyboard"=>[
        [["text"=>"500","callback_data"=>"wd_500"]],
        [["text"=>"1K","callback_data"=>"wd_1000"]],
        [["text"=>"2K","callback_data"=>"wd_2000"]],
        [["text"=>"4K","callback_data"=>"wd_4000"]],
        [["text"=>"⬅ Back","callback_data"=>"back_main"]]
      ]
    ]);
    exit;
  }

  if(preg_match("/wd_(\d+)/",$d,$m)){
    $amt=(int)$m[1];
    $need=getPointsFor($amt);
    $u=user($uid);
    if($u["points"]<$need){ send($cid,"Not enough points.",mainMenu(isAdmin($uid))); exit; }

    $pdo->beginTransaction();
    $c=$pdo->query("SELECT id,code FROM coupons WHERE used=false LIMIT 1 FOR UPDATE")->fetch();
    if(!$c){ $pdo->rollBack(); send($cid,"Out of stock.",mainMenu(isAdmin($uid))); exit; }

    $pdo->prepare("UPDATE users SET points=points-? WHERE tg_id=?")->execute([$need,$uid]);
    $pdo->prepare("UPDATE coupons SET used=true,used_by=?,used_at=NOW() WHERE id=?")->execute([$uid,$c["id"]]);
    $pdo->prepare("INSERT INTO withdrawals (tg_id,coupon_code,points_deducted) VALUES (?,?,?)")
        ->execute([$uid,$c["code"],$need]);
    $pdo->commit();

    send($cid,"🎟 Coupon:\n<code>{$c['code']}</code>",mainMenu(isAdmin($uid)));
    exit;
  }

  if($d==="admin_panel" && isAdmin($uid)){
    send($cid,"Admin Panel:",adminMenu());
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
    send($cid,"Updated.",adminMenu());
    exit;
  }

  if($d==="back_main"){
    send($cid,"🏠 Menu:",mainMenu(isAdmin($uid)));
    exit;
  }
}

http_response_code(200);
