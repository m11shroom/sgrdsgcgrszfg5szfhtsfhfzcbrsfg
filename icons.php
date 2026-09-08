<?php
declare(strict_types=1);
// icons.php — общий набор иконок для новостей (dashboard.php и stories_admin.php)

function m1_icons(): array {
    $s = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    return [
        'card'     => "<svg $s><rect x=\"2\" y=\"5\" width=\"20\" height=\"14\" rx=\"3\"/><line x1=\"2\" y1=\"10\" x2=\"22\" y2=\"10\"/></svg>",
        'qr'       => "<svg $s><rect x=\"3\" y=\"3\" width=\"6\" height=\"6\" rx=\"1\"/><rect x=\"15\" y=\"3\" width=\"6\" height=\"6\" rx=\"1\"/><rect x=\"3\" y=\"15\" width=\"6\" height=\"6\" rx=\"1\"/><path d=\"M15 15h2v2h-2zM21 15v2M21 21h-2M15 19v2\"/></svg>",
        'bolt'     => "<svg $s><polygon points=\"13 2 3 14 12 14 11 22 21 10 12 10 13 2\"/></svg>",
        'link'     => "<svg $s><path d=\"M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71\"/><path d=\"M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71\"/></svg>",
        'gift'     => "<svg $s><polyline points=\"20 12 20 22 4 22 4 12\"/><rect x=\"2\" y=\"7\" width=\"20\" height=\"5\"/><line x1=\"12\" y1=\"22\" x2=\"12\" y2=\"7\"/><path d=\"M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z\"/><path d=\"M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z\"/></svg>",
        'shield'   => "<svg $s><path d=\"M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z\"/></svg>",
        'percent'  => "<svg $s><line x1=\"19\" y1=\"5\" x2=\"5\" y2=\"19\"/><circle cx=\"6.5\" cy=\"6.5\" r=\"2.5\"/><circle cx=\"17.5\" cy=\"17.5\" r=\"2.5\"/></svg>",
        'rocket'   => "<svg $s><path d=\"M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z\"/><path d=\"M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z\"/><path d=\"M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0\"/><path d=\"M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5\"/></svg>",
        'star'     => "<svg $s><polygon points=\"12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2\"/></svg>",
        'ruble'    => "<svg $s><circle cx=\"12\" cy=\"12\" r=\"9\"/><path d=\"M10 16V8h3.5a2.5 2.5 0 0 1 0 5H8.5\"/><path d=\"M8.5 15H13\"/></svg>",
        'bell'     => "<svg $s><path d=\"M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9\"/><path d=\"M13.73 21a2 2 0 0 1-3.46 0\"/></svg>",
        'mushroom' => "<svg $s><path d=\"M12 3c-4.5 0-8 3-8 6.5 0 1 .8 1.5 1.8 1.5h12.4c1 0 1.8-.5 1.8-1.5C20 6 16.5 3 12 3z\"/><path d=\"M9.5 11l-.5 7a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2l-.5-7\"/></svg>",
        'sparkles' => "<svg $s><path d=\"M12 3l1.8 4.9L19 9.7l-4.6 2.3L12 17l-2.4-5L5 9.7l5.2-1.8z\"/><path d=\"M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z\"/></svg>",
    ];
}

function m1_icon_labels(): array {
    return [
        'card'=>'Карта','qr'=>'QR','bolt'=>'Молния','link'=>'Ссылка','gift'=>'Подарок',
        'shield'=>'Щит','percent'=>'Процент','rocket'=>'Ракета','star'=>'Звезда',
        'ruble'=>'Рубль','bell'=>'Звонок','mushroom'=>'Гриб','sparkles'=>'Искры',
    ];
}
