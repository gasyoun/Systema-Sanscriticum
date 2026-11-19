_Created: 11-10-2026 · Last updated: 11-10-2026_

# Sandhi teaching order on `/reading/sandhi` — H6304 report

Handoff [H6304](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6304-GLM_Systema-Sanscriticum_sandhi-teaching-order-consumer_09.10.26.md) (GLM 5.3 `zai-coding-plan/glm-5.3`, 11-10-2026). Closes the residual named in the [H4718 report](REPORT_CORPUS_SANDHI_READING_PAGE_H4718_2026-09-15.md) §«Not done»: «kosha's sibling `sandhi-curriculum` has a pedagogical priority order … Swapping the page to that order is a separate consumer edge.» This PR adds that consumer edge **as a second view** — the frequency order, its stats and its denominator are carried over byte-for-byte.

## Changed

- `scripts/vendor_corpus_sandhi.py` — reads a second pinned TSV from kosha `origin/main` (`data/sandhi/sandhi_curriculum.tsv`), joins it to the baked frequency rules by rule string, and emits a `curriculum` block. An unjoined rule or a count disagreement between the two datasets fails the build loudly (`JOIN FAIL: …`). Kosha path resolution is now portable (env `KOSHA_DIR`, Windows default, macOS default) — the pin still comes from git refs, so the output stays deterministic. No rules are derived or re-weighted here; the curriculum is consumed as-is.
- `resources/data/corpus_sandhi/corpus_sandhi_top.json` — schema `corpus_sandhi_top_v1` → `corpus_sandhi_top_v2`. Everything v1 had is unchanged (same 147 rules, same order, same `stats` incl. the 707,936-event denominator, same provenance). New `curriculum` block: pinned source (commit `3ccfd4050`, blob `f65564d5`, CC BY-SA 4.0), method note, and `teaching_order` — the 147 rules re-ordered by curriculum rank, each entry `{rank, rule, curriculum_rank, lesson}`. All 147/147 rules joined; 0 count mismatches (the datasets share one kosha vintage). Lessons 1–9 hold 133 rules; 14 baked rules sit in the curriculum's ungraded lesson-10 tail.
- `app/Http/Controllers/CorpusSandhiController.php` — new `teachingGroups()` static: resolves `teaching_order` against the frequency rules (full row + `curriculum_rank`), grouped into lessons 1–9 ascending with the lesson-10 tail last. `show()` passes `teachingGroups` to the view. `bands()` and the flag gate are untouched.
- `resources/views/reading/sandhi.blade.php` — new section «Порядок изучения (учебная программа)» after the frequency bands: intro states the priority formula (frequency × class × environment-generality, MG ruling 14-07-2026) and that coverage shown remains **corpus-frequency** coverage; per-lesson tables (№ в программе, правило, класс, доля в корпусе, № по частоте, пример); footer gains the curriculum provenance line. `noindex` and the `features.kosha_reader` gate are untouched.
- `tests/Feature/CorpusSandhiPageTest.php` — 4 new tests (see below); the 4 H4718 tests are unchanged and green.

## Unchanged

