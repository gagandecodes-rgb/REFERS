<?php
/**
 * ✅ FULL SINGLE-FILE index.php (Telegram Bot + Website Verify in SAME file)
 *
 * FEATURES:
 * ✅ 3 refer = 1 coupon (referral adds +1 point per new user; points -> withdraw_points table)
 * ✅ Coupon stock + mark used + withdrawals log
 * ✅ Admin panel: add coupon (500/1K/2K/4K) / stock / redeems log
 * ✅ Admin: change withdraw points (500/1K/2K/4K)
 * ✅ Admin: 🎁 Get Code (Free) -> pick amount -> enter quantity -> bot gives THAT MANY coupons
 * ✅ IMPORTANT: Free Get Code uses ONLY coupons where used=false in DB (available stock)
 * ✅ TOTAL force-join channels (FORCE_JOIN_1..FORCE_JOIN_10 supported)
 * ✅ Force-join checked ONLY when user clicks "✅ Check Verification"
 * ✅ AFTER join verified -> NEW message + Verification message
 * ✅ NO "Verify Yourself" button (removed)
 * ✅ Verification message has:
 *    - ✅ Verify Now (URL opens website verify page)
 *    - ✅ Check Verification (callback)
 * ✅ Website verify page:
 *    - shows UI first
 *    - user clicks ✅ Verify Now
 *    - verifies in DB (verify_token + expiry)
 *    - enforces "1 device token = 1 tg id" using device_links table
 *    - redirects back to Telegram bot
 *
 * REQUIRED Render ENV:
 * BOT_TOKEN, ADMIN_ID, BOT_USERNAME (no @)
 * DB_HOST, DB_PORT(5432), DB_NAME(postgres), DB_USER, DB_PASS
 * FORCE_JOIN_1..FORCE_JOIN_10 (e.g. @channelname)
 *
 * REQUIRED DB (Supabase):
 * users(tg_id PK bigint, referred_by bigint, points int default 0, total_referrals int default 0,
 *       verified boolean default false, verified_at timestamptz, verify_token text, verify_token_expires timestamptz)
 * coupons(id bigserial, code text unique, amount int, used boolean default false, used_by bigint, used_at timestamptz, added_by bigint, created_at timestamptz default now())
 * withdrawals(id bigserial, tg_id bigint, coupon_code text, points_deducted int, created_at timestamptz default now())
 * device_links(device_token text PK, tg_id bigint UNIQUE, created_at timestamptz default now())
 * withdraw_points(amount int PK, points int)
 */

error_reporting(0);
ini_set("display_errors", 0);

define("VERIFY_TOKEN_MINUTES", 10);
define("TG_CONNECT_TIMEOUT", 2);
define("TG_TIMEOUT", 6);

// Free-get safety limit
define("FREE_GET_MAX_QTY", 50);

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

