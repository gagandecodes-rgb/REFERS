<?php
error_reporting(0);
ini_set("display_errors", 0);

$uid   = (int)($_GET["uid"] ?? 0);
$token = trim($_GET["token"] ?? "");

if(!$uid || !$token) { die(page("Invalid", "Invalid verification link.")); }

// Device token cookie (1 device = 1 ID)
$cookieName = "device_token";
if(empty($_COOKIE[$cookieName]) || strlen($_COOKIE[$cookieName]) < 20){
  $dt = bin2hex(random_bytes(16));
  setcookie($cookieName, $dt, time()+3600*24*365, "/", "", true, true);
  $_COOKIE[$cookieName] = $dt;
}
$deviceToken = $_COOKIE[$cookieName];

// DB connect (Supabase pooler)
$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

try{
  $pdo = new PDO(
    "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};sslmode=require;connect_timeout=5",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
}catch(Exception $e){
  die(page("DB Error", "Database connection failed."));
}

// Ensure user exists
$pdo->prepare("INSERT INTO users (tg_id) VALUES (:tg) ON CONFLICT (tg_id) DO NOTHING")
    ->execute([":tg"=>$uid]);

// Validate verify token
$st = $pdo->prepare("SELECT verified, verify_token, verify_token_expires FROM users WHERE tg_id=:tg LIMIT 1");
$st->execute([":tg"=>$uid]);
$u = $st->fetch();

if(!$u) die(page("Error", "User not found."));

if($u["verified"]) {
  die(page("✅ Verified", "Already verified. Return to Telegram and use the bot."));
}

if($u["verify_token"] !== $token) {
  die(page("Invalid", "This verify link is not valid. Go back to Telegram and press Verify again."));
}

if(empty($u["verify_token_expires"]) || strtotime($u["verify_token_expires"]) < time()){
  die(page("Expired", "Verify link expired. Go back to Telegram and press Verify again."));
}

// Device lock: if device token already linked to another TG ID -> block
$st = $pdo->prepare("SELECT tg_id FROM device_links WHERE device_token=:dt LIMIT 1");
$st->execute([":dt"=>$deviceToken]);
$existing = $st->fetch();

if($existing && (int)$existing["tg_id"] !== $uid){
  die(page("Blocked", "❌ This device is already registered with another Telegram ID."));
}

// Link device token to this TG ID (first time)
try{
  $pdo->prepare("INSERT INTO device_links (device_token, tg_id) VALUES (:dt,:tg)
                 ON CONFLICT (device_token) DO UPDATE SET tg_id=EXCLUDED.tg_id")
      ->execute([":dt"=>$deviceToken, ":tg"=>$uid]);
}catch(Exception $e){
  die(page("Error", "Device lock error."));
}

// Mark verified & clear token
$pdo->prepare("UPDATE users SET verified=true, verified_at=NOW(), verify_token=NULL, verify_token_expires=NULL WHERE tg_id=:tg")
    ->execute([":tg"=>$uid]);

echo page("✅ Verified", "Verification successful. Return to Telegram and press /start.");

function page($title,$msg){
  return '<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>'.$title.'</title>
<style>
  body{margin:0;font-family:system-ui;background:#0b1220;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;}
  .card{width:min(520px,92vw);background:#111a2c;border-radius:18px;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,.35);}
  .h{font-size:28px;font-weight:800;margin:0 0 8px;}
  .p{opacity:.85;line-height:1.4;margin:0 0 14px;}
  .btn{display:inline-block;background:#2f6dff;color:#fff;padding:14px 18px;border-radius:12px;text-decoration:none;font-weight:700;}
</style>
</head>
<body>
  <div class="card">
    <div class="h">🔐 '.$title.'</div>
    <div class="p">'.$msg.'</div>
    <div class="p">You can close this page.</div>
  </div>
</body>
</html>';
}
