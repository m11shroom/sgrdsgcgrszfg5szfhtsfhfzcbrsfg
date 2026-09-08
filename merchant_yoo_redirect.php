<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$token = trim($_GET['token'] ?? '');
if (!$token) { header('Location: index.php'); exit; }

$db = getDB();
$s  = $db->prepare("SELECT mp.*, m.webhook_url, m.secret_key, m.user_id AS merchant_user_id
                    FROM merchant_payments mp JOIN merchants m ON m.id=mp.merchant_id
                    WHERE mp.payment_token=? AND mp.status='pending'");
$s->execute([$token]); $p = $s->fetch();

if ($p) {
    $amount       = (float)$p['amount'];
    $merchantGets = round($amount * 0.90, 2);
    $commission   = round($amount * 0.10, 2);

    // Mark paid
    $db->prepare('UPDATE merchant_payments SET status=?,payment_method=?,paid_at=NOW() WHERE id=?')
       ->execute(['paid', 'yoomoney', $p['id']]);

    // Credit 90% to merchant
    $db->prepare('INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)')->execute([$p['merchant_user_id']]);
    $db->prepare('UPDATE bank_accounts SET balance=balance+? WHERE user_id=?')->execute([$merchantGets, $p['merchant_user_id']]);

    // Transaction record (amount=what merchant gets, commission=platform fee)
    $db->prepare("INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,?,?)")
       ->execute([$p['merchant_user_id'], 'merchant_payment', $merchantGets, $commission, 'completed']);

    // Send webhook
    if ($p['webhook_url']) {
        $payload = json_encode([
            'event'       => 'payment.paid',
            'payment_id'  => (int)$p['id'],
            'external_id' => $p['external_id'],
            'token'       => $p['payment_token'],
            'amount'      => $amount,
            'merchant_gets' => $merchantGets,
            'commission'  => $commission,
            'currency'    => $p['currency'],
            'status'      => 'paid',
            'method'      => 'yoomoney',
            'buyer_email' => $p['buyer_email'],
            'buyer_name'  => $p['buyer_name'],
            'buyer_phone' => $p['buyer_phone'],
            'paid_at'     => date('c'),
            'merchant_id' => (int)$p['merchant_id'],
        ]);
        $sig = hash_hmac('sha256', $payload, $p['secret_key']);
        $ch  = curl_init($p['webhook_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-M1Bank-Signature: ' . $sig,
                'X-M1Bank-Timestamp: ' . time(),
                'X-M1Bank-Event: payment.paid',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $r2 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_exec($ch); curl_close($ch);
        $db->prepare('UPDATE merchant_payments SET webhook_sent=?,webhook_attempts=webhook_attempts+1 WHERE id=?')
           ->execute([($r2 >= 200 && $r2 < 300) ? 1 : 0, $p['id']]);
    }
}

header('Location: merchant_success.php?token=' . urlencode($token)); exit;
