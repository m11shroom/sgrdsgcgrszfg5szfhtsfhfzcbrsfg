<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
$db = getDB();
// Таблица terminal_sessions создаётся через migrate.php

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Admin: create terminal session
if ($action === 'create_session') {
    if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }
    $org = trim($_POST['org_name'] ?? '');
    if (!$org) { echo json_encode(['ok'=>false,'error'=>'Введите название организации']); exit; }
    $token = bin2hex(random_bytes(24));
    $db->prepare("INSERT INTO terminal_sessions (token, org_name, status) VALUES (?,?,'waiting')")->execute([$token, $org]);
    $id = $db->lastInsertId();
    echo json_encode(['ok'=>true,'token'=>$token,'id'=>$id]);
    exit;
}

// Admin: set amount (from terminal.php cash register)
if ($action === 'set_amount') {
    $token = $_POST['token'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
    if (!$token || $amount < 0.01) { echo json_encode(['ok'=>false,'error'=>'Неверные данные']); exit; }
    $stmt = $db->prepare("SELECT * FROM terminal_sessions WHERE token=?");
    $stmt->execute([$token]); $sess = $stmt->fetch();
    if (!$sess) { echo json_encode(['ok'=>false,'error'=>'Сессия не найдена']); exit; }
    $db->prepare("UPDATE terminal_sessions SET amount=?, status='pending', updated_at=CURRENT_TIMESTAMP WHERE token=?")->execute([$amount, $token]);
    echo json_encode(['ok'=>true]);
    exit;
}

// Admin: cancel payment request
if ($action === 'cancel') {
    $token = $_POST['token'] ?? '';
    if (!$token) { echo json_encode(['ok'=>false]); exit; }
    $db->prepare("UPDATE terminal_sessions SET amount=NULL, status='waiting', updated_at=CURRENT_TIMESTAMP WHERE token=?")->execute([$token]);
    echo json_encode(['ok'=>true]);
    exit;
}

// Get session status (used by display screen and client scan)
if ($action === 'get_status') {
    $token = $_GET['token'] ?? '';
    if (!$token) { echo json_encode(['ok'=>false,'error'=>'No token']); exit; }
    $stmt = $db->prepare("SELECT id, org_name, amount, status FROM terminal_sessions WHERE token=?");
    $stmt->execute([$token]); $sess = $stmt->fetch();
    if (!$sess) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }
    echo json_encode(['ok'=>true,'session'=>[
        'id'       => $sess['id'],
        'org_name' => $sess['org_name'],
        'amount'   => $sess['amount'],
        'status'   => $sess['status'],
    ]]);
    exit;
}

// Client: pay via terminal
if ($action === 'pay') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'Не авторизован']); exit; }
    $token = $_POST['token'] ?? '';
    if (!$token) { echo json_encode(['ok'=>false,'error'=>'No token']); exit; }
    $stmt = $db->prepare("SELECT * FROM terminal_sessions WHERE token=?");
    $stmt->execute([$token]); $sess = $stmt->fetch();
    if (!$sess) { echo json_encode(['ok'=>false,'error'=>'Сессия не найдена']); exit; }
    if ($sess['status'] !== 'pending') { echo json_encode(['ok'=>false,'error'=>'Нет активного запроса оплаты']); exit; }
    $amount = (float)$sess['amount'];
    $uid = (int)$_SESSION['user_id'];

    // Check balance
    $accStmt = $db->prepare("SELECT * FROM bank_accounts WHERE user_id=?");
    $accStmt->execute([$uid]); $acc = $accStmt->fetch();
    if (!$acc || (float)$acc['balance'] < $amount) {
        echo json_encode(['ok'=>false,'error'=>'Недостаточно средств']);
        exit;
    }

    // Deduct balance
    $db->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE user_id=?")->execute([$amount, $uid]);

    // Record transaction
    $db->prepare("INSERT INTO transactions (user_id, type, amount, status, created_at) VALUES (?,?,?,'completed',CURRENT_TIMESTAMP)")
       ->execute([$uid, 'payment_sent', $amount]);

    // Mark session as paid
    $db->prepare("UPDATE terminal_sessions SET status='paid', paid_by_user_id=?, updated_at=CURRENT_TIMESTAMP WHERE token=?")
       ->execute([$uid, $token]);

    echo json_encode(['ok'=>true,'amount'=>$amount,'org_name'=>$sess['org_name']]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
