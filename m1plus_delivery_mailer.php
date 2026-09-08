<?php
declare(strict_types=1);
/**
 * m1plus_delivery_mailer.php
 * Запускать по крону раз в день (например в 08:00):
 *   0 8 * * * /usr/bin/php /путь/к/сайту/m1plus_delivery_mailer.php >> /var/log/m1plus_delivery.log 2>&1
 *
 * Находит заказы, у которых delivery_date_actual = сегодня и email ещё не отправлен,
 * шлёт письмо с данными представителя через существующий SMTP-механизм сайта.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/smtp_mailer_bank.php';

$db = getDB();

$today = date('Y-m-d');

$s = $db->prepare(
    "SELECT o.*, u.email, u.username, t.name AS template_name
     FROM card_orders o
     JOIN users u ON u.id=o.user_id
     LEFT JOIN card_templates t ON t.id=o.card_template_id
     WHERE o.delivery_date_actual = ? AND o.email_sent = 0 AND o.status IN ('confirmed','in_production','shipped')"
);
$s->execute([$today]);
$orders = $s->fetchAll();

$sent = 0;

foreach ($orders as $o) {
    if (empty($o['email'])) continue;

    $repName  = $o['representative_name'] ?: 'Будет назначен позже';
    $repPhone = $o['representative_phone'] ?: '—';

    $msg  = "Здравствуйте, {$o['username']}!\n\n";
    $msg .= "Сегодня день доставки вашей карты \"{$o['template_name']}\".\n\n";
    $msg .= "Представитель: {$repName}\n";
    $msg .= "Телефон: {$repPhone}\n";
    $msg .= "Адрес: {$o['delivery_address']}\n";
    $msg .= "Код заказа: {$o['confirmation_code']}\n\n";
    $msg .= "Представитель свяжется с вами для уточнения деталей.\n\n";
    $msg .= "— M1plus wallet";

    $r = wm_smtp_send($o['email'], 'Доставка карты сегодня — M1plus wallet', $msg);

    if (!empty($r['ok'])) {
        $db->prepare('UPDATE card_orders SET email_sent=1, status=? WHERE id=?')
           ->execute(['shipped', $o['id']]);
        $sent++;
    } else {
        error_log('m1plus_delivery_mailer: failed for order ' . $o['id'] . ': ' . ($r['error'] ?? ''));
    }
}

echo "Отправлено писем: {$sent}\n";
