<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yoomoney_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'Не авторизован']); exit; }

$db      = getDB();
$uid     = (int)$_SESSION['user_id'];
$isAdmin = !empty($_SESSION['is_admin']);
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

/* КРИТИЧНО для поллинга раз в секунду: сессия PHP по умолчанию блокирует файл
   сессии на всё время запроса. Если не закрыть сессию здесь, все последующие
   запросы от того же браузера (в т.ч. этот же поллинг) будут вставать в очередь
   и ждать, пока не завершится текущий — включая медленный сетевой запрос к
   YooMoney (до 15 сек). Из-за этого баланс выглядит «замёрзшим» после первого
   обновления. Мы уже прочитали всё нужное из $_SESSION выше — закрываем сессию
   на запись, чтобы разблокировать параллельные запросы. */
session_write_close();

/* Минимальный интервал между реальными запросами к YooMoney на одну карту (сек).
   Клиент может опрашивать этот файл хоть каждую секунду — до YooMoney мы
   достучимся не чаще, чем раз в это время, отдавая в остальное время кэш. */
const PLASTIC_BALANCE_TTL = 2;

/* Диагностика: открой в браузере под админом
   plastic_cards_api.php?action=debug_card&card_id=ID
   чтобы увидеть сырое состояние привязки кошелька и результат прямого вызова
   account-info / operation-history (без самого токена в ответе). */
