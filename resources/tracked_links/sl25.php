<?php

declare(strict_types=1);

/*
 * sl25 — пост «ВОПРОС ИЗ КЛАССА · САХИТЬЯ» в @institutsanskrita (пост 2382,
 * правка по D15-D20, протокол Uprava reports/DECISIONS_SAHITYA_INST_POST_GRILL_18-11-2026.md,
 * PR #7922): курс 333 «Санскритская литература (2025)» — прошёл, доступен
 * только в записи (доступ бессрочный, D17). Якорь названия курса в последнем
 * абзаце поста ведёт на ключ; прямой canonical URL не публикуется (D8/D20).
 * Канал inst — свой канал @institutsanskrita (D20); код is занят чужой
 * платной площадкой «Индия Свами» и не переиспользуется. Креатив p —
 * читательское действие поста: страница курса / запись в записи.
 */

$sl25Campaign = 'sanskritskaia_literatura_recorded';

$links['sl25-inst-p'] = [
    'destination' => '/k/sanskritskaia-literatura-2025',
    'utm' => [
        'utm_source' => $channels['inst']['source'],
        'utm_medium' => $channels['inst']['medium'],
        'utm_campaign' => $sl25Campaign,
        'utm_content' => 'sl25_p',
        'utm_term' => 'literature',
    ],
];
