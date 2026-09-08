<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
$db = getDB();
// Таблица device_tokens создаётся через migrate.php

const DEVICE_COOKIE = 'm1_device';
const COOKIE_DAYS   = 365;
const MAX_ATTEMPTS  = 5;

function setDeviceCookie(string $token): void {
    setcookie(DEVICE_COOKIE, $token, [
        'expires'  => time() + 86400 * COOKIE_DAYS,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}
function clearDeviceCookie(): void {
    setcookie(DEVICE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}
function findDevice(PDO $db, string $rawToken) {
    if (!$rawToken) return null;
    $h = hash('sha256', $rawToken);
    $st = $db->prepare("SELECT * FROM device_tokens WHERE token_hash=?");
    $st->execute([$h]);
    return $st->fetch() ?: null;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---- Create PIN for this device (requires active session) ---- */
if ($action === 'setup') {
    if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'Нет сессии']); exit; }
    $pin = preg_replace('/\D/','', (string)($_POST['pin'] ?? ''));
    if (strlen($pin) !== 4) { echo json_encode(['ok'=>false,'error'=>'Код должен быть 4 цифры']); exit; }

    $uid = (int)$_SESSION['user_id'];

    // Replace existing token for this browser if any
    $old = findDevice($db, $_COOKIE[DEVICE_COOKIE] ?? '');
    if ($old) $db->prepare("DELETE FROM device_tokens WHERE id=?")->execute([$old['id']]);

    $token = bin2hex(random_bytes(32));
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
    $db->prepare("INSERT INTO device_tokens (user_id, token_hash, pin_hash, device_label) VALUES (?,?,?,?)")
       ->execute([$uid, hash('sha256', $token), password_hash($pin, PASSWORD_DEFAULT), $ua]);
    setDeviceCookie($token);
    echo json_encode(['ok'=>true]);
    exit;
}

/* ---- Unlock with PIN (no session needed, uses device cookie) ---- */
if ($action === 'unlock') {
    $pin = preg_replace('/\D/','', (string)($_POST['pin'] ?? ''));
    $dev = findDevice($db, $_COOKIE[DEVICE_COOKIE] ?? '');
    if (!$dev) { echo json_encode(['ok'=>false,'error'=>'device_gone']); exit; }

    if ((int)$dev['attempts'] >= MAX_ATTEMPTS) {
        $db->prepare("DELETE FROM device_tokens WHERE id=?")->execute([$dev['id']]);
        clearDeviceCookie();
        echo json_encode(['ok'=>false,'error'=>'device_gone']);
        exit;
    }

    if (strlen($pin) !== 4 || !password_verify($pin, $dev['pin_hash'])) {
        $left = MAX_ATTEMPTS - ((int)$dev['attempts'] + 1);
        $db->prepare("UPDATE device_tokens SET attempts=attempts+1 WHERE id=?")->execute([$dev['id']]);
        if ($left <= 0) {
            $db->prepare("DELETE FROM device_tokens WHERE id=?")->execute([$dev['id']]);
            clearDeviceCookie();
            echo json_encode(['ok'=>false,'error'=>'device_gone']);
        } else {
            echo json_encode(['ok'=>false,'error'=>'wrong_pin','left'=>$left]);
        }
        exit;
    }

    // Success: restore session
    $st = $db->prepare("SELECT * FROM users WHERE id=?");
    $st->execute([(int)$dev['user_id']]);
    $user = $st->fetch();
    if (!$user) { echo json_encode(['ok'=>false,'error'=>'device_gone']); exit; }

    session_regenerate_id(true);
    $_SESSION['user_id']  = (int)$user['id'];
    $_SESSION['is_admin'] = !empty($user['is_admin']) ? 1 : 0;

    $db->prepare("UPDATE device_tokens SET attempts=0, last_used=CURRENT_TIMESTAMP WHERE id=?")->execute([$dev['id']]);
    echo json_encode(['ok'=>true]);
    exit;
}

/* ---- Forget device (logout from PIN screen) ---- */
if ($action === 'forget') {
    $dev = findDevice($db, $_COOKIE[DEVICE_COOKIE] ?? '');
    if ($dev) $db->prepare("DELETE FROM device_tokens WHERE id=?")->execute([$dev['id']]);
    clearDeviceCookie();
    session_destroy();
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