- Frequency ranking, coverage bands, stats, denominator (707,936 events / 41 texts), categories mix.
- `features.kosha_reader` default (OFF → route 404s; publication stays separately gated — the deployer's step, as in H4718).
- No kosha-side edits: no manifest `consumers` row for Systema on `sandhi-curriculum`, no interlinks change (the kosha → Systema-Sanscriticum repo-level edge already exists from H4718; a per-dataset consumer row in kosha's manifest is noted as an optional residual below).
- No money/access/webhook surfaces; no new messages, budgets, prices, schedules or feature-flag activation.

## Checks (commands, exit codes, revisions)

Reprobed before edits: default branch had no teaching-order code; no open PR touches this edge. Baseline `CorpusSandhiPageTest` = 4 passed / 602 assertions; new tests written first and confirmed RED (schema still `v1`, no `curriculum` block), then implementation made them green.

| Check | Command | Result |
|---|---|---|
| Build (deterministic) | `python3 scripts/vendor_corpus_sandhi.py` | exit 0 — «147 rules of 13012 (707936 events); 50%=23 80%=82 90%=147; kosha 3ccfd4050» |
| Drift gate on pin | `python3 scripts/vendor_corpus_sandhi.py --check` | exit 0 — «OK: vendored corpus sandhi layer matches kosha pin» |
| Drift detection | perturb one value in the JSON, re-run `--check` | exit 1 — «DRIFT: … differs from a rebuild off kosha origin/main»; rebuild restored, `--check` exit 0 again |
| Unknown join fails visibly | forced by construction: `build_curriculum` exits with `JOIN FAIL: N baked rule(s) missing …` on any unjoined rule or count mismatch | live join: 147/147, 0 mismatches (no failure path exercised on real data — it is code-visible, not silent) |
| Focused suite | `php -d memory_limit=1G vendor/bin/phpunit --filter=CorpusSandhiPageTest` | **8 passed / 974 assertions** (4 old + 4 new) |
| Neighbour regression | `php -d memory_limit=1G vendor/bin/phpunit --filter='CorpusSandhiPageTest\|ReadingPackTest'` | **36 passed / 7,109 assertions** (H4718 baseline 32 + 4 new) |
| Style | `vendor/bin/pint --dirty` | passed, no diffs |

Suite runs on PHP 8.5.9 (repo baseline 8.3+). PHPUnit emits its pre-existing suite-wide doc-comment deprecation warnings (3,110) — present in the H4718 baseline too, not introduced here.

Source revisions: kosha `origin/main` = `3ccfd4050c3b68595cb333861183aae6befa6654`; `corpus_sandhi.tsv` blob and `sandhi_curriculum.tsv` blob `f65564d54d4bf7c75ad867cd58b7c57095d70023` both pinned inside the JSON. PHP 8.5.9 CLI on macOS; no live/prod access was used — all evidence is from the committed fixtures (own data), no synthetic canaries.

### Source-joined 10-rule sample (`test_layer_teaching_order_sample_matches_kosha_curriculum`)

The head of kosha `sandhi_curriculum.tsv` (checked against the TSV on 11-10-2026), each row asserting curriculum rank, lesson, frequency rank and the frequency layer's example — ranks, examples and provenance preserved through the join:

| № в программе | Правило | Урок | № по частоте | Пример |
|---|---|---|---|---|
| 1 | a a → ā | 1 | 1 | ajara+amara→ajarāmaravat |
| 2 | m p → ṃ p | 1 | 2 | udyoginaṃ+puruṣa |
| 3 | m s → ṃ s | 2 | 3 | pāṭavaṃ+saṃskṛta |
| 4 | m v → ṃ v | 2 | 4 | vinayaṃ+vinayād |
| 5 | m t → ṃ t | 2 | 5 | dharmaṃ+tataḥ |
| 6 | m c → ṃ c | 3 | **7** | artham→vidyāmarthaṃ+ca |
| 7 | m k → ṃ k | 3 | **9** | kiṃ+kariṣyati |
| 8 | m m → ṃ m | 3 | **11** | prathitaṃ+manaḥ |
| 9 | m n → ṃ n | 3 | **12** | vaicitryaṃ+nīti |
| 10 | a ā → ā | 3 | 10 | ākarṇya+ātmanaḥ→ākarṇyātmanaḥ |

Bold = the two orders disagree: the curriculum promotes easy, environment-general anusvāra rules (m m, m n) ahead of the visarga rules (ḥ t → s t, ḥ c → ś c) that raw frequency ranks 6 and 8.

## Six-item teacher comparison (≤10 minutes, weekly review allowance)

The six largest order disagreements among the baked rules — the whole point of the teaching order. Read one row, check the direction makes pedagogical sense, move on (~1 min/row).

| Правило | Класс | № по частоте (доля) | № в программе (урок) | Куда и почему |
|---|---|---|---|---|
| t c → c c | consonant / other | 53 (0.41%) | 78 (урок 8) | позже: согласный класс, узкая среда |
| t n → n n | consonant / other | 62 (0.35%) | 86 (урок 9) | позже: то же |
| n ś → ñ ś | consonant / other | 110 (0.14%) | 138 (урок 10) | позже: редкое, палатализация |
| m gh → ṃ gh | anusvāra / nasal | 128 (0.11%) | 104 (урок 9) | **раньше**: лёгкий anusvāra, общая среда |
| t j → j j | consonant / other | 131 (0.11%) | 154 (урок 10) | позже: ассимиляция, редкая пара |
| t h → dd h | consonant / other | 143 (0.09%) | 166 (урок 10) | позже: самая «дорогая» фонетика |

Pattern: every large disagreement demotes consonant-class assimilations (harder, less general) and the single promotion is an easy anusvāra rule — exactly what «frequency × class × environment-generality» should do. Examples for each row live on the page (`/reading/sandhi#curriculum`, flag-gated) and in the layer JSON.

## Delivery status

- **Prepared code:** this PR — builder, layer v2, controller, view, tests. Merged ⇒ done.
- **Reviewed teaching preview:** the six-item table above awaits MG's weekly pass (batch allowance 30 min/week; this artifact is sized ≤10 min). No teacher has reviewed it yet.
- **Live operation:** OFF — `features.kosha_reader` stays default OFF; the page 404s and is `noindex`. Turning the flag on in prod is the deployer's step, unchanged from H4718.

## Risks / residuals

- kosha's manifest `sandhi-curriculum.consumers` does not yet name Systema-Sanscriticum (kosha-side edit, out of this repo's scope; optional follow-up mirroring the H4718 corpus-sandhi row).
- If kosha re-cuts the curriculum against a newer corpus-sandhi vintage, `--check` will report DRIFT and a rebuild will re-pin both TSVs from one commit — by design.
- PHP 8.5.9 ran the suite locally (repo baseline names 8.3); CI remains the authoritative style/compat gate, as in H4718.

_Гасунс_
