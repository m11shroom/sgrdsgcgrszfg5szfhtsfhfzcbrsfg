<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { echo json_encode(['found'=>false]); exit; }

$uid = (int)$_SESSION['user_id'];
$q   = trim($_GET['username'] ?? '');

if (!$q) { echo json_encode(['found'=>false]); exit; }

$db = getDB();

// Look up by username
$s = $db->prepare('SELECT u.id, u.username, COALESCE(cp.display_name, u.username) AS display_name
                   FROM users u
                   LEFT JOIN chat_profiles cp ON cp.user_id = u.id
                   WHERE u.username = ? AND u.id != ? AND u.is_admin = 0 LIMIT 1');
$s->execute([$q, $uid]);
$user = $s->fetch();

if (!$user) {
    echo json_encode(['found' => false]); exit;
}

echo json_encode([
    'found'        => true,
    'id'           => (int)$user['id'],
    'username'     => $user['username'],
    'display_name' => $user['display_name'],
]);
