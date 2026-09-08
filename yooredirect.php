<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$db = getDB();

// ── TOPUP: ?topup_id=X ──
if (!empty($_GET['topup_id']) && !empty($_SESSION['user_id'])) {
    $topupId = (int)$_GET['topup_id'];
    $uid     = (int)$_SESSION['user_id'];
    $s = $db->prepare("SELECT * FROM bank_topups WHERE id=? AND user_id=? AND status='pending'");
    $s->execute([$topupId,$uid]); $t = $s->fetch();
    if ($t) {
        $db->prepare('UPDATE bank_topups SET status=? WHERE id=?')->execute(['active',$topupId]);
        $db->prepare('INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)')->execute([$uid]);
        $db->prepare('UPDATE bank_accounts SET balance=balance+? WHERE user_id=?')->execute([$t['credit'],$uid]);
        $_SESSION['flash'] = 'success:Баланс пополнен на '.number_format((float)$t['credit'],2,'.',' ').' ₽';
    }
    header('Location: dashboard.php'); exit;
}

// ── PAYMENT LINK: ?tx=X&t=TOKEN ──
if (!empty($_GET['tx']) && !empty($_GET['t'])) {
    $txId  = (int)$_GET['tx'];
    $token = trim($_GET['t']);

    $s = $db->prepare(
        "SELECT plt.*, pl.user_id AS creator_id, pl.one_time, pl.id AS pl_id
         FROM payment_link_txs plt
         JOIN payment_links pl ON pl.id=plt.link_id
         WHERE plt.id=? AND plt.status='pending'"
    );
    $s->execute([$txId]); $tx = $s->fetch();

    if ($tx) {
        // Mark completed
        $db->prepare('UPDATE payment_link_txs SET status=? WHERE id=?')->execute(['completed',$txId]);
        // Credit creator
        $db->prepare('INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)')->execute([$tx['creator_id']]);
        $db->prepare('UPDATE bank_accounts SET balance=balance+? WHERE user_id=?')->execute([$tx['creator_gets'],$tx['creator_id']]);
        // Creator transaction record
        $db->prepare("INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,?,?)")
           ->execute([$tx['creator_id'],'payment_link',$tx['creator_gets'],round($tx['amount']*0.10,2),'completed']);
        // Deactivate if one-time
        if ($tx['one_time']) {
            $db->prepare('UPDATE payment_links SET is_active=0 WHERE id=?')->execute([$tx['pl_id']]);
        }
        // Redirect to success page with payment ID
        header('Location: pay_success.php?pid='.urlencode($tx['payment_id'])); exit;
    }

    header('Location: pay.php?t='.urlencode($token)); exit;
}

header('Location: dashboard.php'); exit;
