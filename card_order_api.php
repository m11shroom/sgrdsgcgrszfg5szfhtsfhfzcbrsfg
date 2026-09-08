<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/m1plus_delivery_setup.php'; // создаёт таблицы если их нет

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Не авторизован']); exit; }

try { $db = getDB(); } catch (Exception $e) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Ошибка соединения с БД']); exit; }

$uid    = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?: [];

// ─── Список доступных карт ──────────────────────────────────────────────────
if ($action === 'get_templates') {
    $s = $db->query('SELECT id, name, cover_image, price FROM delivery_card_templates WHERE is_active=1 ORDER BY name');
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['cover_image'] = $r['cover_image'] ? UPLOAD_URL . $r['cover_image'] : null;
        $r['price'] = (float)$r['price'];
    }
    echo json_encode(['ok'=>true,'templates'=>$rows]); exit;
}

// ─── Зоны доставки карты ────────────────────────────────────────────────────
if ($action === 'get_zones') {
    $tid = (int)($_GET['template_id'] ?? 0);
    if (!$tid) { echo json_encode(['ok'=>false,'error'=>'template_id не указан']); exit; }

    $s = $db->prepare('SELECT id, zone_name, zone_polygon FROM delivery_zones WHERE card_template_id=? AND is_active=1');
    $s->execute([$tid]);
    $zones = [];
    foreach ($s->fetchAll() as $z) {
        $zones[] = ['id'=>$z['id'], 'name'=>$z['zone_name'], 'polygon'=>json_decode($z['zone_polygon'], true)];
    }
    echo json_encode(['ok'=>true,'zones'=>$zones]); exit;
}

// ─── Слоты доставки карты ───────────────────────────────────────────────────
if ($action === 'get_slots') {
    $tid = (int)($_GET['template_id'] ?? 0);
    if (!$tid) { echo json_encode(['ok'=>false,'error'=>'template_id не указан']); exit; }

    $s = $db->prepare(
        'SELECT id, delivery_date, time_start, time_end, max_orders, current_orders
         FROM delivery_slots WHERE card_template_id=? AND is_active=1 AND delivery_date>=CURDATE()
         ORDER BY delivery_date, time_start'
    );
    $s->execute([$tid]);
    $slots = [];
    foreach ($s->fetchAll() as $r) {
        $slots[] = [
            'id'=>$r['id'], 'date'=>$r['delivery_date'],
            'time_start'=>substr($r['time_start'],0,5), 'time_end'=>substr($r['time_end'],0,5),
            'available'=>(int)$r['current_orders'] < (int)$r['max_orders'],
        ];
    }
    echo json_encode(['ok'=>true,'slots'=>$slots]); exit;
}

// ─── Проверка: доступна ли доставка в точке ─────────────────────────────────
if ($action === 'check_zone_available') {
    $tid = (int)($body['template_id'] ?? 0);
    $lat = (float)($body['latitude'] ?? 0);
    $lon = (float)($body['longitude'] ?? 0);
    if (!$tid || !$lat || !$lon) { echo json_encode(['ok'=>false,'error'=>'Не хватает параметров']); exit; }

    $s = $db->prepare('SELECT id, zone_name, zone_polygon FROM delivery_zones WHERE card_template_id=? AND is_active=1');
    $s->execute([$tid]);
    $available = [];
    foreach ($s->fetchAll() as $z) {
        $poly = json_decode($z['zone_polygon'], true);
        if (m1_point_in_polygon($lat, $lon, $poly)) {
            $available[] = ['id'=>$z['id'], 'name'=>$z['zone_name']];
        }
    }
    echo json_encode(['ok'=>true,'available'=>count($available)>0,'zones'=>$available]); exit;
}

function m1_point_in_polygon(float $lat, float $lon, ?array $polygon): bool {
    $points = $polygon['coordinates'][0] ?? [];
    if (empty($points)) return false;

    // Ray casting алгоритм
    $inside = false;
    $n = count($points);
    for ($i=0, $j=$n-1; $i<$n; $j=$i++) {
        $xi = $points[$i][0]; $yi = $points[$i][1];
        $xj = $points[$j][0]; $yj = $points[$j][1];
        $intersect = (($yi > $lat) != ($yj > $lat))
            && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
        if ($intersect) $inside = !$inside;
    }
    return $inside;
}

