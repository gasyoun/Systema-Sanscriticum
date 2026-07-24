<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | vk-ors derived data directory (H1564)
    |--------------------------------------------------------------------------
    | Directory containing activity_by_month.csv, top_posts_by_likes.csv, etc.
    | from IndologyScholars/vk-ors/data/processed. Prefer storage/app/vk_ors
    | after content:import-vk-ors.
    */
    'vk_ors_data_path' => env('VK_ORS_DATA_PATH', storage_path('app/vk_ors')),

    /*
    |--------------------------------------------------------------------------
    | n8n lecture content engine (H1547–H1549)
    |--------------------------------------------------------------------------
    */
    'clip_rank_n' => (int) env('CONTENT_CLIP_RANK_N', 5),

    /*
    |--------------------------------------------------------------------------
    | Lecture-sourced FAQ knowledge file (H1549 Wave 3 / CAI5 target)
    |--------------------------------------------------------------------------
    |
    | Accepted faq_draft candidates are appended here — NOT into
    | resources/knowledge/faq.md (that file is regenerated from ORS-FAQ wiki).
    | Override in tests with a temp path under storage/framework.
    |
    */
    'lecture_faq_path' => env('CONTENT_LECTURE_FAQ_PATH')
        ?: resource_path('knowledge/faq_from_lectures.md'),
];
