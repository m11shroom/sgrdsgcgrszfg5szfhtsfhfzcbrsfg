<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$db = getDB();
$uid = (int)$_SESSION['user_id'];

$stmt = $db->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
$stmt->execute([$uid]);
$txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Форматируем для удобства на клиенте
foreach ($txs as &$tx) {
    $tx['amount'] = (float)$tx['amount'];
    $tx['commission'] = (float)($tx['commission'] ?? 0);
    $tx['created_at_formatted'] = date('d.m H:i', strtotime($tx['created_at']));
    $tx['type_label'] = match($tx['type']) {
        'card'         => 'На карту',
        'sbp'          => 'СБП — ' . ($tx['bank_name'] ?? ''),
        'user_out'     => 'Перевод пользователю',
        'user_in'      => 'Входящий перевод',
        'payment_link' => 'Платёжная ссылка',
        'payment_sent' => 'Оплата ссылки',
        default        => $tx['type']
    };
    $tx['is_incoming'] = in_array($tx['type'], ['user_in', 'payment_link']);
    $tx['detail'] = match($tx['type']) {
        'card' => '•••• ' . substr($tx['card_number'] ?? '', -4),
        'sbp'  => $tx['phone'] ?? '',
        default => ''
    };
    // Чек ID (как в основном коде)
    $hash = substr(hash_hmac('sha256', $tx['id'] . $tx['created_at'] . $tx['amount'], 'M1plusWalletCheck'), 0, 8);
    $tx['check_id'] = 'CHK-' . $tx['id'] . '-' . $hash;
}
unset($tx);

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'transactions' => $txs]);