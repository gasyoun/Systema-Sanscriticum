# H2474 — H071 lower-severity (MEDIUM/LOW) census on origin/main

_Created: 08-08-2026 · Last updated: 08-08-2026_

**Executor:** Grok 4.5 (`grok-4.5`) · handoff [H2474](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2474-Grok_Systema-Sanscriticum_security-w2-h071-lower-severity-census_08.08.26.md)

**Path:** single-session re-verify (path B) of all Wave 2 lower-severity residuals after access + revenue bullets closed. Fan-out units H2463–H2471 name the same defects one-by-one; this pass re-checks **code + regression test** on `origin/main` and ticks the roadmap bucket.

**Baseline HEAD:** `6e520be2` (`chore(release): cut v1.88.11 …` on 08-08-2026).

## Verdict

| Status | Count |
|---|---|
| **FIXED + regression present** | 9 / 9 Wave2 lower-severity residuals |
| **Still open leak** | 0 |
| **Won't-fix** | 0 |

**Roadmap:** [docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md) Wave 2 lower-severity sub-bullets all `[x]` with cites (this pass). Parent Wave 2 "Land the ~15" checklist closes when every access/revenue/lower-severity child is `[x]` — all three buckets are now fully cited.

## Per-defect table

Fan-out H071 numbering (H2474 table) differs slightly from [SECURITY_AUDIT_money_2026-07-02.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/SECURITY_AUDIT_money_2026-07-02.md) section numbers; both are named below.

| Unit | Fan-out # | Audit § | Defect (one line) | Fix cite | Code signal on main | Regression test | Verdict |
|---|---|---|---|---|---|---|---|
| [H2463](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2463-Grok_Systema-Sanscriticum_security-loyalty-conditional-zero-one-defect_08.08.26.md) | #7 | §8 / §13 | Loyalty tier counts conditional/0₽ as purchased | [PR #253](https://github.com/gasyoun/Systema-Sanscriticum/pull/253) | `Tariff::getDiscountPercentForUser` uses `->real()` + amount>0 | `LoyaltyDiscountTest::conditional_and_zero_amount_payments_do_not_count_toward_loyalty` | **FIXED** (already archived unit) |
| [H2464](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2464-Grok_Systema-Sanscriticum_security-deposit-partial-consumption-one-defect_08.08.26.md) | #9+#10 | §9–§10 | Partial deposit consume + upgrade credit loses deposit half | [PR #360](https://github.com/gasyoun/Systema-Sanscriticum/pull/360) | `consumed_amount` + deposit-aware upgrade path in `Payment`/`Tariff` | `DepositPartialConsumptionTest` (partial drain, residual survive, upgrade includes deposit half) | **FIXED** |
| [H2465](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2465-Grok_Systema-Sanscriticum_security-block-paid-share-keys-one-defect_08.08.26.md) | #11 | §15 | Block base ignores already-paid share keys | `TeacherSalaryService` on main (`paidShareKeys` in `blockGroupRevenueDetail` when `teacher_id` set; landed via salary late-pay / direct-receipt layers, commit family `13b054d3` / `2af69937`) | `refreshBaseRevenue` / record path pass `teacher_id` | `SalaryPayoutLedgerTest::available_prior_block_payments_excludes_already_paid_shares` + `paid_share_keys_include_prior_blocks_paid_entries` + **new** `TeacherBlockPayoutTest::block_group_revenue_excludes_already_paid_share_keys` (this PR) | **FIXED** |
| [H2466](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2466-Grok_Systema-Sanscriticum_security-block-payout-advances-one-defect_08.08.26.md) | #17 | §20 | Block calculator never settles/deducts advances | `TeacherSalaries` uses `advanceOffsetForTotal` + `settleAdvancesForBlockPayout` (commit family `2af69937`) | Filament block action subtracts advance then settles | `TeacherAdvanceTest` (outstanding / settled balance) + **new** `TeacherAdvanceTest::settle_advances_for_block_payout_applies_fifo_up_to_limit` (this PR) | **FIXED** |
| [H2467](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2467-Grok_Systema-Sanscriticum_security-promo-pending-strip-one-defect_08.08.26.md) | #13 | §16 | Promo silently stripped on re-pending → full price | [PR #264](https://github.com/gasyoun/Systema-Sanscriticum/pull/264) | `PaymentController` recent-pending path errors instead of silent full price | `PromoRecentPendingTest::fresh_pending_with_same_promo_errors_instead_of_charging_full_price` | **FIXED** |
| [H2468](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2468-Grok_Systema-Sanscriticum_security-checkout-loyalty-badge-one-defect_08.08.26.md) | #14 | §17 | Checkout loyalty badge from dropped column | [PR #256](https://github.com/gasyoun/Systema-Sanscriticum/pull/256) | `CheckoutController::computeState` uses `getDiscountPercentForUser`; `isLoyal = percent > 0` | `CheckoutLoyaltyStateTest::deposit_only_user_is_not_marked_loyal` | **FIXED** |
| [H2469](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2469-Grok_Systema-Sanscriticum_security-password-regen-repaid-one-defect_08.08.26.md) | #15 | §18 | paid→paid re-fires password regen | [PR #257](https://github.com/gasyoun/Systema-Sanscriticum/pull/257) | welcome/password once-per-account guard | `WelcomePasswordRegenTest::status_roundtrip_does_not_regenerate_student_password` | **FIXED** |
| [H2470](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2470-Grok_Systema-Sanscriticum_security-homework-lesson-access-grant-one-defect_08.08.26.md) | #16 | §19 | Homework gate ignores LessonAccessGrant | [PR #255](https://github.com/gasyoun/Systema-Sanscriticum/pull/255) | `HomeworkController` honors grant | `HomeworkFlowTest::student_with_lesson_grant_can_submit_homework` | **FIXED** |
| [H2471](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2471-Grok_Systema-Sanscriticum_security-referral-reward-reverse-one-defect_08.08.26.md) | #12 | §14 | Referrer reward not clawed on reverse | [PR #258](https://github.com/gasyoun/Systema-Sanscriticum/pull/258) | `ReferralService::reverseRewardForPayment` + `PaymentObserver` | `ReferralProgramTest::reward_is_clawed_back_when_referred_payment_is_reversed` (+ floors / both-sides) | **FIXED** |

