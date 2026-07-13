# SRS_ROADMAP_2026.meta.md — metadoc about `SRS_ROADMAP_2026`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for the SRS flashcards roadmap [SRS_ROADMAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SRS_ROADMAP_2026.md) — captures what surrounds the document, not its content.

## Subject

- **Document:** [SRS_ROADMAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SRS_ROADMAP_2026.md)
- **Purpose:** Sequence the build of a native, web-only spaced-repetition ("Anki for Sanskrit & Hindi") feature inside the Systema-Sanscriticum student cabinet.
- **Audience:** Engineers implementing the waves; the product owner tracking Tier-0 sequencing; future sessions picking up the handoff chain.
- **Format/contract:** Topical roadmap subordinate to the master ROADMAP_2026_2027.md — locked architecture/sequencing rulings, sequential waves each stating what unblocks it, and an explicit non-goals list.

## Provenance

- **Subject created:** 06-07-2026.
- **Metadoc authored:** 13-07-2026 (H887, Opus 4.8 `claude-opus-4-8`).
- **Next hardening:** none planned — metadoc created as part of the H887 metadoc sweep.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Link each wave to its own H### handoff (only Wave 1 → H211 today) | Waves 2–5 have no traceable execution anchor; roadmap can't be resumed per-wave | parked (handoffs minted only as each wave starts) |
| 2 | Add per-wave status markers (planned / in-flight / shipped) | Reader cannot tell live state from the static wave list; staleness risk grows after Wave 1 lands | parked (needs periodic sync after each wave PR) |
| 3 | Record the concrete self-hosted TTS model/voice once chosen (Wave 3) | "build-time detail" defers a real decision that will need auditing later | parked (deferred until Wave 3 build) |
| 4 | Note Hindi content-sourcing prerequisite owner (Wave 4) | Wave 4 is gated on content that has no named provider | parked (blocked on upstream content decision) |

## Known limitations / caveats

- Scope is limited to the SRS feature — it does not cover the rest of the Tier-0 cabinet roadmap.
- The rulings table is a point-in-time snapshot (05-07-2026); any reversal must be re-dated, not silently edited, or the non-goals list drifts.
- Staleness risk: once Wave 1 ships, the "starts now" language and undated wave list will read as stale unless status markers are added (backlog #2).

## Intended use / known misuse

- **For:** deciding what to build next in the SRS feature, what was deliberately ruled out, and in what order waves unblock.
- **Misuse:** do not read the non-goals as permanently closed (one-way `.apkg` import is flagged as a plausible future extension); do not treat wave boundaries as hard release gates for the pilot flag, which gates student *exposure*, not the build; do not re-propose SM-2/Leitner, cloud TTS, or AnkiWeb sync — all explicitly ruled out.

## Maintenance & sunset plan

- **Kept alive by:** the session that executes each wave — flip that wave's status, mint/cite its handoff, re-date on any ruling reversal.
- **Archived/ended looks like:** all five waves shipped and general release flipped ⇒ mark the roadmap superseded by the live feature docs and move it under the master roadmap's completed section.

## Deprecation status

`active`

## Related documents

- [ROADMAP_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md) — master roadmap this sits under
- [SEO_ROADMAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_ROADMAP_2026.md) — sibling topical roadmap
- [PRANA_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/PRANA_ROADMAP.md) — sibling topical roadmap (rewards currency reused by Wave 5)
- [H211 handoff](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H211-Opus_Systema-Sanscriticum_srs_flashcards_p1_05.07.26.md) — Wave 1 execution anchor

## Revision history

| Date | Event | Who |
|---|---|---|
| 13-07-2026 | metadoc created (H887) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
