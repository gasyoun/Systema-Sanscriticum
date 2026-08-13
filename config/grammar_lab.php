<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Grammar Lab (H2493 / G2)
    |--------------------------------------------------------------------------
    |
    | Pinned SanskritGrammar G1 bundle. Feature flags live in config/features.php
    | (grammar_lab, grammar_lab_semantic) and default OFF. Prod enable is a
    | separate ops step — never part of this merge.
    |
    */
    'pin' => [
        'repo' => 'gasyoun/SanskritGrammar',
        'tag' => 'v0.121.6',
        'pr' => 'https://github.com/gasyoun/SanskritGrammar/pull/857',
        'bundle_version' => '1.0.0',
        'schema_version' => '1.0.0',
    ],

    'vendor_rel' => 'data/grammar_lab',

    'fusion' => [
        'lexical_weight' => 0.70,
        'vector_weight' => 0.30,
        'exact_alias_boost' => 8.0,
        'prefix_alias_boost' => 3.0,
    ],

    'search' => [
        'top_k' => 10,
        'recall_at' => 5,
        'recall_gate' => 0.85,
    ],

    /*
     | Course slugs whose access-granting payment unlocks Grammar Lab.
     | Empty by default: G4 fills the hybrid entitlement; G2 never silently
     | grants from an unspecified course list.
     */
    'entitlement_course_slugs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GRAMMAR_LAB_COURSE_SLUGS', ''))
    ))),
];
