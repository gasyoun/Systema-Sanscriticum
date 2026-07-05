<?php

declare(strict_types=1);

return [
    /*
     | Сколько дней pending-предложение (ReminderSuggestion) висит без действия
     | куратора, прежде чем reminders:expire-stale-suggestions пометит его expired
     | (не удаляет — для аудита). См. H187, ruling #5.
     */
    'suggestion_expiry_days' => (int) env('REMINDER_SUGGESTION_EXPIRY_DAYS', 14),

    /*
     | Порог уверенности LLM-классификации (0..1), ниже которого предложение
     | не создаётся даже при is_reminder_request=true.
     */
    'confidence_threshold' => (float) env('REMINDER_SUGGESTION_CONFIDENCE_THRESHOLD', 0.5),
];
