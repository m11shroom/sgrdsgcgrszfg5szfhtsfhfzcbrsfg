<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/contracts_setup.php';

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Ошибка сервера: '.$e->getMessage()]);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['ok'=>false,'error'=>'Fatal: '.$err['message']]);
    }
});

$db     = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?: [];

/**
 * Ленивая проверка просрочки: если время вышло, а договор не подписан —
 * полностью удаляем запись (и подписи), возвращаем true если удалили.
 */
function contract_expire_if_needed(PDO $db, array $contract): bool {
    if ($contract['status'] === 'signed') return false;
    if (!$contract['expires_at']) return false;
    if (new DateTime($contract['expires_at']) >= new DateTime()) return false;

    $db->prepare('DELETE FROM contract_signatures WHERE contract_id=?')->execute([$contract['id']]);
    $db->prepare('DELETE FROM contracts WHERE id=?')->execute([$contract['id']]);
    return true;
}

/* ─── Открыть договор по коду (первое открытие запускает таймер 12 минут) ── */
if ($action === 'open_by_code') {
    $code = trim($body['code'] ?? $_GET['code'] ?? '');
    if (!$code) { echo json_encode(['ok'=>false,'error'=>'Введите код']); exit; }

    $c = $db->prepare('SELECT * FROM contracts WHERE code=?');
    $c->execute([$code]);
    $contract = $c->fetch();
    if (!$contract) { echo json_encode(['ok'=>false,'error'=>'Договор не найден']); exit; }

    if (contract_expire_if_needed($db, $contract)) {
        echo json_encode(['ok'=>false,'error'=>'expired','message'=>'Договор устарел']); exit;
    }

    if ($contract['status'] === 'signed') {
        echo json_encode(['ok'=>false,'error'=>'already_signed','message'=>'Договор уже подписан']); exit;
    }

    if ($contract['status'] === 'pending') {
        $expiresAt = (new DateTime())->modify('+12 minutes')->format('Y-m-d H:i:s');
        $db->prepare("UPDATE contracts SET status='opened', opened_at=NOW(), expires_at=? WHERE id=?")
           ->execute([$expiresAt, $contract['id']]);
        $contract['status'] = 'opened';
        $contract['expires_at'] = $expiresAt;
    }

    // Текущая дата для авто-заполнения зоны даты: ДД.ММ.ГГ (год двумя цифрами)
    $todayShort = date('d.m.y');

    echo json_encode(['ok'=>true, 'contract'=>[
        'id'              => $contract['id'],
        'title'           => $contract['title'],
        'page_count'      => (int)$contract['page_count'],
        'signature_zones' => json_decode($contract['signature_zones'], true),
        'date_zone'       => $contract['date_zone'] ? json_decode($contract['date_zone'], true) : null,
        'original_pdf'    => $contract['original_pdf'],
        'expires_at'      => $contract['expires_at'],
        'today_short'     => $todayShort,
    ]]); exit;
}

/* ─── Проверка статуса / оставшегося времени (для клиентского таймера) ────── */
if ($action === 'check_status') {
    $id = (int)($_GET['contract_id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }

    $c = $db->prepare('SELECT * FROM contracts WHERE id=?');
    $c->execute([$id]);
    $contract = $c->fetch();
    if (!$contract) { echo json_encode(['ok'=>true,'exists'=>false]); exit; }

    if (contract_expire_if_needed($db, $contract)) {
        echo json_encode(['ok'=>true,'exists'=>false,'expired'=>true]); exit;
    }

    $secondsLeft = $contract['expires_at'] ? (strtotime($contract['expires_at']) - time()) : null;
    echo json_encode(['ok'=>true, 'exists'=>true, 'status'=>$contract['status'], 'seconds_left'=>$secondsLeft]); exit;
}

/* ─── Принудительное истечение (клиент вызывает, когда таймер дошёл до 0) ── */
if ($action === 'expire_now') {
    $id = (int)($body['contract_id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID не указан']); exit; }

    $c = $db->prepare('SELECT * FROM contracts WHERE id=?');
    $c->execute([$id]);
    $contract = $c->fetch();
    if (!$contract) { echo json_encode(['ok'=>true]); exit; }
    if ($contract['status'] === 'signed') { echo json_encode(['ok'=>true]); exit; }

    $db->prepare('DELETE FROM contract_signatures WHERE contract_id=?')->execute([$id]);
    $db->prepare('DELETE FROM contracts WHERE id=?')->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

/* ─── Отправка подписи(ей) + готового подписанного PDF ────────────────────── */
if ($action === 'submit_signature') {
    $id           = (int)($body['contract_id'] ?? 0);
    $signatures   = $body['signatures'] ?? []; // [{zone_index, stroke_data, signature_png}, ...]
    $signedPdfB64 = $body['signed_pdf'] ?? '';

    if (!$id || !$signedPdfB64 || !is_array($signatures) || count($signatures) < 1) {
        echo json_encode(['ok'=>false,'error'=>'Недостаточно данных для подписания']); exit;
    }

    $c = $db->prepare('SELECT * FROM contracts WHERE id=?');
    $c->execute([$id]);
    $contract = $c->fetch();
    if (!$contract) { echo json_encode(['ok'=>false,'error'=>'Договор не найден']); exit; }

    if (contract_expire_if_needed($db, $contract)) {
        echo json_encode(['ok'=>false,'error'=>'expired','message'=>'Договор устарел, время на подписание истекло']); exit;
    }
    if ($contract['status'] === 'signed') {
        echo json_encode(['ok'=>false,'error'=>'already_signed','message'=>'Договор уже подписан']); exit;
    }

    $pdfBytes = base64_decode($signedPdfB64, true);
    if ($pdfBytes === false) { echo json_encode(['ok'=>false,'error'=>'Некорректный файл подписанного договора']); exit; }
    $signedHash = hash('sha256', $pdfBytes);

    foreach ($signatures as $s) {
        $zoneIndex = (int)($s['zone_index'] ?? -1);
        $strokeData = $s['stroke_data'] ?? null;
        $sigPng = $s['signature_png'] ?? '';
        if ($zoneIndex < 0 || !$strokeData || !$sigPng) continue;

        $db->prepare('INSERT INTO contract_signatures (contract_id, zone_index, stroke_data, signature_png) VALUES (?,?,?,?)')
           ->execute([$id, $zoneIndex, json_encode($strokeData), $sigPng]);
    }

    $db->prepare("UPDATE contracts SET status='signed', signed_at=NOW(), signed_pdf=?, signed_hash=? WHERE id=?")
       ->execute([$signedPdfB64, $signedHash, $id]);

    echo json_encode(['ok'=>true, 'signed_hash'=>$signedHash]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
