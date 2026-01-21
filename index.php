<?php
// ---------- FAST webhook health ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo "OK"; exit; }

error_reporting(0);
ini_set("display_errors", 0);

// ===== SETTINGS =====
define("POINTS_PER_WITHDRAW", 3);         // ✅ 3 refer = 1 coupon
define("VERIFY_TOKEN_MINUTES", 10);       // verify link expires in 10 minutes
define("TG_CONNECT_TIMEOUT", 2);
define("TG_TIMEOUT", 6);

// ===== ENV =====
$BOT_TOKEN      = getenv("BOT_TOKEN");
$ADMIN_ID       = getenv("ADMIN_ID");
$BOT_USERNAME   = getenv("BOT_USERNAME");        // no @
$VERIFY_BASE_URL= getenv("VERIFY_BASE_URL");     // https://.../verify.php

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

if (!$BOT_TOKEN) { http_response_code(200); echo "OK"; exit; }
$API = "https://api.telegram.org/bot{$BOT_TOKEN}";

// ===== DB CONNECT (Supabase needs SSL) =====
$pdo = null;
try {
  $pdo = new PDO(
    "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};sslmode=require;connect_timeout=5",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Exception $e) {
  $pdo = null;
}

function dbReady(){ global $pdo; return $pdo instanceof PDO; }

// ===== Telegram helpers =====
function tg($method, $data=[]){
  global $API;
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $API."/".$method);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, TG_CONNECT_TIMEOUT);
  curl_setopt($ch, CURLOPT_TIMEOUT, TG_TIMEOUT);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function sendMessage($chat_id, $text, $reply_markup=null){
  $data = [
    "chat_id"=>$chat_id,
    "text"=>$text,
    "parse_mode"=>"HTML",
    "disable_web_page_preview"=>true
  ];
  if($reply_markup) $data["reply_markup"]=json_encode($reply_markup);
  return tg("sendMessage",$data);
}

function answerCallback($id,$text="",$alert=false){
  return tg("answerCallbackQuery",[
    "callback_query_id"=>$id,
    "text"=>$text,
    "show_alert"=>$alert?"true":"false"
  ]);
}

function normalizeChannel($s){
  $s = trim((string)$s);
  if($s==="") return "";
  if($s[0]!=="@") $s="@".$s;
  return $s;
}

function isAdmin($tg_id){
  global $ADMIN_ID;
  return (string)$tg_id === (string)$ADMIN_ID;
}

// ===== Channel list (4) =====
function channelsList(){
  return [
    normalizeChannel(getenv("FORCE_JOIN_1")),
    normalizeChannel(getenv("FORCE_JOIN_2")),
    normalizeChannel(getenv("FORCE_JOIN_3")),
    normalizeChannel(getenv("FORCE_JOIN_4")),
  ];
}

// ===== UI =====
function joinMarkup(){
  $chs = channelsList();
  $rows = [];
  $i=1;
  foreach($chs as $ch){
    if(!$ch) continue;
    $rows[] = [[ "text"=>"✅ Join $i", "url"=>"https://t.me/".ltrim($ch,"@") ]];
    $i++;
  }
  // Like screenshot: Verify Yourself + Check Verification
  $rows[] = [[ "text"=>"🔐 Verify Yourself", "callback_data"=>"show_verify_info" ]];
  $rows[] = [[ "text"=>"✅ Check Verification", "callback_data"=>"check_join" ]];
  return ["inline_keyboard"=>$rows];
}

function verifyNowUrlButton($url){
  // This is the BLUE URL button that opens website (like image)
  return ["inline_keyboard"=>[
    [[ "text"=>"✅ Verify Now", "url"=>$url ]]
  ]];
}

function mainMenuMarkup($admin=false){
  $rows = [
    [
      ["text"=>"📊 Stats","callback_data"=>"stats"],
      ["text"=>"🎁 Withdraw","callback_data"=>"withdraw"]
    ],
    [
      ["text"=>"🔗 My Referral Link","callback_data"=>"reflink"]
    ],
  ];
  if($admin) $rows[] = [[ "text"=>"🛠 Admin Panel", "callback_data"=>"admin_panel" ]];
  return ["inline_keyboard"=>$rows];
}

function adminPanelMarkup(){
  return ["inline_keyboard"=>[
    [
      ["text"=>"➕ Add Coupon","callback_data"=>"admin_add_coupon"],
      ["text"=>"📦 Coupon Stock","callback_data"=>"admin_stock"]
    ],
    [
      ["text"=>"🗂 Redeems Log","callback_data"=>"admin_redeems"]
    ],
    [
      ["text"=>"⬅️ Back","callback_data"=>"back_main"]
    ]
  ]];
}

