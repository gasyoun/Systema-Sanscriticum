# Money / access-core systems manual — Systema-Sanscriticum

_Created: 25-07-2026 · Last updated: 31-07-2026_

The deep systems manual for the money and access core of the Systema-Sanscriticum LMS
(samskrte.ru): how a payment becomes access, how a price is computed, how the bank
webhook is guarded, and what the finance-facing control loops watch. Written for
engineers touching the money path and — in the `[RU]` chapters — for the findir /
accountant operating it. Every executable claim in this manual was actually run during
authoring; the command list and recorded outputs live in the companion metadoc
[money-access-core-manual.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-access-core-manual.meta.md).

> **All amounts, names and IDs in this document are fictional placeholders** (the same
> synthetic values the test suite uses: a 12 000 ₽ course, a 4 800 ₽ block, a 2 500 ₽
> half-block, a 2 000 ₽ deposit). The repository is public; no real student data, no
> real Tochka keys or amounts appear here.

**Sources of truth** (code wins over any doc, including this one):
[Tariff.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Tariff.php) ·
[Lesson.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Lesson.php) ·
[Payment.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Payment.php) ·
[PaymentController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaymentController.php) ·
[WebhookController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/WebhookController.php) ·
[PaymentWebhookEvent.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/PaymentWebhookEvent.php) ·
[BlockAccessMaterializer.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/BlockAccessMaterializer.php) ·
[PranaService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Prana/PranaService.php) ·
[ReferralService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/ReferralService.php) ·
[AuditCheckoutIntegrity.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/AuditCheckoutIntegrity.php).
Sibling docs: [finance-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/finance-manual.md)
(panel navigation for the accountant),
[accountant-guide.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/accountant-guide.md)
(step-by-step panel procedures),
[webhook-security.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md),
[money-core-adversarial-review.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-core-adversarial-review.md)
(the standing adversarial-review harness),
[revenue-recognition.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/revenue-recognition.md).

---

## 1. The access-key algebra

Access to paid lessons is gated by **string keys**, not by foreign keys. One paid
`Payment` row carries exactly one key in `payments.tariff`; a lesson names the set of
keys that open it; access is set intersection. Three functions define the whole
algebra:

### 1.1 `Tariff::accessKey()` — what a purchase writes

| Tariff configuration | `accessKey()` | Meaning |
|---|---|---|
| `type='full'` (and any non-block type) | `full` | whole course |
| `type='vip'` | `full` | VIP is a *price* variant, not an access variant |
| `type='bundle'`, no block range | `full` | multi-course bundle without block binding |
| `type='bundle'`, `start_block=3`, `end_block=6` | `block_3` | range bundle: key names the *first* block; the rest of the range is materialized (§1.4) |
| `type='block'`, `block_number=2` | `block_2` | one whole block |
| `type='block'`, `block_number=2`, `block_half=1` | `block_2_h1` | half-block |

