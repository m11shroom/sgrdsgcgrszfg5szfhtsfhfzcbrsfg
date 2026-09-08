<?php
declare(strict_types=1);
// migrate2.php — запусти один раз в браузере, потом удали с сервера.
// Создаёт таблицу stories (новости-сторис) и наполняет стартовыми новостями.

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/db.php';
$db = getDB();

function tableExists(PDO $db, string $table): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

echo "=== МИГРАЦИЯ 2: STORIES ===\n\n";

if (!tableExists($db, 'stories')) {
    $db->exec("CREATE TABLE stories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(120) NOT NULL,
        body TEXT NOT NULL,
        emoji VARCHAR(16) NOT NULL DEFAULT '💜',
        grad_from VARCHAR(16) NOT NULL DEFAULT '#6d28d9',
        grad_to VARCHAR(16) NOT NULL DEFAULT '#2d1f52',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "+ создана таблица stories\n";
} else {
    echo "  ок: таблица stories уже есть\n";
}

$count = (int)$db->query("SELECT COUNT(*) FROM stories")->fetchColumn();
if ($count === 0) {
    $seed = [
        ['Терминалы уже здесь', "Оплачивайте покупки по QR-коду прямо с баланса M1plus wallet.\n\nНажмите «Терминал» на главном экране, наведите камеру на QR — и готово. Деньги списываются мгновенно, без комиссии.", '✌️', '#7c3aed', '#3b0764', 1],
        ['Выпускайте карты', "Виртуальные карты M1plus — с нулевым балансом или предоплаченные, в рублях, долларах и евро.\n\nЗайдите в раздел «Карты» и выберите подходящую. Моментальный выпуск доступен для большинства карт.", '💳', '#0d9488', '#134e4a', 2],
        ['Переводы без комиссии', "Переводите деньги другим пользователям M1plus wallet по никнейму — комиссия 0 ₽, зачисление мгновенно.\n\nПросто введите @никнейм получателя в разделе «Переводы».", '⚡', '#d97706', '#7c2d12', 3],
        ['Платёжные ссылки', "Создавайте ссылки для приёма платежей — отправьте ссылку, и вам переведут деньги в один клик.\n\nИдеально для продаж и сборов. Раздел «Ссылки» на главном экране.", '🔗', '#db2777', '#831843', 4],
    ];
    $ins = $db->prepare("INSERT INTO stories (title, body, emoji, grad_from, grad_to, sort_order) VALUES (?,?,?,?,?,?)");
    foreach ($seed as $s) { $ins->execute($s); echo "+ новость: {$s[0]}\n"; }
} else {
    echo "  ок: новости уже есть ($count шт.)\n";
}

echo "\n=== ГОТОВО ===\nУдали migrate2.php с сервера.\nУправление новостями: stories_admin.php (только для админа).\n";
