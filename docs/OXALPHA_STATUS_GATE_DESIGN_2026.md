# Future OxAlpha status gate design (NOT ENABLED)

_Created: 26-08-2026 · Last updated: 26-08-2026_

Handoff: [H3546](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3546-OxAlpha_Systema-Sanscriticum_oxalpha-30d-risk-review-gate_26.08.26.md) · Companion report: [CODE_REVIEW_SYSTEMA_OXALPHA_30D_2026-08-26](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CODE_REVIEW_SYSTEMA_OXALPHA_30D_2026-08-26.md)

**Status: design only. No workflow, no branch-protection rule, no required check was created or enabled by this document or its PR** — plan decision 12 and the autonomy contract («Never enable a workflow or protection rule») hold.

## Purpose

A repeatable independent review gate so future OxAlpha (or any second-opinion) passes over Systema diffs are bounded, evidence-backed, and risk-scoped instead of ad-hoc.

## 1. Executable-code matching

A diff counts as **reviewable executable code** when any changed path matches:

```
app/**            (PHP application code)
routes/**         (HTTP surface)
config/*.php      (behavior-bearing config)
database/migrations/**
resources/views/**/*.blade.php  (only when logic: @if/@php/auth changes)
tests/**          (reviewed as spec evidence, not as churn)
```

Excluded by default (decision 5): `public/vendor/**`, `tools/*/` vendored trees, `*.min.js`, lockfiles, `docs/**`, `CHANGELOG*`, fixture data, `.ai_state.md`. A slice consisting only of excluded paths is **not reviewed as executable code**; it is listed in the report with an explicit exclusion note.

## 2. Independent required status check (design)

When enabled later, the gate would be a **separate workflow job** `oxalpha-review-gate` that runs on a pull_request event and posts exactly one of three conclusions as a commit status / check run:

| Conclusion | Condition |
|---|---|
| `pass` | Every retained slice has separate Standards and Spec verdicts with evidence links |
| `fail` | Any finding lacks severity/location/failure-mode/repro, or a P0/P1 lacks a regression test |
| `skip` | Diff matches no executable-code pattern (exclusion note posted as the run summary) |

Independence rule: the gate's verdicts must cite hunks and spec quotes produced **without access to the author session's reasoning** — inputs are the diff, the linked spec surfaces, and committed tests only. The job would be added to `.github/workflows/ci.yml` as a new job id, marked non-required initially, and flipped to required in branch protection **by a human**, as its own audited step.

## 3. Added human approval for money/security/production paths

Even after enablement, the gate never auto-approves changes whose changed-path set intersects:

```
app/Http/Controllers/*Claim*      app/Http/Controllers/Webhooks/**
app/Services/Payroll/**           app/Models/Payment.php
app/Http/Middleware/Verify*       config/services.php   config/receivables.php
deploy.sh                         database/migrations/*money-or-access*
```

Those slices require, on top of a green gate: (a) regression tests proving fail-before/pass-after, and (b) explicit human approval recorded in the PR body before merge. Money semantics are never auto-resolved (standing autonomy contract).

## 4. Rollout plan (future, human-triggered)

1. Land this design doc (no code).
2. A separate PR adds the workflow job with `required: false`; one dogfood run against a docs-only PR must conclude `skip`.
3. Two-week soak with weekly verdict audits.
4. Human flips the protection-rule requirement; rollback = untick the required check (no history rewrite, no force events).

## Rollback

Deleting the job from `ci.yml` fully disables the gate; because nothing here touches branch protection, default-branch merges never depended on it at design time.

## Proof of non-enablement

This PR contains only `docs/OXALPHA_STATUS_GATE_DESIGN_2026.md` (+ changelog/state). `git diff --name-only` against base shows no `.github/workflows/**` path and no protected-branch mutation.

_Dr. Mārcis Gasūns_
