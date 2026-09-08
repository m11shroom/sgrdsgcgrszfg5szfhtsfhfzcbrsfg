<?php
declare(strict_types=1);
// migrate4.php — запусти один раз в браузере, потом удали.
// Добавляет столбец icon в stories и назначает иконки стартовым новостям.

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

echo "=== МИГРАЦИЯ 4: ИКОНКИ В НОВОСТЯХ ===\n\n";

if (!tableExists($db, 'stories')) {
    echo "! Таблицы stories нет — сначала запусти migrate3.php\n";
    exit;
}

ensureColumn($db, 'stories', 'icon', "VARCHAR(32) DEFAULT NULL");

// Назначить иконки стартовым новостям (если у них ещё нет иконки)
$map = [
    'Терминалы%'  => 'qr',
    'Выпускайте%' => 'card',
    'Переводы%'   => 'bolt',
    'Платёжные%'  => 'link',
];
foreach ($map as $like => $icon) {
    $st = $db->prepare("UPDATE stories SET icon=? WHERE title LIKE ? AND (icon IS NULL OR icon='')");
    $st->execute([$icon, $like]);
    if ($st->rowCount() > 0) echo "+ иконка '$icon' → новости '$like'\n";
}

echo "\n=== ГОТОВО === Удали migrate4.php.\n";
