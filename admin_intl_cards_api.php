<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/intl_cards_setup.php';

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Ошибка сервера: '.$e->getMessage()]);
    exit;
});

$db     = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?: [];

/* ─── Список заказов (по умолчанию — оплаченные, ждущие данных) ────────────── */
if ($action === 'list_orders') {
    $status = $_GET['status'] ?? '';
    $sql = 'SELECT c.*, u.username FROM intl_cards c JOIN users u ON u.id=c.user_id';
    $params = [];
    if ($status) { $sql .= ' WHERE c.status=?'; $params[] = $status; }
    $sql .= ' ORDER BY c.created_at DESC LIMIT 200';
    $s = $db->prepare($sql);
    $s->execute($params);
    echo json_encode(['ok'=>true, 'orders'=>$s->fetchAll()]); exit;
}

/* ─── Внести данные карты (активирует её) ──────────────────────────────────── */
if ($action === 'fill_card') {
    $id     = (int)($body['order_id'] ?? 0);
    $number = preg_replace('/\D/', '', $body['card_number'] ?? '');
    $expiry = trim($body['expiry'] ?? '');
    $cvv    = trim($body['cvv'] ?? '');

    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }
    if (strlen($number) < 15) { echo json_encode(['ok'=>false,'error'=>'Неверный номер карты']); exit; }
    if (!preg_match('/^\d{2}\/\d{2}$/', $expiry)) { echo json_encode(['ok'=>false,'error'=>'Формат срока: MM/YY']); exit; }
    if (strlen($cvv) < 3) { echo json_encode(['ok'=>false,'error'=>'CVV должен быть 3 цифры']); exit; }

    $c = $db->prepare('SELECT status FROM intl_cards WHERE id=?');
    $c->execute([$id]);
    $row = $c->fetch();
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Заказ не найден']); exit; }
    if ($row['status'] !== 'paid') { echo json_encode(['ok'=>false,'error'=>'Карта должна быть оплачена перед вводом данных']); exit; }

    $db->prepare("UPDATE intl_cards SET card_number=?, expiry=?, cvv=?, status='active', activated_at=NOW() WHERE id=?")
       ->execute([$number, $expiry, $cvv, $id]);

    echo json_encode(['ok'=>true]); exit;
}

/* ─── Отменить заказ вручную ────────────────────────────────────────────────── */
if ($action === 'cancel_order') {
    $id = (int)($body['order_id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }
    $db->prepare("UPDATE intl_cards SET status='cancelled', cancelled_at=NOW() WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
