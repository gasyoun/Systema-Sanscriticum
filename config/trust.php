<?php

/*
|--------------------------------------------------------------------------
| Доверие / social proof на публичной витрине (H323)
|--------------------------------------------------------------------------
| Цифры полосы доверия («с N года», выпускники). Env-backed — не хардкодить
| в шаблонах/сервисах. graduates_count = null → плитка выпускников скрыта,
| пока владелец не подтвердит честную цифру (никаких выдуманных чисел).
*/

return [
    // Год основания школы — «Преподаём санскрит с 2005 года».
    'since_year' => (int) env('TRUST_SINCE_YEAR', 2005),

    // Сколько человек прошло курсы. null = не показывать, пока нет честной цифры.
    'graduates_count' => env('TRUST_GRADUATES_COUNT') !== null
        ? (int) env('TRUST_GRADUATES_COUNT')
        : null,

    // Издано книг (Bibliotheca Sanscritica) — цифра из
    // custdev/SELLING_LAYOUT_COMPARISON_2026.md (H431), 20 книг / 3834 стр.
    'books_published' => env('TRUST_BOOKS_PUBLISHED') !== null
        ? (int) env('TRUST_BOOKS_PUBLISHED')
        : 20,

    // Собрано краудфандингом, ₽. null = не показывать плитку.
    'crowdfunding_raised_rub' => env('TRUST_CROWDFUNDING_RAISED_RUB') !== null
        ? (int) env('TRUST_CROWDFUNDING_RAISED_RUB')
        : 1_270_000,
];