// ===== Admin add coupon state (file) =====
function stateDir(){ $d=__DIR__."/state"; if(!is_dir($d)) @mkdir($d,0777,true); return $d; }
function setState($tg_id,$state){ file_put_contents(stateDir()."/{$tg_id}.txt",$state); }
function getState($tg_id){ $f=stateDir()."/{$tg_id}.txt"; return file_exists($f)?trim((string)file_get_contents($f)):""; }
function clearState($tg_id){ $f=stateDir()."/{$tg_id}.txt"; if(file_exists($f)) @unlink($f); }

// ===== DB helpers =====
function getUser($tg_id){
  global $pdo;
  $st=$pdo->prepare("SELECT * FROM users WHERE tg_id=:tg LIMIT 1");
  $st->execute([":tg"=>$tg_id]);
  return $st->fetch();
}

function upsertUser($tg_id,$referred_by=null){
  global $pdo;
  $u=getUser($tg_id);
  if($u) return $u;
  $pdo->prepare("INSERT INTO users (tg_id,referred_by) VALUES (:tg,:ref)")
      ->execute([":tg"=>$tg_id,":ref"=>$referred_by]);
  return getUser($tg_id);
}

function isVerified($tg_id){
  $u = getUser($tg_id);
  return $u && !empty($u["verified"]);
}

function makeVerifyLink($tg_id){
  global $pdo, $VERIFY_BASE_URL;
  if(!$VERIFY_BASE_URL) return null;

  $token = bin2hex(random_bytes(16));
  $pdo->prepare("UPDATE users SET verify_token=:t, verify_token_expires=NOW() + (:m || ' minutes')::interval WHERE tg_id=:tg")
      ->execute([":t"=>$token, ":m"=>VERIFY_TOKEN_MINUTES, ":tg"=>$tg_id]);

  return $VERIFY_BASE_URL . "?uid=" . urlencode($tg_id) . "&token=" . urlencode($token);
}

function notifyAdminRedeem($tg_id,$coupon,$before,$after){
  global $ADMIN_ID;
  $time = date("Y-m-d H:i:s");
  $msg="✅ <b>Coupon Redeemed</b>\n"
     . "👤 User: <code>{$tg_id}</code>\n"
     . "🎟 Code: <code>{$coupon}</code>\n"
     . "🕒 Time: <code>{$time}</code>\n"
     . "⭐ Points: <b>{$before}</b> → <b>{$after}</b>";
  sendMessage($ADMIN_ID,$msg);
}

// ===== Join check (only when user clicks “Check Verification”) =====
function checkMember($user_id,$chat){
  $r = tg("getChatMember", ["chat_id"=>$chat,"user_id"=>$user_id]);
  if(!$r || empty($r["ok"])) return false;
  $status = $r["result"]["status"] ?? "";
  return in_array($status, ["member","administrator","creator"], true);
}

function allJoined($tg_id){
  $chs = channelsList();
  foreach($chs as $ch){
    if(!$ch) continue;
    if(!checkMember($tg_id,$ch)) return false;
  }
  return true;
}

// ===== Bot Username (ref link) =====
function botUsername(){
  global $BOT_USERNAME;
  if($BOT_USERNAME) return ltrim($BOT_USERNAME,"@");
  $me = tg("getMe");
  return $me["result"]["username"] ?? "";
}

// ===== Handle update =====
$update = json_decode(file_get_contents("php://input"), true);
if(!$update){ http_response_code(200); echo "OK"; exit; }

if(!dbReady()){
  // If DB down, reply fast
  if(isset($update["message"])){
    $chat_id = $update["message"]["chat"]["id"];
    sendMessage($chat_id,"⚠️ Database not connected.\nCheck Render ENV DB_HOST/DB_USER/DB_PASS and redeploy.");
  }
  http_response_code(200); echo "OK"; exit;
}

