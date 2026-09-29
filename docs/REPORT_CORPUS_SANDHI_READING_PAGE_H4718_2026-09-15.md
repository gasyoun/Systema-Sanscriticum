_Created: 15-09-2026 · Last updated: 15-09-2026_

# Corpus sandhi → Systema `/reading/sandhi` — H4718 report (census A13)

Handoff [H4718](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4718-OxAlpha_Systema-Sanscriticum_xwalk-a13-corpus-sandhi-pages_14.09.26.md) (OxAlpha tier, 🟢1 trivial; executed by Opus 5 `claude-opus-5`, drain worker, 15-09-2026). This closes row **A13** of the Uprava [crosswalk candidate census 14-09-2026](https://github.com/gasyoun/Uprava/blob/main/reports/CROSSWALK_CANDIDATE_MAPPINGS_CENSUS_14-09-2026.md) on its Systema leg: kosha's `corpus-sandhi` listed Systema as a `consumer_candidate` («reading/sandhi/ pages», confidence low) with no wiring.

## What shipped

1. **A baked page layer:** [resources/data/corpus_sandhi/corpus_sandhi_top.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/corpus_sandhi/corpus_sandhi_top.json) (~81 KB), schema `corpus_sandhi_top_v1`. It holds the head of kosha's frequency ranking: every rule up to 90% of all corpus sandhi. That is 147 of 13,012 distinct rules, covering 707,936 events in 41 DCS texts. Each rule carries its rank, share, cumulative share, number of texts, top-3 texts and one attested example. A provenance block pins the source: kosha commit `3a9c35bd9`, the TSV's blob SHA, the licence (CC BY-SA 4.0) and the credit.
2. **Builder:** [scripts/vendor_corpus_sandhi.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_corpus_sandhi.py). It reads `data/sandhi/corpus_sandhi.tsv` from kosha's `origin/main`, not its working tree. `--check` rebuilds in memory and exits 1 on drift. Never hand-edit the JSON.
3. **Page:** `/reading/sandhi` ([CorpusSandhiController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/CorpusSandhiController.php), [reading/sandhi.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/reading/sandhi.blade.php)). It sits behind the existing `features.kosha_reader` flag, the same one as `/reading/kosha-demo`: default OFF, so the page returns 404 until the flag is on. The page is `noindex`. Rules are grouped into three coverage bands: the first 23 rules (50% of all junctures), then up to 80% (82 rules), then up to 90% (147 rules).

## Verification

- **Render smoke:** `php artisan test --filter=CorpusSandhiPageTest` gave 4 passed / 602 assertions. With the flag off the page returns 404. With it on the page returns 200 and shows the title, ranks 1 and 8, an example split, the event count and the licence.
- **Neighbour regression:** `--filter='CorpusSandhiPageTest|ReadingPackTest'` gave 32 passed (all reading-pack suites).
- **Pint:** could not run locally because the box has PHP 8.2.12 and Pint's build needs 8.3. CI is the style gate.

### 10-rule sample (pinned in `test_layer_head_matches_kosha_ranking`)

| Rank | Rule | Class | Share | Cumulative | Texts | Attested example |
|---|---|---|---|---|---|---|
| 1 | a a → ā | vowel coalescence | 6.40% | 6.40% | 41 | ajara+amara→ajarāmaravat |
| 2 | m p → ṃ p | anusvāra / nasal | 3.77% | 10.17% | 41 | udyoginaṃ+puruṣa |
| 3 | m s → ṃ s | anusvāra / nasal | 3.32% | 13.48% | 39 | pāṭavaṃ+saṃskṛta |
| 4 | m v → ṃ v | anusvāra / nasal | 3.20% | 16.68% | 40 | vinayaṃ+vinayād |
| 5 | m t → ṃ t | anusvāra / nasal | 3.12% | 19.81% | 39 | dharmaṃ+tataḥ |
| 6 | ḥ t → s t | visarga | 3.01% | 22.82% | 38 | nītis+tad |
| 7 | m c → ṃ c | anusvāra / nasal | 2.84% | 25.65% | 40 | artham→vidyāmarthaṃ+ca |
| 8 | ḥ c → ś c | visarga | 2.67% | 28.32% | 38 | ekaś+candramās |
| 9 | m k → ṃ k | anusvāra / nasal | 2.25% | 30.58% | 39 | kiṃ+kariṣyati |
| 10 | a ā → ā | vowel coalescence | 2.25% | 32.83% | 41 | ākarṇya+ātmanaḥ→ākarṇyātmanaḥ |

The sample was checked against the source TSV. Ranks 1–10 are the first ten TSV rows, which are already sorted by `global_count`. The shares were recomputed as count ÷ 707,936, and they reproduce the TSV's own `global_pct` column (6.4, 3.77, 3.32, …). The 80% cutoff falls at **82 rules**, which matches the manifest's claim «top 82 rules cover 80%». All ten examples are real corpus lines: Hitopadeśa prologue verses for most of them, and Buddhist/nīti openings for the rest.

**Class mix of the 147 baked rules (by events):** anusvāra/nasal 36.6% · visarga 31.9% · vowel coalescence 22.1% · consonant/other 9.4%.

## Not done / residuals

- **Ranking choice.** The page ranks by raw frequency. kosha's sibling `sandhi-curriculum` has a pedagogical priority order: frequency × class weight × environment-generality, following the MG ruling of 14-07-2026. Swapping the page to that order is a separate consumer edge. The SanskritGrammar leg of A13 («graded sandhi curriculum») is untouched.
- **Flag.** The page is dark until `features.kosha_reader` is on in prod. That flag already gates `/reading/kosha-demo`, and turning it on is the deployer's step, not this handoff's.
- **Edge registration.** kosha's manifest gains `consumers: Systema-Sanscriticum` for `corpus-sandhi`. Uprava's [interlinks_edges.tsv](https://github.com/gasyoun/Uprava/blob/main/interlinks_edges.tsv) gains the kosha → Systema-Sanscriticum edge, landed in the same pass as this PR.

_Гасунс_
