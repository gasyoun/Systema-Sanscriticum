# Security & Vulnerability-Avoidance Roadmap — Systema Sanscriticum

_Created: 03-07-2026 · Last updated: 27-08-2026_

> **Truth-pass 27-08-2026** (Grok 4.6 `grok-4.6`). Closed references checked against the combined registry. Ledger kept in place ([FINDINGS §475](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md) clause 3). Not archived.

Security-focused companion to the general
[docs/ROADMAP_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md).
Authored from a `/roadmap-interview` (03-07-2026, Fable 5 `claude-fable-5`) grounded in a
posture audit; every wave order and depth ruling below was **decided by MG**, not assumed
(see [Decisions taken](#decisions-taken)).

---

## Why this repo needs its own security track

Systema Sanscriticum is a **paid** online-education platform that stores **personal data**
(names, emails, password hashes, IPs) and runs a **money core** (checkout, deposits,
referral credit, teacher payroll, webhooks) — and the GitHub repository is **public**. That
combination means three distinct attack surfaces, each with a different failure mode:

1. **Exposure** — personal data or secrets committed to a public repo, or public endpoints
   that leak paid resources.
2. **Application logic** — pricing/access/payout defects that leak revenue or grant unpaid
   access (a paid platform's logic *is* its security boundary).
3. **Platform & supply chain** — an EOL framework, unscanned dependencies, no SAST for the
   PHP that the whole app is written in.

The roadmap is ordered so the cheapest, most externally-visible exposure is closed first,
then the known logic defects, then the automated defenses that stop regressions, then the
platform upgrade.

---

## Posture snapshot (audit 03-07-2026)

**Already closed this session (Wave 1 partial):**
- ✅ GitHub **secret scanning** + **push protection** enabled (were off).
- ✅ GitHub **Dependabot vulnerability alerts** + **automated security fixes** enabled (were off).
- ✅ GitHub **private vulnerability reporting** enabled (was off).
- ✅ [`SECURITY.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/SECURITY.md) disclosure policy added (was missing).
- ✅ `t_login_at` (real student PII dump) and `KEYS` **untracked from HEAD** + `.gitignore`
  patterns added for dumps (`*_login_at`, `*.dump`, `*.tinker`), then **fully purged from git
  history** via `/secret-purge` (`git filter-repo` + coordinated force-push of all 15 branches
  + tag `v1.0.1`, 05-07-2026, [H080](#handoffs)). Both paths 404 on every branch.

**Open — carried into the waves below:**
- 🟡 PII dump **purged from history** (05-07-2026); the only residual is the orphaned old
  commit `8851c92` still fetchable **by exact 40-char SHA** until GitHub's automatic GC (not in
  any branch, not browsable/searchable). Optional acceleration = a GitHub Support GC request
  (GTD `@DO`, MG account).
- ✅ **H071 money/access defects fully drained** (07-07-2026) — all findings from the 02-07
  adversarial review fixed, one PR each with a regression test; last two ([PR #360](https://github.com/gasyoun/Systema-Sanscriticum/pull/360))
  await manual MG review per the money-core no-auto-merge discipline.
- ✅ **Telegram / VK / Zoom webhook secrets SET on prod** and code fail-closed (16-08-2026, [H2896](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2896-Grok_Systema-Sanscriticum_systema-app-vuln-audit-2026-08-16_16.08.26.md)): unsigned POST → 403/401; empty Zoom secret would be 503, live Zoom returns 403 (secret present). Wave 1 webhook deploy item is closed.
- ✅ **PHP SAST added** (07-07-2026) — [Semgrep job](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/semgrep.yml)
  (`p/php` + `p/security-audit` + `p/owasp-top-ten`), advisory/non-blocking during triage. CodeQL still
  can't cover PHP directly, but the gap is now filled.
- ✅ **Laravel 13 + PHP 8.3.32** on prod (Wave 4 closed; snapshot below is historical).
- ✅ 23 tracked PNG screenshots **audited** (05-07-2026) — all synthetic UI/design/marketing
  renders (blank certificate template, placeholder `Иван`/`mail@example.com` forms, module
  grids); no real student PII, none purged.
- ✅ `main` branch protection now **requires 1 approving review** + blocks force-push/deletion
  (set 05-07-2026 during H080; the one purge force-push was a temporary, immediately-reverted lift).

---

## Checkout money-safety follow-up (18-07-2026)

Five manually reviewed, default-OFF deliveries close checkout integrity gaps discovered after
H071. All PRs carry the money-contour no-auto-merge marker; none may be enabled before human
review and merge:

- [PR #574](https://github.com/gasyoun/Systema-Sanscriticum/pull/574) — reject inactive tariffs
  before guest creation or any payment/bank side effect (`CHECKOUT_INACTIVE_TARIFF_GUARD`).
- [PR #576](https://github.com/gasyoun/Systema-Sanscriticum/pull/576) — reload and lock the user
  before calculating/debiting referral credit (`CHECKOUT_REFERRAL_CREDIT_LOCK`).
- [PR #579](https://github.com/gasyoun/Systema-Sanscriticum/pull/579) — restore the exact deposit
  credit on a real paid-to-reversed transition, newest consumption first and idempotently
  (`CHECKOUT_DEPOSIT_REVERSAL`).
- [PR #581](https://github.com/gasyoun/Systema-Sanscriticum/pull/581) — reserve limited promo
  capacity for the 30-minute bank-link TTL plus a 10-minute webhook buffer; add
  `payments.payment_link_expires_at`; keep legacy null-expiry reservations held; reverse and
  reapply `used_count` exactly once (`CHECKOUT_PROMO_RESERVATIONS`).
- [PR #582](https://github.com/gasyoun/Systema-Sanscriticum/pull/582) — dry-run-first
  `payments:audit-checkout-integrity`; its `--apply-safe` mode changes only promo `used_count`
  and additionally requires `CHECKOUT_INTEGRITY_SAFE_REPAIRS`. It is stacked on #581 and must be
  retargeted to `main` after #581 merges.

Deployment order: review and merge #574/#576/#579 independently; merge #581, run its migration
while the flag remains OFF, then enable it; retarget/review/merge #582; run the audit dry-run;
temporarily enable the safe-repair flag only for `--apply-safe`, disable it immediately afterward,
and manually adjudicate negative wallets, historical deposit restoration, and legacy pending
links against bank/accounting evidence. Rerun until only documented exceptions remain. The local
production-data audit was not run during delivery because the configured MySQL endpoint at
`127.0.0.1:3306` was unavailable.

Regression evidence across the five PRs: focused suites cover inactive authenticated/guest POSTs,
stale referral models, full/partial/multi-deposit reversal round trips, promo concurrency/expiry,
Tochka TTL payloads, reversal counters, and audit repair scope. The combined money gate is green
through 104 tests / 366 assertions on the stacked audit branch; Pint is clean.

---

## Decisions taken

Recorded from the 03-07-2026 interview (MG rulings):

| # | Decision point | Ruling | Rationale |
|---|---|---|---|
| D1 | Depth of PII-dump remediation | **Full git-history purge + force-push; no user notification** | Scrub `t_login_at`/`KEYS` from all history so the data is unrecoverable; treated as internal cleanup — the affected student is not contacted and no forced password reset is issued. |
| D2 | Where the security roadmap lives | **Dedicated `docs/SECURITY_ROADMAP.md`** | Keep the security track coherent and referenceable, cross-linked with the general roadmap, rather than diluted into a hardening subsection. |
| D3 | Wave-1 focus | **Exposure + platform toggles first** | Fast, external-facing, low-risk; shrink the public attack surface before touching money code. |
| D4 | Automated tooling & process | **Both — PHP SAST in CI *and* an institutionalized recurring adversarial money-core review** | The adversarial review found 16 real bugs; make it repeatable and add a static gate CodeQL cannot provide for PHP. |

---

## Wave 1 — Kill the exposure (Q3 2026, now)

**Unblocked by:** nothing — this is the entry point. **Complete** (16-08-2026). History purge + branch protection landed 05-07-2026; prod webhook secrets confirmed SET 16-08-2026 (H2896). Residual optional: GitHub Support GC of orphaned SHA `8851c92`.

- ✅ Enable GitHub secret scanning, push protection, Dependabot alerts + auto-fixes, private
  vulnerability reporting. **(done this session)**
- ✅ Add [`SECURITY.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/SECURITY.md). **(done)**
- ✅ Untrack `t_login_at` + `KEYS` from HEAD; extend `.gitignore` with dump patterns. **(done)**
- ✅ **Purge `t_login_at` + `KEYS` from all git history** (D1) via `/secret-purge`:
  `git filter-repo --invert-paths` on a fresh mirror, coordinated force-push of all 15 branches +
  tag, verified no ref resolves the paths and both 404 on GitHub. [PR #248](https://github.com/gasyoun/Systema-Sanscriticum/pull/248)
  was already merged, so nothing to rebase. **(done 05-07-2026)** → **[H080](#handoffs)**
- ✅ **Audit the 23 tracked PNG screenshots** for embedded student PII — all synthetic
  UI/design renders (blank certificate, placeholder forms, module grids); none showed real
  student data, none purged. **(done 05-07-2026)** → **[H080](#handoffs)**
- [x] **Flip the 3 fail-open webhooks to fail-closed** — code was already fail-closed; prod `.env` now has `TELEGRAM_BOT_WEBHOOK_SECRET`, `VK_CALLBACK_SECRET`, `ZOOM_WEBHOOK_SECRET` **SET** (probed 16-08-2026 via Laravel config, values not logged). Live unsigned POST: `/api/telegram/webhook` 403, `/api/vk-webhook` 403, `/api/webhooks/zoom` 403 (would be 503 if Zoom secret empty), `/api/webhooks/tochka` 401, `/api/sync-lessons` 401. Matrix: [docs/webhook-security.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md). → **[H2896](#handoffs)**
- ✅ **Add required-review branch protection on `main`** — 1 approving review required, force-push
  + deletion blocked (codifies the "no auto-merge on money core" convention as a gate). **(done 05-07-2026)**

**Exit criterion:** no personal data or secret is retrievable from the repo or its history;
every inbound webhook is authenticated fail-closed; secret pushes are blocked at the gate.

---

## Wave 2 — Close the known logic defects (Q3 to Q4 2026)

**Unblocked by:** Wave 1 (do not rewrite history under half-finished money PRs). Runs against the
existing [H071](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H071-Fable_Systema-Sanscriticum_systema_money_core_findings_03.07.26.md)
handoff — this roadmap does not restate the findings, it sequences them.

- [x] Land the ~15 remaining CONFIRMED money/access defects, **one small PR each, each with a
  regression test, no auto-merge** (money core under special protection). Highest-impact first:
  Closed 08-08-2026 (access + revenue verifies H2366–H2453/H2471 + lower-severity census H2474):
  - **Access leaks** (unpaid access / revenue-visible-to-anon):
    - [x] VIP/bundle tariff → `Tariff::accessKey()` not raw `type` so paid VIP unlocks
      lessons — shipped [PR #250](https://github.com/gasyoun/Systema-Sanscriticum/pull/250)
      (`PaymentController` + `VipBundleAccessKeyTest`); verified on main 07-08-2026 (H2366).
    - [x] public `/class/{id}/join` never redirects anonymous users to the real Zoom link
      — shipped [PR #252](https://github.com/gasyoun/Systema-Sanscriticum/pull/252)
      (`JoinClassController` + `JoinClassAccessTest` / `JoinClassControllerTest`); verified on main 07-08-2026 (H2383).
    - [x] chargeback/failed reversal revokes group membership (and thus Zoom calendar access
      via group-gated `IcsFeedBuilder`) — shipped [PR #258](https://github.com/gasyoun/Systema-Sanscriticum/pull/258)
      (`Payment::reconcileAccessAfterReversal` + `AutoEnrollOnPaymentTest`); verified on main 07-08-2026 (H2384).
  - **Revenue leaks:**
    - [x] deposit credited to multiple pending payments — blocked while unspent deposit
      exists (`Payment::scopeHasOtherPendingOrderForCourse` + `PaymentController` lock) —
      shipped [PR #342](https://github.com/gasyoun/Systema-Sanscriticum/pull/342)
      (`CheckoutPriceTest::second_pending_order_on_same_course_is_blocked_while_deposit_unspent`);
      verified on main 08-08-2026 (H2418).
    - [x] referral reward paid on 0-ruble / deposit / trial / conditional orders —
      filtered by `ReferralService::isQualifyingCoursePayment` (amount>0, course_id,
      not deposit/trial/conditional/expense/salary_payout) —
      shipped [PR #251](https://github.com/gasyoun/Systema-Sanscriticum/pull/251)
      (`ReferralProgramTest::no_reward_on_non_course_payments` +
      `real_course_purchase_after_non_qualifying_still_rewards`);
      verified on main 08-08-2026 (H2437).
    - [x] salary month-close double-counts the whole month — closed periods use
      `SalaryClosedPeriod` + `rollForwardMonth`/`remapMonths` (closed month earns 0;
      amount rolls to next open; lifetime total unchanged) — shipped
      [PR #618](https://github.com/gasyoun/Systema-Sanscriticum/pull/618)
      (`SalaryPeriodCloseTest`); verified on main 08-08-2026 (H2451).
      Residual test-order flake tracked separately as H2151 / issue #1055.
    - [x] block payout ignores refunds — `blockGroupRevenueDetail` subtracts
      `Расход` return rows covering the block (`isReturnPayment` / floor at 0) —
      shipped [PR #254](https://github.com/gasyoun/Systema-Sanscriticum/pull/254)
      (`TeacherBlockPayoutTest::block_revenue_subtracts_refund_expense_rows` +
      `block_revenue_floors_at_zero_when_refunds_exceed_revenue`);
      verified on main 08-08-2026 (H2453).
    - [x] referral credit not clawed on paid→failed/canceled — reverse via
      `ReferralService::reverseRewardForPayment` from `PaymentObserver` (floor at 0;
      deletes `ReferralReward` so unique(referred_id) slot reopens) —
      shipped [PR #258](https://github.com/gasyoun/Systema-Sanscriticum/pull/258)
      (`ReferralProgramTest::reward_is_clawed_back_when_referred_payment_is_reversed` +
      `clawback_floors_referrer_credit_at_zero_when_already_spent` +
      `both_sides_clawback_when_referred_amount_was_granted`);
      verified on main 08-08-2026 (H2471). No re-implementation — regression present.
  - **Lower-severity pricing/loyalty defects** — remaining MEDIUM/LOW items in H071
    (census [docs/SECURITY_WAVE2_LOWER_SEVERITY_CENSUS_H2474_2026-08-08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_WAVE2_LOWER_SEVERITY_CENSUS_H2474_2026-08-08.md), H2474):
    - [x] tariff loyalty wholesale count ignores conditional / 0₽ payments —
      Tariff::getDiscountPercentForUser uses ->real() + where('amount', '>', 0) —
      shipped [PR #253](https://github.com/gasyoun/Systema-Sanscriticum/pull/253)
      (LoyaltyDiscountTest::conditional_and_zero_amount_payments_do_not_count_toward_loyalty);
      verified on main 08-08-2026 (H2463 / H2474 census).
    - [x] deposit partial consumption + upgrade credit keeps deposit half —
      Payment::consumeDepositsForCourse drains by deposit_credit_applied / consumed_amount;
      Tariff::upgradeCreditForUser sums amount + COALESCE(deposit_credit_applied, 0) —
      shipped [PR #360](https://github.com/gasyoun/Systema-Sanscriticum/pull/360)
      (DepositPartialConsumptionTest);
      verified on main 08-08-2026 (H2464 / H2474 census).
    - [x] block payout base excludes already-paid share keys (paidShareKeys in
      blockGroupRevenueDetail when 	eacher_id set) —
      TeacherSalaryService + SalaryPayoutLedgerTest /
      TeacherBlockPayoutTest::block_group_revenue_excludes_already_paid_share_keys;
      verified on main 08-08-2026 (H2465 / H2474).
    - [x] block calculator deducts and settles unsettled advances —
      advanceOffsetForTotal + settleAdvancesForBlockPayout on TeacherSalaries /
      TeacherAdvanceTest::settle_advances_for_block_payout_applies_fifo_up_to_limit;
      verified on main 08-08-2026 (H2466 / H2474).
    - [x] promo re-pending errors instead of silently charging full price —
      shipped [PR #264](https://github.com/gasyoun/Systema-Sanscriticum/pull/264)
      (PromoRecentPendingTest); verified on main 08-08-2026 (H2467 / H2474).
    - [x] checkout loyalty badge from price engine (not dropped column) —
      shipped [PR #256](https://github.com/gasyoun/Systema-Sanscriticum/pull/256)
      (CheckoutLoyaltyStateTest); verified on main 08-08-2026 (H2468 / H2474).
    - [x] paid→paid status re-fire does not regenerate student password —
      shipped [PR #257](https://github.com/gasyoun/Systema-Sanscriticum/pull/257)
      (WelcomePasswordRegenTest); verified on main 08-08-2026 (H2469 / H2474).
    - [x] homework submit gate honors LessonAccessGrant —
      shipped [PR #255](https://github.com/gasyoun/Systema-Sanscriticum/pull/255)
      (HomeworkFlowTest::student_with_lesson_grant_can_submit_homework);
      verified on main 08-08-2026 (H2470 / H2474).
- [x] After each fix, extend the money-core test suite so the defect cannot regress silently
  (suite present for every Wave 2 cite above; H2474 added two residual base-case tests).

**Exit criterion:** every H071 finding is either fixed-with-test or explicitly ruled
won't-fix with rationale in the handoff; no access path grants paid resources without a paid,
non-reversed payment.

---

## Wave 3 — Automated defense so it does not regress (Q4 2026)

**Unblocked by:** Wave 2 (institutionalize the review that produced the Wave-2 list; wire SAST
once the known-defect backlog is drained so the baseline is clean).

- [x] **PHP SAST in CI** (D4): add a `semgrep` job with the PHP + Laravel security rulesets to
  [.github/workflows](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/.github/workflows),
  and/or `larastan/larastan` at a security-focused level. Start advisory (non-blocking) to
  triage the false-positive rate, then make it required once tuned. Fills the gap CodeQL
  leaves — CodeQL is JS-only here. → **[H081](#handoffs)**
  - ✅ **Semgrep job added** (07-07-2026, Sonnet 5 `claude-sonnet-5`):
    [.github/workflows/semgrep.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/semgrep.yml),
    `p/php` + `p/security-audit` + `p/owasp-top-ten` rulesets, `continue-on-error: true`
    (advisory).
  - ✅ **Triaged and promoted to required** (13-07-2026, Opus 4.8 `claude-opus-4-8`,
    [H885](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H885-Opus_Systema-Sanscriticum_semgrep-sast-required-gate_13.07.26.md),
    [PR #509](https://github.com/gasyoun/Systema-Sanscriticum/pull/509)): the 18
    advisory findings were all non-PHP-source (`github-actions-mutable-action-tag`
    ×13, `dependabot-missing-cooldown`, `plaintext-http-link` in a stray committed
    nginx default page) — fixed by pinning Action `uses:` to commit SHAs, adding a
    7-day Dependabot cooldown, and removing the stray HTML file. `semgrep.yml` then
    dropped `continue-on-error` and added `--error`; "Semgrep scan" is now a required
    branch-protection check on `main` (verified 09-08-2026). Larastan not added —
    Semgrep's Laravel ruleset judged sufficient coverage; revisit if a future triage
    shows thin Laravel-specific sink coverage.
- [x] **Institutionalize the adversarial money-core review** (D4) — ✅ done
  (07-07-2026, Sonnet 5 `claude-sonnet-5`): the 02-07 multi-agent finder+verifier
  run is now a committed, repeatable harness —
  [scripts/security/money_core_adversarial_review.workflow.js](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/security/money_core_adversarial_review.workflow.js)
  (5 finder agents × adversarial-verifier, same scope: `Payment.php`, `Tariff.php`,
  `PaymentController`, `ReferralService`, `TeacherSalaryService`, webhook
  controllers), documented in
  [docs/money-core-adversarial-review.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-core-adversarial-review.md)
  with cadence (per money-core release + quarterly, not per-PR) and the
  findings-to-PR discipline. → **[H081](#handoffs)**
- [x] Keep Dependabot auto-merge (already deployed) green so dependency CVEs close without a human.
  - ✅ **Verified + unblocked** (14-08-2026, Grok 4.6 `grok-4.6`,
    [H2476](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2476-Grok_Systema-Sanscriticum_security-w3-dependabot-auto-merge-green_08.08.26.md)):
    0 open Dependabot PRs; 0 open Dependabot alerts. Workflow
    [.github/workflows/dependabot-auto-merge.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/dependabot-auto-merge.yml)
    still patch/minor-only (no force-merge of majors). Last queue (10-08-2026)
    merged [#1580](https://github.com/gasyoun/Systema-Sanscriticum/pull/1580)–[#1583](https://github.com/gasyoun/Systema-Sanscriticum/pull/1583)
    after a human armed auto-merge; CodeQL split [#1584](https://github.com/gasyoun/Systema-Sanscriticum/pull/1584)–[#1586](https://github.com/gasyoun/Systema-Sanscriticum/pull/1586)
    stayed closed (grouped in [dependabot.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/dependabot.yml)).
    Root cause of the red path: Approve hard-failed with
    `GitHub Actions is not permitted to approve pull requests` (repo
    `can_approve_pull_request_reviews=false`; GTD `@DO` still open for the
    org toggle) because [PR #1109](https://github.com/gasyoun/Systema-Sanscriticum/pull/1109)
    dropped `|| true`. Approve is now `continue-on-error` so Enable auto-merge
    still runs. Next weekly scan Monday 08:00 Europe/Moscow + 7-day cooldown.

**Exit criterion:** a new injection or obvious access defect in PHP is caught by CI before
merge; the adversarial review is a scheduled, documented step rather than a one-off.

---

## Wave 4 — Platform & supply-chain hardening (Q1 to Q2 2027)

**Unblocked by:** Wave 3 (a clean SAST baseline and green money-core tests de-risk the upgrade).
Overlaps the general roadmap's Laravel-11 item — this track owns the **security** rationale.

- [x] **Laravel 10 to 12** — ✅ done. Upgrade shipped 13-07-2026 under H862 (commit `34fbb0c3`,
  [PR #505](https://github.com/gasyoun/Systema-Sanscriticum/pull/505)); the security rationale
  and support-window record were written 09-08-2026 under H2477:
  [docs/LARAVEL_10_TO_12_UPGRADE_SECURITY_NOTES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LARAVEL_10_TO_12_UPGRADE_SECURITY_NOTES.md).
  `composer.json` requires `"laravel/framework": "^12.61.1"`; `composer.lock` **and the live
  box** (`php artisan --version` in `/var/www/html`, probed 09-08-2026) both report **v12.64.0**
  on PHP 8.3.32. Supersedes the 10→11 roadmap target for a dated reason: Laravel 11's security
  window closed **12-03-2026**, so an 11-target upgrade would have moved prod from one EOL line
  to another. Laravel 12 takes security fixes until **24-02-2027**, but **bug-fix support ends
  13-08-2026** — from that date this is a security-fixes-only line, not a steady state.
  Successor: **H2506** ran the gating package-compatibility audit (09-08-2026, evidence:
  [docs/LARAVEL_10_TO_12_UPGRADE_SECURITY_NOTES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LARAVEL_10_TO_12_UPGRADE_SECURITY_NOTES.md)
  § Successor). Verdict: **not** a Filament/Horizon/Reverb blanket blocker — core Filament
  `^3.0` and everything else in the require block already resolve against Laravel 13; the
  only two blockers are `mokhosh/filament-kanban` (no `^13`-compatible release) and
  `saade/filament-fullcalendar` (stable caps at `^12`, only `v4.0.0-beta7` supports `^13` and
  that requires a Filament major). H2506 closed INCONCLUSIVE-with-evidence per its own stop
  condition; narrowly-scoped successor tracks unblocking just those two plugins. PHP 8.3
  already sits inside 13.x's 8.3–8.5 band, so no runtime move is needed.
- [x] **Laravel 12 to 13** — ✅ done. Shipped 10-08-2026 under **H2529** (Opus 5):
  `composer.json` requires `"laravel/framework": "^13.24.0"` and `"laravel/tinker": "^3.0"`.
  Both H2506 blockers were cleared **without** a Filament major bump — core Filament stays
  `v3.3.54`. Each blocker needed only its declared `illuminate/contracts` constraint widened
  to admit `^13.0` (no source changes), applied on an `l13-compatibility` branch in a fork
  and consumed as a `vcs` repository:
  [gasyoun/filament-kanban](https://github.com/gasyoun/filament-kanban/tree/l13-compatibility)
  and [gasyoun/filament-fullcalendar](https://github.com/gasyoun/filament-fullcalendar/tree/l13-compatibility)
  (cut from upstream `3.x`). Upstream fixes filed so the forks can be retired:
  [mokhosh/filament-kanban#95](https://github.com/mokhosh/filament-kanban/pull/95) (ours) and
  the pre-existing [saade/filament-fullcalendar#280](https://github.com/saade/filament-fullcalendar/pull/280).
  A replacement kanban package was ruled out first: of 12 candidates on Packagist the only
  `^13`-capable one (`sheavescapital/filament-kanban` v5.2) requires Filament `^4|^5`.
  Laravel 13 (released 17-03-2026) takes bug fixes until **Q3 2027** and security fixes until
  **17-03-2028** ([support policy](https://laravel.com/docs/13.x/releases#support-policy)), so
  this exits the 12.x line *before* its **13-08-2026** bug-fix cutoff — three days ahead of it,
  rather than sliding into a security-fixes-only steady state. PHP 8.3.32 already sits inside
  13.x's 8.3–8.5 band, so no runtime move was needed.
- [x] **PHP 8.2 to 8.3** — ✅ done (05-07-2026, [PR #298](https://github.com/gasyoun/Systema-Sanscriticum/pull/298);
  H2478 doc-close 09-08-2026): `composer.json` requires `php: "^8.3"` with
  `config.platform.php: "8.3.0"`; CI matrix `php: ["8.3"]` only
  ([.github/workflows/ci.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/ci.yml));
  prod nginx serves via `php8.3-fpm.sock`, php-fpm and Horizon workers run PHP 8.3.32
  (confirmed live 09-08-2026). Runbook:
  [docs/php-8.3-upgrade.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/php-8.3-upgrade.md) (superseded/historical).
- [x] **Dependency posture review** — ✅ done 14-08-2026 under **H2479** (Grok 4.6
  `grok-4.6`, [PR #1671](https://github.com/gasyoun/Systema-Sanscriticum/pull/1671)):
  [docs/DEPENDENCY_POSTURE_REVIEW_SYSTEMA_2026-08-14.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DEPENDENCY_POSTURE_REVIEW_SYSTEMA_2026-08-14.md).
  Re-verified on `origin/main` `520bbbad`: `composer audit --locked` reports
  empty advisories and empty abandoned; root and `mobile/` `npm audit
  --package-lock-only` report 0 vulnerabilities; Dependabot open-alert list
  is empty (40 historical alerts, all `fixed`, including two critical
  PhpSpreadsheet CVEs now locked at 1.30.6). No Packagist-abandoned direct
  dep. Residual this PR: Dependabot now watches `/mobile` (npm) and
  `/lecture-builder` (pip); CI gained a mobile lockfile audit job. Won't-fix
  this pass: `jenssegers/agent` v2.6.4 (last tag 13-06-2020, telemetry-only
  caller, not abandoned), Filament 3→5, Guzzle 7→8, and the H2529 `vcs`
  forks (already tracked).
- [x] **Deploy-surface review** — ✅ done (14-08-2026, H2480): `deploy.sh` / CI deploy /
  webhook-preflight never echo secret **values**; prod secrets come only from a
  non-committed `.env`. One FAIL closed in the same pass — Sail MySQL healthcheck
  no longer compose-interpolates `${DB_PASSWORD}` (`docker compose config` would
  have printed it). Evidence:
  [docs/SECURITY_W4_DEPLOY_SURFACE_REVIEW_2026-08-14.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_W4_DEPLOY_SURFACE_REVIEW_2026-08-14.md);
  gate: [tests/Unit/DeploySurfaceSecretsTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Unit/DeploySurfaceSecretsTest.php).

**Exit criterion:** production runs a supported Laravel/PHP with no open high/critical
Dependabot alerts and no secret material in deploy tooling.

---

## Non-goals (considered and ruled out)

- **Notifying the exposed student / forcing a password reset** — ruled out under D1 (treated as
  internal cleanup). Do not re-propose per-user breach notification for the `t_login_at` leak.
- **Folding security into the general roadmap** — ruled out under D2; this dedicated doc is the
  home. Do not spawn a third security file or migrate this content back.
- **CodeQL for PHP** — not viable (unsupported by CodeQL); Wave 3 uses Semgrep/Larastan instead.
  Do not re-attempt a PHP CodeQL job (it previously failed every PR and was already removed).
- **Blocking per-PR adversarial review** — ruled out under D4 (per-release + quarterly, not
  per-PR); a per-PR full adversarial run is too slow and noisy.
- **Rewriting the two message stores or the identity mappings for "security"** — that is a
  support-subsystem architecture concern already settled in
  [docs/support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md);
  not a security workstream here.

---

## Handoffs

Agent-doable Wave-1/Wave-3 work is packaged as executable handoffs in
[Uprava/handoffs](https://github.com/gasyoun/Uprava/tree/main/handoffs):

- **H080** — Wave 1 exposure purge (git-history scrub of `t_login_at`/`KEYS` via `/secret-purge`
  + PNG PII sweep + `main` branch protection).
  [H080-Opus_Systema-Sanscriticum_systema_security_exposure_purge_03.07.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H080-Opus_Systema-Sanscriticum_systema_security_exposure_purge_03.07.26.md)
- **H081** — Wave 3 automated defense (PHP SAST CI job + recurring adversarial money-core review harness).
  [H081-Sonnet_Systema-Sanscriticum_systema_security_sast_and_review_03.07.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H081-Sonnet_Systema-Sanscriticum_systema_security_sast_and_review_03.07.26.md)
- **H2476** — Wave 3 Dependabot auto-merge keep-green (14-08-2026): queue empty, Approve
  no longer blocks Enable auto-merge.
  [H2476-Grok_Systema-Sanscriticum_security-w3-dependabot-auto-merge-green_08.08.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2476-Grok_Systema-Sanscriticum_security-w3-dependabot-auto-merge-green_08.08.26.md)
- **H2479** — Wave 4 dependency posture review (14-08-2026): 0 open alerts,
  0 Composer/npm advisories, residual Dependabot + CI coverage for
  `mobile/` and `lecture-builder/`.
  [H2479-Grok_Systema-Sanscriticum_security-w4-dependency-posture-review_08.08.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2479-Grok_Systema-Sanscriticum_security-w4-dependency-posture-review_08.08.26.md)
- **H2480** — Wave 4 deploy-surface review (14-08-2026): no secret echo in
  `deploy.sh` / CI; Sail healthcheck no longer interpolates `${DB_PASSWORD}`.
  [H2480-Grok_Systema-Sanscriticum_security-w4-deploy-surface-review_08.08.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2480-Grok_Systema-Sanscriticum_security-w4-deploy-surface-review_08.08.26.md)
- **H2896** — App vulnerability audit 16-08-2026: July AUDIT_PLAN items re-verified fixed; Wave 1 prod webhook secrets confirmed; residuals numbered in the handoff (latent `$fillable`, lecture-builder localhost fail-open, trusted-author `{!! !!}`, `ADMIN_EMAIL` default).
  [H2896-Grok_Systema-Sanscriticum_systema-app-vuln-audit-2026-08-16_16.08.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2896-Grok_Systema-Sanscriticum_systema-app-vuln-audit-2026-08-16_16.08.26.md)
- **H071** — Wave 2 money/access findings (pre-existing).
  [H071-Fable_Systema-Sanscriticum_systema_money_core_findings_03.07.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H071-Fable_Systema-Sanscriticum_systema_money_core_findings_03.07.26.md)

_Dr. Mārcis Gasūns_
