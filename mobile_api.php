<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$db = getDB();

/* ── LOGIN (email+пароль, оставлено для обратной совместимости) ──────────── */
if ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if (!$email || !$pass) { echo json_encode(['ok'=>false,'error'=>'Заполните все поля']); exit; }

    $s = $db->prepare('SELECT * FROM users WHERE email=?');
    $s->execute([$email]);
    $user = $s->fetch();

    if (!$user || !password_verify($pass, $user['password'])) {
        echo json_encode(['ok'=>false,'error'=>'Неверный email или пароль']); exit;
    }

    $db->prepare('INSERT IGNORE INTO bank_accounts (user_id) VALUES (?)')->execute([$user['id']]);

    // Та же сессия, что использует веб-версия сайта — приложение и браузер полностью совместимы
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['is_admin'] = $user['is_admin'];

    echo json_encode(['ok'=>true, 'username'=>$user['username'], 'is_admin'=>(bool)$user['is_admin']]);
    exit;
}

/* ── ЛОГИН ПО ЮЗЕРНЕЙМУ: ШАГ 1 — запросить код на почту ───────────────────
   Ответ намеренно ВСЕГДА ok:true (даже если юзернейма нет) — чтобы нельзя
   было перебором узнать, какие юзернеймы существуют. Почта нигде не
   раскрывается клиенту. */
if ($action === 'request_login_code') {
    $username = trim($_POST['username'] ?? '');
    if (!$username) { echo json_encode(['ok'=>false,'error'=>'Введите юзернейм']); exit; }

    $db->exec("CREATE TABLE IF NOT EXISTS login_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        code VARCHAR(6) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        used TINYINT(1) NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $s = $db->prepare('SELECT * FROM users WHERE username=?');
    $s->execute([$username]);
    $user = $s->fetch();

    // Юзернейм не найден — отвечаем так же, как при успехе, но письмо не шлём
    if (!$user || empty($user['email'])) { echo json_encode(['ok'=>true]); exit; }

    // Не чаще одного кода в 30 секунд на пользователя
    $rl = $db->prepare("SELECT id FROM login_codes WHERE user_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)");
    $rl->execute([$user['id']]);
    if ($rl->fetch()) { echo json_encode(['ok'=>true]); exit; }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->prepare('INSERT INTO login_codes (user_id, code, expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))')
       ->execute([$user['id'], $code]);

    require_once __DIR__ . '/smtp_mailer_bank.php';
    if (function_exists('wm_smtp_send')) {
        $msg = "Здравствуйте, {$user['username']}!\n\n"
             . "Код для входа в приложение M1plus wallet:\n\n  {$code}\n\n"
             . "Код действителен 10 минут. Если это были не вы — проигнорируйте письмо.\n\n"
             . "— M1plus wallet";
        try { wm_smtp_send($user['email'], 'Код для входа — M1plus wallet', $msg); }
        catch (\Throwable $e) { error_log('request_login_code mail failed: ' . $e->getMessage()); }
    }

    echo json_encode(['ok'=>true]);
    exit;
}

/* ── ЛОГИН ПО ЮЗЕРНЕЙМУ: ШАГ 2 — проверить код из письма ─────────────────── */
if ($action === 'verify_login_code') {
    $username = trim($_POST['username'] ?? '');
    $code     = trim($_POST['code'] ?? '');
    if (!$username || !$code) { echo json_encode(['ok'=>false,'error'=>'Заполните все поля']); exit; }

    $s = $db->prepare('SELECT * FROM users WHERE username=?');
    $s->execute([$username]);
    $user = $s->fetch();
    if (!$user) { echo json_encode(['ok'=>false,'error'=>'Неверный код']); exit; }

    $c = $db->prepare('SELECT * FROM login_codes WHERE user_id=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1');
    $c->execute([$user['id']]);
    $row = $c->fetch();
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Код истёк или не найден — запросите новый']); exit; }

    if ((int)$row['attempts'] >= 5) {
        $db->prepare('UPDATE login_codes SET used=1 WHERE id=?')->execute([$row['id']]);
        echo json_encode(['ok'=>false,'error'=>'Слишком много попыток — запросите новый код']); exit;
    }

    if (!hash_equals($row['code'], $code)) {
        $db->prepare('UPDATE login_codes SET attempts=attempts+1 WHERE id=?')->execute([$row['id']]);
        echo json_encode(['ok'=>false,'error'=>'Неверный код']); exit;
    }

    $db->prepare('UPDATE login_codes SET used=1 WHERE id=?')->execute([$row['id']]);
    $db->prepare('INSERT IGNORE INTO bank_accounts (user_id) VALUES (?)')->execute([$user['id']]);

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['is_admin'] = $user['is_admin'];

    echo json_encode(['ok'=>true, 'username'=>$user['username'], 'is_admin'=>(bool)$user['is_admin']]);
    exit;
}

/* ── ЛОГИН ПО ЮЗЕРНЕЙМУ + ПАРОЛЮ (кнопка «Продолжить с паролем») ─────────── */
if ($action === 'login_password') {
    $username = trim($_POST['username'] ?? '');
    $pass     = $_POST['password'] ?? '';
    if (!$username || !$pass) { echo json_encode(['ok'=>false,'error'=>'Заполните все поля']); exit; }

    $s = $db->prepare('SELECT * FROM users WHERE username=?');
    $s->execute([$username]);
    $user = $s->fetch();

    if (!$user || !password_verify($pass, $user['password'])) {
        echo json_encode(['ok'=>false,'error'=>'Неверный юзернейм или пароль']); exit;
    }

    $db->prepare('INSERT IGNORE INTO bank_accounts (user_id) VALUES (?)')->execute([$user['id']]);

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['is_admin'] = $user['is_admin'];

    echo json_encode(['ok'=>true, 'username'=>$user['username'], 'is_admin'=>(bool)$user['is_admin']]);
    exit;
}

/* ── LOGOUT ─────────────────────────────────────────────────────────────── */
if ($action === 'logout') {
    session_destroy();
    echo json_encode(['ok'=>true]);
    exit;
}

/* ── ТЕКУЩИЙ ПОЛЬЗОВАТЕЛЬ + БАЛАНС (для проверки активной сессии при запуске) */
if ($action === 'me') {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Не авторизован']);
        exit;
    }
    $uid = (int)$_SESSION['user_id'];

    $u = $db->prepare('SELECT * FROM users WHERE id=?');
    $u->execute([$uid]);
    $user = $u->fetch();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Пользователь не найден']);
        exit;
    }

    $acc = $db->prepare('SELECT balance FROM bank_accounts WHERE user_id=?');
    $acc->execute([$uid]);
    $bal = (float)($acc->fetchColumn() ?: 0);

    $avatarUrl = $user['avatar'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $user['avatar'] : null;

    echo json_encode([
        'ok'       => true,
        'username' => $user['username'],
        'email'    => $user['email'],
        'avatar'   => $avatarUrl,
        'balance'  => $bal,
        'is_admin' => (bool)$user['is_admin'],
    ]);
    exit;
}

