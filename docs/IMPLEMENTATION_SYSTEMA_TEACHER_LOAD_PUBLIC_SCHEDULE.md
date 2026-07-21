# IMPLEMENTATION — Teacher-load report + public schedule widget

_Created: 21-07-2026 · Last updated: 21-07-2026_

Cover doc: [PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md). Step-ordered; each step
names the files it touches and what it depends on. Two handoffs, matching the wave split in
[ROADMAP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_2026H2.md) (1a can ship and merge before 1b starts; 1b's query layer imports 1a's).

## Handoff split

- **H-A (wave 1a)**: steps 1–5 below. Sonnet-tier, no frontend framework work, pure
  Laravel/Filament — matches the model tier already used for `TeacherAnalytics`/`DelegationKpi`-
  class work in this repo.
- **H-B (wave 1b)**: steps 6–10. Depends on H-A merged (imports `Group::directions()` /
  `Teacher::groupsLed()`). Sonnet-tier; the JS is vanilla/Alpine, not a new framework, so no
  frontend-specialist tier needed.

Mint both via `/handoff-mint --batch 2` before starting, per the standing mint-at-first-artifact
rule — the first artifact is this plan itself already committed, so mint now.

## Wave 1a

### Step 1 — `CategorySeeder`

New file `database/seeders/CategorySeeder.php`. Draft rows (slug, name), idempotent
`firstOrCreate`:

| slug | name |
|---|---|
| sanskrit-s-nulya | Санскрит с нуля |
| sanskrit-prodvinutyj | Продвинутый санскрит |
| sanskritskaya-grammatika | Санскритская грамматика и морфология |
| vedijskaya-literatura | Ведийская литература |
| napevnyj-sanskrit | Напевный санскрит |
| hindi | Хинди |
| sanskritskaya-paleografiya | Санскритская палеография и рукописи |
| ayurveda | Аюрведа |
| indijskaya-filosofiya | Индийская философия |
| kashmirskij-shivaizm | Кашмирский шиваизм |
| kalligrafiya-devanagari | Каллиграфия деванагари |
| vostochnye-kalendari | Восточные календари |
| sakralnaya-geografiya | Сакральная география Индии |
| chtenie-tekstov | Чтение и перевод текстов |
| lingvistika | Ликбез по лингвистике |

`sanskritskaya-grammatika` is the direct answer to the original example ("Углубленная
морфология", «Караки по Панини», "синтаксис санскрита" all belong here) — attach it to those
three courses in the seeder's course-category pivot inserts (match by existing `Course.slug`;
skip silently, log via `Log::info`, for any slug not found rather than failing the seeder —
per the ambiguity policy, course slugs may have drifted since the `raspisanie/` scrape).

Register in `DatabaseSeeder::run()` but do **not** add to any CI/test auto-seed path beyond
what `RefreshDatabase`-based feature tests need for their own fixtures (tests seed their own
minimal categories, not this full draft list — see step 5).

### Step 2 — `Group::directions()`

Edit `app/Models/Group.php` (see ARCHITECTURE for the exact method). No migration.

### Step 3 — `Teacher::groupsLed()`

Edit `app/Models/Teacher.php` (see ARCHITECTURE). Add a companion query-scope on `Group` if the
Filament table needs a `Builder`-level filter (not just a Collection) — e.g.
`Group::scopeLedBy($query, int $teacherId)` mirroring `Course::scopeForTeacher()`'s shape, so
the Filament page can paginate/filter server-side rather than loading every group into memory.

### Step 4 — `TeacherLoad` Filament page + chart widget

New `app/Filament/Pages/TeacherLoad.php` (nav group "Обучение" or alongside `DelegationKpi`
under "Финансы"/"Аналитика" — match whichever the current nav actually groups analytics under;
check `AdminPanelProvider.php` nav groups before picking). New
`app/Filament/Widgets/TeacherLoadByDirectionChart.php` extending Filament's `ChartWidget` (same
base class as existing chart widgets in the repo — grep `extends ChartWidget` for the exact
import path before writing, don't guess the Filament version's namespace).

Table columns: teacher name, total groups led (`groupsLed()->count()`), per-direction badges.
Filter: by direction (`SelectFilter` over `Category`).

### Step 5 — Tests + screenshot

