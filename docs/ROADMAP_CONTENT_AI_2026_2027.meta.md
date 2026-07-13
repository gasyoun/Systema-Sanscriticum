# ROADMAP_CONTENT_AI_2026_2027.meta.md — metadoc about `ROADMAP_CONTENT_AI_2026_2027`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [`docs/ROADMAP_CONTENT_AI_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_CONTENT_AI_2026_2027.md) — it records what surrounds that roadmap (why it exists, who reads it, what is still open, how it evolves), not what the roadmap itself says.

## Subject

- **Document:** [`docs/ROADMAP_CONTENT_AI_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_CONTENT_AI_2026_2027.md)
- **Purpose:** Scope the Postmypost-inspired content-ops slice of Systema — public social ingestion (VK/Instagram comments, story replies), 2–3-person claim routing, and a weekly AI content-gap pipeline (FAQ + social-post drafts with one-click publish) — as in-place Systema tickets, not a standalone product.
- **Audience:** MG (product owner) and the agents/curators implementing the CAI1–CAI10 tickets; secondary readers are anyone touching the sibling support-automation or general roadmaps who needs to see where the Content stream forks off.
- **Format/contract:** Wave-structured roadmap — locked decision log (§2, "do not re-litigate"), non-goals (§3), CAI-numbered tickets with effort/reuse/steps/success-metric (§4), quarterly layout (§5), risk table (§6), wiring to the handoff and GTD (§7).

## Provenance

- **Subject created:** 07-07-2026.
- **Metadoc authored:** 13-07-2026 (H887, Opus 4.8 `claude-opus-4-8`).
- **Next hardening:** none planned — refresh when Wave 1 (CAI1–CAI3) lands or a §2 decision is reopened.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Track CAI-ticket status against the H310 Wave-1 handoff (which of CAI1/CAI2/CAI3 shipped) | The roadmap lists tickets but never reflects delivery; a stale roadmap misleads the next session | open — owned by [`H310`](https://github.com/gasyoun/Uprava/blob/main/handoffs/H310-Sonnet_Systema-Sanscriticum_content_ops_inbox_wave1_07.07.26.md) (Wave-1 execution) |
| 2 | Confirm the S2 (support-automation) dependency for CAI3 is actually built before CAI3 starts | CAI3 reads S2's deflection output; if S2 lags, CAI3 is blocked and the quarterly layout slips | parked (blocked on the SUPPORT_AUTOMATION roadmap's S2 status, not owned here) |
| 3 | Resolve the X0 deploy-gate (prod migration credentials) that 🔒-marks CAI1/CAI2 | Every migration-bearing ticket waits on it; it is the single largest schedule risk | parked (X0 is a Systema-wide human blocker tracked in `ROADMAP_2026_2027.md`) |
| 4 | Verify VK `wall.post` scope + Telegram channel-admin status before CAI7 | The roadmap flags these as unverified assumptions; building on a wrong scope wastes a whole L-effort ticket | parked (human credential/scope check, deferred to Wave 3) |
| 5 | Add measurable acceptance-rate targets to the §6 LLM-cost risk row | "bounded volume" is asserted, not numbered; a cap threshold would make the risk falsifiable | parked (needs real CAI5/CAI6 usage data before a number is meaningful) |

## Known limitations / caveats

- **Scope is deliberately narrow.** This doc owns only the content-ops/public-channel slice; support-DM deflection lives in the sibling SUPPORT_AUTOMATION roadmap and the general C-stream lives in ROADMAP_2026_2027 — reading it alone gives a partial picture.
- **Not ground truth on the code.** It describes what *should* be built; `docs/support-subsystem-map.md` is authoritative on what actually exists. Reuse claims (existing `VkController`, `SupportConversation.status`, `SupportAiService`) are as of 07-07-2026 and can drift.
- **Staleness risk:** ticket delivery is not reflected back into the doc, and unverified assumptions (VK posting scope, Telegram channel-admin) are called out but not yet resolved. Cross-dependencies (S2) sit in another document whose status this file does not mirror.

## Intended use / known misuse

- **For:** deciding what to build next in the content-ops slice, in what order, reusing which existing Systema assets — and for keeping locked decisions from being re-argued each session.
- **Misuse:** treating it as the definition of what is *already implemented* (it is a plan, not a status report); re-litigating the eight §2 decisions or re-proposing a §3 non-goal (standalone product, full omnichannel, public AI autopilot, skill-based routing); starting the Meta/Instagram app review early (explicitly ordered after Wave 1–2); or building CAI3 before its S2 dependency exists.

## Maintenance & sunset plan

- **Kept alive by:** the session that ships each CAI wave — flip delivered tickets and update the quarterly layout in the same pass; MG reopens §2 only by explicit decision.
- **Archived/ended looks like:** all of CAI1–CAI10 shipped (or explicitly dropped), the content-ops slice folded into steady-state operation, and this roadmap marked superseded by whatever measurement doc CAI9/CAI10 produce — at which point move it to an `archive/` sibling and point the general roadmap's specialized-roadmaps table at its successor.

## Deprecation status

`active`

## Related documents

- [`docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md) — sibling roadmap owning support-DM deflection (S2 is CAI3's upstream dependency)
- [`docs/ROADMAP_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md) — general roadmap whose C (Content) stream this extends; owns the X0 deploy gate
- [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) — ground truth on the actual support/inbox code
- [`docs/jivo.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md) — product framing that seeded the aggregation-scope decisions
- [`Uprava/handoffs/H310-Sonnet_Systema-Sanscriticum_content_ops_inbox_wave1_07.07.26.md`](https://github.com/gasyoun/Uprava/blob/main/handoffs/H310-Sonnet_Systema-Sanscriticum_content_ops_inbox_wave1_07.07.26.md) — Wave-1 execution handoff
- [`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md) — human `@DO` actions mirrored from §7

## Revision history

| Date | Event | Who |
|---|---|---|
| 13-07-2026 | metadoc created (H887) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
