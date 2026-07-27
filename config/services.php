<?php

use danog\MadelineProto\API;

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
        // env('...', default) НЕ применяет default к пустой строке — а .env.example
        // держит TOCHKA_API_URL= пустым. Пустой URL → scheme-less запрос → Guzzle
        // RequestException «scheme "" is not allowed» (НЕ ConnectionException, ловля в
        // PaymentController её не перехватывает) → 500 на всём checkout в CI (#271).
        // ?: заставляет пустую строку тоже падать в дефолт. Домен публичный, не секрет.
        'url' => env('TOCHKA_API_URL') ?: 'https://enter.tochka.com/uapi/acquiring/v1.0',
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

    // Курс валют для конвертации выплат преподавателям (PayPal, USD/EUR).
    // exchangerate.host: бесплатный тариф, исторические курсы по дате, есть RUB
    // (в отличие от ECB, который не публикует курс рубля с 01.03.2022).
    // Пусто → авто-подтягивание выключено, курс вводится вручную
    // (см. App\Services\CurrencyRateProvider).
    'exchangerate' => [
        'key' => env('EXCHANGERATE_HOST_KEY'),
    ],

    'n8n' => [
        'payments_webhook' => env('N8N_PAYMENTS_WEBHOOK_URL'),
        'schedule_sheet_webhook' => env('N8N_SCHEDULE_SHEET_WEBHOOK'),
        // Секрет для входящего вебхука «лид дошёл до шага бота» (POST /api/webhooks/lead-step).
        // Пусто → эндпоинт всегда отвечает 403 (выключен).
        'lead_step_secret' => env('N8N_LEAD_STEP_SECRET'),
        // Ежемесячный пост «сейчас идут курсы» в ВК/ТГ: Laravel шлёт готовый
        // payload в этот n8n-вебхук, n8n постит. Секрет уходит в X-Webhook-Secret.
        'monthly_schedule_webhook' => env('N8N_MONTHLY_SCHEDULE_WEBHOOK'),
        'monthly_schedule_secret' => env('N8N_MONTHLY_SCHEDULE_SECRET'),
        // «Нарежь лекцию на клипы» (H1452): Laravel шлёт лекцию + AI-таймкод-спаны
        // в этот n8n-вебхук (сам ffmpeg/VK-аплоад — вне Laravel), секрет — в
        // X-Webhook-Secret. Callback обратно защищён отдельным секретом.
        'clip_extract_webhook' => env('N8N_CLIP_EXTRACT_WEBHOOK'),
        'clip_extract_secret' => env('N8N_CLIP_EXTRACT_SECRET'),
        'clip_callback_secret' => env('N8N_CLIP_CALLBACK_SECRET'),
        // Content engine Wave 2 (H1548): один социальный пост — ВК-стена
        // (с прикреплённым клипом) + зеркало в ТГ-канал — тот же
        // webhook-forward паттерн, что monthly_schedule/clip_extract.
        'social_post_webhook' => env('N8N_SOCIAL_POST_WEBHOOK'),
        'social_post_secret' => env('N8N_SOCIAL_POST_SECRET'),
        // VK/ORS content calendar Wave 5 auto-pilot (H1568): content:publish-due
        // posts one due ContentCalendarSlot at a time — same webhook-forward
        // pattern as monthly_schedule/social_post, VK-only (D10, no TG mirror).
        'calendar_post_webhook' => env('N8N_CALENDAR_POST_WEBHOOK'),
        'calendar_post_secret' => env('N8N_CALENDAR_POST_SECRET'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),

        // Отдельный бот ДЛЯ СТУДЕНЧЕСКОГО КАБИНЕТА: привязка аккаунта, ИИ-куратор
        // и личные уведомления студенту. Заведён отдельно, потому что основной
        // бот занят в служебных чатах (кураторы/маркетологи/онбординг).
        // Пусто → используется основной бот выше (обратная совместимость).
        'student_bot_token' => env('STUDENT_TELEGRAM_BOT_TOKEN'),
        'student_bot_username' => env('STUDENT_TELEGRAM_BOT_USERNAME'),

        // Секрет подписи легаси бот-вебхука (/api/telegram/webhook). Пусто →
        // проверка отключена (см. App\Http\Middleware\VerifyTelegramBotWebhook).
        'bot_webhook_secret' => env('TELEGRAM_BOT_WEBHOOK_SECRET'),
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

    'telegram_support' => [
        'enabled' => (bool) env('TELEGRAM_SUPPORT_ENABLED', false),
        'api_id' => env('TELEGRAM_SUPPORT_API_ID'),
        'api_hash' => env('TELEGRAM_SUPPORT_API_HASH'),
        'session' => env('TELEGRAM_SUPPORT_SESSION', storage_path('app/telegram-support/session.madeline')),
        'history_limit' => (int) env('TELEGRAM_SUPPORT_HISTORY_LIMIT', 50),
        'dialog_limit' => (int) env('TELEGRAM_SUPPORT_DIALOG_LIMIT', 20),
        'profile_backfill_limit' => (int) env('TELEGRAM_SUPPORT_PROFILE_BACKFILL_LIMIT', 20),
        'client_class' => env('TELEGRAM_SUPPORT_CLIENT_CLASS') ?: API::class,
        // Минут без успешного синка, после которых сессия считается протухшей
        // (и SupportObservability, и telegram-support:healthcheck читают отсюда —
        // один порог, не два разных магических числа).
        'stale_after_minutes' => (int) env('TELEGRAM_SUPPORT_STALE_AFTER_MINUTES', 15),
        // Потолок времени одного захода синка (секунды, 0 — без потолка).
        // Зависший заход обязан умереть РАНЬШЕ, чем протухнет замок
        // ->withoutOverlapping() планировщика: иначе на одной MTProto-сессии
        // окажутся два экземпляра, каждый со своим IPC-демоном. Kernel::schedule()
        // выводит TTL замка отсюда, так что инвариант держится сам.
        'sync_timeout_seconds' => (int) env('TELEGRAM_SUPPORT_SYNC_TIMEOUT_SECONDS', 120),
        // Очередь «Техника» — канон в config/support_tech.php; зеркало здесь
        // для чтения из services.telegram_support.* (см. TechnicalIssueRouter).
        'tech_assignee_user_id' => env('SUPPORT_TECH_ASSIGNEE_USER_ID')
            ? (int) env('SUPPORT_TECH_ASSIGNEE_USER_ID')
            : null,
        'tech_group_peers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TELEGRAM_SUPPORT_TECH_GROUP_PEERS', '')),
        ))),
        'username' => ltrim((string) env('TELEGRAM_SUPPORT_USERNAME', ''), '@') ?: null,
    ],

    // Track B harvester (Uprava/docs/DECISIONS_telegram_harvester.md, D1-D3).
    // Reuses the SAME MadelineProto session/credentials as telegram_support
    // (one account, one session) — this block only carries harvester-specific
    // behaviour. Never run telegram-harvest:sync concurrently with
    // telegram-support:sync (a second parallel session triggers AUTH_RESTART).
    'telegram_harvest' => [
        'enabled' => (bool) env('TELEGRAM_HARVEST_ENABLED', false),
        // Own telegram_support_accounts row → cursor namespace separate from 'support'.
        'account_name' => env('TELEGRAM_HARVEST_ACCOUNT_NAME', 'harvester'),
        'history_limit' => (int) env('TELEGRAM_HARVEST_HISTORY_LIMIT', 200),
        // Comma-separated peer list (@usernames or numeric ids); NOT the 20-dialog support cap.
        'peers' => array_values(array_filter(array_map('trim', explode(',', (string) env('TELEGRAM_HARVEST_PEERS', ''))))),
        // Optional JSON file with a managed peer list (merged with the env list).
        'peers_file' => env('TELEGRAM_HARVEST_PEERS_FILE'),
        // OUT-OF-GIT raw store (PII-bearing). Points at the corpus repo's gitignored
        // data/raw/ dir or an external workspace — never inside the Systema repo.
        // `?:` (not env's 2nd arg) so an empty TELEGRAM_HARVEST_STORE_PATH= still
        // falls back to the default; env(key, default) keeps the '' and the writer
        // would then build paths from filesystem root (/corpus/… → mkdir denied).
        'store_path' => env('TELEGRAM_HARVEST_STORE_PATH') ?: storage_path('app/telegram-harvest/raw'),
        // Anti-ban: randomized inter-peer delay bounds in seconds (default 0/0 → no
        // sleep, so tests/CI never pause). Raise on a real host to look less bot-like.
        'peer_delay_min' => (int) env('TELEGRAM_HARVEST_PEER_DELAY_MIN', 0),
        'peer_delay_max' => (int) env('TELEGRAM_HARVEST_PEER_DELAY_MAX', 0),
        // D11 override (Track C, Uprava/docs/DECISIONS_telegram_harvester.md): peers
        // (@username or numeric id) for which the MadelineProto historical backfill
        // ALSO downloads the actual media file (D4's metadata-only default stands
        // unchanged for every other Track B peer).
        'media_download_peers' => array_values(array_filter(array_map('trim', explode(',', (string) env('TELEGRAM_HARVEST_MEDIA_DOWNLOAD_PEERS', ''))))),
    ],

    'vk' => [
        'bot_token' => env('VK_BOT_TOKEN'),
        'group_id' => env('VK_GROUP_ID'),
        'confirm_code' => env('VK_CONFIRM_CODE'),
        // Секрет VK callback (/api/vk-webhook). Пусто → проверка отключена
        // (см. App\Http\Middleware\VerifyVkBotWebhook).
        'callback_secret' => env('VK_CALLBACK_SECRET'),
    ],

    'yandex' => [
        'api_key' => env('YANDEX_API_KEY'),
        'folder_id' => env('YANDEX_FOLDER_ID'),
        'agent_id' => env('YANDEX_AGENT_ID'),
    ],

    // SMS.ru — резервный канал для «спящих» студентов без Telegram/VK/рабочего
    // email (students:send-login-invites). Пусто → SMS-канал отключен (см.
    // App\Services\Messaging\SmsRuChannel::isConfigured()).
    'sms_ru' => [
        'api_id' => env('SMS_RU_API_ID'),
    ],

    // «Мозги» ИИ-куратора TG/VK. Любой OpenAI-совместимый шлюз; по умолчанию —
    // OpenRouter с DeepSeek V3 (быстро/дёшево, контекст ~64k). При блокировке
    // OpenRouter по IP переключаемся на прямой DeepSeek:
    // OPENROUTER_BASE_URL=https://api.deepseek.com, OPENROUTER_MODEL=deepseek-chat
    // (без префикса «deepseek/»), ключ — с platform.deepseek.com.
    // См. App\Services\Bot\CuratorAi.
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('OPENROUTER_MODEL', 'deepseek/deepseek-chat'),
        // $ per 1M tokens, per OpenRouter model — source: openrouter.ai/<model>,
        // checked 12-07-2026 (H763). Unknown models fall back to null spend
        // rather than guessing a price (SupportObservability::llm()).
        'pricing' => [
            'deepseek/deepseek-chat' => [
                'prompt_per_1m' => (float) env('OPENROUTER_PRICE_DEEPSEEK_CHAT_PROMPT', 0.2002),
                'completion_per_1m' => (float) env('OPENROUTER_PRICE_DEEPSEEK_CHAT_COMPLETION', 0.8001),
            ],
        ],
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

    // === ВЕБИНАРЫ ZOOM (Server-to-Server OAuth, issue #78) ===
    // Все три значения создаёт владелец в Zoom Marketplace (S2S OAuth app);
    // в коде секретов нет. Кнопка «Создать Zoom-встречу» в ScheduleResource
    // показывается, только когда все три заданы (ZoomService::isConfigured()).
    'zoom' => [
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        'timeout' => (int) env('ZOOM_TIMEOUT', 30),
        // Secret Token из настроек Event Subscriptions приложения. Подписывает
        // вебхуки (`recording.completed`) и проверку URL. Пусто → проверка
        // подписи выключена (только для локалки; на проде задать ОБЯЗАТЕЛЬНО).
        'webhook_secret' => env('ZOOM_WEBHOOK_SECRET'),
    ],

    // === СОЦИАЛЬНАЯ АВТОРИЗАЦИЯ (Socialite) ===
    // Кнопка провайдера показывается, только если задан его client_id (см.
    // App\Services\SocialAuthService::enabledProviders). Секреты — в .env.
    // VK и Yandex требуют community-драйверов socialiteproviders/*.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],
    'vkontakte' => [
        'client_id' => env('VK_CLIENT_ID'),
        'client_secret' => env('VK_CLIENT_SECRET'),
        'redirect' => env('VK_REDIRECT_URI', '/auth/vkontakte/callback'),
    ],
    'yandex' => [
        'client_id' => env('YANDEX_CLIENT_ID'),
        'client_secret' => env('YANDEX_CLIENT_SECRET'),
        'redirect' => env('YANDEX_REDIRECT_URI', '/auth/yandex/callback'),
    ],

    // === ОПЛАТА ИЗ-ЗА РУБЕЖА (PayPal, полу-интегрированная) ===
    // Автосписания нет: студент платит на me_link и подаёт заявку
    // (PaypalClaimController), админ сверяет вручную. Пусто/выключено → публичные
    // роуты /paypal/* отдают 404, кнопка на чекауте скрыта.
    'paypal' => [
        'enabled' => (bool) env('PAYPAL_CLAIM_ENABLED', false),
        'me_link' => env('PAYPAL_ME_LINK'),      // напр. https://www.paypal.com/paypalme/xxx
        'recipient' => env('PAYPAL_RECIPIENT'),  // email/имя получателя для инструкции студенту
    ],

];
