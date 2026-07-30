<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Пульс кабинета (H1777 + H1794 hardening)
    |--------------------------------------------------------------------------
    |
    | In-process login + surfaces + optional student smoke + public URLs.
    | History → cabinet_probe_runs. TG + optional healthchecks.io.
    |
    | Deferred (explicit non-goals H1794): Playwright browser smoke;
    | auto-restart php-fpm; public status page.
    |
    */

    'ping_url' => (string) env('CABINET_PROBE_PING_URL', ''),

    // Critical alerts (down/recovery). Default ADMIN_TELEGRAM_ID.
    'telegram_chat_id' => env('CABINET_PROBE_TELEGRAM_CHAT_ID', env('ADMIN_TELEGRAM_ID', '')),

    // Soft-only failures (optional surfaces). Empty → same as critical.
    'telegram_soft_chat_id' => env('CABINET_PROBE_TELEGRAM_SOFT_CHAT_ID', ''),

    // Critical down/recovery re-alert spacing.
    'telegram_cooldown_minutes' => (int) env('CABINET_PROBE_TELEGRAM_COOLDOWN', 60),

    // Soft-only path (guards/hybrid): same failure set is not re-sent every */15.
    // A different soft set (new fingerprint) alerts immediately. --force-alert bypasses.
    'telegram_soft_cooldown_minutes' => (int) env(
        'CABINET_PROBE_TELEGRAM_SOFT_COOLDOWN',
        env('CABINET_PROBE_TELEGRAM_COOLDOWN', 60),
    ),

    'cron' => (string) env('CABINET_PROBE_CRON', '*/15 * * * *'),

    'timeout' => (int) env('CABINET_PROBE_TIMEOUT', 15),

    // Keep last N rows in cabinet_probe_runs (prune after each write).
    'history_keep' => (int) env('CABINET_PROBE_HISTORY_KEEP', 500),

    /*
     * H1914: сверять ресурсные предохранители прода (guards:verify) как ещё одну
     * поверхность этой пробы. Пропажа предохранителя после пересборки LXC иначе
     * выясняется только следующей аварией — а у этой команды уже есть история в
     * cabinet_probe_runs и канал в Telegram. critical-находка = critical-тревога,
     * расхождение = soft. Проверка идёт только там, где включён guards:verify
     * (config/server_guards.php).
     */
    'check_server_guards' => (bool) env('CABINET_PROBE_CHECK_GUARDS', true),

    'error_markers' => [
        'Whoops',
        'Server Error',
        'Fatal error',
        'Stack trace',
        'TokenMismatchException',
        'SQLSTATE[',
    ],

    // Guest GETs (no auth) — critical.
    'public_surfaces' => [
        ['uri' => '/login', 'label' => 'public /login', 'severity' => 'critical'],
        ['uri' => '/online', 'label' => 'public /online (vitrine)', 'severity' => 'critical'],
    ],

    // After manager login — critical unless marked soft.
    'surfaces' => [
        ['name' => 'student.dashboard', 'label' => 'manager /dvaram', 'severity' => 'critical'],
        ['name' => 'student.messages', 'label' => 'manager /messages', 'severity' => 'critical'],
        ['name' => 'student.calendar', 'label' => 'manager /calendar', 'severity' => 'critical'],
        ['name' => 'student.open-lessons', 'label' => 'manager /open-lessons', 'severity' => 'critical'],
        ['uri' => '/admin', 'label' => 'filament /admin', 'panel' => true, 'severity' => 'critical'],
    ],

    // Only when features.cabinet_hybrid = true — soft (hybrid chassis optional).
    'hybrid_surfaces' => [
        ['name' => 'student.library', 'label' => 'hybrid /library', 'severity' => 'soft'],
        ['name' => 'student.progress', 'label' => 'hybrid /progress', 'severity' => 'soft'],
        ['name' => 'student.access', 'label' => 'hybrid /access', 'severity' => 'soft'],
    ],

    // After student login (TEST_STUDENT_*) — critical for student path.
    'student_surfaces' => [
        ['name' => 'student.dashboard', 'label' => 'student /dvaram', 'severity' => 'critical'],
        ['name' => 'student.messages', 'label' => 'student /messages', 'severity' => 'critical'],
        ['name' => 'student.open-lessons', 'label' => 'student /open-lessons', 'severity' => 'critical'],
    ],

    // Ops runbook appended to TG alerts.
    'runbook' => [
        'ssh root@193.232.229.92',
        'cd /var/www/html && php artisan cabinet:probe',
        'systemctl status php8.3-fpm nginx --no-pager',
        'df -h /',
        'tail -n 50 storage/logs/laravel.log',
    ],
];
