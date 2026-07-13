# SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.meta.md — metadoc about `SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [`SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md) — records what surrounds the document (why it exists, who maintains it, how it should age), not what it says.

## Subject

- **Document:** [`SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md)
- **Purpose:** The pedagogy spine for the Sanskrit-HUB — a CEFR-shaped A0→C2 ladder where each rung is bound to a concrete existing asset (or a named gap), tying the paid courses and the free NLP layer to real capabilities.
- **Audience:** Course designers, the NLP/product team building HUB surfaces, and custdev/marketing deciding what a cohort can be sold at each level.
- **Format / contract:** Narrative Markdown with a glance table plus one section per rung (A0–C2); asset IDs are pointers that resolve against the asset-pedagogy index and the org FEATURES_INDEX, not self-contained definitions.

## Provenance

- **Subject created:** 10-07-2026
- **Metadoc authored:** 13-07-2026, H890 (metadoc sweep II), Opus 4.8 `claude-opus-4-8`
- **Next hardening:** on the next substantive rung/asset edit, reconcile every asset ID against the current asset-pedagogy index and FEATURES_INDEX, and tick the backlog below.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Resolve the four `@DECIDE` pedagogy questions (placement, bridge language, certification, audio source) into decisions and fold rulings back into the ladder | The whole ladder is provisional while these forks are open; audio in particular gates A0–A2 | parked (awaits human decision) |
| 2 | Close the recurring **audio gap** flagged at A1/A2 as the single biggest cross-rung blocker | Named repeatedly as the top build; without it A1/A2 rungs are aspirational | parked (build not scheduled) |
| 3 | Add a machine-checkable audit that every asset ID (F37, H7, M11, E31, …) still resolves in the asset-pedagogy index | IDs drift silently as sibling repos rename/retire assets | parked (no linter yet) |
| 4 | Split the Aug-2026 (Cyrillic-only, read-first) vs Jan-2027 (script-writing UGC) cohort scope into an explicit per-rung availability column | Cohort deferrals are currently prose caveats, easy to miss when planning a launch | parked (pending cohort-plan freeze) |
| 5 | Attach measurable gate criteria / assessment items to each "Gate to next" cell | Gates are described qualitatively; a course needs a testable exit check | parked (design not started) |

## Known limitations / caveats

- **Pointer document, not a spec.** Every capability lives behind an asset ID; the ladder asserts a binding, not the asset's readiness — several are explicit gaps ("audio", A0 lesson content, stroke-order module).
- **Cohort-time-bound.** Written around the 28 Aug 2026 "истинно нулевая" cohort and the Jan 2027 follow-on; the A0-is-Cyrillic-only constraint is a launch decision, not a permanent pedagogy claim.
- **Open decisions unresolved.** Four `@DECIDE` items at the foot mean placement, bridge language, certification, and audio approach are not yet settled.
- **Cross-repo coupling.** Asset IDs reference many sibling repos (SanskritKaraoke, csl-atlas, VedaWeb, Whitney roots, kosha); staleness elsewhere silently invalidates a rung.

## Intended use / known misuse

- **Intended:** as the reference map from a learner level to the assets that must exist to serve it — for scoping a course, prioritising a build, or checking whether a cohort can be sold at a given rung.
- **Misuse:** treating a listed asset as shipped and learner-ready (many are gaps or partial), or reading the CEFR labels as a formal accreditation claim rather than an internal difficulty scaffold.

## Maintenance & sunset plan

- **Trigger to update:** any rung's asset set changing, an `@DECIDE` item resolving, a cohort-scope shift, or an asset ID being renamed/retired upstream.
- **Cadence:** review alongside the asset-pedagogy index; they should never disagree on an ID.
- **Sunset:** retire or fold into the HUB product spec once the four open decisions are locked and the audio gap closes — at which point the ladder stops being provisional and becomes an implemented curriculum.

## Deprecation status

`active`

## Related documents

- [`SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md) — the asset↔pedagogy index every rung's IDs resolve against.
- [`ROADMAP_SANSKRIT_HUB_NLP_2026_2028.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SANSKRIT_HUB_NLP_2026_2028.md) — the NLP-layer roadmap that supplies the free-tier machinery under these rungs.
- [`FEATURES_INDEX.md`](https://github.com/gasyoun/SanskritLexicography/blob/master/FEATURES_INDEX.md) — org inventory of dicts/interfaces/datasets/tools the asset IDs ultimately point into.
- [`CUSTDEV_2026.md`](https://github.com/gasyoun/Uprava/blob/main/custdev/CUSTDEV_2026.md) — custdev source for the TIME-objection design constraint.

## Revision history

| Date | Change | Model |
|---|---|---|
| 13-07-2026 | metadoc created (H890) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
