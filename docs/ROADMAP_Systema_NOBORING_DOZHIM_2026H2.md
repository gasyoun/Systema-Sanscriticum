# ROADMAP — Noboring «дожим» adoption (Systema + samskrte)

_Created: 01-08-2026 · Last updated: 27-08-2026_

> **Truth-pass 27-08-2026** (Grok 4.6 `grok-4.6`). Dual-axis: closed references checked; the two optional A boxes stay open. Not archived.

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

Prod was still on pre-H2094 deploy at probe time — numbers from the same filters as `dozhimBaseline` / `conversionForRange` + Lead counts (read-only tinker probe). Re-verified after deploy with live artisan (H2188).

| Window | A orders | A paid | A pending | A lost | **Rate A** | B leads | B converted | **Rate B** |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 30 d | 125 | 82 | 40 | 3 | **65.6%** | 50 | 0 | **0.0%** |
| 90 d | 574 | 492 | 40 | 42 | **85.7%** | 217 | 5 | **2.3%** |

**vs NF case** (47% → 63% → 67% after own sales desk): Rate A **30 d is already in the post-dozhim band** (~66%); 90 d is higher (mix/seasonality). Primary KPI for H-B targets remains **Rate A**. Rate B is **not** a usable funnel rate today — `converted_at` is almost never set (instrumentation gap, not a true 0% lead→pay; product fix shipped flag-OFF in H2186, **enabled in prod 02-08-2026** — see the § Rate B residual below and the 24-08 check-in).

#### H2188 re-verify — live `dozhim:baseline --json` (prod, as_of `2026-08-02 19:26:05`)

Command **present** on box after deploy. Artifact: [docs/ops/dozhim_baseline_prod_2026-08-02.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ops/dozhim_baseline_prod_2026-08-02.json). No percentages invented — table is artisan JSON verbatim.

| Window | A orders | A paid | A pending | A lost | **Rate A** | B leads | B converted | **Rate B** | Δ Rate A vs H2096 |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 30 d | 120 | 74 | 43 | 3 | **61.7%** | 50 | 0 | **0.0%** | **−3.9 pp** |
| 90 d | 567 | 482 | 43 | 42 | **85.0%** | 216 | 5 | **2.3%** | **−0.7 pp** |

**Drift read (not a product regression claim):** rolling 30d window lost ~5 paid rows net and gained +3 pending; Rate A 30d slipped from the post-dozhim band (~66%) to **61.7%** (still above red `CONVERSION_WARN_PCT` 50, just under green 63). 90d nearly flat. Rate B unchanged story (sparse / flag OFF). Keep H2096 as the Wave 0 freeze; use this table as the current live check-in.

#### Rate B instrumentation residual (H2186, 02-08-2026)

**Why Rate B is ~0%:** not product failure. Root cause (code + GETCOURSE_PARITY §2.4):

| Path | Sets `payment.lead_id`? | Calls `Lead::markConverted()`? |
|---|---|---|
| Deposit / trial checkout | Yes (email match) | Yes on paid webhook |
| Marathon paid | Yes (enrollment) | Yes |
| **Ordinary course checkout** | Yes since **02-08-2026** (prod env ON; code default still false) | Yes since **02-08-2026** (prod env ON) |

**Owner path shipped (code default still false; env is the deploy rubilnik):**

1. Flag `features.lead_converted_at_on_course_paid` / env `LEAD_CONVERTED_AT_ON_COURSE_PAID` (code default **false**).
2. `Payment::markLinkedLeadConverted()` — `lead_id` or email fallback + backfill FK.
3. `processSuccessfulPayment` (non-conditional) calls it when flag ON.
4. Checkout attaches `lead_id` on create only when flag ON.
5. Tests: `tests/Feature/LeadConvertedAtOnCoursePaidTest.php`.

**Prod enable (human, 02-08-2026):** on `193.232.229.92` `/var/www/html` — `.env` has `LEAD_CONVERTED_AT_ON_COURSE_PAID=true` (backup `.env.bak.h2186.20260802`), `php artisan config:cache` rebuilt; `php artisan config:show features.lead_converted_at_on_course_paid` → **true**.

