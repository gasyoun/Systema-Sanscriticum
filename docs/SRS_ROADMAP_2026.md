# SRS Flashcards Roadmap — "Anki for Sanskrit & Hindi"

_Created: 05-07-2026 · Last updated: 05-07-2026_

A native, web-only spaced-repetition system built into the Systema-Sanscriticum student
cabinet — decks, cards, an FSRS scheduler, and a review loop — with no dependency on the
Anki desktop/mobile apps or `.apkg`/AnkiWeb sync. Topical roadmap; sits under the master
[docs/ROADMAP_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md)
alongside [SEO_ROADMAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_ROADMAP_2026.md)
and [PRANA_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/PRANA_ROADMAP.md).

---

## What this is / why

Students learning Sanskrit (and later Hindi) need durable vocabulary retention. The platform
already stores lexical data ([`DictionaryWord`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DictionaryWord.php):
`devanagari` / `iast` / `cyrillic` / `translation` / `page`) but has no way for a student to
*drill* it. This feature turns that data — plus teacher- and student-authored decks — into a
spaced-repetition study loop inside the existing cabinet, rewarded through the existing
[Prana](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Prana/PranaService.php)
gamification currency.

Built entirely in the current stack — Laravel 10, Livewire, Filament 3, MySQL — matching the
existing [`student-dictionary`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/livewire/student-dictionary.blade.php)
Livewire component. No SPA, no new framework.

---

## Decisions taken (rulings, 05-07-2026)

Architecture forks (session plan, 05-07-2026):

| Fork | Ruling | Rationale |
|---|---|---|
| Anki compatibility | **Native web SRS only** — no `.apkg` import/export, no AnkiWeb sync | Sync/interchange is the largest, most brittle part of Anki; the value here is an in-cabinet study loop, fully owned |
| Scheduler | **FSRS** (modern Anki default) | Better retention/efficiency than SM-2; vendor the open-spaced-repetition reference rather than hand-roll |
| Content sources | Dictionary seed **+** teacher (Filament) **+** student self-authored **+** lesson/course-tied | Serves system decks, course curricula, and personal study from one card model |
| Card faces | Devanagari↔meaning bidirectional; IAST/Cyrillic toggle; TTS audio; Hindi vs Sanskrit note types | Matches how the Dictionary data is already shaped |
| Rewards | **Yes** — capped per-review Prana + streak bonus on the daily goal | Reuses `PranaService` / `StreakService`; hard cap + throttle prevents grinding |

Roadmap / sequencing forks (interview, 05-07-2026):

| Fork | Ruling | Consequence |
|---|---|---|
| Launch language scope | **Sanskrit first; Hindi a later wave** | P1–P3 ship on Sanskrit (Dictionary seed exists); Hindi note-type + content is Wave 4 |
| Rollout | **Feature flag → pilot one course**, then open to all | Ship behind a flag, enable for one active cohort, gather signal before general release |
| TTS provider (audio) | **Self-hosted / open model** | No per-call cost, full control; accept weaker Devanagari quality and the ops overhead |
| Priority vs other Tier-0 | **Top priority — start now** | P1 begins immediately; roadmap dated from Q3 2026 |

---

## Waves

Each wave states what unblocks it. Waves are sequential; the pilot flag gates *student
exposure*, not the build.

### Wave 1 — Core loop (P1) · **starts now** · handoff [H211](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H211-Opus_Systema-Sanscriticum_srs_flashcards_p1_05.07.26.md)
_Unblocked by: nothing — ready._

- Migrations: `srs_decks`, `srs_note_types`, `srs_cards`, `srs_review_states` (per user×card FSRS state), `srs_review_logs`, `srs_deck_user`.
- `App\Services\Srs\Fsrs` — vendored FSRS reference (19 weights, `Card` state, grade→next-state), pinned default weights, unit-tested against the reference vectors.
- `App\Services\Srs\ReviewService` — build today's queue (due + new-per-day limit), record a grade, update state, append log.
- Dictionary seeder: `DictionaryWord` → one system Sanskrit deck.
- Minimal Livewire `SrsReview` component at `/dvaram/srs`: front → reveal → Again/Hard/Good/Easy with FSRS-predicted intervals; keyboard shortcuts.
- Behind a `srs_enabled` feature flag (off in prod).
- **Deliverable:** one Sanskrit system deck reviewable end-to-end by a flagged account.

### Wave 2 — Authoring · _Unblocked by: Wave 1 data model._
- Filament `SrsDeckResource` + `SrsCardResource` (teacher CRUD, bulk add, CSV/paste import, "seed from Dictionary" action).
- Attach a deck to a course/lesson (nullable `course_id`/`lesson_id`).
- Student self-authored private decks (`SrsDeckEditor` Livewire).
- **Deliverable:** teachers curate decks; students build personal decks; decks attach to lessons.

### Wave 3 — Language polish + audio · _Unblocked by: Waves 1–2._
- Bidirectional review (script→meaning / meaning→script) per subscription.
- IAST + Cyrillic show/hide on the answer face.
- **Self-hosted TTS** job: synthesize Devanagari pronunciation → cache to `srs_cards.audio_path`; player button in review UI. (Model/voice selection is a build-time detail of this wave, not a blocker.)
- **Deliverable:** full Sanskrit card experience with transliteration toggles and audio.

### Wave 4 — Hindi · _Unblocked by: Wave 3 + Hindi card content sourced._
- Hindi note type (gender, verb forms, no `iast`/sandhi assumptions).
- Hindi decks + seed content (content sourcing is a prerequisite — flagged, not assumed present).
- **Deliverable:** Hindi decks reviewable alongside Sanskrit.

### Wave 5 — Engagement + general release · _Unblocked by: Waves 1–4 stable in pilot._
- Capped per-review Prana + daily-goal streak bonus via `PranaService` / `StreakService`.
- Stats panel: retention %, reviews/day, streak, mature-card count.
- Per-user FSRS re-optimization job once `srs_review_logs` has enough data.
- Flip the `srs_enabled` flag from the pilot cohort to all students.
- **Deliverable:** rewarded, measured SRS live for the whole student body.

---

## Non-goals (considered and ruled out — do not re-propose)

- **`.apkg` / `.colpkg` import or export** — ruled out 05-07-2026. If students later need their existing Anki decks, *one-way import* is the natural first extension, but it is not on this roadmap.
- **AnkiWeb sync-protocol emulation** (Anki desktop/mobile syncing to this server) — ruled out; very large, brittle, reverse-engineered.
- **SM-2 or Leitner scheduling** — ruled out in favour of FSRS.
- **Cloud/paid TTS** (Google Cloud, Azure) — ruled out 05-07-2026 in favour of a self-hosted open model.
- **Hindi at launch** — deferred to Wave 4; not a P1 blocker.

---

## Build discipline (mandatory)

Systema-Sanscriticum runs an external **watcher** that reverts uncommitted working-tree
changes. Every file change in this feature MUST follow the
[`/watcher-safe-commit`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md)
pattern: author in the scratchpad, land + commit in one shell invocation, verify survival
against `HEAD`, restore from `HEAD` if reverted. Develop on a branch → PR → `main`.

---

_Dr. Mārcis Gasūns_
