<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | VisualDCS native learner (H2482)
    |--------------------------------------------------------------------------
    |
    | Surfaces are gated by the three OFF-by-default flags in config/features.php.
    | This file is identity/progress/import config only — never prices or grants.
    */

    'provider' => 'visualdcs',

    'contract_version_prefix' => '1.',

    'preview_limit' => (int) env('VISUALDCS_PREVIEW_LIMIT', 5),

    /*
     | Course slugs whose access-granting payments unlock attested-tier items.
     | Empty = any real paid non-deposit/non-trial payment unlocks full access.
     | Partial entitlement = some but not all listed slugs are paid.
     */
    'entitlement_course_slugs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VISUALDCS_COURSE_SLUGS', ''))
    ))),

    'surfaces' => ['verb', 'nominal', 'passage'],

    'flag_by_surface' => [
        'verb' => 'visualdcs_verb',
        'nominal' => 'visualdcs_nominal',
        'passage' => 'visualdcs_passage',
    ],

    'payload_files' => [
        'verb' => 'verb-trainer.json',
        'nominal' => 'nominal-trainer.json',
        'passage' => 'concordance-passage.json',
    ],

    'storage_root' => 'visualdcs',
];
