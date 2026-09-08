<?php
declare(strict_types=1);
// migrate.php — запусти один раз: открой в браузере /migrate.php (или php migrate.php)
// Создаёт недостающие таблицы и добавляет недостающие столбцы. Повторный запуск безопасен.

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/db.php';
$db = getDB();

/* Добавить столбец, если его нет (аналог ADD COLUMN IF NOT EXISTS для MySQL) */
function ensureColumn(PDO $db, string $table, string $column, string $definition): void {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    if ((int)$st->fetchColumn() === 0) {
        $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        echo "+ добавлен столбец $table.$column\n";
    } else {
        echo "  ок: $table.$column уже есть\n";
    }
}

/* Проверить, существует ли таблица */
function tableExists(PDO $db, string $table): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

echo "=== МИГРАЦИЯ M1PLUS WALLET ===\n\n";

/* ---------- device_tokens (PIN-вход) ---------- */
if (!tableExists($db, 'device_tokens')) {
    $db->exec("CREATE TABLE device_tokens (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        pin_hash VARCHAR(255) NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        device_label VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_used DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_device_token (token_hash),
        KEY idx_device_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "+ создана таблица device_tokens\n";
} else {
    echo "  ок: таблица device_tokens уже есть\n";
    ensureColumn($db, 'device_tokens', 'attempts',     "INT NOT NULL DEFAULT 0");
    ensureColumn($db, 'device_tokens', 'device_label', "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'device_tokens', 'last_used',    "DATETIME DEFAULT CURRENT_TIMESTAMP");
}

/* ---------- terminal_sessions (терминал) ---------- */
if (!tableExists($db, 'terminal_sessions')) {
    $db->exec("CREATE TABLE terminal_sessions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        token VARCHAR(64) NOT NULL,
        org_name VARCHAR(255) NOT NULL,
        amount DECIMAL(12,2) DEFAULT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'waiting',
        paid_by_user_id INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_terminal_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "+ создана таблица terminal_sessions\n";
} else {
    echo "  ок: таблица terminal_sessions уже есть\n";
    ensureColumn($db, 'terminal_sessions', 'amount',          "DECIMAL(12,2) DEFAULT NULL");
    ensureColumn($db, 'terminal_sessions', 'status',          "VARCHAR(32) NOT NULL DEFAULT 'waiting'");
    ensureColumn($db, 'terminal_sessions', 'paid_by_user_id', "INT DEFAULT NULL");
    ensureColumn($db, 'terminal_sessions', 'updated_at',      "DATETIME DEFAULT CURRENT_TIMESTAMP");
}

/* ---------- card_templates (новые поля карт) ---------- */
if (tableExists($db, 'card_templates')) {
    ensureColumn($db, 'card_templates', 'currency',       "VARCHAR(8) NOT NULL DEFAULT 'RUB'");
    ensureColumn($db, 'card_templates', 'quantity',       "INT DEFAULT NULL");
    ensureColumn($db, 'card_templates', 'prepaid_amount', "DECIMAL(12,2) NOT NULL DEFAULT 0");
} else {
    echo "! таблицы card_templates нет — пропускаю\n";
}

/* ---------- issued_cards (новые поля) ---------- */
if (tableExists($db, 'issued_cards')) {
    ensureColumn($db, 'issued_cards', 'purpose',           "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'issued_cards', 'currency',          "VARCHAR(8) NOT NULL DEFAULT 'RUB'");
    ensureColumn($db, 'issued_cards', 'prepaid_amount',    "DECIMAL(12,2) DEFAULT NULL");
    ensureColumn($db, 'issued_cards', 'commission_amount', "DECIMAL(12,2) NOT NULL DEFAULT 0");
} else {
    echo "! таблицы issued_cards нет — пропускаю\n";
}

echo "\n=== ГОТОВО ===\n";
echo "Теперь удали migrate.php с сервера или закрой к нему доступ.\n";
