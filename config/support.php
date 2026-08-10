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
        // Okapi BM25 floor for money/policy refuse gate (category D).
        'min_score' => (float) env('SUPPORT_FAQ_RAG_MIN_SCORE', 1.5),
        'money_policy_categories' => ['D'],
    ],
];
