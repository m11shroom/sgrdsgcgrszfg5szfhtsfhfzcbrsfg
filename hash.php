<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h3>Поиск файла БД...</h3>";

// Проверяем разные пути
$paths = [
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../../db.php',
    __DIR__ . '/db_credentials.php',
    __DIR__ . '/../db_credentials.php',
    __DIR__ . '/../../db_credentials.php',
    __DIR__ . '/config.php',
    __DIR__ . '/../config.php',
    '/home/m/m1shrecs/db_credentials.php',
    '/home/m/m1shrecs/bank.m1shroom.ru/db.php',
    '/home/m/m1shrecs/bank.m1shroom.ru/db_credentials.php'
];

echo "<b>Проверка путей:</b><br>";
foreach ($paths as $p) {
    $exists = file_exists($p) ? '✅ НАЙДЕН' : '❌ нет';
    echo "$exists → " . htmlspecialchars($p) . "<br>";
}

echo "<br><b>Файлы в public_html:</b><br>";
foreach (scandir(__DIR__) as $f) {
    if ($f != '.' && $f != '..') echo "📄 $f<br>";
}

echo "<br><b>Файлы в папке выше (bank.m1shroom.ru):</b><br>";
$up = dirname(__DIR__);
foreach (scandir($up) as $f) {
    if ($f != '.' && $f != '..') echo "📄 $f<br>";
}

echo "<br><b>Файлы ещё выше (m1shrecs):</b><br>";
$up2 = dirname($up);
foreach (scandir($up2) as $f) {
    if ($f != '.' && $f != '..') echo "📄 $f<br>";
}