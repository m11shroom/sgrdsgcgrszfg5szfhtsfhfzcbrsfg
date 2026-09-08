<?php
declare(strict_types=1);

// Сессия по умолчанию в PHP живёт ~24 минуты бездействия (session.gc_maxlifetime)
// и куки без явного срока действия ненадёжно переживают закрытие приложения/браузера.
// Продлеваем и то, и другое — 30 дней.
$sessionLifetime = 60 * 60 * 24 * 30; // 30 дней в секундах
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Credentials хранятся ВЫШЕ корня сайта — недоступны через HTTP.
// Если сайт в /home/user/public_html/ — файл лежит в /home/user/db_credentials.php
$_cred = dirname(__DIR__) . '/db_credentials.php';
if (!file_exists($_cred)) {
    http_response_code(500);
    error_log('CRITICAL: db_credentials.php not found at ' . $_cred);
    die('Server configuration error. Contact administrator.');
}
require_once $_cred;
unset($_cred);

define('UPLOAD_DIR',  __DIR__ . '/uploads/');
define('UPLOAD_URL',  'uploads/');
define('ADMIN_EMAIL', 'm1plus@icloud.com');
define('MAIL_FROM',   'noreply@bank.m1shroom.ru');
define('WA_RPID',     'm1shroom.ru');
define('WA_RPNAME',   'M1plus wallet');
define('WA_ORIGIN',   'https://bank.m1shroom.ru');
