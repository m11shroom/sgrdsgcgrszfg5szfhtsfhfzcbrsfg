<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/smtp_mailer_bank.php';
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

function sendCode(string $email, string $code, string $purpose): bool {
    if (!$email) { error_log('sendCode: empty email'); return false; }

    $subj = $purpose === 'issue'
        ? 'Подтверждение выпуска карты — M1plus wallet'
        : 'Данные карты — код подтверждения — M1plus wallet';

    $actionTxt = $purpose === 'issue' ? 'выпуска карты' : 'просмотра данных карты';
    $msg  = "Здравствуйте!\n\n";
    $msg .= "Код подтверждения для {$actionTxt}:\n\n";
    $msg .= "  {$code}\n\n";
    $msg .= "Код действителен 10 минут.\n";
    $msg .= "Если вы не запрашивали этот код — проигнорируйте письмо.\n\n";
    $msg .= "— M1plus wallet";

    // Отправка через SMTP (banknoreply@m1plus.ru)
    if (function_exists('wm_smtp_send')) {
        $r = wm_smtp_send($email, $subj, $msg);
        if (empty($r['ok'])) {
            error_log('sendCode SMTP failed: ' . ($r['error'] ?? 'unknown'));
            $GLOBALS['_lastMailError'] = $r['error'] ?? 'unknown';
        }
        return !empty($r['ok']);
    }

    // SMTP-функция не найдена
    error_log('sendCode: wm_smtp_send not defined — smtp_mailer_bank.php not loaded');
    $GLOBALS['_lastMailError'] = 'wm_smtp_send not defined (smtp_mailer_bank.php not loaded or SMTP constants missing)';
    return false;
}

// ─── SEND ISSUE CODE ───────────────────────────────────────────────────────
if ($action === 'send_issue_code') {
    $tmplId = (int)($body['template_id'] ?? 0);
    if (!$tmplId) { echo json_encode(['ok'=>false,'error'=>'Не выбрана карта']); exit; }

    $tmpl = $db->prepare('SELECT * FROM card_templates WHERE id=? AND is_active=1');
    $tmpl->execute([$tmplId]); $tmpl = $tmpl->fetch();
    if (!$tmpl) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена']); exit; }

    // Check quantity
    if (isset($tmpl['quantity']) && $tmpl['quantity'] !== null && (int)$tmpl['quantity'] <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Карты данного типа закончились']); exit;
    }

    $prepaid    = (float)($tmpl['prepaid_amount'] ?? 0);
    $commission = $prepaid > 0 ? round($prepaid * 0.07, 2) : 0;
    $total      = (float)$tmpl['issue_cost'] + $prepaid + $commission;
    if ($total > $bal) { echo json_encode(['ok'=>false,'error'=>'Недостаточно средств']); exit; }

    $code = genCode();
    $db->prepare('INSERT INTO card_codes (user_id, purpose, code, expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))')
       ->execute([$uid, 'issue', $code]);

    $sent = sendCode($user['email'], $code, 'issue');
    if (!$sent) {
        $detail = $GLOBALS['_lastMailError'] ?? '';
        echo json_encode(['ok'=>false,'error'=>'Не удалось отправить код. ' . $detail]); exit;
    }
    echo json_encode(['ok'=>true, 'email'=>$user['email']]);
    exit;
}