All six rows above were reproduced live from `Tariff::accessKey()` during authoring
(see metadoc, spot-run #3). The invariant is **one key = one block**: a range never
lives in the key, only in the numeric `payments.start_block` / `end_block` columns.

Special *non-access* values of `payments.tariff` that never match any lesson key:
`deposit` (course reservation), `trial` (single-lesson trial), `marathon_paid`
(H471 marathon track), `Расход` (bookkeeping expense/refund), `salary_payout`
(teacher payout). These route to their own handlers (§3.2) and are excluded from
revenue/loyalty/credit queries by the `real()` scope and explicit `whereNotIn` lists.

### 1.2 `Lesson::unlockingKeys()` — what a lesson accepts

```
lesson(block_number=2, block_half=1)  →  ["full", "block_2", "block_2_h1"]
lesson(block_number=3, half=null)     →  ["full", "block_3"]
```

A lesson always accepts `full` and its whole block; only a half-marked lesson
additionally accepts its half key. An unsplit lesson is **not** openable by a half key
(`block_3_h1` does not open a `block_3` lesson with `block_half=null` — verified
live, spot-run #3).

### 1.3 `Lesson::isUnlockedBy(array $ownedKeys)` — the gate

`is_preview` lessons return `true` for everyone, **including guests with zero keys**
(the public "пример урока" on the landing page) — checked *before* key intersection.
For everything else: `array_intersect(unlockingKeys(), ownedKeys) ≠ ∅`.

The owned-key set is computed per course:
[StudentController::getUserUnlockedTariffs()](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/StudentController.php)
plucks `payments.tariff` of the user's **paid** payments (`status` ∈ `['paid','success']`,
`Payment::PAID_STATUSES`) for that `course_id`. Two statuses both mean "paid" for
historical reasons; never re-hardcode the pair — use the `paid()` scope.

### 1.4 Two parallel ACLs: groups and keys

A paid course payment produces **two** grants, and they answer different questions:

1. **Group membership** — `Payment::grantAccess()` syncs the student into every group
   attached to the course (`syncWithoutDetaching`). Groups gate schedules, Zoom
   links, chat — the *live-course* surface.
2. **Key-based lesson gating** — the algebra above. Keys gate the *content* surface.

A recording tariff (`is_recording`) is deliberately **not** a third system: its
`accessKey()` is still `full`/`block_N`; a completed course simply has no live
surface left, so "recording opens lessons but not a schedule" holds by itself.

### 1.5 Range purchases: `BlockAccessMaterializer`

A payment covering blocks N..M carries only `block_N`. On success,
[BlockAccessMaterializer](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/BlockAccessMaterializer.php)
creates one **zero-amount access-only sibling row** per missing block
(`amount=0`, `tariff='block_X'`, `transaction_id='access_grant_#<primary id>'`,
created via `withoutEvents`). Siblings:

- open their block's lessons (the key lands in the owned-key set);
- are invisible to finance (the `access_grant_#` prefix is excluded from sheet-sync;
  `amount=0` + no deposit credit excludes them from every credit/loyalty query);
- are idempotent (already-owned keys are skipped) and are **deleted** when the
  primary payment is reversed (`removeSiblingsOf`, wired into the reversal hook §3.3).

`full`-key payments and conditional grants are skipped entirely (conditional access
creates its own per-block rows in `ConditionalAccessGranter`).

### 1.6 Conditional access ("под обещание")

`is_conditional=true` payments are access without money: `amount=0`, excluded from
every financial aggregate by `real()`. When a real payment for the same scope lands,
`reconcileConditionalGrants()` deletes the covered conditional rows (containment
model: real `full` covers any conditional on the course; real `block_N` covers only
conditional `block_N`) and closes the linked `PaymentPromise` once no open
conditional rows remain on it.

---

## 2. Containment upgrade-credit

**Rule: a tariff credits the money already paid for everything it strictly contains,
on the same course.**

```
full        ⊃  every block_% key of the course     (whole blocks and halves)
block_N     ⊃  block_N_h1, block_N_h2              (its two halves)
block_N_hH  ⊃  ∅                                    (halves never overlap)
```

Implemented in [Tariff::upgradeCreditForUser()](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Tariff.php).
Verified live: the whole test matrix (24 tests, 80 assertions) in
[HalfBlockPurchaseTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/HalfBlockPurchaseTest.php)
and [DepositPartialConsumptionTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Deposit/DepositPartialConsumptionTest.php)
is green in this worktree (metadoc, spot-run #2).

Key properties, each carried by a named test:

- **Contained value = cash + deposit part.** The credit of a contained payment is
  `amount + deposit_credit_applied` — a half bought partly (or wholly) with a deposit
  still credits its full value on upgrade (H071 #9;
  `upgrade_credit_includes_deposit_part_of_half_block`,
  `half_fully_covered_by_deposit_still_gives_upgrade_credit`).
- **`full` credit is flag-fenced.** Crediting already-paid blocks into a full-course
  purchase sits behind `features.full_course_block_credit` (**default OFF**,
  `FULL_COURSE_BLOCK_CREDIT` in `.env`). Half→whole-block credit is **always on** and
  not governed by the flag (`full_course_does_not_credit_paid_blocks_when_flag_off`).
- **`vip` and `bundle` tariffs give no upgrade credit** — a range bundle's price is
  already a package price (`vip_and_bundle_tariffs_give_no_upgrade_credit`).
- **Zero-amount and conditional rows never credit** (access-only siblings §1.5,
  conditional grants §1.6).
- **Refund netting.** `Расход` rows with negative amounts attributable to the
  purchased scope subtract from the credit, floored at zero
  (`refunded_half_block_no_longer_discounts_whole_block`,
  `partial_refund_reduces_upgrade_credit_and_floors_at_zero`). **Attribution is the
  weak point — see defect C2 in §9.**

---

## 3. Payment lifecycle

### 3.1 States

`pending → paid` (bank webhook or admin) is the money-bearing transition.
`Payment::PAID_STATUSES = ['paid','success']` — both mean paid; different save paths
historically wrote different literals. Failure states: `failed`, `canceled`
(canonical single-l spelling; `cancelled` is tolerated defensively on the reversal
hook). A payment created *already paid* (admin manual entry, import) fires the same
paid-path as a transition.

### 3.2 What "paid" triggers — `fireOnPaid` routing

| `tariff` value | Handler | Grants |
|---|---|---|
| `Расход`, `salary_payout` | — (early return) | nothing: pure bookkeeping rows |
| `deposit` | `processDeposit()` | no lesson access; welcome email (once per account), lead conversion, curator note |
| `trial` | `processTrial()` | one `LessonAccessGrant` on the course's trial lesson, no course access |
| `marathon_paid` | `processMarathonPaid()` | marks the marathon enrollment paid, no course access |
| everything else | `processSuccessfulPayment()` | full paid path below |

The full paid path (one DB transaction): `grantAccess()` (groups §1.4) →
`enrollInCourse()` (course_user "Записался", never overwrites an existing manual
status) → *unless conditional*: welcome email (**once per account ever** — the
password regeneration guard, H071 #15), course-welcome email (first paid payment of
*this* course), purchase-confirmation receipt (every real payment, H1286), first-week
onboarding reminders (first payment of this course), prana award (§4.5), promo
`markRedeemed()` (redemption counted **on payment**, not on checkout creation).
After the transaction: curator notification, deposit consumption (§4.3), conditional
reconciliation (§1.6), promise auto-fulfillment, block-range materialization (§1.5),
Telegram confirmation.

### 3.3 Reversal — paid → failed/canceled

The `updated` hook refunds, in order: spent prana (`refundPranaIfSpent`,
idempotent — `prana_spent` refunded via the unique transaction key), applied referral
credit (`refundReferralCreditIfApplied`, zeroed after refund so a second
failed→cancelled hop cannot double-refund). If the payment *was* paid:
promo slot release (flag `checkout_promo_reservations`), deposit-credit restoration
(flag `checkout_deposit_reversal`; LIFO under row locks, keeps the
`deposit_credit_applied` marker as audit trail), access-only sibling removal (§1.5),
and group-access reconciliation — the group is detached **only** when no other
access-granting paid payment of this user still needs it (including via other courses
sharing the group).

### 3.4 Checkout — where the price is computed

`POST /payment/create` ([PaymentController::createPayment()](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaymentController.php)):
guest resolution is **anti-takeover** (a guest checkout on an existing email is
refused — otherwise the first-payment welcome email would regenerate the owner's
password); the DB write happens in one transaction *before* the Tochka HTTP call
(a slow bank must not hold row locks); a zero-total order is marked paid immediately
and never reaches the bank. Five default-OFF hardening flags (inactive-tariff guard,
referral-wallet lock, deposit reversal, promo reservations, integrity safe-repairs)
and the three H1396 session-lapse flags are catalogued in §8.

---

## 4. The stacking order

**Canonical order of price reductions** (fixed by
`Tariff::calculateFinalPriceForUser()` + `createPayment()`; verified by
`loyalty_deposit_and_upgrade_credit_stack_in_order` and the §2 matrix):

```
base price
  1. discount: personal StudentDiscount  OR  loyalty percent   (never both)
  2. − upgrade credit          (containment, §2)
  3. − prepaid credit          (deposit/trial remainders, capped at what is left)
  4. − promo code              (percent or fixed)
  5. − prana                   (≤30% of the running total, min 1 ₽ left, 10 prana = 1 ₽)
  6. − referral credit         (auto-applied down to 0 ₽)
  = payments.amount  (0 ⇒ instant paid, no bank)
```

### 4.1 Discount (step 1)

A personal `StudentDiscount` (percent or fixed; block-specific row beats the
course-wide row) **replaces** the loyalty discount — they never stack. Loyalty
percent comes from `MarketingSetting` thresholds over the count of *distinct courses*
with real paid payments in the trailing year (`deposit`/`trial`/`Расход`/
`salary_payout`/conditional/0 ₽ excluded — they must not inflate the wholesale
count). An `is_unreliable` student gets 0 % loyalty until the manager clears the flag
("прана сгорает" policy). The applied discount is denormalized onto the payment
(`discount_percent` / `discount_amount`) for the admin badge and sheet export.

### 4.2 Upgrade credit (step 2) — §2.

### 4.3 Prepaid credit (step 3)

Unconsumed `deposit` + `trial` remainders (`amount − consumed_amount`) on the same
course. Only the amount actually needed is applied and recorded in
`payments.deposit_credit_applied`; on payment success exactly that much is consumed
from the deposit rows FIFO (partial consumption stamps `consumed_amount`, the
remainder survives for the next purchase — H071 #10). While an unconsumed deposit
exists, a **second pending order on the same course is refused** (H071 #2: the same
deposit must not credit two open orders at once).

### 4.4 Promo code (step 4)

Validity = active + calendar + capacity + course match + once-per-user (paid
payments only — an abandoned pending does not burn the code). `used_count`
increments **on payment**, not on checkout. Capacity can be hardened with timed
reservations (`checkout_promo_reservations`, default OFF): a live pending holds a
slot until the Tochka link TTL (30 min) + webhook buffer (10 min) expires. A carried
promo that lapsed between page load and submit triggers an **explicit price
confirmation** — never a silent full-price charge (H1396 §1, MG ruling 20-07-2026).

### 4.5 Prana (step 5)

Config [prana.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/prana.php):
rate **10 prana = 1 ₽**, cap **30 %** of the price after steps 1–4, and the order can
never be zeroed by prana (min 1 ₽ remains). The server recomputes the max and snaps
the spend to a rate multiple — the client-submitted value is never trusted. Spending
locks the wallet row; failure paths refund via the idempotent transaction key.
A successful real payment *awards* prana (`payment_success`, 50 by default;
0 ₽ orders award nothing).

### 4.6 Referral credit (step 6)

Real money credit (default 500 ₽ per referred first purchase,
[referral.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/referral.php)),
auto-applied from the buyer's wallet down to a 0 ₽ total if it covers everything.
Debited in the checkout transaction; reversal refunds it (§3.3). The row-lock flag
`checkout_referral_credit_lock` (default OFF) serializes concurrent checkouts on one
wallet.

### 4.7 Worked example (fictional numbers)

Block price 4 800 ₽; student already paid the first half 2 500 ₽ (of which 2 000 ₽
was covered by a deposit); loyalty 10 %; 300 prana on the wallet.

```
4 800.00
  −480.00   loyalty 10 %                        → 4 320.00
−2 500.00   upgrade credit (2 500 = 500 cash + 2 000 deposit part)
                                                → 1 820.00
     −0.00  no unconsumed deposit left
     −0.00  no promo
   −30.00   prana: cap = 30 % × 1 820 = 546 ₽; wallet 300 prana = 30 ₽ → spend 300
                                                → 1 790.00
     −0.00  no referral credit
payments.amount = 1 790.00
```

---

## 5. The bank webhook and the H1359 idempotency ledger

Route: `POST /api/webhooks/tochka`
([routes/api.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/api.php)) —
**the single automatic "paid → access" trigger in production**.

### 5.1 Trust chain

The body is a **JWT signed RS256 by Tochka**; the public JWK is pinned in the
controller (overridable via `services.tochka.webhook_public_key` — used by tests to
substitute a throwaway pair). Invalid signature ⇒ 401, no state change. The order is
identified by `Заказ №(\d+)` parsed from `purpose`. The bank's `status` maps:
success ∈ `{paid, authorized, APPROVED, AUTHORIZED, captured, completed}`, failure ∈
`{rejected, canceled, failed}`. `paymentType` (documented values `card`/`sbp`/
`dolyame`) is normalized into `payments.payment_method` for unit-economics; an
unrecognized value is logged raw (no PII) and stored as NULL.

### 5.2 The ledger — `payment_webhook_events`

**Append-only, written for every signature-valid delivery regardless of any flag**
(purely additive; `UPDATED_AT = null`, rows are never edited). One row per unique
delivery body: `event_hash = sha256(raw JWT)` with a unique index — the true guard
against replay races. Columns: provider, `payment_id` (nullable — unmatched
deliveries are journaled too), `bank_status`, `reported_amount`, `decision`:

| `decision` | Meaning |
|---|---|
| `applied` | delivery applied — status/access updated as normal |
| `duplicate` | **reserved, never persisted**: a repeat delivery is answered 200 and leaves **no** new row (guard ON short-circuits before the insert; guard OFF `firstOrCreate` no-ops on the existing row) — the original delivery's row is the record, the unique `event_hash` index the backstop |
| `rejected_resurrection` | success for a paid-then-reversed payment — refused |
| `rejected_amount_mismatch` | bank amount diverges from the order beyond tolerance — refused |
| `unmatched` | valid signature but no local payment matched |

### 5.3 The three refusal guards — flag `tochka_webhook_guard` (default OFF)

With the flag OFF the ledger still records everything but **behavior is unchanged**
(proven by the flag-OFF parity tests, including the deliberately-vulnerable
`flag_off_paid_then_failed_then_replay_still_resurrects`). With
`TOCHKA_WEBHOOK_GUARD=true`:

- **(a) Duplicate delivery** (same `event_hash`) → 200 no-op before the transaction;
  the unique index remains the race-proof backstop.
- **(b) Resurrection** — a success JWT for a payment that is *not currently paid* but
  **has a prior paid transition in its audit trail** (`hasPriorPaidTransition()`,
  reading `payment_audits`) is refused: a replayed/late success must not resurrect
  access, deposit consumption, the promo slot or the referral reward. **The guard is
  only as good as the audit trail — defect C3, §9.**
- **(c) Amount mismatch** — if the payload carries an amount and
  `|bank − payments.amount| > checkout.webhook_amount_tolerance` (default 1.00 ₽,
  `CHECKOUT_WEBHOOK_AMOUNT_TOLERANCE`), access is refused. Amount extraction is
  deliberately **fail-open**: no recognizable amount field ⇒ the check is skipped
  (limitation C1, §9).

All webhook processing for a matched payment runs under `lockForUpdate()` in one
transaction — parallel deliveries for one order serialize, so the paid path (groups +
welcome email) cannot fire twice. The whole surface is covered by the 13 tests of
[TochkaWebhookTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Webhooks/TochkaWebhookTest.php)
(47 assertions, green in this worktree — metadoc spot-run #1) — **the template every
new money test should follow** (flag-OFF parity proof + flag-ON behavior).

### 5.4 Operator surface

`php artisan payments:audit-checkout-integrity` (read-only without flags) prints,
among other invariants, the **"Rejected webhook deliveries"** block — operators see
resurrections and amount mismatches without DB access. §11 (RU runbook) tells the
operator what to do with each.

---

## 6. Receivables vs conversion — the two finance control loops

Two deliberately separate loops watch the order book, both `.env`-tunable without
deploys and both surfaced in the admin finance area (gates: `RoleGate::finance()`):

- **Receivables** ([config/receivables.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/receivables.php),
  [ReceivablesGovernanceService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/ReceivablesGovernanceService.php)) —
  debt that *already exists*: unfulfilled payment promises (installments singled out
  via `installment_group_id`). Threshold: receivables must stay under a share
  (default **0.5**) of trailing-30-day revenue — the anti-case is the online school
  that accumulated receivables ≈ one month's revenue in 3 weeks and hit a cash gap.
  A promise unpaid **14 days** past its date counts illiquid. Hard installment
  limits: ≤30 % of sales, ≤50 concurrent plans, ≤3 months per plan. Traffic light at
  80 % of threshold.
- **Conversion** ([config/conversion.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/conversion.php),
  [OrderPaymentConversionService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reports/OrderPaymentConversionService.php)) —
  orders that *never became money*: real course orders (deposit/trial/bookkeeping
  excluded) that reached `paid`. Target ≥63 %, red <50 %; a pending order older than
  **3 days** lands on the "недожатые" work list — the early signal *before* it ever
  becomes receivables.

Downstream of profit: **profit funds**
([config/profit_funds.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/profit_funds.php),
[ProfitFundsService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/ProfitFundsService.php))
distributes *positive accrual-basis monthly profit* into dividends/reserve/team/
company (default 60/20/10/10; mis-summing `.env` shares are normalized loudly, never
silently), with a cash-coverage traffic light on the reserve; **investment model**
([config/investment.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/investment.php),
[InvestmentModelService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/InvestmentModelService.php))
gates large spends by NPV/IRR/payback (default discount rate 20 %/yr, horizon 5 y,
acceptable payback 4 y).

---

## 7. Business-case rationale

Why the money core looks like this:

- **Guards ship default-OFF.** Every behavioral money change lands behind a
  `config/features.php` flag with a flag-OFF parity test. The deploy is prod-inert;
  the findir consciously flips each flag (with `php artisan config:clear`) after
  review — see the deploy queue
  ([DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)
  rows №38/№44). This is the standing money-core PR discipline
  ([AGENTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AGENTS.md)):
  one fix per PR, regression test, no auto-merge, human review.
- **Containment credit exists to make upgrading safe** for the student: two halves
  cost exactly a whole block; a half then the block costs exactly the block. No
  double-charging, no lost deposits (H071 #9/#10) — upgrade friction is a sales
  killer for a block-structured course.
- **The webhook ledger exists because the bank's word grants access.** Before H1359
  there was no idempotency by delivery body and no trace of refused deliveries; a
  replayed success could silently resurrect a refunded student's access, deposit
  consumption, promo slot and referral reward. The ledger is the flight recorder;
  the guards are the interlocks.
- **Receivables/conversion thresholds encode borrowed case-study numbers** (the
  0.5-of-revenue receivables cap, the 63 % conversion target) — they are starting
  points for the findir to tune in `.env`, not laws.

---

## 7b. Semi-manual payment paths (outside Tochka) — H2017

These create a normal `Payment` row with `status=pending` and a non-null `provider`.
Access opens only when an admin flips status to `paid` (same `Payment::booted()` path
as a bank webhook success). They are **never** reaped by
`payments:expire-stale-checkouts` (`Payment::MANUAL_CLAIM_PROVIDERS`).

| Path | `provider` | Student surface | Admin action | Prod (31-07-2026) |
|---|---|---|---|---|
| PayPal diaspora | `paypal` | Checkout CTA → `/paypal/{tariff}`; claim_meta: from + date + amount | Filter «Заявки PayPal» → «Подтвердить PayPal» | **ON** — money to `gasyoun@gmail.com` / paypal.me/gasyoun |
| Company invoice | `invoice` | CTA → `/invoice/{tariff}` → print `/invoices/{id}/print` | Filter «Счета юрлиц» → «Подтвердить счет» | **ON** — `BILLING_*` from Tochka customer + site footer |

Config: `services.paypal.*` · `billing.company_invoice.enabled` · `billing.legal.*`.
Ops detail: [TOCHKA_PAYMENT_METHODS_AUDIT_2026-07-31.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TOCHKA_PAYMENT_METHODS_AUDIT_2026-07-31.md).
Tochka card/SBP acquiring is unchanged (§5 webhook).

---

## 8. Config-gate reference (money-relevant)

All flags read through `config('features.*')` from
[config/features.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php);
after any `.env` change on a config-cached deployment run `php artisan config:clear`.

| Flag (`features.*`) | `.env` key | Default | Gates |
|---|---|---|---|
| `full_course_block_credit` | `FULL_COURSE_BLOCK_CREDIT` | OFF | crediting paid blocks into a `full` purchase (§2); half→block credit is always on |
| `tochka_webhook_guard` | `TOCHKA_WEBHOOK_GUARD` | OFF | the three webhook refusals (§5.3); ledger writes are unconditional |
| `checkout_inactive_tariff_guard` | `CHECKOUT_INACTIVE_TARIFF_GUARD` | OFF | 404 on checkout of a deactivated tariff before any row/bank link is created |
| `checkout_referral_credit_lock` | `CHECKOUT_REFERRAL_CREDIT_LOCK` | OFF | row-lock + DB-authoritative referral wallet in checkout |
| `checkout_deposit_reversal` | `CHECKOUT_DEPOSIT_REVERSAL` | OFF | LIFO restoration of `deposit_credit_applied` on paid→failed/canceled |
| `checkout_promo_reservations` | `CHECKOUT_PROMO_RESERVATIONS` | OFF | timed promo capacity slots (link TTL 30 min + 10 min webhook buffer); paid-reversal releases `used_count` |
| `checkout_integrity_safe_repairs` | `CHECKOUT_INTEGRITY_SAFE_REPAIRS` | OFF | allows `payments:audit-checkout-integrity --apply-safe` (promo counters only) |
| `checkout_stale_order_expiry` | `CHECKOUT_STALE_ORDER_EXPIRY` | OFF | the stale-checkout reaper `payments:expire-stale-checkouts --apply` (dry-run works without the flag) |
| `checkout_promo_survives_session` | `CHECKOUT_PROMO_SURVIVES_SESSION` | OFF | H1396 §1: promo carried in a hidden field, re-resolved authoritatively; lapsed ⇒ explicit price confirmation |
| `checkout_session_lapse_relogin` | `CHECKOUT_SESSION_LAPSE_RELOGIN` | OFF | H1396 §2: lapsed-session submit → login with return to checkout |
| `checkout_signed_return_url` | `CHECKOUT_SIGNED_RETURN_URL` | OFF | H1396 §3: signed bank return URLs carrying the payment id |

Non-flag money knobs: `checkout.webhook_amount_tolerance` (1.00 ₽ default, §5.3c),
`checkout.legacy_pending_days` (30) / `checkout.stale_pending_minutes` (180, reserved)
in [config/checkout.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/checkout.php);
prana rate/cap/rewards in [config/prana.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/prana.php);
`referral.credit_amount` (500 ₽); the §6 receivables/conversion/profit-funds/
investment threshold families. Loyalty thresholds and several operational toggles
live in the DB (`MarketingSetting`, cached singleton), not in config.

---

## 9. Known limitations and the defect register (H1405 claim-verify)

Three candidates were claim-verified for this manual (Wave 2 of the org deep-manuals
programme). Verdicts:

### C1 — webhook amount-guard field coverage: **documented limitation, @DECIDE spike**

`WebhookController::extractReportedAmount()` probes
`['amount','sum','paymentAmount','totalAmount','operationAmount']`; absence ⇒ the
amount check (§5.3c) is silently skipped (fail-open by design — the guard must not
depend on live bank credentials). The official Tochka reference for the
`acquiringInternetPayment` webhook (checked 25-07-2026,
[developers.tochka.com](https://developers.tochka.com/docs/tochka-api/opisanie-metodov/vebhuki/acquiringInternetPayment))
documents the field as **`amount`** — the *first* probe — with a decimal-ruble style
example (`"0.33"`), so the probe list is doc-consistent. What remains unconfirmed is
the **shape on a real production payload** (nesting, kopeck variants). Until one
refused or applied delivery with a non-null `reported_amount` is observed in the
ledger, treat guard (c) as *best-effort*. `@DECIDE` spike: after the flag is ON in
prod, pull one real ledger row and confirm `reported_amount` matches the order —
then this limitation closes. **Do not "fix" the probe list blind.**

### C2 — upgrade-credit refund netting misses form-created refunds: **CONFIRMED**

The block branch of `Tariff::upgradeRefundsForUser()` only subtracts `Расход` rows
whose `start_block..end_block` range covers the purchased block
(`whereNotNull('start_block')`). But the canonical refund procedure produces rows
that can never match:

- the admin form ([PaymentResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/PaymentResource.php))
  **auto-nulls `start_block`/`end_block` the moment `Расход` is selected**;
- the accountant cookbook ("Провести возврат",
  [accountant-guide.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/accountant-guide.md) §6)
  instructs only "тариф Расход, сумма с минусом" — block fields are never mentioned;
- the refund does **not** cancel the original payment (H352 model: revenue is
  truncated via `refund_of_payment_id`, the original row stays `paid`).

Consequence (fictional numbers): a student buys the 2 500 ₽ half, is refunded
2 500 ₽, then buys the 4 800 ₽ whole block — and still pays 4 800 − 2 500 = 2 300 ₽,
because the refunded half still counts as credit while its refund row is invisible
to the netting. The school silently loses the refunded amount a second time. The
existing green netting tests construct their `Расход` rows *with* the block range —
they test the logic, not the data the form actually produces. The `full` branch is
unaffected (it subtracts all course refunds unfiltered).

**Fix (this wave, flag-gated):** `features.upgrade_credit_refund_link` (default
OFF) — when ON, the block branch additionally subtracts `Расход` rows linked via
`refund_of_payment_id` to a payment whose tariff is in the purchased tariff's
containment set. Residual: a refund created with *neither* a block range *nor* a
`refund_of_payment_id` link remains invisible — the runbook (§11.4) therefore tells
the operator to always fill «Возврат за платёж №…» when refunding.

### C3 — resurrection guard depends on the audit trail: **fix merged, pending prod migrate/backfill**

`hasPriorPaidTransition()` (guard §5.3b) reads `payment_audits`. Verified bounds of
the blind spot:

- `PaymentAuditObserver` has existed since **08-06-2026** (migration
  `2026_06_08_120000_create_payment_audits_table.php`). A payment that was paid *and
  reversed* entirely before that date has no audit rows ⇒ the guard cannot see its
  prior paid state ⇒ a differently-bodied success JWT for it would be applied even
  with the flag ON (the `event_hash` dedup only blocks byte-identical replays).
- Status *transitions* all go through Eloquent `update()` (events fire; verified by
  sweep — no `updateQuietly`/mass-update touches `payments.status`). But several
  paths **create payments already-paid via `withoutEvents`**, so they get no
  `created` audit snapshot: silent promise fulfillment
  ([PromiseFulfillment::fulfil()](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/PromiseFulfillment.php)
  with `silent=true` — **real money**), access-only siblings (§1.5), conditional
  grants (§1.6). Combined with the next point, such a payment stays invisible to
  the guard even post-observer.
- `hasPriorPaidTransition()` inspects only the **new** value of each audit diff
  (`$status[1]`). A normal lifecycle leaves a `pending→paid` row (new = paid ⇒
  detected). A *silently-created* paid payment that is later reversed leaves only a
  `paid→failed` diff — whose new value is `failed` — so its prior paid state is
  **not** detected. Cheap no-schema hardening (also inspecting the *old* value,
  `$status[0]`) is folded into the queued fix below.

**Fix (H1645, merged):** a nullable `payments.first_paid_at` timestamp, stamped once
(`fireOnPaid`, `updateQuietly` so no observer recurses) on the first transition into
`PAID_STATUSES` — including create-as-paid — and written directly into the create
payloads of the three `withoutEvents` paths (silent `PromiseFulfillment::fulfil()`,
`BlockAccessMaterializer`, `ConditionalAccessGranter`) that fire no `created` event at
all. `hasPriorPaidTransition()` now checks `first_paid_at !== null` first, falling back
to the audit walk while the column is unbackfilled; the audit walk itself was hardened
to inspect **both** sides of each status diff (`$status[0]` and `$status[1]`), so a
`paid→failed` row now proves prior-paid on its own (previously only the new value was
checked). Both changes make detection a strict superset of the old behavior, so no new
feature flag was needed — the resurrection guard's existing `TOCHKA_WEBHOOK_GUARD` flag
still gates the only behavior change. A one-shot idempotent, dry-run-by-default backfill
command (`php artisan payments:backfill-first-paid-at [--apply]`) fills the column for
existing rows from the earliest audit evidence, or from `created_at` when a payment is
currently paid with zero audit trail. **The genuinely unrecoverable residue stays
documented, not silently dropped:** a payment paid *and* reversed entirely before
08-06-2026, with zero rows in `payment_audits`, has no evidence anywhere and stays
`first_paid_at = null` forever — the command prints its count (not rows) on every run.
The migration and backfill command are **not** run against prod by an agent (D16) — see
[DEPLOY_QUEUE.md row 62](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md).

---

## 10. [RU] Как устроен доступ и деньги — глава для финдира и бухгалтера

Все суммы ниже — вымышленные.

### 10.1 Ключи доступа

Каждая оплата несёт **один ключ** в поле «Тариф»: `весь курс` (full), `блок N`,
`половина блока` (block_N_h1/h2). Урок открывается, если у студента есть хотя бы
один подходящий ключ: `full` открывает всё, ключ блока — уроки блока, ключ
половины — только уроки этой половины. Бронь (`deposit`), пробное (`trial`),
расход/возврат («Расход») и выплата ЗП (`salary_payout`) — **не** ключи доступа:
уроков они не открывают и в скидках/выручке не участвуют.

Оплата диапазона блоков (например, «блоки 3–6») хранит ключ `block_3`, а на блоки
4–6 система сама дорисовывает **нулевые строки-спутники** — они открывают уроки, но
в финансовых отчётах не участвуют (сумма 0, служебный ID транзакции). Удалять или
«чистить» их вручную нельзя: доступ студента закроется.

### 10.2 Порядок скидок при покупке (фиксированный)

1. **Скидка**: персональная (если назначена) **вместо** накопительной лояльности —
   они не складываются.
2. **Зачёт докупки**: full засчитывает оплаченные блоки и половины (сейчас за
   выключенным флагом), целый блок — свои половины (всегда). Считается «кэш +
   зачтённая в ту покупку предоплата»: половина, оплаченная бронью, при докупке
   блока засчитывается полностью.
3. **Зачёт предоплаты**: неизрасходованные бронь и пробное по этому курсу, ровно на
   сумму остатка; излишек брони не сгорает и ждёт следующую покупку.
4. **Промокод** (процент или фиксированная сумма). Использование засчитывается
   только по факту оплаты.
5. **Прана**: 10 праны = 1 ₽, не больше 30 % цены, заказ не обнуляется праной
   (минимум 1 ₽ к оплате).
6. **Реферальный кредит**: настоящие деньги студента, списываются автоматически до
   нуля.

Пример: блок 4 800 ₽, куплена половина за 2 500 ₽ (из них 2 000 ₽ бронью), лояльность
10 %, на кошельке 300 праны: 4 800 → 4 320 (скидка) → 1 820 (зачёт половины) →
1 790 ₽ к оплате (300 праны = 30 ₽).

### 10.3 Что происходит после оплаты

Студент попадает в группы курса, получает письмо-приветствие (пароль генерируется
**один раз за всю жизнь аккаунта**), чек-письмо на каждую оплату, прану за покупку;
гасится бронь; промокод помечается использованным. При отмене/возврате оплаты
система сама возвращает прану, реферальный кредит, промо-слот (за флагом), бронь
(за флагом) и закрывает доступ, **если** других оплат, дающих этот доступ, нет.

### 10.4 Три защиты банковского вебхука (флаг `TOCHKA_WEBHOOK_GUARD`)

Банковское уведомление — единственный автоматический способ «оплачено → доступ».
Журнал `payment_webhook_events` пишется **всегда**; при включённом флаге система
отказывает трём опасным случаям: повтор той же доставки; «воскрешение» (успех по
платежу, который был оплачен и затем отменён/возвращён); расхождение суммы банка с
суммой заказа больше допуска (по умолчанию 1 ₽). Отказ **не** трогает платёж — он
остаётся как был, а строка с решением появляется в журнале и в команде
`php artisan payments:audit-checkout-integrity` (блок «Rejected webhook deliveries»).

---

## 11. [RU] Runbook — сбои и восстановление

Диагностическая команда для всех сценариев (только чтение, безопасна всегда):

```
php artisan payments:audit-checkout-integrity
```

### 11.1 «Оплата прошла, а доступа нет»

1. Найти платёж в «Финансах»: статус должен быть «Оплачено». Если `pending` —
   вебхук не дошёл/отказан: проверить блок «Rejected webhook deliveries» команды
   выше и журнал вебхуков платежа.
2. Отказ `rejected_amount_mismatch`: сверить сумму заказа с реально списанной в
   личном кабинете Точки. Суммы совпадают, а отказ есть — это ложное срабатывание
   допуска: эскалировать инженеру (допуск `CHECKOUT_WEBHOOK_AMOUNT_TOLERANCE`),
   доступ выдать вручную — перевести платёж в «Оплачено» в админке (это штатный
   путь: сработает та же выдача групп).
3. Отказ `rejected_resurrection`: система считает, что платёж уже был оплачен и
   возвращён. Если возврат реальный — отказ **корректен**, доступ не положен. Если
   студент легитимно оплатил заново — оформить **новый** платёж (не воскрешать
   старый), деньги сверить с банком.
4. Статус «Оплачено», а уроки закрыты: проверить, что у курса привязаны группы
   (админка курса → «Группы») и что ключ тарифа соответствует блоку урока (§10.1).

### 11.2 «Студент говорит, что платил дважды»

Повтор доставки НЕ оставляет второй строки в журнале (первая строка `applied` и
есть запись о ней) — значит: один платёж + одна строка `applied` = деньги были
списаны один раз, паника ложная. Два разных платежа (два ряда в «Финансах») —
действительно двойная оплата: возврат через штатную процедуру §11.4.

### 11.3 Завис pending / брошенные заказы

Брошенный pending держит зачтённые прану/кредит/бронь. Отчёт без записи:
`php artisan payments:expire-stale-checkouts` (dry-run). Автоматическое провал-и-
вернуть — за флагом `CHECKOUT_STALE_ORDER_EXPIRY`; вручную — перевести платёж в
«Отменено»: наблюдатель сам вернёт прану/кредит (и бронь — при включённом
`CHECKOUT_DEPOSIT_REVERSAL`).

### 11.4 Возврат денег студенту — правильная процедура

1. «Финансы» → «Создать» → тариф «💸 Системный расход / Возврат», сумма **с
   минусом**.
2. **Обязательно** заполнить «Возврат за платёж №…» (`refund_of_payment_id`) —
   иначе возврат не будет виден зачёту докупки (§9 C2) и признанию выручки.
3. Если доступ тоже отзывается — перевести исходный платёж в «Отменено» (система
   снимет группы, если других оплат нет). Возврат-строка сама по себе доступ **не**
   закрывает.
4. Не редактировать историческую строку оплаты и не менять её сумму: корректировки —
   только отдельными строками.

### 11.5 Прана/кредит «пропали»

Смотреть историю: прана — в транзакциях праны (каждое списание/возврат — отдельная
строка с причиной), кредит — `referral_credit_applied` на платеже. Возврат праны и
кредита при срыве оплаты идемпотентен: повторная отмена не удвоит возврат.
Отрицательный кошелёк в отчёте команды — стоп-сигнал: только ручной разбор с
банком, автоматических правок нет.

### 11.6 Когда звать инженера, а не чинить руками

Любое расхождение «Promo counter mismatches» (чинится только
`--apply-safe` при включённом `CHECKOUT_INTEGRITY_SAFE_REPAIRS`); ложные отказы
вебхука; массовые `unmatched`-строки (сломан парсинг «Заказ №»); всё из §9.

---

_Dr. Mārcis Gasūns_
