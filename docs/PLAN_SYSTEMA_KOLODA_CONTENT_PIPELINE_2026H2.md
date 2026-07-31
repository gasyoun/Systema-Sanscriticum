# PLAN — Systema koloda content pipeline (2026H2)

_Created: 31-07-2026 · Last updated: 31-07-2026_

**Goal.** Make `/koloda` (public) and `/dvaram/koloda` (cabinet) the durable vocabulary + grammar drill surface for samskrte.ru by filling **content**, reconciling lesson flash cards into the SRS store, and exposing a **Hindi track filter** — without rebuilding the FSRS engine (already live).

**Span.** Wave-1 deliverables that a Sonnet/Grok session can ship unattended once human Memrise export lands. P3 mems/UGC and Wave-3 audio stay staged, not wave-1.

**Repo:** [Systema-Sanscriticum](https://github.com/gasyoun/Systema-Sanscriticum) · product URL https://samskrte.ru/koloda

---

## Layer docs

| Layer | Doc |
|---|---|
| Roadmap / waves | [ROADMAP_SYSTEMA_KOLODA_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_KOLODA_2026H2.md) |
| Architecture | [ARCHITECTURE_SYSTEMA_KOLODA_CONTENT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_KOLODA_CONTENT.md) |
| Implementation (wave-1 steps) | [IMPLEMENTATION_SYSTEMA_KOLODA_CONTENT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_KOLODA_CONTENT.md) |
| Verification | [VERIFICATION_SYSTEMA_KOLODA_CONTENT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_KOLODA_CONTENT.md) |

Prior art (do not rebuild): [docs/SRS_ROADMAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SRS_ROADMAP_2026.md) · [ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md) · [ROADMAP_GRAMMAR_TABLES_BUHLER_MEMRISE_SRS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ROADMAP_GRAMMAR_TABLES_BUHLER_MEMRISE_SRS_2026.md) · [docs/MANUAL_AGENT_ANKI_SRS_IMPORT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_AGENT_ANKI_SRS_IMPORT.md)

---

## Decisions taken (ask-batch sitting 31-07-2026)

| # | Fork | Ruling | Rationale |
|---|---|---|---|
| K1 | Next-wave focus | **Content pipeline** | Engine (FSRS, P2 modes, guest trial, authoring) is shipped; bottleneck is decks + lesson wiring |
| K2 | `Lesson.flash_cards` vs `SrsCard` | **Migrate into SRS** — one source of truth | Shared FSRS + Prana + guest/public URLs; lesson UI becomes a thin reader of lesson-tied decks |
| K3 | UGC / public mems | **Post-moderation** when P3 lands | Faster live UGC; not wave-1 |
| K4 | Hindi placement | **Separate track / language filter** in catalog | Hindi Core already exists; leaderboard and hub list scope by `language` |
| K5 | Grammar tables (Bühler 6517849) | **Option A first** — one card per inflected form + `stem_class`/`lemma` metadata | Reuses importer; Option B paradigm-grid is later polish |
| K6 | Memrise 6679375 | **Human P0 remains** (`MEMRISE_SESSION`) | Tooling shipped (H1146); agent cannot log in |
| K7 | Anki / kosha imports | **Reuse** `srs:import-anki` + `srs:import-kosha-b1-demo` | No new importer family without a new source format |

---

## Autonomy contract (wave-1 executors)

| Situation | Policy |
|---|---|
| Ambiguity | Pick the marked default in this PLAN; log one line in the PR body under «Defaults applied» |
| Stop | Money/payment contour; missing `MEMRISE_SESSION` for live export; watcher wipe thrice; failing Feature/Srs suite after a step that should keep it green |
| Commit | Worktree → branch → PR → merge (Systema watcher: `/watcher-safe-commit`); no force-push |
| Fence | Do not flip payment flags; do not rewrite FSRS weights; do not delete `Lesson.flash_cards` column until dual-read period ends (see IMPLEMENTATION) |
| Model | Content import / migration mechanical → **Sonnet** or **Grok**; Hindi filter UX judgment → **Grok**/**Opus** if A11y forks appear |

---

## Wave-1 handoffs (queued)

Minted same pass as this PLAN (see Uprava handoffs registry). Execute via `/go H###` or starter lines in each file — **nothing auto-runs**.

| Order | Deliverable | Typical tier |
|---|---|---|
| 0 | Human: export Memrise 6679375 → `database/seeders/data/memrise_6679375/` | @DO |
| 1 | Import Bühler grammar tables 6517849 (Option A + metadata) | Sonnet/Grok |
| 2 | `Lesson.flash_cards` → lesson-tied `SrsDeck`/`SrsCard` migration + dual-read | Sonnet |
| 3 | Hindi (and language) filter on `/koloda` + cabinet hub | Grok/Sonnet |
| 4 | After human P0: `srs:import-memrise` for 6679375 + DEPLOY_QUEUE note | Sonnet |

Later (not wave-1): P3 SrsMem post-mod · P2b Devanagari keyboard · Wave-3 TTS · paradigm-grid Option B.

---

## Status vs older ask-batch

July [ASK_BATCH_STAGING_2026-07.md](https://github.com/gasyoun/Uprava/blob/main/ASK_BATCH_STAGING_2026-07.md) ruled «keep SRS dark». **Superseded:** `config/srs.php` default `SRS_ENABLED=true` (product 30-07-2026), public `/koloda`, guest trial H1981, P2 modes H1988. This PLAN is the post-launch content wave.

_Dr. Mārcis Gasūns_
