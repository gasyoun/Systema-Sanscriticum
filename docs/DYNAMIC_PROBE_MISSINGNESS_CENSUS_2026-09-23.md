# Dynamic (non-command) probe/missingness census — H5298 wave 2

_Created: 23-09-2026 · Last updated: 23-09-2026_

Explicit residual from [H5061](https://github.com/gasyoun/Systema-Sanscriticum/pull/2664)
([recovery #2767](https://github.com/gasyoun/Systema-Sanscriticum/pull/2767)):
that census covered eight command-oriented probe/SLI seams and named
"dynamic (non-command) metric paths outside these eight seams" as an explicit
risk it did not cover. This wave inventories listeners, queued jobs, Eloquent
observers, middleware and service-layer alert/metric writes that are
**dynamically dispatched** (registered via `EventServiceProvider`, `Schedule::
observe()`, `->onFailure()` closures on scheduled `Event` objects, or plain
service-layer calls from a listener/router) rather than reached by running a
named artisan command directly.

Same vocabulary as wave 1:
[app/Support/Observability/ProbeOutcome.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/Observability/ProbeOutcome.php)
(`value` / `unavailable` / `not_supported` / `pending` / `failed` / `partial`).

## Method

1. Enumerated every registered listener (`app/Providers/EventServiceProvider.php`),
   queued job (`app/Jobs/`), Eloquent observer (`AppServiceProvider::boot()`
   `observe()` calls) and HTTP middleware (`bootstrap/app.php` /
   `app/Http/Middleware/`).
2. Grepped each for the vocabulary that marks an observability/alert/metric
   write (`Log::warning|critical`, `healthchecks`/`Better Stack`/ping URLs,
   `Notification::make()->sendToDatabase()`, `Sli`/`probe`/`heartbeat`/
   `dead-man` identifiers) — the same signature wave 1 used to find its eight
   command seams, applied to the dynamic surface instead.
3. Traced reachability: is the seam wired to fire in production today, or is
   it dead code / feature-flagged permanently off.

## Census table

| # | Code path | Trigger (dynamic, non-command) | Destination | State vocabulary today | Missing-config behavior | Verdict |
|---|-----------|--------------------------------|-------------|-------------------------|--------------------------|---------|
| 1 | [`ScheduleFailureSignal::report()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ScheduleFailureSignal.php) | `->onFailure()` closures on 7 scheduled money commands ([`SchedulesOpsAndMembership.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Concerns/SchedulesOpsAndMembership.php), [`SchedulesOvernightAndCrm.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Concerns/SchedulesOvernightAndCrm.php), [`SchedulesSupportAndPayments.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Concerns/SchedulesSupportAndPayments.php)) — fires only when a command's process exits non-zero, never by running a command directly | `Log::critical` (always) + Filament DB notification to `super_admin`/`admin`/`accountant` | critical log unconditional; DB notify fan-out | **REPAIRED**: empty recipient set was a **silent return** after the critical log — now `Log::warning` (`not_supported`) records that the in-admin pager specifically could not be armed | ✅ repaired (was: silent skip on the notify branch) |
| 2 | [`TechnicalIssueNotifier::newTechnicalIssue()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/TechnicalIssueNotifier.php) | Called once per thread from `TechnicalIssueRouter` when a support thread transitions into the "Техника" queue — an event-adjacent service call, not a command | Filament DB notification to the configured tech assignee, else `super_admin`/`admin` | own docblock names it "тот же паттерн дежурного алерта, что у `telegram-support:healthcheck`" (a wave-1 seam) | **REPAIRED**: empty recipient set (`assignee_user_id` unset AND no admin/super_admin user) was a **silent return** — now `Log::warning` (`not_supported`) | ✅ repaired (was: silent skip, identical class to the pre-H5061 `telegram-support:healthcheck` defect) |
| 3 | [`ScheduleObserver::sendToN8n()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/ScheduleObserver.php) | `Schedule::observe()` ([`AppServiceProvider.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Providers/AppServiceProvider.php)) — fires on every `Schedule` (calendar event) create/update/delete from the admin, not on a schedule run | n8n webhook (`services.n8n.schedule_sheet_webhook`) | HTTP call on `create`/`update`/`delete`; errors already logged loudly (`Log::error`) | **REPAIRED**: empty webhook URL was a **silent return** with zero record that the sync is off by design — now `Log::info` (`not_supported`), distinguishable from "sent, no response" and from a future accidental misconfiguration | ✅ repaired (was: silent skip, no distinction between "off by design" and "should have fired") |
| 4 | [`TrackUserActivity`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/TrackUserActivity.php) (request-triggered heartbeat: `last_activity_at` + `user_sessions.last_heartbeat_at`) | Every authenticated, non-admin, non-impersonated request | `users`/`user_sessions` tables (business state, not an external monitor) | Redis `NX` throttle skip (by design, once/60s) is a **legitimate no-op**, not a missing-config seam; failures are caught and `Log::warning`'d with full context | n/a — no external config gates this path; the only "skip" (throttle) is expected steady-state behavior, not absence of config/credentials/data | ✅ compliant (no missingness surface: throttle skip ≠ missing measurement) |
| 5 | [`CloseStaleSessionsJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/CloseStaleSessionsJob.php) | Queued every 5 min (see Kernel) to close sessions past `last_heartbeat_at` | `user_sessions`/`users`/`activity_events` tables + `Log::info`/`Log::warning` | business job, not an external probe: no config can disable it; per-session failure is caught and logged loudly, a zero-closed run logs nothing (deliberately — "closed 0" is not a failure) | n/a — genuine zero (`$closedCount === 0`) correctly stays silent-on-success, this is NOT the absent-vs-zero class since there is no "unmeasured" state possible here | ✅ compliant (out of missingness scope: no configurable dependency to go missing) |
| 6 | [`HomeworkNotifier::notifyCourseTeacher()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HomeworkNotifier.php) | Called from the homework-submission flow when a group has no active reviewer | Mail to the course teacher | `Log::info` when the course has no teacher email, then skips | already loud: `HomeworkNotifier: у курса #{id} нет email преподавателя — уведомление о сдаче пропущено.` | ✅ compliant (reference example: the loud-skip idiom the three repairs above now match) |
| 7 | [`EnforceMailSendingGuards`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Listeners/Email/EnforceMailSendingGuards.php) | `Illuminate\Mail\Events\MessageSending`, global — every outgoing Mailable | cancels the send (`return false`) | suppression → `Log::info`; throttle → `Log::warning`, both with the recipient list | both skip paths are already loud and machine-readable | ✅ compliant |

## Reachability note (risk named per the mission)

Seams 1–3 are reached only through their real dynamic wiring (a failing
scheduled command's `->onFailure()` closure; a thread's queue transition; a
`Schedule` model CRUD) — none is reachable by running a named artisan
command, which is exactly the "runtime-created listener may evade static
census" risk the mission calls out. Static registration search
(`grep` over `EventServiceProvider`, `AppServiceProvider::boot()`, and every
`Schedules*.php` concern) plus scheduler/event discovery (`Schedule::events()`
in tests) was combined, as instructed, to close that gap for the seams found
here. Residual uncertainty: any listener registered by a package outside
`app/` (none found) or by a closure built at runtime from data (none found —
every dynamic seam here is wired at boot from static code).

## No second monitoring store, no changed alert destinations

All three repairs add `Log::` calls only (the same channel every wave-1 seam
already writes to). No new table, no new webhook, no new Telegram/Filament
destination. The Filament DB notification recipients, the n8n webhook target,
and `Log::critical` all stay exactly as they were — only the previously-silent
branches gained a matching `Log::` line.

## Repairs shipped (each with a per-defect regression test)

See [`tests/Feature/Support/DynamicSeamMissingnessRepairsTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/DynamicSeamMissingnessRepairsTest.php):

1. `test_schedule_failure_signal_warns_when_no_recipient_exists` /
   `test_schedule_failure_signal_does_not_warn_when_a_recipient_exists`.
2. `test_technical_issue_notifier_warns_when_no_recipient_exists`.
3. `test_schedule_observer_logs_not_supported_when_webhook_unconfigured` /
   `test_schedule_observer_stays_quiet_on_the_log_when_webhook_configured`.

## Seeded-red / green contract (reused, not duplicated)

The mission's silent-skip / absent-to-zero-coercion / sticky-state-never-clears
triad is the SAME contract wave 1 already proved red→green with
[`SeededViolationProbes`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Support/SeededViolationProbes.php)
and [`ProbeMissingnessContractTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/ProbeMissingnessContractTest.php)
— re-running it here (`test_seeded_silent_skip_turns_the_contract_red`,
`…_zero_coercion…`, `…_sticky_failure…`, all still green, see Checks below)
demonstrates the reusable `AssertsProbeMissingnessContract` trait catches the
exact defect class found live in this wave's dynamic seams (all three proven
violations were the silent-skip class; no live absent-to-zero-coercion or
sticky-never-clears defect was found in the dynamic surface censused here —
those two classes stay proven only by the wave-1 synthetic fixtures, per the
"if the census finds fewer, report the real count" instruction). No second
seeded-fixture file was created — extending the existing one would duplicate,
not add, coverage.

## Changed / Unchanged / Risks

- **Changed:** this census, three silent-skip repairs
  (`ScheduleFailureSignal`, `TechnicalIssueNotifier`, `ScheduleObserver`) each
  with regression tests, CHANGELOG entry.
- **Unchanged:** monitoring/alert destinations (Filament DB notifications, the
  n8n webhook target, `Log::critical` pager), exit-code policy (none of these
  seams affect a command's exit code), storage architecture (no new store).
- **Risks:** a listener/observer built entirely at runtime from configuration
  data (not found in this codebase today) would still evade a static grep —
  named here as residual uncertainty per the mission's own risk callout.

_Гасунс_
