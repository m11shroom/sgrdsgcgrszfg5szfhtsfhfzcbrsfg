<?php
/**
 * test_smtp.php — проверка отправки письма (m1plus Bank).
 * Открой: wallet.m1plus.ru/test_smtp.php?to=твой@email.com
 * УДАЛИ ПОСЛЕ ПРОВЕРКИ!
 *
 * Подключи свой основной конфиг чтобы загрузились SMTP-константы из db_credentials.php.
 * Если конфиг называется иначе — поправь строку require ниже.
 */
header('Content-Type: text/plain; charset=utf-8');

// Подключаем db_credentials напрямую (выше корня)
$cred = dirname(__DIR__) . '/db_credentials.php';
if (file_exists($cred)) {
    require_once $cred;
} else {
    echo "❌ db_credentials.php не найден по пути: $cred\n";
    exit;
}

require_once 'smtp_mailer_bank.php';

echo "=== ТЕСТ SMTP (m1plus Bank) ===\n\n";

if (!defined('SMTP_HOST')) {
    echo "❌ SMTP-константы не определены в db_credentials.php\n";
    echo "   Добавь блок из ADD_TO_db_credentials.php\n";
    exit;
}

echo "✓ SMTP-настройки загружены\n";
echo "  Host: " . SMTP_HOST . ":" . SMTP_PORT . "\n";
echo "  User: " . SMTP_USER . "\n";
echo "  From: " . SMTP_FROM . "\n\n";

$to = isset($_GET['to']) ? $_GET['to'] : '';
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "Укажи получателя: test_smtp.php?to=твой@email.com\n";
    exit;
}

echo "Отправляю тестовое письмо на: $to ...\n\n";

$r = wm_smtp_send($to, 'M1plus wallet — тест SMTP', "Это тестовое письмо.\n\nЕсли ты его получил — SMTP работает!");

if ($r['ok']) {
    echo "✅ УСПЕХ! Письмо отправлено с " . SMTP_FROM . "\n";
    echo "   Проверь почту (и папку Спам).\n";
} else {
    echo "❌ ОШИБКА: " . $r['error'] . "\n\n";
    echo "Частые причины:\n";
    echo "- Неверный пароль ящика\n";
    echo "- Ящик " . SMTP_USER . " не создан в панели Beget\n";
    echo "- Порт 465 заблокирован (попробуй SMTP_PORT 2525 и SMTP_SECURE '' в db_credentials.php)\n";
}
