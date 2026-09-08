<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Not authorized']); exit; }

header('Content-Type: application/json');
try { $db = getDB(); } catch (Exception $e) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Ошибка соединения с БД']); exit; }

$uid = (int)$_SESSION['user_id'];
$user = $db->prepare('SELECT * FROM users WHERE id=?'); $user->execute([$uid]); $user = $user->fetch();
$acc  = $db->prepare('SELECT * FROM bank_accounts WHERE user_id=?'); $acc->execute([$uid]); $acc = $acc->fetch();
$bal  = (float)($acc['balance'] ?? 0);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

function genCode(): string { return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); }

// ─── ВСТРОЕННАЯ SMTP-ОТПРАВКА (без внешних файлов) ───
function bank_smtp_send(string $toEmail, string $subject, string $bodyText): array {
    // Грузим SMTP-константы из db_credentials.php (выше корня) если их нет
    if (!defined('SMTP_HOST')) {
        foreach ([dirname(__DIR__).'/db_credentials.php', dirname(dirname(__DIR__)).'/db_credentials.php'] as $p) {
            if (file_exists($p)) { require_once $p; break; }
        }
    }
    if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
        return ['ok'=>false, 'error'=>'SMTP не настроен в db_credentials.php'];
    }

    $host = SMTP_HOST;
    $port = defined('SMTP_PORT') ? SMTP_PORT : 465;
    $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl';
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    $from = defined('SMTP_FROM') ? SMTP_FROM : $user;
    $fromNm = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'M1plus wallet';

    $remote = ($secure==='ssl'?'ssl://':'').$host.':'.$port;
    $ctx = stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['ok'=>false, 'error'=>"Connect failed: $errstr ($errno)"];
    stream_set_timeout($fp, 20);

    $read = function() use ($fp) { $d=''; while($l=fgets($fp,515)){ $d.=$l; if(isset($l[3])&&$l[3]===' ')break; } return $d; };
    $cmd = function($c) use ($fp){ fputs($fp,$c."\r\n"); };
    $ok = function($r,$c){ return substr(ltrim($r),0,3)===(string)$c; };

    $g=$read(); if(!$ok($g,220)){fclose($fp);return['ok'=>false,'error'=>'No 220: '.trim($g)];}
    $hn = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $cmd('EHLO '.$hn); $e=$read();
    if(!$ok($e,250)){ $cmd('HELO '.$hn); $e=$read(); if(!$ok($e,250)){fclose($fp);return['ok'=>false,'error'=>'EHLO failed'];} }
    $cmd('AUTH LOGIN'); $r=$read(); if(!$ok($r,334)){fclose($fp);return['ok'=>false,'error'=>'AUTH: '.trim($r)];}
    $cmd(base64_encode($user)); $r=$read(); if(!$ok($r,334)){fclose($fp);return['ok'=>false,'error'=>'User rejected'];}
    $cmd(base64_encode($pass)); $r=$read(); if(!$ok($r,235)){fclose($fp);return['ok'=>false,'error'=>'Auth failed (пароль?): '.trim($r)];}
    $cmd('MAIL FROM:<'.$from.'>'); $r=$read(); if(!$ok($r,250)){fclose($fp);return['ok'=>false,'error'=>'MAIL FROM: '.trim($r)];}
    $cmd('RCPT TO:<'.$toEmail.'>'); $r=$read(); if(!$ok($r,250)&&!$ok($r,251)){fclose($fp);return['ok'=>false,'error'=>'RCPT: '.trim($r)];}
    $cmd('DATA'); $r=$read(); if(!$ok($r,354)){fclose($fp);return['ok'=>false,'error'=>'DATA: '.trim($r)];}

    $encSub = '=?UTF-8?B?'.base64_encode($subject).'?=';
    $encNm = '=?UTF-8?B?'.base64_encode($fromNm).'?=';
    $m  = "Date: ".date('r')."\r\n";
    $m .= "From: $encNm <$from>\r\n";
    $m .= "To: <$toEmail>\r\n";
    $m .= "Subject: $encSub\r\n";
    $m .= "MIME-Version: 1.0\r\n";
    $m .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $m .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $m .= chunk_split(base64_encode($bodyText));
    $m .= "\r\n.\r\n";
    fputs($fp, $m);
    $r=$read(); if(!$ok($r,250)){fclose($fp);return['ok'=>false,'error'=>'Rejected: '.trim($r)];}
    $cmd('QUIT'); fclose($fp);
    return ['ok'=>true, 'error'=>''];
}

