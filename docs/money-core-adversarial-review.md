# Money-core adversarial review — repeatable harness

_Created: 07-07-2026 · Last updated: 07-07-2026_

Institutionalizes the 02-07-2026 multi-agent review that produced
[H071](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H071-Fable_Systema-Sanscriticum_systema_money_core_findings_03.07.26.md)
(20 CONFIRMED / 0 PLAUSIBLE / 0 REFUTED after dedup) as a standing process rather
than a one-off. Part B of
[H081](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H081-Sonnet_Systema-Sanscriticum_systema_security_sast_and_review_03.07.26.md),
Wave 3 of [docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md).

## Why not CI

CodeQL cannot analyze PHP ([codeql.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/codeql.yml))
and [Semgrep](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/semgrep.yml)
catches pattern-level injection/config issues, not business-logic defects like
"deposit credited to two pending payments" or "chargeback doesn't revoke access" —
those need an agent that reads the actual state machine. That's a Claude Code
session task, not a GitHub Actions job.

## Scope

`app/Models/Payment.php`, `app/Models/Tariff.php`,
`app/Http/Controllers/PaymentController.php`, `app/Services/ReferralService.php`,
`app/Services/TeacherSalaryService.php`, and the webhook controllers (Telegram
bot / VK bot / Zoom / Tochka).

## How to run it

From a Claude Code session `cd`-ed into this repo:

```
Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\scripts\security\money_core_adversarial_review.workflow.js,
then run it with the Workflow tool (scriptPath) and write the CONFIRMED/PLAUSIBLE
findings into a fresh, dated SECURITY_AUDIT_money_<YYYY-MM-DD>.md (gitignored —
.gitignore already excludes SECURITY_AUDIT*.md; this is a public repo).
```

The script (`scripts/security/money_core_adversarial_review.workflow.js`) runs 5
dimension-specific finder agents (pricing / access / payment-state / prana-referral
/ salary) over the scope above, then adversarially verifies every candidate finding
against the current code before it counts as CONFIRMED.

## What happens to findings

Each CONFIRMED or PLAUSIBLE finding becomes its own PR under the standing
money-core discipline in [AGENTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AGENTS.md)
"High-Risk Areas": one fix per PR, a regression test, no auto-merge, manual MG
review. Mint a handoff (`Uprava/handoffs/H###`) per batch of findings, mirroring
the H071 shape, rather than fixing silently in the review session.

## Cadence (D4, MG ruling 03-07-2026)

Run **per money-core release** (any PR touching the scope files above merges to
main) **and on a quarterly cadence** regardless of release activity — NOT per-PR.
Per-PR was ruled out as too slow/noisy for the review pattern to add value at that
frequency; Semgrep (advisory → required) is the per-PR gate instead.

Track the next scheduled run in [docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md)
Wave 3.

_Dr. Mārcis Gasūns_
