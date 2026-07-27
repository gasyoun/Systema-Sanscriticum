# Root-frequency drills

_Created: 20-07-2026 · Last updated: 20-07-2026_

Match-the-pairs drills that teach Sanskrit verbal roots (dhātu) in the order they
actually occur in text — frequency-ranked, not alphabetical. Served as static
files from `public/lila/roots/`, reusing the
[match engine](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/match/README.md)
(`../match/engine.js` + `../match/engine.css`). No build step, no framework, no
external network.

Sibling to the
[ligatures family](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/ligatures/index.html)
(D6, H1281) — same top-N cumulative-band shape (top25 ⊂ top50 ⊂ top100), same
palm-leaf theme tokens. Unlike ligatures, this family's data generator is
**committed** (`scripts/build_root_drill_data.py`), closing a gap the ligatures
family left open.

## Contents

| Path | What it is |
|---|---|
| [`data.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/roots/data.js) | Generated — `global.ROOT_BANDS = {top25, top50, top100}`, never hand-edited |
| [`index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/roots/index.html) | Family landing page (band picker) |
| [`top-25/index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/roots/top-25/index.html) | Drill: the 25 most frequent roots, all shown at once |
| [`top-50/index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/roots/top-50/index.html) | Drill: the 50 most frequent roots, random 10-per-round |
| [`top-100/index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/roots/top-100/index.html) | Drill: the 100 most frequent roots, random 10-per-round |
| [`../../../scripts/build_root_drill_data.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/build_root_drill_data.py) | Generator — fixture → `data.js`, `--check` mode for drift detection |

## Data source

`database/seeders/data/roots_frequency_ru.tsv` (H1280) — 570 Sanskrit roots
joined from kosha's DCS token-frequency table against WhitneyRoots' RU-gloss
crosswalk. Rank 1 = most frequent root by corpus token count. The same fixture
already ships as the D4 SRS deck (`SrsRootFrequencyDeckSeeder`) for logged-in
students; this family gives every visitor a free, no-login surface over the
same data.

Regenerate after the fixture changes:

```sh
python scripts/build_root_drill_data.py          # writes data.js
python scripts/build_root_drill_data.py --check   # verifies data.js is current, exits 1 on drift
```

## URLs (once deployed)

- Top-level exercises catalogue — `/lila/`
- Root-drills catalogue — `/lila/roots/`
- Bands — `/lila/roots/top-25/`, `/top-50/`, `/top-100/`

## Drill shape

Each band mounts `MatchExercise` with **devanagari root ↔ its most frequent
attested form** (`top_form`), the Russian gloss (`gloss_ru`) as the hint on the
form side and the IAST transliteration as the hint on the root side:

```js
var pairs = ROOT_BANDS.top25.map(function (row) {
  return {
    left: { text: row.devanagari, hint: row.iast },
    right: { text: row.top_form, hint: row.gloss_ru }
  };
});
```

top-25 shows the full band every round; top-50/top-100 sample a random 10
(`perRound: 10`) so the board stays playable at that size — same reasoning as
the ligatures top-50/top-200 bands.

_Dr. Mārcis Gasūns_
