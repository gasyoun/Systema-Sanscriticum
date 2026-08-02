<?php

declare(strict_types=1);

return [
    /*
     | Days a pending PaymentPromiseSuggestion may sit without curator action
     | before promises:expire-stale-suggestions marks it expired (audit keep).
     */
    'suggestion_expiry_days' => (int) env('PROMISE_SUGGESTION_EXPIRY_DAYS', 14),

    /*
     | LLM confidence floor (0..1). Below this, no pending suggestion is created
     | even when is_payment_deferral=true.
     */
    'confidence_threshold' => (float) env('PROMISE_SUGGESTION_CONFIDENCE_THRESHOLD', 0.5),
];
