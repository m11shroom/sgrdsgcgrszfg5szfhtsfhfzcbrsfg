<?php
declare(strict_types=1);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');
error_reporting(E_ALL);
require_once __DIR__ . '/db.php';
session_start(); // сессия нужна только для того, чтобы сохранять данные, но не для авторизации
header('Content-Type: application/json');
$db = getDB();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_contract_by_code': handleGetContractByCode($db); break;
        case 'sign_contract': handleSignContract($db); break;
        default: echo json_encode(['ok' => false, 'error' => 'Неизвестное действие']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

function handleGetContractByCode(PDO $db) {
    $code = trim($_GET['code'] ?? '');
    if (!$code) throw new Exception('Не указан код');
    $stmt = $db->prepare("SELECT id, code, title, original_pdf, signature_zones, date_zone, status, signed_at, opened_at, expires_at FROM contracts WHERE code = ?");
    $stmt->execute([$code]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) throw new Exception('Договор не найден');

    if ($contract['status'] === 'signed') {
        $stmtSig = $db->prepare("SELECT zone_index, stroke_data FROM contract_signatures WHERE contract_id = ? ORDER BY zone_index");
        $stmtSig->execute([$contract['id']]);
        $signatures = $stmtSig->fetchAll(PDO::FETCH_ASSOC);
        foreach ($signatures as &$sig) {
            $sig['stroke_data'] = json_decode($sig['stroke_data'], true);
        }
        echo json_encode([
            'ok' => true,
            'contract' => [
                'id' => $contract['id'],
                'code' => $contract['code'],
                'title' => $contract['title'],
                'status' => 'signed',
                'signed_at' => $contract['signed_at'],
                'pdf_url' => 'get_pdf.php?file=' . urlencode($contract['original_pdf']),
                'signatures' => $signatures,
                'date_zone' => json_decode($contract['date_zone'], true),
                'signature_zones' => json_decode($contract['signature_zones'], true),
            ]
        ]);
        return;
    }

    if ($contract['status'] === 'opened') {
        $expires = strtotime($contract['expires_at']);
        if (time() > $expires) {
            $db->prepare("DELETE FROM contract_signatures WHERE contract_id = ?")->execute([$contract['id']]);
            $db->prepare("DELETE FROM contracts WHERE id = ?")->execute([$contract['id']]);
            $filePath = dirname(__DIR__) . '/pdfuploads/' . $contract['original_pdf'];
            if (file_exists($filePath)) unlink($filePath);
            throw new Exception('Время на подписание истекло. Договор удалён.');
        }
        echo json_encode([
            'ok' => true,
            'contract' => [
                'id' => $contract['id'],
                'code' => $contract['code'],
                'title' => $contract['title'],
                'status' => 'opened',
                'pdf_url' => 'get_pdf.php?file=' . urlencode($contract['original_pdf']),
                'signature_zones' => json_decode($contract['signature_zones'], true),
                'date_zone' => json_decode($contract['date_zone'], true),
                'expires_at' => $contract['expires_at'],
            ]
        ]);
        return;
    }

    if ($contract['status'] === 'pending') {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+12 minutes'));
        $upd = $db->prepare("UPDATE contracts SET status = 'opened', opened_at = NOW(), expires_at = ? WHERE id = ?");
        $upd->execute([$expiresAt, $contract['id']]);
        echo json_encode([
            'ok' => true,
            'contract' => [
                'id' => $contract['id'],
                'code' => $contract['code'],
                'title' => $contract['title'],
                'status' => 'opened',
                'pdf_url' => 'get_pdf.php?file=' . urlencode($contract['original_pdf']),
                'signature_zones' => json_decode($contract['signature_zones'], true),
                'date_zone' => json_decode($contract['date_zone'], true),
                'expires_at' => $expiresAt,
            ]
        ]);
        return;
    }

    throw new Exception('Неизвестный статус договора');
}

function handleSignContract(PDO $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception('Некорректный JSON');
    $code = trim($input['code'] ?? '');
    $strokeData = $input['stroke_data'] ?? null;
    if (!$code) throw new Exception('Не указан код');
    // Убираем строгую проверку, оставляем только проверку на пустоту
    if ($code === '') throw new Exception('Код не может быть пустым');
    if (!$strokeData || !is_array($strokeData)) throw new Exception('Нет данных подписи');

    $stmt = $db->prepare("SELECT * FROM contracts WHERE code = ?");
    $stmt->execute([$code]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) throw new Exception('Договор не найден');
    if ($contract['status'] === 'signed') throw new Exception('Уже подписан');
    if ($contract['status'] === 'opened') {
        $expires = strtotime($contract['expires_at']);
        if (time() > $expires) throw new Exception('Время истекло');
    }

    // Сохраняем подписи
    foreach ($strokeData as $idx => $strokes) {
        $stmtIns = $db->prepare("INSERT INTO contract_signatures (contract_id, zone_index, stroke_data, signature_png, created_at) VALUES (?, ?, ?, '', NOW())");
        $stmtIns->execute([$contract['id'], $idx, json_encode($strokes)]);
    }

    $stmtUpd = $db->prepare("UPDATE contracts SET status = 'signed', signed_at = NOW() WHERE id = ?");
    $stmtUpd->execute([$contract['id']]);

    echo json_encode(['ok' => true, 'message' => 'Договор подписан']);
}