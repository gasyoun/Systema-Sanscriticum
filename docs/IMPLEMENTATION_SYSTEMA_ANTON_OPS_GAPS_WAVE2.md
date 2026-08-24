# IMPLEMENTATION — Wave 2 (in-video resume)

_Created: 22-07-2026 · Last updated: 22-07-2026_

File-level, step-ordered build sequence for **Wave 2 only** (D9). Architecture:
[`docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md)
§W2. Acceptance: [`docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md)
§W2. Nothing here touches money code; the migration is additive; `video_resume`
defaults OFF (D12).

---

## Step C1 — feature flag

Files: `config/features.php` — `'video_resume' => (bool) env('VIDEO_RESUME', false)`
with a doc comment in the repo idiom. Depends on: none (read by every later step).

## Step C2 — additive migration + model

Files: `database/migrations/2026_07_22_140000_add_resume_position_to_lesson_views_table.php`
(new — `last_position_seconds`, `max_position_seconds`, `video_duration_seconds`,
all `unsignedInteger`/nullable, no touch to existing columns),
`app/Models/LessonView.php` (+3 fillable, +3 integer casts). Depends on: none.

## Step C3 — heartbeat payload extension (reuse the endpoint)

Files: `app/Http/Requests/Activity/HeartbeatRequest.php` (+`position`/`duration`,
both `sometimes|integer|min:0` — no new endpoint, no new auth surface),
`app/Http/Controllers/Api/HeartbeatController.php` (writes `last_position_seconds`
+ `video_duration_seconds` unconditionally when present; `max_position_seconds`
computed as `max(existing, incoming)` **in PHP**, not SQL `GREATEST()` — that
function isn't portable to SQLite, which is what the test suite runs on). When
`position`/`duration` are absent from the request body, behaviour is byte-for-byte
identical to before H1450. Depends on: C2.

## Step C4 — host-agnostic JS adapter module

Files: `public/js/video-resume.js` (new) — `window.VideoResumeAdapters`, one
adapter per host (`youtube`, `rutube`, `vk`, `kinescope`, `vimeo`) per D8's table.
Each adapter exposes `available()`/`poll()`/`parseMessage()`/`seek()`; every SDK
call is wrapped in try/catch so one host's failure can't break another or the
page. **Only `youtube` and `rutube` are exercised today** — they're the only two
hosts `student.lesson` actually renders an iframe for (verified: no VK/Kinescope/
Vimeo embed exists anywhere in the lesson player, `video_url` on `Lesson` is
parsed but never rendered there — out of scope for W2, that's a separate gap).
`vk`/`kinescope`/`vimeo` adapters are real, SDK-shaped code, but their
`available()` always returns `false` today (no matching global SDK object, no
matching iframe) — this is the D8 "degrade gracefully" contract exercised for
real, not simulated, and is exactly what W3 ("reuse W2's Kinescope resume
adapter") will flip to `true` by loading the SDK script and rendering an iframe.
Depends on: none (pure client-side).

## Step C5 — wire the player + resume banner

Files: `resources/views/student/lesson.blade.php` — the existing inline
`x-data` (lines ~104–185 pre-H1450) already had ad-hoc postMessage code for
YouTube/RuTube current-time tracking (used by the transcript sync feature, which
predates and is independent of resume). That code is refactored to call
`window.VideoResumeAdapters[this.player]` instead of duplicating the postMessage
JSON inline — **same wire protocol, zero behaviour change** for transcript sync
regardless of the `video_resume` flag. New: `onVideoTick()` (updates
`currentTime`/`videoDuration`, and — only when `videoResumeEnabled` — dispatches
a `lesson-video-tick` `CustomEvent` on `window`), a "продолжить с HH:MM" banner
(`x-show="resumeOffered"`, rendered server-side only when
`$videoResumeEnabled && $resumePosition`), `resumePlayback()`. `StudentController::showLesson()`
gained `$videoResumeEnabled`/`$resumePosition`/`$resumeDuration` (looked up from
`LessonView` only when the flag is on — a no-op query otherwise doesn't even run).
Depends on: C2, C4.

## Step C6 — heartbeat client bridge

Files: `public/js/lesson-heartbeat.js` — listens for `lesson-video-tick` (fired
by C5's `onVideoTick()` only when the flag is on) and folds `position`/`duration`
into the existing `payload()` builder used by both `send()` and `sendBeacon()`.
When no tick has ever fired (flag off, or lesson has no video), the request body
is identical to pre-H1450. Depends on: C3, C5.

## Step C7 — tests

Files: `tests/Feature/Activity/HeartbeatResumeTest.php` (new, 4 cases):
persists `last_position_seconds`/`max_position_seconds`/`video_duration_seconds`
on first heartbeat with position; `max_position_seconds` never regresses below a
pre-seeded higher value (single heartbeat call, deliberately avoiding the
`Redis::set(..., 'NX')` per-lesson throttle in `HeartbeatController` — a second
real HTTP call within the 20s window would be silently throttled if a real Redis
happens to be reachable in the run environment, which would make the test
environment-dependent); a heartbeat with no `position`/`duration` at all behaves
exactly as before; a `Schema::hasColumns` check pins the migration as additive.
Manual QA note in place of a JS test harness (repo has no JS test runner —
`package.json` confirmed, `vitest`/`jest` absent): each adapter's `available()`
was read-verified to return `false` when its global SDK object is undefined
(`vk`/`kinescope`/`vimeo` on every page today) and `true` only when the target
iframe exists AND has a `contentWindow` (`youtube`/`rutube`) — the no-op path is
exercised by simply loading `/course/{slug}/lesson/{id}` with `video_resume` on
and confirming no console errors fire from the three unused adapters (they're
never even polled, since `this.player` only ever becomes `'youtube'`/`'rutube'`/
`'none'` — the switcher UI has no VK/Kinescope/Vimeo option). Depends on: C2, C3.

## Step C8 — changelog + activation row

Files: `CHANGELOG.md` (`[Unreleased]` → `### Added`), `DEPLOY_QUEUE.md` (row 46:
migration is part of the standard `php artisan migrate`, activation is
`VIDEO_RESUME=true` + `config:clear`, no external prerequisite — no VK app
approval needed since VK isn't live in this wave). Depends on: all above.

---

## Step order (dependency graph)

```
C1 ─┐
C2 ─┼→ C3 ─┐
C4 ─┘      ├→ C5 → C6 → C7 → C8
           └──────┘
```

## Decisions taken (D11 ambiguity policy)

- **R3 fallback applied without waiting on a live spike.** The VERIFICATION
  doc's S2 spike ("confirm RuTube/VK expose a usable current-time API") called
  for checking RuTube and VK specifically before committing to the adapter
  arch. RuTube's postMessage protocol was already load-bearing in this repo
  (the pre-existing transcript-sync code proves it works in production today);
  VK has **no existing embed anywhere in the student lesson player** to spike
  against — there is nothing to verify live without first building the render
  path VideoEmbed→lesson.blade.php integration, which is out of W2's stated
  scope (persist position + adapter layer, not "add new video hosts to the
  player"). Per R3's own marked default ("ship YouTube+Vimeo+Kinescope first if
  RuTube/VK prove flaky"), and since RuTube already demonstrably isn't flaky
  here, the shipped set is YouTube+RuTube (both live and proven) with
  VK/Kinescope/Vimeo adapters built to spec but held inert pending an actual
  embed to attach to — logged per D11, pressing on.
- **`video_url` (generic VK/Vimeo link field on `Lesson`) stays unrendered.**
  Confirmed via grep: `StudentController::showLesson()` already reads
  `$lesson->video_url` (only to suppress the "upcoming session" placeholder),
  but no `<iframe>` for it exists in `student.lesson.blade.php`. Wiring
  `VideoEmbed::embed($lesson->video_url)` into the player switcher would be a
  genuine, separately-scoped feature (new host in the *rendering* path, not the
  *resume* path) — flagged as a gap, not built here.

_Dr. Mārcis Gasūns_
