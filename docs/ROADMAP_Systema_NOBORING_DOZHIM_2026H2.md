# ROADMAP — Noboring «дожим» adoption (Systema + samskrte)

_Created: 01-08-2026 · Last updated: 01-08-2026_

Index: [PLAN_Systema_NOBORING_DOZHIM_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_Systema_NOBORING_DOZHIM_2026H2.md)

## Source map (NF case → us)

| NF case section | Their move | Our lever |
|---|---|---|
| Как устроены продажи | Webinar/marathon → заявка → call-center | Intro/marathon → Order/Lead → **product queue** |
| Пробоина | 47% order→pay; op report marketing | **Baseline script** + dual denominators |
| Свой отдел | Scripts, CRM, upsell, payment help | **Deal + FollowUpTask + MessageTemplate + drip** |
| Итог | Same ad spend, more revenue | Measure order→pay; ad spend constant is external |

## Waves

### Wave 0 — Measure (unblocks everything)

- [x] Commit `tools/order_pay_conversion_baseline.php` (or Artisan command): last 30/90d — **done H2094** (`php artisan dozhim:baseline`; service `OrderPaymentConversionService::dozhimBaseline`)
  - rate A: paid Orders / (paid + unpaid eligible Orders)
  - rate B: Leads with first Payment / Leads in period (`converted_at`)
- [x] Write numbers into this roadmap + ORS `roadmap_samskrte_sales` — **done H2096** (01-08-2026; see snapshot below; mirrored under ORS Noboring section)
- [x] Document which statuses count as «заявка» (PLAN defaults) — **done H2096** (see § «Заявка» definition)

**Unblocks:** H-B targets; honest KPI.

#### Wave 0 baseline snapshot (prod `193.232.229.92`, as_of `2026-08-01 13:25:58`)

Prod was still on pre-H2094 deploy at probe time — numbers from the same filters as `dozhimBaseline` / `conversionForRange` + Lead counts (read-only tinker probe). After deploy: `php artisan dozhim:baseline --json`.

| Window | A orders | A paid | A pending | A lost | **Rate A** | B leads | B converted | **Rate B** |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 30 d | 125 | 82 | 40 | 3 | **65.6%** | 50 | 0 | **0.0%** |
| 90 d | 574 | 492 | 40 | 42 | **85.7%** | 217 | 5 | **2.3%** |

**vs NF case** (47% → 63% → 67% after own sales desk): Rate A **30 d is already in the post-dozhim band** (~66%); 90 d is higher (mix/seasonality). Primary KPI for H-B targets remains **Rate A**. Rate B is **not** a usable funnel rate today — `converted_at` is almost never set (instrumentation gap, not a true 0% lead→pay).

#### «Заявка» definition (PLAN D3/D7 + `config/conversion.php`)

| Rate | «Заявка» (denominator) | Success (numerator) | Exclusions / status map |
|---|---|---|---|
| **A (primary)** | Real course `Payment` created in window (`is_conditional = false`) | `status IN ('paid','success')` | Excluded tariffs (env `CONVERSION_EXCLUDED_TARIFFS`, default): `Расход`, `salary_payout`, `deposit`, `trial`. Open unpaid: `pending`. Lost: `canceled` / `cancelled` / `failed`. |
| **B (secondary)** | `Lead` row with `created_at` in window | `converted_at` IS NOT NULL | Product mark on paid path — **underfilled today** (see Rate B above). |

Targets from config (not re-derived here): green ≥ `CONVERSION_TARGET_PCT` (default **63**), red < `CONVERSION_WARN_PCT` (default **50**); unclosed pending after `CONVERSION_UNCLOSED_AFTER_DAYS` (default **3**).

### Wave 1a — Deal dozhim readiness (H-A)

Prior art: GC-C1 **shipped**. Residual for dozhim:

- [x] Audit: when is Deal created today? (paid bridge only vs pending intent) — **done H2097** (01-08-2026; see § below)
- [ ] **Open Deal** (or ensure open Deal) when user creates **payable intent** (pending Payment / unpaid Order path) — still rank-4 observer only
- [ ] GC-C2 manager attribution report if `assigned_to` still unused in reports (spec: NOT_BUILT as of last census — re-verify)
- [ ] Flag `crm_pipeline_board` remains default OFF; staging admin-on

**Unblocks:** H-B queue has cards.

#### Wave 1a audit — when is Deal created? (H2097, 01-08-2026)

**Verdict: paid bridge only.** There is **no** production path that opens a Deal on pending payable intent. Auto-created rows are **won (closed)** after a qualifying paid Payment. Open Deals exist only via tests/factory (or manual DB); Filament does not create them.