**Post-enable baseline** (`php artisan dozhim:baseline --json`, as_of `2026-08-02 19:26:28`): Rate A 30d **61.7%** (74/120) / 90d **85.0%** (482/567); Rate B 30d **0.0%** (0/50) / 90d **2.3%** (5/216) — still sparse, as expected (no historical backfill). Forward-looking only: Rate B numerator fills for **new** course paid events. Primary H-B targets stay on **Rate A**. Re-check after ~1 week of paid volume.

#### Post-enable check-in (24-08-2026, prod `193.232.229.92`, deploy `04ad4f3a`)

Fresh `php artisan dozhim:baseline --json` + read-only usage counters (H3440; full method + evidence in [Uprava SYSTEMA_NOBORING_PROD_USAGE_AUDIT_24-08-2026.md](https://github.com/gasyoun/Uprava/blob/main/SYSTEMA_NOBORING_PROD_USAGE_AUDIT_24-08-2026.md)):

| Window | A orders | A paid | A pending | A lost | **Rate A** | B leads | B converted | **Rate B** |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 30 d | 147 | 116 | 30 | 1 | **78.9%** | 16 | 1 | **6.3%** |
| 90 d | 512 | 412 | 30 | 70 | **80.5%** | 179 | 17 | **9.5%** |

Trend line Rate A 30d: 65.6% (01-08) → 61.7% (02-08) → **78.9%** (24-08) — above green `CONVERSION_WARN_PCT` band (63) and above NF's post-dozhim band (63–67%). **Causality is NOT proven: no controlled experiment was run** (recovery CTA enablement, queue, seasonality and mix all moved together); the number is a check-in, not an effect claim. Rate B is filling forward since the 02-08 instrumentation enable but stays sparse (16 leads in 30d).

Usage counters (same probe): `dozhim_drip_logs` total **0** (auto-drip never fired — `dozhim_drip=false` in prod, the only OFF flag); follow-ups with `deal_id` created in 30d **3**, closed **0** (operator queue not being worked); active landings using `student_story_block` **0** (block built 10-08-2026, unused).

Flag status — code default vs prod `.env` (verified 24-08-2026 via live `config()`):

| Flag | Code default | Prod |
|---|---|---|
| `payment_recovery_cta` | false | ✅ true (07-08-2026) |
| `lead_converted_at_on_course_paid` | false | ✅ true (02-08-2026) |
| `manager_sales_report` | false | ✅ true |
| `dozhim_queue` | false | ✅ true |
| `dozhim_drip` | false | ❌ false — the only OFF flag |
| `crm_pipeline_board` | false | ✅ true |

Earlier sections below keep their historical wording («default OFF», «not yet enabled») as dated records of their time; this table is the current state.

#### «Заявка» definition (PLAN D3/D7 + `config/conversion.php`)

| Rate | «Заявка» (denominator) | Success (numerator) | Exclusions / status map |
|---|---|---|---|
| **A (primary)** | Real course `Payment` created in window (`is_conditional = false`) | `status IN ('paid','success')` | Excluded tariffs (env `CONVERSION_EXCLUDED_TARIFFS`, default): `Расход`, `salary_payout`, `deposit`, `trial`. Open unpaid: `pending`. Lost: `canceled` / `cancelled` / `failed`. |
| **B (secondary)** | `Lead` row with `created_at` in window | `converted_at` IS NOT NULL | Product mark on paid path. Deposit/trial/marathon always; **course path ON in prod** as of 02-08-2026 (H2186 env enable; code default still false). |

Targets from config (not re-derived here): green ≥ `CONVERSION_TARGET_PCT` (default **63**), red < `CONVERSION_WARN_PCT` (default **50**); unclosed pending after `CONVERSION_UNCLOSED_AFTER_DAYS` (default **3**).

### Wave 1a — Deal dozhim readiness (H-A)

Prior art: GC-C1 **shipped**. Residual for dozhim:

- [x] Audit: when is Deal created today? (paid bridge only vs pending intent) — **done H2097** (01-08-2026; see § below)
- [x] **Open Deal** (or ensure open Deal) when user creates **payable intent** (pending Payment) — **done H2102** (01-08-2026; `PaymentDealBridgeObserver::openDealForIntent`, still rank-4, flag OFF)
- [x] GC-C2 manager attribution report — **done H2058 residual** ([PR #1098](https://github.com/gasyoun/Systema-Sanscriticum/pull/1098), 03-08-2026); roadmap checkbox closed H2362 (07-08-2026). Product: [`ManagerSalesReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/ManagerSalesReport.php) + `OrderPaymentConversionService::managerScoreboard()` + flag `manager_sales_report` default **OFF**. F5 RULED join=`payments.created_by_user_id` (not `leads`/`deals.assigned_to`); vis=super_admin/admin/accountant all + manager own. Empty-looking UI is expected until CRM managers create/fix payments in admin (NULL → «Без менеджера»).
- [x] Flag `crm_pipeline_board` remains default OFF; staging admin-on — **re-pinned H2102** (default still false; pending path gated by same flag)

**Unblocks:** H-B queue has cards.

#### Wave 1a census — GC-C2 manager sales attribution (H2185 → SHIPPED H2058 residual)

**Verdict: SHIPPED** (03-08-2026, [PR #1098](https://github.com/gasyoun/Systema-Sanscriticum/pull/1098) — H2058 residual). Spec row in [GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md) §1 GC-C2 = **SHIPPED**. H2185 (02-08) correctly reported NOT_BUILT; product landed next day on the F5-ruled path. H2362 (07-08) only closed this roadmap checkbox (stale `[ ]` after product merge).

##### What shipped (product surfaces)

| Surface | Role |
|---|---|
| [`ManagerSalesReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/ManagerSalesReport.php) | Filament «Продажи по менеджеру» `/admin/manager-sales-report` |
| [`OrderPaymentConversionService::managerScoreboard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reports/OrderPaymentConversionService.php) | Breakdown by `payments.created_by_user_id` (F5 a) |
| [`RoleGate::managerSalesReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/RoleGate.php) | admin/accountant/manager; page scopes manager → own rows |
| `config/features.php` → `manager_sales_report` | Default **false** (`MANAGER_SALES_REPORT`) — deploy switch |
| [`ManagerSalesReportTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/ManagerSalesReportTest.php) | Flag off / admin all / manager own / teacher 403 |

Join is **not** `Lead`/`Deal.assigned_to` (near-empty by construction on conversion-eligible tariffs; H2185 prod fill Lead ~0.4% / Deal 0%). F5 a RULED: blame column `created_by_user_id`. Self-serve/webhook (`NULL`) → «Без менеджера».

##### Historical `assigned_to` census (H2185, 02-08-2026 — still true for that column)

`assigned_to` remains CRM-ops only (LeadResource, Helpdesk, RemindLeads). It is **not** the manager-sales join path. Prod fill snapshot (host `193.232.229.92`, as_of `2026-08-02 18:46:31`): leads 1/264 (0.4%), deals 0/8 (0%). Do not invent newer numbers here.

##### Ops residual (not H2362)

1. **Prod flag still OFF by design** — enable only after deploy + `MANAGER_SALES_REPORT=true` + `config:cache` (separate ops step; not this handoff).
2. **Empty-looking board is expected** until managers create/fix payments in admin (so `created_by_user_id` fills).

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

- [x] WorkQueue bucket: **unpaid / open Deal past N hours** — **done H2119** (01-08-2026): `WorkQueueReport::unpaidOpenDeals()` + WorkQueue card; threshold `config/dozhim.php` `unpaid_deal_hours` (default 24); flag `dozhim_queue` default **OFF**. Cards need open Deals from H2102 when `crm_pipeline_board` ON. UnifiedSales surface deferred (cockpit bucket is the operator surface).
- [x] MessageTemplate category `dozhim` (4 scripts from NF table: payment help, installment CTA, feedback, upsell) — **done H2059** (03-08-2026): `MessageTemplateSeeder`, 3 of 4 tagged with `dozhim_step` (day0/day3/day7) for the auto-drip below; upsell ships untagged (operator sends it manually).
- [x] Auto FollowUpTask on open Deal age threshold — **done H2059**: `dozhim:create-followups` (daily 08:00), idempotent on an existing open `FollowUpTask` per Deal, gated `dozhim_queue` (same flag as the bucket above — this is the bucket's actionable half, not a new surface).
- [x] Auto-drip via existing Messaging channels (TG/email) — linear only; no n8n branch required for wave-1 — **done H2059**: `dozhim:drip` (daily 08:00), `WorkQueueReport::agedOpenDeals()` (ungated twin of `unpaidOpenDeals()` — visibility and delivery are independent flags), `DozhimDripDispatcher` (TG/VK/email, reuses `MessagePlaceholders` + `DebtorReminderMail`), `DozhimDripLog` idempotency (deal_id, step unique). One step per deal per run, never skips a step even if the deal aged past a later one.
- [x] Flag `dozhim_queue` default OFF — **done H2119** (pinned in features + test)
- [x] Flag `dozhim_drip` default OFF — **done H2059** (pinned in `config/features.php` + tests)
- [x] Operator daily TG digest + drip enabled — **done MG ruling 24-08-2026** (`dozhim:drip` enabled in prod 24-08, first run delivered day-0 to all aged deals; `dozhim:notify-operator` будни 10:00 MSK → Telegram сводка владельцу очереди, flag `dozhim_operator_notify`, recipient `DOZHIM_OPERATOR_TG_CHAT_ID` / manager-with-tg fallback; guide [docs/MANAGER_DOZHIM_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANAGER_DOZHIM_GUIDE_RU.md))

**Unblocks:** H-C front mirror; live operator use. All Wave 1b checkboxes closed; Wave 1c (H-C) closed by H2060 (roadmap ticks H2363).

### Wave 1c — Student recovery (H-C)

- [x] Cabinet route: resume unpaid payment + FAQ payment + curator contact — **done H2060** ([PR #1110](https://github.com/gasyoun/Systema-Sanscriticum/pull/1110), release 1.87.2): `student.access` debt cards keep existing «Оплатить / продлить» / «Внести платёж» resume buttons (`DebtPaymentResolver`); when flag ON also show amount + FAQ + curator block
- [x] CTA copy: рассрочка / partial / cheaper path → link (no new payment product) — **done H2060**: installment CTA prose + links only (`route('faq.payment')`, `t.me/rusamskrtam`); no new SKU/installment product
- [x] Flag `payment_recovery_cta` OFF; PR **without auto-merge** (money-adjacent) — **done H2060**: `config/features.php` `PAYMENT_RECOVERY_CTA` default **false**; tests `PaymentRecoveryCtaTest` (flag off hides block, flag on shows amount/FAQ/curator, public FAQ page). Roadmap residual close: **H2363** (Grok 4.5)

**Surfaces (verify on main):** [`resources/views/student/hybrid/access.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/hybrid/access.blade.php) · [`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php) `payment_recovery_cta` · public [`/faq/payment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/faq/payment.blade.php) · [`tests/Feature/Cabinet/PaymentRecoveryCtaTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Cabinet/PaymentRecoveryCtaTest.php).

**Prod enable (07-08-2026, H2363 follow-through):** on `193.232.229.92` `/var/www/html` — `.env` has `PAYMENT_RECOVERY_CTA=true` (backup `.env.bak.h2363.20260807145802`), `php artisan config:cache` rebuilt; `php artisan config:show features.payment_recovery_cta` → **true**; public `/faq/payment` HTTP 200. No grant-path change.

### Wave 2 — NF Education next case

- [x] [Антикейс языковой школы](https://noboring-finance.ru/cases/antikejs-yazykovoj-shkoly-produkt-horoshij-deneg-net/) — unit economics / P&L product gaps — **read + mapped 19-08-2026 (Sonnet 5 `claude-sonnet-5`, via `/drain`)**.

  Case is a **cautionary anti-case, not a proven lever**: offline «Lingvistik» language school
  (3 physical locations, kids+adults+summer camps) — the owner had every gap NF flags (CAC>LTV,
  underpriced product, no churn tracking, fixed teacher comp regardless of enrollment, 2/3
  locations unprofitable) diagnosed correctly and **never executed the fix**; the school closed.
  No measured outcome to port — the transferable content is the gap checklist itself.

  | NF gap flagged | Systema-Sanscriticum today | Verdict |
  |---|---|---|
  | CAC > LTV blindness | [`UnitEconomicsService::forBlock()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/UnitEconomicsService.php) takes CAC as a first-class param per course/block; [`UnitEconomics`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/UnitEconomics.php) + [`StudentUnitEconomics`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/StudentUnitEconomics.php) Filament pages | **Already covered** |
  | No churn/retention tracking | [`RetentionChart`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Widgets/RetentionChart.php) widget (lesson-completion doходимость, admin/manager-visible); Rq4 study retention pipeline | **Already covered** |
  | Teacher comp fixed regardless of enrollment | Default `salary_type` is **`percent`** (revenue-share, scales with enrollment automatically); `fix_per_student` variant also exists in [`TeacherSalaries`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/TeacherSalaries.php) — the fixed-regardless-of-size mode the case describes is not Systema's default | **Already avoided** |
  | Unprofitable physical locations | N/A — Systema is online-only, no physical-location P&L to split | **Not applicable** |
  | Pricing too low relative to quality/cost | No dedicated pricing-strategy review surface found; existing `trial_price` / tariff config is mechanical, not a margin-vs-quality audit | **Residual — business call, not engineering** |

  **Net finding:** Systema's product surface already has instrumented answers for 3 of the 5
  gaps this anti-case warns about (unlike «Lingvistik», which had none); the 4th doesn't apply
  (no physical locations); the 5th (pricing strategy) is a pricing-policy question for a human,
  not a missing engineering surface — no code gap to close here.
- [x] Optional siblings: дебиторка antikeis, «собственник из операционки», «тратим меньше / зарабатываем больше» — **read + mapped 23-08-2026 (ox-alpha `x-preview-f-free`, via `/drain`)**.

  Same NF Education series as the anti-case above (programme parent [H259](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H259-Opus_Systema-Sanscriticum_profit_funds_bdr_kpi_06.07.26.md)); all three are narratives with no directly transferable metric — the transferable content is again the gap checklist. Surfaces verified against `origin/main` today; source pages fetched live 23-08-2026.

  **1. Дебиторка antikeis** — [Антикейс: онлайн-школа и невозвратная дебиторка](https://noboring-finance.ru/cases/antikeis/) («Алохомора», 17.02.2023): school launched half-upfront installments without telling its финдир → ~2 млн ₽ uncollectible receivables in 3 weeks + кассовый разрыв; collection calls degenerated into re-selling the unpaid half.

  | NF gap flagged | Systema-Sanscriticum today | Verdict |
  |---|---|---|
  | Installments launched ad hoc, outside the finmodel | Installments are first-class: [`PaymentPromise`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/PaymentPromise.php) + conditional access [`ConditionalAccessGranter`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/ConditionalAccessGranter.php), Dolyami webhook — a modeled surface, not a sales-desk improvisation | **Already covered** |
  | ДЗ invisible until the balance exposed it | [`ReceivablesGovernance`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/ReceivablesGovernance.php) «Дебиторка и рассрочка — план-факт», [`Debtors`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Debtors.php), daily `receivables:check` 04:00, [`config/receivables.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/receivables.php) | **Already covered** |
  | «Collections» calls that are really re-selling | Debt work is split by design: automated [`DozhimDripDispatcher`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DozhimDripDispatcher.php) + [`DebtorReminderMail`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/DebtorReminderMail.php); curator gets suggestion templates, explicitly «подсказка куратору, а не автопродажа» | **Already covered** |
  | Commercial policy: ceiling on installment share/count vs cash plan | Plan-fact reporting exists, but no explicit ceiling number anywhere in config or docs — a finance-policy ruling, not a missing surface | **Residual — business call** |

  **Net finding:** the exact trap described here (informal installments → silent uncollectible debt discovered only from the balance) is structurally closed in Systema — promises are modeled, aged, reminded, drip-chased and reported план-факт. Residue is one policy number (allowed receivables ceiling) that only MG can set.

  **2. «Собственник из операционки»** — [Онлайн-школа: наладили процессы и вывели собственника из операционки](https://noboring-finance.ru/cases/onlayn-schkola-viveli-sobstvennika-iz-operacionki/) («Аура», 12.09.2024): кассовый учёт → ДДС/ОПиУ/баланс, поэтапное признание выручки годового курса, фонды прибыли, БДР, отдел маркетинга; собственник вышел из операционки за 9 месяцев (+33% заказов, +700 тыс ₽ прибыли).

  | NF move | Systema-Sanscriticum today | Verdict |
  |---|---|---|
  | Detailize opaque marketplace payouts (who bought what) | Payments are first-party natively (payments ↔ students ↔ courses, Deal bridge) — no weekly anonymous platform lump to untangle | **Not applicable** |
  | Accrual revenue recognition per control point on long courses | No deferred-revenue / recognition engine found in `app/`; risk bounded by short course cycles + funds discipline, but the accounting-policy choice itself is MG's | **Residual — business call** |
  | Funds system (дивиденды / резерв / развитие) | [`ProfitFunds`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/ProfitFunds.php) «Фонды прибыли — распределение и резерв» | **Already covered** |
  | БДР plan-fact + weekly management rhythm | [`FINANCE_REVIEW_RHYTHM.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md) weekly finance-KPI review, [`DelegationKpi`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/DelegationKpi.php), [`FinanceCockpit`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/FinanceCockpit.php), opex entry ruled [#953](https://github.com/gasyoun/Systema-Sanscriticum/issues/953) | **Already covered** |
  | Owner exits operations (trained ops director takes the levers) | Delegation plumbing exists (`RoleGate::finance()`, accountant role, curator lanes); actually handing over is a life/business decision, not a code gap | **Residual — owner call** |

  **Net finding:** the financial machinery «Аура» spent 9 months building already exists here instrumented (funds, KPI dashboards, delegated finance roles). Two honest residues are human by nature: accrual-recognition policy for long courses, and how much операционки MG chooses to keep.

  **3. «Тратим меньше / зарабатываем больше»** — [Стали тратить на миллион меньше, а зарабатывать на миллион больше](https://noboring-finance.ru/cases/stali-tratit-na-reklamu/) («Мандаринка», 03.02.2022): еженедельный план-факт по выручке и расходам на рекламу, конверсия в продажу по менеджерам с роутингом лидов к конвертирующим, ROMI по каналам с отказом от слабых: план 53%→68%, доля рекламы в выручке 65%→26,5%.

  | NF move | Systema-Sanscriticum today | Verdict |
  |---|---|---|
  | Еженедельный план-факт выручка vs расходы | Weekly [`FINANCE_REVIEW_RHYTHM.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md) cadence + [`SalesForecastService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Crm/SalesForecastService.php) forecast baseline | **Already covered** |
  | Конверсия в продажу по менеджерам, лиды — к конвертирующим (>20%) | [`ManagerSalesReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/ManagerSalesReport.php) «Продажи по менеджеру» + двухзнаменательный [`OrderPaymentConversionService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reports/OrderPaymentConversionService.php) (rate A/B); кто получает лид — остаётся операционной практикой, не поверхностью | **Already covered** (роутинг — практика) |
  | ROMI по каналам, отказ от неэффективных | Расходный ledger есть — [`AdPostSpend`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/AdPostSpend.php) (budget/writers на пост); доходная атрибуция принадлежит конвенции UTM из ORS-FAQ sales roadmap + целям Метрики — объединённый ROMI-view здесь бы её продублировал | **Partial — routed to Sales UTM lane** |
  | Рассрочка на этапе оплаты, чтобы не терять клиентов | [`PaymentPromise`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/PaymentPromise.php) + Dolyami webhook | **Already covered** |
  | Ещё бесплатные вебинары (объём верха воронки) | Редакционно-контентное решение МГ, не код-поверхность | **Residual — owner call** |

  **Net finding:** mechanical half of «Мандаринки» уже покрыто (per-manager conversion, план-факт ритм, рассрочки); единственный реальный стык — ROMI-картинка — сознательно живёт в Sales UTM lane (ORS-FAQ), а не новым отчётом тут. Wave 3 «Connect front funnel» остаётся правильным следующим шагом.

### Wave 3 — Connect front funnel (ORS sales Phase 1 leftovers)

Only after wave-1 rates are visible:

- [x] «С чего начать», quiz, intro CTA site-wide (existing sales roadmap — not reinvented here) — **already shipped, verified live 24-08-2026 (A05 drain)**: all three sub-items were done under [ORS-FAQ roadmap_samskrte_sales.md](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/roadmap_samskrte_sales.md) (its own source of truth, not duplicated here) — «С чего начать» page + quiz embed [PR #385](https://github.com/gasyoun/Systema-Sanscriticum/pull/385) (H323), free-intro CTA site-wide [PR #1185](https://github.com/gasyoun/Systema-Sanscriticum/pull/1185) (H2365) + [PR #1722](https://github.com/gasyoun/Systema-Sanscriticum/pull/1722) (H2760). Live check: `https://samskrte.ru/online/s-chego-nachat` → HTTP 200 with onramp quiz present; `https://samskrte.ru/online` → HTTP 200 with `free-intro-banner` block present.

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