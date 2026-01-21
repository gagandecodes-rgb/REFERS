<?php
/**
 * FULL SINGLE-FILE TELEGRAM REFER BOT (FAST) + WEB VERIFY PAGE INSIDE SAME index.php
 *
 * ✅ 3 refer = 1 coupon (deduct ONLY 3 points even if user has 5/6/7…)
 * ✅ 4 force-join channels (checked ONLY when user taps “✅ Check Verification”)
 * ✅ After “Check Verification” -> NEW message “Channel join verified! Next: Verify Yourself”
 * ✅ Then NEW message with BLUE “✅ Verify Now” button that opens website (this same index.php)
 * ✅ Website verifies + “1 device = 1 Telegram ID” (cookie device token + DB table device_links)
 * ✅ Coupon stock, remove/mark used, withdraw log
 * ✅ Admin panel: Add Coupon, Stock, Redeems Log
 * ✅ Admin gets message when coupon redeemed (time + points before/after)
 *
 * REQUIRED Render ENV:
 * BOT_TOKEN, ADMIN_ID
 * DB_HOST, DB_PORT(5432), DB_NAME(postgres), DB_USER, DB_PASS
 * FORCE_JOIN_1..FORCE_JOIN_4 (e.g. @channelname)
 * BOT_USERNAME (optional for referral link speed)
 *
 * REQUIRED DB tables/columns (run in Supabase SQL editor):
 * - users: tg_id (PK bigint), referred_by bigint, points int, total_referrals int,
 *          verified boolean, verified_at timestamptz, verify_token text, verify_token_expires timestamptz
 * - coupons: id bigserial, code text unique, used boolean, used_by bigint, used_at timestamptz, added_by bigint
 * - withdrawals: id bigserial, tg_id bigint, coupon_code text, points_deducted int, created_at timestamptz
 * - device_links: device_token text PK, tg_id bigint UNIQUE
 */

// ---------- BASIC SETTINGS ----------
error_reporting(0);
ini_set("display_errors", 0);

define("POINTS_PER_WITHDRAW", 3);
define("VERIFY_TOKEN_MINUTES", 10);
define("TG_CONNECT_TIMEOUT", 2);
define("TG_TIMEOUT", 6);

// ---------- ENV ----------
$BOT_TOKEN    = getenv("BOT_TOKEN");
$ADMIN_ID     = getenv("ADMIN_ID");
$BOT_USERNAME = getenv("BOT_USERNAME"); // optional (no @)

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

// ---------- URL (for verify page) ----------
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
  // like your screenshot
  $rows[] = [[ "text" => "🔐 Verify Yourself", "callback_data" => "show_verify_info" ]];
  $rows[] = [[ "text" => "✅ Check Verification", "callback_data" => "check_join" ]];
  return ["inline_keyboard" => $rows];
}

