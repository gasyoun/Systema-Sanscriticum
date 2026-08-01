# IMPLEMENTATION — Noboring dozhim wave-1

_Created: 01-08-2026 · Last updated: 01-08-2026_

Index: [PLAN_Systema_NOBORING_DOZHIM_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_Systema_NOBORING_DOZHIM_2026H2.md)

Work in a **session-unique worktree** off `origin/main`. Systema is main-tree guarded.

## H-A — Deal dozhim readiness (before queue UI)

1. Re-read `GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md` §1 GC-C1/C2 and `PaymentDealBridgeObserver`.
2. Inventory tests in `tests/Feature/DealTest.php` — what creation paths exist. — **H2097:** sole create path was paid bridge → won; factory/tests only for open.
3. Gap: **open Deal on pending payable intent** — **done H2102** (01-08-2026):
   - Additive methods on observer: `qualifiesAsPayableIntent` + `openDealForIntent` (not a separate service)
   - Idempotent on (user/lead, course, installment_group) via `findOpenDealFor` / `dealOfPlan`
   - Flag `crm_pipeline_board` still gates all writes (default OFF)
   - **Never** call grant/access; `source_payment_id` set only on paid close
4. GC-C2: if manager report still missing, add Filament page grouping paid conversion by `Deal.assigned_to` / `Lead.assigned_to` behind `manager_sales_report`.
5. Feature tests + Pint — H2102: pending→open, second pending no dup, pending→paid same deal, flag-off silence.
6. PR non-money; merge when green.

## H-B — Baseline + queue + templates + drip

Depends on H-A merged (or Deal open-on-intent already true — still re-verify).

1. Baseline command:
   - `php artisan dozhim:baseline --days=90` (name flexible)
   - Output: rates A/B, counts, date; exit 0
   - Document in ROADMAP + RESULTS_LOG
2. `WorkQueueReport` / UnifiedSales: bucket open Deals with pending payment age > N (config hours, default 24 or 168 matching NF «неделя»).
3. Seed MessageTemplates (`dozhim`):
   - payment methods help
   - installment / curator CTA
   - feedback «what was wrong with webinar»
   - upsell complement product (generic placeholder)
4. Job/command: create FollowUpTask for aged open Deals (idempotent marker).
5. Drip: schedule send via existing Messaging for template steps day0/day3/day7; flag `dozhim_drip`.
6. Tests for bucket + task creation + flag off silence.
7. PR; flags OFF.

## H-C — Cabinet recovery + CTA

Depends on H-A at least (resume needs payment id / course).

1. Route e.g. `cabinet/payment-resume/{payment}` or unpaid list card.
2. UI: amount, course, pay button, FAQ payment link, curator contact, **installment CTA copy**.
3. Flag `payment_recovery_cta`.
4. Optional: deep-link from drip template.
5. Feature tests + Playwright smoke if cabinet harness exists.
6. PR **without auto-merge**; GTD `@DO` human merge.

## File touch map (expected)

| Area | Paths |
|---|---|
| Flags | `config/features.php` |
| Deal bridge | `app/Observers/PaymentDealBridgeObserver.php` or new service |
| Queue | `app/Services/WorkQueueReport.php`, Filament pages |
| Templates | seeder / `MessageTemplate` |
| Baseline | `app/Console/Commands/…` or `tools/` |
| Cabinet | `routes/web.php`, controller, blade |
| Tests | `tests/Feature/Dozhim*.php` |

## Non-touch

- `PaymentObserver` grant / fireOnPaid core
- Prod env / live flag flip
- ORS WP publish (unless separate content link task)

_Dr. Mārcis Gasūns_
