<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yoomoney_lib.php';

header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'Не авторизован']); exit; }

$db    = getDB();
$uid   = (int)$_SESSION['user_id'];
$label = $_GET['label'] ?? '';
if (!$label) { echo json_encode(['ok'=>false,'error'=>'No label']); exit; }

$st = $db->prepare("SELECT * FROM topups WHERE label=? AND user_id=?");
$st->execute([$label, $uid]);
$topup = $st->fetch();
if (!$topup) { echo json_encode(['ok'=>false,'error'=>'Заявка не найдена']); exit; }

/* Уже зачислено ранее */
if ((int)$topup['credited'] === 1) {
    echo json_encode(['ok'=>true,'paid'=>true,'credited'=>(float)$topup['credit_amount']]);
    exit;
}

$cfg = ym_getConfig($db);
if (!$cfg || empty($cfg['access_token'])) { echo json_encode(['ok'=>false,'error'=>'Кошелёк не подключён']); exit; }

/* Спрашиваем YooMoney: есть ли успешная входящая операция с этой меткой */
$op = ym_findPaidOperation($cfg['access_token'], $label);

if (!$op) { echo json_encode(['ok'=>true,'paid'=>false]); exit; }

/* Оплата найдена — зачисляем идемпотентно */
try {
    $db->beginTransaction();

    // Помечаем credited=1 только если ещё 0 — защита от двойного зачисления при гонке запросов
    $upd = $db->prepare("UPDATE topups SET credited=1, operation_id=?, credited_at=CURRENT_TIMESTAMP WHERE id=? AND credited=0");
    $upd->execute([(string)($op['operation_id'] ?? ''), (int)$topup['id']]);

    if ($upd->rowCount() === 1) {
        $db->prepare("INSERT IGNORE INTO bank_accounts (user_id) VALUES (?)")->execute([$uid]);
        $db->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE user_id=?")
           ->execute([(float)$topup['credit_amount'], $uid]);
        $db->prepare("INSERT INTO transactions (user_id, type, amount, status, created_at) VALUES (?,?,?,'completed',CURRENT_TIMESTAMP)")
           ->execute([$uid, 'topup', (float)$topup['credit_amount']]);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['ok'=>false,'error'=>'Ошибка зачисления']);
    exit;
}

echo json_encode(['ok'=>true,'paid'=>true,'credited'=>(float)$topup['credit_amount']]);
