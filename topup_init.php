<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yoomoney_lib.php';

header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { echo json_encode(['error'=>'Не авторизован']); exit; }

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$amount = round((float)($_POST['amount'] ?? 0), 2);
if ($amount < 200) { echo json_encode(['error'=>'Минимальная сумма 200 ₽']); exit; }

$cfg = ym_getConfig($db);
if (!$cfg || empty($cfg['access_token']) || empty($cfg['wallet'])) {
    echo json_encode(['error'=>'Пополнение временно недоступно']); exit;
}

$label = 'tp_' . $uid . '_' . time() . '_' . bin2hex(random_bytes(3));
$creditAmount = round($amount * 0.95, 2); // комиссия 5%

$db->prepare("INSERT INTO topups (user_id, label, amount, credit_amount) VALUES (?,?,?,?)")
   ->execute([$uid, $label, $amount, $creditAmount]);

echo json_encode(['url' => 'topup_wait.php?label=' . urlencode($label)]);
