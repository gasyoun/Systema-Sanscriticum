# money-access-core-manual.meta.md — metadoc for `money-access-core-manual`

_Created: 25-07-2026 · Last updated: 18-08-2026_

Companion record for
[money-access-core-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-access-core-manual.md) —
purpose, provenance, verification evidence, backlog and limitations, without
restating the manual's content.

## Staleness block
LAST_VERIFIED: 18-08-2026
VERIFIED_BY: Sonnet 5 (claude-sonnet-5) — re-ran all 3 recorded verification commands
(TochkaWebhookTest, HalfBlockPurchaseTest+DepositPartialConsumptionTest, the
access-key/unlockingKeys/isUnlockedBy tinker script) fresh against a full
`composer install` in the canonical checkout (unshallowed; no separate worktree
needed), and read every commit since 01-08-2026 touching the manual's "Sources of
truth" files. Corrected the manual where code had moved (see revision history);
where the diffs were feature work outside the manual's scope (gamification prana
decay, membership tier storefront internals beyond the access-key surface), left
them undocumented on purpose — this manual is the money/access core, not every
file it lists in full.
COMMANDS_SPOT_RUN: 3

> **History: LAST_VERIFIED deliberately NOT bumped on 16-08-2026 (H2886).** That pass
> added §1.7 (club membership as a second key source) and verified only what it
> wrote — the club/entitlement paths, via `tests/Feature/Membership` +
> `tests/Feature/Cabinet`, 104 passed / 326 assertions. It did **not** re-run this
> manual's own command suite, so the staleness alarm (371 commits vs a 293
> threshold) stayed true and kept firing. Bumping the date there would have
> silenced an alarm nobody resolved — the failure mode
> [Uprava FINDINGS §396](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md)
> records, where a repair is recorded as done because a neighbouring gate went
> quiet. This 18-08-2026 pass is the first since then to re-run the full recorded
> command suite, so the bump above is earned, not inherited.

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

## Verification block — 18-08-2026 re-verification (php 8.4.19, PHPUnit 11.5.56, Laravel 13.24.0)

