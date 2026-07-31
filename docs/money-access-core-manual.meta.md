# money-access-core-manual.meta.md — metadoc for `money-access-core-manual`

_Created: 25-07-2026 · Last updated: 31-07-2026_

Companion record for
[money-access-core-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-access-core-manual.md) —
purpose, provenance, verification evidence, backlog and limitations, without
restating the manual's content.

## Staleness block

LAST_VERIFIED: 31-07-2026
VERIFIED_BY: Grok 4.5 (grok-4.5), H2017 docs pass (manual §7b only; full H1405 suite not re-run)
COMMANDS_SPOT_RUN: 4 (H1405) + prod smoke PayPal/invoice 200

## Subject

- **Document:** [money-access-core-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-access-core-manual.md)
- **Purpose:** the deep systems manual for the Systema money/access core — access-key
  algebra, containment upgrade-credit, payment lifecycle, the H1359 webhook ledger
  and its three guards, the discount/prana/deposit/loyalty stacking order, the
  receivables-vs-conversion loops, the money config-gate map, plus a `[RU]`
  findir/accountant mechanism chapter and a `[RU]` failure/recovery runbook.
- **Audience:** engineers touching the money path (EN core, §§1–9); findir /
  accountant / operator (RU chapters, §§10–11).
- **Contract:** code wins over the manual; all numbers are fictional placeholders
  (public repository); every executable claim was run during authoring and is
  recorded below.

## Provenance

- Authored 25-07-2026 by Fable 5 (`claude-fable-5`) executing
  [H1405](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1405-Fable_Systema-Sanscriticum_deep-manual-money-access-core-wave2_20.07.26.md)
  (Wave 2 of the org deep-manuals programme,
  [PLAN index](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_ORG_DEEP_MANUALS_FABLE_WAVES_2026H2.md)),
  in a fresh worktree off `origin/main` (ddac4f5) with `composer install` run in the
  worktree (the canonical tree cannot run the suite).

## Verification block — commands actually run (25-07-2026, php 8.3.32, PHPUnit 11.5.56)

1. **Webhook surface** — `php artisan test --filter=TochkaWebhookTest` →
   **13 passed (47 assertions)**. Covers RS256 rejection, no-order no-op, paid+
   idempotency, `paymentType` persistence (card/sbp/dolyame), flag-OFF parity
   (incl. the deliberately-vulnerable resurrection replay), flag-ON refusals
   (duplicate / resurrection / amount mismatch), applied-with-matching-amount, and
   flag-OFF ledger writes.
2. **Key algebra + containment credit + stacking** —
   `php artisan test --filter="HalfBlockPurchaseTest|DepositPartialConsumptionTest"`
   → **24 passed (80 assertions)**. Covers every §2 property cited in the manual by
   test name, the §4 stacking-order test, and the refund-netting tests (which
   construct `Расход` rows *with* block ranges — see defect C2).
3. **Access-key examples (attribute-level, live code)** — `php artisan tinker
   --execute='…'` over `Tariff::accessKey()`, `Lesson::unlockingKeys()`,
   `Lesson::isUnlockedBy()`. Recorded output:

   ```
   full=>full
   vip=>full
   bundle(no range)=>full
   bundle(3..6)=>block_3
   block 2=>block_2
   block 2 h1=>block_2_h1
   lesson b2h1 keys=["full","block_2","block_2_h1"]
   lesson b3 keys=["full","block_3"]
   b2h1 unlocked by [block_2]? true
   b2h1 unlocked by [block_2_h2]? false
   b3 unlocked by [block_3_h1]? false
   preview unlocked by []? true
   ```

   Matches the §1 tables verbatim.
