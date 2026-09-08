<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$token  = trim($_POST['token'] ?? '');
$source = $_POST['source'] ?? 'bank';
$db     = getDB();

$s = $db->prepare('SELECT mp.*, m.name AS merchant_name, m.webhook_url, m.secret_key, m.user_id AS merchant_user_id FROM merchant_payments mp JOIN merchants m ON m.id=mp.merchant_id WHERE mp.payment_token=? AND mp.status=?');
$s->execute([$token, 'pending']); $p = $s->fetch();

if (!$p) { echo json_encode(['error'=>'Платёж не найден или уже оплачен']); exit; }

$amount = (float)$p['amount'];

// ── YOOMONEY ──
if ($source === 'yoomoney') {
    $label       = 'mpay_' . $p['id'] . '_' . $token;
    $redirectUrl = 'https://wallet.m1plus.ru/merchant_yoo_redirect.php?token=' . urlencode($token);
    $params = [
        'receiver'      => '4100118931284257',
        'quickpay-form' => 'donate',
        'paymentType'   => 'AC',
        'sum'           => number_format($amount, 2, '.', ''),
        'label'         => $label,
        'comment'       => 'Оплата: ' . $p['merchant_name'] . ($p['external_id'] ? ' #' . $p['external_id'] : ''),
        'need-fio'      => 'false',
        'need-email'    => 'false',
        'need-phone'    => 'false',
        'need-address'  => 'false',
        'successURL'    => $redirectUrl,
    ];
    echo json_encode(['ok'=>1, 'url'=>'https://yoomoney.ru/quickpay/confirm.xml?'.http_build_query($params)]);
    exit;
}

// ── BANK TRANSFER ──
if (empty($_SESSION['user_id'])) { echo json_encode(['error'=>'Не авторизован']); exit; }

$uid = (int)$_SESSION['user_id'];
if ($uid === (int)$p['merchant_user_id']) { echo json_encode(['error'=>'Нельзя оплатить свой же платёж']); exit; }

$ba = $db->prepare('SELECT balance FROM bank_accounts WHERE user_id=?');
$ba->execute([$uid]); $br = $ba->fetch();
$balance = (float)($br['balance'] ?? 0);
if ($balance < $amount) { echo json_encode(['error'=>'Недостаточно средств. Баланс: '.number_format($balance,2,'.',' ').' ₽']); exit; }

// Deduct from payer
$db->prepare('UPDATE bank_accounts SET balance=balance-? WHERE user_id=?')->execute([$amount, $uid]);

// Credit 90% to merchant (10% platform commission)
$merchantGets = round($amount * 0.90, 2);
$commission   = round($amount * 0.10, 2);
$db->prepare('INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)')->execute([$p['merchant_user_id']]);
$db->prepare('UPDATE bank_accounts SET balance=balance+? WHERE user_id=?')->execute([$merchantGets, $p['merchant_user_id']]);

// Mark paid
$db->prepare('UPDATE merchant_payments SET status=?,payment_method=?,payer_user_id=?,paid_at=NOW() WHERE id=?')
   ->execute(['paid', 'bank', $uid, $p['id']]);

// Add to merchant transactions
$db->prepare("INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,?,?)")
   ->execute([$p['merchant_user_id'], 'merchant_payment', $merchantGets, $commission, 'completed']);

// Send webhook
sendMerchantWebhook($p, 'paid', 'bank', $db);

echo json_encode(['ok'=>1]); exit;

// ── WEBHOOK ──
function sendMerchantWebhook(array $p, string $status, string $method, PDO $db): void {
    if (!$p['webhook_url']) return;

    $payload = [
        'event'       => 'payment.paid',
        'payment_id'  => (int)$p['id'],
        'external_id' => $p['external_id'],
        'token'       => $p['payment_token'],
        'amount'      => (float)$p['amount'],
        'merchant_gets' => round((float)$p['amount'] * 0.90, 2),
        'commission'  => round((float)$p['amount'] * 0.10, 2),
        'currency'    => $p['currency'],
        'status'      => $status,
        'method'      => $method,
        'buyer_email' => $p['buyer_email'],
        'buyer_name'  => $p['buyer_name'],
        'buyer_phone' => $p['buyer_phone'],
        'paid_at'     => date('c'),
        'merchant_id' => (int)$p['merchant_id'],
    ];

    $body      = json_encode($payload);
    $signature = hash_hmac('sha256', $body, $p['secret_key']);
    $ts        = (string)time();

    $ch = curl_init($p['webhook_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-M1Bank-Signature: ' . $signature,
            'X-M1Bank-Timestamp: ' . $ts,
            'X-M1Bank-Event: payment.paid',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp   = curl_exec($ch);
    $status2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $sent = ($status2 >= 200 && $status2 < 300) ? 1 : 0;
    $db->prepare('UPDATE merchant_payments SET webhook_sent=?, webhook_attempts=webhook_attempts+1 WHERE id=?')
       ->execute([$sent, $p['id']]);
    error_log("Merchant webhook to {$p['webhook_url']}: HTTP $status2");
}