| Path | Creates Deal? | When | Stage / closed |
|---|---|---|---|
| [`PaymentDealBridgeObserver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentDealBridgeObserver.php) `Deal::create` | **Yes — sole app create** | After `qualifiesAsSale` (status ∈ `paid`/`success`, not expense/salary/deposit/trial/marathon/`is_conditional`) and no open/plan deal to close | **Won immediately** (`stage` = won, `closed_at` set, `source_payment_id` set) |
| Same observer `closeDealWith` | No create — closes existing open Deal | Paid sale finds open Deal via lead/user + course (+ installment group rules) | Open → won |
| Same observer `reopenDealClosedBy` | No create — reopens | Payment reverse (`failed`/`canceled`/`cancelled`) on deal that had that `source_payment_id` | Won → first stage (if still on won) |
| Pending Payment / unpaid Order | **No** | `qualifiesAsSale` requires `PAID_STATUSES` only | — |
| Filament DealKanban / UnifiedSalesBoard | **No create** | Stage drag / move only (`disableEditModal = true` on kanban) | — |
| Filament `DealResource` | **Absent** | — | — |
| `Deal::factory()` / tests | Yes (test only) | Default state is **open** (`closed_at` null) | Open unless `->won()` |

**Gates / silence conditions**

1. Flag [`crm_pipeline_board`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php) default **`false`** (`CRM_PIPELINE_BOARD`) — entire bridge returns early; prod inert unless env ON.
2. Registered only on `Payment` observe in `AppServiceProvider` (created + status-changed updated).
3. Known gap (observer docblock): clearing `is_conditional` without status change never fires `updated` → no Deal for that path until predicate expands.
4. Exceptions in bridge are swallowed (rank-4 must not roll back rank-1 webhook tx).

**Reproduce (code, no prod DB required)**

```text
# sole Deal::create in app/
# PaymentDealBridgeObserver::closeOrRecordDeal ~L224
# qualifiesAsSale ~L128 requires Payment::PAID_STATUSES
# Feature: tests/Feature/DealTest.php (paid → won; flag off → 0 deals)
```

**Implication for next Wave 1a checkbox:** H-B unpaid/open Deal queue will have **no auto cards** for the ~40 pending payments in the Wave 0 30d window until **open Deal on pending intent** ships (next checkbox / H2058 residual). Existing bridge only backfills **won** history when flag is ON.

**Does not change:** money grant (`PaymentObserver`), flag defaults, schema.

Executor: Grok 4.5 (`grok-4.5`). Parent programme H-A: [H2058](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2058-Sonnet_Systema-Sanscriticum_noboring-dozhim-ha-deal-readiness_01.08.26.md).

### Wave 1b — Operator dozhim (H-B)

- [ ] WorkQueue / UnifiedSales bucket: **unpaid / open Deal past N hours**
- [ ] MessageTemplate category `dozhim` (4 scripts from NF table: payment help, installment CTA, feedback, upsell)
- [ ] Auto FollowUpTask on open Deal age threshold
- [ ] Auto-drip via existing Messaging channels (TG/email) — linear only; no n8n branch required for wave-1
- [ ] Flags: `dozhim_queue`, `dozhim_drip` default OFF

**Unblocks:** H-C front mirror; live operator use.

### Wave 1c — Student recovery (H-C)

- [ ] Cabinet route: resume unpaid payment + FAQ payment + curator contact
- [ ] CTA copy: рассрочка / partial / cheaper path → link (no new payment product)
- [ ] Flag `payment_recovery_cta` OFF; PR **without auto-merge** (money-adjacent)

### Wave 2 — NF Education next case

- [ ] [Антикейс языковой школы](https://noboring-finance.ru/cases/antikejs-yazykovoj-shkoly-produkt-horoshij-deneg-net/) — unit economics / P&L product gaps
- [ ] Optional siblings: дебиторка antikeis, «собственник из операционки», «тратим меньше / зарабатываем больше»

### Wave 3 — Connect front funnel (ORS sales Phase 1 leftovers)

Only after wave-1 rates are visible:

- [ ] «С чего начать», quiz, intro CTA site-wide (existing sales roadmap — not reinvented here)

## Non-goals

- Hire 5 remote sales reps
- Rebuild Deal/Lead kanban from scratch
- Full Tochka installment product in this programme
- GetCourse SaaS for third parties
- Parallel third sales roadmap file that drifts from `roadmap_samskrte_sales`
- Live % lift as ship gate (measurement is post-ship)

## Linked roadmaps

- [ROADMAP_GETCOURSE_PARITY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md) — CRM domain
- [GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md) — money ladder
- [ORS roadmap_samskrte_sales](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/roadmap_samskrte_sales.md) — shop funnel
- [increase_course_sales.md](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/increase_course_sales.md)

_Dr. Mārcis Gasūns_