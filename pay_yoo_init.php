<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$token      = trim($_POST['token'] ?? '');
$buyerData  = json_decode($_POST['buyer_data'] ?? '{}', true) ?: [];

if (!$token) { echo json_encode(['error'=>'Нет токена']); exit; }

$db = getDB();
$s  = $db->prepare('SELECT * FROM payment_links WHERE token=? AND is_active=1');
$s->execute([$token]); $link = $s->fetch();
if (!$link) { echo json_encode(['error'=>'Ссылка не найдена']); exit; }

// One-time check
if ($link['one_time']) {
    $c = $db->prepare("SELECT id FROM payment_link_txs WHERE link_id=? AND status='completed' LIMIT 1");
    $c->execute([$link['id']]);
    if ($c->fetch()) { echo json_encode(['error'=>'Ссылка уже была оплачена']); exit; }
}

$amount       = (float)$link['amount'];
$creator_gets = round($amount * 0.90, 2);
$paymentId    = strtoupper(bin2hex(random_bytes(8))); // e.g. A1B2C3D4E5F60708

$db->prepare(
    'INSERT INTO payment_link_txs (link_id,payment_id,method,amount,creator_gets,status,buyer_email,buyer_phone,buyer_name) VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([
    $link['id'], $paymentId, 'yoomoney', $amount, $creator_gets, 'pending',
    $buyerData['email'] ?? null, $buyerData['phone'] ?? null, $buyerData['name'] ?? null,
]);
$txId = (int)$db->lastInsertId();

$label       = 'plink_'.$txId.'_lid_'.$link['id'].'_uid_'.$link['user_id'];
$redirectUrl = 'https://wallet.m1plus.ru/yooredirect.php?tx='.$txId.'&t='.urlencode($token);

// Get creator username
$cu = $db->prepare('SELECT username FROM users WHERE id=?');
$cu->execute([$link['user_id']]); $creator = $cu->fetch();

$params = [
    'receiver'      => '4100118931284257',
    'quickpay-form' => 'donate',
    'paymentType'   => 'AC',
    'sum'           => number_format($amount,2,'.','' ),
    'label'         => $label,
    'comment'       => 'Оплата: '.$link['service_name'].' (@'.($creator['username']??'').')',
    'need-fio'      => 'false',
    'need-email'    => 'false',
    'need-phone'    => 'false',
    'need-address'  => 'false',
    'successURL'    => $redirectUrl,
];

echo json_encode(['ok'=>1,'url'=>'https://yoomoney.ru/quickpay/confirm.xml?'.http_build_query($params),'tx_id'=>$txId,'payment_id'=>$paymentId]);
