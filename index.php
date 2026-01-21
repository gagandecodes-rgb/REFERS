<?php
/**
 * FULL TELEGRAM BOT (Webhook) - Single index.php
 * Features:
 * - Force join 4 channels
 * - Check Verification -> NEW message -> Verify Yourself (URL)
 * - Referral link for each user
 * - Points + Stats
 * - Withdraw coupon (deduct points once, give next coupon)
 * - Admin Panel: add coupons, stock, stats, withdrawals
 * - SQLite storage (auto creates DB)
 */

/* ===================== ENV / CONFIG ===================== */

$BOT_TOKEN  = getenv("BOT_TOKEN") ?: "PASTE_BOT_TOKEN_HERE";
$ADMIN_ID   = (int)(getenv("ADMIN_ID") ?: 0);

// 4 channels usernames (with @)
$CHANNELS = [
  getenv("CHANNEL_1") ?: "@channel1",
  getenv("CHANNEL_2") ?: "@channel2",
  getenv("CHANNEL_3") ?: "@channel3",
  getenv("CHANNEL_4") ?: "@channel4",
];

// Verification page URL (opens in browser)
$VERIFY_URL = getenv("VERIFY_URL") ?: "https://yourdomain.com/verify.php";

// Optional: sign verification link
$VERIFY_SECRET = getenv("VERIFY_SECRET") ?: ""; // can be empty

// Points needed for withdraw (you previously said "deduct only 3 credits")
$WITHDRAW_COST = (int)(getenv("WITHDRAW_COST") ?: 3);

// Bot name (used for referral link)
$BOT_USERNAME = getenv("BOT_USERNAME") ?: ""; // strongly recommended to set

// Database file
$dbFile = __DIR__ . "/bot.sqlite";

/* ===================== FAST RESPONSE ===================== */
header("Content-Type: text/plain");
echo "OK"; // respond immediately to Telegram
// Continue processing in background (still within request time)
if (function_exists("fastcgi_finish_request")) { @fastcgi_finish_request(); }

/* ===================== HELPERS ===================== */

function tg($method, $data = []) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 18,
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function answerCb($cb_id, $text = "") {
  $data = ["callback_query_id" => $cb_id];
  if ($text !== "") $data["text"] = $text;
  tg("answerCallbackQuery", $data);
}

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); }

function isAdmin($user_id) {
  global $ADMIN_ID;
  return $ADMIN_ID > 0 && ((int)$user_id === (int)$ADMIN_ID);
}

function now() { return date("Y-m-d H:i:s"); }

/* ===================== DB (SQLite) ===================== */

function db() {
  static $pdo = null;
  global $dbFile;

  if ($pdo) return $pdo;

  $pdo = new PDO("sqlite:" . $dbFile);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("PRAGMA journal_mode=WAL;");
  $pdo->exec("PRAGMA synchronous=NORMAL;");

  // tables
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      tg_id INTEGER UNIQUE,
      username TEXT,
      first_name TEXT,
      points INTEGER DEFAULT 0,
      referrals INTEGER DEFAULT 0,
      referred_by INTEGER DEFAULT NULL,
      created_at TEXT
    );
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS referrals_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      new_user_tg_id INTEGER UNIQUE,
      referrer_tg_id INTEGER,
      created_at TEXT
    );
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS coupons (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      code TEXT,
      added_at TEXT,
      used INTEGER DEFAULT 0,
      used_by INTEGER DEFAULT NULL,
      used_at TEXT DEFAULT NULL
    );
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS withdrawals (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      tg_id INTEGER,
      coupon_code TEXT,
      cost INTEGER,
      created_at TEXT
    );
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_state (
      admin_id INTEGER PRIMARY KEY,
      mode TEXT,
      updated_at TEXT
    );
  ");

  return $pdo;
}