function sendLongText($chat_id, $header, $lines, $reply_markup = null) {
  $max = 3500; // keep below Telegram 4096 limit
  $chunk = $header;
  foreach ($lines as $ln) {
    $add = $ln . "\n";
    if (strlen($chunk) + strlen($add) > $max) {
      sendMessage($chat_id, rtrim($chunk), $reply_markup);
      $chunk = "";
      $reply_markup = null; // attach keyboard only once
    }
    $chunk .= $add;
  }
  if (trim($chunk) !== "") sendMessage($chat_id, rtrim($chunk), $reply_markup);
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

function botUsername() {
  global $BOT_USERNAME;
  $u = ltrim((string)$BOT_USERNAME, "@");
  if ($u) return $u;
  $me = tg("getMe");
  return $me["result"]["username"] ?? "";
}

// ---------- CHANNELS (SUPPORT UP TO 10) ----------
function channelsList() {
  return array_values(array_filter([
    normalizeChannel(getenv("FORCE_JOIN_1")),
    normalizeChannel(getenv("FORCE_JOIN_2")),
    normalizeChannel(getenv("FORCE_JOIN_3")),
    normalizeChannel(getenv("FORCE_JOIN_4")),
    normalizeChannel(getenv("FORCE_JOIN_5")),
    normalizeChannel(getenv("FORCE_JOIN_6")),
    normalizeChannel(getenv("FORCE_JOIN_7")),
    normalizeChannel(getenv("FORCE_JOIN_8")),
    normalizeChannel(getenv("FORCE_JOIN_9")),
    normalizeChannel(getenv("FORCE_JOIN_10")),
  ]));
}

// ---------- UI ----------
function joinMarkup() {
  $chs = channelsList();
  $rows = [];
  $i = 1;
  foreach ($chs as $ch) {
    $rows[] = [[
      "text" => "➕ Join $i",
      "url"  => "https://t.me/" . ltrim($ch, "@")
    ]];
    $i++;
  }
  $rows[] = [[ "text" => "✅ Check Verification", "callback_data" => "check_join" ]];
  return ["inline_keyboard" => $rows];
}

function verifyMenuMarkup($verifyUrl) {
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
      ["text" => "⚙ Change Withdraw Points", "callback_data" => "admin_points"]
    ],
    [
      ["text" => "🎁 Get Code (Free)", "callback_data" => "admin_free_get"]
    ],
    [
      ["text" => "⬅️ Back", "callback_data" => "back_main"]
    ]
  ]];
}

function adminAmountOptionsKb($prefix, $backCb) {
  return ["inline_keyboard" => [
    [[ "text"=>"500", "callback_data"=>"{$prefix}_500" ]],
    [[ "text"=>"1K",  "callback_data"=>"{$prefix}_1000" ]],
    [[ "text"=>"2K",  "callback_data"=>"{$prefix}_2000" ]],
    [[ "text"=>"4K",  "callback_data"=>"{$prefix}_4000" ]],
    [[ "text"=>"⬅️ Back", "callback_data"=>$backCb ]]
  ]];
}

