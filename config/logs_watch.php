<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Лог-сторож 500-класса (H4648, инцидент 10–13.09.2026)
    |--------------------------------------------------------------------------
    |
    | 43 ошибки / 9 студентов / ~3 дня: фатал класса «CI green, прод 500»
    | лежал в storage/logs/laravel-YYYY-MM-DD.log, и никто это не читал.
    | `logs:error-watch` сканирует СЕГОДНАШНИЙ daily-лог + вчерашний до
    | LOGS_WATCH_YESTERDAY_UNTIL_UTC (overlap через ротацию: ночной всплеск
    | не теряется, если сторож сам лежал ночью), группирует production.ERROR
    | по классу исключения + месту в стектрейсе и шлёт TG soft при всплеске
    | (порог одинаковых находок в пределах одного часа).
    |
    | Команда НИЧЕГО не пишет в прод-данные: читает логи, ведёт свой
    | state-файл (анти-spam, H2335-семантика) и шлёт TG. Без канала —
    | громкий warning в выводе, НЕ тихий пропуск.
    |
    */

    'enabled' => (bool) env('LOGS_WATCH_ENABLED', true),

    // Пусто → storage_path('logs'). Для тестов.
    'log_dir' => (string) env('LOGS_WATCH_DIR', ''),

    // Какие env-каналы лога считаем (Laravel пишет `[ts] {env}.{LEVEL}:`).
    'environments' => ['production'],

    // Какие уровни ловим. ERROR покрывает необработанные исключения (500).
    'levels' => ['ERROR'],

    // ≥ N одинаковых (класс + место) в пределах одного часа → алерт.
    'threshold_per_hour' => (int) env('LOGS_WATCH_THRESHOLD', 3),

    // Вчерашний daily-лог подключается только до этого момента UTC
    // (Europe/Moscow: 06:00 UTC = 09:00 local). Overlap-окно через ротацию
    // файлов: burst на стыке суток виден сторожем, даже если ночные прогоны
    // сорвались. Пусто/0 → вчерашний файл не сканируется.
    'yesterday_until_utc' => (string) env('LOGS_WATCH_YESTERDAY_UNTIL_UTC', '06:00'),

    // TG-канал: дефолт — существующий soft-канал пробы (критичный — отдельная
    // история, а всплеск ошибок в логах сначала soft). Пусто → команда КРИЧИТ
    // в выводе, но не шлёт и не ставит sticky-состояние.
    'telegram_chat_id' => (string) env(
        'LOGS_WATCH_TELEGRAM_CHAT_ID',
        env('CABINET_PROBE_TELEGRAM_SOFT_CHAT_ID', env('CABINET_PROBE_TELEGRAM_CHAT_ID', env('ADMIN_TELEGRAM_ID', ''))),
    ),

    // H2335-семантика: тот же класс молчит до зелёного, reminder раз в N часов.
    // 0 = один раз до зелёного (тише всех).
    'reminder_hours' => (int) env('LOGS_WATCH_REMINDER_HOURS', 24),

    // Durable state (переживает optimize:clear). Пусто →
    // storage/app/logs_error_watch_state.json. Тесты переопределяют.
    'state_path' => (string) env('LOGS_WATCH_STATE_PATH', ''),

    // Сколько строк находок максимум в одном TG-сообщении.
    'max_alert_lines' => (int) env('LOGS_WATCH_MAX_ALERT_LINES', 8),

    'timeout' => (int) env('LOGS_WATCH_TIMEOUT', 15),

    // Известный хронический шум (H4879, 15-09-2026,
    // docs/SERVER_SOFT_ALERT_PLAYBOOK.md, строка 2026-09-15 00:10-00:20 UTC):
    // подстроки сообщения, которые НИКОГДА не считаются во всплеск, даже если
    // 'levels' выше когда-нибудь включит их уровень. Флуд мёртвых peer'ов —
    // data-quality, не инцидент; полный текст остаётся в самой строке лога,
    // просто не участвует в подсчёте порога. '|' — разделитель нескольких
    // подстрок в env.
    'allowlist_patterns' => array_values(array_filter(array_map(
        'trim',
        explode('|', (string) env(
            'LOGS_WATCH_ALLOWLIST_PATTERNS',
            'This peer is not present in the internal peer database'
        ))
    ))),
];