function getUser($tg_id) {
  $st = db()->prepare("SELECT * FROM users WHERE tg_id = ?");
  $st->execute([(int)$tg_id]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function upsertUser($tg_id, $username, $first_name) {
  $pdo = db();
  $u = getUser($tg_id);
  if (!$u) {
    $st = $pdo->prepare("INSERT INTO users (tg_id, username, first_name, points, referrals, created_at) VALUES (?,?,?,?,?,?)");
    $st->execute([(int)$tg_id, (string)$username, (string)$first_name, 0, 0, now()]);
  } else {
    $st = $pdo->prepare("UPDATE users SET username=?, first_name=? WHERE tg_id=?");
    $st->execute([(string)$username, (string)$first_name, (int)$tg_id]);
  }
}

function addReferralIfEligible($new_user_id, $referrer_id) {
  if ((int)$new_user_id === (int)$referrer_id) return false;

  $pdo = db();
  // Only if new user not already logged in referrals_log
  $st = $pdo->prepare("SELECT 1 FROM referrals_log WHERE new_user_tg_id = ?");
  $st->execute([(int)$new_user_id]);
  if ($st->fetchColumn()) return false;

  // ensure referrer exists
  $refUser = getUser($referrer_id);
  if (!$refUser) return false;

  // log + update
  $pdo->beginTransaction();
  try {
    $pdo->prepare("INSERT INTO referrals_log (new_user_tg_id, referrer_tg_id, created_at) VALUES (?,?,?)")
        ->execute([(int)$new_user_id, (int)$referrer_id, now()]);

    $pdo->prepare("UPDATE users SET referred_by=? WHERE tg_id=? AND referred_by IS NULL")
        ->execute([(int)$referrer_id, (int)$new_user_id]);

    $pdo->prepare("UPDATE users SET points = points + 1, referrals = referrals + 1 WHERE tg_id=?")
        ->execute([(int)$referrer_id]);

    $pdo->commit();
    return true;
  } catch (Exception $e) {
    $pdo->rollBack();
    return false;
  }
}

function adminGetMode() {
  global $ADMIN_ID;
  if (!$ADMIN_ID) return "";
  $st = db()->prepare("SELECT mode FROM admin_state WHERE admin_id=?");
  $st->execute([$ADMIN_ID]);
  return (string)($st->fetchColumn() ?: "");
}

function adminSetMode($mode) {
  global $ADMIN_ID;
  if (!$ADMIN_ID) return;
  db()->prepare("INSERT INTO admin_state (admin_id, mode, updated_at) VALUES (?,?,?)
                 ON CONFLICT(admin_id) DO UPDATE SET mode=excluded.mode, updated_at=excluded.updated_at")
     ->execute([$ADMIN_ID, (string)$mode, now()]);
}

/* ===================== JOIN CHECK ===================== */

function joinedAllChannels($user_id) {
  global $CHANNELS;

  foreach ($CHANNELS as $ch) {
    if (!$ch) continue;
    $r = tg("getChatMember", ["chat_id" => $ch, "user_id" => (int)$user_id]);
    if (!$r || empty($r["ok"])) return false;

    $status = $r["result"]["status"] ?? "left";
    if (!in_array($status, ["member", "administrator", "creator"], true)) {
      return false;
    }
  }
  return true;
}

/* ===================== UI / MENUS ===================== */

function sendJoinScreen($chat_id) {
  global $CHANNELS;

  $kb = [];
  $i = 1;
  foreach ($CHANNELS as $ch) {
    $username = ltrim($ch, "@");
    $kb[] = [[ "text" => "📢 Join $i", "url" => "https://t.me/" . $username ]];
    $i++;
  }
  $kb[] = [[ "text" => "☑️ Check Verification", "callback_data" => "check_verification" ]];

  tg("sendMessage", [
    "chat_id" => $chat_id,
    "text" => "🔒 Join all channels first, then tap ☑️ Check Verification.",
    "reply_markup" => json_encode(["inline_keyboard" => $kb])
  ]);
}

function mainMenuKb($user_id) {
  $rows = [
    [
      ["text" => "📊 Stats", "callback_data" => "stats"],
      ["text" => "🎁 Withdraw", "callback_data" => "withdraw"],
    ],
    [
      ["text" => "🔗 My Referral Link", "callback_data" => "reflink"],
    ],
  ];

  if (isAdmin($user_id)) {
    $rows[] = [
      ["text" => "🛠 Admin Panel", "callback_data" => "admin_panel"],
    ];
  }

  return ["inline_keyboard" => $rows];
}

function sendWelcomeMenu($chat_id, $user_id) {
  tg("sendMessage", [
    "chat_id" => $chat_id,
    "text" => "🎉WELCOME!\n\nChoose an option below:",
    "reply_markup" => json_encode(mainMenuKb($user_id))
  ]);
}

function verifyLinkForUser($user_id) {
  global $VERIFY_URL, $VERIFY_SECRET;

  $uid = (int)$user_id;
  $url = $VERIFY_URL . "?uid=" . urlencode((string)$uid);

  if ($VERIFY_SECRET !== "") {
    $sig = hash_hmac("sha256", (string)$uid, $VERIFY_SECRET);
    $url .= "&sig=" . urlencode($sig);
  }
  return $url;
}

function sendVerifyYourselfMessage($chat_id, $user_id) {
  $url = verifyLinkForUser($user_id);

  tg("sendMessage", [
    "chat_id" => $chat_id,
    "text" => "✅ Channel join verified!\n\nNow verify yourself 👇",
    "reply_markup" => json_encode([
      "inline_keyboard" => [
        [[ "text" => "🔐 Verify Yourself", "url" => $url ]],
        [[ "text" => "⬅️ Back to Menu", "callback_data" => "menu" ]],
      ]
    ])
  ]);
}

/* ===================== COUPONS / WITHDRAW ===================== */

function couponsAvailableCount() {
  $st = db()->query("SELECT COUNT(*) FROM coupons WHERE used=0");
  return (int)$st->fetchColumn();
}

function getNextCouponAndMarkUsed($tg_id) {
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $st = $pdo->query("SELECT id, code FROM coupons WHERE used=0 ORDER BY id ASC LIMIT 1");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->rollBack(); return null; }

    $pdo->prepare("UPDATE coupons SET used=1, used_by=?, used_at=? WHERE id=?")
        ->execute([(int)$tg_id, now(), (int)$row["id"]]);

    $pdo->commit();
    return $row["code"];
  } catch (Exception $e) {
    $pdo->rollBack();
    return null;
  }
}

function withdrawCoupon($chat_id, $user_id) {
  global $WITHDRAW_COST, $ADMIN_ID;

  $u = getUser($user_id);
  if (!$u) return;

  if ((int)$u["points"] < $WITHDRAW_COST) {
    tg("sendMessage", [
      "chat_id" => $chat_id,
      "text" => "❌ Not enough points.\n\nYou need {$WITHDRAW_COST} points to withdraw."
    ]);
    return;
  }

  if (couponsAvailableCount() <= 0) {
    tg("sendMessage", [
      "chat_id" => $chat_id,
      "text" => "❌ No coupons available right now.\nPlease try later."
    ]);
    return;
  }

  $pdo = db();
  $pdo->beginTransaction();
  try {
    // Deduct points first
    $pdo->prepare("UPDATE users SET points = points - ? WHERE tg_id=?")
        ->execute([$WITHDRAW_COST, (int)$user_id]);

    // Get coupon
    $code = getNextCouponAndMarkUsed($user_id);
    if (!$code) {
      // revert points
      $pdo->rollBack();
      tg("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "❌ No coupons available right now.\nPlease try later."
      ]);
      return;
    }

    // log withdrawal
    $pdo->prepare("INSERT INTO withdrawals (tg_id, coupon_code, cost, created_at) VALUES (?,?,?,?)")
        ->execute([(int)$user_id, (string)$code, (int)$WITHDRAW_COST, now()]);

    $pdo->commit();

    // User message format you wanted
    tg("sendMessage", [
      "chat_id" => $chat_id,
      "text" => "🎉 Congratulations!\n\nYour Coupon:\n<code>" . esc($code) . "</code>",
      "parse_mode" => "HTML"
    ]);

    // Notify admin
    if ($ADMIN_ID) {
      tg("sendMessage", [
        "chat_id" => $ADMIN_ID,
        "text" => "✅ Coupon redeemed!\nUser: {$user_id}\nCost: {$WITHDRAW_COST}\nCoupon: {$code}"
      ]);
    }

  } catch (Exception $e) {
    $pdo->rollBack();
    tg("sendMessage", [
      "chat_id" => $chat_id,
      "text" => "❌ Withdraw failed. Try again."
    ]);
  }
}

