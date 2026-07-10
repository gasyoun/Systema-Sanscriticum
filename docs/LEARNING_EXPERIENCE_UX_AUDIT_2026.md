# Learning Experience UX Audit — 2026

_Created: 09-07-2026 · Last updated: 09-07-2026_

**Scope:** the day-to-day learning loop once a student already has access — course home
(`/dvaram/course/{slug}`) and the lesson page (`/dvaram/course/{slug}/lesson/{id}`), covering
video, transcript, materials, notes, homework, completion, and locked-lesson messaging.
Code-grounded against
[`resources/views/student/course.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/course.blade.php),
[`resources/views/student/lesson.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/lesson.blade.php),
[`resources/views/student/partials/homework.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/partials/homework.blade.php),
[`resources/views/student/partials/homework-alerts.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/partials/homework-alerts.blade.php),
`StudentController::showCourse/showLesson/completeLesson/saveNote`,
[`app/Models/Lesson.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Lesson.php),
[`docs/student-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md),
[`tests/Feature/Student/LessonAccessTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Student/LessonAccessTest.php),
[`tests/Feature/LessonMaterialsAccessTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/LessonMaterialsAccessTest.php),
[`tests/Feature/CourseCardNextLessonTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CourseCardNextLessonTest.php).
Twin of [`docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md) (H297) —
that one ends at "access opens," this one starts there. Document-only — no Blade/code changes.

## 1. Learning north star

Once a student is inside a course, the platform's only job is to make the next 20 minutes of
learning feel calm, guided, and easy to pick back up:

1. **Land on "where I left off," not a list to re-scan.** A student returning after three days
   should not have to remember which lesson they were on.
2. **Know instantly whether this lesson is a live class, a recording, or reading/exercise
   material** — the mental posture ("show up now" vs. "watch whenever") is different for each.
3. **Never lose a half-written note or an in-progress homework draft.**
4. **Always know why something is locked, and what unlocks it**, without leaving the page to
   find out.
5. **See progress reflected back immediately** — completing a lesson should feel like the
   platform noticed.

The building blocks for all five exist in the code today (progress bar, per-lesson lock badges,
draft-save for homework, a `notes` textarea, an upcoming-session Zoom card). The gaps are almost
entirely about **sequencing and orientation** — the course page shows everything at once with no
"start here" signal, and the lesson page reorders its most learning-critical panel (transcript)
behind homework on mobile.

## 2. Course home findings

