<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$label = $_POST['label']        ?? '';
$opId  = $_POST['operation_id'] ?? '';

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
    );
} catch (\Throwable $e) { http_response_code(500); exit; }

// ── PAYMENT LINK ──
if (preg_match('/^plink_(\d+)_lid_(\d+)_uid_(\d+)$/', $label, $m)) {
    $txId      = (int)$m[1];
    $creatorId = (int)$m[3];

    $s = $pdo->prepare("SELECT plt.*, pl.one_time, pl.id AS pl_id FROM payment_link_txs plt JOIN payment_links pl ON pl.id=plt.link_id WHERE plt.id=? AND plt.status='pending'");
    $s->execute([$txId]); $tx = $s->fetch(PDO::FETCH_ASSOC);

    if ($tx) {
        $pdo->prepare('UPDATE payment_link_txs SET status=?,yoo_operation_id=? WHERE id=?')->execute(['completed',$opId,$txId]);
        $pdo->prepare('INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)')->execute([$creatorId]);
        $pdo->prepare('UPDATE bank_accounts SET balance=balance+? WHERE user_id=?')->execute([$tx['creator_gets'],$creatorId]);
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,?,?)")
            ->execute([$creatorId,'payment_link',$tx['creator_gets'],round($tx['amount']*0.10,2),'completed']);
        if ($tx['one_time']) {
            $pdo->prepare('UPDATE payment_links SET is_active=0 WHERE id=?')->execute([$tx['pl_id']]);
        }
    }
    http_response_code(200); echo 'OK'; exit;
}

// ── TOPUP ──
if (preg_match('/^topup_(\d+)_uid_(\d+)$/', $label, $m)) {
    $topupId = (int)$m[1]; $uid = (int)$m[2];
    $s = $pdo->prepare("SELECT * FROM bank_topups WHERE id=? AND user_id=? AND status='pending'");
    $s->execute([$topupId,$uid]); $t = $s->fetch(PDO::FETCH_ASSOC);
    if ($t) {
        $pdo->prepare('UPDATE bank_topups SET status=?,yoo_operation_id=? WHERE id=?')->execute(['active',$opId,$topupId]);
        $pdo->prepare('INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)')->execute([$uid]);
        $pdo->prepare('UPDATE bank_accounts SET balance=balance+? WHERE user_id=?')->execute([$t['credit'],$uid]);
    }
    http_response_code(200); echo 'OK'; exit;
}

http_response_code(200); echo 'OK';