/* ===================== ADMIN PANEL ===================== */

function adminPanelKb() {
  return [
    "inline_keyboard" => [
      [
        ["text" => "➕ Add Coupons", "callback_data" => "admin_add_coupons"],
        ["text" => "📦 Coupon Stock", "callback_data" => "admin_stock"],
      ],
      [
        ["text" => "📈 Admin Stats", "callback_data" => "admin_stats"],
        ["text" => "🧾 Withdrawals", "callback_data" => "admin_withdrawals"],
      ],
      [
        ["text" => "⬅️ Back", "callback_data" => "menu"],
      ]
    ]
  ];
}

function sendAdminPanel($chat_id) {
  tg("sendMessage", [
    "chat_id" => $chat_id,
    "text" => "🛠 Admin Panel",
    "reply_markup" => json_encode(adminPanelKb())
  ]);
}

function adminSendStats($chat_id) {
  $pdo = db();
  $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
  $totalWithdrawals = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals")->fetchColumn();
  $stock = couponsAvailableCount();

  tg("sendMessage", [
    "chat_id" => $chat_id,
    "text" => "📈 Admin Stats\n\n👥 Users: {$totalUsers}\n🎁 Withdrawals: {$totalWithdrawals}\n📦 Coupon Stock: {$stock}"
  ]);
}