4. **C1 documentation check** — official Tochka `acquiringInternetPayment` webhook
   reference fetched 25-07-2026
   ([developers.tochka.com](https://developers.tochka.com/docs/tochka-api/opisanie-metodov/vebhuki/acquiringInternetPayment)):
   payload field for the amount is documented as `amount` (decimal-ruble style
   example `"0.33"`), `paymentType` ∈ {card, sbp, dolyame} — consistent with
   `extractReportedAmount`'s first probe and `extractPaymentMethod`. Live-payload
   confirmation remains outstanding (manual §9 C1, `@DECIDE` spike).

Claim-verify verdicts recorded in manual §9: **C1** documented-limitation
(+`@DECIDE` spike), **C2 CONFIRMED** (fix shipped separately, flag
`features.upgrade_credit_refund_link` default-OFF, PR under money-core no-auto-merge
discipline), **C3** documented-limitation (robust fix = `payments.first_paid_at`
schema change → queued as its own handoff per programme ruling D16; no migration
authored in this wave).

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Close C1: confirm `reported_amount` on one real ledger row after `TOCHKA_WEBHOOK_GUARD` is ON in prod | Turns the amount guard from best-effort into verified | open (`@DECIDE` / prod observation) |
| 2 | Close C3 durably: `payments.first_paid_at` + backfill (+ the no-schema `$status[0]` old-value check) | Removes the pre-08-06-2026 audit blind spot AND the `withoutEvents` create-as-paid blind spot (silent promise fulfillment) of the resurrection guard | fix merged (H1645); prod migrate + backfill pending, DEPLOY_QUEUE row 62 |
| 3 | Admin form: auto-fill `start_block`/`end_block` (or keep them) on `Расход` linked via `refund_of_payment_id` | Removes the C2 data-entry gap at the source, complements the flag-gated netting fix | open |
| 4 | Add a `payment_webhook_events` retention/dashboard note once volume data exists | Ledger is append-only and unbounded | open |
| 5 | Worked full-course upgrade example once `full_course_block_credit` is turned ON in prod | §4.7 covers the always-on half→block path only | open |

## Known limitations

- The manual documents the code at `origin/main` ddac4f5 (25-07-2026); money-core
  PRs after that date may outdate specific sections — the staleness detector
  ([Uprava/tools/manual_staleness.py](https://github.com/gasyoun/Uprava/blob/main/tools/manual_staleness.py))
  tracks this via the block above.
- §9 C1/C3 are open limitations by design (ruled, not overlooked).
- Loyalty thresholds and several operational toggles live in the `MarketingSetting`
  DB row — their *values* are runtime state and are deliberately not documented.

## Intended use / known misuse

- **Use** as the engineer's map of the money path, the reviewer's checklist for
  money PRs (flag fence, stacking order, guard semantics), and the operator's
  runbook (§11).
- **Do not use** as a substitute for reading the code in a money PR review, nor as
  a source of real amounts/keys (all numbers fictional), nor as authority over the
  deploy queue ([DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)
  owns activation steps).

## Maintenance / retirement

- Re-verify (bump the staleness block) whenever a PR touches the §"Sources of truth"
  file set; the money-core adversarial review cadence
  ([money-core-adversarial-review.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-core-adversarial-review.md))
  is the natural trigger.
- Retire only if the key-based access model itself is replaced; archive together
  with [finance-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/finance-manual.md).

## Revision history

| Date | Event | Model |
|---|---|---|
| 25-07-2026 | Manual + metadoc authored; C1/C2/C3 claim-verified; 4 spot-runs recorded | Fable 5 (`claude-fable-5`), H1405 |
| 25-07-2026 | §9 C3 amended after the adversarial pass: `withoutEvents` create-as-paid paths (silent promise fulfillment) + the new-value-only audit walk make the guard blind to silently-created paid payments — "trail complete going forward" retracted | Fable 5 (`claude-fable-5`), H1405 |
| 25-07-2026 | Adversarial ledger complete: 45/47 CONFIRMED; §5.2 `duplicate` row + §11.2 RU guidance corrected (the `duplicate` decision constant is never persisted — a replay leaves no ledger row) | Fable 5 (`claude-fable-5`), H1405 |
| 26-07-2026 | §9 C3 fix merged: `payments.first_paid_at` (write-path stamp + 3 `withoutEvents` create-as-paid payloads) + old-value audit-diff hardening + backfill command; guard's `hasPriorPaidTransition()` now checks the column first, audit walk as fallback — backlog row 2 updated, prod migrate/backfill queued as DEPLOY_QUEUE row 62 | Sonnet 5 (`claude-sonnet-5`), H1645 |
| 31-07-2026 | §7b semi-manual paths (PayPal + company invoice) documented after H2017 merge + prod enable; Tochka audit + accountant guide + copy docs updated | Grok 4.5 (`grok-4.5`), H2017 |

_Dr. Mārcis Gasūns_
