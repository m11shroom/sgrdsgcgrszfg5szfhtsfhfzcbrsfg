<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/intl_cards_setup.php';
require_once __DIR__ . '/yoomoney_lib.php';

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Не авторизован']); exit; }

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Ошибка сервера: '.$e->getMessage()]);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['ok'=>false,'error'=>'Fatal: '.$err['message']]);
    }
});

const PRICES = ['visa' => 1500.0, 'mastercard' => 2000.0];

$db     = getDB();
$uid    = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?: [];

/* ─── Создать заказ карты и получить ссылку на оплату ──────────────────────
   Оплата возможна только если админ уже привязал основной кошелёк сайта
   (та же таблица yoomoney_oauth, что используется для пополнений баланса). */
if ($action === 'create_order') {
    $system = $body['payment_system'] ?? '';
    if (!isset(PRICES[$system])) { echo json_encode(['ok'=>false,'error'=>'Выберите Visa или Mastercard']); exit; }

    $cfg = ym_getConfig($db);
    if (!$cfg || empty($cfg['access_token']) || empty($cfg['wallet'])) {
        echo json_encode(['ok'=>false,'error'=>'Оплата временно недоступна — администратор ещё не подключил кошелёк для приёма платежей']); exit;
    }

    $price = PRICES[$system];
    $label = 'intlcard_' . $uid . '_' . time() . '_' . bin2hex(random_bytes(3));

    $db->prepare('INSERT INTO intl_cards (user_id, payment_system, price, payment_label) VALUES (?,?,?,?)')
       ->execute([$uid, $system, $price, $label]);
    $orderId = (int)$db->lastInsertId();

    $params = [
        'receiver'      => $cfg['wallet'],
        'quickpay-form' => 'donate',
        'paymentType'   => 'AC',
        'sum'           => number_format($price, 2, '.', ''),
        'label'         => $label,
        'comment'       => 'Выпуск карты ' . ucfirst($system) . ' — M1plus wallet',
        'need-fio'      => 'false', 'need-email' => 'false', 'need-phone' => 'false', 'need-address' => 'false',
    ];

    echo json_encode([
        'ok'         => true,
        'order_id'   => $orderId,
        'pay_url'    => 'https://yoomoney.ru/quickpay/confirm.xml?' . http_build_query($params),
        'price'      => $price,
    ]); exit;
}

/* ─── Проверка оплаты (вызывается раз в секунду, пока клиент в приложении) ── */
if ($action === 'check_payment') {
    $orderId = (int)($_GET['order_id'] ?? 0);
    if (!$orderId) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }

    $s = $db->prepare('SELECT * FROM intl_cards WHERE id=? AND user_id=?');
    $s->execute([$orderId, $uid]);
    $order = $s->fetch();
    if (!$order) { echo json_encode(['ok'=>false,'error'=>'Заказ не найден']); exit; }

    if ($order['status'] !== 'pending_payment') {
        echo json_encode(['ok'=>true, 'status'=>$order['status']]); exit;
    }

    // Автоотмена: если не оплачено в течение 7 дней с момента создания заказа
    if (strtotime($order['created_at']) < time() - 7*24*3600) {
        $db->prepare("UPDATE intl_cards SET status='cancelled', cancelled_at=NOW() WHERE id=?")->execute([$orderId]);
        echo json_encode(['ok'=>true, 'status'=>'cancelled']); exit;
    }

    $cfg = ym_getConfig($db);
    if (!$cfg || empty($cfg['access_token'])) { echo json_encode(['ok'=>true, 'status'=>'pending_payment']); exit; }

    $op = ym_findPaidOperation($cfg['access_token'], $order['payment_label']);
    if ($op) {
        $db->prepare("UPDATE intl_cards SET status='paid', paid_at=NOW() WHERE id=?")->execute([$orderId]);
        echo json_encode(['ok'=>true, 'status'=>'paid']); exit;
    }

    echo json_encode(['ok'=>true, 'status'=>'pending_payment']); exit;
}

/* ─── Мои карты (для отображения в приложении, с блюром данных) ───────────── */
if ($action === 'my_cards') {
    $s = $db->prepare('SELECT * FROM intl_cards WHERE user_id=? AND status<>"cancelled" ORDER BY created_at DESC');
    $s->execute([$uid]);
    $rows = array_map(function($c){
        return [
            'id'             => $c['id'],
            'payment_system' => $c['payment_system'],
            'price'          => (float)$c['price'],
            'status'         => $c['status'],
            'card_number'    => $c['card_number'] ? implode(' ', str_split($c['card_number'], 4)) : null,
            'expiry'         => $c['expiry'],
            'cvv'            => $c['cvv'],
            'created_at'     => $c['created_at'],
        ];
    }, $s->fetchAll());
    echo json_encode(['ok'=>true, 'cards'=>$rows]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
