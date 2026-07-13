# SELF_SERVICE_SUPPORT_UX_AUDIT_2026.meta.md — metadoc about `SELF_SERVICE_SUPPORT_UX_AUDIT_2026`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [`docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md) — it records what surrounds that audit (why it exists, how to trust it, what to fix next), never the audit's own findings.

## Subject

- **Document:** [`docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md)
- **Purpose:** A code-grounded audit of the student self-service and support surfaces in the cabinet — which help paths exist, which are only specced, and the ranked tickets to close the gap.
- **Audience:** MG plus any engineer or session picking up the support/self-service backlog (access diagnostics, escalation-with-context, help discoverability).
- **Format / contract:** Standalone audit prose — north star, current-surfaces inventory, problem taxonomy, recommended flows, ranked tickets, metrics. Every claim is cited to a real file/line or PR; it is a point-in-time snapshot, not a living spec.

## Provenance

- **Subject created:** 09-07-2026.
- **Metadoc authored:** 13-07-2026, handoff H891 (metadoc sweep III), Opus 4.8 `claude-opus-4-8`.
- **Next hardening:** Re-verify the file/line citations against current `main` (the codebase moves under the audit), and reconcile with the support-subsystem map once Flow A ships.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Re-anchor the file/line citations (dashboard tab lines, chat-view lines) against current `main` | Line numbers drift as views change; stale citations quietly mislead the next reader | parked (needs a re-verification pass against HEAD) |
| 2 | Fold the "known-stale support-subsystem-map line 99" note into that map directly | The audit flags the map as stale but the fix lives in the map, not here | parked (cross-doc edit, owner is the map) |
| 3 | Attach live `support:topic-ranking` / `content:detect-gaps` output to ground §3's taxonomy ranking | The audit ranks by judgment, not production volume — its own ticket 6 | parked (needs production-data run) |
| 4 | Add a status line per ticket (open/in-progress/shipped) so the audit tracks its own execution | Readers can't tell which of the six tickets already landed | parked (needs periodic reconciliation with PRs) |
| 5 | Cross-link the three open `@DECIDE` items to the GTD rollup so they aren't stranded here | The access-spec decisions block ticket 1 but live only in prose | parked (route to Uprava GTD) |

## Known limitations / caveats

- **Snapshot, not maintained.** Citations are true as of 09-07-2026; the audit does not self-update as the code moves.
- **Qualitative ranking.** §3's problem ordering is judgment-based — the audit itself flags that no live `support:topic-ranking` / `content:detect-gaps` numbers backed it.
- **Depends on unresolved decisions.** The highest-leverage ticket (Flow A / `AccessDiagnosticsService`) is gated on three `@DECIDE` answers carried forward from the access-self-service spec.
- **Scope boundaries stated but not resolved.** Recordings ("где записи") are explicitly out of scope and left as a possible future spec, not designed here.

## Intended use / known misuse

- **Use it** to pick the next self-service/support ticket, to confirm which surfaces are built vs. spec-only, and as the entry point into the access/debt/escalation specs it cites.
- **Do not** treat it as a live spec or as ground truth for current line numbers — verify citations before acting on an exact location, and read the linked specs for implementation detail.
- **Do not** infer production support volumes from §3 — that ranking is explicitly a placeholder for real command output.

## Maintenance & sunset plan

- Refresh when Flow A (access diagnostics) or Flow B (escalation-with-context) ships, or when live topic-ranking data becomes available — bump "Last updated" on the subject and tick the relevant ticket.
- Reconcile with the support-subsystem map on each edit so the two don't diverge.
- Sunset when the six tickets are all shipped and the audit's gaps are closed — at that point fold any surviving guidance into the support-subsystem map and mark this `superseded`.

## Deprecation status

`active` — no fixes from this audit have shipped that would supersede it; the largest gap (access self-service) remains spec-only.

## Related documents

- [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) — the standing map of the support subsystem; the audit flags its line 99 as stale, so read them together.
- [`docs/access-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/access-self-service-spec.md) — the design behind the audit's biggest gap (Flow A).
- [`docs/debtor-self-service-phase2-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtor-self-service-phase2-spec.md) — the shipped debt self-service the audit marks fully built.
- [`docs/cabinet-bot.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/cabinet-bot.md) — the bot/support framing and escalation trigger words the audit builds on.

## Revision history

| Date | Change | Model |
|---|---|---|
| 13-07-2026 | metadoc created (H891) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
