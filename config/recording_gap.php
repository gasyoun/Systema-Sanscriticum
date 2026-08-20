<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Recording gap watcher (H3209)
    |--------------------------------------------------------------------------
    |
    | Daily 08:00 Europe/Moscow: yesterday had a schedules row, but no matching
    | published lesson with video/rutube/youtube/recording_attached_at.
    | Alerts reuse cabinet:probe chat ids. Does not re-run n8n ZOOM 1.4.
    |
    | Live workflow is ZOOM 1.4 (Final) + АДМИНКА ТЕСТ (1EIqqNzMl5NNIxST).
    | Inactive twin MtN1h7FdF3JTmrse is not live — do not query it.
    |
    | n8n REST is skip-soft when N8N_API_KEY is empty or the host is unreachable.
    | Fallback one-liner on .91 (SSH skill, not a cron):
    |   sqlite3 /opt/n8n/storage/database.sqlite \
    |     "SELECT id,status,startedAt FROM execution_entity WHERE workflowId='1EIqqNzMl5NNIxST' ORDER BY startedAt DESC LIMIT 1;"
    |
    */

    'telegram_chat_id' => env(
        'RECORDING_GAP_TELEGRAM_CHAT_ID',
        env('CABINET_PROBE_TELEGRAM_CHAT_ID', env('ADMIN_TELEGRAM_ID', '')),
    ),

    'n8n_api_base' => rtrim((string) env('N8N_API_BASE_URL', 'https://context-ai.ru'), '/'),

    // Never commit the value. Empty → skip-soft (table still prints).
    'n8n_api_key' => (string) env('N8N_API_KEY', ''),

    'n8n_workflow_id' => (string) env('N8N_ZOOM_WORKFLOW_ID', '1EIqqNzMl5NNIxST'),

    'n8n_timeout' => (int) env('N8N_API_TIMEOUT', 8),

    // Pipe-separated title fragments. PHP allow-list, never a hardcoded SQL name.
    'skip_title_substrings' => array_values(array_filter(array_map(
        'trim',
        explode('|', (string) env('RECORDING_GAP_SKIP_TITLE_SUBSTRINGS', 'Созвон отдела Заботы')),
    ))),

    'skip_course_ids' => array_values(array_filter(array_map(
        static fn (string $id): int => (int) trim($id),
        explode(',', (string) env('RECORDING_GAP_SKIP_COURSE_IDS', '')),
    ))),
];
