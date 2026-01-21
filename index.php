<?php
/**
 * ✅ FULL SINGLE-FILE index.php (Telegram Bot + Website Verify in SAME file)
 *
 * FEATURES:
 * ✅ 3 refer = 1 coupon (deduct ONLY 3 points)
 * ✅ Coupon stock + remove/mark used + withdrawals log
 * ✅ Admin panel: add coupon / stock / redeems log
 * ✅ 4 force-join channels (checked ONLY when user clicks "✅ Check Verification")
 * ✅ AFTER join verified -> NEW message + Verification message
 * ✅ NO "Verify Yourself" button (removed as you asked)
 * ✅ Verification message has:
 *    - ✅ Verify Now (URL opens website verify page like your screenshot)
 *    - ✅ Check Verification (callback to confirm after returning)
 * ✅ Website verify page:
 *    - shows UI first
 *    - user clicks ✅ Verify Now
 *    - verifies in DB
 *    - enforces "1 device token = 1 tg id"
 *    - redirects back to Telegram bot
 *
 * REQUIRED Render ENV:
 * BOT_TOKEN, ADMIN_ID
 * DB_HOST, DB_PORT(5432), DB_NAME(postgres), DB_USER, DB_PASS
 * FORCE_JOIN_1..FORCE_JOIN_4 (e.g. @channelname)
 * BOT_USERNAME (REQUIRED for redirect back to Telegram)
 *
 * REQUIRED DB (Supabase SQL Editor) - ensure columns exist:
 * users(tg_id PK bigint, referred_by bigint, points int default 0, total_referrals int default 0,
 *       verified boolean default false, verified_at timestamptz, verify_token text, verify_token_expires timestamptz)
 * coupons(id bigserial, code text unique, used boolean default false, used_by bigint, used_at timestamptz, added_by bigint, created_at timestamptz default now())
 * withdrawals(id bigserial, tg_id bigint, coupon_code text, points_deducted int, created_at timestamptz default now())
 * device_links(device_token text PK, tg_id bigint UNIQUE, created_at timestamptz default now())
 */

error_reporting(0);
ini_set("display_errors", 0);

// ---------- SETTINGS ----------
define("POINTS_PER_WITHDRAW", 3);
define("VERIFY_TOKEN_MINUTES", 10);
define("TG_CONNECT_TIMEOUT", 2);
define("TG_TIMEOUT", 6);

// ---------- ENV ----------
$BOT_TOKEN    = getenv("BOT_TOKEN");
$ADMIN_ID     = getenv("ADMIN_ID");
$BOT_USERNAME = getenv("BOT_USERNAME"); // IMPORTANT (no @) for redirect

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

if (!$BOT_TOKEN) { http_response_code(200); echo "OK"; exit; }
$API = "https://api.telegram.org/bot{$BOT_TOKEN}";

// ---------- DB CONNECT ----------
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

// ---------- URL HELPERS ----------
function baseUrlThisFile() {
  $proto = "https";
  if (!empty($_SERVER["HTTP_X_FORWARDED_PROTO"])) $proto = $_SERVER["HTTP_X_FORWARDED_PROTO"];
  elseif (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") $proto = "https";

  $host = $_SERVER["HTTP_X_FORWARDED_HOST"] ?? ($_SERVER["HTTP_HOST"] ?? "");
  $path = $_SERVER["SCRIPT_NAME"] ?? "/index.php";
  if (!$host) return "";
  return $proto . "://" . $host . $path;
}

// ---------- TELEGRAM HELPERS ----------
function tg($method, $data = []) {
  global $API;
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $API . "/" . $method);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, TG_CONNECT_TIMEOUT);
  curl_setopt($ch, CURLOPT_TIMEOUT, TG_TIMEOUT);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function sendMessage($chat_id, $text, $reply_markup = null) {
  $data = [
    "chat_id" => $chat_id,
    "text" => $text,
    "parse_mode" => "HTML",
    "disable_web_page_preview" => true
  ];
  if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
  return tg("sendMessage", $data);
}

function answerCallback($callback_id, $text = "", $alert = false) {
  return tg("answerCallbackQuery", [
    "callback_query_id" => $callback_id,
    "text" => $text,
    "show_alert" => $alert ? "true" : "false"
  ]);
}

function normalizeChannel($s) {
  $s = trim((string)$s);
  if ($s === "") return "";
  if ($s[0] !== "@") $s = "@".$s;
  return $s;
}

