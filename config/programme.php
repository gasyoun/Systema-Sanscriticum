<?php

declare(strict_types=1);

return [
    /*
     | Hindi programme shells (H2441). Category slug is preferred when the
     | row exists; prod samskrte.ru has no `hindi` category (measured
     | 13-08-2026), so course_ids are the H2333-stable fallback (416 / 356 / 366).
     | Title/slug heuristic (`хинди` / hindi / xindi) catches future shells.
     */
    'hindi' => [
        'category_slug' => env('HINDI_PROGRAMME_CATEGORY_SLUG', 'hindi'),
        'course_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('HINDI_PROGRAMME_COURSE_IDS', '416,356,366')),
        ))),
    ],
];
