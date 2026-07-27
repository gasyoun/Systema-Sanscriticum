# IMPLEMENTATION — Online Sanskrit Games · Wave 1 (ordered)

_Created: 26-07-2026 · Last updated: 26-07-2026_

Index: [PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md)

**Scope of this doc:** Wave 1 only — platform (guest UUID, 5-play gate, CTA metric) + P0/P1 engine-fill packs.  
**Not in this wave:** SRS onboarding (Wave 2), csl-guides (Wave 1b), new engines, audio, multiplayer, money.

**Worktree required.** Session-unique path off `origin/main`.

---

## Preconditions

- [ ] Human named the handoff / `/go` (do not self-start from ambient GTD)
- [ ] Tier-0 money/GC capacity free enough to run (D22)
- [ ] Read PLAN decisions D1–D22 and autonomy contract
- [ ] `git fetch origin` + clean worktree from `origin/main`

---

## Step 0 — Baseline

1. Read `public/lila/gate.js`, `telemetry.js`, `GameTelemetryController`, migration for `game_events`.
2. Run existing games-related tests if present; note funnel command: `php artisan games:funnel --days=7`.
3. Confirm no concurrent WIP on games in `.ai_state.md`.

**Done when:** short baseline note in handoff Dev Notes.

---

## Step 1 — Guest UUID in telemetry

**Touches:** `public/lila/telemetry.js`, possibly `GameTelemetryController` + migration if `guest_id` column missing, feature tests.

1. On first load, if no `localStorage.exercises.guest_id`, generate UUID v4 and store.
2. Include `guest_id` on every `POST /api/games/event` payload.
3. Persist `guest_id` on the event row; if `auth()->check()`, also set `user_id`.
4. Tests: guest_id required/accepted; authenticated path sets both.

**Default on ambiguity:** UUID in localStorage only (not cookie) — already D10.

**Done when:** PHPUnit green for event ingest with guest_id.

---

## Step 2 — Five free plays per family

**Touches:** `public/lila/gate.js`, any pages importing it, tests if JS is covered / manual smoke checklist.

1. Replace one-global free play with **per-family** counter (`sort`, `match`, `cloze`, `ligatures`, `roots`, …).
2. Free while `plays[family] < 5`; on 6th attempt show register nudge (existing copy tone).
3. Authenticated users: no gate (current behavior).
4. Emit `gate_hit` when nudge shows; keep `cta_click` on «Начать бесплатно».

**Default:** family = first path segment under `/lila/`.

**Done when:** smoke on two families proves independent counters.

---

## Step 3 — CTA → register metric plumbing

**Touches:** telemetry events, `games:funnel` report, Filament funnel page if it hardcodes steps.

1. Ensure funnel stages: `started` → `completed` → `gate_hit` → `cta_click` → (registration is server-side: join users created after cta by guest→user merge or time-window heuristic documented in VERIFICATION).
2. Document the exact SQL/report definition of “CTA clicker completed registration” for the ≥15% KPI.
3. Prefer: on first authenticated `games/event` after register, write `user_id` onto recent events for same `guest_id` (merge job or inline).

**Done when:** `games:funnel` shows stages including post-auth merge rate.

---

## Step 4 — Locale toggle (RU/EN) shell

**Touches:** shared small script or gate/telemetry sibling; pack configs for P0 strings.

1. `localStorage.exercises.locale` default `ru`.
2. Toggle control on catalogue + each new pack.
3. Pack configs carry `i18n: { ru: {task, feedback}, en: {...} }`.

**Default if EN copy missing:** fall back to RU (never blank UI).

**Done when:** one pack switches task string in both locales.

---

## Step 5 — P0 pack G-C01 (vowel length sort)

**Touches:** `public/lila/sort/vowel-length/index.html` (+ optional data file), catalogue card on `public/lila/index.html` and `sort/index.html`.

1. Curate 24 items: long vs short vowel groups in Cyrillic+IAST (A0, no Devanāgarī required).
2. Mount `SortExercise` with 2 groups; `perRound` ≤ 6.
3. Provenance comment in HTML: author + date; spot-check not needed for phonology pairs (no gloss).
4. Wire `telemetry.js` `data-drill="sort/vowel-length"`.

**Done when:** manual complete round + event `completed` fires.

---

## Step 6 — P0 pack G-C02 (IAST↔Cyrillic match)

**Touches:** `public/lila/match/iast-cyrillic/index.html`, catalogue links.

1. 40 pairs; verify with a one-off script calling vendored/local util if available, else hand-check sample of 20.
2. Mount `MatchExercise`; RU/EN task strings.
3. Telemetry drill id `match/iast-cyrillic`.

**Done when:** smoke complete + provenance note in README or HTML comment.

---

## Step 7 — P0 pack G-C03 (Kochergina L1 free match)

**Touches:** `public/lila/match/kochergina-l1/index.html`, optional generator from `database/seeders/data/memrise_6502608/level_02.csv` or Kochergina deck source.

1. Subset ≤ 20 pairs (IAST/Cyrillic ↔ RU gloss) from verified lesson-1 fixture (H1431).
2. Do **not** run production `srs:import-*` as part of this step.
3. Provenance: cite CSV path + H1431; spot-check ≥20 rows if pack ≥20 (D15).

**Done when:** pack ships; README lists source path.

---

## Step 8 — P1 packs (same pattern)

In order: **G-C04** roots top-25 RU faces → **G-C05** ligatures top-10 hints → **G-C06** root-rank cloze.

Reuse `roots/data.js` / ligatures data; do not hand-edit generated files — regenerate via existing scripts when needed.

**Done when:** three packs linked from catalogue; each has telemetry drill id.

---

## Step 9 — Catalogue + copy

**Touches:** `public/lila/index.html`, family indexes, free-play banner text (5 plays).

1. Update free banner to state five plays per family (honest scarcity).
2. A0-first ordering: new packs above advanced ones where possible.
3. RU default; EN strings for new packs.

---

## Step 10 — Tests, pint, PR

1. PHPUnit for API/migration changes; full suite if money-adjacent files untouched (still run Feature games + SRS if import shared).
2. `./vendor/bin/pint` on PHP changes.
3. PR description: decisions table pointer, packs list, flag/gate behavior, **not** enabling prod flags.
4. Merge per handoff autonomy; add DEPLOY_QUEUE only if ops must warm caches (static files usually deploy with app).

**Done when:** PR merged; handoff flipped deferred→done in registry when executed.

---

## Explicit non-steps (Wave 1)

- Do not implement `SrsOnboardingFromGames` (Wave 2 handoff)
- Do not open csl-guides PR unless handoff is Wave 1b
- Do not add audio, multiplayer, or new `engine.js` families
- Do not set `SRS_ENABLED=true` in prod

---

## File touch map (expected)

| Path | Steps |
|---|---|
| `public/lila/telemetry.js` | 1, 3 |
| `public/lila/gate.js` | 2 |
| `app/Http/Controllers/Api/GameTelemetryController.php` | 1, 3 |
| `database/migrations/*game_events*` | 1 if needed |
| `app/Console/Commands/GamesFunnelReport.php` | 3 |
| `public/lila/sort/vowel-length/` | 5 |
| `public/lila/match/iast-cyrillic/` | 6 |
| `public/lila/match/kochergina-l1/` | 7 |
| `public/lila/roots/*` or faces | 8 |
| `public/lila/ligatures/*` | 8 |
| `public/lila/cloze/root-rank/` | 8 |
| `public/lila/index.html` | 9 |
| `tests/Feature/...` | 1, 3, 10 |

---

_Dr. Mārcis Gasūns_
