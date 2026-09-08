<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error'=>'Method']); exit; }

$token     = trim($_POST['token'] ?? '');
$code      = trim($_POST['code'] ?? '');
$username  = trim($_POST['username'] ?? '');
$buyerData = json_decode($_POST['buyer_data'] ?? '{}', true) ?: [];
$db        = getDB();

if (!$code || !$token || !$username) { echo json_encode(['error'=>'Missing fields']); exit; }

// Find payer by username
$payerRow = $db->prepare('SELECT id, username FROM users WHERE username=? AND is_admin=0 LIMIT 1');
$payerRow->execute([$username]);
$payer = $payerRow->fetch();
if (!$payer) { echo json_encode(['error'=>'Username not found']); exit; }
$uid = (int)$payer['id'];

// Verify code
$codeRow = $db->prepare("SELECT * FROM payment_verify_codes WHERE user_id=? AND token=? AND expires_at > NOW()");
$codeRow->execute([$uid, $token]);
$codeData = $codeRow->fetch();

if (!$codeData) { echo json_encode(['error'=>'Code expired or not found. Request a new code.']); exit; }

if ((int)$codeData['attempts'] >= 5) {
    $db->prepare('DELETE FROM payment_verify_codes WHERE id=?')->execute([$codeData['id']]);
    echo json_encode(['error'=>'Too many attempts. Request a new code.']); exit;
}

if (!hash_equals($codeData['code'], $code)) {
    $db->prepare('UPDATE payment_verify_codes SET attempts=attempts+1 WHERE id=?')->execute([$codeData['id']]);
    $left = 4 - (int)$codeData['attempts'];
    echo json_encode(['error'=>'Incorrect code. ' . max(0,$left) . ' attempts left.']); exit;
}

$db->prepare('DELETE FROM payment_verify_codes WHERE id=?')->execute([$codeData['id']]);

// Load payment link
$s = $db->prepare('SELECT pl.*, u.username AS cu FROM payment_links pl JOIN users u ON u.id=pl.user_id WHERE pl.token=? AND pl.is_active=1');
$s->execute([$token]);
$link = $s->fetch();
if (!$link) { echo json_encode(['error'=>'Payment link not found']); exit; }
if ((int)$link['user_id'] === $uid) { echo json_encode(['error'=>'Cannot pay your own link']); exit; }

if ($link['one_time']) {
    $c = $db->prepare("SELECT id FROM payment_link_txs WHERE link_id=? AND status='completed' LIMIT 1");
    $c->execute([$link['id']]);
    if ($c->fetch()) { echo json_encode(['error'=>'This link has already been paid']); exit; }
}

$amount       = (float)$link['amount'];
$creator_gets = round($amount * 0.90, 2);
$creatorId    = (int)$link['user_id'];

$ba = $db->prepare('SELECT balance FROM bank_accounts WHERE user_id=?');
$ba->execute([$uid]);
$row = $ba->fetch();
if ((float)($row['balance'] ?? 0) < $amount) {
    echo json_encode(['error'=>'Insufficient funds on M1plus wallet account']); exit;
}

$paymentId = strtoupper(bin2hex(random_bytes(8)));

$db->prepare('UPDATE bank_accounts SET balance=balance-? WHERE user_id=?')->execute([$amount, $uid]);
$db->prepare('INSERT IGNORE INTO bank_accounts (user_id,balance) VALUES (?,0)')->execute([$creatorId]);
$db->prepare('UPDATE bank_accounts SET balance=balance+? WHERE user_id=?')->execute([$creator_gets, $creatorId]);

$db->prepare('INSERT INTO payment_link_txs (link_id,payment_id,payer_user_id,method,amount,creator_gets,status,buyer_email,buyer_phone,buyer_name) VALUES (?,?,?,?,?,?,?,?,?,?)')
   ->execute([$link['id'],$paymentId,$uid,'bank',$amount,$creator_gets,'completed',
              $buyerData['email']??null,$buyerData['phone']??null,$buyerData['name']??null]);

$db->prepare("INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,?,?)")
   ->execute([$creatorId,'payment_link',$creator_gets,round($amount*0.10,2),'completed']);
$db->prepare("INSERT INTO transactions (user_id,type,amount,commission,status) VALUES (?,?,?,?,?)")
   ->execute([$uid,'payment_sent',$amount,0,'completed']);

if ($link['one_time']) {
    $db->prepare('UPDATE payment_links SET is_active=0 WHERE id=?')->execute([$link['id']]);
}

echo json_encode(['ok'=>1,'payment_id'=>$paymentId,'redirect'=>'pay_success.php?pid='.$paymentId]);
