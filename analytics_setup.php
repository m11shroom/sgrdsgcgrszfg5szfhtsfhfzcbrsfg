<?php
declare(strict_types=1);
// analytics_setup.php — таблица настроек аналитики + AES-шифрование пароля PDF в БД.
// Подключается через require_once во всех analytics-файлах.

$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS analytics_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/**
 * Ключ шифрования берём из db_credentials.php (тот же файл, где хранятся
 * пароли от БД и SMTP — он и так лежит выше корня сайта, недоступен по HTTP).
 * Если константы там нет — используем производную от DB_PASS как запасной
 * вариант (менее надёжно, но работает без правки credentials-файла).
 */
function analytics_enc_key(): string {
    if (defined('ANALYTICS_ENC_KEY')) {
        return hash('sha256', ANALYTICS_ENC_KEY, true);
    }
    return hash('sha256', 'm1plus_analytics_' . (defined('DB_PASS') ? DB_PASS : 'fallback_key'), true);
}

function analytics_encrypt(string $plain): string {
    $key = analytics_enc_key();
    $iv  = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function analytics_decrypt(string $encoded): ?string {
    $key  = analytics_enc_key();
    $data = base64_decode($encoded, true);
    if ($data === false || strlen($data) < 17) return null;
    $iv     = substr($data, 0, 16);
    $cipher = substr($data, 16);
    $plain  = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? null : $plain;
}

function analytics_get_setting(PDO $db, string $key): ?string {
    $s = $db->prepare('SELECT setting_value FROM analytics_settings WHERE setting_key=?');
    $s->execute([$key]);
    $val = $s->fetchColumn();
    if ($val === false) return null;
    return analytics_decrypt($val);
}

function analytics_set_setting(PDO $db, string $key, string $plainValue): void {
    $enc = analytics_encrypt($plainValue);
    $db->prepare('INSERT INTO analytics_settings (setting_key, setting_value) VALUES (?,?)
                  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
       ->execute([$key, $enc]);
}

// Первичная установка пароля PDF-отчётов, если его ещё нет в БД
$existing = analytics_get_setting($db, 'pdf_password');
if ($existing === null) {
    analytics_set_setting($db, 'pdf_password', 'Mushroomqwerty777!!');
}
