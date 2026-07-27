_Created: 19-07-2026 · Last updated: 19-07-2026_

# Plan — corpus-frequency learner surfaces in Systema (root SRS deck + script drill order, 2026 H2)

Two Tier-0 integrations that pull **corpus-frequency data from the research repos into the
revenue-facing student cabinet**: a frequency-ranked Sanskrit-root SRS deck with Russian
glosses (D4), and a Devanāgarī script drill ordered by real conjunct frequency (D6). Both
consume shipped sibling datasets — Systema builds no new linguistics, only surfaces.

Staged 19-07-2026 via [`/ask-batch`](https://github.com/gasyoun/claude-config/blob/main/commands/ask-batch.md)
by Fable 5 (`claude-fable-5`); interview rulings in
[`ASK_BATCH_STAGING_PEDAGOGY_2026-07.md`](https://github.com/gasyoun/Uprava/blob/main/ASK_BATCH_STAGING_PEDAGOGY_2026-07.md)
(private hub). Sits under the master
[`docs/ROADMAP_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md)
beside [`docs/SRS_ROADMAP_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SRS_ROADMAP_2026.md);
asset↔use-case context:
[`docs/SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md).

## Decisions taken (19-07-2026 interview — the execution agent trusts these)

| # | Decision | Ruling |
|---|---|---|
| 1 | Target surface | **Systema directly** (mixed-per-candidate ruling: small integrations go straight to the Tier-0 cabinet; heavier builds prove in kosha first) |
| 2 | D4 data source | **kosha's shipped W2b export** ([`data/roots/roots_frequency.tsv`](https://github.com/gasyoun/kosha/blob/main/data/roots/roots_frequency.tsv), H950) + [WhitneyRoots](https://github.com/gasyoun/WhitneyRoots) RU root glosses (H347) — consume, never re-derive frequency or glosses here |
| 3 | D4 delivery | **A system SRS deck** via the existing FSRS stack ([`app/Services/Srs/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/app/Services/Srs), seeder pattern of [`database/seeders/SrsSanskritDeckSeeder.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/SrsSanskritDeckSeeder.php)) — no new engine, per the SRS roadmap non-goals |
| 4 | D6 data source | **`dcs-grapheme-frequency`** (akshara 7,347 / conjunct 999 frequency tables, VisualDCS `derived-data/Fonetika/regen-2026/`, registered in [`kosha/data/manifest/datasets.json`](https://github.com/gasyoun/kosha/blob/main/data/manifest/datasets.json)) — the same order the Nagari teaching layer uses |
| 5 | D6 delivery | **A new script-drill exercise in the existing [`public/lila/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/public/lila) family** (cloze/match/sort pattern) — the current tree has no letter-drill surface on `main`, so this is an add-alongside, not a rewrite |
| 6 | Rights | Root glosses: WhitneyRoots RU layer is org-owned — publishable. Frequency tables: derived from DCS — attribute DCS (Hellwig) on the surface's about/notes block |

## D4 — Frequency-ranked RU root deck ([H1280](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1280-Sonnet_Systema-Sanscriticum_srs-root-frequency-ru-deck_19.07.26.md), Sonnet)

**What ships:** a curated system deck «Корни санскрита по частотности» — the 935 Whitney
roots ordered by corpus token frequency, front = root (devanagari + IAST), back = Russian
gloss + verb class + a top attested form; reviewable through the existing FSRS loop.

1. **Join script** (one-off artisan command or seeder-side): kosha
   [`roots_frequency.tsv`](https://github.com/gasyoun/kosha/blob/main/data/roots/roots_frequency.tsv)
   × WhitneyRoots RU gloss layer (H347; locate the gloss file via the WhitneyRoots README) →
   `database/seeders/data/` fixture TSV, committed, with a `source`+`rank` column per row.
2. **Seeder**: `SrsRootFrequencyDeckSeeder` following
   [`SrsSanskritDeckSeeder.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/SrsSanskritDeckSeeder.php)
   — note type `sanskrit_root`, one system deck, cards created in frequency order so
   FSRS "new cards/day" naturally serves highest-yield roots first.
3. **Review UI**: no changes expected — the deck rides the existing
   [`SrsReview`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Livewire/SrsReview.php)
   loop. Only verify the root note-type renders (devanagari front, RU + class back).
4. **Idempotency + tests**: seeder re-runs update in place (keyed by root), never
   duplicate cards; feature test seeds a 10-root fixture and reviews one card end-to-end.

**Acceptance:** deck seeds all rows with zero unmatched-gloss roots unaccounted for
(unmatched list logged, not silently dropped); re-seed is a no-op; a flagged account can
review the deck end-to-end; DCS attribution present in the deck description.

## D6 — Conjunct-frequency script drill ([H1281](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1281-Sonnet_Systema-Sanscriticum_marathon-conjunct-frequency-order_19.07.26.md), Sonnet)

**What ships:** a static exercise «Лигатуры по частотности» in
[`public/lila/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/public/lila)
— recognize/match Devanāgarī conjuncts presented most-frequent-first, so learners drill
the ~50 ligatures that cover the bulk of real text before the long tail.

1. **Data**: export the top conjuncts (with romanization + frequency band) from the
   `dcs-grapheme-frequency` tables into a committed `public/lila/ligatures/data.js`
   (static, no backend — matches the exercises pattern); document the regeneration
   command in the file header.
2. **Exercise**: clone the closest existing mechanic (match or sort) from
   [`public/lila/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/public/lila)
   into `public/lila/ligatures/`; levels = frequency bands (top-10 → top-50 →
   top-200); reuse `gate.js` access gating as the siblings do.
3. **Prior-art fence**: [csl-guides](https://github.com/sanskrit-lexicon/csl-guides) owns
   the general devanāgarī/transliteration quizzes — this drill is Systema-specific
   (ligature bands, RU interface, cabinet gating) and must link out to csl-guides for
   the full script course rather than duplicating it.
4. **Deploy**: static files — lands via the normal merge; add the prod copy step to
   [`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)
   for Ivan (Systema is FTP-deployed, agents cannot deploy).

**Acceptance:** exercise loads standalone (double-click / direct URL), band ordering
matches the frequency table exactly (spot-check top-10), RU interface text, DCS
attribution in the about block, DEPLOY_QUEUE row added in the same PR.

## Verification (both)

- The repo test suite stays green; new feature test for D4.
- No new engine/framework; no schema change beyond what the seeder needs (note type row).
- Watcher discipline: any main-tree file landing follows
  [`/watcher-safe-commit`](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md)
  (Systema is watcher-afflicted); worktree-authored PRs are the default path.
- `/publish-safety-check` before anything new becomes publicly linked.

_Dr. Mārcis Gasūns_
