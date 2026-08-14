<?php

declare(strict_types=1);

/**
 * H2486 — Literal-Jivo telephony / department / routing gate.
 *
 * Flags live in config/features.php and default OFF. This file holds the
 * activation thresholds and policy constants so Blade / later jobs cannot
 * invent new numbers. Changing a threshold is a docs+config change, not a
 * silent UI tweak.
 *
 * @see docs/PACKET_JIVO_TELEPHONY_PROVIDER_ROUTING_GATE_2026.md
 */
return [

    /*
     | Preferred PSTN carrier *if* Phase 2 ever unlocks. Not a purchase.
     | jivo_vats is rejected (second inbox + default Jivo AON).
     */
    'preferred_carrier' => 'none',

    'forbidden_default_aon' => '+74993504327',

    'activation' => [
        'callback_requests_per_14d' => 8,
        'completed_manual_callbacks_per_30d' => 20,
        'department_staff' => 3,
        'department_closed_threads_per_staff_30d' => 10,
        'capacity_concurrent_operators' => 4,
        'capacity_median_first_response_seconds' => 900,
        'capacity_window_days' => 14,
    ],

    'recording' => [
        'default_ttl_days' => 0,
        'store_audio_in_app' => false,
        'send_transcript_to_llm' => false,
    ],

    'event_types' => [
        'call.requested',
        'call.started',
        'call.answered',
        'call.missed',
        'call.ended',
        'call.recording_available',
    ],
];