function isAdmin($tg_id) {
  global $ADMIN_ID;
  return (string)$tg_id === (string)$ADMIN_ID;
}

// ---------- CHANNELS (4) ----------
function channelsList() {
  return [
    normalizeChannel(getenv("FORCE_JOIN_1")),
    normalizeChannel(getenv("FORCE_JOIN_2")),
    normalizeChannel(getenv("FORCE_JOIN_3")),
    normalizeChannel(getenv("FORCE_JOIN_4")),
  ];
}

// ---------- UI ----------
function joinMarkup() {
  $chs = channelsList();
  $rows = [];
  $i = 1;
  foreach ($chs as $ch) {
    if (!$ch) continue;
    $rows[] = [[
      "text" => "✅ Join $i",
      "url"  => "https://t.me/" . ltrim($ch, "@")
    ]];
    $i++;
  }
  // ✅ Only Check Verification (Verify Yourself removed)
  $rows[] = [[ "text" => "✅ Check Verification", "callback_data" => "check_join" ]];
  return ["inline_keyboard" => $rows];
}

function verifyMenuMarkup($verifyUrl) {
  // Like your photo: Verify Now + Check Verification
  return ["inline_keyboard" => [
    [[ "text" => "✅ Verify Now", "url" => $verifyUrl ]],
    [[ "text" => "✅ Check Verification", "callback_data" => "check_verified" ]]
  ]];
}

function mainMenuMarkup($admin = false) {
  $rows = [
    [
      ["text" => "📊 Stats", "callback_data" => "stats"],
      ["text" => "🎁 Withdraw", "callback_data" => "withdraw"]
    ],
    [
      ["text" => "🔗 My Referral Link", "callback_data" => "reflink"]
    ],
  ];
  if ($admin) $rows[] = [[ "text" => "🛠 Admin Panel", "callback_data" => "admin_panel" ]];
  return ["inline_keyboard" => $rows];
}

function adminPanelMarkup() {
  return ["inline_keyboard" => [
    [
      ["text" => "➕ Add Coupon", "callback_data" => "admin_add_coupon"],
      ["text" => "📦 Coupon Stock", "callback_data" => "admin_stock"]
    ],
    [
      ["text" => "🗂 Redeems Log", "callback_data" => "admin_redeems"]
    ],
    [
      ["text" => "⬅️ Back", "callback_data" => "back_main"]
    ]
  ]];
}

// ---------- ADMIN ADD COUPON STATE ----------
function stateDir() {
  $d = __DIR__ . "/state";
  if (!is_dir($d)) @mkdir($d, 0777, true);
  return $d;
}
function setState($tg_id, $state) { file_put_contents(stateDir()."/{$tg_id}.txt", $state); }
function getState($tg_id) {
  $f = stateDir()."/{$tg_id}.txt";
  return file_exists($f) ? trim((string)file_get_contents($f)) : "";
}
function clearState($tg_id) {
  $f = stateDir()."/{$tg_id}.txt";
  if (file_exists($f)) @unlink($f);
}

// ---------- DB HELPERS ----------
function getUser($tg_id) {
  global $pdo;
  $st = $pdo->prepare("SELECT * FROM users WHERE tg_id=:tg LIMIT 1");
  $st->execute([":tg" => $tg_id]);
  return $st->fetch();
}

function upsertUser($tg_id, $referred_by = null) {
  global $pdo;
  $u = getUser($tg_id);
  if ($u) return $u;
  $pdo->prepare("INSERT INTO users (tg_id, referred_by) VALUES (:tg, :ref)")
      ->execute([":tg" => $tg_id, ":ref" => $referred_by]);
  return getUser($tg_id);
}

function isVerifiedUser($tg_id) {
  $u = getUser($tg_id);
  return $u && !empty($u["verified"]);
}

function makeVerifyLink($tg_id) {
  global $pdo;
  $token = bin2hex(random_bytes(16));
  $pdo->prepare("UPDATE users SET verify_token=:t, verify_token_expires=NOW() + (:m || ' minutes')::interval WHERE tg_id=:tg")
      ->execute([":t" => $token, ":m" => VERIFY_TOKEN_MINUTES, ":tg" => $tg_id]);

  $base = baseUrlThisFile(); // this index.php
  return $base . "?mode=verify&uid=" . urlencode($tg_id) . "&token=" . urlencode($token);
}

