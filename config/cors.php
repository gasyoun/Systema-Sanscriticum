<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    | H3311: allowlist через env, по умолчанию ПУСТО = cross-origin запрещён.
    | Same-origin запросам CORS не нужен — фронт сайта продолжает работать.
    | Локальная разработка добавляет localhost-происхождения; прод — свои
    | домены (samskrtam.ru/samskrte.ru/staging). Пример:
    |   CORS_ALLOWED_ORIGINS=https://samskrtam.ru,https://samskrte.ru,http://localhost:3000
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