/* ── МОИ ПЛАСТИКОВЫЕ КАРТЫ (для обычного клиента) ── */
if ($action === 'plastic_cards') {
    if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Не авторизован']); exit; }
    $uid = (int)$_SESSION['user_id'];

    try {
        $s = $db->prepare('SELECT id, name, cover_image, is_delivered, delivery_status, nfc_uid, nfc_scanned_at FROM plastic_cards WHERE user_id=? ORDER BY created_at DESC');
        $s->execute([$uid]);
        $rows = $s->fetchAll();
    } catch (\Throwable $e) { $rows = []; }

    $cards = array_map(function ($c) {
        return [
            'id'              => (int)$c['id'],
            'name'            => $c['name'],
            'cover_image'     => $c['cover_image'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $c['cover_image'] : null,
            'is_delivered'    => (bool)$c['is_delivered'],
            'delivery_status' => $c['delivery_status'],
            'nfc_confirmed'   => !empty($c['nfc_uid']),
        ];
    }, $rows);

    echo json_encode(['ok'=>true, 'cards'=>$cards]);
    exit;
}

/* ── ВСЕ ПЛАСТИКОВЫЕ КАРТЫ (для админа — чтобы выбрать карту для NFC-сканирования) ── */
if ($action === 'admin_plastic_cards') {
    if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Не авторизован']); exit; }

    try {
        $s = $db->query('SELECT pc.id, pc.name, pc.cover_image, pc.is_delivered, pc.delivery_status, pc.nfc_uid, pc.nfc_scanned_at, u.username
                          FROM plastic_cards pc JOIN users u ON u.id = pc.user_id
                          ORDER BY pc.created_at DESC');
        $rows = $s->fetchAll();
    } catch (\Throwable $e) { $rows = []; }

    $cards = array_map(function ($c) {
        return [
            'id'              => (int)$c['id'],
            'name'            => $c['name'],
            'owner'           => $c['username'],
            'cover_image'     => $c['cover_image'] ? (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $c['cover_image'] : null,
            'is_delivered'    => (bool)$c['is_delivered'],
            'delivery_status' => $c['delivery_status'],
            'nfc_confirmed'   => !empty($c['nfc_uid']),
        ];
    }, $rows);

    echo json_encode(['ok'=>true, 'cards'=>$cards]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);