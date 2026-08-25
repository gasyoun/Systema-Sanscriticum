<?php

return [

    // Account-scoped key from the OpenRouter dashboard (sk-or-v1-…), NOT a
    // per-model provisioned key: GET /api/v1/credits is account-level.
    'key' => env('OPENROUTER_API_KEY', ''),

    'base_url' => rtrim((string) env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),

    'timeout' => (int) env('OPENROUTER_TIMEOUT', 10),

    // MG 24-08-2026: warn two weeks before projected exhaustion, size the
    // top-up for a full year ahead.
    'alert_within_days' => (int) env('OPENROUTER_ALERT_WITHIN_DAYS', 14),

    'horizon_days' => (int) env('OPENROUTER_HORIZON_DAYS', 365),

    'safety_factor' => (float) env('OPENROUTER_SAFETY_FACTOR', 1.25),

    // Burn-rate baseline needs at least this many days between snapshots.
    'min_baseline_days' => (int) env('OPENROUTER_MIN_BASELINE_DAYS', 7),

    'lookback_days' => (int) env('OPENROUTER_LOOKBACK_DAYS', 28),

    'topup_round_to' => (int) env('OPENROUTER_TOPUP_ROUND_TO', 10),

    'telegram_chat_id' => env(
        'OPENROUTER_TELEGRAM_CHAT_ID',
        env('CABINET_PROBE_TELEGRAM_CHAT_ID', env('ADMIN_TELEGRAM_ID', '')),
    ),

    // Kill-switch for the daily scheduled tick. Default ON.
    'enabled' => (bool) env('OPENROUTER_BALANCE_CHECK_ENABLED', true),
];
