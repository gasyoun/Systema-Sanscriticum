# GOOGLE_CALENDAR_INTEGRATION_ROADMAP.meta.md — metadoc about `GOOGLE_CALENDAR_INTEGRATION_ROADMAP`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion record for [GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md), the design blueprint for a two-way Google Calendar integration in Systema-Sanscriticum.

## Subject

- **Document:** [GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md)
- **Purpose:** Records the MG-locked design decisions and the phased build order for connecting the app's internal schedule to real Google Calendars (feed for students, OAuth two-way for teachers/admin).
- **Audience:** Engineers implementing the phases; MG as the decision authority on the open axes.
- **Format/contract:** A plan-and-handoff-target roadmap — status quo, locked decisions, target architecture, data model, sync mechanics, phased build table, risks, open questions. Nothing beyond Phase 1 is built.

## Provenance

- **Subject created:** 04-07-2026.
- **Metadoc authored:** 13-07-2026 (H887, Opus 4.8 `claude-opus-4-8`).
- **Next hardening:** none scheduled — reopens when Phase 2 (OAuth) is minted as a handoff or an open question in §9 is ruled.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Mint the Phase 2 handoff (OAuth connect + app→Google push) and cite it in §7 | Phase 1 is done via H153; the roadmap's next actionable phase has no owning handoff, so it is not schedulable | parked (gated on the Google `calendar`-scope app verification in §8 — external lead time before OAuth can be granted) |
| 2 | Resolve the two §9 open questions (shift rule a/b; admin master calendar) inline once MG rules | They block Phase 4 domain logic and the §4 token model; leaving them in the doc keeps the plan indeterminate | parked (awaiting MG decision interview) |
| 3 | Add an echo-suppression / clock-normalization sequence diagram to §5 | The two named failure modes (ping-pong, DST-boundary LWW) are the hardest part and a diagram would de-risk implementation | parked (best authored alongside the Phase 3 build, not before) |
| 4 | Track Google app-verification submission status in §8 with a date | It is the single external blocker on Phase 2; the doc says "start early" but records no state | parked (no submission started yet) |

## Known limitations / caveats

- **Scope is design, not build.** Only Phase 1 (the signed `.ics` feed, H153) exists in code; Phases 2–4 are unbuilt plans. Treat every architecture/data-model detail as a proposal until its phase ships.
- **Staleness risk.** The §1 "verified 04-07-2026" status quo (no Google API client, Zoom-only external time integration) drifts as the codebase changes; the locked-decisions table can be superseded by a later MG ruling that is not back-propagated here.
- Two open questions in §9 remain unanswered, so the doc is not a complete spec.

## Intended use / known misuse

- **For:** onboarding an engineer onto the calendar-integration effort, and serving as the handoff target for each phase.
- **Misuse:** reading it as a description of shipped behaviour (only Phase 1 ships); assuming the students path is two-way (it is deliberately read-only feed); starting implementation without the `/watcher-safe-commit` discipline this repo's watcher requires (§8).

## Maintenance & sunset plan

- **Kept alive by:** the engineer executing each phase — bumping the §7 phase table's status line and "Last updated" when a phase ships, and MG when an open question is ruled.
- **Sunset:** once all four phases ship and both §9 questions are closed, the doc becomes historical; mark it `retired` here and point implementers at the code + tests instead.

## Deprecation status

`active`

## Related documents

- [GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md) — the subject.

## Revision history

| Date | Event | Who |
|---|---|---|
| 13-07-2026 | metadoc created (H887) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
