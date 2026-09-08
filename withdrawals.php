<?php
/**
 * withdrawals.php — вывод средств артиста через FPS (СБП)
 * Таблица: withdrawals (id, user_id, phone, bank_name, amount, status, refunded, created_at)
 * status: processing | completed | declined
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Fatal: ' . $e['message']]);
    }
});

if (session_status() === PHP_SESSION_NONE) session_start();

function wd_json($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

function wd_user_id($input) {
    foreach (array('user_id', 'id', 'userId', 'uid', 'wm_user_id', 'wm_uid') as $k) {
        if (!empty($_SESSION[$k]) && is_numeric($_SESSION[$k])) return (int)$_SESSION[$k];
    }
    if (!empty($input['user_id']) && is_numeric($input['user_id'])) return (int)$input['user_id'];
    return 0;
}

$input  = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = array();
$action = $input['action'] ?? ($_POST['action'] ?? ($_GET['action'] ?? ''));

try {
    require_once __DIR__ . '/db.php';
    $pdo = m1_get_db();
} catch (Throwable $e) {
    wd_json(['success' => false, 'error' => 'DB: ' . $e->getMessage()]);
}

// Создаём таблицу если её нет
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS withdrawals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        phone VARCHAR(32) NOT NULL,
        bank_name VARCHAR(128) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'processing',
        refunded TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* ignore */ }

/* ─── Создать заявку на вывод ─── */
if ($action === 'createWithdrawal') {
    $uid    = wd_user_id($input);
    if (!$uid) wd_json(['success' => false, 'error' => 'Not authorized']);

    $phone  = trim($input['phone'] ?? '');
    $bank   = trim($input['bank_name'] ?? '');
    $amount = (float)($input['amount'] ?? 0);

    if (!$phone) wd_json(['success' => false, 'error' => 'Enter phone number']);
    if (!$bank)  wd_json(['success' => false, 'error' => 'Enter bank name']);
    if ($amount < 100) wd_json(['success' => false, 'error' => 'Minimum withdrawal is 100 ₽']);

    // Проверяем баланс
    $balRow = $pdo->prepare("SELECT balance FROM user_balances WHERE user_id = ?");
    try {
        $balRow->execute([$uid]);
        $balance = (float)($balRow->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        // Таблица балансов может называться иначе — пробуем users
        $balance = 0;
        try {
            $b2 = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
            $b2->execute([$uid]);
            $balance = (float)($b2->fetchColumn() ?: 0);
        } catch (Throwable $e2) {}
    }

    if ($amount > $balance) {
        wd_json(['success' => false, 'error' => 'Insufficient funds. Available: ' . number_format($balance, 2, '.', ' ') . ' ₽']);
    }

    // Списываем с баланса сразу
    $deducted = false;
    try {
        $upd = $pdo->prepare("UPDATE user_balances SET balance = balance - ? WHERE user_id = ?");
        $upd->execute([$amount, $uid]);
        $deducted = $upd->rowCount() > 0;
    } catch (Throwable $e) {}
    if (!$deducted) {
        try {
            $upd2 = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
            $upd2->execute([$amount, $uid]);
        } catch (Throwable $e) {}
    }

    // Создаём заявку
    $ins = $pdo->prepare("INSERT INTO withdrawals (user_id, phone, bank_name, amount, status) VALUES (?, ?, ?, ?, 'processing')");
    $ins->execute([$uid, $phone, $bank, $amount]);

    wd_json(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

/* ─── История выводов пользователя ─── */
if ($action === 'getWithdrawals') {
    $uid = wd_user_id($input);
    if (!$uid) wd_json(['success' => false, 'error' => 'Not authorized', 'withdrawals' => []]);

    $st = $pdo->prepare("SELECT id, phone, bank_name, amount, status, created_at FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC");
    $st->execute([$uid]);
    wd_json(['success' => true, 'withdrawals' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ─── Список всех выводов (админ) ─── */
if ($action === 'adminGetWithdrawals') {
    if (empty($_SESSION['is_admin'])) wd_json(['success' => false, 'error' => 'Forbidden']);
    $rows = $pdo->query("SELECT w.*, u.email, u.nickname FROM withdrawals w LEFT JOIN users u ON u.id = w.user_id ORDER BY w.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    wd_json(['success' => true, 'withdrawals' => $rows]);
}

/* ─── Сменить статус (админ) ─── */
if ($action === 'adminUpdateWithdrawal') {
    if (empty($_SESSION['is_admin'])) wd_json(['success' => false, 'error' => 'Forbidden']);

    $wid    = (int)($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    if (!in_array($status, ['processing', 'completed', 'declined'], true)) {
        wd_json(['success' => false, 'error' => 'Invalid status']);
    }

    $wRow = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ?");
    $wRow->execute([$wid]);
    $w = $wRow->fetch(PDO::FETCH_ASSOC);
    if (!$w) wd_json(['success' => false, 'error' => 'Not found']);

    // Если отклонено и ещё не возвращено — вернуть на баланс
    if ($status === 'declined' && $w['status'] !== 'declined' && !$w['refunded']) {
        $refunded = false;
        try {
            $r = $pdo->prepare("UPDATE user_balances SET balance = balance + ? WHERE user_id = ?");
            $r->execute([$w['amount'], $w['user_id']]);
            $refunded = $r->rowCount() > 0;
        } catch (Throwable $e) {}
        if (!$refunded) {
            try {
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$w['amount'], $w['user_id']]);
            } catch (Throwable $e) {}
        }
        $pdo->prepare("UPDATE withdrawals SET status = ?, refunded = 1 WHERE id = ?")->execute([$status, $wid]);
    }
    // Если сняли отклонение — снова списать
    elseif ($w['status'] === 'declined' && $status !== 'declined' && $w['refunded']) {
        try {
            $pdo->prepare("UPDATE user_balances SET balance = balance - ? WHERE user_id = ?")->execute([$w['amount'], $w['user_id']]);
        } catch (Throwable $e) {
            try { $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$w['amount'], $w['user_id']]); } catch (Throwable $e2) {}
        }
        $pdo->prepare("UPDATE withdrawals SET status = ?, refunded = 0 WHERE id = ?")->execute([$status, $wid]);
    }
    else {
        $pdo->prepare("UPDATE withdrawals SET status = ? WHERE id = ?")->execute([$status, $wid]);
    }

    wd_json(['success' => true]);
}

wd_json(['success' => false, 'error' => 'Unknown action: ' . $action]);
