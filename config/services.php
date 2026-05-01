<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    
    'tochka' => [
        'url' => env('TOCHKA_API_URL', 'https://enter.tochka.com/uapi/acquiring/v1.0'),
        'token' => env('TOCHKA_API_TOKEN'),
        'customer_code' => env('TOCHKA_CUSTOMER_CODE'),
    ],
    
    'lesson_sync' => [
    'secret' => env('LESSON_SYNC_SECRET'),
    ],
    
    'n8n' => [
        'payments_webhook' => env('N8N_PAYMENTS_WEBHOOK_URL'),
    ],

    'telegram' => [
        'bot_token'    => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'admin_id'     => env('ADMIN_TELEGRAM_ID'),
    ],

    'vk' => [
        'bot_token'    => env('VK_BOT_TOKEN'),
        'group_id'     => env('VK_GROUP_ID'),
        'confirm_code' => env('VK_CONFIRM_CODE'),
    ],

    'yandex' => [
        'api_key'   => env('YANDEX_API_KEY'),
        'folder_id' => env('YANDEX_FOLDER_ID'),
        'agent_id'  => env('YANDEX_AGENT_ID'),
    ],

    'admin' => [
        'email'    => env('ADMIN_EMAIL', 'pe4kin.85@mail.ru'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'lecture_builder' => [
        'url'        => env('LECTURE_BUILDER_URL', 'http://127.0.0.1:5001'),
        'token'      => env('LECTURE_BUILDER_TOKEN'),
        'timeout'    => (int) env('LECTURE_BUILDER_TIMEOUT', 180),
        // AI-задачи могут идти долго (особенно correct: N запросов подряд)
        'ai_timeout' => (int) env('LECTURE_BUILDER_AI_TIMEOUT', 600),
    ],

];