# Maturity audit — Systema-Sanscriticum PRODUCT CORE

_Created: 2026-10-21 (audit run date) · H6395 · OxAlpha (opencode/z-ai/glm-5.3-flash)_
_Filename date follows the H6395 acceptance contract (handoff minted 10-10-2026); all live metrics below were pulled 2026-10-21T17:47–18:01Z._
_Report-only: zero mutations to prod, repo state or config. The audit repairs nothing; findings that warrant work mint handoffs._
_Predecessor audits 09-10 (operational circuits, out of scope here): [backups-DR](MATURITY_BACKUPS_DR_2026-10-09.md), [payments circuit](MATURITY_PAYMENTS_CIRCUIT_2026-10-09.md), [CI/deploy](MATURITY_CI_DEPLOY_2026-10-09.md)._

## Frame (written before any metric was scored)

Five axes, framed per the /maturity-audit discipline BEFORE looking at data:

1. **Test-suite health** — green rate of the `ci.yml` workflow on recent runs, flake/failure classes, and statement coverage with emphasis on money-path files (payments, tariffs, enrollments, payouts, webhook verification).
2. **Code & data-model integrity** — migration inventory, presence/absence of an offline schema baseline (drift detection), schema-docs truth, open-issue pressure.
3. **Student-facing surface** — cabinet and bot critical flows reachable and correct via READ-ONLY GET probes of the same URLs the uptime workflow pulses (`/`, `/login`, `/online`), plus hourly journey-probe telemetry (GET-only enrollment/payment journeys) and the in-process cabinet probe machinery.
4. **Docs truth** — failsweep gates `agent_docs_sla` and `megabook_s16` (both FAIL as of 10-10 per H6395), re-measured live before vs after this audit; Systema-side changelog lint.
5. **App-error observability** — how application errors are captured, alerted and retained: log channels, stack wiring, PII masking, external error trackers, de-facto detection via external probes.

Threshold rules are stated per axis in the scorecard below. Machine metrics only (git CI telemetry + read-only HTTP GETs + repo tree via API); every number carries its source command and UTC timestamp.

## Verdict: **3 / 5**

| Axis | Score | Threshold rule | One-line basis |
|---|---|---|---|
| Test-suite health | 4/5 | 5 if CI green ≥95% AND coverage ≥80% AND no money-critical file <50%; 4 if green ≥90% AND coverage ≥75% AND money core ≥85%; 3 if green ≥80% | CI 36/40 green (M1), coverage 76.2% (M5), money core 86–97% (M6–M8, M11) — but webhook signature verifier 7% (M9) caps it |
| Code & data-model integrity | 3/5 | 5 if offline schema baseline exists AND drift check green; 4 if baseline missing but migrations uniform; 3 if baseline AND schema-docs coverage missing | 508 migrations (M12), NO `database/schema/` dump (M13), docs/schema covers pedagogy model only, 97 open issues (M19) |
| Student-facing surface | 4/5 | 5 if all probes 200 AND p90 latency <2s; 4 if all 200 AND one surface >5s; 3 if any non-200 on critical URL | `/` 200 1.71s, `/login` 200 1.67s, `/online` 200 5.45s (M17); journey probes 24/24 PASS (M3); uptime 24/24 (M4); in-process cabinet probe exists (M18) |
| Docs truth | 2/5 | 5 if all failsweep gates PASS; 4 if only one gate red and freshly triaged; 2 if same gates red for >7 days unremediated | `agent_docs_sla` FAIL and `megabook_s16` FAIL both on 10-10 (H6395 baseline) and still FAIL 21-10 (M16) — 11 days, no repair handoff landed |
| App-error observability | 2/5 | 5 if error tracker + alerting channel wired; 4 if stack includes daily + slack/critical alerting; 2 if single-file local log only | composer has no sentry/bugsnag (M14); `stack => ['single']` (M15); detection de-facto outsourced to external probes (M3, M4) |

**Headline: 3 / 5.**

## Top-3 levers (each cites the metric row that justifies it)