if ($action === 'debug_card' && $isAdmin) {
    $cardId = (int)($_GET['card_id'] ?? 0);
    $s = $db->prepare('SELECT id, name, yoo_wallet, wallet_connected_at, balance_cached, balance_updated_at,
                               (yoo_access_token IS NOT NULL) AS has_token, LENGTH(yoo_access_token) AS token_len
                        FROM plastic_cards WHERE id=?');
    $s->execute([$cardId]);
    $row = $s->fetch();
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена']); exit; }

    $accountInfoResult = null;
    $historyResult     = null;
    $historyFiltered   = null;
    if (!empty($row['has_token'])) {
        $full = $db->prepare('SELECT yoo_access_token FROM plastic_cards WHERE id=?');
        $full->execute([$cardId]);
        $token = $full->fetchColumn();

        $accountInfoResult = ym_accountInfo($token);

        $hist = ym_operationHistory($token, ['records' => 30]);
        $historyResult = $hist;

        if (!empty($hist['operations'])) {
            $sinceTs = $row['wallet_connected_at'] ? strtotime($row['wallet_connected_at']) : 0;
            $kept = [];
            foreach ($hist['operations'] as $op) {
                $opTs = isset($op['datetime']) ? strtotime($op['datetime']) : 0;
                $kept[] = [
                    'title'      => $op['title'] ?? null,
                    'amount'     => $op['amount'] ?? null,
                    'direction'  => $op['direction'] ?? null,
                    'status'     => $op['status'] ?? null,
                    'datetime'   => $op['datetime'] ?? null,
                    'passes_status_success' => (($op['status'] ?? '') === 'success'),
                    'passes_direction_out'  => (($op['direction'] ?? 'out') === 'out'),
                    'passes_after_connect'  => ($opTs && $sinceTs && $opTs >= $sinceTs),
                ];
            }
            $historyFiltered = ['wallet_connected_at' => $row['wallet_connected_at'], 'operations_checked' => $kept];
        }
    }
    echo json_encode([
        'ok' => true,
        'card' => $row,
        'account_info_call' => $accountInfoResult,
        'operation_history_call' => $historyResult,
        'operation_history_filter_debug' => $historyFiltered,
    ]);
    exit;
}

/* Комиссия ЮMoney за пополнение кошелька с банковской карты (по тарифам ЮMoney, не наша) */
const YOOMONEY_CARD_TOPUP_FEE_PERCENT = 3;

if ($action === 'topup_init') {
    $cardId = (int)($_POST['card_id'] ?? 0);
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    if (!$cardId) { echo json_encode(['ok'=>false,'error'=>'card_id не указан']); exit; }
    if ($amount < 2) { echo json_encode(['ok'=>false,'error'=>'Минимальная сумма пополнения — 2 ₽']); exit; }

    if ($isAdmin) {
        $s = $db->prepare('SELECT * FROM plastic_cards WHERE id=?');
        $s->execute([$cardId]);
    } else {
        $s = $db->prepare('SELECT * FROM plastic_cards WHERE id=? AND user_id=?');
        $s->execute([$cardId, $uid]);
    }
    $card = $s->fetch();
    if (!$card) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена']); exit; }
    if (empty($card['yoo_wallet'])) { echo json_encode(['ok'=>false,'error'=>'К карте не привязан кошелёк']); exit; }

    $label      = 'pcard_' . $cardId . '_' . time() . '_' . bin2hex(random_bytes(3));
    $successUrl = 'https://wallet.m1plus.ru/cards.php?pcard=' . $cardId . '&topup=1';

    // Получатель — именно кошелёк, привязанный к этой карте, а не кошелёк сайта
    $params = [
        'receiver'      => $card['yoo_wallet'],
        'quickpay-form' => 'donate',
        'paymentType'   => 'AC',
        'sum'           => number_format($amount, 2, '.', ''),
        'label'         => $label,
        'comment'       => 'Пополнение карты: ' . $card['name'],
        'need-fio'      => 'false',
        'need-email'    => 'false',
        'need-phone'    => 'false',
        'need-address'  => 'false',
        'successURL'    => $successUrl,
    ];

    echo json_encode([
        'ok'  => true,
        'url' => 'https://yoomoney.ru/quickpay/confirm.xml?' . http_build_query($params),
        'fee_percent' => YOOMONEY_CARD_TOPUP_FEE_PERCENT,
    ]);
    exit;
}

if ($action === 'balance') {
    $cardId = (int)($_GET['card_id'] ?? $_POST['card_id'] ?? 0);
    $debug  = !empty($_GET['debug']);
    if (!$cardId) { echo json_encode(['ok'=>false,'error'=>'card_id не указан']); exit; }

    if ($isAdmin) {
        $s = $db->prepare('SELECT * FROM plastic_cards WHERE id=?');
        $s->execute([$cardId]);
    } else {
        $s = $db->prepare('SELECT * FROM plastic_cards WHERE id=? AND user_id=?');
        $s->execute([$cardId, $uid]);
    }
    $card = $s->fetch();
    if (!$card) { echo json_encode(['ok'=>false,'error'=>'Карта не найдена']); exit; }

    if (empty($card['yoo_access_token'])) {
        echo json_encode([
            'ok' => true,
            'connected' => false,
            'balance' => null,
            'is_delivered' => (bool)$card['is_delivered'],
        ]);
        exit;
    }

    $balance      = $card['balance_cached'] !== null ? (float)$card['balance_cached'] : null;
    $lastUpdate   = $card['balance_updated_at'] ? strtotime($card['balance_updated_at']) : 0;
    $needsRefresh = $debug || (time() - $lastUpdate) >= PLASTIC_BALANCE_TTL;
    $fetchError   = null;
    $rawInfo      = null;

    if ($needsRefresh) {
        $info    = ym_accountInfo($card['yoo_access_token']);
        $rawInfo = $info;
        if (isset($info['balance'])) {
            $balance = (float)$info['balance'];
            $db->prepare('UPDATE plastic_cards SET balance_cached=?, balance_updated_at=NOW() WHERE id=?')
               ->execute([$balance, $cardId]);
        } else {
            // Токен мог протухнуть/быть отозван, либо сбой сети/API — не молчим, логируем и отдаём причину
            $tok = (string)$card['yoo_access_token'];
            $tokPreview = strlen($tok) > 10 ? (substr($tok, 0, 6) . '...' . substr($tok, -4)) : $tok;
            $fetchError = ($info['error'] ?? 'unknown_error')
                . (isset($info['http_code']) ? ' | http_code=' . $info['http_code'] : '')
                . (isset($info['raw']) ? ' | raw=' . $info['raw'] : '')
                . ' | token_len=' . strlen($tok) . ' | token_preview=' . $tokPreview;
            error_log('plastic_cards_api balance: card ' . $cardId . ' ym_accountInfo failed: ' . json_encode($info) . ' token_len=' . strlen($tok));
            $db->prepare('UPDATE plastic_cards SET balance_updated_at=NOW() WHERE id=?')->execute([$cardId]);
        }
    }

    $resp = [
        'ok' => true,
        'connected' => true,
        'balance' => $balance,
        'is_delivered' => (bool)$card['is_delivered'],
    ];
    // Пока отлаживаем интеграцию — отдаём ошибку всем, не только админу
    if ($fetchError) $resp['debug_error'] = $fetchError;
    if ($debug) $resp['debug_raw'] = $rawInfo;
    echo json_encode($resp);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
