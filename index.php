<?php
// Health check
if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo "OK"; exit; }

error_reporting(0);
ini_set("display_errors", 0);

define("POINTS_PER_WITHDRAW", 5);
define("VERIFY_CACHE_HOURS", 24); // verified cache time (fast)
define("TG_CONNECT_TIMEOUT", 2);
define("TG_TIMEOUT", 5);

$BOT_TOKEN    = getenv("BOT_TOKEN");
$ADMIN_ID     = getenv("ADMIN_ID");
$FORCE_JOIN_1 = getenv("FORCE_JOIN_1");
$FORCE_JOIN_2 = getenv("FORCE_JOIN_2");
$BOT_USERNAME = getenv("BOT_USERNAME"); // optional: set this for fastest ref link

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

if (!$BOT_TOKEN) { http_response_code(200); echo "OK"; exit; }
$API = "https://api.telegram.org/bot{$BOT_TOKEN}";

// ---------- DB (Supabase + Pooler) ----------
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

function dbReady() { global $pdo; return $pdo instanceof PDO; }

// ---------- Telegram helpers ----------
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

// ---------- UI ----------
function verifyMarkup() {
  global $FORCE_JOIN_1, $FORCE_JOIN_2;
  $c1 = normalizeChannel($FORCE_JOIN_1);
  $c2 = normalizeChannel($FORCE_JOIN_2);
  $rows = [];
  if ($c1) $rows[] = [["text" => "✅ Join 1", "url" => "https://t.me/".ltrim($c1, "@")]];
  if ($c2) $rows[] = [["text" => "✅ Join 2", "url" => "https://t.me/".ltrim($c2, "@")]];
  $rows[] = [["text" => "✅ Verify Now", "callback_data" => "verify_now"]];
  return ["inline_keyboard" => $rows];
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
  if ($admin) $rows[] = [["text" => "🛠 Admin Panel", "callback_data" => "admin_panel"]];
  return ["inline_keyboard" => $rows];
}

function adminPanelMarkup() {
  return ["inline_keyboard" => [
    [
      ["text" => "➕ Add Coupon", "callback_data" => "admin_add_coupon"],
      ["text" => "📦 Stock", "callback_data" => "admin_stock"]
    ],
    [
      ["text" => "🗂 Redeems", "callback_data" => "admin_redeems"]
    ],
    [
      ["text" => "⬅️ Back", "callback_data" => "back_main"]
    ]
  ]];
}

function sendVerifyScreen($chat_id) {
  $text = "🔐 <b>Verification</b>\n\nJoin both channels and tap <b>Verify Now</b>.";
  return sendMessage($chat_id, $text, verifyMarkup());
}

function sendMainMenu($chat_id, $tg_id) {
  $need = POINTS_PER_WITHDRAW;
  $text = "🎉 <b>WELCOME!</b>\n\n"
        . "✅ 1 Refer = <b>1 point</b>\n"
        . "🎁 Withdraw = <b>{$need} points</b> = 1 coupon\n\n"
        . "Choose an option:";
  return sendMessage($chat_id, $text, mainMenuMarkup(isAdmin($tg_id)));
}

// ---------- Admin add-coupon state (file) ----------
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

// ---------- DB helpers ----------
function getUser($tg_id) {
  global $pdo;
  if (!dbReady()) return null;
  $st = $pdo->prepare("SELECT tg_id, points, total_referrals, referred_by, verified_until FROM users WHERE tg_id=:tg LIMIT 1");
  $st->execute([":tg"=>$tg_id]);
  return $st->fetch();
}

function upsertUser($tg_id, $referred_by = null) {
  global $pdo;
  if (!dbReady()) return ["tg_id"=>$tg_id,"points"=>0,"total_referrals"=>0,"verified_until"=>null];

  $u = getUser($tg_id);
  if ($u) return $u;

  $st = $pdo->prepare("INSERT INTO users (tg_id, referred_by) VALUES (:tg, :ref)");
  $st->execute([":tg"=>$tg_id, ":ref"=>$referred_by]);
  return getUser($tg_id);
}

function isVerifiedCached($tg_id) {
  $u = getUser($tg_id);
  if (!$u) return false;
  if (empty($u["verified_until"])) return false;
  return (strtotime($u["verified_until"]) > time());
}

