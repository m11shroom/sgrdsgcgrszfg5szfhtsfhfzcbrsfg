<?php
declare(strict_types=1);
// migrate5.php — запусти один раз, потом удали.
// Таблицы для OAuth YooMoney и автоматических пополнений.

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/db.php';
$db = getDB();

function tableExists(PDO $db, string $t): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$t]); return (int)$st->fetchColumn() > 0;
}

echo "=== МИГРАЦИЯ 5: YOOMONEY OAUTH + АВТОПОПОЛНЕНИЯ ===\n\n";

if (!tableExists($db, 'yoomoney_oauth')) {
    $db->exec("CREATE TABLE yoomoney_oauth (
        id INT UNSIGNED NOT NULL,
        client_id VARCHAR(128) DEFAULT NULL,
        client_secret VARCHAR(128) DEFAULT NULL,
        access_token VARCHAR(128) DEFAULT NULL,
        wallet VARCHAR(32) DEFAULT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "+ создана таблица yoomoney_oauth\n";
} else echo "  ок: yoomoney_oauth есть\n";

if (!tableExists($db, 'topups')) {
    $db->exec("CREATE TABLE topups (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        label VARCHAR(64) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        credit_amount DECIMAL(12,2) NOT NULL,
        credited TINYINT(1) NOT NULL DEFAULT 0,
        operation_id VARCHAR(64) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        credited_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_topup_label (label),
        KEY idx_topup_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "+ создана таблица topups\n";
} else echo "  ок: topups есть\n";

echo "\n=== ГОТОВО ===\n";
echo "1. Зарегистрируй приложение: https://yoomoney.ru/myservices/new\n";
echo "   Redirect URI укажи: https://ТВОЙ_ДОМЕН/yoomoney_auth.php\n";
echo "2. Открой yoomoney_auth.php под админом и подключи кошелёк.\n";
echo "3. Удали migrate5.php с сервера.\n";
