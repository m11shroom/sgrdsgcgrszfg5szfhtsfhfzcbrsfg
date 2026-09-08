<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])||empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}
try { $db = getDB(); } catch (Exception $e) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Ошибка соединения с БД']); exit;
}
header('Content-Type: application/json');
$action = $_POST['action'] ?? '';

if ($action === 'add_template') {
    $name      = trim($_POST['name'] ?? '');
    $cost      = max(0, (float)($_POST['issue_cost'] ?? 0));
    $itype     = in_array($_POST['issue_type']??'', ['instant','wait']) ? $_POST['issue_type'] : 'instant';
    $wdays     = $itype === 'wait' ? max(1, (int)($_POST['wait_days'] ?? 7)) : null;
    $btype     = in_array($_POST['balance_type']??'', ['zero','prepaid']) ? $_POST['balance_type'] : 'zero';
    $currency  = in_array(strtoupper($_POST['currency']??''), ['RUB','EUR','USD']) ? strtoupper($_POST['currency']) : 'RUB';
    $prepaidAmt= $btype === 'prepaid' ? max(0, (float)($_POST['prepaid_amount'] ?? 0)) : 0;
    $quantity  = isset($_POST['quantity']) && $_POST['quantity'] !== '' ? max(1, (int)$_POST['quantity']) : null;
    $cover     = null;

    if (!$name) { echo json_encode(['ok'=>false,'error'=>'Укажите название']); exit; }

    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'])) {
            $fname = uniqid('card_') . '.' . $ext;
            $dest  = __DIR__ . '/uploads/' . $fname;
            if (!is_dir(__DIR__.'/uploads')) mkdir(__DIR__.'/uploads', 0755, true);
            if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $dest)) $cover = $fname;
        }
    }

    try {
        $db->prepare('INSERT INTO card_templates (name,cover_image,issue_cost,issue_type,wait_days,balance_type,currency,prepaid_amount,quantity) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$name, $cover, $cost, $itype, $wdays, $btype, $currency, $prepaidAmt ?: null, $quantity]);
    } catch (Exception $e) {
        // Fallback for old schema
        $db->prepare('INSERT INTO card_templates (name,cover_image,issue_cost,issue_type,wait_days,balance_type) VALUES (?,?,?,?,?,?)')
           ->execute([$name, $cover, $cost, $itype, $wdays, $btype]);
    }
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'toggle_template') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }
    $db->prepare('UPDATE card_templates SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'fill_card') {
    $cardId  = (int)($_POST['card_id'] ?? 0);
    $num     = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
    $exp     = trim($_POST['expiry'] ?? '');
    $cvv     = trim($_POST['cvv'] ?? '');
    $prepaid = isset($_POST['prepaid_amount']) ? max(0, (float)$_POST['prepaid_amount']) : null;
    $purpose = mb_substr(trim($_POST['purpose'] ?? ''), 0, 255);

    if (!$cardId)          { echo json_encode(['ok'=>false,'error'=>'ID карты не указан']); exit; }
    if (strlen($num) < 13) { echo json_encode(['ok'=>false,'error'=>'Неверный номер карты']); exit; }
    if (!preg_match('/^\d{2}\/\d{2}$/', $exp)) { echo json_encode(['ok'=>false,'error'=>'Формат срока: MM/YY']); exit; }
    if (strlen($cvv) < 3)  { echo json_encode(['ok'=>false,'error'=>'CVV должен быть 3 цифры']); exit; }

    try {
        $db->prepare("UPDATE issued_cards SET status='active', card_number=?, expiry=?, cvv=?, purpose=?, prepaid_amount=COALESCE(?,prepaid_amount), activated_at=NOW() WHERE id=?")
           ->execute([$num, $exp, $cvv, $purpose ?: null, $prepaid, $cardId]);
    } catch (Exception $e) {
        $db->prepare("UPDATE issued_cards SET status='active', card_number=?, expiry=?, cvv=?, prepaid_amount=COALESCE(?,prepaid_amount), activated_at=NOW() WHERE id=?")
           ->execute([$num, $exp, $cvv, $prepaid, $cardId]);
    }
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'topup_card') {
    $num  = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
    $amt  = max(0, (float)($_POST['amount'] ?? 0));
    $note = trim($_POST['note'] ?? '');

    if (strlen($num) < 4) { echo json_encode(['ok'=>false,'error'=>'Укажите номер карты']); exit; }
    if ($amt <= 0)         { echo json_encode(['ok'=>false,'error'=>'Укажите сумму']); exit; }

    $card = $db->prepare("SELECT id FROM issued_cards WHERE REPLACE(REPLACE(card_number,' ',''),'-','') LIKE ? AND status='active' LIMIT 1");
    $card->execute(['%'.$num]);
    $card = $card->fetch();
    if (!$card) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена или не активна']); exit; }

    $db->prepare('INSERT INTO card_topups (card_id, amount, note) VALUES (?,?,?)')->execute([$card['id'], $amt, $note ?: null]);
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