// ---------- STATE ----------
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
  $pdo->prepare("UPDATE users
                 SET verify_token=:t,
                     verify_token_expires=NOW() + (:m || ' minutes')::interval
                 WHERE tg_id=:tg")
      ->execute([":t" => $token, ":m" => VERIFY_TOKEN_MINUTES, ":tg" => $tg_id]);

  $base = baseUrlThisFile();
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

function getWithdrawPoints($amount) {
  global $pdo;
  try {
    $st = $pdo->prepare("SELECT points FROM withdraw_points WHERE amount=:a");
    $st->execute([":a" => (int)$amount]);
    $r = $st->fetch();
    return $r ? (int)$r["points"] : 0;
  } catch (Exception $e) {
    return 0;
  }
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

  // Show UI first
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
  $pdo->prepare("INSERT INTO users (tg_id) VALUES (:tg) ON CONFLICT (tg_id) DO NOTHING")
      ->execute([":tg" => $uid]);

  // Validate token + expiry
  $st = $pdo->prepare("SELECT verified, verify_token, verify_token_expires FROM users WHERE tg_id=:tg LIMIT 1");
  $st->execute([":tg" => $uid]);
  $u = $st->fetch();

  if (!$u) { echo htmlVerifyUI("Error", "User not found.", null); exit; }
  if (!empty($u["verified"])) {
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
  $pdo->prepare("INSERT INTO device_links (device_token, tg_id) VALUES (:dt,:tg)
                 ON CONFLICT (device_token) DO UPDATE SET tg_id=EXCLUDED.tg_id")
      ->execute([":dt" => $deviceToken, ":tg" => $uid]);

  // Mark verified & clear token
  $pdo->prepare("UPDATE users
                 SET verified=true, verified_at=NOW(), verify_token=NULL, verify_token_expires=NULL
                 WHERE tg_id=:tg")
      ->execute([":tg" => $uid]);

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

  $stState = getState($from_id);

  // State: admin add coupons
  if (isAdmin($from_id) && preg_match("/^await_coupon_(500|1000|2000|4000)$/", $stState, $mm) && $text !== "" && strpos($text, "/") !== 0) {
    $amount = (int)$mm[1];
    $codes = preg_split("/\r\n|\n|\r|,|\s+/", $text);
    $codes = array_values(array_filter(array_map("trim", $codes)));
    $added = 0;
    foreach ($codes as $c) {
      if ($c === "") continue;
      try {
        $pdo->prepare("INSERT INTO coupons (code, amount, added_by) VALUES (:c,:amt,:a)")
            ->execute([":c" => $c, ":amt" => $amount, ":a" => $from_id]);
        $added++;
      } catch (Exception $e) {}
    }
    clearState($from_id);
    sendMessage($chat_id, "✅ Added <b>{$added}</b> coupon(s) for <b>{$amount}</b>.", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // State: admin set points
  if (isAdmin($from_id) && preg_match("/^setp_(500|1000|2000|4000)$/", $stState, $mm) && $text !== "" && strpos($text, "/") !== 0) {
    $amount = (int)$mm[1];
    $pts = (int)$text;
    if ($pts < 0) $pts = 0;
    $pdo->prepare("INSERT INTO withdraw_points (amount, points)
                   VALUES (:a,:p)
                   ON CONFLICT (amount) DO UPDATE SET points=EXCLUDED.points")
        ->execute([":a"=>$amount, ":p"=>$pts]);
    clearState($from_id);
    sendMessage($chat_id, "✅ Points updated for <b>{$amount}</b> → <b>{$pts}</b> points.", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // State: ADMIN FREE GET -> quantity input (only picks used=false coupons)
  if (isAdmin($from_id) && preg_match("/^freeqty_(500|1000|2000|4000)$/", $stState, $mm) && $text !== "" && strpos($text, "/") !== 0) {
    $amount = (int)$mm[1];
    $qty = (int)trim($text);
    if ($qty < 1) $qty = 1;
    if ($qty > FREE_GET_MAX_QTY) $qty = FREE_GET_MAX_QTY;

    try {
      $pdo->beginTransaction();

      // Lock + pick from available stock only (used=false)
      $st = $pdo->prepare("
        SELECT id, code
        FROM coupons
        WHERE used=false AND amount=:amt
        ORDER BY id ASC
        LIMIT :lim
        FOR UPDATE SKIP LOCKED
      ");
      $st->bindValue(":amt", $amount, PDO::PARAM_INT);
      $st->bindValue(":lim", $qty, PDO::PARAM_INT);
      $st->execute();
      $rows = $st->fetchAll();

      if (!$rows) {
        $pdo->rollBack();
        clearState($from_id);
        sendMessage($chat_id, "⚠️ No coupons available (used=false) for <b>{$amount}</b> right now.", adminPanelMarkup());
        http_response_code(200); echo "OK"; exit;
      }

      $ids = [];
      $codes = [];
      foreach ($rows as $r) { $ids[] = (int)$r["id"]; $codes[] = $r["code"]; }

      // Mark them used by ADMIN so they can't be taken again
      $in = implode(",", array_fill(0, count($ids), "?"));
      $sql = "UPDATE coupons SET used=true, used_by=?, used_at=NOW() WHERE id IN ($in)";
      $params = array_merge([(int)$from_id], $ids);
      $pdo->prepare($sql)->execute($params);

      // Log as withdrawals (0 points) so admin_redeems shows it too
      $ins = $pdo->prepare("INSERT INTO withdrawals (tg_id, coupon_code, points_deducted) VALUES (:tg,:c,0)");
      foreach ($codes as $c) $ins->execute([":tg" => $from_id, ":c" => $c]);

      $pdo->commit();
      clearState($from_id);

      $got = count($codes);
      $header = "🎁 <b>Free Codes ({$amount})</b>\n✅ Given: <b>{$got}</b>\n\n";
      $lines = array_map(function($c){ return "<code>{$c}</code>"; }, $codes);

      sendLongText($chat_id, $header, $lines, adminPanelMarkup());

      if ($got < $qty) {
        sendMessage($chat_id, "⚠️ Stock was less than requested.\nRequested: <b>{$qty}</b>\nGiven: <b>{$got}</b>", adminPanelMarkup());
      }

      http_response_code(200); echo "OK"; exit;

    } catch (Exception $e) {
      if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
      clearState($from_id);
      sendMessage($chat_id, "⚠️ Error while getting free codes. Try again.", adminPanelMarkup());
      http_response_code(200); echo "OK"; exit;
    }
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

  // ACK fast
  answerCallback($cq["id"], "…");

  upsertUser($from_id, null);

  // Check join -> NEW messages + verification menu
  if ($data === "check_join") {
    if (allJoined($from_id)) {
      sendMessage($chat_id, "✅ <b>Channel join verified!</b>\nNow verify on website.");

      $url = makeVerifyLink($from_id);
      sendMessage(
        $chat_id,
        "🔐 <b>Verification</b>\nTap below to verify.",
        verifyMenuMarkup($url)
      );
    } else {
      sendMessage($chat_id, "❌ <b>Verification failed.</b>\nJoin all channels then try again.", joinMarkup());
    }

    http_response_code(200); echo "OK"; exit;
  }

  // Check verified after returning from website
  if ($data === "check_verified") {
    if (isVerifiedUser($from_id)) {
      sendMessage($chat_id, "✅ <b>Verified Successfully!</b>\nYou can now use the bot.", mainMenuMarkup(isAdmin($from_id)));
    } else {
      $url = makeVerifyLink($from_id);
      sendMessage(
        $chat_id,
        "❌ <b>Not verified yet.</b>\n\n1) Tap ✅ Verify Now\n2) Complete verification\n3) Come back and tap ✅ Check Verification",
        verifyMenuMarkup($url)
      );
    }

    http_response_code(200); echo "OK"; exit;
  }

  // Block all other actions if not verified
  if (!isVerifiedUser($from_id)) {
    sendMessage($chat_id, "🔐 Please verify first.\nJoin channels then press <b>Check Verification</b>.", joinMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // STATS
  if ($data === "stats") {
    $u = getUser($from_id);
    sendMessage(
      $chat_id,
      "📊 <b>Your Stats</b>\n\n"
      . "⭐ Points: <b>{$u['points']}</b>\n"
      . "👥 Referrals: <b>{$u['total_referrals']}</b>",
      mainMenuMarkup(isAdmin($from_id))
    );
    http_response_code(200); echo "OK"; exit;
  }

  // REF LINK
  if ($data === "reflink") {
    $bot = botUsername();
    $link = $bot ? "https://t.me/{$bot}?start={$from_id}" : "Set BOT_USERNAME in ENV";
    sendMessage($chat_id, "🔗 <b>Your Referral Link</b>\n\n<code>{$link}</code>", mainMenuMarkup(isAdmin($from_id)));
    http_response_code(200); echo "OK"; exit;
  }

  // WITHDRAW MENU (show points needed)
  if ($data === "withdraw") {
    $p500 = getWithdrawPoints(500);
    $p1k  = getWithdrawPoints(1000);
    $p2k  = getWithdrawPoints(2000);
    $p4k  = getWithdrawPoints(4000);

    $kb = ["inline_keyboard" => [
      [[ "text" => "🎁 500 (need {$p500} pts)", "callback_data" => "wd_500" ]],
      [[ "text" => "🎁 1K (need {$p1k} pts)",  "callback_data" => "wd_1000" ]],
      [[ "text" => "🎁 2K (need {$p2k} pts)",  "callback_data" => "wd_2000" ]],
      [[ "text" => "🎁 4K (need {$p4k} pts)",  "callback_data" => "wd_4000" ]],
      [[ "text" => "⬅️ Back", "callback_data" => "back_main" ]]
    ]];

    sendMessage($chat_id, "🎁 <b>Choose withdraw option</b>", $kb);
    http_response_code(200); echo "OK"; exit;
  }

  // WITHDRAW PROCESS
  if (preg_match("/^wd_(500|1000|2000|4000)$/", $data, $m)) {
    $amount = (int)$m[1];
    $need = getWithdrawPoints($amount);
    $u = getUser($from_id);

    if ($need <= 0) {
      sendMessage($chat_id, "⚠️ Withdraw points not set for {$amount}. Ask admin.", mainMenuMarkup(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;
    }

    if ((int)$u["points"] < $need) {
      sendMessage($chat_id, "❌ Not enough points.\nYou have <b>{$u['points']}</b>, need <b>{$need}</b>.", mainMenuMarkup(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;
    }

    try {
      $pdo->beginTransaction();

      $st = $pdo->prepare("SELECT id, code FROM coupons WHERE used=false AND amount=:amt ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
      $st->execute([":amt"=>$amount]);
      $coupon = $st->fetch();

      if (!$coupon) {
        $pdo->rollBack();
        sendMessage($chat_id, "⚠️ Coupons out of stock for <b>{$amount}</b>. Try later.", mainMenuMarkup(isAdmin($from_id)));
        http_response_code(200); echo "OK"; exit;
      }

      $before = (int)$u["points"];
      $after  = $before - $need;

      $st = $pdo->prepare("UPDATE users SET points = points - :need WHERE tg_id=:tg AND points >= :need");
      $st->execute([":need" => $need, ":tg" => $from_id]);
      if ($st->rowCount() < 1) {
        $pdo->rollBack();
        sendMessage($chat_id, "❌ Not enough points.", mainMenuMarkup(isAdmin($from_id)));
        http_response_code(200); echo "OK"; exit;
      }

      $pdo->prepare("UPDATE coupons SET used=true, used_by=:tg, used_at=NOW() WHERE id=:id")
          ->execute([":tg" => $from_id, ":id" => $coupon["id"]]);

      $pdo->prepare("INSERT INTO withdrawals (tg_id, coupon_code, points_deducted) VALUES (:tg,:c,:d)")
          ->execute([":tg" => $from_id, ":c" => $coupon["code"], ":d" => $need]);

      $pdo->commit();

      sendMessage($chat_id, "🎉 <b>Coupon Redeemed!</b>\n\n<code>{$coupon['code']}</code>", mainMenuMarkup(isAdmin($from_id)));
      notifyAdminRedeem($from_id, $coupon["code"], $before, $after);

      http_response_code(200); echo "OK"; exit;

    } catch (Exception $e) {
      if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
      sendMessage($chat_id, "⚠️ Error. Try again.", mainMenuMarkup(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;
    }
  }

  // ADMIN PANEL
  if ($data === "admin_panel") {
    if (!isAdmin($from_id)) { sendMessage($chat_id, "❌ Not allowed."); http_response_code(200); echo "OK"; exit; }
    sendMessage($chat_id, "🛠 <b>Admin Panel</b>", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // ADMIN: ADD COUPON -> choose amount
  if ($data === "admin_add_coupon") {
    if (!isAdmin($from_id)) { sendMessage($chat_id, "❌ Not allowed."); http_response_code(200); echo "OK"; exit; }
    sendMessage($chat_id, "➕ <b>Select coupon amount</b>", adminAmountOptionsKb("admin_add", "admin_panel"));
    http_response_code(200); echo "OK"; exit;
  }

  if (preg_match("/^admin_add_(500|1000|2000|4000)$/", $data, $m) && isAdmin($from_id)) {
    $amount = (int)$m[1];
    setState($from_id, "await_coupon_".$amount);
    sendMessage($chat_id, "➕ Send coupon codes for <b>{$amount}</b>\n(Separate by newline / space / comma)");
    http_response_code(200); echo "OK"; exit;
  }

  // ADMIN: 🎁 GET CODE (FREE)
  if ($data === "admin_free_get") {
    if (!isAdmin($from_id)) { sendMessage($chat_id, "❌ Not allowed."); http_response_code(200); echo "OK"; exit; }
    sendMessage($chat_id, "🎁 <b>Select amount to get free codes</b>", adminAmountOptionsKb("free", "admin_panel"));
    http_response_code(200); echo "OK"; exit;
  }

  if (preg_match("/^free_(500|1000|2000|4000)$/", $data, $m) && isAdmin($from_id)) {
    $amount = (int)$m[1];
    setState($from_id, "freeqty_" . $amount);
    sendMessage($chat_id, "✏️ Enter how many <b>{$amount}</b> coupons you need (max ".FREE_GET_MAX_QTY."):");
    http_response_code(200); echo "OK"; exit;
  }

  // ADMIN: STOCK (only unused / used=false)
  if ($data === "admin_stock") {
    if (!isAdmin($from_id)) { sendMessage($chat_id, "❌ Not allowed."); http_response_code(200); echo "OK"; exit; }
    $rows = $pdo->query("SELECT amount, COUNT(*) c FROM coupons WHERE used=false GROUP BY amount ORDER BY amount")->fetchAll();
    $text = "📦 <b>Coupon Stock (used=false)</b>\n\n";
    if (!$rows) $text .= "No coupons available.";
    else foreach ($rows as $r) $text .= "🎁 <b>{$r['amount']}</b>: <b>{$r['c']}</b>\n";
    sendMessage($chat_id, $text, adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // ADMIN: REDEEMS LOG
  if ($data === "admin_redeems") {
    if (!isAdmin($from_id)) { sendMessage($chat_id, "❌ Not allowed."); http_response_code(200); echo "OK"; exit; }
    $rows = $pdo->query("SELECT tg_id, coupon_code, created_at, points_deducted FROM withdrawals ORDER BY id DESC LIMIT 15")->fetchAll();
    $text = "🗂 <b>Last 15 Redeems</b>\n\n";
    if (!$rows) $text .= "No redeems yet.";
    else {
      foreach ($rows as $r) {
        $text .= "👤 <code>{$r['tg_id']}</code>\n🎟 <code>{$r['coupon_code']}</code>\n⭐ <b>{$r['points_deducted']}</b>\n🕒 <code>{$r['created_at']}</code>\n\n";
      }
    }
    sendMessage($chat_id, $text, adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // ADMIN: CHANGE POINTS
  if ($data === "admin_points") {
    if (!isAdmin($from_id)) { sendMessage($chat_id, "❌ Not allowed."); http_response_code(200); echo "OK"; exit; }
    sendMessage($chat_id, "⚙ <b>Select amount to change points</b>", adminAmountOptionsKb("setp", "admin_panel"));
    http_response_code(200); echo "OK"; exit;
  }

  if (preg_match("/^setp_(500|1000|2000|4000)$/", $data, $m) && isAdmin($from_id)) {
    $amount = (int)$m[1];
    setState($from_id, "setp_".$amount);
    sendMessage($chat_id, "✏️ Send new points for <b>{$amount}</b>:");
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "back_main") {
    sendMessage($chat_id, "🏠 Main Menu:", mainMenuMarkup(isAdmin($from_id)));
    http_response_code(200); echo "OK"; exit;
  }

  // fallback
  sendMessage($chat_id, "🏠 Main Menu:", mainMenuMarkup(isAdmin($from_id)));
  http_response_code(200); echo "OK"; exit;
}

http_response_code(200);
echo "OK";
