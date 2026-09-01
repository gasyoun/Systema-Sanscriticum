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
        'open_banking_url' => env('TOCHKA_OPEN_BANKING_URL') ?: 'https://enter.tochka.com/uapi/open-banking/v1.0',
        'balance_cache_seconds' => (int) env('TOCHKA_BALANCE_CACHE_SECONDS', 60),
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
        // Header Auth on n8n «АДМИНКА+ТАБЛИЦА ОПЛАТ» webhook (H1960 / C03).
        // Sent as X-Webhook-Secret; empty → caller omits header (dev only).
        'payments_webhook_secret' => env('N8N_PAYMENTS_WEBHOOK_SECRET'),
        'schedule_sheet_webhook' => env('N8N_SCHEDULE_SHEET_WEBHOOK'),
        // Секрет для входящего вебхука «лид дошёл до шага бота» (POST /api/webhooks/lead-step).
        // Пусто → эндпоинт всегда отвечает 403 (выключен).
        'lead_step_secret' => env('N8N_LEAD_STEP_SECRET'),
        // Секрет входящего вебхука баллов экзамена «Санка» из Google-таблицы
        // (POST /api/webhooks/exam-scores). Пусто → 403 (выключен).
        'exam_scores_secret' => env('N8N_EXAM_SCORES_SECRET'),
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

    // Входящий email-канал поддержки (H3462): секрет пути вебхука
    // POST /api/webhooks/inbound-email/{secret}. Пусто → эндпоинт всегда 403
    // (выключен, fail-closed — как verify.max.magnet).
    'inbound_email' => [
        'webhook_secret' => env('INBOUND_EMAIL_WEBHOOK_SECRET'),
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

        // Оператор дожима (решение MG 24-08-2026): числовой chat_id получателя
        // будничной 10:00 сводки dozhim:notify-operator. Пусто → фолбэк на
        // первого User роли manager с привязанным telegram_id (см.
        // DozhimNotifyOperatorCommand::recipient()).
        'dozhim_operator_tg_chat_id' => env('DOZHIM_OPERATOR_TG_CHAT_ID'),
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
        // Досыл ответов куратора идёт ВНУТРИ захода синка (см.
        // PendingSupportReplyDrainer): из воркера очереди MadelineProto не
        // работает в принципе. batch — потолок сообщений за заход, чтобы досыл
        // не съедал минутный слот; max_attempts — после скольких неудач
        // сообщение помечается недоставленным и ждёт ручного досыла, вместо
        // того чтобы перемалывать сессию на каждом заходе.
        'pending_delivery_batch' => (int) env('TELEGRAM_SUPPORT_PENDING_DELIVERY_BATCH', 20),
        'pending_delivery_max_attempts' => (int) env('TELEGRAM_SUPPORT_PENDING_DELIVERY_MAX_ATTEMPTS', 3),
        // Через сколько минут ожидающий доставки ответ куратора считается
        // подзависшим и получает в ленте формулировку «дольше обычного».
        // Держите заметно выше периода синка (минута): доставка теперь идёт его
        // заходами, и слишком низкий порог красил бы «дольше обычного» ответы,
        // которые просто ждут своей очереди. Это оттенок текста, а не отдельное
        // состояние доставки.
        'pending_stale_after_minutes' => (int) env('TELEGRAM_SUPPORT_PENDING_STALE_AFTER_MINUTES', 15),
        // Потолок времени одного захода синка (секунды, 0 — без потолка).
        // Зависший заход обязан умереть РАНЬШЕ, чем протухнет замок
        // ->withoutOverlapping() планировщика: иначе на одной MTProto-сессии
        // окажутся два экземпляра, каждый со своим IPC-демоном. Kernel::schedule()
        // выводит TTL замка отсюда, так что инвариант держится сам.
        'sync_timeout_seconds' => (int) env('TELEGRAM_SUPPORT_SYNC_TIMEOUT_SECONDS', 120),
        // After a watchdog kill the daemon is reaped. Immediate retry cold-starts
        // the same DC and, on a transient stall, dies at 120 s again (H2988:
        // 18 kills / ~100 min). 0 disables the skip. Does not raise the ceiling.
        'sync_timeout_cooldown_seconds' => (int) env('TELEGRAM_SUPPORT_SYNC_TIMEOUT_COOLDOWN_SECONDS', 600),
        // H3380: текст ack'а автоответчика («приняли, ответим») и cooldown-окно
        // в часах: пока в чате есть исходящее моложе окна, ack не шлётся.
        'auto_ack_text' => env(
            'TELEGRAM_SUPPORT_AUTO_ACK_TEXT',
            "Намасте!\n\nПолучили ваше сообщение и уже разбираемся. Ответим в течение рабочего дня.",
        ),
        'auto_ack_cooldown_hours' => (int) env('TELEGRAM_SUPPORT_AUTO_ACK_COOLDOWN_HOURS', 6),
        // H3380 v2.2: входящие старше этого возраста (часов) не порождают ни
        // автоответа, ни подсказки — первичный history-забор новой сессии
        // приносит месяцы старых сообщений, реагировать на них нельзя.
        //
        // H3765 A2: потолок расщеплён надвое, потому что цена ошибки у половин
        // разная. Автоответ уходит СТУДЕНТУ — просроченный ответ на вопрос
        // двухдневной давности хуже молчания, поэтому строгие 6 ч остаются.
        // Подсказка уходит КУРАТОРУ и студенту не видна — здесь худший исход
        // это лишняя строка в чате куратора, поэтому окно шире (24 ч) и
        // переживает перебой приёма (перезапуск сессии, watchdog-убийство),
        // после которого сутки вопросов иначе уходили бы в тишину.
        //
        // Перепись A1 (support:ingest-latency-report, 31-08-2026) показала, что
        // расширение вернёт немного: из 1072 stale-пропусков за 30 дней в полосе
        // 6–24 ч лежат 30, остальное — дозабор истории возрастом от суток до
        // трёх лет. Ставка здесь на устойчивость к перебоям, а не на объём.
        'auto_reply_max_age_hours' => (int) env('TELEGRAM_SUPPORT_AUTO_REPLY_MAX_AGE_HOURS', 6),
        'hint_max_age_hours' => (int) env('TELEGRAM_SUPPORT_HINT_MAX_AGE_HOURS', 24),
        // H3380 v2: тёплый ответ на чистое приветствие («Намасте!») — один раз
        // за то же cooldown-окно чата. Благодарности молча не отвечаются.
        'auto_greeting_text' => env(
            'TELEGRAM_SUPPORT_AUTO_GREETING_TEXT',
            "Намасте!\n\nРады вас видеть. Напишите, по какому курсу или расписанию вопрос — с радостью поможем.",
        ),
        // H3542: приглашение связать Telegram с кабинетом (support_dm_link_invite).
        // {url} заменяется на одноразовую capability-ссылку; текст — выверенный
        // канреплай, править только осознанно (не свободная генерация).
        'link_invite_text' => env(
            'TELEGRAM_SUPPORT_LINK_INVITE_TEXT',
            "Намасте!\n\nВаш вопрос дошёл до нас. Бот отвечает мгновенно тем, чей Telegram связан с личным кабинетом школы.\n\nСвяжите их за минуту: откройте ссылку и укажите вашу почту.\n{url}\n\nЕсли аккаунта ещё нет — он создастся автоматически, бесплатно и без пароля. После связывания бот начнёт отвечать сам, а куратор будет видеть ваш курс.",
        ),
        // Повторное приглашение тому же контакту не раньше этого окна (часы);
        // по умолчанию неделя — «никогда не спрашиваем второй раз подряд».
        'link_invite_cooldown_hours' => (int) env('TELEGRAM_SUPPORT_LINK_INVITE_COOLDOWN_HOURS', 168),
        // Ссылка-приглашение живёт столько часов; после истечения следующий
        // возможный запрос порождает новую ссылку (токен перегенерируется).
        'link_token_ttl_hours' => (int) env('TELEGRAM_SUPPORT_LINK_TOKEN_TTL_HOURS', 336),
        // Auto-heal IPC hang (01.08.2026): healthcheck → recover (kill worker,
        // clear ipc/locks, unlock madeline-session, one sync). Default OFF —
        // flip TELEGRAM_SUPPORT_AUTO_HEAL=true on prod after smoke.
        'auto_heal' => (bool) env('TELEGRAM_SUPPORT_AUTO_HEAL', false),
        'auto_heal_cooldown_minutes' => (int) env('TELEGRAM_SUPPORT_AUTO_HEAL_COOLDOWN_MINUTES', 30),
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
        'daily_enabled' => (bool) env('TELEGRAM_HARVEST_DAILY_ENABLED', false),
        'daily_cron' => env('TELEGRAM_HARVEST_DAILY_CRON', '15 5,17 * * *'),
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
        // H3411: 19-24-08-2026 a manual root-invoked run left ors_faq_peers.json
        // root:root 640 under store_path — both daily www-data cron runs then hit
        // Permission-denied until a human ran `chown -R www-data storage/app/telegram-harvest`
        // (docs/SERVER_SOFT_ALERT_PLAYBOOK.md incident log). SyncTelegramHarvest
        // checks/reclaims ownership against this pair, never against the running uid.
        'expected_owner' => env('TELEGRAM_HARVEST_EXPECTED_OWNER', 'www-data'),
        'expected_group' => env('TELEGRAM_HARVEST_EXPECTED_GROUP', 'www-data'),
        // Через сколько часов снимок состава чата считается протухшим (0 —
        // не помечать). Снимок пишет часовой telegram-harvest:roster-groups, и
        // при любом его сбое СТАРЫЙ файл остаётся лежать как есть — дашборд
        // «Записи (бот)» помечает такой снимок, а не выдаёт за текущий.
        'roster_stale_after_hours' => (int) env('TELEGRAM_HARVEST_ROSTER_STALE_AFTER_HOURS', 6),
        // Через сколько часов молчания в чате (нет новых сообщений в corpus)
        // дашборд предупреждает о возможной поломке вебхука (0 — не мерить).
        // Дорожка сообщений — Bot API webhook, она независима от MTProto-сессии,
        // и её молчание внешне неотличимо от «в чате просто тихо».
        'corpus_silence_after_hours' => (int) env('TELEGRAM_HARVEST_CORPUS_SILENCE_AFTER_HOURS', 48),
        // Потолок времени одного прохода telegram-harvest:roster-groups (секунды,
        // 0 — без потолка). Заметно больше, чем у support-синка: проход обходит
        // все группы, а getPwrChat по супергруппе идёт рекурсивным алфавитным
        // поиском. Kernel::schedule() выводит TTL замка отсюда — зависший проход
        // обязан умереть раньше, чем замок протухнет и пустит второй экземпляр.
        'roster_timeout_seconds' => (int) env('TELEGRAM_HARVEST_ROSTER_TIMEOUT_SECONDS', 600),
        // H3411: потолок времени одного прохода telegram-harvest:sync (секунды,
        // 0 — без watchdog). Тот же shared-session watchdog/reaper паттерн, что
        // у telegram_support.sync_timeout_seconds — если проход зависает
        // (демон MadelineProto залипает в D-state и не реагирует на SIGTERM),
        // watchdog убивает демон, снимает lock и артефакты IPC, и на cooldown
        // секунд блокирует следующий запуск, чтобы не долбить зависшую сессию.
        'sync_timeout_seconds' => (int) env('TELEGRAM_HARVEST_SYNC_TIMEOUT_SECONDS', 120),
        'sync_timeout_cooldown_seconds' => (int) env('TELEGRAM_HARVEST_SYNC_TIMEOUT_COOLDOWN_SECONDS', 600),
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

    // ВХОДНОЙ УЗЕЛ вебхуков Telegram — общий для ВСЕХ ботов: кабинетного,
    // лид-магнитного, ботов лендингов и @zapisi_ORSbot.
    //
    // Зачем отдельный блок. Telegram не может достучаться до прода напрямую:
    // входящие из его подсетей не проходят, при том что сайт снаружи открыт и
    // вебхуки Точки доходят. Поэтому апдейты принимает промежуточный nginx на
    // зарубежном хосте и уводит их внутрь обратным SSH-туннелем. Пока этот адрес
    // не хранился нигде (команды строили URL строго из app.url, а кабинетный бот
    // регистрировался руками), переезд узла молча терял ботов: 22.07.2026
    // @zapisi_ORSbot остался на умершем входе и пять дней не получал сообщений,
    // хотя кабинетного перерегистрировали. Разбор —
    // docs/telegram-userbot-inventory.md §4.3.
    'telegram_webhook' => [
        // Пусто → app.url (dev и случай восстановленного прямого канала).
        'base_url' => env('TELEGRAM_WEBHOOK_BASE_URL'),
        // Сертификат входного узла, если он самоподписанный: такой вебхук Telegram
        // примет только вместе с загруженным файлом. Пусто → ничего не грузим.
        // Прикладывается ТОЛЬКО когда задан base_url (см. App\Support\TelegramWebhooks).
        'certificate' => env('TELEGRAM_WEBHOOK_CERTIFICATE'),
    ],

    // Track C (@zapisi_ORSbot). Токен/username/секрет живут в MarketingSetting
    // (админка), здесь — только поведение приёма апдейтов.
    //
    // Апдейты забирает long polling (zapisi:poll), а не вебхук: Telegram не может
    // достучаться до прода — входящие из его подсетей не проходят, при том что
    // сайт снаружи открывается и вебхуки Точки доходят (разбор 27.07.2026 в
    // docs/telegram-userbot-inventory.md §4.3). Исходящий канал до Telegram уже
    // обеспечен sshuttle-туннелем, поллинг ходит по нему.
    'telegram_zapisi' => [
        // Рубильник АВАРИЙНОГО поллинга. Штатно апдейты приходят вебхуком через
        // входной узел; поллинг нужен, только когда узел недоступен. Дефолт
        // false осознанно: команда снимает вебхук, и случайный запуск иначе
        // молча уводил бы бота с рабочей дорожки.
        'poll_enabled' => (bool) env('TELEGRAM_ZAPISI_POLL_ENABLED', false),
        // Сколько Telegram держит соединение, ожидая новых апдейтов. Клиентский
        // таймаут строится от этого значения (+запас) внутри канала.
        'poll_timeout_seconds' => (int) env('TELEGRAM_ZAPISI_POLL_TIMEOUT_SECONDS', 50),
        // Плановый выход демона, после которого supervisor поднимает свежий
        // процесс — иначе после деплоя поллер остаётся на старом коде.
        'poll_max_lifetime_seconds' => (int) env('TELEGRAM_ZAPISI_POLL_MAX_LIFETIME_SECONDS', 3600),
        // Пауза после ошибки getUpdates, чтобы не молотить впустую при
        // недоступности Telegram (туннель упал, сеть моргнула).
        'poll_retry_seconds' => (int) env('TELEGRAM_ZAPISI_POLL_RETRY_SECONDS', 10),
    ],

    'vk' => [
        'bot_token' => env('VK_BOT_TOKEN'),
        'group_id' => env('VK_GROUP_ID'),
        'confirm_code' => env('VK_CONFIRM_CODE'),
        // Секрет VK callback (/api/vk-webhook). Пусто → проверка отключена
        // (см. App\Http\Middleware\VerifyVkBotWebhook).
        'callback_secret' => env('VK_CALLBACK_SECRET'),
    ],

    // H3311: раньше этот блок и Socialite-блок ниже оба назывались 'yandex';
    // PHP last-wins молча выбрасывал api_key/folder_id/agent_id. Читатели
    // речи/агента теперь обязаны ходить в services.yandex_speech.
    'yandex_speech' => [
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
        'model' => env('OPENROUTER_MODEL', 'deepseek/deepseek-v4-flash'),
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

    // H3312: единый канонический адрес суперадмина - только из env, без
    // литерального фолбэка (литерал в публичном репо = раскрытие логина
    // super_admin). Fail-closed: пустой ADMIN_EMAIL отключает админ-функции
    // (Horizon deny, backup-уведомления skip, админ-письма по платежам skip),
    // а не уводит их на «угаданный» адрес. Прод получает значение через
    // DEPLOY_QUEUE (Uprava); DatabaseSeeder дополнительно требует
    // непустой ADMIN_PASSWORD перед сидированием.
    'admin' => [
        'email' => env('ADMIN_EMAIL', ''),
        'password' => env('ADMIN_PASSWORD'),
    ],

    // Smoke-менеджер для проверки входа / CRM (не super_admin).
    // php artisan users:ensure-test-manager — идемпотентно, пароль только из .env.
    'test_manager' => [
        'email' => env('TEST_MANAGER_EMAIL', 'smoke-manager@samskrte.ru'),
        'password' => env('TEST_MANAGER_PASSWORD'),
        'name' => env('TEST_MANAGER_NAME', 'Smoke Manager'),
    ],

    // Smoke-студент для cabinet:probe student-ветки (role=null).
    // php artisan users:ensure-test-student
    'test_student' => [
        'email' => env('TEST_STUDENT_EMAIL', 'smoke-student@samskrte.ru'),
        'password' => env('TEST_STUDENT_PASSWORD'),
        'name' => env('TEST_STUDENT_NAME', 'Smoke Student'),
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
        // вебхуки и проверку URL. Обязателен: пустой → endpoint 503.
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

    // === ОПЛАТА ИЗ-ЗА РУБЕЖА (PayPal, полу-интегрированная, H2017) ===
    // Автосписания нет: студент платит на me_link и подаёт заявку
    // (PaypalClaimController: from + date + amount), админ сверяет вручную.
    // Prod 31-07-2026: enabled=true, recipient gasyoun@gmail.com.
    // false → /paypal/* 404, CTA скрыта.
    'paypal' => [
        'enabled' => (bool) env('PAYPAL_CLAIM_ENABLED', false),
        'me_link' => env('PAYPAL_ME_LINK'),      // напр. https://www.paypal.com/paypalme/xxx
        'recipient' => env('PAYPAL_RECIPIENT'),  // email/имя получателя для инструкции студенту
        // Ruling 22-08-2026: заявка СУЩЕСТВУЮЩЕГО ученика (вошедшего в кабинет)
        // сразу становится paid — доступ/финансы открываются немедленно, сверка
        // выборочная и пост-фактум (фильтр «PayPal: без сверки»). Гости с новым
        // email идут по-старому через pending → ручную сверку.
        // false → откат к ручной сверке для всех, без деплоя логики.
        'trust_existing_students' => (bool) env('PAYPAL_TRUST_EXISTING_STUDENTS', true),
        // MG 23-08-2026: в PayPal платят только EUR (предпочтительно) и USD,
        // и дороже рублевых — рублевую цену тарифа на форме НЕ показываем.
        // Валютная цена за БЛОК по course_id; показывается только блочным
        // тарифам. Источник прайса: Google-таблица цен (гр.53 Кочергиной =
        // тот же блок 8000 ₽ = 105 $/90 €).
        'foreign_block_prices' => [
            434 => ['eur' => 90, 'usd' => 105], // Грамматика по Кочергиной
        ],
        // H3819/H3821: доля наценки над чистой конвертацией RUB→EUR/USD в
        // published fixed price list (см. PaypalForeignPriceService), покрывающая
        // комиссию PayPal (§4 PAYPAL_RECONCILIATION_MONEY_CONTOUR) и снос курса
        // между расчётом и оплатой. Не применяется к student_discounts-активным
        // местам — рулинг MG про carve-out.
        'fixed_price_markup' => (float) env('PAYPAL_FIXED_PRICE_MARKUP', 0.08),
        // H2027 PayPal Subscriptions API (auto-bill diaspora) — separate from claim.
        // Master flag default OFF; secrets never committed. See
        // docs/ARCHITECTURE_PAYPAL_SUBSCRIPTIONS_2026.md
        'subscriptions' => [
            'enabled' => (bool) env('PAYPAL_SUBSCRIPTIONS_ENABLED', false),
            'mode' => env('PAYPAL_API_MODE', 'sandbox'), // sandbox|live
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            // Test-only escape hatch — never true in prod .env
            'skip_signature_verify' => (bool) env('PAYPAL_SKIP_WEBHOOK_SIGNATURE', false),
            'base_url' => env('PAYPAL_API_BASE_URL'), // optional override
        ],
    ],

    // H3497 — заявка об оплате банковским переводом (SEPA/SWIFT на внешний счёт
    // получателя школы за рубежом). Флаг default OFF: маршрут отвечает 404, пока
    // MG не скажет включать. Реквизиты получателя — только из env, не хардкод.
    'bank_claim' => [
        'enabled' => (bool) env('BANK_CLAIM_ENABLED', false),
        // Заявка вошедшего существующего ученика сразу paid (зеркало рулинга
        // 22-08-2026 из PayPal-канала); гость с новым email → ручная сверка.
        'trust_existing_students' => (bool) env('BANK_TRUST_EXISTING_STUDENTS', true),
        // Реквизиты для шага 1 формы (показываются ученику).
        'recipient_name' => env('BANK_RECIPIENT_NAME', ''),
        'iban' => env('BANK_RECIPIENT_IBAN', ''),
        'bic' => env('BANK_RECIPIENT_BIC', ''),
        'bank_name' => env('BANK_RECIPIENT_BANK_NAME', ''),
    ],

];
