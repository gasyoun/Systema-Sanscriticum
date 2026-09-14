<?php

declare(strict_types=1);

/**
 * Членство (H2644): бесплатный уровень «Свободный» + «Клуб».
 *
 * Продуктовая спецификация — SPEC_SAMSKRTE_MEMBERSHIP_LADDER_FOUR_LEVELS_2026 §6
 * (Uprava, приватный хаб). Здесь только то, что нужно коду.
 *
 * Механика оплаты на 01-09-2026 — РУЧНОЕ продление через обычный чекаут:
 * авто-списания нет (TOCHKA_RECURRING_ENABLED не задан, billing_subscriptions
 * пуст). Поэтому оплаченный период ЗАКАНЧИВАЕТСЯ, и его снимает демон
 * `membership:expire-club`, а не банк.
 */
return [

    'tiers' => [
        'free' => ['monthly_price' => 0],
        'basic' => ['monthly_price' => 1000],
        'club' => ['monthly_price' => 2000],
        // H3916 (ратифицировано MG 06-09-2026, сетка A): годовая подписка на
        // архив «в записи». Точные цены за срок — в term_prices; quarterly
        // 5 500 — пробный квартал тира Standard.
        'standard' => [
            'monthly_price' => 1667,
            'term_prices' => [3 => 5500, 12 => 20000],
        ],
        'professional' => [
            'monthly_price' => 2917,
            'term_prices' => [12 => 35000],
        ],
        // Top is deliberately unpriced for checkout until its separate go/no-go.
        'top' => ['monthly_price' => 5000],
    ],

    'terms' => [
        1 => ['discount_percent' => 0],
        3 => ['discount_percent' => 5],
        12 => ['discount_percent' => 15],
    ],

    'capabilities' => [
        'enhanced_cabinet' => 'basic',
        'library' => 'basic',
        'standard_exercises' => 'basic',
        'benefits' => 'basic',
        'recordings' => 'club',
        'personalised_exercises' => 'club',
        'maximum_tooling' => 'club',
        'private_archives' => 'top',
        'additive_samudra' => 'top',
    ],

    'recording_gate' => [
        'pilot_user_ids' => env('MEMBERSHIP_RECORDING_PILOT_USERS', ''),
        'pilot_started_at' => env('MEMBERSHIP_RECORDING_PILOT_STARTED_AT', ''),
        'pilot_size' => 20,
        'pilot_hours' => 48,
    ],

    'public_feed' => [
        'version' => env('MEMBERSHIP_PUBLIC_FEED_VERSION', '2026-08-17.1'),
        'window_start' => env('MEMBERSHIP_PUBLIC_FEED_WINDOW_START', '2026-09-01'),
        'window_end' => env('MEMBERSHIP_PUBLIC_FEED_WINDOW_END', '2026-10-31'),
        'membership_launch_date' => env('MEMBERSHIP_LAUNCH_DATE', '2026-09-01'),
        'samskrte_host' => env('MEMBERSHIP_SAMSKRTE_HOST', 'samskrte.ru'),
        'samskrtam_host' => env('MEMBERSHIP_SAMSKRTAM_HOST', 'samskrtam.ru'),
    ],

    /*
     | H2745 private archive offers. The public feed reads none of these rows.
     | Eligibility is derived from paid Payments on the listed historical
     | courses; no emails, contact lists or purchaser identities enter config.
     */
    'private_archives' => [
        'archives' => [
            'yoga_sutras' => [
                'title' => 'Архив «Йога-сутры Патанджали»',
                'description' => 'Закрытое предложение для подтверждённых участников прежних потоков.',
                'enabled' => (bool) env('PRIVATE_ARCHIVE_YOGA_SUTRAS', false),
                'offer_course_slug' => env('PRIVATE_ARCHIVE_YOGA_OFFER_SLUG', 'ioga-sutry-patandzali-1-potok-2025'),
                'eligibility_course_slugs' => array_values(array_filter(array_map('trim', explode(',', (string) env(
                    'PRIVATE_ARCHIVE_YOGA_ELIGIBILITY_SLUGS',
                    'ioga-sutry-patandzali-1-potok-2025,ioga-sutry-patandzali-2-potok-2025-2026'
                ))))),
            ],
            'soboleva_ayurveda' => [
                'title' => 'Архив аюрведы Е. Соболевой',
                'description' => 'Закрытое предложение для подтверждённых покупателей курса.',
                'enabled' => (bool) env('PRIVATE_ARCHIVE_SOBOLEVA_AYURVEDA', false),
                'offer_course_slug' => env('PRIVATE_ARCHIVE_SOBOLEVA_OFFER_SLUG', 'aiurveda-soboleva-v-zapisi'),
                'eligibility_course_slugs' => array_values(array_filter(array_map('trim', explode(',', (string) env(
                    'PRIVATE_ARCHIVE_SOBOLEVA_ELIGIBILITY_SLUGS',
                    'osnovy-aiurvedy-ocno'
                ))))),
            ],
            'druzhinin_ayurveda' => [
                'title' => 'Архив аюрведы А. Дружинина',
                'description' => 'Закрытое предложение для подтверждённых покупателей курса.',
                'enabled' => (bool) env('PRIVATE_ARCHIVE_DRUZHININ_AYURVEDA', false),
                'offer_course_slug' => env('PRIVATE_ARCHIVE_DRUZHININ_OFFER_SLUG', 'povtornaia-aiurveda-v-zapisi'),
                'eligibility_course_slugs' => array_values(array_filter(array_map('trim', explode(',', (string) env(
                    'PRIVATE_ARCHIVE_DRUZHININ_ELIGIBILITY_SLUGS',
                    'povtornaia-aiurveda'
                ))))),
            ],
        ],
    ],

    'club' => [
        /*
         | Slug курса-членства. Курс и три тарифа (месяц/квартал/год) заводит
         | человек в Filament — это ops-шаг, а не работа агента (§7 п.5).
         | Пока курса с таким slug нет, весь клубный контур — no-op.
         */
        'course_slug' => env('CLUB_COURSE_SLUG', 'club'),

        /*
         | Грейс после конца оплаченного периода, дни. Дефолт 3 —
         | ARCHITECTURE_TOCHKA_RECURRING_BILLING_MODES_2026 §11 D2.
         | Смысл: продлившийся в последний день НЕ теряет доступ на час
         | из-за того, что демон отработал раньше вебхука оплаты.
         */
        'grace_days' => (int) env('CLUB_GRACE_DAYS', 3),

        /*
         | Срок по умолчанию (месяцев), если платёж лежит на клубном курсе,
         | но его ключ доступа не в форме `club_{N}m` (ручной платёж из
         | админки, импорт). Меньше срока продать нельзя — берём месяц.
         */
        'default_term_months' => (int) env('CLUB_DEFAULT_TERM_MONTHS', 1),

        /*
         | Верхняя граница срока одного платежа, месяцев. Защита от опечатки
         | в Filament («120» вместо «12») — платёж на 10 лет клуба.
         */
        'max_term_months' => (int) env('CLUB_MAX_TERM_MONTHS', 12),
    ],

    'free_tier' => [
        /*
         | Срок разового гранта бесплатного уровня, дни (§6: «30-day grant»).
         */
        'grant_days' => (int) env('FREE_TIER_GRANT_DAYS', 30),

        /*
         | Метка в lesson_access_grants.reason. Кампания (H2566) передаёт свою
         | через --reason, чтобы конверсию можно было посчитать отдельно; на
         | «не копить» проверяются ВСЕ гранты бесплатного уровня, независимо
         | от метки (см. FreeTierLessonGranter::REASON_PREFIX).
         */
        'reason' => env('FREE_TIER_GRANT_REASON', 'free_tier_monthly'),

        /*
         | H2899 — можно ли ДЕМОНУ писать гранты, или бесплатный уровень
         | доставляется только кампанией (явный --user / --users-file).
         |
         | Почему по умолчанию ВЫКЛЮЧЕНО. `MEMBERSHIP_FREE_TIER` во всех
         | документах описан как «месячные гранты 350 уснувшим (D6 вариант «а»,
         | кампания H2566)», и эти 350 — плательщики, молчащие **>12 месяцев**
         | (D6 §4.1; H2566 воспроизвёл 351/247). Но правило отбора демона,
         | `FreeTierLessonGranter::eligibleUserIds()`, фильтра по давности не
         | имеет вовсе: оно сортирует по «давно не платил» и берёт верхние N.
         | Замер на проде 16-08-2026: демон видит **923** кандидата против 372
         | в двенадцатимесячной когорте — то есть включение флага раздавало бы
         | доступ в 2,5 раза более широкой аудитории, чем написано в документах,
         | по 200 в сутки, молча.
         |
         | Расхождение НЕ чинится подгонкой правила демона: «кому подарить» —
         | решение человека, а не следствие сортировки. Поэтому демон по
         | умолчанию только отчитывается, а выдаёт кампания с явным списком.
         | Включить авто-выдачу обратно: FREE_TIER_DAEMON_APPLY=true.
         */
        'daemon_apply' => (bool) env('FREE_TIER_DAEMON_APPLY', false),

        /*
         | H2915 — КОГОРТА ДЕМОНА ФАЙЛОМ. Путь относительно storage/app
         | (абсолютный тоже принимается). Формат тот же, что у --users-file:
         | user_id или email по одному в строке, `#` — комментарий.
         |
         | Зачем. H2899 заглушил авто-выдачу, потому что правило отбора демона
         | не знает про давность и видит 923 человека против 372 в когорте,
         | которую документы называют «350». Но заглушённый демон — это пауза,
         | а не решение: бесплатный уровень задуман ЕЖЕМЕСЯЧНЫМ, у каждого свой
         | цикл от даты его гранта. Файл возвращает демону работу, не возвращая
         | ему право решать: список пишет человек, демон доставляет.
         |
         | ФАЙЛ ЖИВЁТ В storage/app, А НЕ В /root. Планировщик крутится под
         | www-data (crontab www-data → systema-schedule-run.sh), и файл в /root
         | ему недоступен — демон падал бы каждую ночь. Проверено на проде
         | 16-08-2026: `sudo -u www-data test -r /root/...` → нет.
         |
         | Пусто = поведение H2899: авто-подбор, писать нельзя без
         | FREE_TIER_DAEMON_APPLY.
         */
        'cohort_file' => env('FREE_TIER_COHORT_FILE', 'membership/free_tier_cohort.txt'),
    ],
];