1. **Wire app-error alerting into the log stack (or add Sentry).** `stack => ['single']` with the `slack` channel defined but not in the stack and no error tracker in composer (M14, M15) means a fatal app error on prod is visible only when a human looks at `laravel.log` or an external probe trips (M3/M4). Smallest lever: add `daily` + `slack` (level `critical`) to the stack — config-only, no code change.
2. **Close the money-critical crypto/trust test gap.** `HttpPaypalWebhookSignatureVerifier` 3/43 statements (7%) and `ClaimTrustPolicy` 0/12 (0%) (M9, M10) sit directly on the payment-ingest trust boundary; everything around them is 86–98% (M6–M8, M11). Two focused test classes close the only red cells in the money matrix.
3. **Repair the two red docs-truth gates and cap their dwell time.** `agent_docs_sla` + `megabook_s16` have been FAIL since at least 10-10 and are still FAIL at 21-10 (M16); the audit gates exist and are loud, but nothing owns the repair — mint a repair handoff per gate and add a dwell-time reminder (gate red >7 days → auto-mint).

## Metric table (≥15 rows; every row with source command + UTC timestamp)

| # | Metric | Value | Source command | Timestamp (UTC) |
|---|---|---|---|---|
| M1 | CI workflow green rate, last 40 completed runs | 36 success / 4 failure (90%) | `gh run list -R gasyoun/Systema-Sanscriticum -w ci.yml --limit 40 --json conclusion,createdAt,event,displayTitle` | 2026-10-21T17:52Z |
| M2 | CI failure classes, last 40 | 4/4 on `pull_request` events (runs 39561170640 21-10, 39410496385 + 39410129118 + 39408467039 20-10); 0 on `push`→main | same command as M1 | 2026-10-21T17:52Z |
| M3 | Journey probes (external, hourly, GET-only) | 24/24 success | `gh run list -R gasyoun/Systema-Sanscriticum -w journey-probes.yml --limit 24 --json conclusion,createdAt` | 2026-10-21T17:55Z |
| M4 | Uptime — samskrte.ru (hourly) | 24/24 success | `gh run list -R gasyoun/Systema-Sanscriticum -w uptime-samskrte.yml --limit 24 --json conclusion,createdAt` | 2026-10-21T17:55Z |
| M5 | Statement coverage, whole app | 86,251 / 113,225 = **76.2%** | artifact `php-coverage` of run 39218851105 (`gh api repos/gasyoun/Systema-Sanscriticum/actions/artifacts/12173234013/zip`, clover XML sum of `coveredstatements/statements`) | 2026-10-21T18:00Z |
| M6 | Coverage: `app/Models/Payment.php` | 642/672 = 96% | same clover artifact as M5 | 2026-10-21T18:00Z |
| M7 | Coverage: `app/Http/Controllers/PaymentController.php` | 276/321 = 86% | same clover artifact as M5 | 2026-10-21T18:00Z |
| M8 | Coverage: `app/Http/Controllers/PaypalClaimController.php` | 522/540 = 97% | same clover artifact as M5 | 2026-10-21T18:00Z |
| M9 | Coverage: `app/Services/Payments/HttpPaypalWebhookSignatureVerifier.php` | 3/43 = **7%** | same clover artifact as M5 | 2026-10-21T18:00Z |
| M10 | Coverage: `app/Services/Payments/ClaimTrustPolicy.php` | 0/12 = **0%** | same clover artifact as M5 | 2026-10-21T18:00Z |
| M11 | Coverage: Tochka money services | `TochkaPaymentService` 54/55 = 98% · `TochkaApiCreditImporter` 73/78 = 94% · `TochkaBalanceService` 89/96 = 93% | same clover artifact as M5 | 2026-10-21T18:00Z |
| M12 | Migration inventory | 508 files under `database/migrations/` | `gh api 'repos/gasyoun/Systema-Sanscriticum/git/trees/main?recursive=1'` (6733 blobs, not truncated) | 2026-10-21T17:56Z |
| M13 | Offline schema baseline for drift check | **absent** — no `database/schema/*.sql` dump in tree; `docs/schema/` holds only `ai_native_pedagogy_model` (pedagogy JSON/YAML, not DB) | same tree query as M12 | 2026-10-21T17:56Z |
| M14 | External error trackers in composer require | none (no sentry/bugsnag/rollbar; stack: laravel/framework, filament, horizon, reverb, spatie/laravel-backup, danog/madelineproto…) | `gh api repos/gasyoun/Systema-Sanscriticum/contents/composer.json --jq .content` → require keys | 2026-10-21T17:57Z |
| M15 | Log stack wiring | `'stack' => ['single']` (file `storage/logs/laravel.log`, MaskPersonalData tap); `daily` (14 days) defined but NOT in stack; `slack` channel defined, level `critical`, NOT in stack | `gh api repos/gasyoun/Systema-Sanscriticum/contents/config/logging.php --jq .content` | 2026-10-21T17:57Z |
| M16 | Failsweep before vs after audit | BEFORE 17:54Z: `agent_docs_sla` FAIL · `megabook_s16` FAIL · GTD byte-cap FAIL · Goals parity FAIL · registry PASS · interlinks PASS. AFTER 18:01Z: identical (bit-for-bit same states) — zero mutation | `python3 tools/failsweep_check.py` (Uprava) — twice | 2026-10-21T17:54Z / 18:01Z |
| M17 | Read-only prod probes (this box, GET only) | `https://samskrte.ru/` → 200 in 1.71s · `/login` → 200 in 1.67s · `/online` → 200 in 5.45s | `curl -sS -o /dev/null -w '%{http_code} %{time_total}s' <url>` ×3 | 2026-10-21T17:58–17:59Z |
| M18 | In-process cabinet probe machinery | present: `app/Console/Commands/ProbeCabinetHealth.php`, `app/Filament/Pages/CabinetProbeHealth.php`, `app/Models/CabinetProbeRun.php`, `config/cabinet_probe.php` (login+surfaces, TG alerts, history → `cabinet_probe_runs`) | tree query as M12 + `gh api …/contents/config/cabinet_probe.php` | 2026-10-21T17:57Z |
| M19 | Open issues pressure | 97 open | `gh api repos/gasyoun/Systema-Sanscriticum --jq .open_issues_count` | 2026-10-21T17:53Z |
| M20 | Test suite size | 1086 `*Test.php` files under `tests/` | tree query as M12 | 2026-10-21T17:56Z |
| M21 | Deploy-via-Actions state | last run 2026-09-24 (`cancelled` — the H6288 «waiting» run has since been cancelled); deploys continue via server-side cron per MATURITY_CI_DEPLOY_2026-10-09 | `gh run list -R gasyoun/Systema-Sanscriticum -w deploy.yml --limit 10 --json conclusion,createdAt,databaseId` | 2026-10-21T18:04Z |

