<?php
declare(strict_types=1);

// Любую непойманную ошибку/исключение превращаем в JSON, а не в сырой HTML —
// иначе Warning/Fatal ломает JSON.parse() на фронте, и кнопки выглядят так,
// будто "ничего не происходит".
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'Fatal: ' . $err['message']]);
    }
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/m1plus_delivery_setup.php';

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

try { $db = getDB(); } catch (Exception $e) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Ошибка соединения с БД']); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?: [];

// ─── Список карт ─────────────────────────────────────────────────────────────
if ($action === 'list_templates') {
    $rows = $db->query('SELECT id, name, cover_image, price, is_active, created_at FROM delivery_card_templates ORDER BY created_at DESC')->fetchAll();
    foreach ($rows as &$r) {
        $r['cover_image'] = $r['cover_image'] ? UPLOAD_URL . $r['cover_image'] : null;
        $r['price'] = (float)$r['price'];
        $r['is_active'] = (bool)$r['is_active'];
    }
    echo json_encode(['ok'=>true,'templates'=>$rows]); exit;
}

// ─── Создать карту ───────────────────────────────────────────────────────────
// Защита от двойной отправки (двойной клик/повторный POST от браузера):
// если карта с таким же названием и ценой уже создана в последние 10 секунд —
// не создаём вторую, а возвращаем ту же самую.
if ($action === 'create_template') {
    $name  = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    if (!$name) { echo json_encode(['ok'=>false,'error'=>'Укажите название']); exit; }
    if ($price < 0) { echo json_encode(['ok'=>false,'error'=>'Неверная цена']); exit; }

    $dup = $db->prepare(
        'SELECT id FROM delivery_card_templates WHERE name=? AND price=? AND created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND) LIMIT 1'
    );
    $dup->execute([$name, $price]);
    $dupId = $dup->fetchColumn();
    if ($dupId) { echo json_encode(['ok'=>true, 'template_id'=>(int)$dupId, 'duplicate_prevented'=>true]); exit; }

    $cover = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) { echo json_encode(['ok'=>false,'error'=>'Разрешены только JPG, PNG, WEBP']); exit; }
        if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
        $fname = 'cardtpl_' . uniqid() . '.' . $ext;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_DIR . $fname)) {
            echo json_encode(['ok'=>false,'error'=>'Не удалось сохранить файл']); exit;
        }
        $cover = $fname;
    } else {
        echo json_encode(['ok'=>false,'error'=>'Загрузите изображение дизайна']); exit;
    }

    $db->prepare('INSERT INTO delivery_card_templates (name, cover_image, price) VALUES (?,?,?)')
       ->execute([$name, $cover, $price]);

    echo json_encode(['ok'=>true, 'template_id'=>(int)$db->lastInsertId()]); exit;
}

// ─── Изменить карту ──────────────────────────────────────────────────────────
if ($action === 'update_template') {
    $id    = (int)($body['template_id'] ?? 0);
    $name  = trim($body['name'] ?? '');
    $price = (float)($body['price'] ?? 0);
    if (!$id || !$name) { echo json_encode(['ok'=>false,'error'=>'Неверные данные']); exit; }

    $db->prepare('UPDATE delivery_card_templates SET name=?, price=? WHERE id=?')->execute([$name, $price, $id]);
    echo json_encode(['ok'=>true]); exit;
}

