# TECHNICAL_ANALYTICS_UX_AUDIT_2026.meta.md — metadoc about `TECHNICAL_ANALYTICS_UX_AUDIT_2026`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [`TECHNICAL_ANALYTICS_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TECHNICAL_ANALYTICS_UX_AUDIT_2026.md) — records what surrounds that audit (provenance, backlog, limits) without restating its findings.

## Subject

- **Document:** [`TECHNICAL_ANALYTICS_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TECHNICAL_ANALYTICS_UX_AUDIT_2026.md)
- **Purpose:** Code-grounded audit of the analytics instrumentation, event taxonomy, component readiness, and performance risks behind the cabinet/store UX roadmap, ending in an ordered set of implementation tickets.
- **Audience:** Engineers wiring analytics into the Systema-Sanscriticum Laravel/Blade app; the product owner sequencing the cabinet/checkout UX work.
- **Format/contract:** Advisory engineering audit. Every claim cites a real file/path in the repo; recommendations only, no code shipped. One of a four-doc UX-audit queue plus a content audit.

## Provenance

- **Subject created:** 09-07-2026 (git first-add).
- **Metadoc authored:** 13-07-2026, handoff H891 (metadoc sweep III), Opus 4.8 `claude-opus-4-8`.
- **Next hardening:** re-verify the file/line citations against the live tree the next time the audit is consulted (Blade layouts and route line numbers drift), then flip backlog rows as tickets ship.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Convert §6 tickets into tracked issues with H### / owner references | The audit lists 7 tickets but nothing binds them to executable work items | parked (no issue tracker rows minted yet) |
| 2 | Re-confirm cited line numbers (e.g. `routes/web.php` line 235) on each read | Line-anchored citations rot as the codebase moves | parked (needs live-tree pass) |
| 3 | Add a status column to the §3 taxonomy table (wired / partial / not-fired) | Reader cannot tell at a glance which events are live vs planned | parked (author call) |
| 4 | Cross-link the reconciled `vitrina.md` taxonomy decision into that file | §3 declares `vitrina.md` superseded but the file itself is not annotated | parked (touches sibling doc) |
| 5 | Record which tickets have shipped since 09-07-2026 | Backlog freshness; avoids re-recommending done work | parked (needs status sweep) |

## Known limitations / caveats

- Snapshot of the repo at 09-07-2026; file paths, route line numbers, and the "22 inert `data-analytics` markers" count are point-in-time and drift with the codebase.
- Advisory only — no instrumentation was implemented; the audit asserts none of its tickets were shipped.
- Scope is analytics/UX-readiness, not a full security or payment-correctness review (it explicitly defers to the existing payment/access test suites).
- Findings depend on grep-negative evidence ("zero hits" for tracking on checkout/student layouts); a false-negative in the search would weaken the central gap claim.

## Intended use / known misuse

- **Intended:** as the sequencing reference when wiring analytics into checkout and the student cabinet, and as the canonical source for the event taxonomy and naming rules.
- **Misuse:** treating the ticket list as already-implemented state, copying cited line numbers without re-verifying them, or introducing a second event-naming convention the audit explicitly warns against.

## Maintenance & sunset plan

Refresh when any §6 ticket ships (flip the taxonomy row, note the shipped ticket), or when the Blade layout / routing structure changes enough to invalidate a citation. Sunset once all seven tickets are implemented and the instrumentation is covered by feature tests — at which point this audit becomes a historical record and should be marked `superseded` by the live analytics documentation.

## Deprecation status

active

## Related documents

- [`STUDENT_CABINET_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_UX_AUDIT_2026.md)
- [`CHECKOUT_PURCHASE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md)
- [`SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md)
- [`MANUALS_TO_UI_CONTENT_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUALS_TO_UI_CONTENT_AUDIT_2026.md)
- [`vitrina.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/vitrina.md)
- [`support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md)

## Revision history

| Date | Change | Model |
|---|---|---|
| 13-07-2026 | metadoc created (H891) | Opus 4.8 claude-opus-4-8 |

_Dr. Mārcis Gasūns_
