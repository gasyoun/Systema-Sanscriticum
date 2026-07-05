<?php

/**
 * SEO P2 (H204) — public dictionary entity pages `/slovar/{slug}`.
 *
 * Indexation controls per the ruled policy
 * (Uprava/docs/DECISIONS_seo_p2_dictionary_pages.md, D1–D4).
 *
 * ⚠️ Wave 0 (initial launch): `index_enabled` = false → EVERY word page renders
 * `<meta robots="noindex,follow">` and the word URLs are withheld from the sitemap
 * (built but "unsubmitted", decision D2). Flip `index_enabled` on and promote the
 * curated core (decision D1) only during a monitored Wave-1 with a Yandex.Webmaster
 * checkpoint — never as part of the initial build.
 */
return [

    // Global master switch. FALSE = Wave 0 (all noindex, sitemap withholds word URLs).
    'index_enabled' => env('DICTIONARY_SEO_INDEX_ENABLED', false),

    // D1 — quality gate for the curated core, applied ONLY once index_enabled is true.
    // A word is index-eligible when its translation clears this bar AND (optionally)
    // it is on the curated allowlist. Until the allowlist data (Сборное ядро /
    // DCS-attested) is wired, `curated_only` stays true so nothing indexes by accident.
    'gate' => [
        'min_translation_length' => env('DICTIONARY_SEO_MIN_TRANSLATION', 40),
        'curated_only' => env('DICTIONARY_SEO_CURATED_ONLY', true),
    ],

    // D4 — emit the Wikidata/DBpedia `sameAs` triplet only when a mapping exists.
    // The auto-matcher + confidence gate populates `dictionary_words.wikidata_qid`
    // out of band; the page never guesses.
    'wikidata_base' => 'https://www.wikidata.org/wiki/',
];
