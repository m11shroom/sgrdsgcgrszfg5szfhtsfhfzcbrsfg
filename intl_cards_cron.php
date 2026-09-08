<?php
declare(strict_types=1);
/**
 * intl_cards_cron.php — автоотмена неоплаченных заказов карт через 7 дней.
 * Крон: 0 * * * * /usr/bin/php /путь/к/сайту/intl_cards_cron.php >> /var/log/intl_cards_cron.log 2>&1
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/intl_cards_setup.php';

$db = getDB();

$stmt = $db->prepare(
    "UPDATE intl_cards SET status='cancelled', cancelled_at=NOW()
     WHERE status='pending_payment' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
);
$stmt->execute();

echo "Отменено просроченных заказов: " . $stmt->rowCount() . "\n";
