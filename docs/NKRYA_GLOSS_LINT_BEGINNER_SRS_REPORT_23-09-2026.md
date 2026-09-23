_Created: 23-09-2026 · Last updated: 23-09-2026_

# НКРЯ-линт русских переводов в колодах для начинающих: отчёт первого прохода (H5285)

H5285 (Opus 5, 🔴3 hard) — NKRYa gloss lint for Russian beginner SRS decks and textbook answer keys. Executed by Claude Code Opus 5.5 (`claude-opus-5-5`); the filename tier «Opus» is provenance. Rulings applied: the written recommendations of [GRILL_NKRYA_USES_ROUND2_DECISIONS_23-09-2026.md](https://github.com/gasyoun/SanskritLexicography/blob/master/RussianTranslation/docs/GRILL_NKRYA_USES_ROUND2_DECISIONS_23-09-2026.md) (no MG answers recorded for H5285 yet): Q1 these two files first, Q2 mechanical normalization without a vote, Q3 teacher decks flag-only, Q4 band 1 / XIX-only is a flag and band 2 a note.

## What was built

1. [scripts/nkrya_gloss_lint.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/nkrya_gloss_lint.py) lemmatizes each Russian gloss with pymorphy3. It flags a one-word gloss not in dictionary form (`inflected`, with the proposed form in `gloss_lemma`) and looks up the NKRYa frequency band of every content word (band 1 = under 1 ipm … 6 = most frequent). Band 1 or zero ipm is `rare`, band 2 is `soft_rare`. For a rare word it counts modern (1950+) and 19th-century hits (`c19_only`). It proposes the most frequent synonym already in [sa_ru_glossary.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/sa_ru_glossary.json). `--selftest` covers 20 offline checks; `--offline` reads only the caches; `--measure` bands a proposed replacement.
2. [scripts/nkrya_gloss_lint_sheet.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/nkrya_gloss_lint_sheet.py) cuts a review sheet of 10 cards or fewer. It has three card kinds: a glossary synonym swap, an agent rewording (from [reword_proposals.tsv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/nkrya_lint/reword_proposals.tsv), banded by the same cache), and one policy card.
3. Outputs: [lemmas_gloss_lint.tsv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/nkrya_lint/lemmas_gloss_lint.tsv), [roots_gloss_lint.tsv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/nkrya_lint/roots_gloss_lint.tsv), and the committed evidence cache [nkrya_evidence_cache.tsv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/nkrya_lint/nkrya_evidence_cache.tsv) (67 keys: 57 frequency, 10 period hit counts).
4. The lint is a pre-import step (Phase 3-bis) of the claude-config skill [/anki-srs-import](https://github.com/gasyoun/claude-config/blob/main/commands/anki-srs-import.md).

The source TSVs are **not edited**: both are generated fixtures. Roots come from `database/seeders/data/build_roots_frequency_ru.py` (kosha × WhitneyRoots); lemmas come from the pinned start-chteniya feed (`scripts/vendor_cohort_start_chteniya_packs.py`). A fix belongs upstream; see residuals.

## Findings

| | lemmas_for_srs (1 286 rows) | roots_frequency_ru (570 rows) |
|---|---|---|
| empty `gloss_ru` (the card shows English only) | **841** | 0 |
| English text in the Russian field | **34** | 0 |
| one-word gloss not in dictionary form | 96 | **382** |
| … of which ambiguous (стоит = стоить/стоять) | 11 | 3 |
| phrase glosses (no dictionary form to propose) | 18 | 57 |
| proper names (not judged by frequency) | 11 | 8 |
| NKRYa band 1 (`rare`) | 2 of the measured | 0 of the measured |
| NKRYa band 2 (`soft_rare`) | 6 of the measured | 0 of the measured |
| Russian rows fully measured against NKRYa | 128 of 411 | 46 of 570 |

1. **The biggest defect is not rarity but absence.** 841 of 1 286 start-chteniya SRS rows (65%) have no Russian gloss at all. 34 more carry an English dictionary line in the Russian field (`adravya` «a nothing; a worthless thing; [medic.] …»). A Russian-speaking beginner meets these words in English or not at all.
2. **Root deck glosses are inflected verb forms from the source sentences.** 382 of 570 root glosses are one word not in dictionary form: `kṛ` «сделал», `bhū` «будет», `vac` «сказал», `gam` «пришел», `dṛś` «увидев». The proposed infinitive sits in `gloss_lemma`. Per Q2 this is mechanical and needs no vote, but it must be applied upstream in the builder, not in the seed.
3. **Every rare or band-2 word measured so far is a fused не-/без- adjective glossing a Sanskrit a-/an- negation:** `ajara` «нестареющий» (0.35 ipm), `akṣaya` «нетленные» (2.68), `anuttama` «несравненный» (4.40), `apaṇḍita` «невежда» (4.31), `atandrita` «неустанно» (4.94), and `anartha` «не-артха» (a transliteration, absent from NKRYa). The sheet's policy card asks whether such words should get a wording built from frequent words.
4. **No `rare` row has a more frequent synonym in our own glossary**, so the two row cards are agent rewordings: «вечно молодой» (bands 3 and 4) and «вред» (band 3, 30 ipm).
5. **No `c19_only` word yet.** Both rare lemmas measured have modern hits.

### Precision guards (found on real rows, each now a rule in the lint)

- Names are skipped: Бака is not «бак», Брихаспати is not «брихаспать». A capitalised token counts as a name only if pymorphy does not know it or tags it as a name; «Защищайте» stays a verb.
- Plural and short forms are kept where the form carries meaning: дети is not «ребёнок», гуны is not «гун», должен is not «должный». «Счастье» is not rewritten to «счастие».
- A fused не- participle is measured by its base verb: «неродившихся» is banded via «родиться», not a pymorphy-invented «неродиться».
- Ambiguous forms get no proposal: берегу = берег|беречь. They are counted as `inflected_ambiguous` and resolved by the Sanskrit part of speech (verb root vs noun).

## The rate limit (why the band pass is partial)

The NKRYa API key is per account and shared by every session using it. Measured 23-09-2026:

- A short burst of about 5 calls a minute after idle.
- Then about **1 successful call a minute sustained**, even with no other NKRYa process on the Mac (13:40–14:45Z: 69 raw responses).
- The 429 body is `{"detail": "Too many requests."}` with no Retry-After.
- Read it as roughly 60 calls an hour.

A full pass over both files needs about 950 lookups (frequency for each content lemma, plus period hits for rare ones), which is about 15 hours. The run is resumable: the evidence cache saves every 20 lookups, and `--offline` harvests the raw cache (`storage/app/nkrya_cache`, gitignored), so a stopped run loses nothing. It continues as a residual handoff.

One defect found on the way: the client's offline-miss message embeds a hex cache key. A key containing the digits `429` looked like a rate-limit reply and made an «offline» run sleep. The retry test now matches `HTTP 429 ` only.

## Review sheet

[НКРЯ: редкие слова на карточках для начинающих — заменить?](https://gasyoun.github.io/vote/sheets/systema_nkrya_gloss_lint_rare_swaps_23-09-2026.html) has 3 cards. Screening: 477 deterministic (dictionary form), 14 lookup (ambiguous, resolved by Sanskrit part of speech), 6 agent (band 2 notes), 3 human.

1. `ajara` «нестареющий» → «вечно молодой».
2. `anartha` «не-артха» → «вред».
3. Policy: for a-/an- negations, should the agent propose a wording from frequent words?

Decisions file: `Systema-Sanscriticum/review/systema-sanscriticum-nkrya-gloss-lint_rare-swaps_23-09-2026_decisions.json`.

## Residuals

1. Finish the NKRYa band pass (unattended, ~15 h), then cut the next sheet from new `rare` rows.
2. Apply the 478 mechanical dictionary-form fixes upstream: the roots builder / WhitneyRoots Russian glosses, and the start-chteniya feed.
3. Fill the 841 empty and 34 English-in-Russian glosses in the start-chteniya feed.
4. Step 4 of the handoff: verb government (управление глаголов) in the Кочергина answer keys.
5. The remaining decks: Memrise teacher decks (flag-only, Q3), `sa_ru_glossary.json`, the Бюлер dictionary (a later wave, Q5).

_Гасунс_
