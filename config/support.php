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
];
