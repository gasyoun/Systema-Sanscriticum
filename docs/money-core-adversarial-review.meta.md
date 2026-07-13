# money-core-adversarial-review.meta.md — metadoc about `money-core-adversarial-review`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [docs/money-core-adversarial-review.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-core-adversarial-review.md) — it records what surrounds that document (why it exists, how it is kept current, where it can mislead), not what the document says.

## Subject

- **Document:** [docs/money-core-adversarial-review.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-core-adversarial-review.md)
- **Purpose:** Turn the one-off 02-07-2026 multi-agent money-core review into a standing, repeatable harness with a fixed scope, run procedure, findings-handling discipline, and cadence.
- **Audience:** A Claude Code operator running the security review in-repo, plus MG as the human reviewer who gates the resulting per-finding PRs.
- **Format/contract:** Short process runbook — rationale (why not CI), scope file list, invocation block, findings-to-PR flow, and the MG-ruled cadence.

## Provenance

- **Subject created:** 07-07-2026 (git first-add).
- **Metadoc authored:** 13-07-2026, H890 (metadoc sweep II), Opus 4.8 `claude-opus-4-8`.
- **Next hardening:** confirm the referenced workflow script path stays valid and the cadence tracker in SECURITY_ROADMAP.md Wave 3 is actually updated after each run.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|-------------|-----|--------|
| 1 | Add a link to the most recent CONFIRMED-findings handoff (successor to H071) as a worked example | Operators need a concrete template beyond the archived H071 | parked (awaiting next run's handoff) |
| 2 | Record last-run date + next-due date inline (or a one-line pointer table) | "Track in SECURITY_ROADMAP.md" is easy to skip; drift risk | parked (needs SECURITY_ROADMAP.md sync) |
| 3 | Note expected finder-agent model tier/version in the harness | Reproducibility of the adversarial pass across sessions | parked (verify against workflow script) |
| 4 | Cross-check the scope file list against current `app/` layout | Files may move/rename; stale scope silently narrows coverage | parked (needs code audit) |

## Known limitations / caveats

- The subject documents a **manual, agent-driven** process — it is deliberately not CI-gated, so runs happen only when an operator triggers them.
- Findings output (`SECURITY_AUDIT_money_*.md`) is gitignored on a public repo; the metadoc cannot link to it and neither can the subject.
- Cadence compliance depends on an out-of-band tracker (SECURITY_ROADMAP.md Wave 3); nothing enforces the quarterly/per-release run.

## Intended use / known misuse

- **Intended:** an operator reads the subject, runs the named workflow script over the fixed scope, and routes each CONFIRMED/PLAUSIBLE finding into its own gated PR + handoff.
- **Misuse:** treating the review as a substitute for the per-PR Semgrep gate (it is complementary, not a replacement), auto-merging fixes without MG review, or running it per-PR (explicitly ruled out).

## Maintenance & sunset plan

Revisit whenever the money-core scope files change, the workflow script moves, or the cadence ruling is revised. Sunset only if the review is folded into an automated gate that can reason about business-logic state — until then it stays a live runbook.

## Deprecation status

`active`

## Related documents

- [docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md) — Wave 3 tracks the next scheduled run of this review.
- [AGENTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AGENTS.md) — "High-Risk Areas" money-core PR discipline the findings feed into.
- [H071 (archived)](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H071-Fable_Systema-Sanscriticum_systema_money_core_findings_03.07.26.md) — the original review this harness institutionalizes.

## Revision history

| Date | Change | Author |
|------|--------|--------|
| 13-07-2026 | metadoc created (H890) | Opus 4.8 claude-opus-4-8 |

_Dr. Mārcis Gasūns_
