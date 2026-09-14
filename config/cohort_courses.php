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
 |
 | H2168 adds the reading half of each entry: `title` (cabinet heading)
 | and `packs` — the course's reading packs IN COURSE ORDER, resolved by
 | App\Support\CourseReadingPacks against the vendored H2165 freeze under
 | resources/data/nala_subhashita/. A slug absent from that freeze is
 | ignored rather than 500-ing, and a course with no `packs` key simply
 | has no reader (404), so this stays inert until ops enables the course.
 */

return [
    'nalopakhyana' => [
        'course_slug' => env('NALOPAKHYANA_COURSE_SLUG', 'nalopakhyana'),
        'enabled' => (bool) env('NALOPAKHYANA_COHORT_ENABLED', false),
        'title' => 'Налопакхьяна',
        // Narrative order, MBh 3.50 -> 3.51 -> 3.52. H2168 ambiguity call:
        // the order is fixed and numbered in the cabinet, but no hard
        // sequential LOCK is invented while H2170's transcript analysis is
        // unlanded — see App\Support\CourseReadingPacks' docblock.
        'packs' => ['nala-1', 'nala-2', 'nala-3'],
    ],

    'subhashita' => [
        'course_slug' => env('SUBHASHITA_COURSE_SLUG', 'subhashita'),
        'enabled' => (bool) env('SUBHASHITA_COHORT_ENABLED', false),
        'title' => 'Субхашита',
        'packs' => ['subhashita-beginner'],
    ],

    /*
     | H4162 W1 (06-09-2026): reference Kochergina cohort, flipped ON by
     | MG's explicit ruling in chat. The W0 evidence packet
     | (docs/EVIDENCE_KOCHERGINA_COHORT_W0_H4162_06-09-2026.md) selected
     | gr.61 (course_id 434, group 131) and drafted this entry with
     | enabled=false; this flip only adds the slug-keyed entitlement
     | surface — the course's real access path (ordinary checkout →
     | grantAccess() → groups) is untouched, and with no `packs` key the
     | reader surface stays absent (404), so nothing student-visible
     | changes. Rollback: env line off → config:cache.
     */
    'kochergina-gr61' => [
        'course_slug' => env('KOCHERGINA_GR61_COHORT_SLUG', 'grammatika-po-kocerginoi-gr61'),
        'enabled' => (bool) env('KOCHERGINA_GR61_COHORT_ENABLED', false),
        'title' => 'Грамматика по Кочергиной гр.61',
    ],
];