// ─── CONFIRM ISSUE ─────────────────────────────────────────────────────────
if ($action === 'confirm_issue') {
    $tmplId = (int)($body['template_id'] ?? 0);
    $code   = trim($body['code'] ?? '');

    $cq = $db->prepare('SELECT * FROM card_codes WHERE user_id=? AND purpose=? AND code=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1');
    $cq->execute([$uid, 'issue', $code]); $cq = $cq->fetch();
    if (!$cq) { echo json_encode(['ok'=>false,'error'=>'Неверный или истёкший код']); exit; }

    $tmpl = $db->prepare('SELECT * FROM card_templates WHERE id=? AND is_active=1');
    $tmpl->execute([$tmplId]); $tmpl = $tmpl->fetch();
    if (!$tmpl) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена']); exit; }

    // Check quantity again
    if (isset($tmpl['quantity']) && $tmpl['quantity'] !== null && (int)$tmpl['quantity'] <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Карты данного типа закончились']); exit;
    }

    $issueCost  = (float)$tmpl['issue_cost'];
    $prepaid    = (float)($tmpl['prepaid_amount'] ?? 0);
    $commission = $prepaid > 0 ? round($prepaid * 0.07, 2) : 0;
    $total      = $issueCost + $prepaid + $commission;

    // Fresh balance check
    $fb = $db->prepare('SELECT balance FROM bank_accounts WHERE user_id=?');
    $fb->execute([$uid]); $freshBal = (float)($fb->fetchColumn() ?: 0);
    if ($total > $freshBal) { echo json_encode(['ok'=>false,'error'=>'Недостаточно средств']); exit; }

    if ($total > 0) {
        $db->prepare('UPDATE bank_accounts SET balance=balance-? WHERE user_id=?')->execute([$total, $uid]);
    }

    $db->prepare('UPDATE card_codes SET used=1 WHERE id=?')->execute([$cq['id']]);

    // Decrement quantity if set
    if (isset($tmpl['quantity']) && $tmpl['quantity'] !== null) {
        $db->prepare('UPDATE card_templates SET quantity=quantity-1 WHERE id=?')->execute([$tmplId]);
    }

    try {
        $db->prepare("INSERT INTO issued_cards
            (user_id, template_id, cover_image, status, balance_type, issue_type, wait_days,
             currency, prepaid_amount, requested_amount, commission_amount, issue_cost_paid)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $uid, $tmplId, $tmpl['cover_image'], 'pending',
               $tmpl['balance_type'], $tmpl['issue_type'], $tmpl['wait_days'] ?: null,
               $tmpl['currency'] ?? 'RUB',
               $prepaid > 0 ? $prepaid : null,
               $prepaid > 0 ? $prepaid : null,
               $commission,
               $issueCost
           ]);
    } catch (Exception $e) {
        // Fallback for old schema without new columns
        $db->prepare("INSERT INTO issued_cards
            (user_id, template_id, cover_image, status, balance_type, issue_type, wait_days,
             prepaid_amount, requested_amount, issue_cost_paid)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $uid, $tmplId, $tmpl['cover_image'], 'pending',
               $tmpl['balance_type'], $tmpl['issue_type'], $tmpl['wait_days'] ?: null,
               $prepaid > 0 ? $prepaid : null,
               $prepaid > 0 ? $prepaid : null,
               $issueCost
           ]);
    }

    $cardId = (int)$db->lastInsertId();

    // Письмо: карта успешно оформлена
    if (!empty($user['email']) && function_exists('wm_smtp_send')) {
        $cardName = $tmpl['name'] ?? 'карта';
        $waitInfo = ($tmpl['issue_type'] ?? '') === 'instant'
            ? "Данные карты будут доступны в ближайшее время."
            : "Срок ожидания данных: до " . (int)($tmpl['wait_days'] ?? 0) . " дней.";
        $msg  = "Здравствуйте!\n\n";
        $msg .= "Ваша заявка на выпуск карты \"{$cardName}\" принята.\n\n";
        $msg .= "Списано: " . number_format($total, 2, '.', ' ') . " ₽\n";
        $msg .= $waitInfo . "\n\n";
        $msg .= "Статус карты можно отслеживать в личном кабинете.\n\n";
        $msg .= "— M1plus wallet";
        try { wm_smtp_send($user['email'], 'Карта оформлена — M1plus wallet', $msg); } catch (\Throwable $e) {}
    }

    echo json_encode(['ok'=>true, 'card_id'=>$cardId]);
    exit;
}

// ─── SEND REVEAL CODE ──────────────────────────────────────────────────────
if ($action === 'send_reveal_code') {
    $cardId = (int)($body['card_id'] ?? 0);
    $cq = $db->prepare('SELECT * FROM issued_cards WHERE id=? AND user_id=? AND status=?');
    $cq->execute([$cardId, $uid, 'active']); $cq = $cq->fetch();
    if (!$cq || !$cq['card_number']) { echo json_encode(['ok'=>false,'error'=>'Нет данных']); exit; }

    $code = genCode();
    $db->prepare('INSERT INTO card_codes (user_id, card_id, purpose, code, expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))')
       ->execute([$uid, $cardId, 'reveal', $code]);

    $sent = sendCode($user['email'], $code, 'reveal');
    if (!$sent) {
        $detail = $GLOBALS['_lastMailError'] ?? '';
        echo json_encode(['ok'=>false,'error'=>'Не удалось отправить код. ' . $detail]); exit;
    }
    echo json_encode(['ok'=>true]);
    exit;
}

// ─── REVEAL CARD DATA ──────────────────────────────────────────────────────
if ($action === 'reveal_card') {
    $cardId = (int)($body['card_id'] ?? 0);
    $code   = trim($body['code'] ?? '');

    $cq = $db->prepare('SELECT * FROM card_codes WHERE user_id=? AND card_id=? AND purpose=? AND code=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1');
    $cq->execute([$uid, $cardId, 'reveal', $code]); $cq = $cq->fetch();
    if (!$cq) { echo json_encode(['ok'=>false,'error'=>'Неверный или истёкший код']); exit; }

    $card = $db->prepare('SELECT * FROM issued_cards WHERE id=? AND user_id=?');
    $card->execute([$cardId, $uid]); $card = $card->fetch();
    if (!$card) { echo json_encode(['ok'=>false,'error'=>'Не найдено']); exit; }

    $db->prepare('UPDATE card_codes SET used=1 WHERE id=?')->execute([$cq['id']]);

    $num = preg_replace('/\D/','',$card['card_number']??'');
    $formatted = implode(' ', str_split($num, 4));

    echo json_encode([
        'ok'          => true,
        'card_number' => $formatted,
        'expiry'      => $card['expiry'] ?? '',
        'cvv'         => $card['cvv'] ?? '',
    ]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
