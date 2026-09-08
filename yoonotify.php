<?php
declare(strict_types=1);
// ═══════════════════════════════════════════════════════════════
//  M1plus wallet — Universal YooMoney Notification Handler
//  URL: https://wallet.m1plus.ru/pay_notify.php
//  Handles: topups, payment links, merchant payments
//  This is the ONLY url that needs to be set in YooMoney settings
// ═══════════════════════════════════════════════════════════════
require_once __DIR__ . '/config.php';

$label = trim($_POST['label']        ?? '');
$opId  = trim($_POST['operation_id'] ?? '');

if (!$label) { http_response_code(200); echo 'OK'; exit; }

error_log("pay_notify: label=$label op=$opId");

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (\Throwable $e) {
    error_log('pay_notify DB error: '.$e->getMessage());
    http_response_code(500); exit;
}

// ── 1. TOPUP: topup_{id}_uid_{uid} ──────────────────────────────────────────
if (preg_match('/^topup_(\d+)_uid_(\d+)$/', $label, $m)) {
    $topupId = (int)$m[1];
    $uid     = (int)$m[2];

    $s = $pdo->prepare("SELECT * FROM bank_topups WHERE id=? AND user_id=? AND status='pending'");
    $s->execute([$topupId, $uid]); $topup = $s->fetch();

    if ($topup) {
        $pdo->prepare("UPDATE bank_topups SET status='active', yoo_operation_id=? WHERE id=?")
            ->execute([$opId, $topupId]);
        $pdo->prepare("INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)")->execute([$uid]);
        $pdo->prepare("UPDATE bank_accounts SET balance=balance+? WHERE user_id=?")
            ->execute([$topup['credit'], $uid]);
        error_log("pay_notify: topup $topupId credited {$topup['credit']} to uid $uid");
    } else {
        error_log("pay_notify: topup $topupId not found or already processed");
    }
    http_response_code(200); echo 'OK'; exit;
}

// ── 2. PAYMENT LINK: plink_{txId}_lid_{linkId}_uid_{uid} ────────────────────
if (preg_match('/^plink_(\d+)_lid_(\d+)_uid_(\d+)$/', $label, $m)) {
    $txId      = (int)$m[1];
    $creatorId = (int)$m[3];

    $s = $pdo->prepare(
        "SELECT plt.*, pl.one_time, pl.id AS pl_id
         FROM payment_link_txs plt
         JOIN payment_links pl ON pl.id=plt.link_id
         WHERE plt.id=? AND plt.status='pending'"
    );
    $s->execute([$txId]); $tx = $s->fetch();

    if ($tx) {
        $pdo->prepare("UPDATE payment_link_txs SET status='completed', yoo_operation_id=? WHERE id=?")
            ->execute([$opId, $txId]);
        $pdo->prepare("INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)")->execute([$creatorId]);
        $pdo->prepare("UPDATE bank_accounts SET balance=balance+? WHERE user_id=?")
            ->execute([$tx['creator_gets'], $creatorId]);
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,?,?)")
            ->execute([$creatorId,'payment_link',$tx['creator_gets'],round($tx['amount']*0.10,2),'completed']);
        if ($tx['one_time']) {
            $pdo->prepare("UPDATE payment_links SET is_active=0 WHERE id=?")->execute([$tx['pl_id']]);
        }
        error_log("pay_notify: plink tx $txId credited {$tx['creator_gets']} to uid $creatorId");
    }
    http_response_code(200); echo 'OK'; exit;
}

// ── 3. MERCHANT PAYMENT: merpay_{payment_id} ────────────────────────────────
if (preg_match('/^merpay_([a-f0-9]+)$/', $label, $m)) {
    $pid = $m[1];

    $s = $pdo->prepare(
        "SELECT mp.*, m.webhook_url, m.api_key, m.user_id AS owner_id
         FROM merchant_payments mp
         JOIN merchants m ON m.id=mp.merchant_id
         WHERE mp.payment_id=? AND mp.status='pending'"
    );
    $s->execute([$pid]); $mp = $s->fetch();

    if ($mp) {
        $ownerUid = (int)$mp['owner_id'];
        $amount   = (float)$mp['amount'];

        $pdo->prepare("UPDATE merchant_payments SET status='paid', method='yoomoney', yoo_operation_id=?, paid_at=NOW() WHERE payment_id=?")
            ->execute([$opId, $pid]);
        $pdo->prepare("INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)")->execute([$ownerUid]);
        $pdo->prepare("UPDATE bank_accounts SET balance=balance+? WHERE user_id=?")->execute([$amount, $ownerUid]);
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,0,'completed')")
            ->execute([$ownerUid,'merchant_payment',$amount]);

        // Send webhook to merchant
        if ($mp['webhook_url']) {
            sendMerchantWebhook($mp['webhook_url'], $pid, $mp, $mp['api_key']);
        }
        error_log("pay_notify: merchant payment $pid credited $amount to uid $ownerUid");
    }
    http_response_code(200); echo 'OK'; exit;
}

error_log("pay_notify: unknown label format: $label");
http_response_code(200); echo 'OK';

// ── Webhook sender ───────────────────────────────────────────────────────────
function sendMerchantWebhook(string $url, string $pid, array $p, string $secret): void {
    $payload = json_encode([
        'event'       => 'payment.paid',
        'payment_id'  => $pid,
        'amount'      => (float)$p['amount'],
        'currency'    => 'RUB',
        'status'      => 'paid',
        'method'      => 'yoomoney',
        'description' => $p['description'] ?? null,
        'metadata'    => isset($p['metadata']) ? json_decode($p['metadata'], true) : null,
        'paid_at'     => date('c'),
        'created_at'  => $p['created_at'] ?? date('c'),
    ], JSON_UNESCAPED_UNICODE);

    $sig = hash_hmac('sha256', $payload, $secret);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-M1Bank-Signature: '.$sig,
            'X-M1Bank-Event: payment.paid',
            'User-Agent: M1plusWallet-Webhook/1.0',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_exec($ch);
    curl_close($ch);

    try {
        global $pdo;
        $sent = ($httpCode >= 200 && $httpCode < 300) ? 1 : 0;
        $pdo->prepare("UPDATE merchant_payments SET webhook_sent=?,webhook_attempts=webhook_attempts+1 WHERE payment_id=?")
            ->execute([$sent, $pid]);
    } catch (\Throwable $e) {}
}