function adminSendWithdrawals($chat_id) {
  $st = db()->query("SELECT tg_id, coupon_code, cost, created_at FROM withdrawals ORDER BY id DESC LIMIT 10");
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    tg("sendMessage", ["chat_id" => $chat_id, "text" => "🧾 No withdrawals yet."]);
    return;
  }

  $msg = "🧾 Last 10 Withdrawals:\n\n";
  foreach ($rows as $r) {
    $msg .= "User: {$r['tg_id']}\nCost: {$r['cost']}\nCode: {$r['coupon_code']}\nTime: {$r['created_at']}\n\n";
  }

  tg("sendMessage", ["chat_id" => $chat_id, "text" => trim($msg)]);
}

function adminSendStock($chat_id) {
  $count = couponsAvailableCount();
  tg("sendMessage", [
    "chat_id" => $chat_id,
    "text" => "📦 Coupon Stock: {$count} available"
  ]);
}

function adminAddCouponsFromText($chat_id, $text) {
  // each line = 1 coupon
  $lines = preg_split("/\r\n|\n|\r/", trim($text));
  $lines = array_values(array_filter(array_map("trim", $lines)));

  if (!$lines) {
    tg("sendMessage", ["chat_id" => $chat_id, "text" => "❌ No coupons found. Send codes line by line."]);
    return;
  }

  $pdo = db();
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("INSERT INTO coupons (code, added_at, used) VALUES (?,?,0)");
    $added = 0;
    foreach ($lines as $c) {
      if ($c === "") continue;
      $st->execute([$c, now()]);
      $added++;
    }
    $pdo->commit();

    tg("sendMessage", [
      "chat_id" => $chat_id,
      "text" => "✅ Added {$added} coupon(s).\n📦 Stock now: " . couponsAvailableCount()
    ]);
  } catch (Exception $e) {
    $pdo->rollBack();
    tg("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Failed to add coupons."]);
  }
}

/* ===================== REFERRAL LINK ===================== */

function getReferralLink($user_id) {
  global $BOT_USERNAME;

  // If bot username is not set, user can still use the link by replacing it
  if (!$BOT_USERNAME) return "Set BOT_USERNAME in ENV to generate link.";

  return "https://t.me/{$BOT_USERNAME}?start={$user_id}";
}

/* ===================== PROCESS UPDATE ===================== */

$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit;

