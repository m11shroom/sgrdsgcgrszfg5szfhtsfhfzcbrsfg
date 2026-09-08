<?php
declare(strict_types=1);
// Didit v3 Webhook
// Webhook URL: https://wallet.m1plus.ru/didit_webhook.php
// Set in business.didit.me → выбери свой App → Settings → Webhook URL

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/didit.php';

$rawBody = file_get_contents('php://input');

// Collect headers
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $key = strtolower(str_replace('_', '-', substr($k, 5)));
        $headers[$key] = $v;
    }
}

if (!diditVerifyWebhook($rawBody, $headers)) {
    error_log('Didit webhook invalid sig. Headers: ' . json_encode($headers) . ' Body: ' . substr($rawBody, 0, 200));
    http_response_code(401); echo json_encode(['error' => 'Invalid signature']); exit;
}

$data = json_decode($rawBody, true);
if (!$data) { http_response_code(400); exit; }

$sessionId  = $data['session_id']  ?? '';
$rawStatus  = $data['status']      ?? '';
$vendorData = $data['vendor_data'] ?? '';
$uid        = (int)$vendorData;

error_log("Didit webhook: session=$sessionId status=$rawStatus uid=$uid");

if (!$uid && !$sessionId) { http_response_code(200); echo 'OK'; exit; }

try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS didit_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        session_id VARCHAR(200) NOT NULL DEFAULT '', status VARCHAR(50) DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE users ADD COLUMN is_verified TINYINT(1) DEFAULT 0"); } catch (\Throwable $e) {}

    $status = strtolower($rawStatus);

    // Update or insert verification record
    if ($uid) {
        $ex = $pdo->prepare('SELECT id FROM didit_verifications WHERE user_id=? AND session_id=?');
        $ex->execute([$uid, $sessionId]);
        if ($ex->fetch()) {
            $pdo->prepare('UPDATE didit_verifications SET status=? WHERE user_id=? AND session_id=?')
                ->execute([$status, $uid, $sessionId]);
        } else {
            $pdo->prepare('INSERT INTO didit_verifications (user_id,session_id,status) VALUES (?,?,?)')
                ->execute([$uid, $sessionId, $status]);
        }

        if ($status === 'approved') {
            $pdo->prepare('UPDATE users SET is_verified=1 WHERE id=?')->execute([$uid]);
            error_log("Didit: user $uid marked VERIFIED");
        }
    } elseif ($sessionId) {
        $sv = $pdo->prepare('SELECT user_id FROM didit_verifications WHERE session_id=?');
        $sv->execute([$sessionId]); $row = $sv->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare('UPDATE didit_verifications SET status=? WHERE session_id=?')->execute([$status, $sessionId]);
            if ($status === 'approved') {
                $pdo->prepare('UPDATE users SET is_verified=1 WHERE id=?')->execute([$row['user_id']]);
            }
        }
    }
} catch (\Throwable $e) {
    error_log('Didit webhook DB error: ' . $e->getMessage());
    http_response_code(500); exit;
}

http_response_code(200); echo json_encode(['message' => 'OK', 'status' => $status]);