// ===== Messages =====
if(isset($update["message"])){
  $m = $update["message"];
  $chat_id = $m["chat"]["id"];
  $from_id = $m["from"]["id"];
  $text = trim($m["text"] ?? "");

  // Admin add coupon mode
  if(isAdmin($from_id) && getState($from_id)==="await_coupon" && $text!=="" && strpos($text,"/")!==0){
    $codes = preg_split("/\r\n|\n|\r|,|\s+/", $text);
    $codes = array_values(array_filter(array_map("trim",$codes)));
    $added=0;
    foreach($codes as $c){
      if($c==="") continue;
      try{
        global $pdo;
        $pdo->prepare("INSERT INTO coupons (code,added_by) VALUES (:c,:a)")
            ->execute([":c"=>$c,":a"=>$from_id]);
        $added++;
      }catch(Exception $e){}
    }
    clearState($from_id);
    sendMessage($chat_id,"✅ Added <b>{$added}</b> coupon(s).", mainMenuMarkup(true));
    http_response_code(200); echo "OK"; exit;
  }

  // /start referral
  if(strpos($text,"/start")===0){
    $parts = explode(" ", $text, 2);
    $ref = null;
    if(count($parts)===2 && ctype_digit(trim($parts[1]))) $ref=(int)trim($parts[1]);

    $existing = getUser($from_id);
    if(!$existing){
      $referred_by = null;
      if($ref && $ref!=$from_id){
        $refUser = getUser($ref);
        if($refUser) $referred_by=$ref;
      }
      upsertUser($from_id,$referred_by);

      // Give referrer +1 point (only once for new user)
      if($referred_by){
        $pdo->prepare("UPDATE users SET points=points+1, total_referrals=total_referrals+1 WHERE tg_id=:r")
            ->execute([":r"=>$referred_by]);
      }
    } else {
      upsertUser($from_id,null);
    }

    // If verified -> menu, else join screen
    if(isVerified($from_id)){
      sendMessage($chat_id,"🎉 <b>WELCOME!</b>\nChoose:", mainMenuMarkup(isAdmin($from_id)));
    } else {
      sendMessage($chat_id,"✅ <b>Join all channels</b> then verify.\n\nAfter joining, press <b>Check Verification</b>.", joinMarkup());
    }

    http_response_code(200); echo "OK"; exit;
  }

  // Any other message: show join or menu
  upsertUser($from_id,null);
  if(isVerified($from_id)){
    sendMessage($chat_id,"🏠 Main Menu:", mainMenuMarkup(isAdmin($from_id)));
  } else {
    sendMessage($chat_id,"✅ Join all channels then verify.\nPress <b>Check Verification</b>.", joinMarkup());
  }

  http_response_code(200); echo "OK"; exit;
}

