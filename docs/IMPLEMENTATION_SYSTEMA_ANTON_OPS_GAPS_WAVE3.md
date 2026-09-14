# IMPLEMENTATION — Wave 3 (Kinescope pilot)

_Created: 23-07-2026 · Last updated: 24-07-2026_

File-level build sequence for **Wave 3 only** (D9). Architecture:
[docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md)
§W3. Acceptance:
[docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md)
§W3. No money code; `kinescope_pilot` defaults OFF (D12). Reuses H1450 W2
Kinescope adapter in `public/js/video-resume.js`.

**Executor:** Grok 4.5 (`grok-4.5`) via xAI — user-authorized override of the
Sonnet 5 handoff lock (same standing override as H1450). **True redo 24-07-2026**
(user: “true redo” after first merge #665).

### True-redo delta (24-07-2026)

- `KinescopePilot::candidateUrlFromLesson` / `embedForLesson` — multi-field resolve.
- `VideoEmbed::kinescopeId` reserved-segment reject + query/fragment-safe match.
- `.env.example` — `VIDEO_RESUME` / `KINESCOPE_PILOT` / `KINESCOPE_PILOT_COURSE_ID`.
- Extra unit + feature tests (misfiled youtube_url, reserved paths).

---

## Step C1 — feature flag

Files: `config/features.php` — `'kinescope_pilot' => (bool) env('KINESCOPE_PILOT', false)`
with a doc comment in the repo idiom. Depends on: none.

## Step C2 — pilot course scope config

Files: `config/video.php` (new) — `kinescope_pilot_course_id` from
`env('KINESCOPE_PILOT_COURSE_ID')` (null/empty = no course in pilot even if
flag ON). Depends on: none.

## Step C3 — VideoEmbed Kinescope recognition

Files: `app/Support/VideoEmbed.php` — `kinescopeId()` / `isKinescope()` +
`embed()` returns `https://kinescope.io/embed/{id}` for common URL shapes
(`kinescope.io/{id}`, `/embed/{id}`, `/video/{id}`). Parser is global (landing
pages already referenced Kinescope); **player scope** is enforced by C4, not
by the parser. Depends on: none.

## Step C4 — KinescopePilot gate

Files: `app/Support/KinescopePilot.php` (new) —
`isActiveForCourse(?int)` / `embedForCourse(?string $url, ?int $courseId)`.
Returns embed URL only when flag ON **and** course id matches **and** URL is
Kinescope; otherwise null (caller must leave player behaviour unchanged).
Depends on: C1, C2, C3.

## Step C5 — controller + lesson player

Files:
- `app/Http/Controllers/StudentController.php` — compute `$kinescopeEmbedUrl`
  via `KinescopePilot::embedForCourse($lesson->video_url, $course->id)` and
  pass to the view.
- `resources/views/student/lesson.blade.php` — when `$kinescopeEmbedUrl` set:
  load Kinescope Player SDK script, default `player: 'kinescope'`, render
  `#kinescope-player` iframe, show host switcher button alongside YouTube/RuTube
  when those ids exist. Reuses `window.VideoResumeAdapters.kinescope` from W2
  (SDK now present → `available()` can return true; resume still gated by
  `video_resume` flag). Depends on: C4, H1450.

## Step C6 — comparison memo

Files: `docs/KINESCOPE_PILOT_COMPARISON_2026.md` — native Kinescope
resume/chapters/analytics/DRM vs our iframe + heartbeat + W2 adapters.
Informs any future catalogue decision; does **not** migrate catalogue (D4).
Depends on: C5.

## Step C7 — tests

Files:
- `tests/Unit/VideoEmbedTest.php` — Kinescope URL forms.
- `tests/Feature/Video/KinescopePilotTest.php` — flag off / wrong course /
  non-Kinescope URL → null; pilot match → embed; HTTP lesson page shows
  `#kinescope-player` only for the configured pilot course (deposit+free
  lesson access path). Depends on: C3–C5.

## Step C8 — changelog + activation row

Files: `CHANGELOG.md` (`[Unreleased]` → `### Added`), `DEPLOY_QUEUE.md`
(row 48: no migration; activation is Kinescope account +
`KINESCOPE_PILOT_COURSE_ID=<id>` + `KINESCOPE_PILOT=true` + `config:clear`).
Depends on: all above.

---

## Step order

```
C1 ─┐
C2 ─┼→ C4 → C5 → C6 → C7 → C8
C3 ─┘
```

## Decisions taken (D11)

- **Pilot host default when active.** When the pilot is active for the lesson
  and `video_url` is Kinescope, the page defaults `player` to `kinescope`
  (not YouTube/RuTube), even if YT/RuTube ids also exist — the pilot's point
  is to evaluate Kinescope as primary for that course. Switcher still offers
  YT/RuTube when their ids parse from the lesson fields.
- **`video_url` is the Kinescope source.** Lesson already has `video_url` for
  generic host links; W2 left it unrendered. W3 renders it **only** through
  the pilot gate. No new DB column.
- **Chapters + analytics are native.** Not reimplemented in Laravel — they
  ride inside the Kinescope iframe/SDK when the human account enables them
  in the Kinescope dashboard. Documented in the comparison memo.
- **No money code.** Flag OFF; no Payment/Tochka/checkout touch.

_Dr. Mārcis Gasūns_
