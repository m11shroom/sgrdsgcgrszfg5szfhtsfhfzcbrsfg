<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function generateCheckId(int $txId, string $createdAt, string $amount): string {
    $hash = substr(hash_hmac('sha256', $txId . $createdAt . $amount, 'M1plusWalletCheck'), 0, 8);
    return 'CHK-' . $txId . '-' . $hash;
}

function go(string $url, string $msg = ''): never {
    if ($msg) $_SESSION['flash'] = $msg;
    header('Location: ' . $url);
    exit;
}

$action = $_POST['action'] ?? '';

// ── LOGIN ──────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if (!$email || !$pass) go('index.php', 'Заполните все поля');

    $db = getDB();
    $s  = $db->prepare('SELECT * FROM users WHERE email=?');
    $s->execute([$email]);
    $user = $s->fetch();

    if (!$user || !password_verify($pass, $user['password'])) {
        go('index.php', 'Неверный email или пароль');
    }

    $db->prepare('INSERT IGNORE INTO bank_accounts (user_id) VALUES (?)')->execute([$user['id']]);

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['is_admin'] = $user['is_admin'];

    header('Location: ' . ($user['is_admin'] ? 'admin.php' : 'dashboard.php'));
    exit;
}

// ── LOGOUT ────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

// ── TRANSFER (card / sbp) ─────────────────────────────────────────────────
if ($action === 'transfer') {
    if (empty($_SESSION['user_id'])) go('index.php');
    $uid    = (int)$_SESSION['user_id'];
    $type   = $_POST['type'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
    $card   = trim($_POST['card_number'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    $bank   = trim($_POST['bank_name'] ?? '');
    $db     = getDB();

    if ($amount < 200) go('dashboard.php', 'Минимальная сумма 200 ₽');

    $accRow = $db->prepare('SELECT balance FROM bank_accounts WHERE user_id=?');
    $accRow->execute([$uid]);
    $balance = (float)($accRow->fetch()['balance'] ?? 0);

    if ($type === 'card') {
        $digits = preg_replace('/\D/', '', $card);
        if (strlen($digits) !== 16) go('dashboard.php', 'Номер карты — 16 цифр');
        $total = $amount + 100;
        if ($balance < $total) go('dashboard.php', 'Недостаточно средств. Нужно: '.number_format($total,2).' ₽, доступно: '.number_format($balance,2).' ₽');
        $db->prepare('UPDATE bank_accounts SET balance=balance-? WHERE user_id=?')->execute([$total, $uid]);
        $db->prepare('INSERT INTO transactions (user_id,type,card_number,amount,commission) VALUES (?,?,?,?,100)')->execute([$uid, 'card', $digits, $amount]);
    } elseif ($type === 'sbp') {
        if (!$phone) go('dashboard.php', 'Введите номер телефона');
        if (!$bank)  go('dashboard.php', 'Выберите банк');
        if ($balance < $amount) go('dashboard.php', 'Недостаточно средств. Нужно: '.number_format($amount,2).' ₽, доступно: '.number_format($balance,2).' ₽');
        $db->prepare('UPDATE bank_accounts SET balance=balance-? WHERE user_id=?')->execute([$amount, $uid]);
        $db->prepare('INSERT INTO transactions (user_id,type,phone,bank_name,amount,commission) VALUES (?,?,?,?,?,0)')->execute([$uid, 'sbp', $phone, $bank, $amount]);
    } else {
        go('dashboard.php', 'Неверный тип');
    }

    go('dashboard.php', 'success:Заявка отправлена');
}

// ── TRANSFER TO USER ──────────────────────────────────────────────────────
if ($action === 'transfer_user') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['user_id'])) { echo json_encode(['error' => 'Не авторизован']); exit; }

    $uid      = (int)$_SESSION['user_id'];
    $toUserId = (int)($_POST['to_user_id'] ?? 0);
    $amount   = (float)($_POST['amount'] ?? 0);
    $db       = getDB();

    if (!$toUserId || $toUserId === $uid) { echo json_encode(['error' => 'Неверный получатель']); exit; }
    if ($amount < 1) { echo json_encode(['error' => 'Минимальная сумма 1 ₽']); exit; }

    $rec = $db->prepare('SELECT id, username FROM users WHERE id=? AND is_admin=0');
    $rec->execute([$toUserId]);
    $recipient = $rec->fetch();
    if (!$recipient) { echo json_encode(['error' => 'Получатель не найден']); exit; }

    $bal = $db->prepare('SELECT balance FROM bank_accounts WHERE user_id=?');
    $bal->execute([$uid]);
    $balance = (float)($bal->fetch()['balance'] ?? 0);
    if ($balance < $amount) {
        echo json_encode(['error' => 'Недостаточно средств. Доступно: '.number_format($balance,2,'.',' ').' ₽']); exit;
    }

    $db->prepare('UPDATE bank_accounts SET balance=balance-? WHERE user_id=?')->execute([$amount, $uid]);
    $db->prepare('INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)')->execute([$toUserId]);
    $db->prepare('UPDATE bank_accounts SET balance=balance+? WHERE user_id=?')->execute([$amount, $toUserId]);
    $db->prepare('INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,0,?)')->execute([$uid, 'user_out', $amount, 'completed']);
    $db->prepare('INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,0,?)')->execute([$toUserId, 'user_in', $amount, 'completed']);

    $lastId = (int)$db->lastInsertId();
    $txRow  = $db->prepare('SELECT created_at, amount FROM transactions WHERE id=?');
    $txRow->execute([$lastId]);
    $txRow   = $txRow->fetch();
    $checkId = $txRow ? generateCheckId($lastId, $txRow['created_at'], (string)$txRow['amount']) : '';

    echo json_encode(['ok' => 1, 'to_username' => $recipient['username'], 'check_id' => $checkId]);
    exit;
}