// ─── Создание заказа ─────────────────────────────────────────────────────────
if ($action === 'create_order') {
    $tid   = (int)($body['template_id'] ?? 0);
    $zid   = (int)($body['zone_id'] ?? 0);
    $sid   = (int)($body['slot_id'] ?? 0);
    $addr  = trim($body['address'] ?? '');
    $lat   = (float)($body['latitude'] ?? 0);
    $lon   = (float)($body['longitude'] ?? 0);
    $pay   = in_array($body['payment_method'] ?? '', ['balance','yoomoney']) ? $body['payment_method'] : 'balance';

    if (!$tid || !$zid || !$sid || !$addr) { echo json_encode(['ok'=>false,'error'=>'Заполните все поля']); exit; }

    $tmpl = $db->prepare('SELECT * FROM delivery_card_templates WHERE id=? AND is_active=1');
    $tmpl->execute([$tid]);
    $tmpl = $tmpl->fetch();
    if (!$tmpl) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена']); exit; }

    $price = (float)$tmpl['price'];

    if ($pay === 'balance') {
        $bal = $db->prepare('SELECT balance FROM bank_accounts WHERE user_id=?');
        $bal->execute([$uid]);
        $balance = (float)($bal->fetchColumn() ?: 0);
        if ($balance < $price) { echo json_encode(['ok'=>false,'error'=>'Недостаточно средств на балансе']); exit; }
        $db->prepare('UPDATE bank_accounts SET balance=balance-? WHERE user_id=?')->execute([$price, $uid]);
        $db->prepare("INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,0,?)")
           ->execute([$uid, 'card_order', $price, 'completed']);
    }
    // payment_method=yoomoney — фронт должен отдельно инициировать оплату через YooMoney

    $confCode = strtoupper(bin2hex(random_bytes(8)));

    $db->prepare(
        'INSERT INTO card_orders
         (user_id, card_template_id, delivery_zone_id, delivery_slot_id, delivery_address,
          delivery_latitude, delivery_longitude, payment_method, status, confirmation_code)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([$uid, $tid, $zid, $sid, $addr, $lat, $lon, $pay, 'confirmed', $confCode]);

    $orderId = (int)$db->lastInsertId();

    $db->prepare('UPDATE delivery_slots SET current_orders=current_orders+1 WHERE id=?')->execute([$sid]);

    // Превью карты — берём дизайн шаблона как есть (реальную генерацию можно доработать)
    if ($tmpl['cover_image']) {
        $db->prepare('INSERT INTO card_previews (card_order_id, preview_image) VALUES (?,?)')
           ->execute([$orderId, $tmpl['cover_image']]);
    }

    echo json_encode(['ok'=>true, 'order_id'=>$orderId, 'confirmation_code'=>$confCode]); exit;
}

// ─── Статус заказа ───────────────────────────────────────────────────────────
if ($action === 'get_order_status') {
    $oid = (int)($_GET['order_id'] ?? 0);
    if (!$oid) { echo json_encode(['ok'=>false,'error'=>'order_id не указан']); exit; }

    $s = $db->prepare(
        'SELECT o.*, t.name AS template_name, t.cover_image, p.preview_image
         FROM card_orders o
         LEFT JOIN delivery_card_templates t ON t.id=o.card_template_id
         LEFT JOIN card_previews p ON p.card_order_id=o.id
         WHERE o.id=? AND o.user_id=?'
    );
    $s->execute([$oid, $uid]);
    $o = $s->fetch();
    if (!$o) { echo json_encode(['ok'=>false,'error'=>'Заказ не найден']); exit; }

    echo json_encode(['ok'=>true, 'order'=>[
        'id'=>$o['id'], 'template_name'=>$o['template_name'],
        'template_image'=>$o['cover_image'] ? UPLOAD_URL.$o['cover_image'] : null,
        'preview_image'=>$o['preview_image'] ? UPLOAD_URL.$o['preview_image'] : null,
        'address'=>$o['delivery_address'], 'status'=>$o['status'],
        'confirmation_code'=>$o['confirmation_code'], 'created_at'=>$o['created_at'],
    ]]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
