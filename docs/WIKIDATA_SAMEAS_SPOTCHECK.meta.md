# WIKIDATA_SAMEAS_SPOTCHECK.meta.md — metadoc about `WIKIDATA_SAMEAS_SPOTCHECK`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [WIKIDATA_SAMEAS_SPOTCHECK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/WIKIDATA_SAMEAS_SPOTCHECK.md): it records what the spot-check report is for, where it came from, and how it should age — not the spot-check findings themselves.

## Subject

- **Document:** [WIKIDATA_SAMEAS_SPOTCHECK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/WIKIDATA_SAMEAS_SPOTCHECK.md)
- **Purpose:** Evidence log for decision D4 ("never emit an unverified `sameAs`") — a live-API spot-check demonstrating that the exact-script auto-write threshold plus the P31 denylist is safe-by-default, and that residual conceptual-disambiguation misses still require human eyes.
- **Audience:** The operator running `dictionary:match-wikidata` at deploy, and any future maintainer deciding whether the matcher's write policy can be trusted or must be re-audited.
- **Format / contract:** Point-in-time verification report — narrative framing, one dated result table (12 headwords), three proven conclusions, an operating-procedure snippet. It is a snapshot, not a spec or a living reference.

## Provenance

- **Subject created:** 08-07-2026.
- **Metadoc authored:** 13-07-2026, handoff H891 (metadoc sweep III), Opus 4.8 `claude-opus-4-8`.
- **Next hardening:** on any re-verification, produce a NEW dated spot-check rather than editing this one; link the successor from here so the snapshot chain stays legible.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Re-run the spot-check against the production DB once the FTP-only host exposes a reachable database | The original run used unsaved model instances, never the live table | parked (prod DB not reachable at authoring time) |
| 2 | Widen the sample beyond 12 hand-picked well-known headwords | 12 famous concepts under-represent long-tail homograph collisions | parked (needs a sampling pass) |
| 3 | Capture the exact `WikidataSameAsMatcher` commit / Wikidata API snapshot date | Wikidata QIDs and P31 values drift; the verdicts are only valid against that day's data | parked (no version pin recorded) |
| 4 | Record which of the flagged rows (dharma → Q9241518, saṃsāra → Jainism item) were later corrected upstream or in-app | Closes the loop on the two non-clean 1.0 candidates | parked (no follow-up tracked) |

## Known limitations / caveats

- **Dated snapshot, not a live status.** Every verdict is bound to the Wikidata state on 08-07-2026; QIDs, labels and P31 values can change, silently invalidating rows.
- **Not exercised against real data.** The run used unsaved model instances — it proves matcher behaviour, not what any `dictionary_words` row would receive.
- **Tiny, non-random sample.** 12 famous headwords chosen to expose known collision classes; not a statistical estimate of precision.
- **Conceptual-disambiguation misses are acknowledged, not solved** — P31 cannot catch concept-vs-concept errors; the report's own conclusion is that only human review does.

## Intended use / known misuse

- **Use it to:** justify the D4 safe-by-default write policy, brief an operator on what to watch for (concept vs homograph) before running `--write`, and serve as the baseline any future re-audit is compared against.
- **Do not:** treat the table as a current ground truth, cite the verdicts without re-verifying against today's Wikidata, or edit this file to "refresh" it — a refresh is a fresh spot-check with its own date.

## Maintenance & sunset plan

This document is frozen by design. It is not maintained in place. Re-verifying the matcher means running a NEW spot-check (new date, new table, ideally against the prod DB and a wider sample) and filing it as a sibling report — not editing this one. When such a successor exists, add a pointer from here and mark this snapshot superseded. No routine upkeep is owed to the frozen text.

## Deprecation status

`retired` — a dated point-in-time snapshot, complete as authored. It does not decay into "wrong", it simply stops being current; supersession is by a fresh spot-check, not by revision.

## Related documents

- [WIKIDATA_SAMEAS_SPOTCHECK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/WIKIDATA_SAMEAS_SPOTCHECK.md) — the subject.
- [WikidataSameAsMatcher.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Seo/WikidataSameAsMatcher.php) — the matcher service under test.
- [DictionaryWord.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DictionaryWord.php) — model carrying `wikidata_qid`.
- [H210 handoff](https://github.com/gasyoun/Uprava/blob/main/handoffs/H210-Opus_Systema-Sanscriticum_seo_p2_wave1_indexation_and_wikidata_matcher_05.07.26.md) — the SEO P2 Wave-1 work that introduced decision D4.

## Revision history

| Date | Change | Model |
|---|---|---|
| 13-07-2026 | metadoc created (H891) | Opus 4.8 claude-opus-4-8 |

_Dr. Mārcis Gasūns_