## Out of lower-severity bucket (already closed under access/revenue)

These audit sections are **not** residual lower-severity work; cited so a reader does not re-open them:

| Audit § | Topic | Closed as |
|---|---|---|
| §11 | anon `/class/{id}/join` Zoom | Access leak — [PR #252](https://github.com/gasyoun/Systema-Sanscriticum/pull/252) (H2383) |
| §12 | chargeback keeps group/Zoom | Access leak — [PR #258](https://github.com/gasyoun/Systema-Sanscriticum/pull/258) revoke path (H2384) |
| §6 / refunds in block base | block payout ignores refunds | Revenue — [PR #254](https://github.com/gasyoun/Systema-Sanscriticum/pull/254) (H2453) |

## Method

1. Worktree off `origin/main` (session-unique).
2. Code probes for each fix surface (`Tariff`, `Payment`, `CheckoutController`, `PaymentController`, `HomeworkController`, `ReferralService`/`PaymentObserver`, `TeacherSalaryService`, `TeacherSalaries`).
3. `gh pr view` for numbered money-core fix PRs (all **MERGED**).
4. Test file + method name presence for every residual; two missing base-case tests added this PR (paidShareKeys on block base; FIFO advance settle API).
5. No re-implementation of already-fixed leaks.

## Concurrent fan-out

At census start, live claims/worktrees existed for **H2464** and **H2471** (other Grok sessions). This pass did **not** steal those units; evidence here is independent re-verify they can close against. H2472/H2473 are docs-only follow-through once units are closed.

## Reproduce

```text
git fetch origin
git show origin/main:app/Models/Tariff.php | findstr /C:"->real()"
# or open the tests named in the table on origin/main
```

_Dr. Mārcis Gasūns_