function notifyAdminRedeem($tg_id, $coupon, $beforePoints, $afterPoints) {
  global $ADMIN_ID;
  $time = date("Y-m-d H:i:s");
  $msg = "✅ <b>Coupon Redeemed</b>\n"
       . "👤 User: <code>{$tg_id}</code>\n"
       . "🎟 Code: <code>{$coupon}</code>\n"
       . "🕒 Time: <code>{$time}</code>\n"
       . "⭐ Points: <b>{$beforePoints}</b> → <b>{$afterPoints}</b>";
  sendMessage($ADMIN_ID, $msg);
}

// ---------- JOIN CHECK ----------
function checkMember($user_id, $chat) {
  $r = tg("getChatMember", ["chat_id" => $chat, "user_id" => $user_id]);
  if (!$r || empty($r["ok"])) return false;
  $status = $r["result"]["status"] ?? "";
  return in_array($status, ["member", "administrator", "creator"], true);
}

function allJoined($tg_id) {
  $chs = channelsList();
  foreach ($chs as $ch) {
    if (!$ch) continue;
    if (!checkMember($tg_id, $ch)) return false;
  }
  return true;
}

// ---------- BOT USERNAME ----------
function botUsername() {
  global $BOT_USERNAME;
  if ($BOT_USERNAME) return ltrim($BOT_USERNAME, "@");
  $me = tg("getMe");
  return $me["result"]["username"] ?? "";
}

