# First Five Minutes: Student UX Audit (2026)

_Created: 09-07-2026 · Last updated: 09-07-2026_

Audit of the student cabinet's first-screen activation path after login, following
[PR #353](https://github.com/gasyoun/Systema-Sanscriticum/pull/353) ("Add cabinet
continue learning block"). Document-only — no code changed by this audit.

## 1. Activation north star

**A new student's first five minutes should end with one action taken toward
learning** — opening the first lesson, starting the first homework draft, or (if
blocked) resolving the one thing that blocks learning (an unpaid block, a Telegram
connection needed for support). Everything else on the first screen is either in
service of that action or in the way of it.

Concretely, "activated" means the student has done **at least one** of:

1. Opened a lesson (`LessonView` created).
2. Started a homework draft (`HomeworkSubmission` in `draft` status).
3. Connected a messenger (Telegram/VK — the support channel).

These three map directly onto the `OnboardingChecklist` steps
([`app/Support/OnboardingChecklist.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/OnboardingChecklist.php))
and the `docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026` metrics in §6. A student should
not need to read
[`docs/student-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md)
(the developer/curator reference) to get there — the shorter
[`docs/onboarding-student.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md)
is the only doc a student should ever need, and ideally the UI alone suffices.

## 2. Current first-screen diagnosis after PR #353

Reading top-to-bottom through
[`resources/views/student/dashboard.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/dashboard.blade.php)
as a fresh student would see it, in render order:

1. **Header** — greeting + "Сменить пароль" (change password) button, top right.
2. **`onboarding-checklist`** partial — the 4-step P0 checklist card (hidden once
   `allComplete`).
3. **`homework-alerts`** partial — only if a submission needs revision (empty for a
   brand-new student).
4. Session flash banners (password/bot status) — only after an action.
5. **`continue-learning-card`** partial (PR #353) — "Следующий шаг" (Next step),
   one CTA card.
6. **`subscriber-shelf`** partial — only if the newsletter feature flag is on and
   the user is a subscriber (irrelevant for most students, especially day one).
7. **Change-password modal** (hidden, triggered by the header button).
8. **Cabinet-under-construction notice** — dismissible banner, "Кабинет находится
   на стадии наполнения" ("the cabinet is still being filled with content").
9. **Bot connect blocks** (Telegram/VK) — one or two large cards, shown whenever
   `MarketingSetting` enables them, regardless of onboarding state.
10. **Tab navigation** — Мои курсы / Словари / Мои оплаты / (Мои долги, if any) /
    (Прана, if active) / Поддержка.
11. **Course grid** (the "Мои курсы" tab content) — cards with per-course progress
    and a "Продолжить"/"Начать обучение" button.

**Order problem: the checklist (step 2) renders *above* the Continue Learning card
(step 5).** Both are "what do I do next" widgets, and they now compete for the same
job at the very top of the screen — see §3 below for the diagnosis of that overlap,
and §4 for the resolution (the checklist should not survive as a parallel widget at
all).

**Everything between the two "what next" widgets and the actual course grid is
distraction, not activation:**

- The change-password button is a settings action wearing top-of-page real estate a
  brand-new student has zero use for in minute one — nobody changes a password they
  set thirty seconds ago during registration.
- The "cabinet under construction" banner is an operational apology aimed at
  existing students living through a content rollout, not a first-time activation
  message — for a genuinely new student it reads as "this product isn't ready,"
  which undercuts activation before it starts. Its `localStorage`-based dismissal
  also means it reappears on a different device/browser.
- The bot-connect cards duplicate the "link_messenger" onboarding step but render
  as two full-width cards *unconditionally* (not gated on whether onboarding is
  already done), so a returning student who already connected Telegram sees a
  connected-state card here forever — visual weight that never goes away.
- Tabs for Словари / Мои оплаты / Мои долги / Прана / Поддержка are all real
  features, but presenting five-to-six tabs before the student has opened a single
  lesson asks them to model the whole cabinet before doing the one thing (open
  lesson 1) that the product exists for.

**What the new Continue Learning card gets right:** it is a single, unambiguous CTA
("Следующий шаг" → one button) driven by `StudentController::buildContinueLearningAction()`
([`app/Http/Controllers/StudentController.php:248`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/StudentController.php#L248)),
with a priority order that is exactly right for activation: unpaid-and-blocking debt
→ homework needing revision → an open trial lesson → the next unfinished lesson →
(implicitly) nothing left to do. That priority order is a genuine UX asset already
built — the diagnosis below is about what surrounds it, not the card itself.

## 3. Does the old checklist now compete with the top card?

**Yes, directly, for a first-time student.** For a student with zero activity:

- `OnboardingChecklist::for()` renders step 1 ("Откройте первый урок" / open the
  first lesson) as not-done, with a "Перейти" (Go) button linking to
  `student.course` (the course page).
- `buildContinueLearningAction()` renders "Продолжить обучение" ("Continue
  learning") with a CTA reading "Начать обучение" ("Start learning"), also linking
  toward the first unfinished lesson (via `student.lesson`, one click deeper than
  the checklist's course-page link).

Both widgets are visible simultaneously, both say "go start the course," in two
different visual languages (a progress-bar checklist vs. a hero CTA card), stacked
directly on top of each other. A first-time student sees two "start here" prompts
before seeing anything else. This is not a hard blocker, but it dilutes the single
clear next action that PR #353 was built to establish, and it doubles the vertical
space spent restating the same instruction.

The checklist's remaining three steps (complete a lesson, submit homework, link a
messenger) are *not* redundant with the Continue Learning card today — the card
only ever shows one action at a time, so a student who has opened lesson 1 but not
submitted homework yet gets no card-level nudge toward homework (the card's
`homework` kind only fires for work returned for *revision*, not an unstarted
draft). The checklist is currently the only place that nudges "submit your first
homework" and "connect a messenger" proactively. **The overlap is narrow (step 1
only) but real, and it is the most visible overlap because step 1 is the top row of
a checklist a first-time student sees before anything else has a chance to be
marked done.**

## 4. Proposed first-5-minutes path

Reorder and trim the first screen so a brand-new student sees, in order:

1. **Greeting** (keep) — but move "Сменить пароль" out of the primary header row
   into the account/profile area (or a low-emphasis icon-only affordance) — it has
   no place competing for attention in minute one.
2. **Continue Learning card** (keep, promote to first position) — this is the
   single next action. For a first-time student this *is* "open lesson 1"; there is
   no need for a separate checklist row to say the same thing above it.
3. **Homework alerts** (keep, unchanged position/condition) — genuinely
   action-required, correctly rare for new students.
4. **Progressive onboarding strip** (replaces the current checklist — see §5) —
   compact, single-line-per-step, appears *after* the Continue Learning card, and
   only surfaces the steps the Continue Learning card does not already cover
   (homework draft, messenger link) once the student has taken the first action.
5. **Course grid** (keep) — move up, ahead of the cabinet-notice banner and bot
   cards.
6. **Cabinet-under-construction banner** — demote below the fold (after the course
   grid) or, better, replace it with a **per-course** "materials being added"
   badge on the specific course card that is incomplete, so a student whose course
   *is* fully loaded never sees an apology that doesn't apply to them. This is a
   ticket (see §5, T4) — targeting completeness data does not exist today and needs
   a decision on what "complete" means per course.
7. **Bot connect cards** — keep, but gate visibility on whether `link_messenger` is
   already `done` for *this* student, matching what the onboarding strip already
   does with its compact inline buttons — the full-width duplicate card is the
   redundant one, not the checklist's compact row (see §5, T2).
8. **Subscriber shelf** — keep position (low priority, flag-gated, rare for new
   students).
9. **Tabs + tab content** — unchanged; a first-time student reaching the tab bar
   has already seen and acted on the one thing that matters.

## 5. Progressive onboarding model

Replace the current "all 4 steps visible until all 4 are done" checklist
([`resources/views/student/partials/onboarding-checklist.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/partials/onboarding-checklist.blade.php))
with a model where **only the single next uncompleted step is shown**, sized like a
strip/toast rather than a full card, and it disappears entirely once the Continue
Learning card is already pointing at the same action (step 1, "open a lesson").

Proposed step sequence (reuses the exact `done` signals already in
`OnboardingChecklist`, no new schema):

| Step | Shown when | Suppressed when |
|---|---|---|
| Open first lesson | never shown as a separate widget — the Continue Learning card already *is* this instruction for a first-time student | always (superseded by the card) |
| Submit first homework (draft, not just "not needs-revision") | `open_lesson` done AND course has homework-enabled lessons AND no submission (draft or otherwise) exists | homework already submitted, or course has no homework lessons |
| Connect a messenger | `open_lesson` done AND messenger not yet linked | messenger already linked, or both bot flags are off org-wide |

This keeps the "S3 fact-only, no full checklist" instinct that already worked well
for [`SupportAnswerSuggester`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAnswerSuggester.php)-style
incremental UI in this codebase: show one thing, not four, and let it retire itself
as signals change.

### Ticket list with acceptance criteria

**T1 — Drop the redundant "open first lesson" checklist row**
- Remove step 1 (`open_lesson`) from the rendered onboarding strip; keep the signal
  in `OnboardingChecklist::for()` for use in analytics/metrics (§6), but do not
  render a UI row for it — the Continue Learning card already carries this
  instruction for a first-time student.
- Acceptance: `CourseCardNextLessonTest`-style fixture with a fresh student sees
  exactly one "go learn" prompt on the dashboard, not two.

**T2 — Collapse the onboarding strip to "next step only," compact styling**
- Change `onboarding-checklist.blade.php` to render only the first not-done step
  (excluding `open_lesson` per T1), as a single compact row (icon + label + inline
  action), not a 4-row card with a progress bar.
- Acceptance: a student who has opened a lesson but not submitted homework sees a
  one-line "submit your first homework" prompt, not a 4-row checklist; a student
  who has done everything sees nothing (unchanged `allComplete` behavior).

**T3 — Gate the full-width bot-connect cards on onboarding step state**
- Hide the large Telegram/VK connect cards on the dashboard once
  `link_messenger` is `done` for that user (today they still render a "connected"
  state card indefinitely) — the connected-state affordance for *disconnecting*
  belongs in account settings, not the activation surface.
- Acceptance: a student who connected Telegram on day one no longer sees a
  full-width Telegram card on day thirty; disconnect remains reachable via account
  settings.

**T4 — Replace the global "cabinet under construction" banner with a per-course
signal, or retire it**
- Requires a decision on what "content complete" means per course (a manual toggle
  on `Course`, or a lesson-count heuristic) — flag as `@DECIDE` for a human, this is
  not purely mechanical.
- Interim acceptance (no new data model): move the existing banner below the course
  grid instead of above it, so it never blocks the first CTA.

**T5 — Move "Сменить пароль" out of the primary header**
- Relocate the change-password entry point to a lower-emphasis position (a
  profile/account menu, or a small icon button) so the header's visual weight goes
  to the greeting only.
- Acceptance: header row on `/dvaram` renders greeting + subtext only; the
  change-password modal remains reachable via the relocated entry point and via the
  existing `open-change-password` event (used elsewhere, e.g. forced-open on
  validation errors).

**T6 — Instrument the four activation metrics from §6**
- Add the event/log points needed to measure first-lesson-opened,
  first-homework-draft, first-messenger-connect, and first-support-contact
  timestamps per user (some already exist as timestamped rows — `LessonView`,
  `HomeworkSubmission.created_at`, `telegram_id`/`vk_id` set — this ticket is about
  aggregating them into one activation-funnel report, not new instrumentation).
- Acceptance: a Filament report or command shows, for a cohort of students by
  registration date, the median time-to-each-milestone and the % reaching each
  within 5 minutes / 24 hours / never.

## 6. Metrics: first lesson opened, first homework draft, bot connect, support start

All four are derivable from existing tables — no new tracking needed, only
aggregation (T6 above):

| Metric | Source | Definition |
|---|---|---|
| First lesson opened | `LessonView` (earliest `created_at` per user) — via `ActivityTracker` per the CLAUDE.md "Activity Tracking" section | Time from `users.created_at` (or first payment) to first `LessonView` row |
| First homework draft | `HomeworkSubmission` (earliest `created_at` per user, any status) | Time from registration to first submission row, regardless of later status |
| Bot (messenger) connect | `users.telegram_id` / `users.vk_id` set — no timestamp column today, so this needs a `telegram_connected_at`/`vk_connected_at` column or an `ActivityEvent` entry to measure *time-to-connect*, not just connected/not | Time from registration to first non-null messenger id, OR (cheaper, no migration) a point-in-time "% of students with a connected messenger" snapshot |
| First support contact | `SupportConversation` (via `SupportConversationManager`, per the CLAUDE.md "Support Inbox" section) or `TelegramSupportMessage` for imported threads | Time from registration to first inbound message in either store |

The activation north star (§1) is satisfied once a student hits milestone 1
(lesson opened) or milestone 2 (homework draft) within the first session — support
contact is a *fallback* signal (the student got stuck and needed help), not itself
an activation success, and should be tracked separately as a "needed help to
activate" flag rather than folded into the activation rate.

---

_Dr. Mārcis Gasūns_
