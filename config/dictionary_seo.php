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
    // A word is index-eligible when its translation clears this bar AND, while
    // `curated_only` is on, it is on the curated allowlist (`dictionary_words.is_indexable`,
    // fed by `php artisan dictionary:mark-core-indexable <list>` from the «Сборное ядро»
    // / DCS-attested headwords). Default `curated_only` = true → nothing indexes by
    // accident even with the master switch flipped until the list is fed.
    'gate' => [
        'min_translation_length' => env('DICTIONARY_SEO_MIN_TRANSLATION', 40),
        'curated_only' => env('DICTIONARY_SEO_CURATED_ONLY', true),
    ],

    // D4 — emit the Wikidata/DBpedia `sameAs` triplet only when a mapping exists.
    // `php artisan dictionary:match-wikidata` populates `dictionary_words.wikidata_qid`
    // out of band (propose-only by default; `--write` after a spot-check — the exact
    // Devanāgarī signal still has conceptual-disambiguation false positives, see
    // docs/WIKIDATA_SAMEAS_SPOTCHECK.md). The page never guesses.
    'wikidata_base' => 'https://www.wikidata.org/wiki/',
];