function verifyNowUrlButton($url) {
  // Blue URL button
  return ["inline_keyboard" => [
    [[ "text" => "✅ Verify Now", "url" => $url ]]
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

// ---------- ADMIN ADD COUPON STATE (small local file) ----------
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

  $base = baseUrlThisFile(); // same index.php
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

// ---------- JOIN CHECK (ONLY WHEN user taps Check Verification) ----------
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

function botUsername() {
  global $BOT_USERNAME;
  if ($BOT_USERNAME) return ltrim($BOT_USERNAME, "@");
  $me = tg("getMe");
  return $me["result"]["username"] ?? "";
}

// =======================================================
// =============== WEB VERIFY PAGE (GET) =================
// =======================================================
function htmlPage($title, $msg) {
  return '<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>'.htmlspecialchars($title).'</title>
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
    <div class="h">🔐 '.htmlspecialchars($title).'</div>
    <div class="p">'.htmlspecialchars($msg).'</div>
    <div class="p">You can close this page and return to Telegram.</div>
  </div>
</body>
</html>';
}

// Handle verify website on GET
if ($_SERVER["REQUEST_METHOD"] === "GET") {
  $mode  = $_GET["mode"] ?? "";
  $uid   = (int)($_GET["uid"] ?? 0);
  $token = trim($_GET["token"] ?? "");

  // Normal health check
  if ($mode !== "verify") { echo "OK"; exit; }

  if (!dbReady()) { echo htmlPage("DB Error", "Database not connected."); exit; }
  if (!$uid || !$token) { echo htmlPage("Invalid", "Invalid verification link."); exit; }

  // Device token cookie (1 device approx = 1 TG ID)
  $cookieName = "device_token";
  if (empty($_COOKIE[$cookieName]) || strlen($_COOKIE[$cookieName]) < 20) {
    $dt = bin2hex(random_bytes(16));
    setcookie($cookieName, $dt, time() + 3600*24*365, "/", "", true, true);
    $_COOKIE[$cookieName] = $dt;
  }
  $deviceToken = $_COOKIE[$cookieName];

  // Ensure user exists
  try {
    global $pdo;
    $pdo->prepare("INSERT INTO users (tg_id) VALUES (:tg) ON CONFLICT (tg_id) DO NOTHING")
        ->execute([":tg" => $uid]);
  } catch (Exception $e) {
    echo htmlPage("Error", "User create failed."); exit;
  }

  // Validate verify token + expiry
  $u = null;
  try {
    $st = $pdo->prepare("SELECT verified, verify_token, verify_token_expires FROM users WHERE tg_id=:tg LIMIT 1");
    $st->execute([":tg" => $uid]);
    $u = $st->fetch();
  } catch (Exception $e) {}

  if (!$u) { echo htmlPage("Error", "User not found."); exit; }
  if (!empty($u["verified"])) { echo htmlPage("Verified", "Already verified. You can use the bot now."); exit; }

  if (($u["verify_token"] ?? "") !== $token) {
    echo htmlPage("Invalid", "This verify link is not valid. Go back to Telegram and press Check Verification again.");
    exit;
  }

  $exp = $u["verify_token_expires"] ?? "";
  if (!$exp || strtotime($exp) < time()) {
    echo htmlPage("Expired", "Verify link expired. Go back to Telegram and press Check Verification again.");
    exit;
  }

  // Device lock: device_links(device_token -> tg_id) must be unique
  try {
    $st = $pdo->prepare("SELECT tg_id FROM device_links WHERE device_token=:dt LIMIT 1");
    $st->execute([":dt" => $deviceToken]);
    $existing = $st->fetch();
    if ($existing && (int)$existing["tg_id"] !== $uid) {
      echo htmlPage("Blocked", "This device is already registered with another Telegram ID.");
      exit;
    }
  } catch (Exception $e) {
    echo htmlPage("DB Error", "Device lock check failed. Make sure device_links table exists.");
    exit;
  }

  // Link device -> this uid (first-time)
  try {
    $pdo->prepare("INSERT INTO device_links (device_token, tg_id) VALUES (:dt,:tg)
                   ON CONFLICT (device_token) DO UPDATE SET tg_id=EXCLUDED.tg_id")
        ->execute([":dt" => $deviceToken, ":tg" => $uid]);
  } catch (Exception $e) {
    echo htmlPage("DB Error", "Device link failed. Make sure device_links exists.");
    exit;
  }

  // Mark user verified and clear token
  try {
    $pdo->prepare("UPDATE users
                   SET verified=true, verified_at=NOW(), verify_token=NULL, verify_token_expires=NULL
                   WHERE tg_id=:tg")
        ->execute([":tg" => $uid]);
  } catch (Exception $e) {
    echo htmlPage("DB Error", "Verification update failed. Make sure users has verified/verify_token columns.");
    exit;
  }

  echo htmlPage("Verified", "Verification successful. Return to Telegram and press /start.");
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

    // If verified -> main menu else join screen
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

  // Just instructions
  if ($data === "show_verify_info") {
    answerCallback($cq["id"], "Follow steps");
    sendMessage(
      $chat_id,
      "✅ <b>Channel join verified?</b>\nNext: <b>Verify Yourself</b>\n\n"
      . "1) Join all channels\n"
      . "2) Tap <b>✅ Check Verification</b>\n"
      . "3) Then tap <b>✅ Verify Now</b> (blue button).",
      joinMarkup()
    );
    http_response_code(200); echo "OK"; exit;
  }

  // CHECK JOIN -> send NEW messages (as you requested)
  if ($data === "check_join") {
    answerCallback($cq["id"], "Checking...");

    if (allJoined($from_id)) {
      // NEW MESSAGE 1
      sendMessage($chat_id, "✅ <b>Channel join verified!</b>\nNext: <b>Verify Yourself</b>");

      // NEW MESSAGE 2 (blue button opens website)
      $url = makeVerifyLink($from_id);
      sendMessage(
        $chat_id,
        "🔐 <b>Verification</b>\nTap below to verify. This blocks fake referrals and keeps rewards fair.",
        verifyNowUrlButton($url)
      );
    } else {
      sendMessage(
        $chat_id,
        "❌ <b>Verification failed.</b>\nYou must join all channels to use the bot.",
        joinMarkup()
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

      // Avoid waiting on locks
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

      // Deduct ONLY 3
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
