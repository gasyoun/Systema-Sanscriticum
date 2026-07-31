# IMPLEMENTATION — koloda content pipeline wave-1

_Created: 31-07-2026 · Last updated: 31-07-2026_

Index: [PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md)

**Watcher:** every edit in Systema uses `/watcher-safe-commit` (scratchpad → atomic land+commit). Worktree off `origin/main`.

---

## W1-D1 — Bühler grammar tables import

1. Read export README + CSVs under `database/seeders/data/memrise_6517849/`.
2. Prefer extending `ImportMemriseSrsDeck` with optional `--note-type=buhler_paradigm_cell` and field map `col_a→form`, `col_b→label`; OR dedicated `srs:import-buhler-paradigms` mirroring Kochergina L1 if maps diverge.
3. Persist `lemma` + `stem_class` on each card (fields JSON or columns — fields JSON preferred to avoid migration).
4. Idempotent: re-run updates cards, no duplicates (match deck slug + form+label key).
5. Feature test: import dry fixture → deck card count = 78 total across levels; public `/koloda/{slug}` 200 when enabled.
6. DEPLOY_QUEUE row: one-time artisan command.

## W1-D2 — Lesson.flash_cards → SRS

1. Census: count lessons with `flash_cards` non-empty (artisan one-off or tinker in test).
2. Artisan `srs:migrate-lesson-flash-cards {--dry-run}`:
   - For each lesson: ensure note type `lesson_flash`; ensure deck `lesson_id` + slug; upsert cards from JSON.
3. Dual-read helper on `Lesson`: `srsDeck()` relation; presentation prefers SRS cards when present.
4. API/Filament: when saving flash cards, write SRS (and optionally keep JSON in sync during dual-read).
5. Tests: factory lesson with JSON → migrate → cards exist; second run no dupes; review queue works for subscribed user.
6. Do **not** drop column in W1.

## W1-D3 — Language filter on hubs

1. `SrsController::publicIndex` / `cabinetIndex`: accept `lang` query (`sa`|`hi`|`all`).
2. Filter `SrsDeck` by `language` when not `all`.
3. UI: simple tabs or select on hub Blade/Livewire (RU copy: «Санскрит» / «Хинди» / «Все»).
4. Preserve per-deck deep links (filter is hub-only).
5. Tests: seed sa+hi decks; `?lang=hi` lists only hi.

## W1-D4 — Memrise 6679375 (after human W0)

1. Confirm validate.py green on `memrise_6679375/`.
2. `php artisan srs:import-memrise database/seeders/data/memrise_6679375`.
3. Smoke Feature test or manual checklist in PR.
4. DEPLOY_QUEUE + marketing post optional update.

## Defaults if stuck

- Note-type field names unclear → copy Kochergina L1 pattern (`translation_ru` + script fields).
- Lesson JSON shape heterogeneous → store raw pair as `front`/`back` only.
- Hindi decks missing `language` → backfill `hi` where slug/name matches known Hindi decks.

_Dr. Mārcis Gasūns_
