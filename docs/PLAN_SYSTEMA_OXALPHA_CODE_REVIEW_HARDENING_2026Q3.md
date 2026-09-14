# Systema-Sanscriticum OxAlpha code-review hardening plan

_Created: 26-08-2026 · Last updated: 26-08-2026_

Goal: give H3546 (OxAlpha) — Systema-Sanscriticum 30-day risk-ranked code review and future independent review gate an unattended path from canonical tracker setup through bounded review, proven urgent repair, and an inactive future-gate design.

## Decisions taken

| # | Ruling |
|---|---|
| 1 | One OxAlpha handoff per repository |
| 2 | Retrospective risk review plus future-gate design |
| 3 | Fixed window 26-07-2026 through 25-08-2026 |
| 4 | At most ten risk-ranked executable-code slices |
| 5 | Generated/vendor/data-only churn excluded unless behavior changed |
| 6 | Standards and Spec passes remain independent |
| 7 | GitHub Issues, default labels, PR intake OFF, single-context |
| 8 | Spec source order: PR body, issue, handoff/plan, matching doc, no spec available |
| 9 | Findings require severity, location, failure mode, and repro/test |
| 10 | Only proven P0/P1 may be fixed, always with regression tests |
| 11 | Adapter and each fix use separate green PRs |
| 12 | Future gate is designed but not enabled |
| 13 | Human approval additionally covers money/security/production paths |

## Autonomy contract

Apply defaults and log them. Missing spec skips only that axis. Stop only an affected fix for secrets/PII, production state, irreversible migration, unclear money behavior, or bulk generated/vendor/data changes; continue safe slices. Never deploy, change production state, or auto-resolve money semantics; money, security, and production paths also require human approval. Merge only separate green adapter and minimal regression-tested P0/P1 PRs. Never enable a workflow or protection rule.

## Layers

1. [Roadmap](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_OXALPHA_CODE_REVIEW_2026Q3.md)
2. [Architecture](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_OXALPHA_CODE_REVIEW.md)
3. [Implementation](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_OXALPHA_CODE_REVIEW.md)
4. [Verification](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_OXALPHA_CODE_REVIEW.md)

## Starter

Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H3546-OxAlpha_Systema-Sanscriticum_oxalpha-30d-risk-review-gate_26.08.26.md and execute it.

_Dr. Mārcis Gasūns_
