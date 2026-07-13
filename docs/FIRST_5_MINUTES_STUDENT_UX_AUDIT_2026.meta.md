# FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.meta.md — metadoc about `FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md) — it records what surrounds the audit (why it exists, how to consume it, where it can mislead), not the audit's findings themselves.

## Subject

- **Document link:** [FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md)
- **Purpose:** A document-only UX audit of the student cabinet's first-screen activation path after login, produced in the wake of PR #353 (the "continue learning" block). It diagnoses the overlap between the onboarding checklist and the new Continue Learning card, proposes a reordered first-5-minutes path, and enumerates implementation tickets (T1–T6) plus four activation metrics.
- **Audience:** Product/UX decision-makers and Laravel developers working the student cabinet; whoever schedules the follow-up implementation tickets.
- **Format/contract:** Narrative audit with numbered sections (1–6), Markdown tables for the onboarding-step model and metrics, and an acceptance-criteria-bearing ticket list. No code was changed by the audit; it is analysis feeding future tickets.

## Provenance

- **Subject created:** 09-07-2026.
- **Metadoc authored:** 13-07-2026, H891 (metadoc sweep III), Opus 4.8 `claude-opus-4-8`.
- **Next hardening:** on the first of T1–T6 landing as a PR, flip the matching backlog row and record the shipped commit; re-check whether §2's render-order diagnosis still matches `dashboard.blade.php` before acting on any later ticket.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Cross-link each ticket (T1–T6) to a tracked GitHub issue once opened | The audit is a floating doc; without issue IDs the tickets can silently rot as the code drifts | parked (no issues cut yet) |
| 2 | Resolve the T4 `@DECIDE` on what "content complete" means per course | T4 is explicitly blocked on a human decision; everything downstream of the cabinet-banner change waits on it | parked (awaiting human decision) |
| 3 | Add a "verified against commit SHA" line pinning the `dashboard.blade.php` render order the audit assumes | §2/§3 read a live Blade file top-to-bottom; the diagnosis silently expires when that file changes | parked (no baseline SHA recorded) |
| 4 | Note post-implementation which tickets shipped and which were dropped | Reader cannot tell the audit's live recommendations from already-actioned ones | parked (no tickets shipped yet) |
| 5 | Confirm the `student.course` vs `student.lesson` link depth claim in §3 against current routes | The competition diagnosis rests on one link being "one click deeper"; route changes invalidate it | parked (unverified against current routing) |

## Known limitations / caveats

- The audit is a **point-in-time reading of live source** (`dashboard.blade.php`, `OnboardingChecklist.php`, `StudentController.php`, the onboarding partial). Any of those files moving invalidates the §2 render order, the §3 overlap claim, or the line numbers cited (e.g. `StudentController.php:248`) without warning.
- It is **document-only** — no code changed, no ticket was opened in a tracker, so nothing enforces that the recommendations get built or stay accurate.
- T4 is explicitly **not fully specified** (an open `@DECIDE` on per-course completeness); the audit hands that back to a human rather than resolving it.
- Metrics in §6 assume existing tables suffice; the bot-connect metric admits a missing timestamp column, so "time-to-connect" is not measurable today without a migration.

## Intended use / known misuse

- **Intended:** as the design rationale a developer reads before implementing T1–T6, and as the reference for what "activated in the first five minutes" is defined to mean.
- **Misuse:** treating the section-2 render order or the cited line numbers as current without re-reading the Blade/controller source; treating the ticket list as a shipped changelog (it is a proposal, not a record of done work); citing the §6 metrics as if the activation-funnel report already exists (T6 is unbuilt aggregation).

## Maintenance & sunset plan

- **Review trigger:** any edit to `resources/views/student/dashboard.blade.php`, `app/Support/OnboardingChecklist.php`, or `StudentController::buildContinueLearningAction()`, and on each of T1–T6 landing.
- **Owner action:** when a ticket ships, tick the matching backlog row here and add a revision-history line; when the dashboard is reordered, re-verify §2 or mark the audit superseded.
- **Sunset:** once all of T1–T6 are resolved (shipped or explicitly dropped) and the reordered first-5-minutes path is live, retire this audit to a "shipped rationale" note and flip Deprecation status to `superseded`.

## Deprecation status

`active` — no ticket from the audit has shipped yet; the diagnosis and proposals are still the live plan.

## Related documents

- [FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md) — the subject.
- [onboarding-student.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md) — the short student-facing onboarding doc the audit says should be the only doc a student needs.
- [student-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) — the developer/curator reference the audit contrasts against.

## Revision history

| Date | Change | Model |
|---|---|---|
| 13-07-2026 | metadoc created (H891) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
