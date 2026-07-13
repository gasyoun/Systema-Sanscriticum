# CHECKOUT_PURCHASE_UX_AUDIT_2026.meta.md — metadoc about `CHECKOUT_PURCHASE_UX_AUDIT_2026`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [CHECKOUT_PURCHASE_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md) — it records what is around that document (why it exists, how far it can be trusted, when to retire it), not the audit findings themselves.

## Subject

- **Document:** [CHECKOUT_PURCHASE_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md)
- **Purpose:** a code-grounded, document-only UX audit of the course-purchase flow (tariff selection to first access), enumerating friction points and copy/routing recommendations without touching money or access logic.
- **Audience:** the platform owner and any engineer or copywriter picking up the checkout template/copy tickets; a product reviewer weighing conversion priorities.
- **Format / contract:** a point-in-time audit narrative — north star, as-coded journey, numbered friction points (F1–F10), recommended screen hierarchy, copy recommendations, implementation tickets, acceptance metrics. Findings are claims about the code as read on the audit date, not applied changes.

## Provenance

- **Subject created:** 08-07-2026 (first commit adding the file).
- **Metadoc authored:** 13-07-2026, handoff H891 (metadoc sweep III), Opus 4.8 `claude-opus-4-8`.
- **Next hardening:** re-verify each F-point and ticket against the live Blade/controller code, then flip the Deprecation status as tickets ship.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Add a per-ticket status column (shipped / open / dropped) tracking the eight implementation tickets against merged PRs | The audit is a snapshot; without status tracking it silently rots as fixes land | parked (needs PR-to-ticket reconciliation) |
| 2 | Attach the code-reference commit SHA the audit was grounded against | Findings cite Blade/controller files that move; a pinned SHA makes staleness detectable | parked (SHA not recorded in subject) |
| 3 | Wire the acceptance metrics (§7) to real funnel instrumentation the audit notes is absent | Metrics are unmeasurable until the page-view to paid funnel log exists | parked (instrumentation is a separate build) |
| 4 | Cross-check F9 / ticket 8 against the current state of the Phase 2 bundle-checkout spec | F9 depends on Phase 2 scope that may have shifted since 08-07 | parked (depends on debtor Phase 2 progress) |
| 5 | Add before/after conversion baseline once any ticket ships | No baseline means no way to prove a fix helped | parked (waits on ticket 1 landing) |

## Known limitations / caveats

- **Snapshot, not a live tracker.** Every finding reflects the code as read on 08-07-2026; a merged copy or routing fix does not update this document.
- **Document-only mandate.** The audit deliberately touches no money or access logic; recommendations are template/copy/routing-target changes and remain proposals until separately implemented.
- **No instrumentation baseline.** §7 explicitly notes no funnel logging was found, so none of the tickets can yet be measured against a real conversion baseline.
- **Code references are unpinned.** File names (`CheckoutController`, `PaymentController`, the Blade partials) are cited without a commit SHA, so drift between the audit and current code is not automatically visible.

## Intended use / known misuse

- **Intended:** a prioritization and copy-spec source for the checkout tickets; a shared reference for what the buyer is told and when.
- **Misuse:** treating it as a record of shipped changes (it is a set of proposals), or acting on a friction point without re-reading the current Blade/controller code, which may already have moved since 08-07-2026.

## Maintenance & sunset plan

- **Trigger to revisit:** any checkout Blade/controller change, any of the eight tickets landing, or the debtor Phase 2 bundle-checkout shipping.
- **Owner:** whoever holds the Systema-Sanscriticum checkout surface.
- **Sunset:** when all actionable tickets (1–8) are resolved and re-verified against live code, mark the subject `superseded` (by the shipped changes) or `retired`, and keep this metadoc as the audit's provenance record.

## Deprecation status

**active** — the friction points and tickets remain open proposals with no confirmed shipped fixes recorded; the audit still describes live behavior.

## Related documents

- [CHECKOUT_PURCHASE_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md) — the subject.
- [debtor-self-service-spec.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtor-self-service-spec.md) — the parallel debtor entry point the audit references (F8, F9).

## Revision history

| Date | Change | Model |
|---|---|---|
| 13-07-2026 | metadoc created (H891) | Opus 4.8 claude-opus-4-8 |

_Dr. Mārcis Gasūns_
