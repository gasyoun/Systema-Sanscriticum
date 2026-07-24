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
];