`course.blade.php` is a hero header (title, description, progress bar, chat button, "download
all materials") over a flat, un-sectioned list of every lesson in `sort_order`. Access, lock, and
completion badges are per-card (`course.blade.php:105-210`); the routing logic is
`Lesson::isUnlockedBy()` (`app/Models/Lesson.php:255-262`).

**C1 — No "next lesson" signal on the course page itself.** The "continue where I left off" /
"first lesson: start here" logic exists — but only on the *dashboard* card
(`CourseCardNextLessonTest.php:47-59`, `:61-74`), not on this page. A student who clicks through
from the dashboard into "Программа курса" sees a plain, undifferentiated list and has to
visually scan for the first non-green checkmark to find their actual next step. For a course
with 20+ lessons this is real friction every single return visit — exactly the "calm, guided"
promise the audit is testing against.

**C2 — Locked-lesson cards explain *what* blocks the lesson but not *why*.** Each locked card
shows "Докупить блок {{ block_number }}" and a "Купить" button that jumps to
`shop.course.show#tariffs` (`course.blade.php:113,150-152,172-176,191-196`) — clear on the
mechanism, silent on the price or what that block actually contains. The student has to leave
the course entirely and land on a pricing page to find out if it's ₽500 or ₽15,000. This is a
direct input to the Self-Service Support audit's "why is this locked" question: the course page
answers "which block," not "what would it cost me right now."

**C3 — No distinction between live-class lessons and self-paced/recorded ones in the list.**
Every card shows the same play/lock/check iconography and only `duration` as metadata
(`course.blade.php:161-166`); nothing signals "this is a live Zoom session on a fixed date" vs.
"recording, watch anytime." That distinction only becomes visible once the student opens a
specific lesson and sees either a video player or the "Занятие состоится" Zoom card
(`lesson.blade.php:214-243`). A student skimming the course list to plan their week has no way to
tell live-vs-recorded apart without opening each lesson.

**C4 — Homework needing revision is invisible from the course page.** `homework-alerts.blade.php`
("Преподаватель вернул работу на доработку") is included only on `dashboard.blade.php:24`, not
on `course.blade.php`. A student who lands directly on the course page (a bookmark, a link from
the curator chat) sees a completed-looking lesson card with no hint that their submitted
homework for it was sent back — they'd only discover it by opening the lesson and scrolling to
the homework panel.

**C5 — "Materials" badge on unlocked cards doesn't say what kind or how many.** The list-item
badge is a bare "Материалы" label if `count($lesson->attachments) > 0`
(`course.blade.php:167-171`) — no count, no type (PDF vs. audio vs. video), which matters for a
student deciding whether to open a lesson on mobile data or wait for wifi.

## 3. Lesson page findings

`lesson.blade.php` splits into a sticky two-column grid on desktop (`≥768px`, `.lesson-layout`
CSS at `lesson.blade.php:33-54`) — video/description/homework on the left, a
transcript/materials/notes tab switcher on the right — collapsing to a single stacked column on
mobile with the side column rendered *after* the entire left column.

**L1 — On mobile, the transcript sits below the homework block, not next to the video.** Because
`.lesson-side-col` only becomes a sticky sidebar at `md:` and otherwise just falls after
`.lesson-main-col` in DOM order (`lesson.blade.php:19-32`, `189` → `444`), a mobile student
following along with the transcript has to scroll past the video, the nav dropdown, the
description, *and* the full homework panel before reaching it. For "watch at your pace" recorded
lessons — where the transcript is the primary navigation aid via `seekTo()`
(`lesson.blade.php:505-520`) — this inverts the priority order specifically on the device most
students will actually use for revisiting a lesson.

**L2 — Notes have no autosave and no unsaved-changes warning.** The notes textarea only persists
on explicit "Сохранить изменения" submit (`homework.blade.php`-sibling flow via
`saveNote()`, `StudentController.php:612-633`; form at `lesson.blade.php:538-548`). There is no
`x-data` dirty-tracking, no beforeunload guard, no autosave debounce. A student who jots a note
mid-video and then clicks a transcript timestamp, switches tabs, or closes the page loses it
silently — directly contradicts the "recording-friendly" north star, since notes-while-rewatching
is exactly the workflow this feature exists for.

**L3 — "Завершить" (complete) is a single irreversible-feeling manual action with no automatic
progress signal from actually watching.** `completeLesson()` only fires on the button POST
(`StudentController.php:554-607`); nothing in the view ties video watch-time to completion state
or even a soft "you've watched most of this" hint. A student can be 30 seconds into a 40-minute
recording and the button looks identical to one who has never opened the lesson. This is fine as
a design choice (self-reporting, not tracked-view-based) but the page never *says* that — it
reads as if the system might already know, which sets a false expectation.

**L4 — Lesson nav dropdown hides progress state behind a click.** The "Перейти к другому уроку"
expandable list (`lesson.blade.php:334-414`) does show lock/complete/current badges once opened,
which is good — but it is closed by default and the trigger button gives no numeric progress hint
(no "3 of 12 done"), so a student has to open it just to orient themselves within the course,
duplicating information the course page already has (and C1 shows the course page itself doesn't
surface it well either).

**L5 — Homework "no homework" state and the actual homework form use different visual registers.**
When `homework_enabled` is false, the lesson page shows a muted grey "Домашнего задания для
этого урока нет" card (`lesson.blade.php:429-436`); when enabled but `awaitingPrompt`, a separate
amber "Задание еще не задано" card (`homework.blade.php:107-111`). Two different "nothing to do
here" states with different colors and copy for what is, from the student's point of view, the
same outcome ("no action needed right now") — a small but real consistency gap for a student
paging through a course quickly.

**L6 — Curator escalation (`Куратору` Telegram button) and the AI-chat widget (course-level chat
button) both live in the lesson header with no differentiation of when to use which.** Both
`x-course-chat-button` and the Telegram "Написать куратору" link sit side-by-side
(`lesson.blade.php:304-315`); `docs/student-manual.md` §7 describes them as the same underlying
AI-curator chat with human escalation via a trigger phrase, but the lesson page presents them as
two separate, equally-weighted buttons with no label clarifying that one *is* the escalation path
for the other.

## 4. Recorded-course learning mode

The platform correctly branches into three lesson states — `player === 'youtube'/'rutube'`
(recording available), `upcomingSession` set (live class not yet held), and bare "Видео
недоступно" (neither) (`lesson.blade.php:104-106, 214-243`) — but nothing on the page names the
state explicitly as "recorded" once a video *is* present. A recording plays exactly like a
would-be-live embed with no on-page microcopy such as "запись — смотрите в своем темпе," and nose
the only place "watch at your own pace" language exists at all is in curator-facing
`onboarding-student.md`/`student-manual.md`, not the student-facing lesson chrome itself
(`student-manual.md:70-72` documents the *mechanism* — auto-swap from Zoom card to video after
the session — but the lesson page never echoes that reassurance to the student in the moment).
Timecodes-in-description are auto-linked to `seekTo()` (`lesson.blade.php:84-91, 420-421`), which
is a strong "watch at your pace" affordance already shipped — it just isn't reinforced anywhere
else on the page (course list, lesson header) as a feature the student should expect and use, and
nothing on the lesson page itself names a loaded recording as recorded/watch-anytime.

## 5. Prioritized tickets

1. **"Continue" entry point on the course page (C1)** — surface the same next-lesson resolution
   `CourseCardNextLessonTest` already exercises on the dashboard as a small sticky/pinned card at
   the top of the course lesson list, above the flat list. *Effort: small* — the resolution logic
   already exists for the dashboard card; this reuses it in a second view.
2. **Reorder the mobile lesson layout so the tab panel (transcript/materials/notes) comes before
   homework (L1)** — CSS/DOM order change only in `.lesson-main-col`/`.lesson-side-col`, no
   Alpine state changes. *Effort: small.*
3. **Notes autosave + unsaved-changes guard (L2)** — debounce-save on textarea input (reusing the
   existing `saveNote` POST endpoint) plus a `beforeunload` warning when dirty. *Effort:
   small-medium.*
4. **Inline price/contents on locked course-page cards (C2)** — show the block's price and a
   one-line "what's inside" directly on the locked card instead of only after navigating to
   `shop.course.show#tariffs`. *Effort: medium* — needs the tariff price surfaced to
   `showCourse()`, which doesn't currently pass it to the view.
5. **Live-vs-recorded badge on course-list cards (C3)** — a small "Прямой эфир" / "Запись" tag
   derived from `lesson_date` + `hasVideo()` (`Lesson.php:118-121`), mirroring the state the
   lesson page already computes. *Effort: small.*
6. **Surface `homework-alerts` on the course page too, not just the dashboard (C4)** —
   `@include('student.partials.homework-alerts')` already exists and works; add it to
   `course.blade.php`. *Effort: trivial.*
7. **"Recorded — watch at your pace" microcopy near the player once a recording is loaded (§4)**
   — one line under the video source-switcher, shown when `player !== 'none'` and no
   `upcomingSession`. *Effort: trivial.*
8. **Unify the two "no homework right now" states' visual treatment (L5)** — same neutral-grey
   card and phrasing for both `homework_enabled === false` and `awaitingPrompt`. *Effort: trivial.*
9. **Label the Telegram curator button as escalation, not a second independent contact channel
   (L6)** — one line of subcopy ("Если ИИ-куратор не помог — сюда"). *Effort: trivial.*

None of tickets 1–9 touch access/payment logic or `Lesson`/`Course` model behavior — they are
template, copy, and (for #1 and #4) read-only controller-data additions, consistent with this
audit's document-only mandate.

## 6. Acceptance metrics

- **Lesson start rate**: `TrackLessonViewJob` dispatches are already wired on every
  non-admin `showLesson()` call (`StudentController.php:487-501`) — a ready-made numerator for
  "course page view → lesson opened," which ticket 1 (continue CTA) should move upward if it
  reduces the scan-and-guess friction from C1.
- **Lesson complete rate**: `completeLesson()` writes an idempotent `lesson_complete` Prana award
  per lesson (`StudentController.php:582`) — completions per lesson-start is a direct proxy for
  whether L3's "does the button know I actually watched" ambiguity is costing completions.
- **Recording play rate**: no current instrumentation distinguishes "video iframe rendered" from
  "video actually played" — `TrackLessonViewJob` fires on page load regardless of whether the
  student presses play. Ticket 7's microcopy would need a lightweight client-side play event (or
  reuse of the existing `postMessage` player-state listener at `lesson.blade.php:113-119`) to
  measure against a baseline; not present today.
- **Homework submit rate**: `HomeworkSubmission` status transitions (`draft` → `submitted`) are
  already persisted per lesson via the form at `homework.blade.php:113-154` — submit-rate among
  students who opened a lesson with `homework_enabled` is a direct, already-available metric with
  no new tracking required, and is the cleanest metric here to baseline before/after ticket 3
  (notes autosave doesn't touch homework, but establishes the save-reliability pattern homework
  already uses that notes currently lacks).

_Dr. Mārcis Gasūns_