// =======================================================
// ================= WEBSITE VERIFY (GET) =================
// =======================================================
function htmlVerifyUI($title, $msg, $doUrl) {
  $btn = $doUrl ? '<a class="btn" href="'.htmlspecialchars($doUrl).'">✅ Verify Now</a>' : '';
  return '<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>'.htmlspecialchars($title).'</title>
<style>
  body{margin:0;height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(#061020,#0b1220);font-family:system-ui;color:#fff;}
  .card{width:min(560px,92vw);background:#0f1a2b;border-radius:22px;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.45);}
  .h{font-size:28px;font-weight:800;margin:0 0 10px}
  .p{opacity:.85;line-height:1.4;margin:0 0 16px;font-size:16px}
  .btn{display:block;text-align:center;background:#2f6dff;color:#fff;padding:14px 16px;border-radius:14px;text-decoration:none;font-weight:800;font-size:18px}
  .sub{margin-top:14px;opacity:.6}
</style>
</head>
<body>
  <div class="card">
    <div class="h">🔐 '.htmlspecialchars($title).'</div>
    <div class="p">'.htmlspecialchars($msg).'</div>
    '.$btn.'
    <div class="sub">Ready.</div>
  </div>
</body>
</html>';
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
  $mode  = $_GET["mode"] ?? "";
  if ($mode !== "verify") { echo "OK"; exit; }

  if (!dbReady()) { echo htmlVerifyUI("DB Error", "Database not connected.", null); exit; }

  $uid   = (int)($_GET["uid"] ?? 0);
  $token = trim($_GET["token"] ?? "");
  $step  = $_GET["step"] ?? "";

  if (!$uid || !$token) { echo htmlVerifyUI("Invalid", "Invalid verification link.", null); exit; }

  // Show UI first (like photo 3)
  if ($step !== "do") {
    $doUrl = baseUrlThisFile()."?mode=verify&uid=".$uid."&token=".urlencode($token)."&step=do";
    echo htmlVerifyUI("Verification", "Tap below to verify. This blocks fake referrals and keeps rewards fair.", $doUrl);
    exit;
  }

  // Step=do -> verify now
  global $pdo;

  // Device token cookie (1 device ≈ 1 TG ID)
  $cookieName = "device_token";
  if (empty($_COOKIE[$cookieName]) || strlen($_COOKIE[$cookieName]) < 20) {
    $dt = bin2hex(random_bytes(16));
    setcookie($cookieName, $dt, time() + 3600*24*365, "/", "", true, true);
    $_COOKIE[$cookieName] = $dt;
  }
  $deviceToken = $_COOKIE[$cookieName];

  // Ensure user exists
  try {
    $pdo->prepare("INSERT INTO users (tg_id) VALUES (:tg) ON CONFLICT (tg_id) DO NOTHING")
        ->execute([":tg" => $uid]);
  } catch (Exception $e) {
    echo htmlVerifyUI("Error", "User create failed.", null); exit;
  }

  // Validate token + expiry
  $st = $pdo->prepare("SELECT verified, verify_token, verify_token_expires FROM users WHERE tg_id=:tg LIMIT 1");
  $st->execute([":tg" => $uid]);
  $u = $st->fetch();

  if (!$u) { echo htmlVerifyUI("Error", "User not found.", null); exit; }
  if (!empty($u["verified"])) {
    // Already verified: redirect back to Telegram
    $bot = botUsername();
    header("Location: https://t.me/".$bot);
    exit;
  }
  if (($u["verify_token"] ?? "") !== $token) {
    echo htmlVerifyUI("Invalid", "This verify link is not valid. Go back and press Check Verification again.", null);
    exit;
  }
  $exp = $u["verify_token_expires"] ?? "";
  if (!$exp || strtotime($exp) < time()) {
    echo htmlVerifyUI("Expired", "Verify link expired. Go back and press Check Verification again.", null);
    exit;
  }

  // Device lock: device_links(device_token) can belong to only one tg_id
  $st = $pdo->prepare("SELECT tg_id FROM device_links WHERE device_token=:dt LIMIT 1");
  $st->execute([":dt" => $deviceToken]);
  $existing = $st->fetch();
  if ($existing && (int)$existing["tg_id"] !== $uid) {
    echo htmlVerifyUI("Blocked", "❌ This device is already registered with another Telegram ID.", null);
    exit;
  }

  // Link device -> tg_id
  try {
    $pdo->prepare("INSERT INTO device_links (device_token, tg_id) VALUES (:dt,:tg)
                   ON CONFLICT (device_token) DO UPDATE SET tg_id=EXCLUDED.tg_id")
        ->execute([":dt" => $deviceToken, ":tg" => $uid]);
  } catch (Exception $e) {
    echo htmlVerifyUI("DB Error", "Device lock table missing. Create device_links table in Supabase.", null);
    exit;
  }

  // Mark verified & clear token
  try {
    $pdo->prepare("UPDATE users
                   SET verified=true, verified_at=NOW(), verify_token=NULL, verify_token_expires=NULL
                   WHERE tg_id=:tg")
        ->execute([":tg" => $uid]);
  } catch (Exception $e) {
    echo htmlVerifyUI("DB Error", "Missing columns in users table. Add verified/verify_token columns.", null);
    exit;
  }

  // Redirect back to Telegram bot
  $bot = botUsername();
  header("Location: https://t.me/".$bot);
  exit;
}

// =======================================================
// ================== WEBHOOK (POST) =====================
// =======================================================
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { http_response_code(200); echo "OK"; exit; }

if (!dbReady()) {
  if (isset($update["message"])) {
    $chat_id = $update["message"]["chat"]["id"];
    sendMessage($chat_id, "⚠️ Server database not connected.\nCheck Render ENV DB_HOST/DB_USER/DB_PASS and redeploy.");
  }
  http_response_code(200); echo "OK"; exit;
}

// ---------- MESSAGES ----------
if (isset($update["message"])) {
  $m = $update["message"];
  $chat_id = $m["chat"]["id"];
  $from_id = $m["from"]["id"];
  $text = trim($m["text"] ?? "");

  // Admin add coupon mode
  if (isAdmin($from_id) && getState($from_id) === "await_coupon" && $text !== "" && strpos($text, "/") !== 0) {
    $codes = preg_split("/\r\n|\n|\r|,|\s+/", $text);
    $codes = array_values(array_filter(array_map("trim", $codes)));
    $added = 0;

    foreach ($codes as $c) {
      if ($c === "") continue;
      try {
        global $pdo;
        $pdo->prepare("INSERT INTO coupons (code, added_by) VALUES (:c, :a)")
            ->execute([":c" => $c, ":a" => $from_id]);
        $added++;
      } catch (Exception $e) {}
    }

    clearState($from_id);
    sendMessage($chat_id, "✅ Added <b>{$added}</b> coupon(s).", mainMenuMarkup(true));
    http_response_code(200); echo "OK"; exit;
  }

  // /start with referral
  if (strpos($text, "/start") === 0) {
    $parts = explode(" ", $text, 2);
    $ref = null;
    if (count($parts) === 2 && ctype_digit(trim($parts[1]))) $ref = (int)trim($parts[1]);

    $existing = getUser($from_id);
    if (!$existing) {
      $referred_by = null;
      if ($ref && $ref != $from_id) {
        $refUser = getUser($ref);
        if ($refUser) $referred_by = $ref;
      }

      upsertUser($from_id, $referred_by);

      // Give referrer +1 point only once (new user)
      if ($referred_by) {
        try {
          global $pdo;
          $pdo->prepare("UPDATE users SET points = points + 1, total_referrals = total_referrals + 1 WHERE tg_id=:r")
              ->execute([":r" => $referred_by]);
        } catch (Exception $e) {}
      }
    } else {
      upsertUser($from_id, null);
    }

    if (isVerifiedUser($from_id)) {
      sendMessage($chat_id, "🎉 <b>WELCOME!</b>\nChoose an option:", mainMenuMarkup(isAdmin($from_id)));
    } else {
      sendMessage($chat_id, "✅ <b>Join all channels</b> then verify.\n\nAfter joining, press <b>Check Verification</b>.", joinMarkup());
    }

    http_response_code(200); echo "OK"; exit;
  }

  // Any other message
  upsertUser($from_id, null);
  if (isVerifiedUser($from_id)) {
    sendMessage($chat_id, "🏠 Main Menu:", mainMenuMarkup(isAdmin($from_id)));
  } else {
    sendMessage($chat_id, "✅ Join all channels then verify.\nPress <b>Check Verification</b>.", joinMarkup());
  }

  http_response_code(200); echo "OK"; exit;
}

// ---------- CALLBACKS ----------
if (isset($update["callback_query"])) {
  $cq = $update["callback_query"];
  $data = $cq["data"] ?? "";
  $from_id = $cq["from"]["id"];
  $chat_id = $cq["message"]["chat"]["id"];

  upsertUser($from_id, null);

  // Check join -> send NEW messages + verification menu
  if ($data === "check_join") {
    answerCallback($cq["id"], "Checking...");

    if (allJoined($from_id)) {
      sendMessage($chat_id, "✅ <b>Channel join verified!</b>\nNext: <b>Verify Yourself</b>");

      $url = makeVerifyLink($from_id);
      sendMessage(
        $chat_id,
        "🔐 <b>Verification</b>\nTap below to verify. This blocks fake referrals and keeps rewards fair.",
        verifyMenuMarkup($url)
      );
    } else {
      sendMessage($chat_id, "❌ <b>Verification failed.</b>\nJoin all channels then try again.", joinMarkup());
    }

    http_response_code(200); echo "OK"; exit;
  }

  // Check verified after returning from website
  if ($data === "check_verified") {
    answerCallback($cq["id"], "Checking...");

    if (isVerifiedUser($from_id)) {
      sendMessage($chat_id, "✅ <b>Verified Successfully!</b>\nYou can now use the bot.", mainMenuMarkup(isAdmin($from_id)));
    } else {
      $url = makeVerifyLink($from_id);
      sendMessage(
        $chat_id,
        "❌ <b>Not verified yet.</b>\n\n1) Tap ✅ Verify Now\n2) Complete verification on website\n3) Come back and tap ✅ Check Verification",
        verifyMenuMarkup($url)
      );
    }

    http_response_code(200); echo "OK"; exit;
  }

  // Block all other actions if not verified
  if (!isVerifiedUser($from_id)) {
    answerCallback($cq["id"], "Verify first", true);
    sendMessage($chat_id, "🔐 Please verify first.\nJoin channels then press <b>Check Verification</b>.", joinMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // STATS
  if ($data === "stats") {
    $u = getUser($from_id);
    answerCallback($cq["id"], "Stats");
    sendMessage(
      $chat_id,
      "📊 <b>Your Stats</b>\n\n"
      . "⭐ Points: <b>{$u['points']}</b>\n"
      . "👥 Referrals: <b>{$u['total_referrals']}</b>\n\n"
      . "🎁 Need <b>".POINTS_PER_WITHDRAW."</b> points for 1 coupon.",
      mainMenuMarkup(isAdmin($from_id))
    );
    http_response_code(200); echo "OK"; exit;
  }

  // REF LINK
  if ($data === "reflink") {
    $bot = botUsername();
    $link = $bot ? "https://t.me/{$bot}?start={$from_id}" : "Set BOT_USERNAME in ENV";
    answerCallback($cq["id"], "Link");
    sendMessage($chat_id, "🔗 <b>Your Referral Link</b>\n\n<code>{$link}</code>", mainMenuMarkup(isAdmin($from_id)));
    http_response_code(200); echo "OK"; exit;
  }

  // WITHDRAW (deduct ONLY 3)
  if ($data === "withdraw") {
    $u = getUser($from_id);
    $need = POINTS_PER_WITHDRAW;

    if ((int)$u["points"] < $need) {
      answerCallback($cq["id"], "Not enough points", true);
      sendMessage($chat_id, "❌ Not enough points.\nYou have <b>{$u['points']}</b>, need <b>{$need}</b>.", mainMenuMarkup(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;
    }

    try {
      global $pdo;
      $pdo->beginTransaction();

      $st = $pdo->query("SELECT id, code FROM coupons WHERE used=false ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
      $coupon = $st->fetch();

      if (!$coupon) {
        $pdo->rollBack();
        answerCallback($cq["id"], "Out of stock", true);
        sendMessage($chat_id, "⚠️ Coupons out of stock. Try later.", mainMenuMarkup(isAdmin($from_id)));
        http_response_code(200); echo "OK"; exit;
      }

      $before = (int)$u["points"];
      $after  = $before - $need;

      $st = $pdo->prepare("UPDATE users SET points = points - :need WHERE tg_id=:tg AND points >= :need");
      $st->execute([":need" => $need, ":tg" => $from_id]);
      if ($st->rowCount() < 1) {
        $pdo->rollBack();
        answerCallback($cq["id"], "Not enough points", true);
        http_response_code(200); echo "OK"; exit;
      }

      $pdo->prepare("UPDATE coupons SET used=true, used_by=:tg, used_at=NOW() WHERE id=:id")
          ->execute([":tg" => $from_id, ":id" => $coupon["id"]]);

      $pdo->prepare("INSERT INTO withdrawals (tg_id, coupon_code, points_deducted) VALUES (:tg,:c,:d)")
          ->execute([":tg" => $from_id, ":c" => $coupon["code"], ":d" => $need]);

      $pdo->commit();

      answerCallback($cq["id"], "Success!");
      sendMessage($chat_id, "🎉 <b>Congratulations!</b>\n\nYour Coupon:\n<code>{$coupon['code']}</code>", mainMenuMarkup(isAdmin($from_id)));
      notifyAdminRedeem($from_id, $coupon["code"], $before, $after);

      http_response_code(200); echo "OK"; exit;

    } catch (Exception $e) {
      if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
      answerCallback($cq["id"], "Error", true);
      sendMessage($chat_id, "⚠️ Error. Try again.", mainMenuMarkup(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;
    }
  }

  // ADMIN PANEL
  if ($data === "admin_panel") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    answerCallback($cq["id"], "Admin");
    sendMessage($chat_id, "🛠 <b>Admin Panel</b>", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "admin_add_coupon") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    setState($from_id, "await_coupon");
    answerCallback($cq["id"], "Send codes");
    sendMessage($chat_id, "➕ Send coupon codes now (new line / space / comma).", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "admin_stock") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    $available = (int)($pdo->query("SELECT COUNT(*) c FROM coupons WHERE used=false")->fetch()["c"] ?? 0);
    $used      = (int)($pdo->query("SELECT COUNT(*) c FROM coupons WHERE used=true")->fetch()["c"] ?? 0);
    answerCallback($cq["id"], "Stock");
    sendMessage($chat_id, "📦 <b>Coupon Stock</b>\n\n✅ Available: <b>{$available}</b>\n🧾 Used: <b>{$used}</b>", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "admin_redeems") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    $rows = $pdo->query("SELECT tg_id, coupon_code, created_at, points_deducted FROM withdrawals ORDER BY id DESC LIMIT 15")->fetchAll();
    $text = "🗂 <b>Last 15 Redeems</b>\n\n";
    if (!$rows) $text .= "No redeems yet.";
    else {
      foreach ($rows as $r) {
        $text .= "👤 <code>{$r['tg_id']}</code>\n🎟 <code>{$r['coupon_code']}</code>\n⭐ <b>{$r['points_deducted']}</b>\n🕒 <code>{$r['created_at']}</code>\n\n";
      }
    }
    answerCallback($cq["id"], "Redeems");
    sendMessage($chat_id, $text, adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "back_main") {
    answerCallback($cq["id"], "Back");
    sendMessage($chat_id, "🏠 Main Menu:", mainMenuMarkup(isAdmin($from_id)));
    http_response_code(200); echo "OK"; exit;
  }

  answerCallback($cq["id"], "OK");
  http_response_code(200); echo "OK"; exit;
}

http_response_code(200);
echo "OK";
