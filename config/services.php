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
        // merchantId торговой точки (15 цифр). Обязателен, если у ИП > 1 retailer'а в Точке,
        // иначе банк сам выбирает первую попавшуюся — а от выбора зависит «адрес сайта» в чеке.
        // Пусто → не передаём поле в payload (старое поведение для одной merchant'ы).
        'merchant_id' => env('TOCHKA_MERCHANT_ID'),
        // Фискализация (Create Payment Operation With Receipt). Реквизиты merchant-specific —
        // подтвердить у бухгалтера/в кабинете Точки до боевой проверки.
        'tax_system_code' => env('TOCHKA_TAX_SYSTEM_CODE'), // СНО: usn_income | usn_income_outcome | osn | patent | ...
        'vat_type' => env('TOCHKA_VAT_TYPE', 'none'),       // ставка НДС позиции; в чеке «не облагается» → none
    ],

    'lesson_sync' => [
        'secret' => env('LESSON_SYNC_SECRET'),
    ],

    'n8n' => [
        'payments_webhook' => env('N8N_PAYMENTS_WEBHOOK_URL'),
        'schedule_sheet_webhook' => env('N8N_SCHEDULE_SHEET_WEBHOOK'),
        // Секрет для входящего вебхука «лид дошёл до шага бота» (POST /api/webhooks/lead-step).
        // Пусто → эндпоинт всегда отвечает 403 (выключен).
        'lead_step_secret' => env('N8N_LEAD_STEP_SECRET'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'admin_id' => env('ADMIN_TELEGRAM_ID'),
        // Чат кураторов: общий group chat, куда добавлен основной бот.
        // Пусто → curator-уведомления отключены (см. App\Services\CuratorNotifier).
        'curators_chat_id' => env('TELEGRAM_CURATORS_CHAT_ID'),
        // Чат маркетологов: group chat для уведомлений о новых лидах.
        // Пусто → уведомления о лидах отключены (см. App\Services\LeadNotifier).
        'marketers_chat_id' => env('TELEGRAM_MARKETERS_CHAT_ID'),
        // Чат онбординга: куда падают «первый вход в кабинет» + еженедельная сводка
        // по не зашедшим. Пусто → отключено (см. App\Services\OnboardingNotifier).
        'onboarding_chat_id' => env('TELEGRAM_ONBOARDING_CHAT_ID'),
    ],

    'vk' => [
        'bot_token' => env('VK_BOT_TOKEN'),
        'group_id' => env('VK_GROUP_ID'),
        'confirm_code' => env('VK_CONFIRM_CODE'),
    ],

    'yandex' => [
        'api_key' => env('YANDEX_API_KEY'),
        'folder_id' => env('YANDEX_FOLDER_ID'),
        'agent_id' => env('YANDEX_AGENT_ID'),
    ],

    'admin' => [
        'email' => env('ADMIN_EMAIL', 'pe4kin.85@mail.ru'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'lecture_builder' => [
        'url' => env('LECTURE_BUILDER_URL', 'http://127.0.0.1:5001'),
        'token' => env('LECTURE_BUILDER_TOKEN'),
        'timeout' => (int) env('LECTURE_BUILDER_TIMEOUT', 180),
        // AI-задачи могут идти долго (особенно correct: N запросов подряд)
        'ai_timeout' => (int) env('LECTURE_BUILDER_AI_TIMEOUT', 600),
    ],

];
