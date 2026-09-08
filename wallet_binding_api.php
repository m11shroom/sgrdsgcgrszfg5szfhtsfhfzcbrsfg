<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

// Кошелёк привязывает ТОЛЬКО админ, и только к уже оформленному заказу карты.
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

try { $db = getDB(); } catch (Exception $e) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Ошибка соединения с БД']); exit; }

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

$db->exec("CREATE TABLE IF NOT EXISTS wallet_transactions_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    wallet_binding_id INT UNSIGNED NOT NULL,
    yoo_operation_id VARCHAR(64) NOT NULL UNIQUE,
    amount DECIMAL(14,2) NOT NULL,
    credited_amount DECIMAL(14,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    credited_at DATETIME DEFAULT NULL,
    KEY idx_binding (wallet_binding_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?: [];

// Привязка кошелька теперь делается ТОЛЬКО через OAuth-авторизацию ЮMoney
// (см. yoomoney_auth.php?connect_delivery_order=ID) — реальная кнопка "Авторизовать
// через ЮMoney" в admin_delivery_cards.php, без ручного ввода токенов.

// ─── Инфо о привязанном к заказу кошельке ───────────────────────────────────
if ($action === 'get_wallet_info') {
    $orderId = (int)($_GET['card_order_id'] ?? 0);
    if (!$orderId) { echo json_encode(['ok'=>false,'error'=>'Не указан заказ']); exit; }

    $s = $db->prepare('SELECT yoo_wallet, connected_at, balance_cached, balance_updated_at FROM wallet_bindings WHERE card_order_id=?');
    $s->execute([$orderId]);
    $w = $s->fetch();

    if (!$w) { echo json_encode(['ok'=>true,'connected'=>false]); exit; }

    echo json_encode([
        'ok'=>true, 'connected'=>true,
        'wallet_id'=>$w['yoo_wallet'],
        'connected_at'=>$w['connected_at'],
        'balance'=>$w['balance_cached'],
        'balance_updated_at'=>$w['balance_updated_at'],
    ]);
    exit;
}

// ─── Отвязать кошелёк от заказа ──────────────────────────────────────────────
if ($action === 'unbind_wallet') {
    $orderId = (int)($body['card_order_id'] ?? 0);
    if (!$orderId) { echo json_encode(['ok'=>false,'error'=>'Не указан заказ']); exit; }
    $db->prepare('DELETE FROM wallet_bindings WHERE card_order_id=?')->execute([$orderId]);
    echo json_encode(['ok'=>true]); exit;
}

// ─── Опрос операций раз в 2 сек (пока у админа открыта карточка заказа) ─────
// Найденные новые входящие операции зачисляются на bank_accounts.balance
// владельца заказа (card_orders.user_id), 97% после 3% комиссии.
if ($action === 'poll_transactions') {
    require_once __DIR__ . '/yoomoney_lib.php';

    $orderId = (int)($_GET['card_order_id'] ?? 0);
    if (!$orderId) { echo json_encode(['ok'=>false,'error'=>'Не указан заказ']); exit; }

    $s = $db->prepare('SELECT id, yoo_wallet, yoo_access_token FROM wallet_bindings WHERE card_order_id=?');
    $s->execute([$orderId]);
    $w = $s->fetch();
    if (!$w) { echo json_encode(['ok'=>false,'error'=>'Кошелёк не привязан']); exit; }

    $order = $db->prepare('SELECT user_id FROM card_orders WHERE id=?');
    $order->execute([$orderId]);
    $ownerUid = (int)$order->fetchColumn();
    if (!$ownerUid) { echo json_encode(['ok'=>false,'error'=>'Владелец заказа не найден']); exit; }

    $hist   = ym_operationHistory($w['yoo_access_token'], ['records' => 20]);
    $newOps = $hist['operations'] ?? [];

    $credited = 0;
    foreach ($newOps as $op) {
        if (($op['status'] ?? '') !== 'success' || ($op['direction'] ?? '') !== 'in') continue;

        $opId   = (string)($op['operation_id'] ?? '');
        $amount = (float)($op['amount'] ?? 0);
        if (!$opId || $amount <= 0) continue;

        $dup = $db->prepare('SELECT id FROM wallet_transactions_log WHERE yoo_operation_id=?');
        $dup->execute([$opId]);
        if ($dup->fetch()) continue;

        $creditedAmount = round($amount * 0.97, 2); // комиссия 3%

        $db->prepare('INSERT INTO wallet_transactions_log (wallet_binding_id, yoo_operation_id, amount, credited_amount, status, credited_at) VALUES (?,?,?,?,?,NOW())')
           ->execute([$w['id'], $opId, $amount, $creditedAmount, 'credited']);

        $db->prepare('INSERT IGNORE INTO bank_accounts (user_id, balance) VALUES (?,0)')->execute([$ownerUid]);
        $db->prepare('UPDATE bank_accounts SET balance=balance+? WHERE user_id=?')->execute([$creditedAmount, $ownerUid]);
        $db->prepare("INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,?,?)")
           ->execute([$ownerUid, 'wallet_topup', $creditedAmount, round($amount*0.03,2), 'completed']);

        $credited++;
    }

    $db->prepare('UPDATE wallet_bindings SET last_check=NOW() WHERE id=?')->execute([$w['id']]);

    echo json_encode(['ok'=>true, 'new_transactions'=>$credited]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
