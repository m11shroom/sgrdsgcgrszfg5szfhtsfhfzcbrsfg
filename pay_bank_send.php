<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error'=>'Method']); exit; }

$inputUsername = trim($_POST['username'] ?? '');
$token         = trim($_POST['token'] ?? '');
$db            = getDB();

if (!$inputUsername || !$token) { echo json_encode(['error'=>'Missing fields']); exit; }

// Find user by username (no session required)
$userRow = $db->prepare('SELECT id, username, email FROM users WHERE username=? AND is_admin=0 LIMIT 1');
$userRow->execute([$inputUsername]);
$user = $userRow->fetch();

if (!$user) { echo json_encode(['error'=>'Username not found']); exit; }

$uid = (int)$user['id'];

// Rate-limit: 1 code per 30 seconds
$rateCheck = $db->prepare("SELECT id FROM payment_verify_codes WHERE user_id=? AND token=? AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)");
$rateCheck->execute([$uid, $token]);
if ($rateCheck->fetch()) { echo json_encode(['error'=>'Please wait 30 seconds before requesting a new code']); exit; }

// Check balance first
$balRow = $db->prepare('SELECT balance FROM bank_accounts WHERE user_id=?');
$balRow->execute([$uid]);
$balData = $balRow->fetch();
// We don't block here — balance checked at payment time

// Clean expired codes
$db->prepare('DELETE FROM payment_verify_codes WHERE expires_at < NOW()')->execute();

$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

$db->prepare(
    'INSERT INTO payment_verify_codes (user_id, token, code, attempts, expires_at)
     VALUES (?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
     ON DUPLICATE KEY UPDATE code=VALUES(code), attempts=0, expires_at=VALUES(expires_at), created_at=NOW()'
)->execute([$uid, $token, $code]);

// ── Send email ────────────────────────────────────────────────────────────────
$to      = $user['email'];
$subject = '=?UTF-8?B?' . base64_encode('M1plus wallet — Payment code: ' . $code) . '?=';
$from    = MAIL_FROM;

$htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#080808">
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:40px 20px">
<table width="460" cellpadding="0" cellspacing="0" style="max-width:460px;width:100%;background:#111;border:1px solid #1e1e1e;border-radius:16px;padding:36px 32px;font-family:monospace;text-align:center">
<tr><td>
<div style="font-size:11px;letter-spacing:6px;color:#363636;margin-bottom:20px;text-transform:uppercase">M1PLUS WALLET</div>
<div style="font-size:11px;letter-spacing:3px;color:#444;margin-bottom:24px;text-transform:uppercase">Payment Verification Code</div>
<div style="font-size:46px;letter-spacing:14px;color:#f0f0f0;padding:22px 16px;background:#080808;border:1px solid #1e1e1e;border-radius:12px;margin:0 0 20px">' . $code . '</div>
<div style="font-size:13px;color:#555;line-height:1.7">Expires in <strong style="color:#888">10 minutes</strong>.<br>Ignore if you did not request this.</div>
<div style="margin-top:28px;padding-top:18px;border-top:1px solid #1a1a1a;font-size:10px;letter-spacing:3px;color:#2a2a2a">wallet.m1plus.ru</div>
</td></tr>
</table>
</td></tr>
</table>
</body></html>';

$headers  = "From: M1plus wallet <{$from}>\r\n";
$headers .= "Reply-To: {$from}\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: base64\r\n";

$result = mail($to, $subject, chunk_split(base64_encode($htmlBody)), $headers, '-f ' . escapeshellarg($from));
error_log("pay_bank_send: to=$to result=" . ($result ? 'OK' : 'FAIL') . " code=$code");

$parts     = explode('@', $to);
$emailHint = substr($parts[0], 0, 2) . str_repeat('*', max(2, strlen($parts[0]) - 2)) . '@' . ($parts[1] ?? '');

echo json_encode(['ok' => 1, 'email_hint' => $emailHint]);
