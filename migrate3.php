<?php
declare(strict_types=1);
// migrate3.php — запусти один раз в браузере, потом удали.
// Создаёт stories (если нет), добавляет столбец image, сеет стартовые новости.

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
    else echo "  ок: $t.$c\n";
}

echo "=== МИГРАЦИЯ 3: STORIES + IMAGE ===\n\n";

if (!tableExists($db, 'stories')) {
    $db->exec("CREATE TABLE stories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(120) NOT NULL,
        body TEXT NOT NULL,
        emoji VARCHAR(16) NOT NULL DEFAULT '💜',
        grad_from VARCHAR(16) NOT NULL DEFAULT '#6d28d9',
        grad_to VARCHAR(16) NOT NULL DEFAULT '#2d1f52',
        image VARCHAR(255) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "+ создана таблица stories\n";
} else {
    echo "  ок: таблица stories есть\n";
    ensureColumn($db, 'stories', 'image', "VARCHAR(255) DEFAULT NULL");
}

$count = (int)$db->query("SELECT COUNT(*) FROM stories")->fetchColumn();
if ($count === 0) {
    $seed = [
        ['Терминалы уже здесь', "Оплачивайте покупки по QR-коду прямо с баланса M1plus wallet.\n\nНажмите «Терминал» на главном экране, наведите камеру на QR — и готово. Деньги списываются мгновенно, без комиссии.", '✌️', '#7c3aed', '#3b0764', 1],
        ['Выпускайте карты', "Виртуальные карты M1plus — с нулевым балансом или предоплаченные, в рублях, долларах и евро.\n\nЗайдите в раздел «Карты» и выберите подходящую.", '💳', '#0d9488', '#134e4a', 2],
        ['Переводы без комиссии', "Переводите деньги другим пользователям M1plus wallet по никнейму — комиссия 0 ₽, зачисление мгновенно.", '⚡', '#d97706', '#7c2d12', 3],
        ['Платёжные ссылки', "Создавайте ссылки для приёма платежей — отправьте ссылку, и вам переведут деньги в один клик.", '🔗', '#db2777', '#831843', 4],
    ];
    $ins = $db->prepare("INSERT INTO stories (title, body, emoji, grad_from, grad_to, sort_order) VALUES (?,?,?,?,?,?)");
    foreach ($seed as $s) { $ins->execute($s); echo "+ новость: {$s[0]}\n"; }
} else echo "  ок: новости уже есть ($count шт.)\n";

echo "\n=== ГОТОВО === Удали migrate3.php.\n";
