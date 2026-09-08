<?php
declare(strict_types=1);
// migrate6.php — запусти один раз в браузере, потом удали.
// Создаёт таблицу plastic_cards (пластиковые карты, выпускаемые только админом).

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/db.php';
$db = getDB();

function tableExists(PDO $db, string $t): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$t]); return (int)$st->fetchColumn() > 0;
}
function ensureColumn(PDO $db, string $t, string $c, string $def): void {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$t, $c]);
    if ((int)$st->fetchColumn() === 0) { $db->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def"); echo "+ $t.$c\n"; }
    else echo "  ок: $t.$c уже есть\n";
}

function columnExists(PDO $db, string $t, string $c): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$t, $c]); return (int)$st->fetchColumn() > 0;
}

echo "=== МИГРАЦИЯ 6: ПЛАСТИКОВЫЕ КАРТЫ ===\n\n";

if (!tableExists($db, 'plastic_cards')) {
    $db->exec("CREATE TABLE plastic_cards (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        cover_image VARCHAR(255) DEFAULT NULL,
        card_number VARCHAR(19) DEFAULT NULL,
        expiry VARCHAR(5) DEFAULT NULL,
        pin_code VARCHAR(4) DEFAULT NULL,
        delivery_address TEXT DEFAULT NULL,
        delivery_date DATE DEFAULT NULL,
        delivery_time VARCHAR(5) DEFAULT NULL,
        delivery_status VARCHAR(30) NOT NULL DEFAULT 'to_factory',
        rep_name VARCHAR(150) DEFAULT NULL,
        rep_phone VARCHAR(30) DEFAULT NULL,
        rep_photo VARCHAR(255) DEFAULT NULL,
        nfc_uid VARCHAR(64) DEFAULT NULL,
        nfc_scanned_at DATETIME DEFAULT NULL,
        is_delivered TINYINT(1) NOT NULL DEFAULT 0,
        delivered_at DATETIME DEFAULT NULL,
        yoo_access_token TEXT DEFAULT NULL,
        yoo_wallet VARCHAR(34) DEFAULT NULL,
        wallet_connected_at DATETIME DEFAULT NULL,
        balance_cached DECIMAL(14,2) DEFAULT NULL,
        balance_updated_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        CONSTRAINT fk_plastic_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "+ создана таблица plastic_cards\n";
} else {
    echo "  ок: таблица plastic_cards уже есть\n";
    ensureColumn($db, 'plastic_cards', 'yoo_access_token',    "TEXT DEFAULT NULL");
    ensureColumn($db, 'plastic_cards', 'yoo_wallet',          "VARCHAR(34) DEFAULT NULL");
    ensureColumn($db, 'plastic_cards', 'wallet_connected_at', "DATETIME DEFAULT NULL");
    ensureColumn($db, 'plastic_cards', 'balance_cached',      "DECIMAL(14,2) DEFAULT NULL");
    ensureColumn($db, 'plastic_cards', 'balance_updated_at',  "DATETIME DEFAULT NULL");
    ensureColumn($db, 'plastic_cards', 'delivered_at',        "DATETIME DEFAULT NULL");
    ensureColumn($db, 'plastic_cards', 'delivery_status',     "VARCHAR(30) NOT NULL DEFAULT 'to_factory'");
    ensureColumn($db, 'plastic_cards', 'rep_name',            "VARCHAR(150) DEFAULT NULL");
    ensureColumn($db, 'plastic_cards', 'rep_phone',            "VARCHAR(30) DEFAULT NULL");
    ensureColumn($db, 'plastic_cards', 'rep_photo',            "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'plastic_cards', 'nfc_uid',              "VARCHAR(64) DEFAULT NULL");
    ensureColumn($db, 'plastic_cards', 'nfc_scanned_at',       "DATETIME DEFAULT NULL");

    // КРИТИЧНО: раньше колонка была VARCHAR(255) и обрезала реальные токены YooMoney
    // (они длиннее 255 символов) — из-за этого баланс/история переставали работать
    // сразу после привязки. Расширяем до TEXT для уже существующих установок.
    $colType = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plastic_cards' AND COLUMN_NAME='yoo_access_token'")->fetchColumn();
    if ($colType && strtolower($colType) === 'varchar') {
        $db->exec("ALTER TABLE plastic_cards MODIFY COLUMN yoo_access_token TEXT DEFAULT NULL");
        echo "+ plastic_cards.yoo_access_token расширена VARCHAR(255) -> TEXT (токены больше не будут обрезаться)\n";
        echo "  !!! ВАЖНО: уже сохранённые токены были обрезаны и невалидны — переподключи кошельки заново через admin_plastic.php\n";
    } else {
        echo "  ок: plastic_cards.yoo_access_token уже TEXT\n";
    }

    // Переименование старого поля cvv -> pin_code (карта теперь использует ПИН-код вместо CVV)
    if (columnExists($db, 'plastic_cards', 'cvv') && !columnExists($db, 'plastic_cards', 'pin_code')) {
        $db->exec("ALTER TABLE plastic_cards CHANGE COLUMN cvv pin_code VARCHAR(4) DEFAULT NULL");
        echo "+ переименовано plastic_cards.cvv -> pin_code\n";
    } elseif (!columnExists($db, 'plastic_cards', 'pin_code')) {
        ensureColumn($db, 'plastic_cards', 'pin_code', "VARCHAR(4) DEFAULT NULL");
    } else {
        echo "  ок: plastic_cards.pin_code уже есть\n";
    }
}

echo "\n=== ГОТОВО ===\n";
echo "Управление: admin_plastic.php (только для админа).\n";
echo "Подключение кошелька к карте использует yoomoney_auth.php (тот же OAuth2-механизм, что и для пополнений).\n";
echo "История операций подключённого кошелька теперь подтягивается в dashboard.php начиная с момента привязки (wallet_connected_at).\n";
echo "Добавлены статусы доставки (delivery_status) и поля представителя (rep_name/rep_phone/rep_photo) — управляются из admin_plastic.php.\n";
echo "Удали migrate6.php с сервера после выполнения.\n";
