# VERIFICATION — Teacher-load report + public schedule widget

_Created: 21-07-2026 · Last updated: 21-07-2026_

Cover doc: [PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md).

## §1 — Admin teacher-load report (wave 1a)

Acceptance (decision 13):

- `tests/Feature/TeacherLoadReportTest.php` green: seeded fixture of ≥2 teachers across ≥3
  groups spanning ≥2 categories; the page's table data and the chart widget's dataset match the
  seeded counts **exactly** (not approximately — this is a counting report, off-by-one is a
  real bug here).
- A teacher-role user viewing the page sees only their own row (reuse the
  `ScheduleResource::getEloquentQuery()` scoping pattern) — assert via a second test as a
  non-admin teacher.
- `CategorySeeder` runs idempotently twice against a fresh DB with no duplicate rows and no
  error on courses whose slug doesn't match the draft list.
- Visual proof: a Playwright/blade-styling screenshot of the rendered page with demo data,
  attached to the PR.
- `php artisan test --filter=TeacherLoad` green; full suite green before merge (per repo
  convention — CI gate is PHP 8.3 tests).

## §2 — Public schedule feed (wave 1b, API layer)

Acceptance (decision 14):

- **Field allowlist test** (the security boundary): assert the JSON response body, serialized
  as a string, does **not** contain the substrings `zoom_join_url`, `zoom_start_url`, `"link"`,
  or any bare integer under a `user_id`/raw PII-shaped key. This is the one test in this whole
  plan that must never regress — treat any future edit to `PublicScheduleResource` that breaks
  it as a shipped-vulnerability-class bug, not a test-maintenance chore.
- **Throttle test**: N+1 requests against `throttle:30,1` return `429` on the request past the
  limit.
- **Filter correctness test**: requesting `?direction=sanskritskaya-grammatika` returns exactly
  the seeded schedule entries whose course carries that category, nothing else.
- **Cache-hit test**: a second identical request within the TTL does not re-run the underlying
  DB query (query-count assertion or a spy on the builder).
- **Recruitment-status field test**: a group under its `min_size` reports `is_recruited: false`
  and the correct seat count, matching `Group::isRecruited()`'s own logic (regression guard
  against the public field silently drifting from the private computation it mirrors).

## §3 — Public widget (wave 1b, frontend layer)

Acceptance (decision 16):

- `tests/Feature/PublicWidgetPageTest.php` green: `GET /widgets/schedule` returns 200
  unauthenticated, page contains the direction/teacher filter controls.
- Manual verification via the Browser pane (per the project's UI-change convention): load
  `/widgets/schedule` on the local dev server, confirm the table renders seeded demo data,
  exercise the direction and teacher filters and confirm the visible rows change accordingly,
  resize to a mobile viewport (375×812) and confirm no horizontal overflow / controls remain
  usable.
- Confirm the auto-resize `postMessage` fires on load and on filter-change (check via
  `read_console_messages` or a `javascript_tool` probe reading `document.body.scrollHeight`
  before/after a filter change).
- The `frame-ancestors` CSP exception is scoped to this one route — assert (or manually check
  response headers) that no other route's frame-options changed.

## §4 — Risks & open unknowns (address before or flag during build)

| Risk | Mitigation / spike |
|---|---|
| `CategorySeeder`'s course-slug matches may have drifted from the live `raspisanie/` scrape (course pages get renamed) | Seeder logs (not fails) unmatched slugs; MG reconciles via `CategoryResource` post-build — matches decision 7's "draft, human reviews" framing |
| `Course::upcomingSchedules()` looped per-course may be slow once wired into a public, cached-but-cold-on-first-hit endpoint | Profile against current catalogue size before optimizing; TTL cache (step 7) absorbs most of the cost regardless |
| A group with courses in >1 category could look like double-counting in the admin report's totals | Explicitly a chosen tradeoff (decision 5) — document the "counted once per direction" behavior in the page's own UI copy so it doesn't read as a bug |
| WP embed sizing: iframes are notoriously fragile across WP themes' CSS resets | The `postMessage` auto-resize (step 8) is the mitigation; verify against a plain HTML test harness since there's no live WP access yet to test against the real theme |
| No WP credentials in hand at plan time | Explicitly not a wave-1 blocker (decision 15) — Laravel-side ships regardless; live embed is a separate, later, human-gated step |
| Public endpoint becomes an unintended scraping target for competing course platforms | Accepted risk per decision 10 (data is already public on `raspisanie/`); throttle is abuse-mitigation, not a confidentiality control |

## §5 — Definition of done for the whole plan

Wave 1a **and** 1b's Laravel-side deliverables (report, API, widget route, all tests) merged to
`main` via PR, full `php artisan test` suite green, `CHANGELOG.md` `[Unreleased]` entries added
for both (per the changelog-cadence rule) and `/cut-release`d. The live WordPress embed
(pasting the iframe into `samskrtam.ru/raspisanie/`) is tracked separately as a human `@DO` in
`Uprava/GTD_NEXT_ACTIONS.md`, not part of this plan's own completion criterion — see decision 15.

_Dr. Mārcis Gasūns_
