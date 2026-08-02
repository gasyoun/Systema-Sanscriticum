<?php

declare(strict_types=1);

/*
 | «Старт чтения» — Akro-style 5-week paid cohort pilot (H2105, plan doc
 | docs/PLAN_AKRO_START_CHTENIYA_2026.md). This config file names the ONE
 | Course row (created by ops in Filament — Course+Tariff+Group, price/dates
 | are human decisions, D6/D10) that the pilot is sold as, plus the
 | entitlement key name sibling handoffs (H2106/H2110/H2111) key their
 | gating off of. No prices are set here — see
 | config/features.php:start_chteniya_cohort for the deploy switch.
 */

return [
    // Slug of the Course row ops creates in Filament for this cohort.
    'course_slug' => env('START_CHTENIYA_COURSE_SLUG', 'start-chteniya'),

    // Name of the cohort entitlement, referenced by ARCHITECTURE_AKRO_START_CHTENIYA_2026.md
    // ("Cohort entitlement (default)"). Not a DB column — App\Support\StartChteniyaCohort
    // derives it from a real, paid, access-granting Payment on course_slug above.
    'entitlement_key' => 'start_chteniya_cohort',

    // Akro price-band reference (EUR) ONLY — documentation, never read to set
    // payments.tariffs.price. Exact RUB amount is set by a human on the Tariff
    // row in Filament at go-live (D6: do not invent live RUB here).
    'price_band_eur_min' => 75,
    'price_band_eur_max' => 129,
];
