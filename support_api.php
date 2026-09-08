<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { echo json_encode(['error'=>'auth']); exit; }

$uid     = (int)$_SESSION['user_id'];
$isAdmin = !empty($_SESSION['is_admin']);
$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$db      = getDB();

// Helper: avatar URL
$avUrl = fn(?string $av): ?string => $av ? UPLOAD_URL.$av : null;

if ($action === 'get') {
    $targetUid = $isAdmin && isset($_GET['user_id']) ? (int)$_GET['user_id'] : $uid;

    // Fetch messages for this conversation, join sender's avatar/username
    $s = $db->prepare(
        'SELECT sm.id, sm.is_admin, sm.message,
                DATE_FORMAT(sm.created_at, "%H:%i") as time,
                sender.username AS sender_name,
                sender.avatar   AS sender_avatar
         FROM support_messages sm
         JOIN users sender ON sender.id = sm.sender_id
         WHERE sm.user_id = ?
         ORDER BY sm.created_at ASC'
    );
    $s->execute([$targetUid]);
    $msgs = array_map(fn($m) => [
        'id'       => $m['id'],
        'is_admin' => (int)$m['is_admin'],
        'message'  => $m['message'],
        'time'     => $m['time'],
        'username' => $m['sender_name'],
        'avatar'   => $avUrl($m['sender_avatar']),
    ], $s->fetchAll());
    echo json_encode(['messages' => $msgs]); exit;
}

if ($action === 'send') {
    $msg = htmlspecialchars(trim(substr($_POST['message'] ?? '', 0, 1000)));
    if (!$msg) { echo json_encode(['error'=>'empty']); exit; }
    // sender_id = current user, user_id = conversation owner (the non-admin user)
    $db->prepare('INSERT INTO support_messages (user_id,sender_id,is_admin,message) VALUES (?,?,0,?)')
       ->execute([$uid, $uid, $msg]);
    echo json_encode(['ok'=>1]); exit;
}

if ($action === 'admin_reply' && $isAdmin) {
    $targetUid = (int)($_POST['user_id'] ?? 0);
    $msg = htmlspecialchars(trim(substr($_POST['message'] ?? '', 0, 1000)));
    if (!$msg || !$targetUid) { echo json_encode(['error'=>'empty']); exit; }
    // sender_id = admin, user_id = target user (conversation owner)
    $db->prepare('INSERT INTO support_messages (user_id,sender_id,is_admin,message) VALUES (?,?,1,?)')
       ->execute([$targetUid, $uid, $msg]);
    echo json_encode(['ok'=>1]); exit;
}

if ($action === 'users' && $isAdmin) {
    $s = $db->query(
        'SELECT sm.user_id, u.username, u.avatar,
         COUNT(sm.id) AS msg_count,
         MAX(sm.created_at) AS last_msg
         FROM support_messages sm
         JOIN users u ON u.id=sm.user_id
         WHERE u.is_admin=0
         GROUP BY sm.user_id
         ORDER BY last_msg DESC'
    );
    $rows = array_map(fn($r) => [
        'user_id'   => $r['user_id'],
        'username'  => $r['username'],
        'avatar'    => $avUrl($r['avatar']),
        'msg_count' => $r['msg_count'],
    ], $s->fetchAll());
    echo json_encode(['users' => $rows]); exit;
}

echo json_encode(['error'=>'unknown']);
