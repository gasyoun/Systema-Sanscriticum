<?php

declare(strict_types=1);

/**
 * CRM Wave 3 (H2485) — sales forecast assumptions.
 *
 * Probabilities, horizon and aging windows live HERE, never in Blade /
 * Filament. Changing a mid/low/high band changes the weighted forecast
 * without a UI deploy of hardcoded numbers.
 *
 * Actual revenue is NOT configured here: it is always the qualifying
 * Payment denominator from config/conversion.php (excluded_tariffs +
 * is_conditional=false + paid/success), via
 * OrderPaymentConversionService::qualifyingPaidRevenue().
 */
return [
    'horizon_days' => (int) env('CRM_FORECAST_HORIZON_DAYS', 30),

    // Stage aging buckets (days in current stage). A deal aged 10 days
    // lands in the first bucket strictly greater than 10, else the overflow.
    'aging_windows_days' => [7, 14, 30],

    // Closed-period backtests. A window whose start is before
    // history_available_from is labelled unavailable — never reconstructed
    // from payments or invented snapshots.
    'backtest_windows_days' => [30, 90],

    // Deal table (and therefore any reconstructable pipeline) exists from
    // 2026-07-25 (GC-C1 / H1641). Earlier calendars have no Deal journal.
    'history_available_from' => (string) env('CRM_FORECAST_HISTORY_FROM', '2026-07-25'),

    'stage_probabilities' => [
        'new' => [
            'mid' => (float) env('CRM_FORECAST_P_NEW_MID', 0.15),
            'low' => (float) env('CRM_FORECAST_P_NEW_LOW', 0.08),
            'high' => (float) env('CRM_FORECAST_P_NEW_HIGH', 0.25),
        ],
        'in_progress' => [
            'mid' => (float) env('CRM_FORECAST_P_IN_PROGRESS_MID', 0.35),
            'low' => (float) env('CRM_FORECAST_P_IN_PROGRESS_LOW', 0.20),
            'high' => (float) env('CRM_FORECAST_P_IN_PROGRESS_HIGH', 0.50),
        ],
        'negotiation' => [
            'mid' => (float) env('CRM_FORECAST_P_NEGOTIATION_MID', 0.60),
            'low' => (float) env('CRM_FORECAST_P_NEGOTIATION_LOW', 0.40),
            'high' => (float) env('CRM_FORECAST_P_NEGOTIATION_HIGH', 0.80),
        ],
        'won' => [
            'mid' => 1.0,
            'low' => 1.0,
            'high' => 1.0,
        ],
        'lost' => [
            'mid' => 0.0,
            'low' => 0.0,
            'high' => 0.0,
        ],
    ],
];
