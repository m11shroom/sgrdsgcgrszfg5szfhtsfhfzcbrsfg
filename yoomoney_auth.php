<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yoomoney_lib.php';
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) { header('Location: index.php'); exit; }

$db  = getDB();
$cfg = ym_getConfig($db) ?? [];
$msg = ''; $msgOk = false;

$redirectUri = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
             . strtok($_SERVER['REQUEST_URI'], '?');

/* ── Режим: подключение отдельного кошелька к конкретной пластиковой карте ──
   Тот же OAuth2-механизм, что и для основного кошелька пополнений, просто
   результат (access_token) привязывается не к сайту, а к одной карте. */
if (isset($_GET['connect_card']) && ctype_digit((string)$_GET['connect_card'])) {
    $_SESSION['plastic_card_id'] = (int)$_GET['connect_card'];
    header('Location: yoomoney_auth.php'); exit;
}
if (isset($_GET['cancel_card'])) {
    unset($_SESSION['plastic_card_id']);
    header('Location: yoomoney_auth.php'); exit;
}

/* ── Режим: подключение кошелька к ЗАКАЗУ ДОСТАВКИ пластиковой карты
   (m1plus delivery — таблица wallet_bindings, ключ card_order_id).
   Тот же принцип, что и connect_card выше, но для системы доставки. */
if (isset($_GET['connect_delivery_order']) && ctype_digit((string)$_GET['connect_delivery_order'])) {
    $_SESSION['delivery_order_id'] = (int)$_GET['connect_delivery_order'];
    header('Location: yoomoney_auth.php'); exit;
}
if (isset($_GET['cancel_delivery_order'])) {
    unset($_SESSION['delivery_order_id']);
    header('Location: yoomoney_auth.php'); exit;
}
$deliveryOrderId = $_SESSION['delivery_order_id'] ?? null;
$deliveryOrder    = null;
if ($deliveryOrderId) {
    $db->exec("CREATE TABLE IF NOT EXISTS wallet_bindings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        card_order_id INT UNSIGNED NOT NULL UNIQUE,
        yoo_wallet VARCHAR(34) NOT NULL,
        yoo_access_token TEXT NOT NULL,
        connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_check DATETIME DEFAULT NULL,
        balance_cached DECIMAL(14,2) DEFAULT NULL,
        balance_updated_at DATETIME DEFAULT NULL,
        KEY idx_order (card_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $do = $db->prepare('SELECT o.*, u.username, t.name AS template_name FROM card_orders o JOIN users u ON u.id=o.user_id LEFT JOIN delivery_card_templates t ON t.id=o.card_template_id WHERE o.id=?');
    $do->execute([$deliveryOrderId]);
    $deliveryOrder = $do->fetch();
    if (!$deliveryOrder) { unset($_SESSION['delivery_order_id']); $deliveryOrderId = null; }
}
$plasticCardId = $_SESSION['plastic_card_id'] ?? null;
$plasticCard   = null;
if ($plasticCardId) {
    $db->exec("CREATE TABLE IF NOT EXISTS plastic_cards (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT NOT NULL, name VARCHAR(100) NOT NULL,
        cover_image VARCHAR(255) DEFAULT NULL, card_number VARCHAR(19) DEFAULT NULL,
        expiry VARCHAR(5) DEFAULT NULL, pin_code VARCHAR(4) DEFAULT NULL,
        delivery_address TEXT DEFAULT NULL, delivery_date DATE DEFAULT NULL, delivery_time VARCHAR(5) DEFAULT NULL,
        delivery_status VARCHAR(30) NOT NULL DEFAULT 'to_factory',
        rep_name VARCHAR(150) DEFAULT NULL, rep_phone VARCHAR(30) DEFAULT NULL, rep_photo VARCHAR(255) DEFAULT NULL,
        is_delivered TINYINT(1) NOT NULL DEFAULT 0, delivered_at DATETIME DEFAULT NULL,
        yoo_access_token TEXT DEFAULT NULL, yoo_wallet VARCHAR(34) DEFAULT NULL,
        wallet_connected_at DATETIME DEFAULT NULL,
        balance_cached DECIMAL(14,2) DEFAULT NULL, balance_updated_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $hasOldCvv = $db->query("SHOW COLUMNS FROM plastic_cards LIKE 'cvv'")->fetch();
        $hasPin    = $db->query("SHOW COLUMNS FROM plastic_cards LIKE 'pin_code'")->fetch();
        if ($hasOldCvv && !$hasPin) { $db->exec("ALTER TABLE plastic_cards CHANGE COLUMN cvv pin_code VARCHAR(4) DEFAULT NULL"); }
        elseif (!$hasPin) { $db->exec("ALTER TABLE plastic_cards ADD COLUMN pin_code VARCHAR(4) DEFAULT NULL"); }
        if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'wallet_connected_at'")->fetch()) {
            $db->exec("ALTER TABLE plastic_cards ADD COLUMN wallet_connected_at DATETIME DEFAULT NULL AFTER yoo_wallet");
        }
        if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'delivery_status'")->fetch()) {
            $db->exec("ALTER TABLE plastic_cards ADD COLUMN delivery_status VARCHAR(30) NOT NULL DEFAULT 'to_factory'");
        }
        if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'rep_name'")->fetch()) {
            $db->exec("ALTER TABLE plastic_cards ADD COLUMN rep_name VARCHAR(150) DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'rep_phone'")->fetch()) {
            $db->exec("ALTER TABLE plastic_cards ADD COLUMN rep_phone VARCHAR(30) DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM plastic_cards LIKE 'rep_photo'")->fetch()) {
            $db->exec("ALTER TABLE plastic_cards ADD COLUMN rep_photo VARCHAR(255) DEFAULT NULL");
        }
        $tokType = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plastic_cards' AND COLUMN_NAME='yoo_access_token'")->fetchColumn();
        if ($tokType && strtolower($tokType) === 'varchar') {
            $db->exec("ALTER TABLE plastic_cards MODIFY COLUMN yoo_access_token TEXT DEFAULT NULL");
        }
    } catch (\Throwable $e) {}
    $pc = $db->prepare('SELECT pc.*, u.username FROM plastic_cards pc JOIN users u ON u.id=pc.user_id WHERE pc.id=?');
    $pc->execute([$plasticCardId]);
    $plasticCard = $pc->fetch();
    if (!$plasticCard) { unset($_SESSION['plastic_card_id']); $plasticCardId = null; }
}