function setVerifiedCached($tg_id) {
  global $pdo;
  if (!dbReady()) return;
  $st = $pdo->prepare("UPDATE users SET verified_until = NOW() + (:h || ' hours')::interval WHERE tg_id=:tg");
  $st->execute([":h"=>VERIFY_CACHE_HOURS, ":tg"=>$tg_id]);
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

// ---------- Force join check ONLY on verify_now ----------
function checkMember($user_id, $chat) {
  $r = tg("getChatMember", ["chat_id"=>$chat, "user_id"=>$user_id]);
  if (!$r || empty($r["ok"])) return false;
  $status = $r["result"]["status"] ?? "";
  return in_array($status, ["member","administrator","creator"], true);
}

function checkJoinNow($tg_id) {
  global $FORCE_JOIN_1, $FORCE_JOIN_2;
  $c1 = normalizeChannel($FORCE_JOIN_1);
  $c2 = normalizeChannel($FORCE_JOIN_2);
  $ok1 = $c1 ? checkMember($tg_id, $c1) : true;
  $ok2 = $c2 ? checkMember($tg_id, $c2) : true;
  return ($ok1 && $ok2);
}

function getBotUsernameFast() {
  global $BOT_USERNAME;
  if ($BOT_USERNAME) return ltrim($BOT_USERNAME, "@");
  $me = tg("getMe");
  return $me["result"]["username"] ?? "";
}

// ---------- Read update ----------
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { http_response_code(200); echo "OK"; exit; }

// ---------- Messages ----------
if (isset($update["message"])) {
  $m = $update["message"];
  $chat_id = $m["chat"]["id"];
  $from_id = $m["from"]["id"];
  $text = trim($m["text"] ?? "");

  if (!dbReady()) {
    sendMessage($chat_id, "⚠️ Database not connected.\nCheck Render ENV DB_HOST/DB_USER/DB_PASS and redeploy.");
    http_response_code(200); echo "OK"; exit;
  }

  // Admin add coupon flow
  if (isAdmin($from_id) && getState($from_id)==="await_coupon_code" && $text!=="" && strpos($text,"/")!==0) {
    $codes = preg_split("/\r\n|\n|\r|,|\s+/", $text);
    $codes = array_values(array_filter(array_map("trim",$codes)));
    $added = 0;

    foreach ($codes as $c) {
      if ($c==="") continue;
      try {
        global $pdo;
        $st = $pdo->prepare("INSERT INTO coupons (code, added_by) VALUES (:code,:by)");
        $st->execute([":code"=>$c, ":by"=>$from_id]);
        $added++;
      } catch (Exception $e) {}
    }
    clearState($from_id);
    sendMessage($chat_id, "✅ Added <b>{$added}</b> coupon(s).", mainMenuMarkup(true));
    http_response_code(200); echo "OK"; exit;
  }

  if (strpos($text, "/start") === 0) {
    $parts = explode(" ", $text, 2);
    $ref = null;
    if (count($parts)===2) {
      $refRaw = trim($parts[1]);
      if ($refRaw!=="" && ctype_digit($refRaw)) $ref = (int)$refRaw;
    }

    $existing = getUser($from_id);
    if (!$existing) {
      $referred_by = null;
      if ($ref && $ref != $from_id) {
        $refUser = getUser($ref);
        if ($refUser) $referred_by = $ref;
      }

      upsertUser($from_id, $referred_by);

      if ($referred_by) {
        try {
          global $pdo;
          $st = $pdo->prepare("UPDATE users SET points=points+1, total_referrals=total_referrals+1 WHERE tg_id=:rid");
          $st->execute([":rid"=>$referred_by]);
        } catch (Exception $e) {}
      }
    } else {
      upsertUser($from_id, null);
    }

    // FAST: no join check here
    if (isVerifiedCached($from_id)) sendMainMenu($chat_id, $from_id);
    else sendVerifyScreen($chat_id);

    http_response_code(200); echo "OK"; exit;
  }

  // Any other message
  upsertUser($from_id, null);
  if (isVerifiedCached($from_id)) sendMainMenu($chat_id, $from_id);
  else sendVerifyScreen($chat_id);

  http_response_code(200); echo "OK"; exit;
}

// ---------- Callbacks ----------
if (isset($update["callback_query"])) {
  $cq = $update["callback_query"];
  $data = $cq["data"] ?? "";
  $from_id = $cq["from"]["id"];
  $chat_id = $cq["message"]["chat"]["id"];

  if (!dbReady()) {
    answerCallback($cq["id"], "DB error", true);
    sendMessage($chat_id, "⚠️ Database not connected.\nFix Render ENV and redeploy.");
    http_response_code(200); echo "OK"; exit;
  }

  upsertUser($from_id, null);

  // VERIFY NOW (only place we check membership)
  if ($data === "verify_now") {
    answerCallback($cq["id"], "Checking...");
    if (checkJoinNow($from_id)) {
      setVerifiedCached($from_id);
      sendMessage($chat_id, "✅ <b>Verified!</b>\nNow you can use the bot.", mainMenuMarkup(isAdmin($from_id)));
    } else {
      sendMessage($chat_id, "❌ <b>Verification failed.</b>\nJoin both channels then try again.", verifyMarkup());
    }
    http_response_code(200); echo "OK"; exit;
  }

  // If not verified cached, show verify screen (fast, no join check)
  if (!isVerifiedCached($from_id)) {
    answerCallback($cq["id"], "Verify first", true);
    sendVerifyScreen($chat_id);
    http_response_code(200); echo "OK"; exit;
  }

  // STATS
  if ($data === "stats") {
    $u = getUser($from_id);
    $need = POINTS_PER_WITHDRAW;
    answerCallback($cq["id"], "Stats");
    sendMessage($chat_id,
      "📊 <b>Your Stats</b>\n\n⭐ Points: <b>{$u['points']}</b>\n👥 Referrals: <b>{$u['total_referrals']}</b>\n\n🎁 Need <b>{$need}</b> points for 1 coupon.",
      mainMenuMarkup(isAdmin($from_id))
    );
    http_response_code(200); echo "OK"; exit;
  }

  // REF LINK
  if ($data === "reflink") {
    $bot = getBotUsernameFast();
    $link = $bot ? "https://t.me/{$bot}?start={$from_id}" : "Set BOT_USERNAME env for fastest link.";
    answerCallback($cq["id"], "Link");
    sendMessage($chat_id, "🔗 <b>Your Referral Link</b>\n\n<code>{$link}</code>", mainMenuMarkup(isAdmin($from_id)));
    http_response_code(200); echo "OK"; exit;
  }

  // WITHDRAW (5 points)
  if ($data === "withdraw") {
    $u = getUser($from_id);
    $need = POINTS_PER_WITHDRAW;

    if ((int)$u["points"] < $need) {
      answerCallback($cq["id"], "Not enough", true);
      sendMessage($chat_id, "❌ Not enough points.\nYou have <b>{$u['points']}</b>, need <b>{$need}</b>.", mainMenuMarkup(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;
    }

    try {
      global $pdo;
      $pdo->beginTransaction();

      // FAST: avoid waiting on locks
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
      $st->execute([":need"=>$need, ":tg"=>$from_id]);

      if ($st->rowCount() < 1) {
        $pdo->rollBack();
        answerCallback($cq["id"], "Not enough", true);
        http_response_code(200); echo "OK"; exit;
      }

      $st = $pdo->prepare("UPDATE coupons SET used=true, used_by=:tg, used_at=NOW() WHERE id=:id");
      $st->execute([":tg"=>$from_id, ":id"=>$coupon["id"]]);

      $st = $pdo->prepare("INSERT INTO withdrawals (tg_id, coupon_code, points_deducted) VALUES (:tg,:code,:need)");
      $st->execute([":tg"=>$from_id, ":code"=>$coupon["code"], ":need"=>$need]);

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

  // ADMIN
  if ($data === "admin_panel") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    answerCallback($cq["id"], "Admin");
    sendMessage($chat_id, "🛠 <b>Admin Panel</b>", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "admin_add_coupon") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    setState($from_id, "await_coupon_code");
    answerCallback($cq["id"], "Send codes");
    sendMessage($chat_id, "➕ Send coupon codes (new line / space / comma).", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "admin_stock") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    $st = $pdo->query("SELECT COUNT(*) AS c FROM coupons WHERE used=false");
    $available = (int)(($st->fetch())["c"] ?? 0);
    $st = $pdo->query("SELECT COUNT(*) AS c FROM coupons WHERE used=true");
    $used = (int)(($st->fetch())["c"] ?? 0);
    answerCallback($cq["id"], "Stock");
    sendMessage($chat_id, "📦 <b>Stock</b>\n\n✅ Available: <b>{$available}</b>\n🧾 Used: <b>{$used}</b>", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "admin_redeems") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    $st = $pdo->query("SELECT tg_id, coupon_code, created_at FROM withdrawals ORDER BY id DESC LIMIT 10");
    $rows = $st->fetchAll();
    $text = "🗂 <b>Last 10 Redeems</b>\n\n";
    if (!$rows) $text .= "No redeems yet.";
    else {
      foreach ($rows as $r) {
        $text .= "👤 <code>{$r['tg_id']}</code>\n🎟 <code>{$r['coupon_code']}</code>\n🕒 <code>{$r['created_at']}</code>\n\n";
      }
    }
    answerCallback($cq["id"], "Redeems");
    sendMessage($chat_id, $text, adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  if ($data === "back_main") {
    answerCallback($cq["id"], "Back");
    sendMessage($chat_id, "🏠 Main Menu", mainMenuMarkup(isAdmin($from_id)));
    http_response_code(200); echo "OK"; exit;
  }

  answerCallback($cq["id"], "OK");
  http_response_code(200); echo "OK"; exit;
}

http_response_code(200);
echo "OK";
