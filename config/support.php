<?php

declare(strict_types=1);

return [
    /*
     | Сколько дней pending-черновик FAQ-ответа (SupportAnswerSuggestion) висит без
     | действия куратора, прежде чем support:expire-stale-answer-suggestions пометит
     | его expired (не удаляет — для аудита). См. H247 (тикет S3 support-roadmap).
     */
    'answer_suggestion_expiry_days' => (int) env('SUPPORT_ANSWER_SUGGESTION_EXPIRY_DAYS', 14),

    /*
     | H3765 A5: сколько дней живёт КНОПКА «Отправить как есть» под подсказкой
     | куратору. Отдельно от expiry выше и строже его намеренно: подметание
     | статусов раз в сутки (support:expire-stale-answer-suggestions) — это
     | аудит, а не защита. Защита нужна в момент нажатия: сообщение в Telegram
     | не исчезает, и кнопку под подсказкой недельной давности можно нажать
     | случайно, пролистывая чат. Ответ на вопрос, заданный неделю назад,
     | студенту хуже молчания — поэтому старая кнопка отвечает отказом, а не
     | отправкой. Прежний 14-дневный сброс статусов не трогаем: он про
     | helpdesk-черновики H247 и ничего не отправляет.
     */
    'hint_send_button_max_age_days' => (int) env('SUPPORT_HINT_SEND_BUTTON_MAX_AGE_DAYS', 7),

    /*
     | Дневные rollup'ы поддержки (H1837, S10) — общие настройки ОБОИХ каналов.
     */
    'rollup' => [
        /*
         | Порог KPI «вопрос висит без ответа», часы. Считается одинаково для
         | TG-support и веб-стороны (см. App\Support\SupportRollupMetrics), иначе
         | сводный deflection-отчёт складывал бы несравнимые числа. 0 → KPI выключен.
         */
        'unresolved_after_hours' => (int) env('SUPPORT_ROLLUP_UNRESOLVED_AFTER_HOURS', 24),

        /*
         | Сколько последних дней пересчитывает support:rollup-web по умолчанию.
         | Больше одного — потому что KPI «висит N часов» у вчерашнего разговора
         | может дозреть только сегодня.
         */
        'web_backfill_days' => (int) env('SUPPORT_ROLLUP_WEB_BACKFILL_DAYS', 2),
    ],

    /*
     | H2320 — inbound «пауза по ДЗ» → append users.note (not HomeworkSubmission).
     | Requires homework_cue AND life_cue. Default ON (append-only, low risk).
     */
    'homework_pause_note' => [
        'enabled' => filter_var(env('SUPPORT_HOMEWORK_PAUSE_NOTE', true), FILTER_VALIDATE_BOOLEAN),
        'quote_max' => (int) env('SUPPORT_HOMEWORK_PAUSE_NOTE_QUOTE_MAX', 120),
        'homework_cues' => [
            'домашк',
            'домашн',
            ' дз',
            'дз ',
            'дз,',
            'дз.',
            'задан',
        ],
        'life_cues' => [
            'больничн',
            'выбило из колеи',
            'застой',
            'не успеваю',
            'на паузе',
            'пауза по',
            'отстаю',
            'отстала',
            'отстал ',
            'с ребенком',
            'с ребёнком',
            'с детьми',
            'форс-мажор',
            'форс мажор',
        ],
    ],

    /*
     | H3999 (волна 1 leverage-плана) — фактические ответы из LMS.
     */
    'facts' => [
        /*
         | Типы фактов, которые бот отправляет студенту САМ.
         |
         | Дефолт — ровно те три, что уходили до H3999 (ссылка на занятие,
         | ближайшие занятия, запись урока): пять новых резолверов копят
         | теневые события (dm_shadow_would_send_facts) и студенту ничего не
         | отправляют, пока человек не откроет тип после недели тени —
         | рулинг V1, тот же гейт, что прошла категория F.
         |
         | Открыть можно только 'homework' и 'schedule_change'. Остаток по
         | оплате ('balance'), состояние доступа ('access') и сертификат
         | ('certificate') вычёркиваются в КОДЕ
         | (SupportAnswerFactResolver::NEVER_AUTO_TYPES) и вписыванием сюда
         | не открываются — рулинг A1 не должен зависеть от правки конфига.
         */
        'live_types' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SUPPORT_FACT_LIVE_TYPES', 'zoom,schedule,recording')),
        ))),
    ],

    /*
     | H3999, рулинг A1: расхождение расчётного остатка с суммой, названной
     | студентом, уходит follow-up-задачей финансовому ведущему, а не ответом
     | студенту. Пусто — задача заводится неназначенной (её видно в общем
     | списке follow-up'ов), это осознанная деградация, а не потеря.
     */
    'escalation' => [
        'finance_lead_user_id' => env('SUPPORT_FINANCE_LEAD_USER_ID') === null
            ? null
            : (int) env('SUPPORT_FINANCE_LEAD_USER_ID'),
    ],

    /*
     | H3999 (рулинг A5) — SLA-сеть по открытым тредам без единого исходящего.
     |
     | ОТДЕЛЬНОЕ окно, а не config/support_hours.php, и это проверено, а не
     | предположено: support_hours кодирует 10:00–20:00 по будням с null на
     | выходных и питает виджет сайта (SupportAvailability::isOnline()).
     | SLA-правило — семь дней в неделю 09:00–22:00. Переиспользование одного
     | блока на два разных правила означало бы, что правка часов виджета молча
     | двигает эскалацию поддержки.
     */
    'sla' => [
        /*
         | Telegram-id кураторов по порядку эскалации: первый получает пинг на
         | первом пороге, следующий — на втором. Пусто → команда молчит (и
         | говорит об этом в выводе), а не шлёт «в никуда».
         */
        'curators' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SUPPORT_SLA_CURATORS', '')),
        ))),

        /* Минуты РАБОЧЕГО времени без ответа до первого и второго пинга. */
        'first_ping_minutes' => (int) env('SUPPORT_SLA_FIRST_PING_MINUTES', 15),
        'second_ping_minutes' => (int) env('SUPPORT_SLA_SECOND_PING_MINUTES', 60),

        /* Тихие часы: [22:00, 09:00) — в них не пингуем и время не копим. */
        'quiet_from' => env('SUPPORT_SLA_QUIET_FROM', '22:00'),
        'quiet_to' => env('SUPPORT_SLA_QUIET_TO', '09:00'),

        'timezone' => env('SUPPORT_SLA_TIMEZONE', 'Europe/Moscow'),

        /*
         | Насколько далеко назад команда вообще смотрит. Без потолка первый
         | прогон на живой базе разослал бы кураторам пинги по всему бэклогу —
         | тот же урок, что H3380 v2.2 выучил на history-заборе.
         */
        'lookback_hours' => (int) env('SUPPORT_SLA_LOOKBACK_HOURS', 48),
    ],

    /*
     | H2448 FAQ BM25 retrieval for SupportAnswerSuggester (flag features.faq_rag_suggester).
     */
    'faq_rag' => [
        // null → resource_path('knowledge/faq.md')
        'path' => env('SUPPORT_FAQ_RAG_PATH', null),
        'top_k' => (int) env('SUPPORT_FAQ_RAG_TOP_K', 3),
        // H3766 B3: во сколько раз токены заголовка раздела весомее токенов
        // тела. Заголовки faq.md почти дословно повторяют вопрос студента.
        // Подобрано на tests/fixtures/faq_rag_eval.json; 1 = выключено.
        'heading_weight' => (int) env('SUPPORT_FAQ_RAG_HEADING_WEIGHT', 5),
        // H3766 B4: сколько разделов FAQ уходит в системный промпт ИИ-куратора
        // при features.bot_faq_retrieval=true.
        //
        // Сильно больше, чем top_k подсказки куратору (3), и это осознанно.
        // Человеку показывают одну цитату, и ошибка ретривала стоит одного
        // лишнего клика; у бота выпавший раздел означает «данных нет» вместо
        // ответа. Смоук на 24 реальных вопросах кабинета (K → доля вопросов,
        // сохранивших нужный раздел / экономия промпта):
        //   6 → 75 % / −87 %   8 → 79 % / −82 %   12 → 88 % / −76 %
        //   16 → 88 % / −70 %  20 → 96 % / −64 %  25 → 96 % / −57 %
        // Берём 20: дальше кривая удержания плоская, а промпт только растёт.
        'bot_top_k' => (int) env('SUPPORT_FAQ_RAG_BOT_TOP_K', 20),
        // Okapi BM25 floor for money/policy refuse gate (category D).
        'min_score' => (float) env('SUPPORT_FAQ_RAG_MIN_SCORE', 1.5),
        'money_policy_categories' => ['D'],
        // H3765 A3: ПРОВИЗОРНЫЙ порог теневого автоответа (features.
        // support_dm_auto_reply_shadow). Измерено на committed 20-вопросном
        // наборе tests/fixtures/faq_rag_eval.json 31-08-2026: top-1 верен в
        // 16 случаях из 20, скоры верных 6.5…18.1, скоры НЕВЕРНЫХ 6.3…12.1 —
        // полосы пересекаются, так что ни один порог на этом наборе не даёт
        // обещанных R3 95 % точности. Лучшее достижимое — 86.7 % при 8.0
        // (сохраняются 15 вопросов из 20), его и берём: он режет самый
        // дешёвый мусор, не притворяясь калибровкой.
        //
        // H3766 B5 (31-08-2026) вывёл настоящие пороги на 100-вопросном наборе —
        // см. shadow_min_score_by_category ниже. Это число осталось нижней
        // границей для категорий без своего порога.
        'shadow_min_score' => (float) env('SUPPORT_FAQ_RAG_SHADOW_MIN_SCORE', 8.0),
        // H3766 B5: покатегорийные пороги, выведенные на 100-вопросном наборе
        // командой `php artisan faq:score-floor --min-coverage=0.20`. Это первый
        // порог, при котором точность top-1 в категории достигает требуемых
        // рулингом R3 95 % — фактически 100 %.
        //
        // ЧЕСТНО О ЦЕНЕ: покрытие на этих порогах мало (A 20 %, B 25 %, C 17 %,
        // F 41 % вопросов категории), то есть 100 % опираются на 2–7 вопросов.
        // Числа ПРОВИЗОРНЫЕ и живут только в ТЕНИ: живое включение автоотправки
        // рулингом R9 остаётся решением человека после недели теневого сбора.
        // Без порога точность top-1 составляет A 67 %, B 56 %, C 42 %, F 65 % —
        // отправлять на этом уровне нельзя ни в одной категории.
        //
        // D (деньги) и E (доступы) сюда не входят вовсе: они исключены R3.
        'shadow_min_score_by_category' => [
            'A' => (float) env('SUPPORT_FAQ_RAG_SHADOW_MIN_SCORE_A', 18.1),
            'B' => (float) env('SUPPORT_FAQ_RAG_SHADOW_MIN_SCORE_B', 14.8),
            'C' => (float) env('SUPPORT_FAQ_RAG_SHADOW_MIN_SCORE_C', 19.4),
            'F' => (float) env('SUPPORT_FAQ_RAG_SHADOW_MIN_SCORE_F', 15.7),
        ],
        // Категории, в которых тень вообще допускает мысль об автоотправке.
        // D (деньги) и E (доступы) исключены рулингом R3 — там цена ошибки
        // не покрывается никаким скором.
        'shadow_categories' => ['A', 'B', 'C', 'F'],
        // H3768, рулинг MG 31-08-2026 «только F». Категории, в которых бот
        // отвечает студенту ответом из FAQ САМ (флаг
        // features.support_dm_auto_reply_live_faq). Подмножество теневых:
        // из A/B/C/F живой стала одна F — по B5 только у неё требуемые R3
        // 95 % точности достигаются при разумном покрытии (41 %), тогда как
        // у A/B/C тот же порог оставляет 2–3 вопроса из выборки.
        //
        // Порог берётся тот же, что мерила тень (shadow_min_score_by_category):
        // одно определение на обе стороны, иначе теневая калибровка ничего не
        // говорила бы про живую отправку.
        //
        // D и E сюда вписать нельзя — SupportDmAutoReply вычёркивает их в коде.
        'live_categories' => ['F'],
    ],
];