/* Сохранение client_id / secret */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_app') {
    ym_saveConfig($db, [
        'client_id'     => trim($_POST['client_id'] ?? ''),
        'client_secret' => trim($_POST['client_secret'] ?? ''),
    ]);
    header('Location: yoomoney_auth.php'); exit;
}

/* Отключение кошелька */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disconnect') {
    ym_saveConfig($db, ['access_token' => null, 'wallet' => null]);
    header('Location: yoomoney_auth.php'); exit;
}

/* Callback от YooMoney с кодом */
if (!empty($_GET['code']) && !empty($cfg['client_id'])) {
    $resp = ym_exchangeCode($_GET['code'], $cfg['client_id'], $redirectUri, $cfg['client_secret'] ?? '');
    if (!empty($resp['access_token'])) {
        $token = $resp['access_token'];
        $info  = ym_accountInfo($token);

        if ($plasticCardId) {
            // Привязываем полученный токен/кошелёк к конкретной пластиковой карте
            $bal = isset($info['balance']) ? (float)$info['balance'] : null;
            $tokPreview = strlen($token) > 10 ? (substr($token, 0, 6) . '...' . substr($token, -4)) : $token;
            error_log('yoomoney_auth connect_card ' . $plasticCardId . ': token_len=' . strlen($token) . ' preview=' . $tokPreview . ' account_info_ok=' . (isset($info['balance']) ? 'yes' : 'no:' . json_encode($info)));

            $db->prepare('UPDATE plastic_cards SET yoo_access_token=?, yoo_wallet=?, wallet_connected_at=NOW(), balance_cached=?, balance_updated_at=NOW() WHERE id=?')
               ->execute([$token, $info['account'] ?? null, $bal, $plasticCardId]);

            // Сверяем, что токен реально сохранился без искажений (обрезка колонкой, кодировка и т.п.)
            $verify = $db->prepare('SELECT yoo_access_token FROM plastic_cards WHERE id=?');
            $verify->execute([$plasticCardId]);
            $storedToken = $verify->fetchColumn();
            if ($storedToken !== $token) {
                error_log('yoomoney_auth connect_card ' . $plasticCardId . ': ВНИМАНИЕ — токен после сохранения в БД отличается от исходного! orig_len=' . strlen($token) . ' stored_len=' . strlen((string)$storedToken));
            }

            $doneCardId = $plasticCardId;
            unset($_SESSION['plastic_card_id']);
            header('Location: admin_plastic.php?wallet_connected=' . $doneCardId); exit;
        }

        if ($deliveryOrderId) {
            // Привязываем полученный токен/кошелёк к заказу доставки карты
            $bal = isset($info['balance']) ? (float)$info['balance'] : null;

            $ex = $db->prepare('SELECT id FROM wallet_bindings WHERE card_order_id=?');
            $ex->execute([$deliveryOrderId]);
            if ($ex->fetch()) {
                $db->prepare('UPDATE wallet_bindings SET yoo_wallet=?, yoo_access_token=?, connected_at=NOW(), balance_cached=?, balance_updated_at=NOW() WHERE card_order_id=?')
                   ->execute([$info['account'] ?? '', $token, $bal, $deliveryOrderId]);
            } else {
                $db->prepare('INSERT INTO wallet_bindings (card_order_id, yoo_wallet, yoo_access_token, balance_cached, balance_updated_at) VALUES (?,?,?,?,NOW())')
                   ->execute([$deliveryOrderId, $info['account'] ?? '', $token, $bal]);
            }

            $doneOrderId = $deliveryOrderId;
            unset($_SESSION['delivery_order_id']);
            header('Location: admin_delivery_cards.php?wallet_connected=' . $doneOrderId); exit;
        }

        ym_saveConfig($db, [
            'access_token' => $token,
            'wallet'       => $info['account'] ?? null,
        ]);
        header('Location: yoomoney_auth.php?connected=1'); exit;
    } else {
        $msg = 'Ошибка обмена кода: ' . ($resp['error'] ?? 'неизвестно');
    }
}
if (!empty($_GET['error'])) $msg = 'YooMoney вернул ошибку: ' . htmlspecialchars($_GET['error']);
if (!empty($_GET['connected'])) { $msg = 'Кошелёк подключён!'; $msgOk = true; }

