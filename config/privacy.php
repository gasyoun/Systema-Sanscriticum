<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Сроки хранения технических ПДн (152-ФЗ ст. 5 ч. 7)
    |--------------------------------------------------------------------------
    |
    | IP-адреса в журналах активности нужны для антиспама и разбора инцидентов,
    | а не навсегда. Команда privacy:prune обнуляет их старше ip_retention_days.
    | Сами строки (события, просмотры, заявки) остаются — без IP.
    | Плановый запуск — только при features.privacy_prune (дефолт OFF);
    | ручной сухой прогон работает всегда:  php artisan privacy:prune --dry-run
    |
    */

    'ip_retention_days' => (int) env('PRIVACY_IP_RETENTION_DAYS', 180),

    // таблица => [колонка IP, колонка даты]
    'ip_tables' => [
        'activity_events' => ['ip_address', 'created_at'],
        'article_views' => ['ip', 'created_at'],
        'access_attempts' => ['ip', 'created_at'],
        'user_sessions' => ['ip_address', 'created_at'],
        'leads' => ['ip_address', 'created_at'],
        'course_interest_requests' => ['ip_address', 'created_at'],
        // consents сюда НЕ входит: IP — часть доказательства согласия (ст. 9 ч. 3).
    ],
];
