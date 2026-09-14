<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | H4672 — money-axis SLI (mocked webhook, no live bank charge)
    |--------------------------------------------------------------------------
    |
    | Daily synthetic-pay probe: signs a Tochka-shaped webhook with a
    | dedicated SLI keypair (never the real bank key) and POSTs it at the
    | real /api/webhooks/tochka route against a dedicated hidden test user/
    | course, then asserts grant + revokes access again. Hourly reconcile:
    | read-only silent-grant (H2085) + webhook success-rate check.
    |
    */

    // PEM private key used to SIGN the synthetic webhook. Pair of
    // services.tochka.sli_webhook_public_key. Generate once per env
    // (openssl genrsa) — never the real Tochka key, never committed.
    'webhook_private_key' => env('MONEY_SLI_WEBHOOK_PRIVATE_KEY', ''),

    // Public URL the synthetic webhook is POSTed to (real HTTP round trip,
    // proxy/TLS included) — defaults to this app's own public URL.
    'webhook_url' => env('MONEY_SLI_WEBHOOK_URL') ?: rtrim((string) env('APP_URL', ''), '/').'/api/webhooks/tochka',

    'http_timeout_seconds' => (int) env('MONEY_SLI_HTTP_TIMEOUT', 15),

    // Flap tolerance for the daily probe (mirrors probeOutboundPaymentTls,
    // CHANGELOG 02-09-2026): a single transient failure must not page:
    // retry up to this many attempts, short pause between, before declaring
    // the run failed and alerting.
    'attempts' => max(1, (int) env('MONEY_SLI_ATTEMPTS', 3)),
    'attempt_pause_seconds' => max(0, (int) env('MONEY_SLI_ATTEMPT_PAUSE_SECONDS', 2)),

    // Dedicated synthetic fixtures (H1946 convention: name prefixed with the
    // handoff id, email @example.invalid — never @example.com, that's Faker's).
    'fixture' => [
        'user_email' => 'h4672-sli-probe@example.invalid',
        'user_name' => 'H4672 SLI probe',
        'course_slug' => 'h4672-sli-probe',
        'course_title' => 'H4672 SLI probe (internal, never sold)',
        'group_slug' => 'h4672-sli-probe',
        'group_name' => 'H4672 SLI probe',
        // Synthetic payment rows older than this are pruned by the daily
        // command itself (append-only ledger otherwise grows forever).
        'retain_days' => (int) env('MONEY_SLI_FIXTURE_RETAIN_DAYS', 30),
    ],

    // Better Stack heartbeats (drop-in healthchecks-compatible contract, see
    // docs/UPTIME_BETTERSTACK_MONITORING.md). Empty → fail-open, no ping.
    'daily_ping_url' => (string) env('MONEY_SLI_DAILY_PING_URL', ''),
    'hourly_ping_url' => (string) env('MONEY_SLI_HOURLY_PING_URL', ''),

    // Two-tier TG paging per MG ruling 14-09-2026 («деньги и прод → Better
    // Stack page + TG»): money-axis alerts always go to the critical chat,
    // never soft-only. Empty → falls back to ADMIN_TELEGRAM_ID.
    'telegram_chat_id' => env('MONEY_SLI_TELEGRAM_CHAT_ID', env('ADMIN_TELEGRAM_ID', '')),

    // Re-alert spacing while the SAME failure class stays open (mirrors
    // cabinet_probe.telegram_soft_reminder_hours) — avoids one TG per hourly
    // tick for a standing incident.
    'telegram_reminder_hours' => (int) env('MONEY_SLI_TELEGRAM_REMINDER_HOURS', 4),

    // Durable TG cooldown state path. Empty → storage/app/money_sli_tg_state.json.
    'tg_state_path' => '',

    // TSV daily-log sink (mission: "SLI-строки в money_sli_daily.tsv").
    'tsv_path' => storage_path('app/money_sli/money_sli_daily.tsv'),

    // Hourly reconcile window + thresholds.
    'reconcile' => [
        'window_hours' => (int) env('MONEY_SLI_RECONCILE_WINDOW_HOURS', 1),
        // Below this applied/total ratio (with enough volume) → alert.
        'webhook_success_rate_floor' => (float) env('MONEY_SLI_WEBHOOK_SUCCESS_RATE_FLOOR', 0.5),
        // Minimum deliveries in the window before the ratio is trusted
        // (avoids paging on "1 of 1 rejected" noise at low traffic).
        'min_sample' => (int) env('MONEY_SLI_MIN_SAMPLE', 3),
        // Silent-grant (H2085) lookback — wider than the reconcile window so a
        // grant that lagged behind its webhook by up to this many hours is
        // still caught before it pages as a false positive.
        'silent_grant_lookback_hours' => (int) env('MONEY_SLI_SILENT_GRANT_LOOKBACK_HOURS', 6),
    ],
];