Staleness detector fired at 484 commits since `LAST_VERIFIED: 01-08-2026` (threshold
~300). Canonical checkout arrived shallow (61 commits) — unshallowed via
`git fetch --unshallow` before any log work, since a shallow history under-counts
drift and would have made the diff below silently incomplete. `composer install`
(no `vendor/` present) needed `--ignore-platform-req=php` — the sandbox ships PHP
8.4.19 only, no 8.3 binary, and `composer.json` pins `"php": "^8.3"` while
`composer.lock`'s `platform.php` is `8.3.0`; `platform-check: false` is already set
in `composer.json` (per this repo's CLAUDE.md worktree note), so the mismatch is
cosmetic, not a real incompatibility, and the run below is on the exact locked
dependency graph (`composer.lock` untouched — `git status` clean on it before and
after). No separate worktree was needed: the canonical checkout had no uncommitted
state to protect.

1. **Commit sweep.** `git log --since="2026-08-01 00:00:00" --until="2026-08-19
   00:00:00" -- <sources-of-truth files>` (Payment.php, Tariff.php, Lesson.php,
   PaymentController.php, WebhookController.php, PaymentWebhookEvent.php,
   BlockAccessMaterializer.php, PranaService.php, ReferralService.php,
   AuditCheckoutIntegrity.php, plus the §8 config files) — narrowed from the raw
   `features.php`-touching commit list (most of which are unrelated feature flags
   for Hindi/Grammar Lab/CRM/etc. that happen to append a new key to the same
   file) to the commits that actually touch code this manual describes. Every
   substantive hit was read via `git show`.
2. **`php artisan test --filter=TochkaWebhookTest`** → **30 passed (112
   assertions)**, up from 13/47 at authoring — H2337 (settlement status matrix) and
   H2304 (missing-groups fail-closed) added coverage; no failures, no regressions
   in the properties the manual already claimed.
3. **`php artisan test --filter="HalfBlockPurchaseTest|DepositPartialConsumptionTest"`**
   → **24 passed (80 assertions)** — byte-identical to the 25-07-2026 count. §2's
   containment-credit algebra is untouched since authoring; confirmed, not assumed.
4. **Access-key examples (tinker, live code)** — same script as 25-07-2026 (§1
   tables), re-run against current `Tariff::accessKey()` / `Lesson::unlockingKeys()`
   / `Lesson::isUnlockedBy()`. Output byte-identical to the 25-07-2026 recording in
   the block above — reproduced here to confirm, not duplicated as a new numbered
   command.

**Drift found and corrected in the manual** (all in money-core files, all shipped
code — none of this was "in progress" or speculative):

- §5.2 ledger `decision` table was missing two values that exist in
  `PaymentWebhookEvent::REJECTED_DECISIONS` and are live in prod: `hold_not_captured`
  (H2085/H2337, shipped 01–07-08-2026) and `rejected_charge` (H2304, PayPal
  Subscriptions structural/amount rejection, shipped 06-08-2026, dark behind
  `features.paypal_subscriptions`). Added both rows.
- §5.3's header said `tochka_webhook_guard` defaults **OFF** — it has defaulted
  **ON** since 01-08-2026 (§8's own table already had this right; §5.3 was never
  updated to match — an internal inconsistency, not new drift). Fixed the header
  and reworded the flag-OFF/flag-ON framing to match reality.
- §5.3 was missing the **unconditional** (not flag-gated) fail-closed check the
  Tochka webhook itself runs when a settled delivery's course has zero groups —
  it throws inside the DB transaction so the paid-status update and the ledger row
  both roll back, and Tochka's own retry of the identical JWT succeeds once an
  operator attaches a group. This exists independently of
  `features.grant_access_fail_closed` (confirmed by reading `WebhookController`
  and the `missing_groups_fail_closed_then_same_delivery_succeeds_after_repair`
  test, and cross-checked against
  [TOCHKA_SETTLEMENT_STATUS_MATRIX_2026-08-07.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TOCHKA_SETTLEMENT_STATUS_MATRIX_2026-08-07.md),
  which documents the same row). Added as a new paragraph in §5.3, a runbook step
  in §11.1, and referenced from §5.4's operator-surface description.
- §3.4 was missing the **unconditional** pre-checkout groups guard added to
  `PaymentController::createPayment()` (an "ai-wip" commit, 05-08-2026): a
  group-less course now refuses checkout with a `ValidationException` before any
  `Payment` row or Tochka HTTP call — belt to the webhook's suspenders. Added a
  bullet.
- §8's config-gate table was missing `grant_access_fail_closed`
  (`GRANT_ACCESS_FAIL_CLOSED`, default OFF, H2304 spec 2) — the flag that moves
  `Payment::grantAccess()`'s missing-groups response from log-and-return to throw,
  on the five paid routes *other* than the Tochka webhook (which is unconditional,
  see above). Added the row, plus a `paypal_subscriptions` row for completeness
  (already documented narratively in §7b, just missing from the flag table).
- §8's non-flag knobs list was missing `checkout.paypal_webhook_amount_tolerance`
  (the `rejected_charge` mirror of `checkout.webhook_amount_tolerance`, H2304
  spec 3) and `referral.referred_credit_amount` (0 ₽ default = dark, the "both-sides
  referral" addition of 02-08-2026 that can credit the *referred* student, not
  just the referrer). Added both.
- §1.1 did not mention that `Tariff::accessKey()` has a **seventh** branch:
  membership-purchase tariffs (`membership_months` set) return `club_{N}m` or,
  once tier-classified, `membership_{tier}_{N}m` (H2644/H2744). Verified by reading
  `Lesson::unlockingKeys()`/`isUnlockedBy()` (neither ever matches a `club_`/
  `membership_` prefix) and `ClubMembershipService::tierFor()` (the sole consumer,
  parsing the string back to a tier/term) — the string is billing/bookkeeping only;
  it plays no role in lesson-gating. A reader following §1.1's table pattern could
  otherwise reasonably assume it does. Added a paragraph after the §1.1 table.

**Explicitly NOT changed** — investigated and found already correct or genuinely
out of scope:

- The §5.1 status-set prose (`settled`/`hold`/`failure`) was itself edited directly
  by the H2337 PR (07-08-2026) and matches current code exactly; no revision-history
  row existed for that edit before now (folded into today's row rather than
  backfilling a separate one — the text was never wrong at any point after that PR
  landed).
- §2's containment-credit rules, §6's receivables/conversion/profit-funds/investment
  thresholds, and §9's C1/C2/C3 defect register: zero commits touched
  `config/receivables.php`, `config/profit_funds.php`, `config/conversion.php`, or
  `config/investment.php` since 01-08-2026 (checked directly), and the C1/C2/C3
  code paths (`extractReportedAmount`, `upgradeRefundsForUser`,
  `hasPriorPaidTransition`/`first_paid_at`) are unchanged since the 25-07-2026
  claim-verify pass — re-read, not re-run in full (C1's live-payload spike is still
  genuinely open, not something this pass could close).
- `app/Services/BlockAccessMaterializer.php`: zero commits since 01-08-2026 — §1.5
  unchanged.
- `app/Services/Prana/PranaService.php` gained a Season-leaderboard decay floor
  (H2553, 10-08-2026) — this is the gamification points economy (inactive-user
  balance decay, rank thresholds), a distinct system from the checkout-time prana
  *spend* this manual documents in §4.5. Left undocumented: out of this manual's
  money/access-core scope, not a gap in what it claims to cover.
- `app/Models/Tariff.php`'s H2744 three-tier membership storefront internals
  (`MembershipTier` enum ranking, `expectedMembershipPrice()`, storefront feed
  ordering) beyond the one accessKey()-format fact folded into §1.1: out of scope
  for the same reason — §1.7 already scopes membership *access* narrowly to
  `ClubEntitlement`, and the storefront/pricing-tier product surface belongs to a
  membership-specific doc, not this one.

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
| 18-08-2026 | Scheduled staleness refresh (484 commits vs ~300 threshold). Unshallowed the clone, `composer install`'d fresh, re-ran all 3 recorded verification commands (TochkaWebhookTest 30/112, up from 13/47; HalfBlockPurchaseTest+DepositPartialConsumptionTest 24/80, byte-identical; access-key tinker script, byte-identical output) and read every commit since 01-08-2026 touching the manual's sources of truth. Corrected 7 places where shipped code had drifted from the text: §5.2 ledger decisions (`hold_not_captured`, `rejected_charge`), §5.3's stale "guard defaults OFF" header + the webhook's own unconditional missing-groups fail-closed check, §3.4's unconditional pre-checkout groups guard, §8's `grant_access_fail_closed`/`paypal_subscriptions` flag rows and two non-flag knobs, §1.1's membership-tariff bookkeeping-key footnote, and §11.1's matching runbook step. §2/§6/§9's claims and `BlockAccessMaterializer.php` were re-checked and found unchanged (zero touching commits or unchanged code) — not silently re-stamped, verified. See metadoc Verification block for the full list and what was deliberately left out of scope (prana Season decay, membership storefront internals) | Sonnet 5 (`claude-sonnet-5`) |
| 16-08-2026 | H2886: new §1.7 — club membership is a **second source of access keys**, virtual and never a `Payment` row, which the manual did not mention at all while claiming to be the money/access-core reference. Records the deliberate visibility/unlocking asymmetry (`coversCourse()` vs the key) and the per-course `club_access_key` that makes a shelf entry one `block_N` instead of the whole course. Staleness at the time: 371 commits vs a 293 threshold | Opus 5 (`claude-opus-5`) |
| 01-08-2026 | H2078: COMMANDS_SPOT_RUN forced to integer (was free-text UNPARSEABLE); LAST_VERIFIED refresh; path+php presence spots | Grok 4.5 (grok-4.5) |
| 25-07-2026 | Manual + metadoc authored; C1/C2/C3 claim-verified; 4 spot-runs recorded | Fable 5 (`claude-fable-5`), H1405 |
| 25-07-2026 | §9 C3 amended after the adversarial pass: `withoutEvents` create-as-paid paths (silent promise fulfillment) + the new-value-only audit walk make the guard blind to silently-created paid payments — "trail complete going forward" retracted | Fable 5 (`claude-fable-5`), H1405 |
| 25-07-2026 | Adversarial ledger complete: 45/47 CONFIRMED; §5.2 `duplicate` row + §11.2 RU guidance corrected (the `duplicate` decision constant is never persisted — a replay leaves no ledger row) | Fable 5 (`claude-fable-5`), H1405 |
| 26-07-2026 | §9 C3 fix merged: `payments.first_paid_at` (write-path stamp + 3 `withoutEvents` create-as-paid payloads) + old-value audit-diff hardening + backfill command; guard's `hasPriorPaidTransition()` now checks the column first, audit walk as fallback — backlog row 2 updated, prod migrate/backfill queued as DEPLOY_QUEUE row 62 | Sonnet 5 (`claude-sonnet-5`), H1645 |
| 31-07-2026 | §7b semi-manual paths (PayPal + company invoice) documented after H2017 merge + prod enable; Tochka audit + accountant guide + copy docs updated | Grok 4.5 (`grok-4.5`), H2017 |

_Dr. Mārcis Gasūns_
