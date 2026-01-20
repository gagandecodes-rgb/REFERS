<?php
/**
 * Telegram Referral Coupon Bot (PHP Webhook)
 * Hosting: Render
 * Database: Supabase PostgreSQL
 *
 * ENV (Render):
 * BOT_TOKEN, ADMIN_ID
 * FORCE_JOIN_1, FORCE_JOIN_2   (e.g. @channel1, @group2)
 * DB_HOST, DB_PORT(5432), DB_NAME(postgres), DB_USER(postgres), DB_PASS
 */

error_reporting(0);
ini_set("display_errors", 0);

// ---------- ENV ----------
$BOT_TOKEN    = getenv("BOT_TOKEN");
$ADMIN_ID     = getenv("ADMIN_ID");

$FORCE_JOIN_1 = getenv("FORCE_JOIN_1");
$FORCE_JOIN_2 = getenv("FORCE_JOIN_2");

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER") ?: "postgres";
$DB_PASS = getenv("DB_PASS");

if (!$BOT_TOKEN) { http_response_code(200); echo "OK"; exit; }
$API = "https://api.telegram.org/bot{$BOT_TOKEN}";

// ---------- DB (Supabase requires SSL) ----------
try {
  $pdo = new PDO(
    "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};sslmode=require",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Exception $e) {
  // Don't break webhook
  http_response_code(200);
  echo "OK";
  exit;
}

// ---------- Telegram Helpers ----------
function tg($method, $data = []) {
  global $API;
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $API . "/" . $method);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
  curl_setopt($ch, CURLOPT_TIMEOUT, 12);
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

function editMessage($chat_id, $message_id, $text, $reply_markup = null) {
  $data = [
    "chat_id" => $chat_id,
    "message_id" => $message_id,
    "text" => $text,
    "parse_mode" => "HTML",
    "disable_web_page_preview" => true
  ];
  if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
  return tg("editMessageText", $data);
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

function getBotUsername() {
  static $u = null;
  if ($u !== null) return $u;
  $me = tg("getMe");
  $u = $me["result"]["username"] ?? "";
  return $u;
}

// ---------- Force Join / Verify ----------
function checkMember($user_id, $chat) {
  // Bot must be ADMIN in channels/groups for reliable check
  $r = tg("getChatMember", ["chat_id" => $chat, "user_id" => $user_id]);
  if (!$r || empty($r["ok"])) return false;
  $status = $r["result"]["status"] ?? "";
  return in_array($status, ["member", "administrator", "creator"], true);
}

function mustJoinFirst($tg_id) {
  global $FORCE_JOIN_1, $FORCE_JOIN_2;
  $c1 = normalizeChannel($FORCE_JOIN_1);
  $c2 = normalizeChannel($FORCE_JOIN_2);

  $ok1 = $c1 ? checkMember($tg_id, $c1) : true;
  $ok2 = $c2 ? checkMember($tg_id, $c2) : true;

  return !($ok1 && $ok2);
}

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

  if ($admin) {
    $rows[] = [
      ["text" => "🛠 Admin Panel", "callback_data" => "admin_panel"]
    ];
  }

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

function sendVerifyScreen($chat_id) {
  $text = "🔐 <b>Verification</b>\nTap below to verify. This blocks fake referrals and keeps rewards fair.";
  return sendMessage($chat_id, $text, verifyMarkup());
}

function sendMainMenu($chat_id, $tg_id) {
  $text = "🎉 <b>WELCOME!</b>\n\n"
        . "✅ 1 Refer = <b>1 point</b>\n"
        . "🎁 Withdraw = <b>3 points</b> = 1 coupon\n\n"
        . "Choose an option below:";
  return sendMessage($chat_id, $text, mainMenuMarkup(isAdmin($tg_id)));
}

// ---------- Simple local state for admin add-coupon ----------
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
  $st = $pdo->prepare("SELECT * FROM users WHERE tg_id = :tg LIMIT 1");
  $st->execute([":tg" => $tg_id]);
  return $st->fetch();
}

function upsertUser($tg_id, $referred_by = null) {
  global $pdo;
  $u = getUser($tg_id);
  if ($u) return $u;

  $st = $pdo->prepare("INSERT INTO users (tg_id, referred_by) VALUES (:tg, :ref)");
  $st->execute([":tg" => $tg_id, ":ref" => $referred_by]);
  return getUser($tg_id);
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

// ---------- Read update ----------
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { http_response_code(200); echo "OK"; exit; }

// ---------- Handle messages ----------
if (isset($update["message"])) {
  $msg = $update["message"];
  $chat_id = $msg["chat"]["id"];
  $from_id = $msg["from"]["id"];
  $text = trim($msg["text"] ?? "");

  // Admin add coupon flow: admin sends codes after tapping "Add Coupon"
  if (isAdmin($from_id) && getState($from_id) === "await_coupon_code" && $text !== "" && strpos($text, "/") !== 0) {
    $codes = preg_split("/\r\n|\n|\r|,|\s+/", $text);
    $codes = array_values(array_filter(array_map("trim", $codes)));

    $added = 0;
    foreach ($codes as $c) {
      if ($c === "") continue;
      try {
        global $pdo;
        $st = $pdo->prepare("INSERT INTO coupons (code, added_by) VALUES (:code, :by)");
        $st->execute([":code" => $c, ":by" => $from_id]);
        $added++;
      } catch (Exception $e) {
        // duplicates ignored
      }
    }

    clearState($from_id);
    sendMessage($chat_id, "✅ Added <b>{$added}</b> coupon(s) to stock.", mainMenuMarkup(true));
    http_response_code(200); echo "OK"; exit;
  }

  // /start with referral
  if (strpos($text, "/start") === 0) {
    $parts = explode(" ", $text, 2);
    $ref = null;

    if (count($parts) === 2) {
      $refRaw = trim($parts[1]);
      if ($refRaw !== "" && ctype_digit($refRaw)) $ref = (int)$refRaw;
    }

    $existing = getUser($from_id);

    if (!$existing) {
      $referred_by = null;

      // referral valid only if ref user exists and not self
      if ($ref && $ref != $from_id) {
        $refUser = getUser($ref);
        if ($refUser) $referred_by = $ref;
      }

      upsertUser($from_id, $referred_by);

      // Give referrer +1 point once (only when new user created)
      if ($referred_by) {
        try {
          global $pdo;
          $st = $pdo->prepare("UPDATE users SET points = points + 1, total_referrals = total_referrals + 1 WHERE tg_id = :rid");
          $st->execute([":rid" => $referred_by]);
        } catch (Exception $e) {}
      }
    } else {
      upsertUser($from_id, null);
    }

    // Force join check
    if (mustJoinFirst($from_id)) {
      sendVerifyScreen($chat_id);
    } else {
      sendMainMenu($chat_id, $from_id);
    }

    http_response_code(200); echo "OK"; exit;
  }

  // Any other message: show verify or menu
  if (mustJoinFirst($from_id)) {
    sendVerifyScreen($chat_id);
  } else {
    sendMainMenu($chat_id, $from_id);
  }

  http_response_code(200); echo "OK"; exit;
}

// ---------- Handle callbacks ----------
if (isset($update["callback_query"])) {
  $cq = $update["callback_query"];
  $data = $cq["data"] ?? "";
  $from_id = $cq["from"]["id"];
  $chat_id = $cq["message"]["chat"]["id"];
  $message_id = $cq["message"]["message_id"];

  // Verify Now (like screenshot)
  if ($data === "verify_now") {
    if (mustJoinFirst($from_id)) {
      answerCallback($cq["id"], "❌ Verification failed.", true);
      editMessage(
        $chat_id,
        $message_id,
        "❌ <b>Verification failed.</b>\nYou can’t use the bot.",
        verifyMarkup()
      );
    } else {
      answerCallback($cq["id"], "✅ Verified!");
      editMessage(
        $chat_id,
        $message_id,
        "✅ <b>Channel join verified!</b>\n\nNext: Choose an option below.",
        mainMenuMarkup(isAdmin($from_id))
      );
    }
    http_response_code(200); echo "OK"; exit;
  }

  // Block any other actions if not verified
  if (mustJoinFirst($from_id)) {
    answerCallback($cq["id"], "Please verify first.", true);
    editMessage(
      $chat_id,
      $message_id,
      "🔐 <b>Verification</b>\nTap below to verify. This blocks fake referrals and keeps rewards fair.",
      verifyMarkup()
    );
    http_response_code(200); echo "OK"; exit;
  }

  // Ensure user exists
  $u = upsertUser($from_id, null);

  // Stats
  if ($data === "stats") {
    $text = "📊 <b>Your Stats</b>\n\n"
          . "⭐ Points: <b>{$u['points']}</b>\n"
          . "👥 Total Referrals: <b>{$u['total_referrals']}</b>\n\n"
          . "🎁 Need <b>3 points</b> to withdraw 1 coupon.";
    answerCallback($cq["id"], "Stats loaded");
    editMessage($chat_id, $message_id, $text, mainMenuMarkup(isAdmin($from_id)));
    http_response_code(200); echo "OK"; exit;
  }

  // Referral link
  if ($data === "reflink") {
    $bot = getBotUsername();
    $link = $bot ? "https://t.me/{$bot}?start={$from_id}" : "Bot username not found";
    $text = "🔗 <b>Your Referral Link</b>\n\n<code>{$link}</code>\n\n"
          . "Share it with friends.\nEach successful join = <b>1 point</b>.";
    answerCallback($cq["id"], "Referral link ready");
    editMessage($chat_id, $message_id, $text, mainMenuMarkup(isAdmin($from_id)));
    http_response_code(200); echo "OK"; exit;
  }

  // Withdraw: 3 points -> 1 coupon
  if ($data === "withdraw") {
    if ((int)$u["points"] < 3) {
      answerCallback($cq["id"], "Not enough points", true);
      editMessage(
        $chat_id,
        $message_id,
        "❌ <b>Not enough points!</b>\n\nYou have <b>{$u['points']}</b> points.\nYou need <b>3</b> points to withdraw.",
        mainMenuMarkup(isAdmin($from_id))
      );
      http_response_code(200); echo "OK"; exit;
    }

    try {
      global $pdo;
      $pdo->beginTransaction();

      // Lock one available coupon (safe)
      $st = $pdo->query("SELECT id, code FROM coupons WHERE used = false ORDER BY id ASC LIMIT 1 FOR UPDATE");
      $coupon = $st->fetch();

      if (!$coupon) {
        $pdo->rollBack();
        answerCallback($cq["id"], "Out of stock", true);
        editMessage(
          $chat_id,
          $message_id,
          "⚠️ <b>Coupons out of stock!</b>\nPlease try again later.",
          mainMenuMarkup(isAdmin($from_id))
        );
        http_response_code(200); echo "OK"; exit;
      }

      $before = (int)$u["points"];
      $after  = $before - 3;

      // Deduct ONLY 3 points
      $st = $pdo->prepare("UPDATE users SET points = points - 3 WHERE tg_id = :tg AND points >= 3");
      $st->execute([":tg" => $from_id]);
      if ($st->rowCount() < 1) {
        $pdo->rollBack();
        answerCallback($cq["id"], "Not enough points", true);
        http_response_code(200); echo "OK"; exit;
      }

      // Mark coupon used
      $st = $pdo->prepare("UPDATE coupons SET used = true, used_by = :tg, used_at = NOW() WHERE id = :id");
      $st->execute([":tg" => $from_id, ":id" => $coupon["id"]]);

      // Log withdrawal
      $st = $pdo->prepare("INSERT INTO withdrawals (tg_id, coupon_code, points_deducted) VALUES (:tg, :code, 3)");
      $st->execute([":tg" => $from_id, ":code" => $coupon["code"]]);

      $pdo->commit();

      answerCallback($cq["id"], "Success!");
      editMessage(
        $chat_id,
        $message_id,
        "🎉 <b>Congratulations!</b>\n\nYour Coupon:\n<code>{$coupon['code']}</code>",
        mainMenuMarkup(isAdmin($from_id))
      );

      // Notify admin
      notifyAdminRedeem($from_id, $coupon["code"], $before, $after);

    } catch (Exception $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      answerCallback($cq["id"], "Error, try again", true);
    }

    http_response_code(200); echo "OK"; exit;
  }

  // Admin panel
  if ($data === "admin_panel") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    answerCallback($cq["id"], "Admin panel");
    editMessage($chat_id, $message_id, "🛠 <b>Admin Panel</b>\nChoose an option:", adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // Admin add coupon (start)
  if ($data === "admin_add_coupon") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    setState($from_id, "await_coupon_code");
    answerCallback($cq["id"], "Send coupon code(s)");
    editMessage(
      $chat_id,
      $message_id,
      "➕ <b>Add Coupon</b>\n\nSend coupon code now.\nYou can send multiple codes (new line / space / comma).",
      adminPanelMarkup()
    );
    http_response_code(200); echo "OK"; exit;
  }

  // Admin stock
  if ($data === "admin_stock") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    global $pdo;

    $st = $pdo->query("SELECT COUNT(*) AS c FROM coupons WHERE used = false");
    $available = (int)(($st->fetch())["c"] ?? 0);

    $st = $pdo->query("SELECT COUNT(*) AS c FROM coupons WHERE used = true");
    $used = (int)(($st->fetch())["c"] ?? 0);

    answerCallback($cq["id"], "Stock loaded");
    editMessage(
      $chat_id,
      $message_id,
      "📦 <b>Coupon Stock</b>\n\n✅ Available: <b>{$available}</b>\n🧾 Used: <b>{$used}</b>",
      adminPanelMarkup()
    );
    http_response_code(200); echo "OK"; exit;
  }

  // Admin redeems
  if ($data === "admin_redeems") {
    if (!isAdmin($from_id)) { answerCallback($cq["id"], "Not allowed", true); http_response_code(200); echo "OK"; exit; }
    global $pdo;

    $st = $pdo->query("SELECT tg_id, coupon_code, created_at FROM withdrawals ORDER BY id DESC LIMIT 10");
    $rows = $st->fetchAll();

    $text = "🗂 <b>Last 10 Redeems</b>\n\n";
    if (!$rows) {
      $text .= "No redeems yet.";
    } else {
      foreach ($rows as $r) {
        $text .= "👤 <code>{$r['tg_id']}</code>\n";
        $text .= "🎟 <code>{$r['coupon_code']}</code>\n";
        $text .= "🕒 <code>{$r['created_at']}</code>\n\n";
      }
    }

    answerCallback($cq["id"], "Redeems loaded");
    editMessage($chat_id, $message_id, $text, adminPanelMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // Back
  if ($data === "back_main") {
    answerCallback($cq["id"], "Back");
    editMessage(
      $chat_id,
      $message_id,
      "🎉 <b>WELCOME!</b>\n\nChoose an option below:",
      mainMenuMarkup(isAdmin($from_id))
    );
    http_response_code(200); echo "OK"; exit;
  }

  answerCallback($cq["id"], "OK");
  http_response_code(200); echo "OK"; exit;
}

http_response_code(200);
echo "OK";
