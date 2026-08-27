# Systema-Sanscriticum OxAlpha code-review architecture

_Created: 26-08-2026 · Last updated: 26-08-2026_

1. Canonical [issue tracker](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/agents/issue-tracker.md), [triage labels](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/agents/triage-labels.md), and [domain rules](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/agents/domain.md).
2. Bounded risk selector: executable and critical-path exposure outranks churn.
3. Standards reviewer: repository rules plus smell baseline.
4. Spec reviewer: ruled evidence chain; no evidence becomes no spec available.
5. [Evidence ledger](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CODE_REVIEW_SYSTEMA_OXALPHA_30D_2026-08-26.md) preserving axes, exclusions, and proof.
6. Fix lane: only proven P0/P1, one minimal regression-tested PR per defect.
7. Inactive [future-gate design](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DESIGN_SYSTEMA_OXALPHA_STATUS_GATE_2026.md).

Each manifest row records PR, base/head SHA, executable paths, exclusions, reasons, spec source, and both review states. Findings require severity, exact location, failure mode, and repro/test. Future check states are pass, evidence-backed fail, or infrastructure-neutral—never silent success.

Prior art is PARTIAL: reuse canonical adapter and two-axis review; build only the missing selection, evidence, urgent-fix, and inactive-gate layers.

_Dr. Mārcis Gasūns_