New `tests/Feature/TeacherLoadReportTest.php`: seed 2–3 teachers, several groups across
categories via factories (check for existing `GroupFactory`/`CourseFactory`/`TeacherFactory` —
reuse, don't hand-roll fixtures the repo already has factories for), assert the page's table
data and the chart widget's underlying dataset match the seeded counts exactly.

Screenshot: use the repo's `blade-styling` skill (Playwright visual loop, per
`Systema-Sanscriticum`'s CLAUDE.md) against the local dev server to capture the rendered page
with seeded demo data — attach to the PR description, not committed as a binary file.

## Wave 1b

### Step 6 — `PublicScheduleResource` (API Resource, the security boundary)

New `app/Http/Resources/PublicScheduleResource.php`. Hard allowlist only — course/direction
title, teacher name, weekday+time (derived from `Schedule.start`/`end`), course landing-page
URL (`route('course.show', $course->slug)` or equivalent existing public route — check
`routes/web.php` for the actual course-landing route name before inventing one), group seat
count + `isRecruited()` boolean. Explicitly do **not** reference `Schedule.link`,
`zoom_join_url`, `zoom_start_url`, `User`, or any numeric `id` as a public field (use slugs).

### Step 7 — `PublicScheduleController` + route

New `app/Http/Controllers/Api/PublicScheduleController.php`, route
`GET /api/public/schedule` in `routes/api.php`, `throttle:30,1`, `Cache::remember` (TTL 5 min,
cache key includes filter query params). Query built on `Course::upcomingSchedules()` per
visible (`is_visible=true`) course, or a direct `Schedule` query joined through `Group`/`Course`
if `upcomingSchedules()`'s per-course loop is too slow at the current catalogue size (profile
before choosing — don't prematurely rewrite a working reused method).

### Step 8 — `PublicWidgetController` + Blade/JS

New `app/Http/Controllers/PublicWidgetController.php`, route `GET /widgets/schedule` (no auth
middleware, no main-site layout — a bare HTML document). New
`resources/views/widgets/schedule.blade.php` + a small vanilla-JS/Alpine script (inline or
`public/widgets/schedule.js`, matching the `public/exercises/telemetry.js` convention already
in the repo) that fetches step 7's endpoint and renders a filterable table (direction dropdown,
teacher dropdown, weekday grouping matching the current `raspisanie/` layout). Auto-resizing
iframe: post the rendered document height via `window.parent.postMessage(...)` on load and on
filter-change, so the parent WP page (or the embed snippet's JS, step 10) can resize the
`<iframe>` — avoids a fixed-height clipped embed on mobile (decision 16).

CSP/frame-ancestors exception scoped to this one route only (see ARCHITECTURE) — verify it
doesn't relax anything site-wide (check `AppServiceProvider`/`SecureHeaders`-equivalent
middleware for how frame-options are currently set globally before adding an override).

### Step 9 — Tests

New `tests/Feature/PublicScheduleFeedTest.php`: assert the JSON response never contains `link`,
`zoom_join_url`, `zoom_start_url`, or a raw numeric `schedule_id`/`group_id`/`user_id` key;
assert throttle kicks in after the configured limit; assert direction/teacher filters return the
right subset against seeded fixtures; assert a second identical request within the cache TTL
doesn't re-run the underlying query (mock/spy or a query-count assertion).

New `tests/Feature/PublicWidgetPageTest.php`: the widget route returns 200 with no auth, and the
rendered HTML contains the filter controls.

### Step 10 — Embed artifact

New `docs/copy/public-schedule-widget-embed.md` (matching the `docs/copy/money-*.md`
convention): the exact `<iframe>` HTML MG pastes into the WP block editor's "Custom HTML" block
on `samskrtam.ru/raspisanie/`, a one-line note on the auto-resize behavior from step 8, and the
explicit reminder (per the autonomy contract) that pasting it onto the **live** page is a human
action requiring an in-chat go-ahead, not something this handoff does itself even if WP
credentials have arrived by then.

## Deploy notes

Both waves are additive (no destructive migrations, no existing-behavior changes) — log a
`DEPLOY_QUEUE.md` row only for the one-time `CategorySeeder` run (step 1), same pattern as
`SrsRootFrequencyDeckSeeder`'s queue entry. Everything else ships inert-until-visited (new
routes, new pages) with no feature flag needed — nothing changes for existing users.

_Dr. Mārcis Gasūns_
