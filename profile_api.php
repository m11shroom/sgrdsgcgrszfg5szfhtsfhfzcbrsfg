<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'Не авторизован']); exit; }

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action === 'avatar') {
    if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok'=>false,'error'=>'Файл не получен']); exit;
    }
    $f = $_FILES['avatar'];
    if ($f['size'] > 5 * 1024 * 1024) { echo json_encode(['ok'=>false,'error'=>'Максимум 5 МБ']); exit; }

    $mime = mime_content_type($f['tmp_name']);
    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($extMap[$mime])) { echo json_encode(['ok'=>false,'error'=>'Только JPG, PNG, WEBP или GIF']); exit; }

    $dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $name = 'av_' . $uid . '_' . time() . '.' . $extMap[$mime];
    if (!move_uploaded_file($f['tmp_name'], rtrim($dir,'/') . '/' . $name)) {
        echo json_encode(['ok'=>false,'error'=>'Не удалось сохранить файл']); exit;
    }

    // Delete old avatar file
    $st = $db->prepare("SELECT avatar FROM users WHERE id=?");
    $st->execute([$uid]);
    $old = $st->fetchColumn();
    if ($old && str_starts_with($old, 'av_')) @unlink(rtrim($dir,'/') . '/' . $old);

    $db->prepare("UPDATE users SET avatar=? WHERE id=?")->execute([$name, $uid]);

    $url = (defined('UPLOAD_URL') ? UPLOAD_URL : '/uploads/') . $name;
    echo json_encode(['ok'=>true,'avatar'=>$url]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
