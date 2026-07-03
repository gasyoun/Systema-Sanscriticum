# Security & Vulnerability-Avoidance Roadmap — Systema Sanscriticum

_Created: 03-07-2026 · Last updated: 03-07-2026_

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
  patterns added for dumps (`*_login_at`, `*.dump`, `*.tinker`) — **history purge still pending** (Wave 1, [H080](#handoffs)).

**Open — carried into the waves below:**
- 🔴 PII dump is still recoverable from **git history** and GitHub caches until the purge runs.
- 🟠 ~15 CONFIRMED money/access defects from the 02-07 adversarial review
  ([H071](https://github.com/gasyoun/Uprava/blob/main/handoffs/H071_systema_money_core_findings.md);
  #1 fixed in [PR #248](https://github.com/gasyoun/Systema-Sanscriticum/pull/248)).
- 🟠 Webhook secrets still need prod `.env` verification (Telegram-bot / VK-bot / Zoom), but code is fail-closed.
- 🟡 **No PHP SAST** — CodeQL cannot analyze PHP, so the language the entire app is written
  in has no static security scanning; the only defense today is manual adversarial review.
- 🟡 **Laravel 10.50.2** — security-EOL since ~Feb 2025 — on PHP 8.2.
- 🟡 23 tracked PNG screenshots may embed seeded/real student PII (unaudited).
- 🟡 `main` branch protection has **no required review** (money core relies on convention, not a gate).

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

**Unblocked by:** nothing — this is the entry point. Mostly done; one destructive step remains.

- ✅ Enable GitHub secret scanning, push protection, Dependabot alerts + auto-fixes, private
  vulnerability reporting. **(done this session)**
- ✅ Add [`SECURITY.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/SECURITY.md). **(done)**
- ✅ Untrack `t_login_at` + `KEYS` from HEAD; extend `.gitignore` with dump patterns. **(done)**
- [ ] **Purge `t_login_at` + `KEYS` from all git history** (D1) via `/secret-purge`:
  `git filter-repo`, coordinated force-push, verify no ref still resolves the blob, confirm
  GitHub cached views drop. Coordinate with open [PR #248](https://github.com/gasyoun/Systema-Sanscriticum/pull/248)
  (rebase after the rewrite). → **[H080](#handoffs)**
- [ ] **Audit the 23 tracked PNG screenshots** for embedded student PII; purge from history any
  that show real names/emails/payments; keep only synthetic-data shots. → **[H080](#handoffs)**
- [ ] **Verify prod webhook secrets** — set
  `TELEGRAM_BOT_WEBHOOK_SECRET`, `VK_CALLBACK_SECRET`, `ZOOM_WEBHOOK_SECRET` and register them
  with each provider before deploying fail-closed code. Matrix:
  [docs/webhook-security.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md).
  Already a GTD `@DO` — **MG action**.
- [ ] **Add required-review branch protection on `main`** so money-core changes cannot merge
  without a review (codifies the "no auto-merge on money core" convention as a gate).

**Exit criterion:** no personal data or secret is retrievable from the repo or its history;
every inbound webhook has a configured prod secret and authenticates fail-closed; secret pushes are blocked at the gate.

---

## Wave 2 — Close the known logic defects (Q3 to Q4 2026)

**Unblocked by:** Wave 1 (do not rewrite history under half-finished money PRs). Runs against the
existing [H071](https://github.com/gasyoun/Uprava/blob/main/handoffs/H071_systema_money_core_findings.md)
handoff — this roadmap does not restate the findings, it sequences them.

- [ ] Land the ~15 remaining CONFIRMED money/access defects, **one small PR each, each with a
  regression test, no auto-merge** (money core under special protection). Highest-impact first:
  - **Access leaks** (unpaid access / revenue-visible-to-anon): VIP/bundle tariff writes raw
    `type` instead of `Tariff::accessKey()`, so paid VIP unlocks 0 lessons
    (`PaymentController.php:119`); public `/class/{id}/join` redirects anonymous users to the
    real Zoom link (`JoinClassController.php:44`); chargeback/failed reversal never revokes
    group membership or Zoom calendar access (`Payment.php:296`).
  - **Revenue leaks:** deposit credited to multiple pending payments (`Payment.php:519`);
    referral reward paid on 0-ruble / deposit / trial / conditional orders (`ReferralService.php:52`);
    salary month-close double-counts the whole month (`TeacherSalaryService.php:769`); block
    payout ignores refunds (`TeacherSalaryService.php:400`).
  - **Lower-severity pricing/loyalty defects** — the remaining MEDIUM/LOW items in H071.
- [ ] After each fix, extend the money-core test suite so the defect cannot regress silently.

**Exit criterion:** every H071 finding is either fixed-with-test or explicitly ruled
won't-fix with rationale in the handoff; no access path grants paid resources without a paid,
non-reversed payment.

---

## Wave 3 — Automated defense so it does not regress (Q4 2026)

**Unblocked by:** Wave 2 (institutionalize the review that produced the Wave-2 list; wire SAST
once the known-defect backlog is drained so the baseline is clean).

- [ ] **PHP SAST in CI** (D4): add a `semgrep` job with the PHP + Laravel security rulesets to
  [.github/workflows](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/.github/workflows),
  and/or `larastan/larastan` at a security-focused level. Start advisory (non-blocking) to
  triage the false-positive rate, then make it required once tuned. Fills the gap CodeQL
  leaves — CodeQL is JS-only here. → **[H081](#handoffs)**
- [ ] **Institutionalize the adversarial money-core review** (D4): capture the 02-07 multi-agent
  finder+verifier run as a repeatable harness (a workflow script / handoff) scoped to
  `app/Models/Payment.php`, `app/Models/Tariff.php`, `PaymentController`, `ReferralService`,
  `TeacherSalaryService`, and the webhook controllers. Run it **per money-core release and on a
  quarterly cadence**, not per-PR. → **[H081](#handoffs)**
- [ ] Keep Dependabot auto-merge (already deployed) green so dependency CVEs close without a human.

**Exit criterion:** a new injection or obvious access defect in PHP is caught by CI before
merge; the adversarial review is a scheduled, documented step rather than a one-off.

---

## Wave 4 — Platform & supply-chain hardening (Q1 to Q2 2027)

**Unblocked by:** Wave 3 (a clean SAST baseline and green money-core tests de-risk the upgrade).
Overlaps the general roadmap's Laravel-11 item — this track owns the **security** rationale.

- [ ] **Laravel 10 to 11** — off the security-EOL framework onto a supported release line.
  Gate on the full suite green + the Wave-3 SAST baseline.
- [ ] **PHP 8.2 to 8.3** (tracked in
  [docs/php-8.3-upgrade.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/php-8.3-upgrade.md)).
- [ ] **Dependency posture review** — audit `composer.lock`/`package-lock.json` for abandoned or
  known-vulnerable packages once Dependabot alerts populate; pin and update.
- [ ] **Deploy-surface review** — confirm `deploy.sh` / `docker-compose.yml` never echo secrets
  to logs and that prod secrets come only from a non-committed `.env`.

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
  [H080_systema_security_exposure_purge.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/H080_systema_security_exposure_purge.md)
- **H081** — Wave 3 automated defense (PHP SAST CI job + recurring adversarial money-core review harness).
  [H081_systema_security_sast_and_review.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/H081_systema_security_sast_and_review.md)
- **H071** — Wave 2 money/access findings (pre-existing).
  [H071_systema_money_core_findings.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/H071_systema_money_core_findings.md)

_Dr. Mārcis Gasūns_
