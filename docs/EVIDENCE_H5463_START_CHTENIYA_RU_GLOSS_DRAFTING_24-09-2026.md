# H5463 — «Старт чтения» residual RU glosses: Hitopadeśa-0 band drafted (37/465)

_Created: 24-09-2026 · Last updated: 24-09-2026_

Partial delivery of [H5463](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5463-Opus_Systema-Sanscriticum_start-chteniya-residual-465-ru-gloss-drafting_24.09.26.md)
(Opus, 🔴3 hard — draft, band-check and sheet-review Russian glosses for the 465
«Старт чтения» SRS words left after H5399). The unit was run under a ~45-minute
worker budget against a handoff scoped at ~8 h of NKRYa windows; this document
records what was drafted, what was deliberately *not* landed, and the mechanical
finding that gates every future batch.

## What was drafted

All **37 `hitopadesa-0` residual rows** — the complete pack band (34 `not_russian`
+ 3 `empty`) — are drafted in
[`resources/data/nkrya_lint/gloss_drafts_h5463.tsv`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/nkrya_lint/gloss_drafts_h5463.tsv).

Each row carries `gloss_ru_lemma` (dictionary form — the H5398 invariant), a
contextual `gloss_ru_surface` inflected to the token's own `morph`, the **sense
source per row** (MW/Apte s.v., or `seeder_deck` for in-data candidates), a
`disposition` (`land` = in-data, `sheet` = needs human approval) and a `note`
naming the sense actually chosen where the entry is polysemous.

Drafting followed the H5399 mission fence: **no blind machine-translation of the
`gloss_en` line.** Each gloss was taken from the Sanskrit lemma and its locus,
with `gloss_en` used only as a sense pointer. Three rows (`autkārṣya`,
`kuśūla`, `viśiṣṭatā`) carry **no `gloss_en` at all** in the pack and were
glossed from the lemma alone; they are marked as such.

The 428 `subhashita-beginner` rows are **not** drafted here — see the mechanical
blocker below, which has to be decided before any of them can land.

## The 5 in-data candidates: 2 applied, 3 deferred, 1 rejected

The handoff's step 1 was "apply the 5 in-data candidates first". On inspection
only two of the five are both semantically right and mechanically landable now:

| lemma | pack | candidate | source | verdict |
|---|---|---|---|---|
| `ekaika` | hitopadesa-0 | один за другим | seeder_deck | **land** — matches `gloss_en` "one by one" |
| `udyogin` | hitopadesa-0 | прилежный, энергичный | seeder_deck | **land** — matches "active; persevering; energetic" |
| `daiva` | hitopadesa-0 | божественный | seeder_deck | **rejected** — the candidate is an adjective, but the token at locus 30 is `NOUN Nom Sing Neut` meaning *fate, destiny*. Drafted instead as «судьба, рок» and routed to a sheet. |
| `dEva` | subhashita-beginner | боги | sa_ru_glossary | **deferred** — blocked on the subhāṣita pin decision below |
| `pAWi` | subhashita-beginner | разрубить | sa_ru_glossary | **deferred** — same |

Applying `божественный` to a noun token would have put a wrong gloss into a
sha256-pinned freeze on the strength of "it came from our data". Recording the
rejection is the cheaper half of that trade.

## Mechanical blocker for the 428 subhāṣita rows (the real gate)

`lemmas_for_srs.tsv` is **not an editable file** — it is *derived*. kosha's
[`lemma_rows()`](https://github.com/gasyoun/kosha/blob/main/scripts/freeze_cohort_start_chteniya.py)
rebuilds it from the two committed pack pins, so a Russian gloss can only enter
the SRS feed by being written into a **pack JSON** and the pack re-pinned. For
`hitopadesa-0` that path is proven and sanctioned — H5398 walked it
([kosha `efa27694f`](https://github.com/gasyoun/kosha/commit/efa27694f342042104cd504ed17b303c821e9280)):
edit `reading/data/hitopadesa-0.json`, copy to the pin, graft only the two
affected rows onto the committed `MANIFEST.json` via
[`scripts/refreeze_lemmas_for_srs.py`](https://github.com/gasyoun/kosha/blob/main/scripts/refreeze_lemmas_for_srs.py).

For `subhashita-beginner` it is **not**. The manifest's own `gloss_fix` note
(H5398) states that the subhāṣita pin's live source has moved since the
2026-08-01 freeze and that "re-freezing that band is a separate decision", and
the freeze `fence` reads "no human-overlay overwrite". So the 428 subhāṣita
rows — **92 % of the entire 465-row residual** — cannot land through any
currently-sanctioned mechanism, no matter how many glosses are drafted or
approved.

**This makes the H5463 acceptance bar (`empty + not_russian` ≤ 175, i.e. 290
glosses landed) unreachable as written.** The hitopadeśa band is only 37 rows;
even landing all of them leaves 428 — far above 175. The bar is gated on a
human decision about the subhāṣita pin, not on drafting throughput.

## Checks

- `awk` census of [`lemmas_gloss_residual.tsv`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/nkrya_lint/lemmas_gloss_residual.tsv):
  465 rows = 428 `subhashita-beginner`/`empty` + 34 `hitopadesa-0`/`not_russian`
  + 3 `hitopadesa-0`/`empty`. **PASS** — matches the H5399 handoff figure.
- kosha `MANIFEST.json` `lemmas-for-srs` pin `e0052c7c88f4` / 98261 bytes equals
  the live `data/cohort_start_chteniya/lemmas_for_srs.tsv`. **PASS** — the freeze
  is hash-clean, nothing was re-pinned by this pass.
- Drafts TSV: 37 data rows, one per `hitopadesa-0` residual row, no duplicates.
  **PASS**.

## Not done (named, not hidden)

1. **NKRYa band-check** (`nkrya_gloss_lint.py --measure`) was **not run** on any
   draft. At the account's ~60 calls/h even the 37-row band needs its own
   window, and the 465-row job needs ~8 h. Every `gloss_ru_lemma` in the TSV is
   therefore **unbanded** — band 3+ preference for beginners is unverified.
2. **No review sheets were cut** and nothing was published to the vote hub.
3. **Nothing was landed in kosha** — no pack edit, no re-pin, no re-vendor. The
   two `land`-dispositioned rows are staged in the TSV only.
4. The 428 subhāṣita rows are undrafted, pending the pin decision above.

## Next

A human decides the subhāṣita pin question before any further drafting is worth
doing: re-freeze the `subhashita-beginner` band from its moved source (unblocks
428 rows but re-opens the 2026-08-01 freeze), or add a sanctioned gloss-overlay
layer beside the pin (keeps the freeze intact, contradicts the current `fence`
line), or accept that the SRS feed stays at 855/1286 glossed and retire the
80 % bar. Until that is settled, the hitopadeśa band can proceed on its own:
band-check the 37 drafts in one NKRYa window, cut 4 sheets of ≤10 cards, and
land the approved rows through the proven H5398 re-pin path.

_Гасунс_