// ===== Callbacks =====
if(isset($update["callback_query"])){
  $cq = $update["callback_query"];
  $data = $cq["data"] ?? "";
  $from_id = $cq["from"]["id"];
  $chat_id = $cq["message"]["chat"]["id"];

  upsertUser($from_id,null);

  if($data==="show_verify_info"){
    answerCallback($cq["id"],"After joining press Check Verification");
    sendMessage($chat_id,"✅ <b>Channel join verified?</b>\nNext: <b>Verify Yourself</b>\n\n1) Join all channels\n2) Tap <b>Check Verification</b>\n3) Then tap <b>Verify Now</b> (blue button).", joinMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if($data==="check_join"){
    answerCallback($cq["id"],"Checking...");
    if(allJoined($from_id)){
      // create signed verify link (token)
      $url = makeVerifyLink($from_id);
      sendMessage($chat_id,
        "🔐 <b>Verification</b>\nTap below to verify.\nThis blocks fake referrals and keeps rewards fair.",
        verifyNowUrlButton($url)
      );
    } else {
      sendMessage($chat_id,"❌ <b>Verification failed.</b>\nYou must join all 4 channels to use the bot.", joinMarkup());
    }
    http_response_code(200); echo "OK"; exit;
  }

  // Block everything if not verified
  if(!isVerified($from_id)){
    answerCallback($cq["id"],"Verify first", true);
    sendMessage($chat_id,"🔐 Please verify first.\nJoin channels then press Check Verification.", joinMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // STATS
  if($data==="stats"){
    $u=getUser($from_id);
    answerCallback($cq["id"],"Stats");
    sendMessage($chat_id,
      "📊 <b>Your Stats</b>\n\n⭐ Points: <b>{$u['points']}</b>\n👥 Referrals: <b>{$u['total_referrals']}</b>\n\n🎁 Need <b>".POINTS_PER_WITHDRAW."</b> points for 1 coupon.",
      mainMenuMarkup(isAdmin($from_id))
    );
    http_response_code(200); echo "OK"; exit;
  }

  // REF LINK
  if($data==="reflink"){
    $bot = botUsername();
    $link = $bot ? "https://t.me/{$bot}?start={$from_id}" : "Set BOT_USERNAME in ENV";
    answerCallback($cq["id"],"Link");
    sendMessage($chat_id,"🔗 <b>Your Referral Link</b>\n\n<code>{$link}</code>", mainMenuMarkup(isAdmin($from_id)));
    http_response_code(200); echo "OK"; exit;
  }

  // WITHDRAW (deduct ONLY 3)
  if($data==="withdraw"){
    $u=getUser($from_id);
    $need = POINTS_PER_WITHDRAW;

    if((int)$u["points"] < $need){
      answerCallback($cq["id"],"Not enough points", true);
      sendMessage($chat_id,"❌ Not enough points.\nYou have <b>{$u['points']}</b>, need <b>{$need}</b>.", mainMenuMarkup(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;
    }

    try{
      global $pdo;
      $pdo->beginTransaction();

      // Fast, no waiting
      $st = $pdo->query("SELECT id, code FROM coupons WHERE used=false ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
      $coupon = $st->fetch();

      if(!$coupon){
        $pdo->rollBack();
        answerCallback($cq["id"],"Out of stock", true);
        sendMessage($chat_id,"⚠️ Coupons out of stock. Try later.", mainMenuMarkup(isAdmin($from_id)));
        http_response_code(200); echo "OK"; exit;
      }

      $before = (int)$u["points"];
      $after  = $before - $need;

      // Deduct ONLY 3
      $st = $pdo->prepare("UPDATE users SET points=points-:need WHERE tg_id=:tg AND points>=:need");
      $st->execute([":need"=>$need, ":tg"=>$from_id]);
      if($st->rowCount()<1){
        $pdo->rollBack();
        answerCallback($cq["id"],"Not enough points", true);
        http_response_code(200); echo "OK"; exit;
      }

      $pdo->prepare("UPDATE coupons SET used=true, used_by=:tg, used_at=NOW() WHERE id=:id")
          ->execute([":tg"=>$from_id, ":id"=>$coupon["id"]]);

      $pdo->prepare("INSERT INTO withdrawals (tg_id, coupon_code, points_deducted) VALUES (:tg,:c,:d)")
          ->execute([":tg"=>$from_id, ":c"=>$coupon["code"], ":d"=>$need]);

      $pdo->commit();

      answerCallback($cq["id"],"Success!");
      sendMessage($chat_id,"🎉 <b>Congratulations!</b>\n\nYour Coupon:\n<code>{$coupon['code']}</code>", mainMenuMarkup(isAdmin($from_id)));
      notifyAdminRedeem($from_id, $coupon["code"], $before, $after);

      http_response_code(200); echo "OK"; exit;

    } catch(Exception $e){
      if($pdo && $pdo->inTransaction()) $pdo->rollBack();
      answerCallback($cq["id"],"Error", true);
      sendMessage($chat_id,"⚠️ Error. Try again.", mainMenuMarkup(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;
    }
  }

  // ADMIN PANEL
  if($data==="admin_panel"){
    if(!isAdmin($from_id)){ answerCallback($cq["id"],"Not allowed", true); http_response_code(200); echo "OK"; exit; }
    answerCallback($cq["id"],"Admin");
    sendMessage($chat_id,"🛠 <b>Admin Panel</b>", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if($data==="admin_add_coupon"){
    if(!isAdmin($from_id)){ answerCallback($cq["id"],"Not allowed", true); http_response_code(200); echo "OK"; exit; }
    setState($from_id,"await_coupon");
    answerCallback($cq["id"],"Send coupon codes");
    sendMessage($chat_id,"➕ Send coupon codes now (new line / space / comma).", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if($data==="admin_stock"){
    if(!isAdmin($from_id)){ answerCallback($cq["id"],"Not allowed", true); http_response_code(200); echo "OK"; exit; }
    $a = (int)($pdo->query("SELECT COUNT(*) c FROM coupons WHERE used=false")->fetch()["c"] ?? 0);
    $u2= (int)($pdo->query("SELECT COUNT(*) c FROM coupons WHERE used=true")->fetch()["c"] ?? 0);
    answerCallback($cq["id"],"Stock");
    sendMessage($chat_id,"📦 <b>Coupon Stock</b>\n\n✅ Available: <b>{$a}</b>\n🧾 Used: <b>{$u2}</b>", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if($data==="admin_redeems"){
    if(!isAdmin($from_id)){ answerCallback($cq["id"],"Not allowed", true); http_response_code(200); echo "OK"; exit; }
    $rows = $pdo->query("SELECT tg_id, coupon_code, created_at, points_deducted FROM withdrawals ORDER BY id DESC LIMIT 15")->fetchAll();
    $text = "🗂 <b>Last 15 Redeems</b>\n\n";
    if(!$rows) $text .= "No redeems yet.";
    else{
      foreach($rows as $r){
        $text .= "👤 <code>{$r['tg_id']}</code>\n🎟 <code>{$r['coupon_code']}</code>\n⭐ <b>{$r['points_deducted']}</b>\n🕒 <code>{$r['created_at']}</code>\n\n";
      }
    }
    answerCallback($cq["id"],"Redeems");
    sendMessage($chat_id,$text, adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if($data==="back_main"){
    answerCallback($cq["id"],"Back");
    sendMessage($chat_id,"🏠 Main Menu:", mainMenuMarkup(isAdmin($from_id)));
    http_response_code(200); echo "OK"; exit;
  }

  answerCallback($cq["id"],"OK");
  http_response_code(200); echo "OK"; exit;
}

http_response_code(200);
echo "OK";
