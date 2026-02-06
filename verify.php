<?php
error_reporting(0);

$DB_HOST=getenv("DB_HOST");
$DB_NAME=getenv("DB_NAME");
$DB_USER=getenv("DB_USER");
$DB_PASS=getenv("DB_PASS");
$DB_PORT=getenv("DB_PORT");
$BOT_USERNAME=getenv("BOT_USERNAME");

$uid=$_GET['uid']??'';
$token=$_COOKIE['device_token']??bin2hex(random_bytes(16));
setcookie("device_token",$token,time()+31536000,"/");

$pdo=new PDO(
 "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME",
 $DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);

$ok=false;
if(is_numeric($uid)){
  $chk=$pdo->prepare("SELECT user_id FROM users WHERE device_token=?");
  $chk->execute([$token]);
  if(!$chk->fetch()){
    $pdo->prepare("UPDATE users SET verified=true, device_token=? WHERE user_id=?")
        ->execute([$token,$uid]);
    $ok=true;
  }
}
?>
<!doctype html>
<html>
<head><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{background:#0f172a;color:#fff;font-family:Arial;display:flex;justify-content:center;align-items:center;height:100vh}
.box{background:#111827;padding:22px;border-radius:14px;text-align:center}
.btn{background:#22c55e;color:#000;padding:12px 18px;border-radius:10px;text-decoration:none;font-weight:700}
</style></head>
<body>
<div class="box">
<h3>Verification</h3>
<?php if($ok): ?>
<p>✅ Verified</p>
<a class="btn" href="https://t.me/<?=htmlspecialchars($BOT_USERNAME)?>">Return to Bot</a>
<?php else: ?>
<p>❌ Verification failed</p>
<?php endif; ?>
</div>
</body>
</html>
