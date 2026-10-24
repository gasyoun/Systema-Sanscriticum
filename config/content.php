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
    | VK/ORS content calendar — Wave 4 forward drafts (H1567)
    |--------------------------------------------------------------------------
    | Cost cap: max ForwardDraftGenerator fills (and therefore CuratorAi calls)
    | per content:fill-forward run, mirrors ArticleDraftGenerator::WEEKLY_LESSON_LIMIT.
    */
    'forward_draft_max_per_run' => (int) env('CONTENT_FORWARD_DRAFT_MAX_PER_RUN', 10),
];