// ── UPDATE STATUS (admin) ─────────────────────────────────────────────────
if ($action === 'update_status') {
    if (empty($_SESSION['is_admin'])) go('index.php');

    $txId   = (int)($_POST['tx_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!in_array($status, ['processing', 'completed', 'declined'], true)) go('admin.php');

    $db = getDB();
    try {
        $txStmt = $db->prepare('SELECT * FROM transactions WHERE id=?');
        $txStmt->execute([$txId]);
        $tx = $txStmt->fetch();
        if (!$tx) go('admin.php', 'Не найдено');

        $type       = $tx['type'] ?? '';
        $isTransfer = in_array($type, ['card', 'sbp', ''], true);

        if ($isTransfer && $status === 'declined' && $tx['status'] !== 'declined' && !$tx['refunded']) {
            $refund = (float)$tx['amount'] + (float)$tx['commission'];
            $db->prepare('UPDATE bank_accounts SET balance=balance+? WHERE user_id=?')->execute([$refund, $tx['user_id']]);
            $db->prepare('UPDATE transactions SET status=?,refunded=1 WHERE id=?')->execute([$status, $txId]);
        } elseif ($isTransfer && $tx['status'] === 'declined' && $status !== 'declined' && $tx['refunded']) {
            $deduct = (float)$tx['amount'] + (float)$tx['commission'];
            $db->prepare('UPDATE bank_accounts SET balance=balance-? WHERE user_id=?')->execute([$deduct, $tx['user_id']]);
            $db->prepare('UPDATE transactions SET status=?,refunded=0 WHERE id=?')->execute([$status, $txId]);
        } else {
            $db->prepare('UPDATE transactions SET status=? WHERE id=?')->execute([$status, $txId]);
        }
    } catch (\Throwable $e) {
        error_log('update_status: ' . $e->getMessage());
    }

    header('Location: admin.php');
    exit;
}

// ── Fallback ──────────────────────────────────────────────────────────────
header('Location: index.php');
exit;
