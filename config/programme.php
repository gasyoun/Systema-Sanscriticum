<?php

declare(strict_types=1);

return [
    /*
     | Programme shells (H2441 playlist + H2442 graph).
     | Category slug is preferred when the row exists; prod samskrte.ru has no
     | `hindi` category (measured 13-08-2026), so course_ids are the H2333-stable
     | fallback (416 гр.2 / 356 гр.3 / 366 гр.5). Title/slug likes catch future
     | shells. Predecessor walk (366→356, optional 356→416) is Filament + the
     | H2333 migration — do not auto-write 356→416 (parallel year-groups).
     | Graph: App\Services\ProgrammeShellGraph. Docs:
     | docs/HINDI_PROGRAMME_SHELL_GRAPH_2026.md
     */
    'hindi' => [
        'category_slug' => env('HINDI_PROGRAMME_CATEGORY_SLUG', 'hindi'),
        'course_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('HINDI_PROGRAMME_COURSE_IDS', '416,356,366')),
        ))),
        'title_likes' => ['%хинди%'],
        'slug_likes' => ['%hindi%', '%xindi%'],
    ],
];
