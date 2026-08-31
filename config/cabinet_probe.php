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

    // Soft-only path (guards/hybrid). H1941 used minute cooldown; H2335 sticky:
    // same *normalized* soft class is not re-sent until green, optional reminder.
    // A different soft class alerts immediately. --force-alert bypasses.
    // Legacy env CABINET_PROBE_TELEGRAM_SOFT_COOLDOWN is ignored for gating
    // (kept in .env.example as comment only) — use reminder hours instead.
    'telegram_soft_cooldown_minutes' => (int) env(
        'CABINET_PROBE_TELEGRAM_SOFT_COOLDOWN',
        env('CABINET_PROBE_TELEGRAM_COOLDOWN', 60),
    ),

    // Hours before re-nudging the *same* soft/HTTP class while still open (H2335 / H3197).
    // 0 = once until green (quietest). Default 24 ≈ max one TG/day per class.
    'telegram_soft_reminder_hours' => (int) env('CABINET_PROBE_TELEGRAM_SOFT_REMINDER_HOURS', 24),

    // Durable TG state path. Empty → storage/app/cabinet_probe_tg_state.json
    // (survives optimize:clear). Tests override this; no extra .env key.
    'tg_state_path' => '',

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

    /*
     * 28-08-2026 Tochka TLS incident: платежи лежали четыре дня (25–28-08,
     * cURL error 60 — «Точка» сменила цепочку на Russian Trusted Root CA,
     * отсутствовавший в серверном CA-бандле), а ни одна проверка этого не
     * видела: in-process surfaces ходят на localhost, guards смотрят в файлы
     * и systemd, outbound-TLS не смотрел никто. Проба бьёт в API эквайринга
     * снаружи: ЛЮБОЙ HTTP-ответ (неавторизованный 401/403/404 — нормален)
     * значит, что TLS жив; critical только на падении соединения.
     */
    'check_payment_tls' => (bool) env('CABINET_PROBE_CHECK_PAYMENT_TLS', true),
    'payment_probe_url' => (string) env(
        'CABINET_PROBE_PAYMENT_URL',
        'https://enter.tochka.com/uapi/acquiring/v1.0/payments_with_receipt',
    ),

    /*
     * H37xx: синтетическая проба загрузки ДЗ — «постоянно ломается подача ДЗ»
     * повторялась 3 раза (молчаливый 64MB-порог, OOM сборки PDF, дубли при
     * зависании), а ни одна проверка не трогала реальный upload-путь: все
     * surfaces выше — GET. Пишет один тестовый файл через ту же
     * HomeworkService::recordSubmission(..., finalize: false), что и форма
     * студента, и удаляет его в finally — идемпотентно на каждом прогоне.
     *
     * ВАЖНО: наводить на ВЫДЕЛЕННЫЙ sandbox-урок (никогда на реальный урок
     * настоящего курса) — is_free=true (без грантов/оплаты), homework_enabled=
     * true, homework_closed_at=null. Пусто по умолчанию — проверка тихо
     * пропускается, пока урок не заведён и не назван явно (как TEST_STUDENT_*).
     *
     * НЕ покрывает: php.ini/nginx client_max_body_size на самом проде (это
     * in-process вызов внутри artisan-процесса пробы, не настоящий HTTP через
     * nginx/php-fpm) и CSRF/HTTP-валидацию формы — тот класс инцидента
     * (несовпадение upload_max_filesize/post_max_size с client_max_body_size)
     * эта проба не ловит; она ловит регрессии в самом коде записи (роут/
     * конфиг/диск/БД). Чёрный ящик через реальный HTTP — сознательно deferred,
     * как Playwright выше по файлу.
     */
    'check_homework_upload' => (bool) env('CABINET_PROBE_CHECK_HOMEWORK_UPLOAD', true),
    'homework_probe_course_slug' => (string) env('CABINET_PROBE_HOMEWORK_COURSE', ''),
    'homework_probe_lesson_id' => (int) env('CABINET_PROBE_HOMEWORK_LESSON_ID', 0),

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

    /*
     * H2148 C: outbound soft-alert webhook (default OFF).
     * When set, cabinet:probe POSTs JSON after a soft-only alert passes cooldown
     * (same gate as soft TG). n8n / GitHub / agent runner consumes it.
     * See docs/ops/SOFT_ALERT_WEBHOOK.md.
     */
    'soft_webhook_url' => (string) env('SOFT_ALERT_WEBHOOK_URL', ''),
    'soft_webhook_secret' => (string) env('SOFT_ALERT_WEBHOOK_SECRET', ''),
    'soft_webhook_timeout' => (int) env('SOFT_ALERT_WEBHOOK_TIMEOUT', 8),
];