function sendCode(string $email, string $code, string $purpose): bool {
    if (!$email) return false;
    $subj = $purpose === 'issue'
        ? 'Подтверждение выпуска карты — M1plus wallet'
        : 'Данные карты — код подтверждения — M1plus wallet';
    $act = $purpose === 'issue' ? 'выпуска карты' : 'просмотра данных карты';
    $msg = "Здравствуйте!\n\nКод подтверждения для {$act}:\n\n  {$code}\n\nКод действителен 10 минут.\nЕсли вы не запрашивали — проигнорируйте письмо.\n\n— M1plus wallet";

    $r = bank_smtp_send($email, $subj, $msg);
    if (empty($r['ok'])) {
        $GLOBALS['_mailErr'] = $r['error'] ?? 'unknown';
        error_log('sendCode failed: ' . $GLOBALS['_mailErr']);
    }
    return !empty($r['ok']);
}

// ─── SEND ISSUE CODE ───
if ($action === 'send_issue_code') {
    $tmplId = (int)($body['template_id'] ?? 0);
    if (!$tmplId) { echo json_encode(['ok'=>false,'error'=>'Не выбрана карта']); exit; }
    $tmpl = $db->prepare('SELECT * FROM card_templates WHERE id=? AND is_active=1');
    $tmpl->execute([$tmplId]); $tmpl = $tmpl->fetch();
    if (!$tmpl) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена']); exit; }
    if (isset($tmpl['quantity']) && $tmpl['quantity'] !== null && (int)$tmpl['quantity'] <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Карты данного типа закончились']); exit;
    }
    $prepaid = (float)($tmpl['prepaid_amount'] ?? 0);
    $commission = $prepaid > 0 ? round($prepaid * 0.07, 2) : 0;
    $total = (float)$tmpl['issue_cost'] + $prepaid + $commission;
    if ($total > $bal) { echo json_encode(['ok'=>false,'error'=>'Недостаточно средств']); exit; }
    $code = genCode();
    $db->prepare('INSERT INTO card_codes (user_id, purpose, code, expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))')
       ->execute([$uid, 'issue', $code]);
    if (!sendCode($user['email'], $code, 'issue')) {
        echo json_encode(['ok'=>false,'error'=>'Код не отправлен: ' . ($GLOBALS['_mailErr'] ?? '')]); exit;
    }
    echo json_encode(['ok'=>true, 'email'=>$user['email']]);
    exit;
}

