<?php
// verify.php
$uid = $_GET["uid"] ?? "";

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verification</title>
  <style>
    body{font-family:Arial,sans-serif;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
    .box{background:#111827;padding:22px;border-radius:14px;max-width:360px;width:92%;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.4)}
    .btn{display:inline-block;margin-top:14px;background:#22c55e;color:#000;padding:12px 16px;border-radius:10px;text-decoration:none;font-weight:700}
    .small{opacity:.8;font-size:13px;margin-top:8px}
    code{background:#0b1220;padding:4px 8px;border-radius:8px}
  </style>
</head>
<body>
  <div class="box">
    <h2>✅ Verify Yourself</h2>

    <?php if (!$uid): ?>
      <p>UID missing. Please go back to Telegram and click the button again.</p>
    <?php else: ?>
      <p>Your verification request has been received.</p>
      <div class="small">Your UID: <code><?php echo htmlspecialchars($uid); ?></code></div>

      <a class="btn" href="https://t.me/share/url?url=&text=✅%20I%20verified%20myself!%20UID:%20<?php echo urlencode($uid); ?>">
        ✅ Continue
      </a>

      <div class="small">
        If you want, I can also save verification in a database (so user becomes “verified”).
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
