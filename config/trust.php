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

    // Сколько человек прошло курсы / русскоязычных студентов санскрита.
    // Публичная цифра с витрины (карточка вебинара «…5 000 учеников»).
    // TRUST_GRADUATES_COUNT=0 → скрыть плитку/число; пустой env → 5000.
    'graduates_count' => env('TRUST_GRADUATES_COUNT') !== null && env('TRUST_GRADUATES_COUNT') !== ''
        ? ((int) env('TRUST_GRADUATES_COUNT') ?: null)
        : 5000,

    // Издано книг (Bibliotheca Sanscritica) — цифра из
    // custdev/SELLING_LAYOUT_COMPARISON_2026.md (H431), 20 книг / 3834 стр.
    'books_published' => env('TRUST_BOOKS_PUBLISHED') !== null
        ? (int) env('TRUST_BOOKS_PUBLISHED')
        : 20,

    // Суммарный объём серии, стр. null = плитка книг не показывает страницы.
    'books_pages' => env('TRUST_BOOKS_PAGES') !== null
        ? (int) env('TRUST_BOOKS_PAGES')
        : 3834,

    // Собрано краудфандингом, ₽. null = не показывать плитку.
    'crowdfunding_raised_rub' => env('TRUST_CROWDFUNDING_RAISED_RUB') !== null
        ? (int) env('TRUST_CROWDFUNDING_RAISED_RUB')
        : 1_270_000,

    // Доп. пожертвования меценатов сверх краудфандинга, ₽ (H1068 audit §2).
    // null = плитка не упоминает меценатов отдельной строкой.
    'crowdfunding_patron_topup_rub' => env('TRUST_CROWDFUNDING_PATRON_TOPUP_RUB') !== null
        ? (int) env('TRUST_CROWDFUNDING_PATRON_TOPUP_RUB')
        : 700_000,
];
