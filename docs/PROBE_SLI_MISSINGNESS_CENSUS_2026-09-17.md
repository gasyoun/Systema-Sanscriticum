# Probe/SLI missingness census — H5061

_Created: 17-09-2026 · Last updated: 17-09-2026_

Census of every active probe/SLI metric seam reachable from the production
scheduler (prod crontab / deploy.sh сторож `*/15`, per each command's own
docblock; `routes/console.php` carries no schedule entries), with its
missingness behavior, and the H5061 contract verdict.

Contract (extracted from [#2526](https://github.com/gasyoun/Systema-Sanscriticum/pull/2526),
[#2565](https://github.com/gasyoun/Systema-Sanscriticum/pull/2565),
[#2645](https://github.com/gasyoun/Systema-Sanscriticum/pull/2645)):
a probe/metric path must distinguish `value` (genuine zero allowed),
`unavailable`, `not_supported`, `pending`, `failed` and `partial` coverage;
missing configuration must be loud AND machine-readable; a green run must
clear sticky failure state. Canonical vocabulary helper:
[app/Support/Observability/ProbeOutcome.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/Observability/ProbeOutcome.php).

## Census table

| # | Code path | Scheduler reachability | State vocabulary today | Missing-config behavior | Sticky/clear behavior | Verdict |
|---|-----------|------------------------|------------------------|-------------------------|----------------------|---------|
| 1 | `cabinet:probe` ([ProbeCabinetHealth.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ProbeCabinetHealth.php)) | сторож `*/15` cron + deploy.sh (`--fail-on-critical`) | full: failures by severity + `coverage_partial` history flag (H4648), `ok (coverage partial)` ping | loud `warn` for TEST_MANAGER_*/TEST_STUDENT_* empty + `coverage_partial` recorded | `HTTP_DOWN` set on fail, `forget()` on green (H3197 durable file) | ✅ compliant |
| 2 | `money:sli-synthetic-pay` ([MoneySliSyntheticPay.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MoneySliSyntheticPay.php)) | daily cron | TSV `ok`/`fail` + attempts/latency/http_status | missing key → loud FAILURE exit; feature OFF → **REPAIRED**: log warning + TSV `not_supported` row (daily cadence), heartbeat stays silent = dead-man | `recovered()` clears TG cooldown on green | ✅ repaired (was: OFF exited green with NO metric record) |
| 3 | `money:sli-hourly-reconcile` ([MoneySliHourlyReconcile.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MoneySliHourlyReconcile.php)) | hourly cron | TSV `ok`/`fail` + rate/silent-grant counts | feature OFF → **REPAIRED**: loud log warning (`not_supported`); no TSV spam at hourly cadence | heartbeat `…/fail` on breach; `recovered()` on green | ✅ repaired (was: quiet comment only) |
| 4 | `MoneySliAlerter::heartbeat` ([MoneySliAlerter.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/MoneySli/MoneySliAlerter.php)) | shared seam of 2+3 | heartbeat `ok` / `…/fail` | **REPAIRED**: empty URL → `Log::warning` with `state: not_supported` (matches `alert()`'s TG-config warning) | n/a (stateless transport) | ✅ repaired (was: SILENT return — proven silent skip) |
| 5 | `heartbeat:ping` ([PingSchedulerHeartbeat.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/PingSchedulerHeartbeat.php)) | frequent cron (dead-man design) | `ok` / `…/fail` + Horizon selfCheck | **REPAIRED**: empty `HEARTBEAT_PING_URL` → console comment + `Log::warning` (`not_supported`); silence itself remains the dead-man signal | n/a (dead-man by design — external alert on missing ping) | ✅ repaired (was: console-only, no machine-readable record) |
| 6 | `telegram-support:healthcheck` ([CheckTelegramSupportSessionHealth.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/CheckTelegramSupportSessionHealth.php)) | frequent cron (with `--dry` variants) | problems list + auto-heal + TG/Filament notify | zero enabled accounts → **REPAIRED**: `warn` + `Log::warning` (`not_supported`), not quiet green; missing `stale_after_minutes` has a default (15) | healer cooldown; per-account sticky `last_sync_error` cleared by recover | ✅ repaired (was: zero enabled accounts = `info` + green, the silent-skip class) |
| 7 | `hindi:*-probe` census commands (e.g. [HindiDictProbeCommand.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/HindiDictProbeCommand.php)) | manual/read-only census, not paged | printed counts + `--json`; `flag=on/off` explicit | prints explicit counts; no paging surface → nothing to silence | n/a | ✅ compliant (manual census, not a monitored seam) |
| 8 | Better Stack / healthchecks destinations (`money_sli.daily_ping_url`, `money_sli.hourly_ping_url`, `heartbeat.url`, `cabinet_probe` healthchecks ping) | external dead-man monitors | `ok` vs `…/fail` body carries the summary | empty URL → dead-man NOT armed; after H5061 every seam logs this loudly as `not_supported` | external monitor owns the alert-on-silence | ✅ repaired via seams 2–5 |

## Repairs shipped (each with a per-defect regression test)

1. `MoneySliAlerter::heartbeat` — empty ping URL was a **silent return**
   (missing configuration exits quietly); now logs
   `money_sli: heartbeat URL пуст — мёртвый сторож НЕ вооружён (not_supported)`.
   Test: `ProbeMissingnessRepairsTest::test_money_sli_heartbeat_with_empty_url_is_loud_and_sends_nothing`.
2. `money:sli-synthetic-pay` feature OFF — was exit-green with **no metric
   record** (not_supported indistinguishable from «never ran»); now appends a
   `status=not_supported` TSV row (daily cadence, no spam) + loud log.
   Test: `…::test_synthetic_pay_feature_off_appends_not_supported_tsv_row`.
3. `money:sli-hourly-reconcile` feature OFF — was quiet; now loud
   `Log::warning` (`not_supported`), TSV deliberately not spammed at hourly
   cadence. Test: `…::test_hourly_reconcile_feature_off_is_loud`.
4. `heartbeat:ping` missing URL — was console-comment-only; now also
   machine-readable `Log::warning`. Test:
   `…::test_scheduler_heartbeat_with_missing_url_is_loud`.
5. `telegram-support:healthcheck` zero enabled accounts — was `info` + green
   (absent data coerced to green); now `warn` + `Log::warning`
   (`not_supported`), exit stays SUCCESS (scheduler run is not failed by an
   unarmed seam). Test: `…::test_telegram_support_healthcheck_with_zero_enabled_accounts_is_not_quiet_green`.

## Reusable contract layer

- Vocabulary: [app/Support/Observability/ProbeOutcome.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/Observability/ProbeOutcome.php)
  (`value` / `unavailable` / `not_supported` / `pending` / `failed` / `partial`;
  `partial` is deliberately NOT green).
- Contract assertions (reusable trait):
  [tests/Concerns/AssertsProbeMissingnessContract.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Concerns/AssertsProbeMissingnessContract.php).
- Contract suite with seeded red / clean green receipts:
  [tests/Feature/Support/ProbeMissingnessContractTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/ProbeMissingnessContractTest.php)
  + seeded violations in
  [tests/Support/SeededViolationProbes.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Support/SeededViolationProbes.php).
- No second monitoring store introduced: alert destinations (TG chats, Better
  Stack/healthchecks URLs, TSV sink, `cabinet_probe_runs` history) unchanged.

## Evidence receipts (2026-09-17, this worktree, PHP 8.5.9)

- Seeded red #1 (silent skip): with `MoneySliAlerter::heartbeat`'s new
  `Log::warning` temporarily removed →
  `ProbeMissingnessRepairsTest` **ERRORS! Tests: 6, Assertions: 18, Errors: 1**
  (`test_money_sli_heartbeat_with_empty_url_is_loud_and_sends_nothing` — expects
  the warning, gets silence). Fix restored → clean PASS below.
- Seeded red #2/#3 (zero coercion, sticky-never-clears): encoded as permanent
  fixtures in `SeededViolationProbes` and asserted REJECTED by
  `ProbeMissingnessContractTest::test_seeded_zero_coercion_turns_the_contract_red`
  and `…::test_seeded_sticky_failure_that_never_clears_turns_the_contract_red`.
- Contract suite + all repair regressions, clean tree:
  `php vendor/bin/phpunit tests/Feature/Support/ProbeMissingnessContractTest.php tests/Feature/Support/ProbeMissingnessRepairsTest.php`
  → **OK (10 tests, 46 assertions)**.
- Existing probe/SLI suites after the repairs:
  `SchedulerHeartbeatTest` ✅, `MoneySliHourlyReconcileTest` ✅,
  `CabinetProbeTest` ✅, `CheckTelegramSupportSessionHealthTest` ✅ (7 tests).
  `MoneySliSyntheticPayCommandTest`: 2 of 3 tests fail — verified PRE-EXISTING
  on the clean tree (`git stash -u` → same 2 failures without any H5061
  changes); local-env dependent (healthy-run webhook grant), out of H5061 scope.
- Own-data dry-run, no production data or real alerts touched:
  all new tests run against `:memory:` sqlite, temp TSV paths
  (`storage/app/money_sli/test_h5061_*.tsv`), `Http::fake()` — nothing sent.

## Changed / Unchanged / Risks

- **Changed:** census (this doc), `ProbeOutcome` vocabulary, contract trait +
  suite + seeded fixtures, five loud-skip/zero-coercion repairs with per-defect
  regression tests, CHANGELOG entry.
- **Unchanged:** monitoring destinations (TG/healthchecks/Better Stack/TSV
  paths), true-zero semantics (genuine zero remains a `value`), production
  data, exit codes of repaired commands (all still SUCCESS on unarmed seams —
  no scheduler run is failed by a misconfiguration).
- **Risks:** scheduler reachability recorded per command docblock/crontab
  conventions, not re-verified against the live prod crontab in this pass;
  dynamic (non-command) metric paths outside these eight seams were not
  censused. The 2 pre-existing `MoneySliSyntheticPayCommandTest` failures
  (healthy-run grant) are INCONCLUSIVE here and owned separately.

_Гасунс_
