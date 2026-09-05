# Roadmap — Bühler grammatical-tables Memrise course into the SRS clone

_Created: 22-07-2026 · Last updated: 05-09-2026_

> **Truth-pass 19-08-2026 (H3072, Opus 5 `claude-opus-5`):** импорт состоялся: команда [srs:import-buhler-paradigms](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportBuhlerParadigmsSrsDeck.php) завозит парадигменные ячейки курса Memrise 6517849 в колоду (H1990, 02-08-2026). Дальнейшая судьба контента живёт в [PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md).

## 1. What this is

Memrise course [6517849 «Грамматические таблицы Руководства к санскриту»](https://community-courses.memrise.com/community/course/6517849/grammaticheskie-tablitsy-rukovodstva-k-sanskritu/)
(author's own course, companion to the Bühler grammar course 6508023) drills
noun-declension paradigms one stem class per level: inflected form in
`col_a`, case/number label in `col_b` (e.g. `bhānave` → "bhānuḥ D., sg.").
Exported + validated 22-07-2026 (H1442):
[`database/seeders/data/memrise_6517849/`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/memrise_6517849/README.md),
5 levels / 78 rows — a-stem masc. (`bhānuḥ`), u-stem neut. (`madhu`), ī-stem
fem. (`dhī`), the pronoun `aham`, and an r-stem (`gir`).

This is **structurally different content** from the Kochergina lesson-vocab
courses (H1431): not headword→translation pairs but paradigm cells (one
lemma × many inflected forms), so it needs its own SRS note-type and drill
mode design rather than reusing the lesson-vocab deck shape as-is.

## 2. Why build this now

[SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md)
already names exactly this gap twice, unbuilt:

- §3 row "Zaliznyak grammar index (E31) → Stem-class tagging → **Auto-
  declension drills**: 98,639 headwords tagged by stem-class → generate
  targeted noun-ending exercises."
- §3 row "VisualDCS paradigm browser (I10) → Paradigm display → **Flashcard
  mode** already built — wire it straight into the learn track's verb unit."

This Memrise export is a small, hand-curated, human-vetted seed set for
exactly that unbuilt feature — five stem classes, already in
question/answer-card shape — cheaper to wire up first than deriving the
full 98,639-headword Zaliznyak set, and a good way to validate the note-type
design before that larger derivation lands.

## 3. Phases

### P0 — Export (done, H1442)
Raw pull + `memrise_export_validate.py` green. See README in the export dir
for the exact paradigm coverage.

### P1 — Schema design (the hard part)
A paradigm-drill `SrsNoteType` is not the same shape as a vocab note:
- **Option A — one card per inflected form**, same shape as vocab cards
  (`col_a` = form, `col_b` = gloss, e.g. current CSV shape as-is). Cheapest
  to import (reuses the H1431 importer pattern verbatim), but loses the
  paradigm structure — a learner drills `bhānave` in isolation, never sees
  it as "dative singular of bhānuḥ" alongside its paradigm siblings.
- **Option B — one card per lemma, testing the full paradigm** (a grid/table
  answer, or a "fill every cell" review mode). Matches how the source
  actually teaches it, but needs new Livewire review-mode UI, not just a new
  note-type — bigger lift.
- **Recommendation**: ship Option A first (fast, reuses H1431's importer),
  tag each card with `stem_class` + `lemma` metadata so Option B can be
  built later without re-importing — same data, better review UX layered on
  top. Mirrors the roadmap's own P1→P2 sequencing pattern in
  [ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md).

### P2 — Import + wire
- Extend (don't fork) `php artisan srs:import-memrise` / the H1431 importer
  path to accept a `stem_class`/`lemma` tag pair per card, sourced from
  parsing `col_b`'s "`<lemma>` `<case>`., `<number>`" pattern.
- Create a dedicated `SrsDeck` ("Bühler paradigm drills"), separate from the
  Kochergina lesson decks and the Bühler vocabulary course decks.
- Keep behind `SRS_ENABLED` per the standing gate.

### P3 — Connect to the Zaliznyak/VisualDCS vision (stretch)
Once Option A ships and the `stem_class` tagging is proven on this small
set, revisit the SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX §3 auto-declension-drill
line for real: generate the same card shape from the 98,639-headword
Zaliznyak stem-class index, with this hand-curated 78-row set as the gold
sanity check that the generator's output matches human-authored expectation.

## 4. Open questions

1. Which review mode ships first — Option A (isolated cards) or invest in
   Option B (full-paradigm grid) up front? Recommendation above is A→B, but
   a human should decide given the UX cost of B.
2. Does `col_b`'s case-label parsing need a fixed abbreviation table (N./V./A./I./D./Abl./G./L.
   — same abbreviations as H1431's Kochergina `level_01.csv` glossary) or
   should it just store the label as free text and defer parsing?
3. Should this feed the existing verb-class trainer work (SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX
   §3, "Verb-class trainer") or stay noun-only for now? Source course is
   noun paradigms only (78 rows, 5 stem classes) — no verb conjugation data
   in this particular export.

_Dr. Mārcis Gasūns_