// ─── Вкл/выкл карту ──────────────────────────────────────────────────────────
if ($action === 'toggle_template') {
    $id = (int)($body['template_id'] ?? $_POST['template_id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }
    $db->prepare('UPDATE delivery_card_templates SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ─── Удалить карту ───────────────────────────────────────────────────────────
if ($action === 'delete_template') {
    $id = (int)($body['template_id'] ?? $_POST['template_id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }
    $db->prepare('DELETE FROM delivery_card_templates WHERE id=?')->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ─── Добавить зону доставки ──────────────────────────────────────────────────
if ($action === 'add_delivery_zone') {
    $tid  = (int)($body['template_id'] ?? 0);
    $name = trim($body['zone_name'] ?? '');
    $poly = $body['zone_polygon'] ?? null;
    if (!$tid || !$name || !$poly) { echo json_encode(['ok'=>false,'error'=>'Заполните все поля']); exit; }

    $db->prepare('INSERT INTO delivery_zones (card_template_id, zone_name, zone_polygon) VALUES (?,?,?)')
       ->execute([$tid, $name, json_encode($poly)]);

    echo json_encode(['ok'=>true, 'zone_id'=>(int)$db->lastInsertId()]); exit;
}

// ─── Список зон ──────────────────────────────────────────────────────────────
if ($action === 'get_delivery_zones') {
    $tid = (int)($_GET['template_id'] ?? 0);
    if (!$tid) { echo json_encode(['ok'=>false,'error'=>'template_id не указан']); exit; }

    $s = $db->prepare('SELECT id, zone_name, zone_polygon, is_active FROM delivery_zones WHERE card_template_id=? ORDER BY created_at DESC');
    $s->execute([$tid]);
    $zones = [];
    foreach ($s->fetchAll() as $z) {
        $zones[] = ['id'=>$z['id'], 'name'=>$z['zone_name'], 'polygon'=>json_decode($z['zone_polygon'],true), 'is_active'=>(bool)$z['is_active']];
    }
    echo json_encode(['ok'=>true,'zones'=>$zones]); exit;
}

// ─── Удалить зону ────────────────────────────────────────────────────────────
if ($action === 'delete_delivery_zone') {
    $id = (int)($body['zone_id'] ?? $_POST['zone_id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }
    $db->prepare('DELETE FROM delivery_zones WHERE id=?')->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ─── Добавить временной слот ─────────────────────────────────────────────────
if ($action === 'add_delivery_slot') {
    $tid   = (int)($body['template_id'] ?? 0);
    $date  = $body['delivery_date'] ?? '';
    $start = $body['time_start'] ?? '';
    $end   = $body['time_end'] ?? '';
    $max   = (int)($body['max_orders'] ?? 10);

    if (!$tid || !$date || !$start || !$end) { echo json_encode(['ok'=>false,'error'=>'Заполните все поля']); exit; }

    $db->prepare('INSERT INTO delivery_slots (card_template_id, delivery_date, time_start, time_end, max_orders) VALUES (?,?,?,?,?)')
       ->execute([$tid, $date, $start, $end, $max]);

    echo json_encode(['ok'=>true, 'slot_id'=>(int)$db->lastInsertId()]); exit;
}

// ─── Список слотов ───────────────────────────────────────────────────────────
if ($action === 'get_delivery_slots') {
    $tid = (int)($_GET['template_id'] ?? 0);
    $sql = 'SELECT s.*, t.name AS template_name FROM delivery_slots s LEFT JOIN delivery_card_templates t ON t.id=s.card_template_id';
    $params = [];
    if ($tid) { $sql .= ' WHERE s.card_template_id=?'; $params[] = $tid; }
    $sql .= ' ORDER BY s.delivery_date, s.time_start';

    $s = $db->prepare($sql);
    $s->execute($params);
    $slots = [];
    foreach ($s->fetchAll() as $r) {
        $slots[] = [
            'id'=>$r['id'], 'template_name'=>$r['template_name'],
            'date'=>$r['delivery_date'], 'time_start'=>substr($r['time_start'],0,5), 'time_end'=>substr($r['time_end'],0,5),
            'max_orders'=>(int)$r['max_orders'], 'current_orders'=>(int)$r['current_orders'],
        ];
    }
    echo json_encode(['ok'=>true,'slots'=>$slots]); exit;
}

// ─── Удалить слот ────────────────────────────────────────────────────────────
if ($action === 'delete_delivery_slot') {
    $id = (int)($body['slot_id'] ?? $_POST['slot_id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }
    $db->prepare('DELETE FROM delivery_slots WHERE id=?')->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ─── Список заказов ──────────────────────────────────────────────────────────
if ($action === 'get_orders') {
    $status = $_GET['status'] ?? '';
    $sql = 'SELECT o.*, u.username AS user_name, u.email AS user_email, t.name AS template_name
            FROM card_orders o
            LEFT JOIN users u ON u.id=o.user_id
            LEFT JOIN delivery_card_templates t ON t.id=o.card_template_id';
    $params = [];
    if ($status) { $sql .= ' WHERE o.status=?'; $params[] = $status; }
    $sql .= ' ORDER BY o.created_at DESC LIMIT 200';

    $s = $db->prepare($sql);
    $s->execute($params);
    $orders = [];
    foreach ($s->fetchAll() as $o) {
        $orders[] = [
            'id'=>$o['id'], 'user_name'=>$o['user_name'], 'user_email'=>$o['user_email'],
            'template_name'=>$o['template_name'], 'address'=>$o['delivery_address'],
            'status'=>$o['status'], 'confirmation_code'=>$o['confirmation_code'],
            'representative_name'=>$o['representative_name'], 'representative_phone'=>$o['representative_phone'],
            'delivery_date_actual'=>$o['delivery_date_actual'], 'created_at'=>$o['created_at'],
        ];
    }
    echo json_encode(['ok'=>true,'orders'=>$orders]); exit;
}

// ─── Обновить статус заказа + данные представителя ──────────────────────────
if ($action === 'update_order_status') {
    $oid    = (int)($body['order_id'] ?? 0);
    $status = trim($body['status'] ?? '');
    $repN   = trim($body['representative_name'] ?? '');
    $repP   = trim($body['representative_phone'] ?? '');
    $delDate= trim($body['delivery_date_actual'] ?? '');

    $allowed = ['pending','confirmed','in_production','shipped','delivered','cancelled'];
    if (!$oid || !in_array($status, $allowed, true)) { echo json_encode(['ok'=>false,'error'=>'Неверные данные']); exit; }

    $sql = 'UPDATE card_orders SET status=?';
    $params = [$status];
    if ($repN)    { $sql .= ', representative_name=?'; $params[] = $repN; }
    if ($repP)    { $sql .= ', representative_phone=?'; $params[] = $repP; }
    if ($delDate) { $sql .= ', delivery_date_actual=?'; $params[] = $delDate; }
    $sql .= ' WHERE id=?';
    $params[] = $oid;

    $db->prepare($sql)->execute($params);

    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
