# ARCHITECTURE — koloda content & lesson reconciliation

_Created: 31-07-2026 · Last updated: 31-07-2026_

Index: [PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md)

---

## Boundaries (reuse, do not fork)

```
[Public]  /koloda[/{slug}]     → SrsController (guest trial, system/public decks)
[Cabinet] /dvaram/koloda/*     → auth + FSRS persist + Prana + modes
[Admin]   Filament SrsDeck/Card → teacher CRUD
[CLI]     srs:import-*         → seed system decks from external sources
[Engine]  ReviewService + Fsrs → queue, grade, log
```

Content pipeline only adds **importers**, **migration of lesson JSON**, and **catalog filter UI**. Engine stays untouched unless a bug blocks import.

---

## Data model (existing)

| Entity | Role |
|---|---|
| `SrsNoteType` | Field schema per card family (`language`, field keys) |
| `SrsDeck` | `user_id` null = system; `course_id`/`lesson_id` optional; `slug`, `language`, `visibility` |
| `SrsCard` | Fields JSON + optional `source_word_id` |
| `SrsReviewState` / `SrsReviewLog` | Per user×card FSRS state + audit |
| `Lesson.flash_cards` | **Legacy** JSON array on lessons — to be dual-read then migrated |

### Lesson reconciliation (K2)

1. **Inventoried source:** each `Lesson` with non-empty `flash_cards` becomes (or links to) one system/course deck with `lesson_id = lesson.id`, slug `lesson-{id}-flash` (or course-scoped if preferred once counts known).
2. **Map:** each JSON card → `SrsCard` fields (`front`/`back` or existing note-type keys). Prefer a dedicated note type `lesson_flash` if shapes vary.
3. **Dual-read window:** lesson UI and API still *can* serve from `flash_cards` if deck missing; after backfill, prefer `SrsDeck` where `lesson_id` set.
4. **Cutover:** stop writing new flash cards only to JSON (Filament/API writes create/update SRS cards). Column retained until a later drop migration (not W1).

### Grammar tables (K5 Option A)

- Note type e.g. `buhler_paradigm_cell`: fields `form`, `label` (case/number), metadata JSON or extra fields `lemma`, `stem_class`.
- One `SrsDeck` per Memrise level (5 decks) **or** one deck with level tags — prefer **one deck per level** to match `srs:import-memrise` pattern.
- Source: `database/seeders/data/memrise_6517849/`.

### Hindi track (K4)

- Filter is **query/UI only** on hubs: `?lang=sa|hi|all` (default `all` or last-used).
- Deck row already has `language`; no schema change required.
- Leaderboard/stats later may scope by language — out of W1 unless free.

---

## Build vs reuse

| Piece | Verdict |
|---|---|
| FSRS / ReviewService / modes | Reuse |
| `ImportMemriseSrsDeck` | Extend flags / note-type map for paradigm metadata if needed |
| Kochergina L1 importer | Pattern for dedicated curriculum decks |
| Anki importer | Reuse for future shared decks |
| New distractor/typing engine | Do not rebuild (H1988) |

_Dr. Mārcis Gasūns_
