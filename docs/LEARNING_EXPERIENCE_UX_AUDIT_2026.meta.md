# LEARNING_EXPERIENCE_UX_AUDIT_2026.meta.md — metadoc about `LEARNING_EXPERIENCE_UX_AUDIT_2026`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [`docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md) — records what is around the audit (its purpose, provenance, and improvement backlog), not what is inside it.

## Subject

- **Document:** [`docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md)
- **Purpose:** A code-grounded UX audit of the in-course learning loop (course home and lesson page) once a student already has access — capturing gaps and a prioritized, document-only ticket list.
- **Audience:** Product/UX owner and the Blade/Laravel developer who will implement the tickets; the platform maintainer setting learning-loop priorities.
- **Format / contract:** English Markdown audit. Sections: north star, course-home findings (C1–C5), lesson-page findings (L1–L6), recorded-course mode, prioritized tickets (1–9 with effort), acceptance metrics. Every finding is anchored to a concrete file and line range. Explicitly document-only — proposes no Blade/code changes.

## Provenance

- **Subject created:** 09-07-2026
- **Metadoc authored:** 13-07-2026, H891, Opus 4.8 `claude-opus-4-8`
- **Next hardening:** On the first implemented ticket, flip the affected backlog row to done and add a revision-history row; if all nine tickets ship, move Deprecation status toward `superseded`.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Cross-link each finding to its GitHub issue/PR once tickets 1–9 are filed | The audit lists tickets but no tracking IDs; a reader cannot see implementation state | parked (no issues filed yet) |
| 2 | Add a summary status column to the prioritized-tickets list (open/shipped) | Lets a returning reader tell delivered work from backlog at a glance | parked (awaiting first shipped ticket) |
| 3 | Verify the cited line ranges against current HEAD periodically | Blade line numbers drift as views are edited, silently rotting anchors | parked (revalidate before implementation) |
| 4 | Note quantified baselines for the four acceptance metrics once measured | §6 names metrics but has no current numbers to move against | parked (instrumentation for play-rate not present) |
| 5 | Link the twin CHECKOUT_PURCHASE_UX_AUDIT_2026 and any Self-Service Support audit reciprocally | Findings reference those audits; back-references aid navigation | parked (confirm sibling audit filenames) |

## Known limitations / caveats

- Point-in-time snapshot: findings and the cited file/line references reflect the repository state on 09-07-2026 and will drift as the Blade views and controllers change.
- Document-only by mandate — nothing here has been implemented or verified against a running build; tickets are proposals, not shipped changes.
- Effort estimates ("trivial/small/medium") are the author's judgment, not measured.
- Acceptance metrics §6 mixes already-available instrumentation with metrics (e.g. recording play-rate) that are explicitly not instrumented today.

## Intended use / known misuse

- **Intended:** Drive a prioritized implementation pass on the in-course learning loop; feed the Self-Service Support audit its "why is this locked / what would it cost" inputs.
- **Misuse:** Treating the ticket list as done work, or trusting the cited line numbers without re-checking them against current HEAD before editing.

## Maintenance & sunset plan

- **Owner:** Systema-Sanscriticum product/UX maintainer.
- **Cadence:** Revisit when any of the audited views (course, lesson, homework partials) or `StudentController` change materially, and when a ticket ships.
- **Sunset:** Retire once all nine tickets are implemented and the learning loop is re-audited by a successor document; until then the audit stays live as the backlog of record.

## Deprecation status

`active`

## Related documents

- [`docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md) — the subject
- [`docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md) — twin audit (H297), ends where this one begins
- [`docs/student-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) — student-facing manual referenced throughout the audit

## Revision history

| Date | Change | Model |
|---|---|---|
| 13-07-2026 | metadoc created (H891) | Opus 4.8 claude-opus-4-8 |

_Dr. Mārcis Gasūns_
