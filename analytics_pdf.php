<?php
declare(strict_types=1);
require_once __DIR__ . '/tcpdf/tcpdf.php';

/**
 * Генерирует PDF-отчёт по аналитике за период, зашифрованный паролем.
 * $summary — результат analytics_get_summary() из admin_analytics_api.php
 */
function analytics_generate_pdf(array $summary, string $dateFrom, string $dateTo, string $password): string {
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('M1plus wallet');
    $pdf->SetAuthor('M1plus wallet');
    $pdf->SetTitle('Аналитика M1plus wallet ' . $dateFrom . ' — ' . $dateTo);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);

    // Пароль на ОТКРЫТИЕ файла (user password) — без него PDF не открыть вообще
    $pdf->SetProtection(['print', 'copy'], $password, null, 0, null);

    $pdf->AddPage();

    $pdf->SetFont('dejavusans', 'B', 18);
    $pdf->Cell(0, 12, 'M1plus wallet — Бизнес-отчёт', 0, 1);

    $pdf->SetFont('dejavusans', '', 11);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 8, 'Период: ' . date('d.m.Y', strtotime($dateFrom)) . ' — ' . date('d.m.Y', strtotime($dateTo)), 0, 1);
    $pdf->Cell(0, 8, 'Сформировано: ' . date('d.m.Y H:i'), 0, 1);
    $pdf->Ln(4);

    // ── Сводка ──
    $pdf->SetTextColor(20, 20, 20);
    $pdf->SetFont('dejavusans', 'B', 13);
    $pdf->Cell(0, 9, 'Общая сводка', 0, 1);
    $pdf->SetFont('dejavusans', '', 11);

    $totalAmount = array_sum(array_column($summary['byType'], 'total'));
    $totalCount  = array_sum(array_column($summary['byType'], 'cnt'));

    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell(90, 9, 'Всего операций:', 1, 0, 'L', true);
    $pdf->Cell(0, 9, number_format((float)$totalCount, 0, '.', ' '), 1, 1, 'R', true);
    $pdf->Cell(90, 9, 'Общий оборот:', 1, 0, 'L', true);
    $pdf->Cell(0, 9, number_format($totalAmount, 2, '.', ' ') . ' RUB', 1, 1, 'R', true);
    $pdf->Ln(6);

    // ── Разбивка по типам операций (то же, что "колесо" на сайте) ──
    $pdf->SetFont('dejavusans', 'B', 13);
    $pdf->Cell(0, 9, 'Разбивка по типам операций', 0, 1);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(80, 8, 'Тип операции', 1, 0, 'L', true);
    $pdf->Cell(35, 8, 'Кол-во', 1, 0, 'R', true);
    $pdf->Cell(0, 8, 'Сумма (RUB)', 1, 1, 'R', true);

    $pdf->SetFont('dejavusans', '', 10);
    foreach ($summary['byType'] as $row) {
        $pdf->Cell(80, 7, $row['label'], 1, 0, 'L');
        $pdf->Cell(35, 7, number_format((float)$row['cnt'], 0, '.', ' '), 1, 0, 'R');
        $pdf->Cell(0, 7, number_format((float)$row['total'], 2, '.', ' '), 1, 1, 'R');
    }
    $pdf->Ln(6);

    // ── Временная шкала (столбчатая диаграмма средствами TCPDF) ──
    $pdf->SetFont('dejavusans', 'B', 13);
    $pdf->Cell(0, 9, 'Динамика по периоду', 0, 1);
    $pdf->Ln(2);

    if (!empty($summary['timeline'])) {
        $maxVal = max(array_column($summary['timeline'], 'total')) ?: 1;
        $chartX = 15; $chartY = $pdf->GetY(); $chartW = 180; $chartH = 60;
        $barCount = count($summary['timeline']);
        $barGap = 2;
        $barW = max(2, ($chartW / $barCount) - $barGap);

        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Rect($chartX, $chartY, $chartW, $chartH);

        $x = $chartX + 1;
        foreach ($summary['timeline'] as $point) {
            $h = ($point['total'] / $maxVal) * ($chartH - 4);
            $y = $chartY + $chartH - $h - 2;
            $pdf->SetFillColor(124, 58, 237);
            $pdf->Rect($x, $y, $barW, $h, 'F');
            $x += $barW + $barGap;
        }
        $pdf->SetY($chartY + $chartH + 4);

        // Подписи первой/последней/средней точки, чтобы не загромождать
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(120, 120, 120);
        $first = $summary['timeline'][0]['label'] ?? '';
        $last  = $summary['timeline'][count($summary['timeline']) - 1]['label'] ?? '';
        $pdf->Cell(90, 5, $first, 0, 0, 'L');
        $pdf->Cell(0, 5, $last, 0, 1, 'R');
    } else {
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(0, 8, 'Нет данных за выбранный период', 0, 1);
    }

    $pdf->Ln(8);
    $pdf->SetFont('dejavusans', 'I', 8);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->Cell(0, 6, 'Документ защищён паролем. M1plus wallet, конфиденциально.', 0, 1);

    return $pdf->Output('report.pdf', 'S'); // 'S' — вернуть как строку
}
