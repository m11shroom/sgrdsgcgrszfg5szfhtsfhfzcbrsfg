<?php
declare(strict_types=1);

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Доступ запрещён']);
    exit;
}

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
});

$db = getDB();
$action = $_GET['action'] ?? '';
header('Content-Type: application/json');

try {
    $db->query("SELECT 1 FROM contracts LIMIT 1");
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'Таблица contracts не найдена: ' . $e->getMessage()]);
    exit;
}

try {
    switch ($action) {
        case 'upload_pdf':
            handleUploadPdf($db);
            break;
        case 'save_zones':
            handleSaveZones($db);
            break;
        case 'list_contracts':
            handleListContracts($db);
            break;
        case 'delete_contract':
            handleDeleteContract($db);
            break;
        case 'get_contract':
            handleGetContract($db);
            break;
        default:
            echo json_encode(['ok' => false, 'error' => 'Неизвестное действие']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

/* ---------- ОБРАБОТЧИКИ ---------- */

function handleUploadPdf(PDO $db)
{
    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Файл не загружен (код: ' . ($_FILES['pdf']['error'] ?? 'нет') . ')');
    }

    $title = $_POST['title'] ?? '';
    $pageCount = (int)($_POST['page_count'] ?? 1);
    $fileName = bin2hex(random_bytes(8)) . '.pdf';
    $uploadDir = dirname(__DIR__) . '/pdfuploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $filePath = $uploadDir . $fileName;
    if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $filePath)) {
        throw new Exception('Не удалось сохранить файл');
    }
    $hash = hash_file('sha256', $filePath);
    $code = 'TMP-' . bin2hex(random_bytes(4));
    $stmt = $db->prepare("INSERT INTO contracts (code, title, original_pdf, original_hash, page_count, signature_zones, status, created_at) VALUES (?, ?, ?, ?, ?, '[]', 'pending', NOW())");
    $stmt->execute([$code, $title, $fileName, $hash, $pageCount]);
    $id = $db->lastInsertId();
    echo json_encode(['ok' => true, 'contract_id' => $id, 'file' => $fileName]);
}

function handleSaveZones(PDO $db)
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception('Некорректный JSON');

    $contractId = (int)($input['contract_id'] ?? 0);
    $sigZones = $input['signature_zones'] ?? [];
    $dateParts = $input['date_parts'] ?? [];
    $customCode = trim($input['code'] ?? '');

    if ($contractId <= 0) throw new Exception('Неверный ID');
    if (empty($sigZones)) throw new Exception('Нет зон подписи');

    if ($customCode === '') {
        $customCode = 'CONTRACT-' . strtoupper(bin2hex(random_bytes(6)));
    } else {
        $stmt = $db->prepare("SELECT id FROM contracts WHERE code = ? AND id != ?");
        $stmt->execute([$customCode, $contractId]);
        if ($stmt->fetch()) throw new Exception('Код уже существует');
    }

    $sigZonesJson = json_encode($sigZones);
    $datePartsJson = json_encode($dateParts);

    $stmt = $db->prepare("UPDATE contracts SET code = ?, signature_zones = ?, date_zone = ?, status = 'pending' WHERE id = ?");
    $stmt->execute([$customCode, $sigZonesJson, $datePartsJson, $contractId]);

    echo json_encode(['ok' => true, 'code' => $customCode]);
}

function handleListContracts(PDO $db)
{
    $stmt = $db->query("SELECT id, code, title, status, created_at, signed_at FROM contracts ORDER BY created_at DESC");
    echo json_encode(['ok' => true, 'contracts' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handleDeleteContract(PDO $db)
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception('Некорректный JSON');
    $id = (int)($input['contract_id'] ?? 0);
    if ($id <= 0) throw new Exception('Неверный ID');

    $stmt = $db->prepare("SELECT original_pdf FROM contracts WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && $row['original_pdf']) {
        $filePath = dirname(__DIR__) . '/pdfuploads/' . $row['original_pdf'];
        if (file_exists($filePath)) unlink($filePath);
    }
    try {
        $db->prepare("DELETE FROM contract_signatures WHERE contract_id = ?")->execute([$id]);
    } catch (PDOException $e) {}
    $db->prepare("DELETE FROM contracts WHERE id = ?")->execute([$id]);
    echo json_encode(['ok' => true]);
}

function handleGetContract(PDO $db)
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception('Неверный ID');

    $stmt = $db->prepare("SELECT id, code, title, original_pdf, original_hash, signed_hash, signed_pdf, signed_at, status, created_at, date_zone, signature_zones FROM contracts WHERE id = ?");
    $stmt->execute([$id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) throw new Exception('Договор не найден');

    // Подписи
    $stmtSig = $db->prepare("SELECT zone_index, stroke_data FROM contract_signatures WHERE contract_id = ? ORDER BY zone_index");
    $stmtSig->execute([$id]);
    $signatures = $stmtSig->fetchAll(PDO::FETCH_ASSOC);
    foreach ($signatures as &$sig) {
        $sig['stroke_data'] = json_decode($sig['stroke_data'], true);
    }

    $pdfUrl = 'get_pdf.php?file=' . urlencode($contract['original_pdf']);
    $dateParts = json_decode($contract['date_zone'], true) ?: [];
    $sigZones = json_decode($contract['signature_zones'], true) ?: [];

    echo json_encode([
        'ok' => true,
        'contract' => [
            'id' => $contract['id'],
            'code' => $contract['code'],
            'title' => $contract['title'],
            'original_hash' => $contract['original_hash'],
            'signed_hash' => $contract['signed_hash'],
            'signed_pdf' => $contract['signed_pdf'],
            'signed_at' => $contract['signed_at'],
            'status' => $contract['status'],
            'created_at' => $contract['created_at'],
            'signatures' => $signatures,
            'pdf_url' => $pdfUrl,
            'date_zone' => $dateParts,
            'signature_zones' => $sigZones,
        ]
    ]);
}