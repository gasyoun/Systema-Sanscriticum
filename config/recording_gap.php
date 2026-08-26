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
    | H3557: задайте RECORDING_GAP_TELEGRAM_CHAT_ID явно — fallback на список
    | CABINET_PROBE (несколько личных чатов) дублирует один алерт во все из них;
    | дедуп хранится в БД (recording_gap_alerts), а не в кэше, который сбрасывает
    | каждый автодеплой.
    |
    | Live workflow is ZOOM 1.4 (Final) + АДМИНКА ТЕСТ (1EIqqNzMl5NNIxST).
    | Inactive twin MtN1h7FdF3JTmrse is not live — do not query it.
    |
    | n8n REST is skip-soft when N8N_API_KEY is empty or the host is unreachable.
    | Fallback one-liner on .91 (SSH skill, not a cron):
    |   sqlite3 /opt/n8n/storage/database.sqlite \
    |     "SELECT id,status,startedAt FROM execution_entity WHERE workflowId='1EIqqNzMl5NNIxST' ORDER BY startedAt DESC LIMIT 1;"
    |
    | The scheduled run NEVER touches n8n. Retries are a separate opt-in lane
    | (23-08-2026 class: executions dying on "Get row(s) in sheet" before any
    | upload): `recordings:gap-watch --retry-failed`, gated by
    | RECORDING_GAP_RETRY_FAILED_ENABLED, safe only when the executed-node set
    | of the failed run is entirely pre-upload (see retry_safe_early_nodes).
    | Late failures (AI Agent / uploads) stay human-only — resume from AI Agent1.
    |
    */

    // Master kill-switch for the --retry-failed lane. Default OFF.
    'retry_enabled' => (bool) env('RECORDING_GAP_RETRY_FAILED_ENABLED', false),

    // Same-day stale pass (MG 24-08-2026): hourly tick flags today's slots
    // whose start is this many hours in the past with still no recording.
    // Kill-switch RECORDING_GAP_STALE_ENABLED (default ON).
    'stale_enabled' => (bool) env('RECORDING_GAP_STALE_ENABLED', true),
    'stale_hours' => (int) env('RECORDING_GAP_STALE_HOURS', 4),

    // Upper bound per invocation — a jam day had three recordings at once.
    'retry_max_per_run' => (int) env('RECORDING_GAP_RETRY_MAX_PER_RUN', 5),

    // Zoom cloud recording lands hours after the lesson; widen the execution
    // startedAt window by this many days past the lesson-date range.
    'retry_window_slack_days' => (int) env('RECORDING_GAP_RETRY_WINDOW_SLACK_DAYS', 1),

    // Nodes whose execution proves nothing was downloaded/uploaded yet, so a
    // full replay cannot duplicate YouTube/Rutube. Anything outside this set
    // in runData marks the execution unsafe for full retry.
    'retry_safe_early_nodes' => [
        'ZOOM',
        'Switch',
        'Switch1',
        'Switch2',
        'Code in JavaScript',
        'Code in JavaScript1',
        'Code in JavaScript2',
        'Code in JavaScript5',
        'Respond to Webhook',
        'Respond to Webhook1',
        'Respond to Webhook2',
        'Get row(s) in sheet',
        'Get row(s) in sheet1',
    ],

    'telegram_chat_id' => env(
        'RECORDING_GAP_TELEGRAM_CHAT_ID',
        env('CABINET_PROBE_TELEGRAM_CHAT_ID', env('ADMIN_TELEGRAM_ID', '')),
    ),

    // MG 24-08-2026: when a recording is stuck past the morning check, the
    // care department must hear it too, not only the ops pulse. Same payload,
    // same 08:00 run. The sending bot (services.telegram.bot_token) has to be
    // a member of that chat; empty = admin-only (previous behaviour).
    'care_telegram_chat_id' => env('RECORDING_GAP_CARE_TELEGRAM_CHAT_ID', ''),

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
