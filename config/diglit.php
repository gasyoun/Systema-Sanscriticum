<?php

// «Основы цифровой грамотности» — поток октября 2026. Лестница ратифицирована
// MG 24-08-2026 (Uprava docs/COURSE_DIGLIT_PRICING_RU_MARKET_24-08-2026.md):
// ранние птицы 14 900 · основной 19 900 · VIP 34 900 · записи+чат 8 900.
// Публичная страница /online/cifrovaya-gramotnost живёт только при
// features.diglit_landing = true; цены читаются из тарифов курса по slug ниже
// (заведены в Filament), не из Blade — тот же принцип, что у /klub (H2645).
return [
    'course_slug' => env('DIGLIT_COURSE_SLUG', 'diglit-2026'),
];
