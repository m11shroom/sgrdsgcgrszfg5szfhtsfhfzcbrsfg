<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/analytics_setup.php';

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Ошибка сервера: '.$e->getMessage()]);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['ok'=>false,'error'=>'Fatal: '.$err['message']]);
    }
});

$db     = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?: [];

$typeLabels = [
    'card'             => 'Вывод на карту',
    'sbp'              => 'Вывод через СБП',
    'user_out'         => 'Перевод пользователю',
    'user_in'          => 'Входящий перевод',
    'payment_link'     => 'Платёжная ссылка (доход)',
    'payment_sent'     => 'Оплата по ссылке',
    'merchant_payment' => 'Оплата мерчанту',
    'wallet_topup'     => 'Пополнение кошелька',
    'card_order'       => 'Заказ карты',
    'topup'            => 'Пополнение баланса',
];

function analytics_resolve_range(string $period, ?string $from, ?string $to): array {
    $now = new DateTime();
    switch ($period) {
        case 'year':
            $f = (new DateTime('first day of january this year'))->setTime(0,0,0);
            $t = (new DateTime('last day of december this year'))->setTime(23,59,59);
            break;
        case 'month':
            $f = (new DateTime('first day of this month'))->setTime(0,0,0);
            $t = (new DateTime('last day of this month'))->setTime(23,59,59);
            break;
        case 'all':
            $f = new DateTime('2000-01-01');
            $t = $now;
            break;
        case 'custom':
        default:
            $f = $from ? new DateTime($from) : new DateTime('2000-01-01');
            $t = $to ? (new DateTime($to))->setTime(23,59,59) : $now;
            break;
    }
    return [$f->format('Y-m-d H:i:s'), $t->format('Y-m-d H:i:s')];
}

function analytics_get_summary(PDO $db, array $typeLabels, string $dateFrom, string $dateTo): array {
    // Разбивка по типам ("колесо")
    $s = $db->prepare(
        'SELECT type, COUNT(*) AS cnt, SUM(amount) AS total
         FROM transactions WHERE created_at BETWEEN ? AND ?
         GROUP BY type ORDER BY total DESC'
    );
    $s->execute([$dateFrom, $dateTo]);
    $byType = array_map(function($r) use ($typeLabels) {
        return [
            'type'  => $r['type'],
            'label' => $typeLabels[$r['type']] ?? $r['type'],
            'cnt'   => (int)$r['cnt'],
            'total' => (float)$r['total'],
        ];
    }, $s->fetchAll());

    // Временная шкала: по дням если период <= 62 дней, иначе по месяцам
    $days = (strtotime($dateTo) - strtotime($dateFrom)) / 86400;
    if ($days <= 62) {
        $s = $db->prepare(
            "SELECT DATE(created_at) AS d, SUM(amount) AS total FROM transactions
             WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d"
        );
        $s->execute([$dateFrom, $dateTo]);
        $timeline = array_map(fn($r) => ['label'=>date('d.m', strtotime($r['d'])), 'date'=>$r['d'], 'total'=>(float)$r['total']], $s->fetchAll());
    } else {
        $s = $db->prepare(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') AS m, SUM(amount) AS total FROM transactions
             WHERE created_at BETWEEN ? AND ? GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY m"
        );
        $s->execute([$dateFrom, $dateTo]);
        $timeline = array_map(fn($r) => ['label'=>date('m.Y', strtotime($r['m'].'-01')), 'date'=>$r['m'], 'total'=>(float)$r['total']], $s->fetchAll());
    }

    return ['byType' => $byType, 'timeline' => $timeline];
}

/* ─── Получить сводку для графиков на странице ─────────────────────────────── */
if ($action === 'get_summary') {
    $period = $_GET['period'] ?? 'month';
    $from   = $_GET['from'] ?? null;
    $to     = $_GET['to'] ?? null;
    [$dateFrom, $dateTo] = analytics_resolve_range($period, $from, $to);

    $summary = analytics_get_summary($db, $typeLabels, $dateFrom, $dateTo);
    echo json_encode(['ok'=>true, 'summary'=>$summary, 'date_from'=>$dateFrom, 'date_to'=>$dateTo]); exit;
}

/* ─── Отправить отчёт письмом с PDF-вложением ──────────────────────────────── */
if ($action === 'send_report') {
    require_once __DIR__ . '/analytics_pdf.php';
    require_once __DIR__ . '/smtp_mailer_attachment.php';

    $toEmail = trim($body['email'] ?? '');
    $period  = $body['period'] ?? 'custom';
    $from    = $body['from'] ?? null;
    $to      = $body['to'] ?? null;

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok'=>false,'error'=>'Некорректный email получателя']); exit;
    }

    [$dateFrom, $dateTo] = analytics_resolve_range($period, $from, $to);
    $summary = analytics_get_summary($db, $typeLabels, $dateFrom, $dateTo);

    $password = analytics_get_setting($db, 'pdf_password');
    if (!$password) { echo json_encode(['ok'=>false,'error'=>'Пароль отчёта не настроен']); exit; }

    $pdfData = analytics_generate_pdf($summary, substr($dateFrom,0,10), substr($dateTo,0,10), $password);

    // Определяем домен, с которого открыт сайт админом, для адреса отправителя
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = preg_replace('/^www\./', '', strtolower($host));
    if (str_contains($host, 'm1plus.pw')) {
        $fromEmail = 'finance@m1plus.pw';
    } else {
        $fromEmail = 'finance@bank.m1shroom.ru'; // домен по умолчанию (bank.m1shroom.ru и любой другой)
    }

    $totalAmount = array_sum(array_column($summary['byType'], 'total'));
    $totalCount  = array_sum(array_column($summary['byType'], 'cnt'));

    $subject = 'Аналитический отчёт M1plus wallet — ' . date('d.m.Y', strtotime($dateFrom)) . ' — ' . date('d.m.Y', strtotime($dateTo));
    $message = "Здравствуйте!\n\n";
    $message .= "Во вложении — аналитический отчёт M1plus wallet за период ";
    $message .= date('d.m.Y', strtotime($dateFrom)) . " — " . date('d.m.Y', strtotime($dateTo)) . ".\n\n";
    $message .= "Кратко:\n";
    $message .= "— Всего операций: " . number_format($totalCount, 0, '.', ' ') . "\n";
    $message .= "— Общий оборот: " . number_format($totalAmount, 2, '.', ' ') . " ₽\n\n";
    $message .= "Файл защищён паролем. Пароль для открытия документа предоставляется отдельно, ";
    $message .= "по запросу у администрации.\n\n";
    $message .= "С уважением,\nКоманда M1plus wallet";

    $filename = 'm1plus_report_' . date('Ymd', strtotime($dateFrom)) . '-' . date('Ymd', strtotime($dateTo)) . '.pdf';

    $res = wm_smtp_send_attachment($toEmail, $subject, $message, $pdfData, $filename, $fromEmail, 'M1plus wallet — Finance');

    if (!$res['ok']) { echo json_encode(['ok'=>false,'error'=>'Не удалось отправить письмо: ' . $res['error']]); exit; }

    echo json_encode(['ok'=>true, 'from'=>$fromEmail]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);