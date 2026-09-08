<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/merchant_db.php';
header('Content-Type: application/json; charset=utf-8');

$pid = trim($_POST['pid'] ?? '');
if (!$pid) { echo json_encode(['error'=>'No pid']); exit; }

$db = getDB();
setupMerchantTables($db);

$p = $db->prepare('SELECT mp.*, m.name AS merchant_name FROM merchant_payments mp JOIN merchants m ON m.id=mp.merchant_id WHERE mp.payment_id=?');
$p->execute([$pid]); $p = $p->fetch();
if (!$p) { echo json_encode(['error'=>'Платёж не найден']); exit; }
if ($p['status'] === 'paid') { echo json_encode(['error'=>'Уже оплачен']); exit; }

// Redirect goes to unified yooredirect.php with ?mpid=
$redirectUrl = 'https://wallet.m1plus.ru/yooredirect.php?mpid='.urlencode($pid);
$label       = 'merpay_'.$pid;

$params = [
    'receiver'      => '4100118931284257',
    'quickpay-form' => 'donate',
    'paymentType'   => 'AC',
    'sum'           => number_format((float)$p['amount'],2,'.','' ),
    'label'         => $label,
    'comment'       => 'Оплата: '.htmlspecialchars($p['merchant_name']).' — '.($p['description']??''),
    'need-fio'      => 'false',
    'need-email'    => 'false',
    'need-phone'    => 'false',
    'need-address'  => 'false',
    'successURL'    => $redirectUrl,
];

echo json_encode(['ok'=>1,'url'=>'https://yoomoney.ru/quickpay/confirm.xml?'.http_build_query($params)]);
