# PLAN — Teacher-load report + public schedule widget (Systema-Sanscriticum, 2026H2)

_Created: 21-07-2026 · Last updated: 21-07-2026_

## Goal

Two linked deliverables, built in one sequenced wave:

1. **Admin analytics**: a Filament page showing how many groups each teacher currently
   leads, broken down by direction (e.g. "санскритская грамматика") — the question that
   started this plan (no such report exists today; `TeacherResource` only counts *courses*,
   not groups, and carries no direction breakdown).
2. **A reusable mechanism to publish interactive Systema data as embeddable pages on
   WordPress**, proven by making it power the *actual* `samskrtam.ru/raspisanie/` schedule
   page (currently 34 hand-typed lines re-entered by a human every term), not just a demo.

Both deliverables share one foundation: a direction ("category") taxonomy resolved onto
`Group`, and a query pattern for "which groups does teacher X lead, in which direction" —
built once, consumed by both the admin report and the public feed.

## Layer docs

- [ROADMAP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_2026H2.md) — waves, deliverables, non-goals.
- [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE.md) — data model, component boundaries, build-vs-reuse.
- [IMPLEMENTATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE.md) — file-level step-ordered build sequence.
- [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE.md) — acceptance criteria, risks register.

## Prior-art audit (done before the interview — see decisions below for how it shaped scope)

- `Schedule` model (`course_id`, `group_id`, `start`/`end`, `title`, `link`) already mirrors
  the manually-typed `raspisanie/` grid entry-for-entry.
- `Course::upcomingSchedules()` already has the exact "future events for this course, by
  course_id OR by any of its groups" query the public feed needs — reuse, don't rebuild.
- `Course::scopeForTeacher()` / `Teacher::allTaughtCourses()` already answer "which courses
  does teacher X teach (main or co-taught)" — the base of the group-count report.
