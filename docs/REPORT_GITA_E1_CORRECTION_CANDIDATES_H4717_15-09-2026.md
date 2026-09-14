_Created: 15-09-2026 · Last updated: 15-09-2026_

# Gita inflection-QA ledger → E1 hybrid-forms correction candidates (H4717, census A12)

Crosswalk census row **A12** ([CROSSWALK_CANDIDATE_MAPPINGS_CENSUS_14-09-2026.md](https://github.com/gasyoun/Uprava/blob/main/reports/CROSSWALK_CANDIDATE_MAPPINGS_CENSUS_14-09-2026.md)):
kosha's `gita-inflection-qa` divergence ledger had exactly one named consumer candidate — this repo, as
"E1 hybrid forms layer: disputed/gap-fill corrections (human @DO)". This unit turns the ledger into a
triaged **candidate list for human review**. It writes **no correction anywhere**: kosha.db, kosha's
`pronoun_corrections.tsv` and the Gita gold master are untouched, and the `decision` column is empty.

## What was built

- [scripts/build_gita_e1_correction_candidates.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/build_gita_e1_correction_candidates.py) —
  reads the ledger ([kosha data/gita/gita_inflection_divergences.tsv](https://github.com/gasyoun/kosha/blob/main/data/gita/gita_inflection_divergences.tsv)),
  the gold morphology (for `pos`), the kosha manifest (row parity) and `kosha.db` opened `mode=ro`
  (enrichment + re-derivation parity). Same `clean()` form key as kosha's own
  [scripts/gita_inflection_qa.py](https://github.com/gasyoun/kosha/blob/main/scripts/gita_inflection_qa.py). Runs in under a second.
- [docs/evidence/gita_e1_correction_candidates_H4717.tsv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/evidence/gita_e1_correction_candidates_H4717.tsv) —
  661 rows, one per ledger row. Columns: `verse · form · lemma · gold_pos · gold_case_num_gender ·
  ledger_class · kosha_analyses · candidate_class · proposed_action · evidence · decision`.

Reproduce (inputs from kosha `origin/main`, not a possibly stale working tree):

```sh
git -C ../kosha show origin/main:data/gita/gita_inflection_divergences.tsv > /tmp/ledger.tsv
git -C ../kosha show origin/main:data/gita/gita_morphology_gold.tsv      > /tmp/gold.tsv
git -C ../kosha show origin/main:data/manifest/datasets.json             > /tmp/manifest.json
python scripts/build_gita_e1_correction_candidates.py --ledger /tmp/ledger.tsv --gold /tmp/gold.tsv --manifest /tmp/manifest.json
```

## Ledger parity (all PASS, 15-09-2026)

| Check | Result |
|---|---|
| candidate rows = ledger rows | 661 = 661 ✅ |
| (verse, form, lemma, gold cell) multiset identical | ✅ |
| DIVERGE / GAP split preserved | 73 / 588 ✅ |
| ledger rows = manifest `rows` for `gita-inflection-qa` | 661 = 661 ✅ |
| every ledger row still re-derives on the local kosha.db (GAP ⇒ form absent; DIVERGE ⇒ form present, gold case+number absent) | 0 misses ✅ |

The local kosha.db carries the hybrid sources (`curated-gita-pronoun` 153, `hybrid-natva-fix` 326,
`vidyut-gap-fill` 17 rows), so the re-derivation check runs against the same hybrid layer the
04-09-2026 re-cut used.

**Drift found (not fixed here):** the manifest `keying` text for `gita-inflection-qa` still says
"DIVERGE (360) + GAP (919)" — the pre-pronoun-fix counts. The `rows` field (661) is current; the prose is
stale. Owner: kosha manifest.

## Triage — what the 661 rows actually are

| Candidate class | Rows | What it means | Proposed action |
|---|---:|---|---|
| **G1_compound_member_covered** | 304 | compound; its final member carries the gold cell in kosha | none — compounds are out of paradigm scope |
| G0_sandhi_surface | 13 | gold form is a sandhied surface (`harṣaṁ`, `saṁnyasanād`); the pausa form carries the gold cell | none |
| G5_gold_form_defect | 11 | the gold `form` field is broken: duplicated word (`yuge yuge`), variant reading (`anādi / anādimat`), ASCII `h` for `ḥ` (`mantrah`) | fix the gold master, not the engine |
| **G2_compound_member_gap** | 135 | compound whose final member (or unhyphenated whole form) lacks the gold cell | gap-fill candidate at member level |
| **G3_lemma_absent** | 111 | lemma has no paradigm at all in `inflections` — 71 present participles in *-ant*, 8 in *-māna/-āna*, 11 numerals (`dvi tri catur daśa`), 21 other (`pathin puṁs bhrū saṁpad sant …`) | gap-fill: generate the paradigm (the `vidyut-gap-fill` route) |
| **G4_cell_absent** | 14 | lemma paradigm exists, this form is missing: *-yas* comparatives (`śreyān garīyān aṇīyāṁsam`), `vidvān`, `sakhā/sakhyuḥ`, `striyaḥ`, `bhuvi` | gap-fill: add the irregular cells |
| D1_kosha_null_cells | 9 | kosha HAS the form, but the row's case/gender is NULL (`yatataḥ` → `None.du.None`) — present participles again | fill the cell or flag `disputed=1` |
| D2_number_mismatch | 35 | gold number absent from kosha's analyses | review, split below |
| D3_case_mismatch | 29 | same number, gold case absent | review, split below |

For the 64 D2/D3 rows the `evidence` column names the **suspect side**, computed from kosha's own data:

- **suspect: gold — 37 rows.** kosha holds the form under the gold lemma and puts it in another cell;
  mostly gold mislabels on regular stems (`sukhāni` instr/pl, `lokasya` abl/sg, `vedaiḥ` instr/sg,
  `pāpebhyaḥ` gen/pl).
- **suspect: kosha — 18 rows.** the gold lemma's paradigm lacks the form and kosha only knows it under
  another stem — real engine gaps: `mahān`/`mahat` (mahant), `śrīḥ`, `divi` (div), `śuni` (śvan),
  `āpaḥ` (ap, plural-only), `santaḥ`/`sati` (sant), `ahaḥ` (ahar), `pañca`/`sapta` numerals.
- **suspect: undetermined — 9 rows.** compound lemma keyed differently in kosha (gold joins members
  without internal sandhi: `ati-indriya` vs `atIndriya`).

## The one-line reading

Of 661 ledger rows, **328 need no engine change** (304 compound-covered + 13 sandhi surface + 11 gold-form
defects), **37 point back at the gold**, and **~278 are genuine E1 hybrid-layer gap-fill candidates** —
dominated by one family: **present participles (*-ant*, *-māna*) are missing from `inflections` or sit
there with NULL case**. That single family (79 G3 + 9 D1 + several G2 members) is the highest-yield
correction for whoever acts on this list.

## Human review — who does what

Rulings go into the `decision` column (`accept` / `gold-wrong` / `skip` / free text) of the candidates TSV.
Applying any accepted row is a **separate kosha change** (a `build_db.py` stage, non-destructive
`INSERT OR IGNORE`, like `curated-gita-pronoun`), not part of this unit. Tracked as an MG `@DO` row in
[Uprava/GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md).

## Edge

Registered in [Uprava/interlinks_edges.tsv](https://github.com/gasyoun/Uprava/blob/main/interlinks_edges.tsv):
`kosha → Systema-Sanscriticum · gita-inflection-qa · consumes · live`, asset path = the builder + the
candidates TSV in this repo.

Executed by Opus 5 (claude-opus-5) as drain worker c1 under handoff tier label OxAlpha
(opencode/z-ai/glm-5.3-flash), [H4717](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4717-OxAlpha_Systema-Sanscriticum_xwalk-a12-gita-qa-e1-candidates_14.09.26.md).

_Гасунс_