// ─── CONFIRM ISSUE ───
if ($action === 'confirm_issue') {
    $tmplId = (int)($body['template_id'] ?? 0);
    $code = trim($body['code'] ?? '');
    $cq = $db->prepare('SELECT * FROM card_codes WHERE user_id=? AND purpose=? AND code=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1');
    $cq->execute([$uid, 'issue', $code]); $cq = $cq->fetch();
    if (!$cq) { echo json_encode(['ok'=>false,'error'=>'Неверный или истёкший код']); exit; }
    $tmpl = $db->prepare('SELECT * FROM card_templates WHERE id=? AND is_active=1');
    $tmpl->execute([$tmplId]); $tmpl = $tmpl->fetch();
    if (!$tmpl) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена']); exit; }
    if (isset($tmpl['quantity']) && $tmpl['quantity'] !== null && (int)$tmpl['quantity'] <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Карты данного типа закончились']); exit;
    }
    $issueCost = (float)$tmpl['issue_cost'];
    $prepaid = (float)($tmpl['prepaid_amount'] ?? 0);
    $commission = $prepaid > 0 ? round($prepaid * 0.07, 2) : 0;
    $total = $issueCost + $prepaid + $commission;
    $fb = $db->prepare('SELECT balance FROM bank_accounts WHERE user_id=?');
    $fb->execute([$uid]); $freshBal = (float)($fb->fetchColumn() ?: 0);
    if ($total > $freshBal) { echo json_encode(['ok'=>false,'error'=>'Недостаточно средств']); exit; }
    if ($total > 0) {
        $db->prepare('UPDATE bank_accounts SET balance=balance-? WHERE user_id=?')->execute([$total, $uid]);
    }
    $db->prepare('UPDATE card_codes SET used=1 WHERE id=?')->execute([$cq['id']]);
    if (isset($tmpl['quantity']) && $tmpl['quantity'] !== null) {
        $db->prepare('UPDATE card_templates SET quantity=quantity-1 WHERE id=?')->execute([$tmplId]);
    }
    try {
        $db->prepare("INSERT INTO issued_cards (user_id, template_id, cover_image, status, balance_type, issue_type, wait_days, currency, prepaid_amount, requested_amount, commission_amount, issue_cost_paid) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$uid, $tmplId, $tmpl['cover_image'], 'pending', $tmpl['balance_type'], $tmpl['issue_type'], $tmpl['wait_days'] ?: null, $tmpl['currency'] ?? 'RUB', $prepaid > 0 ? $prepaid : null, $prepaid > 0 ? $prepaid : null, $commission, $issueCost]);
    } catch (Exception $e) {
        $db->prepare("INSERT INTO issued_cards (user_id, template_id, cover_image, status, balance_type, issue_type, wait_days, prepaid_amount, requested_amount, issue_cost_paid) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$uid, $tmplId, $tmpl['cover_image'], 'pending', $tmpl['balance_type'], $tmpl['issue_type'], $tmpl['wait_days'] ?: null, $prepaid > 0 ? $prepaid : null, $prepaid > 0 ? $prepaid : null, $issueCost]);
    }
    $cardId = (int)$db->lastInsertId();
    echo json_encode(['ok'=>true, 'card_id'=>$cardId]);
    exit;
}

// ─── SEND REVEAL CODE ───
if ($action === 'send_reveal_code') {
    $cardId = (int)($body['card_id'] ?? 0);
    $cq = $db->prepare('SELECT * FROM issued_cards WHERE id=? AND user_id=? AND status=?');
    $cq->execute([$cardId, $uid, 'active']); $cq = $cq->fetch();
    if (!$cq || !$cq['card_number']) { echo json_encode(['ok'=>false,'error'=>'Нет данных']); exit; }
    $code = genCode();
    $db->prepare('INSERT INTO card_codes (user_id, card_id, purpose, code, expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))')
       ->execute([$uid, $cardId, 'reveal', $code]);
    if (!sendCode($user['email'], $code, 'reveal')) {
        echo json_encode(['ok'=>false,'error'=>'Код не отправлен: ' . ($GLOBALS['_mailErr'] ?? '')]); exit;
    }
    echo json_encode(['ok'=>true]);
    exit;
}

// ─── REVEAL CARD DATA ───
if ($action === 'reveal_card') {
    $cardId = (int)($body['card_id'] ?? 0);
    $code = trim($body['code'] ?? '');
    $cq = $db->prepare('SELECT * FROM card_codes WHERE user_id=? AND card_id=? AND purpose=? AND code=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1');
    $cq->execute([$uid, $cardId, 'reveal', $code]); $cq = $cq->fetch();
    if (!$cq) { echo json_encode(['ok'=>false,'error'=>'Неверный или истёкший код']); exit; }
    $card = $db->prepare('SELECT * FROM issued_cards WHERE id=? AND user_id=?');
    $card->execute([$cardId, $uid]); $card = $card->fetch();
    if (!$card) { echo json_encode(['ok'=>false,'error'=>'Не найдено']); exit; }
    $db->prepare('UPDATE card_codes SET used=1 WHERE id=?')->execute([$cq['id']]);
    $num = preg_replace('/\D/','',$card['card_number']??'');
    $formatted = implode(' ', str_split($num, 4));
    echo json_encode(['ok'=>true, 'card_number'=>$formatted, 'expiry'=>$card['expiry'] ?? '', 'cvv'=>$card['cvv'] ?? '']);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);