// ---------- Message ----------
if (isset($update["message"])) {
  $m = $update["message"];
  $chat_id = $m["chat"]["id"];
  $user_id = $m["from"]["id"];
  $username = $m["from"]["username"] ?? "";
  $first = $m["from"]["first_name"] ?? "";

  upsertUser($user_id, $username, $first);

  $text = $m["text"] ?? "";

  // Admin add mode
  if (isAdmin($user_id)) {
    $mode = adminGetMode();
    if ($mode === "adding_coupons") {
      if (trim($text) === "/cancel") {
        adminSetMode("");
        tg("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Cancelled."]);
        return;
      }
      adminAddCouponsFromText($chat_id, $text);
      return;
    }
  }

  // /start with optional referrer id
  if (strpos($text, "/start") === 0) {
    $parts = explode(" ", $text, 2);
    if (isset($parts[1]) && ctype_digit(trim($parts[1]))) {
      $ref = (int)trim($parts[1]);
      // Add referral only if eligible (first time)
      addReferralIfEligible($user_id, $ref);
    }

    // Force join screen first
    sendJoinScreen($chat_id);
    return;
  }

  // If user sends any message, just show join screen (as per your flow)
  sendJoinScreen($chat_id);
  return;
}

// ---------- Callback Query ----------
if (isset($update["callback_query"])) {
  $cq = $update["callback_query"];
  $cb_id = $cq["id"];
  $data = $cq["data"] ?? "";
  $chat_id = $cq["message"]["chat"]["id"];
  $user_id = $cq["from"]["id"];
  $username = $cq["from"]["username"] ?? "";
  $first = $cq["from"]["first_name"] ?? "";

  upsertUser($user_id, $username, $first);

  // Always answer callback (remove loading)
  answerCb($cb_id);

  // Enforce join for all user actions except check_verification itself (still enforces)
  if ($data !== "check_verification" && !joinedAllChannels($user_id)) {
    sendJoinScreen($chat_id);
    return;
  }

  // Check Verification -> NEW message -> Verify Yourself
  if ($data === "check_verification") {
    if (!joinedAllChannels($user_id)) {
      tg("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "❌ You have not joined all channels.\n\nJoin all and tap ☑️ Check Verification again."
      ]);
      return;
    }

    // NEW MESSAGE (as you requested)
    sendVerifyYourselfMessage($chat_id, $user_id);
    // Optional: also show menu
    sendWelcomeMenu($chat_id, $user_id);
    return;
  }

  if ($data === "menu") {
    sendWelcomeMenu($chat_id, $user_id);
    return;
  }

  if ($data === "stats") {
    $u = getUser($user_id);
    $points = (int)($u["points"] ?? 0);
    $refs = (int)($u["referrals"] ?? 0);

    tg("sendMessage", [
      "chat_id" => $chat_id,
      "text" => "📊 Your Stats\n\n👥 Referrals: {$refs}\n⭐ Points: {$points}"
    ]);
    return;
  }

  if ($data === "reflink") {
    $link = getReferralLink($user_id);
    tg("sendMessage", [
      "chat_id" => $chat_id,
      "text" => "🔗 Your Referral Link:\n\n{$link}\n\nShare it. When a new user starts the bot using your link, you get +1 point."
    ]);
    return;
  }

  if ($data === "withdraw") {
    withdrawCoupon($chat_id, $user_id);
    return;
  }

  // -------- Admin Panel --------
  if ($data === "admin_panel") {
    if (!isAdmin($user_id)) return;
    sendAdminPanel($chat_id);
    return;
  }

  if ($data === "admin_add_coupons") {
    if (!isAdmin($user_id)) return;
    adminSetMode("adding_coupons");
    tg("sendMessage", [
      "chat_id" => $chat_id,
      "text" => "➕ Send coupon codes now (ONE PER LINE).\n\nType /cancel to stop."
    ]);
    return;
  }

  if ($data === "admin_stock") {
    if (!isAdmin($user_id)) return;
    adminSendStock($chat_id);
    return;
  }

  if ($data === "admin_stats") {
    if (!isAdmin($user_id)) return;
    adminSendStats($chat_id);
    return;
  }

  if ($data === "admin_withdrawals") {
    if (!isAdmin($user_id)) return;
    adminSendWithdrawals($chat_id);
    return;
  }

  // default fallback
  sendWelcomeMenu($chat_id, $user_id);
  return;
}
