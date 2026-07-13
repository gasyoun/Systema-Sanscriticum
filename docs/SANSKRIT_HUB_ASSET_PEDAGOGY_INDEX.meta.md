# SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.meta.md — metadoc about `SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [`SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md) — records what surrounds that document (provenance, backlog, limits) without restating its content.

## Subject

- **Document:** [`SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md)
- **Purpose:** The "what exists → what we build" bridge — maps every real, existing asset to a learner rung, an NLP capability, and concrete product use-cases for the `sanskritHUB` surface.
- **Audience:** Hub product planning, learn-track designers, and NLP developers scoping the open data/API layer.
- **Format / contract:** Layered asset→pedagogy→NLP index — an eight-layer overview table, per-layer asset tables (asset ID · rung · NLP capability · invented use-case), a net-new capabilities list, and an honest coverage-gaps section. Every asset is grounded in a verified `FEATURES_INDEX.md` row.

## Provenance

- **Subject created:** 10-07-2026
- **Metadoc authored:** 13-07-2026, H890 (metadoc sweep II), Opus 4.8 `claude-opus-4-8`
- **Next hardening:** when the underlying `FEATURES_INDEX.md` asset census or the locked positioning changes, re-verify the asset IDs and rung mappings here and bump the subject's "Last updated".

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Add a stable asset-ID column check against `FEATURES_INDEX.md` on each edit | IDs (L1, G3, E27…) can drift as the census is renumbered | parked (needs FEATURES_INDEX cross-walk pass) |
| 2 | Link each "invented use-case" to its roadmap sequencing row | Reader must jump between this index and the roadmap to find build order | parked (roadmap row anchors not yet stable) |
| 3 | Flag which use-cases are shipped vs. still theoretical | Index reads as all-planned; some interfaces already exist | parked (needs ship-status audit) |
| 4 | Add a machine-readable (CSV/JSON) mirror of the asset→use-case rows | Enables dashboards and dependency checks over the mapping | parked (no consumer yet) |
| 5 | Cross-check coverage gaps against the karaoke/audio project state | Gap list can go stale as audio work lands | parked (depends on SanskritKaraoke status) |

## Known limitations / caveats

- The index is a **planning map, not a build ledger** — a row's presence means the asset exists, not that the use-case is built.
- Asset IDs and counts (44 dicts · 21 interfaces · 37 data · 14 tools) are a snapshot of `FEATURES_INDEX.md` at 10-07-2026 and go stale as that census moves.
- Rung assignments (A0–C2) are editorial judgment, not measured difficulty.
- The "invented use-cases" are aspirational product ideas, not committed scope.

## Intended use / known misuse

- **Intended:** to answer "we have asset X — what can a learner or NLP dev DO with it?" and to seed hub feature scoping grounded in real assets.
- **Misuse:** treating the invented use-cases as a delivered feature list, or citing the asset counts as current without re-checking `FEATURES_INDEX.md`.

## Maintenance & sunset plan

- Re-verify against `FEATURES_INDEX.md` whenever the asset census is republished; bump "Last updated" on any substantive edit.
- Owned alongside the roadmap and learner-progression docs; retire or fold in only if the hub positioning is abandoned.

## Deprecation status

active

## Related documents

- [`ROADMAP_SANSKRIT_HUB_NLP_2026_2028.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SANSKRIT_HUB_NLP_2026_2028.md) — the build sequence for the use-cases mapped here.
- [`SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md) — the learner ladder this index pins assets to.
- [`SANSKRIT_HUB_ARCHITECTURE.svg`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ARCHITECTURE.svg) — the layered picture behind the eight-layer table.
- [`FEATURES_INDEX.md`](https://github.com/gasyoun/SanskritLexicography/blob/master/FEATURES_INDEX.md) — the asset census every row is grounded in.

## Revision history

| Date | Change | Author |
|---|---|---|
| 13-07-2026 | metadoc created (H890) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
