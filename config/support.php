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
        // Настоящий порог выводит B5 на наборе из 100 вопросов; до тех пор
        // это число живёт только в ТЕНИ и студента не касается.
        'shadow_min_score' => (float) env('SUPPORT_FAQ_RAG_SHADOW_MIN_SCORE', 8.0),
        // Категории, в которых тень вообще допускает мысль об автоотправке.
        // D (деньги) и E (доступы) исключены рулингом R3 — там цена ошибки
        // не покрывается никаким скором.
        'shadow_categories' => ['A', 'B', 'C', 'F'],
    ],
];
