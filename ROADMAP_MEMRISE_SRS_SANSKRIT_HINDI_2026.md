# ROADMAP — Memrise-clone vocabulary trainer in Systema (Sanskrit + Hindi)

_Created: 11-07-2026 · Last updated: 22-07-2026_

Bring the full Memrise learning loop — spaced-repetition review, every test mode,
gamification, and user mnemonics — into the [Systema-Sanscriticum](https://github.com/gasyoun/Systema-Sanscriticum)
LMS, seeded from the exported Memrise course
[6679375 «Продлёнка по санскриту»](https://community-courses.memrise.com/community/course/6679375/prodlenka-po-sanskritu/),
and extend the same engine to **Hindi**.

---

## 0. The headline finding — we are not starting from zero

A prior-art sweep of Systema (11-07-2026) found that **most of the Memrise engine already
exists**, built under handoff H211 and currently dark behind a feature flag. The work is
therefore mostly *export → import → wire → add the missing test modes → flip the flag* — not
a from-scratch build. Concretely, already present:

| Layer | What exists | Where |
|---|---|---|
| **FSRS engine** | Faithful PHP port of `py-fsrs` (FSRS-6, 21 weights) — schedule, grade, preview intervals | [`app/Services/Srs/Fsrs.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Srs/Fsrs.php), `FsrsCard.php`, `State.php`, `Rating.php` |
| **SRS data model** | Note-types, decks (system/public/private), cards, per-user review state, review log | [`app/Models/SrsNoteType.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SrsNoteType.php), `SrsDeck.php`, `SrsCard.php`, `SrsReviewState.php`, `SrsReviewLog.php` |
| **Review orchestration** | Today's queue (due + new-per-day), grade, interval preview, logging | [`app/Services/Srs/ReviewService.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Srs/ReviewService.php) |
| **Review UI** | Livewire deck-pick → reveal → grade screen | [`app/Livewire/SrsReview.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Livewire/SrsReview.php) + `resources/views/livewire/srs-review.blade.php` |
| **Route + flag** | `/dvaram/srs`, registered only if `config('srs.enabled')` | [`config/srs.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/srs.php), `routes/web.php` |
| **Gamification** | "Prana" XP/points, ranks, shop; streaks; leaderboard | [`app/Services/Prana/PranaService.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Prana/PranaService.php), `app/Services/StreakService.php`, `app/Support/PranaLeaderboard.php` |
| **Daily goals** | Goal-setting + check-ins | [`app/Models/Goal.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Goal.php), `GoalCheckin.php` |
| **Multi-script words** | Devanagari / IAST / Cyrillic columns per headword; SLP1-aware glossary | [`app/Models/DictionaryWord.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DictionaryWord.php), [`app/Services/SanskritGlossary.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/SanskritGlossary.php) |

So no new SRS algorithm, no new points/streak/leaderboard system, no new script-storage
model is needed. **What is genuinely missing:** the Memrise-style *test modes* (multiple
choice, typing, tapping), the *mems/UGC* layer, the *Devanagari typing input*, the *deck
authoring/import path*, and the *Hindi* content dimension.

---

## 1. Locked decisions (from the 11-07-2026 scoping questions)

| # | Decision | Choice |
|---|---|---|
| D1 | Feature scope | **Full clone** — SRS review core + all test modes + gamification + mems/UGC |
| D2 | SRS engine | **FSRS** — and it already exists in-repo (`Fsrs.php`); no adoption work, only reuse |
| D3 | Memrise export | **Authenticated full export** — course owner has login; pull all levels/columns/media |
| D4 | Audio | **Deferred** — ship text-only; audio is its own later phase (aligns with the standing audio-gap workstream) |

---

## 2. Memrise feature → Systema status (gap map)

| Memrise feature | Systema status | Action |
|---|---|---|
| Courses → Levels → items | `SrsDeck` (+ `course_id`/`lesson_id`), `SrsNoteType` | **Reuse.** Map Memrise level → deck or deck-section |
| "Learn" (new cards) | `ReviewService` new-per-day | **Reuse** |
| "Review" (SRS scheduling) | FSRS `Fsrs.php` + `SrsReviewState` | **Reuse** |
| Classic / Speed review | Partial (review loop exists) | **Extend** — add a timed "speed" variant |
| Multiple-choice test | — | **Build** (distractor generator from deck) |
| Typing test (type the answer) | — | **Build** + Devanagari input |
| Tapping / tap-the-pairs | — | **Build** |
| Audio multiple-choice / listening | — | **Deferred** (D4) |
| "Difficult words" bucket | — | **Build** (flag lapsed cards; filter view) |
| Points / XP | Prana | **Wire** review events → `PranaService` |
| Streaks | `StreakService` | **Wire** (already daily-activity driven) |
| Leaderboard | `PranaLeaderboard` | **Reuse** |
| Daily goal | `Goal` / `GoalCheckin` | **Wire** a "N reviews/day" goal type |
| Mems (mnemonics) + images | — | **Build** (`SrsMem` model, per card/user, upvotes) |
| User-editable decks (UGC) | Decks have `user_id`/visibility | **Extend** — student deck CRUD UI |
| Water/plant progress cosmetics | — | **Skip** (cosmetic only) |
| Grammar bot / Pro AI | — | **Out of scope** |

---

## 3. Phased plan

Phases are ordered by *dependency and risk*, not calendar. P0 is time-critical (archive
sunset). P1–P4 are the core clone; P5 is the Hindi dimension; P6 is the deferred audio.

### P0 — Export the Memrise course (DO FIRST, time-critical) — [H569]
Memrise is **sunsetting community courses** with no published shutdown date; the archive can
go dark. Export before anything else.

- Course: `6679375` — «Продлёнка по санскриту», at
  [community-courses.memrise.com/…/6679375/…](https://community-courses.memrise.com/community/course/6679375/prodlenka-po-sanskritu/).
- **Tool (recommended):** [Eltaurus-Lt/CourseDump2022](https://github.com/Eltaurus-Lt/CourseDump2022)
  (Chrome extension) — exports **CSV + all media (audio/images) + alt answers + your progress**,
  batch, per level. Backup tool: [wilddom/memrise2anki-extension](https://github.com/wilddom/memrise2anki-extension)
  (Anki `.apkg` with media) as a cross-check of column mapping.
- **Endpoint shape** (for a scripted pull if the extension breaks): host
  `community-courses.memrise.com`, `v1.25` API — auth via `/v1.25/auth/web/`, course at
  `/community/course/{id}/`, level/things via `/aprender/preview?course_id=&level_index=` /
  `/v1.25/learning_sessions/preview/`; media base `static.memrise.com/`. (The old
  `app.memrise.com/ajax/get_level` XHR is removed — do not rely on pre-2022 scrapers.)
- **Deliverable:** raw export committed to Systema under a data folder (e.g.
  `database/seeders/data/memrise_6679375/`) — CSV per level + a `manifest.json` recording
  column meaning, level order, and export date. **Keep the audio/image files even though
  audio is deferred** (D4) — re-fetching after sunset may be impossible.
- **Also export any Hindi course** the owner holds on the same pass, same tooling (P5 input).

### P1 — Import + wire the existing SRS (the clone goes live behind the flag)
- **Schema map:** Memrise columns → an `SrsNoteType` field set (e.g. `devanagari`, `iast`,
  `translation_ru`, `translation_en`, `notes`). Reconcile with the parallel
  `Lesson.flash_cards` JSON already on lessons — decide migrate-into-SRS vs keep-separate
  (see Open Questions Q1).
  **Kochergina lesson 1 shipped 22-07-2026 (H1431):** `srs:import-kochergina-lesson1`
  reads `database/seeders/data/memrise_6502608/level_02.csv` (verified against the
  textbook's Занятие I, Упражнение II) into a dedicated system deck
  (`kochergina-lesson-1`, note type `kochergina_l1`) mapped onto exactly this field
  set — `devanagari`/`translation_en` stay absent per row (source has neither; the
  UI already tolerates missing fields, same as the existing Memrise cyrillic-only
  rows) and `notes` carries the textbook's parenthetical grammar-class tag
  (`(m)`/`(n)`/`(m,n)`) extracted from the gloss without dropping it from
  `translation_ru`. Deliberately **separate from** the generic
  `srs:import-memrise` decks for the same Memrise course (6502608) — the two
  pipelines coexist, per this section's own "dedicated deck" design. Q1
  (`Lesson.flash_cards` reconciliation) is still open — this lesson-1 deck did
  not touch `Lesson.flash_cards`.
- **Importer:** an artisan command + seeder that reads the P0 export, upserts
  `DictionaryWord` rows (dedup on IAST/`slug`), creates a **system deck** per Memrise level,
  and creates `SrsCard`s linked via `SrsCard.source_word_id`. Follow the existing Filament
  [`DictionaryWordImporter`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Imports/DictionaryWordImporter.php)
  pattern.
- **Wire gamification:** on each graded review, award Prana (`PranaService`), touch the
  streak (`StreakService`), and count toward a daily-review `Goal`.
- **Gate:** everything stays behind `SRS_ENABLED=false`; pilot with `SRS_ENABLED=true` on a
  staging cohort. Tests in `tests/Feature/Srs/` + `tests/Unit/Srs/` (PHPUnit, the H211
  convention).
- **Exit:** a real student can review the imported deck end-to-end and see XP/streak move.

### P2 — Test modes (the visible Memrise feel)
Build as Livewire components alongside `SrsReview`, each consuming the same deck/queue:
- **Multiple choice** — correct answer + N distractors sampled from the deck (same
  note-type, near length). Grades feed FSRS.
- **Typing test** — free-type the answer; needs a **Devanagari input** affordance (see P2b).
  Fuzzy-match against `alt answers` exported from Memrise.
- **Tap-the-pairs / word-tap** — assemble the answer from shuffled tokens.
- **Speed review** — timed variant of the review loop; ties into Prana bonus.
- **Difficult words** — a filter over cards with lapses `> 0`; its own review entry point.

### P2b — Devanagari / transliteration input widget
Typing-mode blocker for both languages. Options (Open Question Q2): a lightweight
IAST→Devanagari live transliterator (client-side), an on-screen Devanagari keyboard, or
accept IAST/Cyrillic input and normalize server-side via the existing
`SanskritGlossary::normalizeKey()` / SLP1 path. Reuse, don't reinvent — the repo already
stores all three scripts per word.

### P3 — Mems (mnemonics) + UGC
- **`SrsMem`** model: `user_id`, `card_id` (or `word_id`), `text`, optional `image`,
  `upvotes`. Shown on the reveal step; "best mem" surfaces the top-voted.
- **User decks:** student-facing CRUD over `SrsDeck` (already carries `user_id` +
  `visibility`) — create a private deck, add cards, optionally publish (`public`).
- Moderation hook for public mems/decks (reuse Filament editor panel).

### P4 — Polish + gamification depth
- Daily-goal widget on the student cabinet; streak freeze; leaderboard surfacing for SRS.
- "Difficult words" and "words due today" badges.
- Analytics: retention curve per deck (FSRS already logs everything in `SrsReviewLog`).

### P5 — Hindi dimension
The engine is language-agnostic (`SrsNoteType.language`, `SrsDeck` per language). Hindi needs
**content + light script handling**, not new machinery:
- Import a Hindi Memrise course (P0 tooling) into Hindi decks/note-types.
- Hindi shares Devanagari, so the P2b input widget covers it; transliteration tables differ
  (Hindi is not SLP1) — add a Hindi romanization map, or accept Devanagari-only input for
  Hindi typing mode.
- Decide whether Hindi is a **separate track** or interleaved (Open Question Q3).

### P6 — Audio (deferred, D4)
Out of the initial ship. When picked up: re-host the Memrise-exported audio (saved in P0)
for words that had it; TTS or recordings for the rest. Coordinate with the standing
Sanskrit-audio-gap workstream (the known critical gap for Sanskrit-HUB / SanskritKaraoke) —
do not solve audio twice.

---

## 4. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Memrise archive shuts down mid-project | **P0 first, this week.** Commit the raw export + media immediately. |
| `Lesson.flash_cards` JSON vs `SrsCard` divergence | Reconcile in P1 (Q1); pick one source of truth. |
| Devanagari typing UX is fiddly | P2b spike early; fall back to romanized input normalized server-side. |
| Feature flag left off / pilot never runs | P1 exit criterion is a live staging pilot cohort, not just green tests. |
| Watcher reverts uncommitted work in this repo | Author in scratchpad, land + commit atomically (`/watcher-safe-commit`). |
| Hindi transliteration ≠ Sanskrit SLP1 | Treat Hindi romanization as its own map in P5; don't overload the SLP1 path. |

---

## 5. Open questions (@DECIDE)

1. **`Lesson.flash_cards` reconciliation.** Lessons already carry a simpler `flash_cards`
   JSON column. Migrate those into the SRS `SrsCard` system (one source of truth), or keep
   the two independent (lesson-embedded quick cards vs the SRS trainer)? Recommendation:
   migrate into SRS so all cards share scheduling + gamification.
2. **Typing-mode input.** Live IAST→Devanagari transliterator, on-screen Devanagari
   keyboard, or accept romanized input + server-side normalize? Recommendation: start with
   romanized-in + normalize (cheapest, reuses existing script logic), add a live
   transliterator in P2b polish.
3. **Hindi placement.** Separate Hindi track (own decks, own menu) or interleaved with
   Sanskrit? Affects navigation and the leaderboard scoping.
4. **UGC moderation policy.** Are public mems/decks pre-moderated or post-moderated? Affects
   P3 scope.

---

## 6. Handoff & next action

The build is tracked by **H569** —
[H569-Sonnet_Systema_memrise-clone-srs-sa-hi_11.07.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/H569-Sonnet_Systema_memrise-clone-srs-sa-hi_11.07.26.md).
Start with P0 (export) — it is the only time-critical phase.

```
Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H569-Sonnet_Systema_memrise-clone-srs-sa-hi_11.07.26.md and execute it.
```
Run from the Systema-Sanscriticum repo; executor tier Sonnet (P0/P1 are mechanical
export+import following the H211 patterns; escalate P2b/P3 UX judgment to Opus if needed).

_Dr. Mārcis Gasūns_
