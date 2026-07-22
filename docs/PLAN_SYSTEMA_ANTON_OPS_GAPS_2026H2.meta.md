# PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.meta.md

_Created: 22-07-2026 · Last updated: 22-07-2026_

Companion metadoc for [`docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md)
and its four layer docs.

## Purpose

Answer "what does Anton's yoga-school stack have that Systema-Sanscriticum lacks, and what do
we do about it?" as an execution-ready `/ask` plan. The honest finding — Systema is *ahead* of
Anton on everything but three operational capabilities — is the load-bearing insight; the plan
exists so the three genuine gaps (email campaigns, in-video resume, clip-marketing) close as
flag-gated waves without a human re-deriving the analysis.

## Audience

MG + any execution agent picking up a wave handoff. The PLAN index is what a wave handoff's
starter line points at.

## Provenance

- Source: two Anton interview transcripts (09-09-2025, YouTube `cf1N7Vh3tFE` and `vxazfvVqXvw`)
  + a full read-only repo audit (subagent, 27 tool-uses).
- Authored by **Opus 4.8 (`claude-opus-4-8`)** via the `/ask` skill; interview = 12 rulings
  across three `AskUserQuestion` rounds, 22-07-2026.
- The four waves and their flag names are the plan's own; the decisions table (D1–D12) is the
  interview record.

## Ranked improvement backlog

1. **Author IMPLEMENTATION docs for W2/W3/W4** — only Wave 1 has a file-level impl doc so far
   (roadmap-per-wave-handoff convention). Do each when its wave starts.
2. **Run spikes S1/S2** (VERIFICATION doc) before committing W1's bounce-capture shape and W2's
   RuTube/VK player-API assumption.
3. **Resolve R1 with a staged deliverability decision** — if mailbox SMTP under-delivers on
   staging, record the Postmark/Mailgun-as-relay override in the activation checklist.
4. **Fold the clip-marketing wave into `docs/ROADMAP_CONTENT_AI_2026_2027.md`** cross-links so
   the two content-ops plans don't drift.

## Limitations

- The transcripts are ~10 months old (Sep 2025); Anton's stack may have moved (he was mid-
  migration Airtable→BaseRow, Collabs→custom). The gap analysis is against the transcript state.
- Player-API details (RuTube/VK) are stated from general knowledge, flagged as spike S2 — verify
  before building the W2 adapters.
- "Homegrown mailbox SMTP" (D5/D6) is a deliberate ruling against the installed Postmark/Mailgun;
  its deliverability risk (R1) is real and the biggest single threat to the plan's value.

## Related docs

- [`docs/ROADMAP_SYSTEMA_ANTON_OPS_GAPS_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_ANTON_OPS_GAPS_2026H2.md)
- [`docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md)
- [`docs/IMPLEMENTATION_SYSTEMA_ANTON_OPS_GAPS_WAVE1.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_ANTON_OPS_GAPS_WAVE1.md)
- [`docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md)
- Adjacent content-ops roadmap: [`docs/ROADMAP_CONTENT_AI_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_CONTENT_AI_2026_2027.md)

## Revision history

| Date | Change | By |
|---|---|---|
| 22-07-2026 | Created — full `/ask` plan (5 docs + this meta) from the Anton interview | Opus 4.8 (`claude-opus-4-8`) |

_Dr. Mārcis Gasūns_
