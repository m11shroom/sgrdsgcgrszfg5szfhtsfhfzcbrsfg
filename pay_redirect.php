<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$txId  = (int)($_GET['tx'] ?? 0);
$token = trim($_GET['t'] ?? '');

if ($txId) {
    $db = getDB();
    completeLinkPayment($db, $txId, 'redirect');
}

$_SESSION['flash'] = 'ok:Оплата прошла успешно! Спасибо.';
header('Location: pay.php?t=' . urlencode($token));
exit;

function completeLinkPayment(PDO $db, int $txId, string $source): void {
    $s = $db->prepare('SELECT plt.*, pl.user_id AS creator_id FROM payment_link_txs plt JOIN payment_links pl ON pl.id=plt.link_id WHERE plt.id=? AND plt.status=?');
    $s->execute([$txId, 'pending']);
    $tx = $s->fetch();
    if (!$tx) return; // Already processed

    // Mark completed
    $db->prepare('UPDATE payment_link_txs SET status=? WHERE id=?')->execute(['completed', $txId]);

    // Credit 90% to creator
    $db->prepare('INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)')->execute([$tx['creator_id']]);
    $db->prepare('UPDATE bank_accounts SET balance=balance+? WHERE user_id=?')->execute([$tx['creator_gets'], $tx['creator_id']]);

    // Add to creator's transactions list
    $db->prepare("INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,?,?)")
       ->execute([$tx['creator_id'], 'payment_link', $tx['creator_gets'], round($tx['amount']*0.10,2), 'completed']);
}