$cfg = ym_getConfig($db) ?? [];
$connected = !empty($cfg['access_token']);
$accInfo = null;
if ($connected) {
    $accInfo = ym_accountInfo($cfg['access_token']);
    if (!empty($accInfo['error'])) { $connected = false; $msg = 'Токен недействителен — переподключи кошелёк'; }
}

$authUrl = '';
if (!empty($cfg['client_id'])) {
    $authUrl = 'https://yoomoney.ru/oauth/authorize?' . http_build_query([
        'client_id'     => $cfg['client_id'],
        'response_type' => 'code',
        'redirect_uri'  => $redirectUri,
        'scope'         => 'account-info operation-history operation-details',
    ]);
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M1plus — YooMoney</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0a12;color:#fff;font-family:'DM Sans',sans-serif;min-height:100vh;padding:24px 16px 60px}
.wrap{max-width:560px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.head{display:flex;align-items:center;justify-content:space-between}
h1{font-size:1.2rem;font-weight:700}
a.back{color:#8f8fa3;font-size:.82rem;text-decoration:none}
.card{background:#15151f;border-radius:18px;padding:18px 16px;display:flex;flex-direction:column;gap:12px}
.card-title{font-size:.95rem;font-weight:700}
.lbl{font-size:.72rem;color:#8f8fa3;margin-bottom:5px;font-weight:500}
input{width:100%;background:#1b1b28;border:1px solid transparent;border-radius:12px;color:#fff;font-family:inherit;font-size:.9rem;padding:11px 13px;outline:none}
input:focus{border-color:#8b5cf6}
.btn{background:#8b5cf6;color:#fff;border:none;border-radius:12px;font-family:inherit;font-size:.88rem;font-weight:500;padding:12px;cursor:pointer;width:100%;text-align:center;text-decoration:none;display:block}
.btn:active{background:#6d28d9}
.btn.red{background:rgba(239,68,68,.14);color:#ef4444}
.msg{font-size:.8rem;padding:11px;border-radius:12px;text-align:center;font-weight:500}
.msg.ok{color:#4ade80;background:rgba(74,222,128,.08)}
.msg.err{color:#ef4444;background:rgba(239,68,68,.08)}
.status-row{display:flex;align-items:center;gap:12px;background:#1b1b28;border-radius:13px;padding:13px 15px}
.status-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.status-dot.on{background:#4ade80;box-shadow:0 0 10px rgba(74,222,128,.6)}
.status-dot.off{background:#55556b}
.status-txt{font-size:.86rem}
.status-sub{font-size:.72rem;color:#55556b;margin-top:2px}
.hint{font-size:.72rem;color:#55556b;line-height:1.5}
.hint code{background:#1b1b28;padding:2px 6px;border-radius:5px;font-size:.68rem;color:#a78bfa;word-break:break-all}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>Кошелёк YooMoney</h1>
    <a class="back" href="admin.php">← В админку</a>
  </div>

  <?php if ($msg): ?><div class="msg <?php echo $msgOk?'ok':'err'; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

  <?php if ($plasticCard): ?>
  <div class="card" style="border:1px solid #8b5cf6">
    <div class="card-title">Подключение кошелька к пластиковой карте</div>
    <div class="hint">
      Карта: <b style="color:#e0e0ff"><?php echo htmlspecialchars($plasticCard['name']); ?></b><br>
      Клиент: <b style="color:#e0e0ff">@<?php echo htmlspecialchars($plasticCard['username']); ?></b>
    </div>
    <div class="hint">Авторизуйся под кошельком, баланс которого должен отображаться на этой карте. Это отдельный кошелёк — он не заменяет основной кошелёк сайта для пополнений.</div>
    <?php if ($authUrl): ?>
    <a class="btn" style="background:#4ade80;color:#04150c" href="<?php echo htmlspecialchars($authUrl); ?>">Авторизовать кошелёк для этой карты →</a>
    <?php else: ?>
    <div class="hint" style="color:#ef4444">Сначала зарегистрируй приложение YooMoney ниже (client_id/secret) — без него привязать кошелёк нельзя.</div>
    <?php endif; ?>
    <a class="btn red" href="yoomoney_auth.php?cancel_card=1" style="text-align:center">Отмена</a>
  </div>
  <?php endif; ?>

  <?php if ($deliveryOrder): ?>
  <div class="card" style="border:1px solid #8b5cf6">
    <div class="card-title">Подключение кошелька к заказу доставки карты</div>
    <div class="hint">
      Заказ: <b style="color:#e0e0ff">#<?php echo htmlspecialchars($deliveryOrder['confirmation_code']); ?></b><br>
      Карта: <b style="color:#e0e0ff"><?php echo htmlspecialchars($deliveryOrder['template_name'] ?? '—'); ?></b><br>
      Клиент: <b style="color:#e0e0ff">@<?php echo htmlspecialchars($deliveryOrder['username']); ?></b>
    </div>
    <div class="hint">Авторизуйся под кошельком, баланс которого должен отображаться по этому заказу. Отдельный кошелёк — не заменяет основной кошелёк сайта.</div>
    <?php if ($authUrl): ?>
    <a class="btn" style="background:#4ade80;color:#04150c" href="<?php echo htmlspecialchars($authUrl); ?>">Авторизовать кошелёк для заказа →</a>
    <?php else: ?>
    <div class="hint" style="color:#ef4444">Сначала зарегистрируй приложение YooMoney ниже (client_id/secret) — без него привязать кошелёк нельзя.</div>
    <?php endif; ?>
    <a class="btn red" href="yoomoney_auth.php?cancel_delivery_order=1" style="text-align:center">Отмена</a>
  </div>
  <?php endif; ?>


  <div class="card">
    <div class="status-row">
      <div class="status-dot <?php echo $connected?'on':'off'; ?>"></div>
      <div>
        <div class="status-txt"><?php echo $connected ? 'Основной кошелёк сайта подключён' : 'Основной кошелёк сайта не подключён'; ?></div>
        <?php if ($connected && $accInfo): ?>
        <div class="status-sub">
          Счёт: <?php echo htmlspecialchars($accInfo['account'] ?? '—'); ?>
          &middot; Баланс: <?php echo htmlspecialchars((string)($accInfo['balance'] ?? '—')); ?> ₽
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($connected): ?>
    <div class="hint">Пополнения теперь проверяются автоматически: страница ожидания оплаты опрашивает историю операций каждую секунду и зачисляет баланс, как только платёж с нужной меткой становится успешным.</div>
    <form method="POST"><input type="hidden" name="action" value="disconnect"><button class="btn red" type="submit">Отключить кошелёк</button></form>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">Приложение YooMoney</div>
    <div class="hint">
      1. Зарегистрируй приложение: <code>yoomoney.ru/myservices/new</code><br>
      2. Redirect URI укажи ровно: <code><?php echo htmlspecialchars($redirectUri); ?></code><br>
      3. Вставь сюда client_id (и secret, если выдан) → сохрани → «Подключить кошелёк».<br>
      Это же приложение используется и для привязки отдельных кошельков к пластиковым картам — каждая авторизация выдаёт свой собственный токен.
    </div>
    <form method="POST" style="display:flex;flex-direction:column;gap:11px">
      <input type="hidden" name="action" value="save_app">
      <div><div class="lbl">Client ID</div><input type="text" name="client_id" value="<?php echo htmlspecialchars($cfg['client_id'] ?? ''); ?>" placeholder="A1B2C3..."></div>
      <div><div class="lbl">Client Secret (если есть)</div><input type="text" name="client_secret" value="<?php echo htmlspecialchars($cfg['client_secret'] ?? ''); ?>" placeholder="необязательно"></div>
      <button class="btn" type="submit">Сохранить</button>
    </form>
    <?php if ($authUrl && !$connected && !$plasticCard && !$deliveryOrder): ?>
    <a class="btn" style="background:#4ade80;color:#04150c" href="<?php echo htmlspecialchars($authUrl); ?>">Подключить кошелёк →</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
