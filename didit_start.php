<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/didit.php';

if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$uid = (int)$_SESSION['user_id'];
$db  = getDB();

// Ensure tables exist
try { $db->exec("ALTER TABLE users ADD COLUMN is_verified TINYINT(1) DEFAULT 0"); } catch (\Throwable $e) {}
$db->exec("CREATE TABLE IF NOT EXISTS didit_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_id VARCHAR(200) NOT NULL DEFAULT '',
    status VARCHAR(50) DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Already verified?
$vCheck = $db->prepare('SELECT is_verified FROM users WHERE id=?');
$vCheck->execute([$uid]); $vRow = $vCheck->fetch();
if (!empty($vRow['is_verified'])) { header('Location: create_link.php'); exit; }

// Pending review?
$sv = $db->prepare("SELECT status FROM didit_verifications WHERE user_id=? ORDER BY created_at DESC LIMIT 1");
$sv->execute([$uid]); $svRow = $sv->fetch();
if ($svRow && in_array($svRow['status'], ['in_review', 'in_progress', 'In Review', 'In Progress'])) {
    $_SESSION['flash'] = 'Ваша верификация уже на рассмотрении. Ожидайте.';
    header('Location: didit_callback.php'); exit;
}

// Get user email
$userRow = $db->prepare('SELECT email FROM users WHERE id=?');
$userRow->execute([$uid]); $userRow = $userRow->fetch();

// Create session
$session = diditCreateSession($uid, $userRow['email'] ?? '');

if (isset($session['error'])) {
    $_SESSION['flash'] = $session['error'];
    header('Location: create_link.php'); exit;
}

$url       = $session['url']        ?? '';
$sessionId = $session['session_id'] ?? '';

if (!$url) {
    $_SESSION['flash'] = 'Didit не вернул URL. Ответ: ' . json_encode($session);
    header('Location: create_link.php'); exit;
}

// Save session to DB
$db->prepare('INSERT INTO didit_verifications (user_id, session_id, status) VALUES (?,?,?)')
   ->execute([$uid, $sessionId, 'pending']);

header('Location: ' . $url); exit;
