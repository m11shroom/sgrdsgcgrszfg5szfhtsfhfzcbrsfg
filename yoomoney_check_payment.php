<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yoomoney_lib.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

$cardId = (int)($_GET['card_id'] ?? 0);
$orderId = (int)($_GET['order_id'] ?? 0);
if (!$cardId || !$orderId) {
    echo json_encode(['ok' => false, 'error' => 'Не указаны параметры']);
    exit;
}

$db = getDB();

// Получаем токен и цену
$stmt = $db->prepare("SELECT pc.yoo_access_token, t.price 
                       FROM plastic_cards pc
                       JOIN card_orders o ON o.user_id = pc.user_id
                       JOIN card_templates t ON t.id = o.template_id
                       WHERE pc.id = ? AND o.id = ?");
$stmt->execute([$cardId, $orderId]);
$data = $stmt->fetch();
if (!$data || empty($data['yoo_access_token'])) {
    echo json_encode(['ok' => false, 'error' => 'Кошелёк не привязан']);
    exit;
}

$token = $data['yoo_access_token'];
$price = (float)$data['price'];

// Получаем историю операций
$hist = ym_operationHistory($token, ['records' => 10]);
if (empty($hist['operations'])) {
    echo json_encode(['ok' => true, 'paid' => false]);
    exit;
}

// Ищем поступление на сумму >= price
$paid = false;
foreach ($hist['operations'] as $op) {
    if (($op['status'] ?? '') !== 'success') continue;
    if (($op['direction'] ?? '') !== 'in') continue;
    if ((float)($op['amount'] ?? 0) >= $price) {
        $paid = true;
        break;
    }
}

if ($paid) {
    // Обновляем статус заказа и карты
    $db->prepare("UPDATE card_orders SET status = 'confirmed' WHERE id = ?")->execute([$orderId]);
    $db->prepare("UPDATE plastic_cards SET payment_status = 'paid', is_delivered = 1, delivered_at = NOW() WHERE id = ?")->execute([$cardId]);
    echo json_encode(['ok' => true, 'paid' => true]);
} else {
    echo json_encode(['ok' => true, 'paid' => false]);
}