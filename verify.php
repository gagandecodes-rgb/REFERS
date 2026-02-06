<?php
// verify.php
error_reporting(0);

$DATABASE_URL = getenv("DATABASE_URL");
$uid = $_GET["uid"] ?? "";

$db = parse_url($DATABASE_URL);
$pdo = new PDO(
  "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'], '/'),
  $db['user'],
  $db['pass'],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$verified = false;

if ($uid && is_numeric($uid)) {
  $stmt = $pdo->prepare("UPDATE users SET verified=true WHERE user_id=?");
  $stmt->execute([$uid]);
  if ($stmt->rowCount() > 0) {
    $verified = true;
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Verification</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:Arial;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
    .box{background:#111827;padding:22px;border-radius:14px;max-width:360px;width:92%;text-align:center}
    .btn{display:inline-block;margin-top:16px;background:#22c55e;color:#000;padding:12px 16px;border-radius:10px;text-decoration:none;font-weight:700}
    .err{color:#f87171}
  </style>
</head>
<body>
  <div class="box">
    <h2>✅ Verification</h2>

    <?php if (!$uid): ?>
      <p class="err">UID missing. Please return to Telegram.</p>
    <?php elseif ($verified): ?>
      <p>You are successfully verified.</p>
      <a class="btn" href="https://t.me/<?= htmlspecialchars(getenv('BOT_USERNAME')) ?>">
        🔁 Return to Bot
      </a>
    <?php else: ?>
      <p class="err">Verification failed. Please try again.</p>
    <?php endif; ?>
  </div>
</body>
</html>
