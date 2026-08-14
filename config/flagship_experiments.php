<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Isolated storefront experiments on ONE flagship (H2762 / R12 R15)
|--------------------------------------------------------------------------
| Flagship = Kochergina (slug contains kocerginoi). Not a catalogue-wide
| rollout. Flags in config/features.php stay OFF until a human flips them.
|
| R12 next-step: a visible «после этого → Бюлер / тексты / рецитация»
| on the Kochergina catalog card. Metric: next_step_click / card_impression.
| R15 CTA A/B: one variable (label) «Смотреть пробный урок» vs «Записаться»
| on the same flagship course page. Metrics: sample_play, begin_checkout.
|
| Window: 30 days from FLAGSHIP_EXPERIMENT_STARTED_AT. Empty start = open
| while the flag is on. After the window: hide next-step and restore the
| previous CTA. Do not ship a «winner». Not a revenue claim.
*/

return [
    'flagship_key' => 'kochergina',

    'window_days' => 30,

    // Set when a human flips a flag on prod. Empty → window is «flag is on».
    'started_at' => env('FLAGSHIP_EXPERIMENT_STARTED_AT', ''),

    'next_step' => [
        'eyebrow' => 'После этого →',
        'targets' => [
            [
                'key' => 'buhler',
                'label' => 'Бюлер',
                'resolve' => 'flagship:buhler',
            ],
            [
                'key' => 'texts',
                'label' => 'тексты',
                'resolve' => 'flagship:start_chteniya',
            ],
            [
                'key' => 'recitation',
                'label' => 'рецитация',
                'resolve' => 'pattern:Рецитаци',
            ],
        ],
        'stop' => 'n too small to distinguish, or the strip breaks the card layout → hide (CATALOG_NEXT_STEP=false).',
        'rollback' => 'CATALOG_NEXT_STEP=false (or revert the PR). The block is gone.',
    ],

    'cta_ab' => [
        'control' => 'Смотреть пробный урок',
        'treatment' => 'Записаться',
        'cookie' => 'shop_cta_ab',
        'stop' => 'Traffic too thin to call a winner → DEFER. Do not ship a winner.',
        'rollback' => 'FLAGSHIP_CTA_AB=false restores the previous CTA string.',
    ],
];
