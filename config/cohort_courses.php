<?php

declare(strict_types=1);

/*
 | H2164 — per-course cohort entitlement registry, generalizing H2105's
 | single-cohort start_chteniya.php into a slug-keyed table. Each entry
 | names ONE Course row (created by ops in Filament — price/dates are
 | human decisions, same as H2105 D6/D10) plus the deploy switch that
 | gates App\Support\CourseCohortEntitlement::hasEntitlement() for that
 | course. No prices are set here.
 |
 | H2105's config/start_chteniya.php + App\Support\StartChteniyaCohort
 | are left untouched (plan §3 default: leave, don't wrap) — this file
 | adds a second, parameterized path for new courses (Nalopakhyana,
 | Subhashita) without touching the "Старт чтения" pilot's own wiring.
 */

return [
    'nalopakhyana' => [
        'course_slug' => env('NALOPAKHYANA_COURSE_SLUG', 'nalopakhyana'),
        'enabled' => (bool) env('NALOPAKHYANA_COHORT_ENABLED', false),
    ],

    'subhashita' => [
        'course_slug' => env('SUBHASHITA_COURSE_SLUG', 'subhashita'),
        'enabled' => (bool) env('SUBHASHITA_COHORT_ENABLED', false),
    ],
];