- `IcsFeedBuilder` + `FeedToken` + `CalendarFeedController` already build a signed personal
  iCal feed (Google Calendar Integration Phase 1) — proves the escaping/folding/RFC-5545
  plumbing pattern; not reused directly (this plan's feed is public JSON, not per-user iCal)
  but the `Schedule`-to-event mapping logic is the template.
- `Group` (H162, Group Recruitment) already carries `min_size`/`activeUsers()`/`isRecruited()`
  — reused for the public feed's seat-count field, no new capacity model needed.
- `Category` model + `category_course` pivot already exist and are exactly the taxonomy
  needed for "direction" — just unseeded. No new taxonomy table.
- The live `raspisanie/` page's "always current" block is already a Google Calendar iframe
  embed (`calendar.google.com/calendar/embed?src=...`) — real prior art for
  Systema-data-into-WordPress-via-iframe, though this plan builds a custom widget instead
  (decision below) because the ask was for interactivity (filter by direction/teacher), which
  a bare calendar embed can't do.
- No WordPress codebase exists locally for `samskrtam.ru` (not a git repo under `GitHub/`) and
  no SSH/FTP/WP-API credentials were visible — confirmed as a real gap, not rebuilding
  something already accessible.

## Decisions taken (Phase 2 interview, 21-07-2026)

| # | Decision | Ruling | Rationale |
|---|---|---|---|
| 1 | Sequencing | Wave 1 = both parts; admin report **first** (builds the direction-on-group foundation), WP publishing consumes it | Report is the smaller, self-contained piece; WP feed needs the same direction-resolution logic |
| 2 | Admin report depth | Full analytics **with charts**, not a bare column | MG explicitly asked for more than a `TeacherResource` badge |
| 3 | WP deliverable scope | Build the **general reusable mechanism** (public feed + widget pattern) AND prove it by actually powering `raspisanie/` | Matches the original ask ("уметь собирать интерактивные страницы", plural/general) |
| 4 | Out of scope | Payment/checkout/access-control code; WooCommerce course-catalogue retirement on samskrtam.ru (open D4 decision, unrelated); samskrte.ru↔samskrtam.ru brand/design sync itself; the mobile app | All confirmed explicitly excluded |
| 5 | Direction model | Derive "group's direction(s)" as `Group→courses→categories`, **no migration**; a group spanning >1 category counts under each | Category already lives on Course (M:M); adding a canonical field to Group would duplicate/desync it |
| 6 | Teacher→group computation | Compute on the fly via `Group::whereHas('courses', fn($q) => $q->forTeacher($teacherId))`, **no denormalized table/column** | Current scale (dozens of groups) doesn't need caching; avoids a sync-drift bug class |
| 7 | Category taxonomy seed | Seed a draft set (~15–20 rows) mined from the current `raspisanie/` course names (начальный/продвинутый санскрит, ведийская литература, санскритская грамматика/морфология, …); MG reviews/renames via the existing `CategoryResource` admin UI after the build | Unblocks the report immediately instead of stalling on an external human step first |
| 8 | WP integration mechanism | **Custom JS widget**, served from a **new public route inside Systema-Sanscriticum itself**, embedded on WordPress via a single `<iframe>` — no WP plugin, no WP-side code | No WP credentials were available at plan time (MG has since offered them, see decision 15); an iframe needs only paste-access to a WP page, not plugin-install access |
| 9 | Public field allowlist | Course/direction title, teacher name, weekday+time, link to the course's own landing page, **plus** group seat count/fill status (reusing `Group::min_size` / `activeUsers()->count()` / `isRecruited()` from H162). **Excluded, always**: `Schedule.link`, `zoom_join_url`, `zoom_start_url`, any student PII, any internal numeric ID exposed as a stable public identifier (use slugs) | Matches what's already public on `raspisanie/` today, plus the one genuinely useful addition (recruitment status) that H162 already computes |
| 10 | Public endpoint auth | Fully unauthenticated, `throttle` + response cache (no token) | Data is already marketing-public by the same logic as the current hand-typed page |
| 11 | Widget host | New public Blade+JS route in Systema-Sanscriticum (e.g. `/widgets/schedule`), on the domain Systema already serves publicly | Zero new infra/CI/domain; doubles as the samskrte→samskrtam cross-link the sync-audit memo already recommends (incidental, not this plan's goal) |
| 12 | Charting library | Reuse the existing Filament `ChartWidget`/ApexCharts pattern (as in `DelegationKpi`, `TeacherAnalytics`) | No new dependency; visually consistent with the rest of the admin |
| 13 | Admin report acceptance | Feature test on seeded fixtures asserting exact counts, **plus** a visual screenshot (blade-styling/Playwright loop) | Chart rendering isn't otherwise machine-checkable |
| 14 | Public API acceptance | Full feature-test suite: field-allowlist assertion, throttle test, direction/teacher filter correctness, cache-hit test | It's public and unauthenticated — the allowlist test is the security boundary |
| 15 | WP go-live gating | MG will provide WP admin access ("Дам доступ") but **had not delivered it as of this plan**. Wave-1 Laravel-side work (report + API + widget route) ships fully autonomously regardless. The actual paste-into-`raspisanie/` step is gated on (a) receiving credentials and (b) an explicit human go-ahead **in chat at the moment of the action** — publishing to a live public site is never autonomous, per standing safety rules, credentials or not | Credentials alone don't authorize a publish action; that authorization is per-action, in-session |
| 16 | Visual/mobile bar | Mobile-responsive required (real `samskrtam.ru` traffic); neutral Tailwind styling matching Systema's own tokens, **not** a full brand-match redesign with samskrtam.ru | Full brand match is the separate samskrte↔samskrtam sync initiative (H563/H1068), out of scope here |

## Autonomy contract

- **On ambiguity** (e.g. a specific category name/boundary the seed list gets wrong): apply
  the plan's marked default, log it in the handoff's "decisions taken unattended" section, and
  keep going. Never stall on a naming judgment call that MG can fix later in `CategoryResource`.
- **Stop and wait for a human** when:
  1. About to paste the `<iframe>` embed into the **live** `samskrtam.ru/raspisanie/` page —
     even after WP credentials are in hand, this specific action needs an explicit "go ahead"
     in chat at that moment (publishing public content is never pre-authorized by a plan).
  2. CI/tests are still red after two fix attempts — stop and hand back a diagnosis instead of
     forcing past it.
  3. Anything in the build would touch `PaymentObserver`, `Payment::grantAccess()`, or other
     access-control/money code — that's outside the fence (below); stop and flag the conflict
     rather than work around it.
- **Commit authority**: standard handoff-scoped rule applies with no exception — once a
  handoff exists for a piece of this plan, commit → PR → merge without asking, for everything
  that lands inside the Systema-Sanscriticum repo (report, public API, widget route, tests,
  seeder). The one carve-out is decision 15 above: the live WordPress-side paste is not a git
  action and is never autonomous regardless of commit authority.
- **The fence** — do not touch: `app/Observers/PaymentObserver.php`, `Payment` model /
  webhook / checkout code, `Tariff` pricing logic, anything under `WooCommerce`/samskrtam.ru
  retirement (D4), the samskrte↔samskrtam sync design work, or `mobile/` (Capacitor app). Do
  not invent or silently rename `Category` taxonomy rows beyond the seeded draft — flag
  proposed renames for MG instead of applying them.

## Execution

Mint wave-1 handoff(s) per [IMPLEMENTATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE.md)'s step ordering — see that doc's handoff-split recommendation. Starter line:

```
Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\docs\PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md and execute it.
```

_Dr. Mārcis Gasūns_
