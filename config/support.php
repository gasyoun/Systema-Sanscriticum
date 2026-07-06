<?php

declare(strict_types=1);

return [
    /*
     | Сколько дней pending-черновик FAQ-ответа (SupportAnswerSuggestion) висит без
     | действия куратора, прежде чем support:expire-stale-answer-suggestions пометит
     | его expired (не удаляет — для аудита). См. H247 (тикет S3 support-roadmap).
     */
    'answer_suggestion_expiry_days' => (int) env('SUPPORT_ANSWER_SUGGESTION_EXPIRY_DAYS', 14),
];
