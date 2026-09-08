<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);



header('Content-Type: application/json');

require_once __DIR__ . '/../../db_credentials.php';

if (!isset($db)) {
    echo json_encode(['error' => '$db не создана']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$password = $input['password'] ?? '';
$from = $input['from'] ?? '';
$to = $input['to'] ?? '';

if (empty($_SESSION['user_id']) || !$password || !$from || !$to) {
    echo json_encode(['error' => 'Неверные параметры']);
    exit;
}

$uid = (int)$_SESSION['user_id'];

try {
    $st = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = 'pdf_password_hash'");
    $st->execute();
    $row = $st->fetch();
    if (!$row) {
        echo json_encode(['error' => 'Пароль не настроен. Откройте hash_password.php']);
        exit;
    }
    $hash = $row['setting_value'];
    if (!password_verify($password, $hash)) {
        echo json_encode(['error' => 'Неверный пароль']);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['error' => 'Ошибка проверки: ' . $e->getMessage()]);
    exit;
}

$host = $_SERVER['HTTP_HOST'] ?? 'm1plus.pw';
if (strpos($host, 'bank.m1shroom.ru') !== false) {
    $recipientEmail = 'finance@bank.m1shroom.ru';
    $siteName = 'Bank M1Shroom';
} else {
    $recipientEmail = 'finance@m1plus.pw';
    $siteName = 'M1Plus';
}

try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as cnt FROM transactions WHERE DATE(created_at) BETWEEN ? AND ? AND status='completed'");
    $stmt->execute([$from, $to]);
    $txData = $stmt->fetch();
    $revenue = (float)$txData['total'];
    $txCount = (int)$txData['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM users WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    $usersCount = (int)$stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM issued_cards WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    $cardsCount = (int)$stmt->fetch()['cnt'];

    $subject = "Бизнес-отчёт $siteName: $from — $to";
    $message = "Уважаемые коллеги!\n\nОтчёт по $siteName за период $from — $to.\n\nВыручка: " . number_format($revenue, 0, '.', ' ') . " руб.\nТранзакций: $txCount\nПользователей: $usersCount\nКарт: $cardsCount\n\nПароль PDF: $password\n\nКоманда $siteName";
    $headers = "From: noreply@$host\r\nReply-To: finance@$host\r\n";
    
    $sent = @mail($recipientEmail, $subject, $message, $headers);

    try {
        $st = $db->prepare("INSERT INTO sent_reports (admin_id, recipient_email, period_from, period_to, site_domain) VALUES (?, ?, ?, ?, ?)");
        $st->execute([$uid, $recipientEmail, $from, $to, $host]);
    } catch (Throwable $e) {}

    if ($sent) {
        echo json_encode(['ok' => true, 'email' => $recipientEmail]);
    } else {
        echo json_encode(['error' => 'mail() не сработал']);
    }
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}