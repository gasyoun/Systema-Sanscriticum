# ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.meta.md — metadoc about `ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md), the decision-locked roadmap for the samskrte.ru student-cabinet mobile app.

## Subject

- **Document:** [ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md)
- **Purpose:** Fix the plan for shipping an Android + iOS app that wraps the existing web cabinet, waved by unblock-order and pinned to seven MG rulings.
- **Audience:** MG (human Wave-0 accounts/branding/signing/store submissions), the agent that executes Wave 1+, and any future session tempted to re-litigate the platform choice.
- **Format/contract:** Decision-locked roadmap — an audit of the reuse surface, an architecture sketch, six ordered waves, a verbatim decisions table, a non-goals list, risks, and a wiring section pointing at the Wave-1 handoff and GTD.

## Provenance

- Subject created: 12-07-2026.
- Metadoc authored: 13-07-2026 (H887, Opus 4.8 `claude-opus-4-8`).
- Next hardening: none planned — revisit when Wave 1 lands and the roadmap's "start now" claim goes stale.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Backfill the concrete Wave-1 handoff ID into § Wiring (the section still self-references instead of naming an H###) | The roadmap's own executable entry point is unresolved — a reader cannot find the handoff | parked (no owning H### cited in the subject; awaits the mint) |
| 2 | Add a wave-status column or checkbox mirror as waves complete | Reader cannot tell from the doc which waves are done vs pending | parked (nothing shipped yet; premature until Wave 1 merges) |
| 3 | Link the Uprava deploy-gate facts doc referenced in § Risks by full blob URL | § Risks names "Uprava's deploy-gate facts doc" without a link | parked (target doc path not yet confirmed) |
| 4 | Cross-check the `/api/v1` endpoint list against routes/api.php after each API PR | The audit table can drift as the mobile API grows | parked (low churn; re-verify at Wave 2) |
| 5 | Record store-account + Firebase provisioning dates once Wave 0 completes | Wave 0 is human-gated; its completion state lives only in GTD | parked (depends on MG account actions) |

## Known limitations / caveats

- **Scope:** covers only the mobile-app effort; platform-wide, GetCourse-parity, and security roadmaps are siblings, not restated here.
- **Staleness risk:** the "unblocked, start now" framing on Wave 1 and the "None" mobile-client audit row both expire the moment a Capacitor scaffold lands — the audit table is a 12-07-2026 snapshot.
- The § Wiring handoff reference is a forward pointer that was not yet a concrete ID at authoring time.

## Intended use / known misuse

- **For:** deciding what to build next in the mobile effort, and settling any "why not native / why not IAP / why email-only on iOS" question by pointing at the rulings table instead of re-arguing.
- **Misuse:** treating the non-goals as reopenable (they are ruled out — D1/D3/D5), or reading the audit table as current state months later without re-checking the repo; it is dated evidence, not a live status board.

## Maintenance & sunset plan

- **Kept alive by:** the agent executing each wave (flip the relevant claim, cite the merged PR) and MG for the human Wave-0/store items; `.ai_state.md` Next Steps points here.
- **Archived/ended looks like:** all six waves shipped (app live on Play + App Store), at which point the roadmap becomes historical and moves to an archive folder, its live status handed to GTD/CHANGELOG.

## Deprecation status

active

## Related documents

- https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md
- https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md
- https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md
- https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md

## Revision history

| Date | Event | Who |
|---|---|---|
| 13-07-2026 | metadoc created (H887) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