## Per-axis notes

### 1. Test-suite health — 4/5
CI green 36/40 (90%) in the recent window (M1); all four reds are PR-branch runs, none on merged main (M2) — consistent with the 30-day baseline (79.7% over a much wider window, MATURITY_CI_DEPLOY_2026-10-09). Weekly coverage artifact: 76.2% statements (M5). Money core is well covered (M6–M8, M11: 86–98%). The single bright-red cell is the PayPal webhook signature verifier at 7% and `ClaimTrustPolicy` at 0% (M9, M10) — the trust boundary of the payment ingest path is effectively untested, which alone caps the axis at 4. Flake evidence in-window: no run shows a success-after-retry pattern in the last 40; rerun-attempt data was not pulled (out of the 45-min budget), so flake count = 0 observed, INCONCLUSIVE as a rate.

### 2. Code & data-model integrity — 3/5
508 migrations (M12) show a continuously evolving schema, but there is **no committed schema dump** (`php artisan schema:dump` output absent from `database/schema/`, M13), so migration drift cannot be checked offline at all — every drift check requires a live DB, and this audit (report-only) did not run one. `docs/schema/` documents the pedagogy model, not the DB — schema-docs truth gap. 97 open issues (M19) is moderate pressure for a repo this size; not scored down (threshold rule only weighs schema-baseline artifacts).

### 3. Student-facing surface — 4/5
All three pulsing URLs return 200 from this box (M17). `/online` (the public schedule/online-classes page) at 5.45s is the one slow surface — above the 2s rule but under the 5s failure line of the threshold, hence 4 not 5 (rule: "4 if all 200 AND one surface >5s"). Hourly external journey probes (GET-only enrollment/payment flows) 24/24 green (M3) and uptime 24/24 (M4). The in-process cabinet probe (login + surfaces, TG alerting, history table, M18) exists and is the right shape; its live pass-rate was not queried (would require prod DB access — out of read-only scope), so cabinet health rests on external evidence only.

