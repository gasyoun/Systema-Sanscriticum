# Architecture — Tochka multi-mode recurring billing (H2026)

_Created: 31-07-2026 · Last updated: 31-07-2026_

**Status:** design of record for auto-subscription via Точка API  
**Handoff:** [H2026](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2026-Grok_Systema-Sanscriticum_tochka-recurring-billing-modes_31.07.26.md)  
**Sibling (separate lane):** [H2027 PayPal Subscriptions](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2027-Grok_Systema-Sanscriticum_paypal-subscriptions-api_31.07.26.md) — **not** in this scope  
**Executor / provenance:** Grok 4.5 (`grok-4.5`), 31-07-2026  

Tochka product docs: [Подписки (рекуррентные платежи)](https://developers.tochka.com/docs/tochka-api/opisanie-metodov/podpiski-rekurrentnye-platezhi) · `Create Subscription With Receipt` (fiscal) · card-only · `period` Day/Month/Year · `trancheCount` caps · cancel via `Set Subscription Status` · webhooks `acquiringInternetPayment`.

---

## 1. Why this exists

Today Systema takes **one-shot** Tochka links (`TochkaPaymentService::createPaymentWithReceipt` → `payments_with_receipt`). Access is a paid `Payment` row + keys/groups ([money-access-core-manual](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-access-core-manual.md)).

Students and ops need **automatic card charges** in several product shapes:

| # | Mode (product language) | What student experiences |
|---|-------------------------|--------------------------|
| **A** | Per-course on payment anniversary | Each course debits on *its* join/pay day of month |
| **B** | Consolidated 1× or 2× / month | One (or two) charges covering **all** active directions |
| **C** | Multi-month prepay | Pay several months of one course at once (no monthly drip) |
| **D** | Club | Separate membership stream, not mixed into course bundles unless opted in |
| **E** | Installment collection | Curator-approved schedule; bank auto-collects due rows |

All five must be **first-class** in the data model even if ship order is phased (A → E → D → C → B).

---

## 2. Non-goals (this programme)

- PayPal Subscriptions (→ **H2027** only).
- Self-serve installment *approval* (Alohomora anti-case: findir still owns plan creation via existing [`InstallmentPlanCreator`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/InstallmentPlanCreator.php) + [`config/receivables.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/receivables.php)).
- SBP on subscriptions (Tochka: **card only** today).
- Replacing one-shot checkout for deposits/trial/full prepay — those stay one-shot.
- Auto-merge money PRs; flags default **OFF**.

---

## 3. Layering (keep access algebra)

```
┌─────────────────────────────────────────────────────────────┐
│  Product UX: mode picker, club join, multi-month offer       │
├─────────────────────────────────────────────────────────────┤
│  BillingCommitment  (what we intend to collect)              │
│  BillingSubscription (1 Tochka subscription / bank object)   │
│  BillingChargeAttempt → Payment (existing access path)       │
├─────────────────────────────────────────────────────────────┤
│  TochkaPaymentService (+ createSubscriptionWithReceipt, …)   │
│  WebhookController (idempotent, same ledger family as H1359) │
├─────────────────────────────────────────────────────────────┤
│  Tariff::accessKey / grantAccess / BlockAccessMaterializer   │
└─────────────────────────────────────────────────────────────┘
```

**Invariant:** a successful bank charge always materialises (or extends) a normal `payments` row and reuses `fireOnPaid` / access keys. Do **not** invent a second access system keyed only by subscription id.

---

## 4. Core entities (proposed)

### 4.1 `billing_profiles` (1 per user)

| Column | Purpose |
|--------|---------|
| `user_id` | owner |
| `preferred_mode` | `per_course` \| `consolidated` (student preference for courses) |
| `consolidated_cadence` | `monthly_1` \| `monthly_2` (1st only / 1st+15th — @DECIDE defaults) |
| `tochka_consumer_id` | Tochka saved-card consumer when offered |
| `default_charge_day` | 1–28 for consolidated (null → join day of first commitment) |

### 4.2 `billing_subscriptions`

One row ↔ one Tochka `operationId` (subscription).

| Column | Purpose |
|--------|---------|
| `uuid` | public id |
| `user_id` | |
| `mode` | enum: `per_course` \| `consolidated` \| `club` \| `installment` \| `multi_month` |
| `provider` | `tochka` (H2027 may add `paypal` later; same table ok) |
| `provider_subscription_id` | Tochka operationId |
| `status` | `pending_first_pay` \| `active` \| `past_due` \| `cancelled` \| `completed` |
| `period` | `Day` \| `Month` \| `Year` (Tochka enum) |
| `tranche_count` | fixed N or null for open-ended / schedule-less |
| `amount_rub` | fixed amount when mode uses fixed tranche; null if variable |
| `amount_policy` | `fixed` \| `sum_commitments` \| `installment_due` |
| `anchor_date` | first successful charge date (anniversary for per-course) |
| `course_id` | set for per_course / multi_month; null for consolidated |
| `installment_group_id` | set for installment mode (ties to PaymentPromise group) |
| `club_product_id` | set for club (see §6.D) |
| `feature_flag_snapshot` | which flags were on at create (debug) |

### 4.3 `billing_commitments`

What the student still owes under a subscription (or under consolidated bag).

| Column | Purpose |
|--------|---------|
| `billing_subscription_id` | parent bank object (nullable until card bound) |
| `user_id` | |
| `kind` | `course_month` \| `course_block` \| `club_period` \| `installment_row` \| `multi_month_pack` |
| `course_id` / `tariff_id` / `payment_promise_id` | product links |
| `amount_rub` | |
| `covers_from` / `covers_to` | access window this commitment buys |
| `access_key` / `start_block` / `end_block` | what to write on resulting Payment |
| `status` | `scheduled` \| `charged` \| `failed` \| `waived` \| `cancelled` |
| `due_on` | calendar date for charge / installment |
| `payment_id` | filled when charge succeeded |

### 4.4 Charge → Payment

On Tochka success webhook for a subscription charge:

1. Resolve `billing_subscriptions.provider_subscription_id`.
2. Allocate amount to one or more `billing_commitments` (FIFO by `due_on`).
3. Create/update `Payment` rows (`provider=tochka`, meta JSON with subscription + commitment ids).
4. Run existing paid path (access, prana, emails) **once per commitment Payment**, not once for the bag only — so teacher revenue shares and block materialization stay correct.

Failed charge: mark commitment `failed`, subscription `past_due`, fire dunning copy lane (existing money-dunning copy where applicable). **Do not** revoke access on first fail if product policy is grace (default grace = 0 for installment, 3 days for club — @DECIDE).

---

## 5. Mode specifications

### Mode A — Per-course anniversary (`per_course`)

**Intent:** each course has its own Tochka subscription; charge day = calendar day of first successful payment (clamp 29–31 → 28).

**Tochka mapping:** `Create Subscription With Receipt`, `period=Month`, `trancheCount` = remaining months of enrolment or open-ended (if open-ended use schedule-less / re-create per Tochka rules — see §8).

**Access:** each successful charge opens the next block/month window via commitment (`covers_from`/`covers_to` or next unpaid `block_N`).

**Fiscal:** one receipt line item per charge = that course’s tariff title.

**UI:** checkout option «Автооплата каждый месяц в день первой оплаты» on a single course.

---

### Mode B — Consolidated 1× or 2× month (`consolidated`)

**Intent:** one bank subscription; amount = **sum** of all active course/club commitments due in the billing window.

**Cadence:**

| Setting | Charge days (MSK) |
|---------|-------------------|
| `monthly_1` | student `default_charge_day` (or 1st) |
| `monthly_2` | day D and D+15 (mod month, clamp) |

**Tochka mapping:** preferably **subscription without fixed graph** + merchant-initiated charges of computed amount on our schedule (Tochka: [подписки без графика](https://developers.tochka.com/docs/tochka-api/opisanie-metodov/podpiski-rekurrentnye-platezhi/rabota-s-podpiskami-bez-grafika-spisania)); if product only allows fixed amount, use fixed max + refund/adjust — **prefer variable-amount API**.

**Access:** split charge across commitments → multiple `Payment` rows sharing `billing_charge_id`.

**Fiscal:** multi-line Items[] on one receipt when Tochka allows; else one aggregated line + internal breakdown in `payments.meta` (must document chosen path after first sandbox call).

**UI:** cabinet «Единый платёж 1/2 раза в месяц по всем направлениям»; migrating from A→B cancels per-course subs and opens one consolidated.

**Risk:** amount changes month to month — student must see next charge preview 3 days prior (mail + cabinet).

---

### Mode C — Multi-month prepay (`multi_month`)

**Intent:** pay N months (or N blocks) of **one** course in one go.

**Two sub-paths (both valid):**

| Sub-path | Bank | When |
|----------|------|------|
| **C1 one-shot** | existing `payments_with_receipt` | N months prepaid, no card save |
| **C2 subscription** | Tochka sub with `trancheCount=N`, `period=Month` **or** single charge of N×month price | if bank requires sub object for card storage |

**Default ship:** **C1** (already almost “bundle” / start_block–end_block). Recurring API only if we need card on file for auto-renew after N.

**Access:** one Payment covering `start_block..end_block` or `covers_from..covers_to` for N months; materialize range via existing bundle/range rules.

**UI:** «Оплатить 3 / 6 месяцев сразу» with price = sum of months (optional multi-month discount — pricing @DECIDE, not hardcode here).

---

### Mode D — Club (`club`)

**Intent:** membership product **orthogonal** to course enrolments.

**Product prerequisites:**

1. A dedicated `Course` (or new `club_products` table) with tariff type `full` / monthly price, OR `Category` flagged membership.
2. Access: groups + optional content keys; do not auto-open paid course blocks.

**Tochka:** own `billing_subscriptions.mode=club`, never folded into consolidated **unless** student toggles «Включать клуб в единый платёж».

**Default:** club = separate subscription (clearer cancel, clearer fiscal name «Клуб …»).

---

### Mode E — Installment collection (`installment`)

**Intent:** existing curator plan (`PaymentPromise` + `installment_group_id`) gets **automatic collection** on `promised_at`.

**Creation:** still only via admin/`InstallmentPlanCreator` (and receivables limits). Self-serve CTA remains «запросить оплату по частям» → curator, not bank.

**Binding:** after plan exists, student opens «Привязать карту для автосписания» → Tochka sub mode `installment` linked to `installment_group_id`.

**Each due date:** charge `amount` of that promise; on success set promise fulfilled + `Payment` linked (`linked_promise_id` already exists).

**Failed:** keep promise active/expired path + dunning; do **not** auto-increase debt beyond plan.

**Receivables:** auto-collection **reduces** illiquid AR risk vs manual promises — still respect `max_concurrent` / `max_term_months`.

---

## 6. Mode interaction matrix

| | A per-course | B consolidated | C multi-month | D club | E installment |
|--|--------------|----------------|---------------|--------|---------------|
| A | — | Mutual exclusive per user for **courses** (profile.preferred_mode) | C is prepay on one course; can coexist with A on other courses | Independent | E is debt schedule; after plan ends may switch to A |
| B | — | — | Multi-month commitment can sit inside consolidated bag as one line | Optional include | Installment rows may be included as lines or stay separate (default **separate**) |
| C | | | — | Independent | Don’t mix multi-month pack with installment on same blocks |
| D | | | | — | Independent |
| E | | | | | — |

**Rule of thumb:** one **course content stream** is either A or B (plus optional C prepay windows); club and installment are **side rails**.

---

## 7. Checkout / cabinet surfaces

1. **Course checkout** — radio:
   - One-shot (current)
   - Auto monthly this course (A)
   - Multi-month pack (C)
   - Request parts (existing installments CTA → E later)
2. **Cabinet → Payments** — list subscriptions, next charge, cancel, switch A↔B.
3. **Club landing** — join D only.
4. **Admin Filament** — create E plan (existing) + «Send card-bind link»; list past_due.

All student strings: no shame language; «оплата по частям» not «рассрочка» on student UI (H1290).

---

## 8. Tochka API integration sketch

Extend [`TochkaPaymentService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Payments/TochkaPaymentService.php):

| Method | Tochka |
|--------|--------|
| `createSubscriptionWithReceipt(...)` | POST subscriptions_with_receipt |
| `getSubscriptionStatus($operationId)` | GET status |
| `cancelSubscription($operationId)` | Set status Cancelled |
| `chargeSubscriptionVariable(...)` | if schedule-less API available |

Config (`services.tochka` + `features.tochka_recurring`):

- `TOCHKA_RECURRING_ENABLED=false` (master)
- `TOCHKA_RECURRING_MODES=per_course,club,installment` (allow-list)
- Reuse token, customerCode, merchantId, taxSystemCode, vatType

**Webhook:** extend `handleTochkaWebhook` to recognise subscription charges (payload fields per Tochka changelog `paymentLinkId` / operation type). Reuse `payment_webhook_events` ledger + amount guards (H1359). New Payment may be created on charge if not pre-created as pending.

**Permissions:** token needs `MakeAcquiringOperation`, `ReadAcquiringData`, `ReadCustomerData`.

**Cabinet checklist (human @DO):** confirm subscription product enabled on merchant; sandbox first.

---

## 9. Phased ship order (H2026)

| Phase | Deliverable | Modes | Exit |
|-------|-------------|-------|------|
| **0** | This architecture + feature flags + empty models/migrations (no live charges) | scaffold | migrations + config + tests that flags OFF ⇒ no new routes change behaviour |
| **1** | Tochka create/cancel/status + webhook → Payment for **fixed-amount** sub | **A** pilot on 1 course | sandbox charge + grantAccess |
| **2** | Installment bind + charge on `promised_at` | **E** | promise fulfilled only via paid Payment |
| **3** | Club product + separate sub | **D** | club group membership without course blocks |
| **4** | Multi-month pack C1 (one-shot range) polished; C2 if needed | **C** | range access + receipt |
| **5** | Consolidated sum + preview + cadence | **B** | multi-commitment single bank charge |

Do not enable Phase 5 in prod until Phase 1 webhook integrity is proven.

---

## 10. Money-contour safety

- Feature flags default **OFF**; deploy migrations inert.
- PR **without** auto-merge; human enable on prod.
- Never double-grant on webhook replay (ledger).
- Cancel Tochka sub when admin cancels installment group or student leaves club.
- Log every mode decision in `billing_subscriptions` meta for finance audit.
- Teacher salary / revenue recognition: still per `Payment` line — consolidated must split.

---

## 11. Open product @DECIDE (human)

| ID | Question | Default if silent |
|----|----------|-------------------|
| D1 | Consolidated charge days for `monthly_2` | 1 and 15 MSK |
| D2 | Grace days after failed club charge before access pull | 3 |
| D3 | Grace for installment fail | 0 (promise already models debt) |
| D4 | Multi-month discount % | 0 |
| D5 | Club include-in-consolidated default | false |
| D6 | Open-ended course monthly vs fixed trancheCount | fixed to course length when known, else 12 |

---

## 12. Related code (today)

| Piece | Role |
|-------|------|
| `TochkaPaymentService` | one-shot only |
| `Payment` / `WebhookController` | paid path + ledger |
| `InstallmentPlanCreator` / `PaymentPromise` | E schedule source |
| `Tariff` / `BlockAccessMaterializer` | access keys |
| `config/receivables.php` | E risk limits |
| H2017 billing paths | claim/invoice — orthogonal |

---

## 13. Metadoc pointer

Companion improvement backlog: `docs/ARCHITECTURE_TOCHKA_RECURRING_BILLING_MODES_2026.meta.md` (created with first implementation PR if not same commit).

_Dr. Mārcis Gasūns_
