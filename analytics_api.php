<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';

if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['error' => '$db не создана']);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$isAdmin = false;

try {
    $st = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
    $st->execute([$uid]);
    $row = $st->fetch();
    $isAdmin = ($row && (int)($row['is_admin'] ?? 0) === 1) || $uid === 1;
} catch (Throwable $e) {
    $isAdmin = ($uid === 1);
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещён']);
    exit;
}

$period = $_GET['period'] ?? 'month';
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;

$now = new DateTime();
switch ($period) {
    case 'month':
        $dateFrom = (clone $now)->modify('first day of this month')->format('Y-m-d');
        $dateTo = $now->format('Y-m-d');
        break;
    case 'year':
        $dateFrom = (clone $now)->modify('first day of january this year')->format('Y-m-d');
        $dateTo = $now->format('Y-m-d');
        break;
    case 'custom':
        if (!$from || !$to) {
            echo json_encode(['error' => 'Укажите даты']);
            exit;
        }
        $dateFrom = $from;
        $dateTo = $to;
        break;
    default:
        $dateFrom = '2020-01-01';
        $dateTo = $now->format('Y-m-d');
}

$stats = [];

try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as total FROM transactions WHERE status='completed' AND DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$dateFrom, $dateTo]);
    $stats['revenue'] = (float)$stmt->fetch()['total'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM transactions WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$dateFrom, $dateTo]);
    $stats['transactions'] = (int)$stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM users WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$dateFrom, $dateTo]);
    $stats['users'] = (int)$stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM issued_cards WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$dateFrom, $dateTo]);
    $stats['cards'] = (int)$stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT type, COUNT(*) as cnt FROM transactions WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY type ORDER BY cnt DESC");
    $stmt->execute([$dateFrom, $dateTo]);
    $rows = $stmt->fetchAll();
    $pie = ['labels' => [], 'values' => []];
    foreach ($rows as $r) {
        $pie['labels'][] = $r['type'] ?: 'Прочее';
        $pie['values'][] = (int)$r['cnt'];
    }
    if (empty($pie['labels'])) { $pie['labels'] = ['Нет данных']; $pie['values'] = [1]; }

    $stmt = $db->prepare("SELECT DATE(created_at) as d, SUM(amount) as total FROM transactions WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d ASC");
    $stmt->execute([$dateFrom, $dateTo]);
    $rows = $stmt->fetchAll();
    $bar = ['labels' => [], 'values' => []];
    foreach ($rows as $r) {
        $bar['labels'][] = date('d.m', strtotime($r['d']));
        $bar['values'][] = (float)$r['total'];
    }
    if (empty($bar['labels'])) { $bar['labels'] = ['—']; $bar['values'] = [0]; }

    echo json_encode(['stats' => $stats, 'pie' => $pie, 'bar' => $bar]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}