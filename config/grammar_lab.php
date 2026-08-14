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

    /*
     | Standalone Grammar Lab SKU (course slug). Empty = no subscription
     | product is wired. Human fills this before production activation.
     */
    'subscription_course_slug' => trim((string) env('GRAMMAR_LAB_SUBSCRIPTION_COURSE_SLUG', '')),

    'pilot' => [
        'eligibility_course_slugs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GRAMMAR_LAB_PILOT_COURSE_SLUGS', ''))
        ))),
    ],

    /*
     | H2494 publication policy. auto_publish is the import-time kill switch
     | (not a learner-facing feature flag). Prod GRAMMAR_LAB stays OFF.
     */
    'publication' => [
        'auto_publish' => filter_var(env('GRAMMAR_LAB_AUTO_PUBLISH', true), FILTER_VALIDATE_BOOLEAN),
        'sample_fraction' => 0.2,
        'sample_seed' => (string) env('GRAMMAR_LAB_SAMPLE_SEED', 'grammar-lab-g3-v1'),
    ],

    'mastery' => [
        'correct_to_master' => 2,
    ],
];
