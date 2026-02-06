<?php
error_reporting(0);
date_default_timezone_set("Asia/Kolkata");

/* ================= ENV ================= */
$BOT_TOKEN = getenv("BOT_TOKEN");
$BOT_USERNAME = getenv("BOT_USERNAME");
$ADMIN_ID = getenv("ADMIN_ID");

$DB_HOST = getenv("DB_HOST");
$DB_NAME = getenv("DB_NAME");
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");
$DB_PORT = getenv("DB_PORT");

$VERIFY_BASE_URL = rtrim(getenv("VERIFY_BASE_URL"), "/");
$API = "https://api.telegram.org/bot$BOT_TOKEN";

/* ================= DB ================= */
$pdo = new PDO(
  "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME",
  $DB_USER,
  $DB_PASS,
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);

/* ================= HELPERS ================= */
function tg($m,$d=[]){
  global $API;
  $c=curl_init("$API/$m");
  curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$d]);
  return curl_exec($c);
}
function kb($b){ return json_encode(["inline_keyboard"=>$b]); }

function channels(){
  return array_filter([
    getenv("FORCE_JOIN_1"),
    getenv("FORCE_JOIN_2"),
    getenv("FORCE_JOIN_3"),
    getenv("FORCE_JOIN_4")
  ]);
}
function joinedAll($uid){
  global $BOT_TOKEN;
  foreach(channels() as $ch){
    $r=json_decode(file_get_contents(
      "https://api.telegram.org/bot$BOT_TOKEN/getChatMember?chat_id=$ch&user_id=$uid"
    ),true);
    if(!in_array($r['result']['status']??'',["member","administrator","creator"])) return false;
  }
  return true;
}
function joinUI(){
  $b=[];
  foreach(channels() as $c){
    $b[]=[["text"=>"➕ Join $c","url"=>"https://t.me/".ltrim($c,'@')]];
  }
  $b[]=[["text"=>"✅ Check Verification","callback_data"=>"check_verify"]];
  return kb($b);
}

/* ================= UPDATE ================= */
$u=json_decode(file_get_contents("php://input"),true);
$msg=$u['message']??null;
$cb=$u['callback_query']??null;

/* ================= START ================= */
if($msg){
  $cid=$msg['chat']['id'];
  $uid=$msg['from']['id'];
  $txt=trim($msg['text']??'');

  if(strpos($txt,"/start")===0){
    $ref=explode(" ",$txt)[1]??null;

    $pdo->prepare("
      INSERT INTO users (user_id,points,ref_by)
      VALUES (?,0,NULL)
      ON CONFLICT (user_id) DO NOTHING
    ")->execute([$uid]);

    if($ref && $ref!=$uid){
      $chk=$pdo->prepare("SELECT ref_by FROM users WHERE user_id=?");
      $chk->execute([$uid]);
      if(!$chk->fetchColumn()){
        $pdo->prepare("UPDATE users SET ref_by=?, points=points+1 WHERE user_id=?")
            ->execute([$ref,$uid]);
      }
    }

    tg("sendMessage",[
      "chat_id"=>$cid,
      "text"=>"👋 Welcome\n\n🎁 3 Referrals = 1 Coupon\n🔐 Join & Verify to continue",
      "reply_markup"=>kb([
        [["text"=>"📊 Stats","callback_data"=>"stats"]],
        [["text"=>"💸 Withdraw","callback_data"=>"withdraw"]],
        $uid==$ADMIN_ID?[["text"=>"⚙ Admin","callback_data"=>"admin"]]:[]
      ])
    ]);
  }
}

/* ================= CALLBACKS ================= */
if($cb){
  $cid=$cb['message']['chat']['id'];
  $mid=$cb['message']['message_id'];
  $uid=$cb['from']['id'];
  $d=$cb['data'];

  /* ---- CHECK VERIFICATION ---- */
  if($d=="check_verify"){
    if(!joinedAll($uid)){
      tg("editMessageText",[
        "chat_id"=>$cid,"message_id"=>$mid,
        "text"=>"❗ Join all channels first",
        "reply_markup"=>joinUI()
      ]);
      exit;
    }

    $v=$pdo->prepare("SELECT verified FROM users WHERE user_id=?");
    $v->execute([$uid]);
    if($v->fetchColumn()){
      tg("editMessageText",[
        "chat_id"=>$cid,"message_id"=>$mid,
        "text"=>"✅ You are verified!",
        "reply_markup"=>kb([
          [["text"=>"📊 Stats","callback_data"=>"stats"]],
          [["text"=>"💸 Withdraw","callback_data"=>"withdraw"]]
        ])
      ]);
    }else{
      tg("editMessageText",[
        "chat_id"=>$cid,"message_id"=>$mid,
        "text"=>"🔐 Verification required",
        "reply_markup"=>kb([
          [["text"=>"🔐 Verify Now","url"=>"$VERIFY_BASE_URL/verify.php?uid=$uid"]],
          [["text"=>"✅ Check Verification","callback_data"=>"check_verify"]]
        ])
      ]);
    }
  }

  /* ---- ADMIN CHANGE POINTS ---- */
  if($d=="change_points" && $uid==$ADMIN_ID){
    tg("editMessageText",[
      "chat_id"=>$cid,"message_id"=>$mid,
      "text"=>"Select option to change points:",
      "reply_markup"=>kb([
        [["text"=>"500","callback_data"=>"cp_500"]],
        [["text"=>"1K","callback_data"=>"cp_1000"]],
        [["text"=>"2K","callback_data"=>"cp_2000"]],
        [["text"=>"4K","callback_data"=>"cp_4000"]],
        [["text"=>"⬅ Back","callback_data"=>"admin"]]
      ])
    ]);
  }

  if(preg_match("/cp_(\d+)/",$d,$m) && $uid==$ADMIN_ID){
    file_put_contents("cp.tmp",$m[1]);
    tg("editMessageText",[
      "chat_id"=>$cid,"message_id"=>$mid,
      "text"=>"Send new points for {$m[1]}:"
    ]);
  }
}

/* ---- ADMIN INPUT HANDLER ---- */
if($msg && file_exists("cp.tmp") && $msg['from']['id']==$ADMIN_ID){
  $amt=file_get_contents("cp.tmp");
  unlink("cp.tmp");
  $pts=(int)$msg['text'];
  $pdo->prepare("UPDATE withdraw_settings SET price_$amt=? WHERE id=1")
      ->execute([$pts]);
  tg("sendMessage",[
    "chat_id"=>$msg['chat']['id'],
    "text"=>"✅ Points updated for $amt"
  ]);
}
