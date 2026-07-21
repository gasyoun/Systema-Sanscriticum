# ARCHITECTURE — Teacher-load report + public schedule widget

_Created: 21-07-2026 · Last updated: 21-07-2026_

Cover doc: [PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md).

## Data model — no migrations

Every relationship this plan needs already exists:

```
Teacher ──< Course (main, teacher_id)
Teacher ──<>── Course (co-taught, pivot course_teacher)
Course  ──<>── Group  (pivot course_group)
Course  ──<>── Category (pivot category_course)      ← "direction" lives here today
Group   ──< Schedule (group_id)
Course  ──< Schedule (course_id)
Group   ──<>── User (pivot, activeUsers() = H162 recruitment state)
```

**Direction resolution (decision 5):** `Group` has no direction field. A group's direction
set is derived: `Group::courses` → each course's `categories`. New helper, no schema change:

```php
// app/Models/Group.php
public function directions(): Collection
{
    return $this->courses->flatMap(fn (Course $c) => $c->categories)->unique('id')->values();
}
```

A group whose courses span more than one category appears under each in the report and the
feed — no synthetic "mixed" bucket, no forced single canonical value.

**Teacher→groups resolution (decision 6):** on-the-fly, reusing `Course::scopeForTeacher()`:

```php
// app/Models/Teacher.php
public function groupsLed(): Collection
{
    return Group::whereHas('courses', fn ($q) => $q->forTeacher($this->id))
        ->with('courses.categories')
        ->get();
}
```

Both the admin report and the public feed call this (or its query-builder equivalent scoped
to a direction filter) — one source of truth, no drift between the two surfaces.

## Component boundaries

### 1. `CategorySeeder` (new)

`database/seeders/CategorySeeder.php` — idempotent (`Category::firstOrCreate(['slug' => ...])`
per row), draft rows mined from `raspisanie/`'s course names (see IMPLEMENTATION for the exact
list). Registered in `DatabaseSeeder` but **not** auto-run against prod — a one-time
`php artisan db:seed --class=CategorySeeder` deploy step (pattern: `DEPLOY_QUEUE.md`, same as
`SrsRootFrequencyDeckSeeder`).

### 2. `TeacherLoad` Filament page (new) — wave 1a

`app/Filament/Pages/TeacherLoad.php` + a `TeacherLoadByDirectionChart` `ChartWidget`, under the
"Обучение" or "Аналитика" nav group next to `TeacherAnalytics`/`DelegationKpi`. Table: teacher
× group count (via `groupsLed()->count()`), expandable to per-direction breakdown. Chart:
stacked/grouped bar, teachers on one axis, directions as series — same ApexCharts plumbing as
`DelegationKpi`'s cards, not a new charting dependency.

Gated `RoleGate::any(Roles::ADMIN, Roles::TEACHER)` (teacher sees only their own row, same
pattern as `ScheduleResource::getEloquentQuery()`).

### 3. Public feed endpoint (new) — wave 1b

`app/Http/Controllers/Api/PublicScheduleController.php`, route `GET /api/public/schedule`
(outside any auth middleware group, inside `throttle:30,1`). Reuses `Course::upcomingSchedules()`
per visible course, maps through a dedicated `PublicScheduleResource` (Laravel API Resource)
that hard-allowlists fields — this is the security boundary, not the query. Response cached
(`Cache::remember`, TTL ~5 min — schedule changes aren't second-to-second) keyed by any
direction/teacher filter params.

**Never reuse `IcsFeedBuilder` or `CalendarFeedController` directly** — those are
per-authenticated-user, token-gated, and include `zoom_join_url`/`link`. This is a distinct,
deliberately narrower public surface; sharing code would risk a field leaking across the
boundary on a future edit to the private builder.

### 4. Public widget route (new) — wave 1b

`app/Http/Controllers/PublicWidgetController.php`, route `GET /widgets/schedule` → a Blade view
with no layout inheritance from the main site chrome (it's iframe content, not a page — no
header/nav/footer). Vanilla JS (or Alpine.js, already a Systema dependency per its Tailwind/Vite
stack) fetches `/api/public/schedule`, renders a filterable table (by direction, by teacher).
No new frontend framework/dependency.

`X-Frame-Options`/CSP on this one route must explicitly allow framing (Laravel's default
`SecureHeaders`-style middleware, if any, otherwise blocks embedding) — a route-level exception
via `frame-ancestors https://samskrtam.ru` (and no wider), not a global relaxation.

### 5. Embed artifact (deliverable, not code)

A markdown snippet (`docs/copy/` pattern, matching `docs/copy/money-*.md` conventions) with the
exact `<iframe src="https://.../widgets/schedule" ...>` HTML MG pastes into the WP block editor,
plus a one-line note that the widget is iframe-responsive-height via `postMessage` (avoid a
fixed-height iframe clipping content on mobile — see IMPLEMENTATION for the resize approach).

## Build-vs-reuse verdict

| Piece | Verdict | Why |
|---|---|---|
| Direction taxonomy | **Reuse** `Category` + `category_course` | Already modeled, just unseeded |
| Teacher↔course↔group query | **Reuse** `Course::scopeForTeacher`, `Teacher::allTaughtCourses`, `Course::upcomingSchedules` | Exact query shape already exists |
| Group capacity/fill | **Reuse** `Group::min_size`/`activeUsers()`/`isRecruited()` (H162) | Already computes what decision 9 needs |
| Charting | **Reuse** Filament ChartWidget/ApexCharts pattern | Matches `DelegationKpi`/`TeacherAnalytics`, no new dep |
| iCal/feed escaping plumbing | **Template only, not shared code** — `IcsFeedBuilder` | Different security boundary (public vs personal); copying the pattern, not the class |
| WP-side code | **Build nothing** | Decision 8 — iframe needs zero WP code |
| Google Calendar embed | **Not reused for this feature** | Proven pattern but non-interactive; the ask was specifically for filterable interactivity |

## Security boundary summary

The entire public-safety surface of this plan is the field allowlist in
`PublicScheduleResource` (decision 9) plus the `frame-ancestors` CSP scoping (component 4). No
authentication is added or removed anywhere else; no existing route's visibility changes.

_Dr. Mārcis Gasūns_