### 4. Docs truth — 2/5
Both gates named by H6395 (`agent_docs_sla`, `megabook_s16`) were FAIL on 10-10 and are **still FAIL** on 21-10 (M16), measured identically before and after this audit — the gates are honest but unowned. Systema-side hygiene that DOES work: `Changelog lint` green in all recent sampled runs (workflow list query, 17:52Z window) and `changelog_queue/` is populated (74 files, tree query M12). Scored 2 because both red gates exceed the 7-day dwell without a landed repair.

### 5. App-error observability — 2/5
No external error tracker (M14). The log stack sends everything to a single local file; `daily` (14-day retention) and `slack` (critical) channels are **defined but not wired into the stack** (M15) — PII masking via `MaskPersonalData` tap is present on the file channels (good). De-facto error detection is outsourced to the external uptime/journey probes (M3, M4), which catch down-classes but not in-app exception storms. Scored 2 per the threshold rule ("stack includes daily + slack/critical alerting" would be 4; single-file only is 2).

## Report-only proof

- Prod touched by: 3 GET requests (M17) — no POST/PUT/DELETE, no SSH, no artisan/DB commands.
- Failsweep before (17:54Z) vs after (18:01Z): identical gate states (M16) — no repo/config mutation during the audit window.
- The report lands via PR; the PR itself (repo state change) is the delivery act, outside the audit window.
- Uprava main tree remained read-only: `failsweep_check.py` itself refused its `data/failsweep_latest.json` write into the guarded checkout (observed 17:54Z, not overridden).

## Risks / unknowns

- Flake rate: not measurable in-budget (needs per-run attempt history pull) — recorded INCONCLUSIVE, not PASS.
- Cabinet in-process probe live pass-rate: not queried (needs prod read access beyond GET).
- Coverage artifact is the weekly run of 19-10 — 2 days stale vs main at audit time.
- `/online` 5.45s single sample from one vantage; no multi-probe latency baseline.

## Delivery

- **Changed:** this report (`reports/MATURITY_PRODUCT_CORE_2026-10-10.md`) on branch → PR to `main`; nothing else.
- **Unchanged:** prod, repo files, config, workflows, Uprava main tree — all untouched (M16 before/after proof).
- **Checks:** `gh api repos/gasyoun/Systema-Sanscriticum/contents/reports/MATURITY_PRODUCT_CORE_2026-10-10.md` → 200 expected after human merge (product repo: PR-only per standing rule; merge left to MG).
- **Risks:** report is a snapshot — CI/coverage numbers move daily; flake rate and cabinet-probe live rate remain unknown (see Risks).
- **Inspect:** verdict table + M9/M10/M14/M15 rows (levers 1–2), M16 rows (lever 3, report-only proof).

## Addendum — 25-10-2026 (lever 2 closed after the audit window)

_Этот раздел дописан ПОСЛЕ аудита и вердикт аудита не пере-скоривает._

- **M9 `HttpPaypalWebhookSignatureVerifier`: 3/43 (7 %) → 43/43 (100 %).** **M10 `ClaimTrustPolicy`: 0/12 (0 %) → 12/12 (100 %).** Обе цифры — из clover-артефакта [`coverage.yml` run 40176250915](https://github.com/gasyoun/Systema-Sanscriticum/actions/runs/40176250915) (`workflow_dispatch` на ветке PR, 25-10-2026T09:41Z) — тот же метод, что у M5–M11, не локальная оценка.
- **PR:** [#3494](https://github.com/gasyoun/Systema-Sanscriticum/pull/3494) — два класса тестов, 31 тест / 52 assertions, Pint чист, CI (PHP 8.3) зелёный.
- **Оговорка, которая переживает метрику:** `ClaimTrustPolicy` на `main` не вызывается ни из одного контроллера — живые каналы идут через `User::isEstablishedClaimStudent()`. 100 % покрытия этого класса доказывают поведение библиотечной политики, а НЕ то, что прод ходит через неё; к тому же docblock класса обещает неймспейс `'paypal'`/`'bank_claim'` вместо реальных `services.paypal`/`services.bank_claim`. Wire-or-delete — открытое человеческое решение, не часть этого PR.
- Headline **3 / 5** не пере-скоривается этим аддендумом: для смены оценки нужен свежий полный прогон матрицы, а не две закрытые ячейки.
