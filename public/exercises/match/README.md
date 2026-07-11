# Match-the-pairs exercise engine

_Created: 11-07-2026 · Last updated: 11-07-2026_

Interactive "connect each item on the left to its partner on the right" drills
for Sanskrit learners, served as static files from `public/exercises/match/`. No
build step, no framework, no external network — the engine is one CSS + one JS
file and works offline and inside an `<iframe>`.

A remake of the LearningApps exercise
[psatvg8jk25](https://learningapps.org/display?v=psatvg8jk25) ("Найди пару для
глагола", LearningApps tool 71 · matching pairs), generalised into a reusable
engine. Sibling to the
[sort-into-groups engine](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/sort/README.md) —
it shares the same palm-leaf theme tokens, accessibility model, and offline
guarantees, but is a **different engine type** (matching, not grouping).

## Contents

| Path | What it is |
|---|---|
| [`engine.css`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/match/engine.css) | Shared styles — palm-leaf theme, light + dark, per-link colour badges |
| [`engine.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/match/engine.js) | The engine — `MatchExercise.mount(container, config)` |
| [`index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/match/index.html) | Catalogue landing page for the match family |
| [`verb-roots/index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/match/verb-roots/index.html) | Drill: 11 verbal roots ↔ their 3rd-person-singular present forms |

## URLs (once deployed)

- Top-level exercises catalogue — `/exercises/`
- Match catalogue — `/exercises/match/`
- Verb-roots drill — `/exercises/match/verb-roots/`

## Data provenance (MEGABOOK §2.9)

Every root in the `verb-roots` drill is anchored to a verified research source
(mechanically checked 11-07-2026, H712): Whitney, *The Roots, Verb-forms and
Primary Derivatives of the Sanskrit Language* via the live
[whitney-roots reader](https://samskrtam.ru/whitney-roots/) (all links below
returned HTTP 200 on 11-07-2026), cross-checked against the Monier-Williams
headword list
[`MW-unique-key1-194084.txt`](https://github.com/gasyoun/SanskritLexicography/blob/master/HeadwordLists/now-2026/MW-unique-key1-194084.txt)
(SLP1, Cologne CDSL 2026 snapshot). Layout/pedagogy source: the LearningApps
original named above.

| Root (drill) | Whitney | MW key1 (SLP1) |
|---|---|---|
| sphur- | [root_sphur.html](https://samskrtam.ru/whitney-roots/root_sphur.html) | `sPur` |
| paṭh- | [root_pa_th.html](https://samskrtam.ru/whitney-roots/root_pa_th.html) | `paW` |
| gam- | [root_gam.html](https://samskrtam.ru/whitney-roots/root_gam.html) | `gam` |
| vikas- (vi + kas) | [root_kas.html](https://samskrtam.ru/whitney-roots/root_kas.html) | `vikas` |
| las- | [root_las.html](https://samskrtam.ru/whitney-roots/root_las.html) | `las` |
| mlā- | [root_mlaa.html](https://samskrtam.ru/whitney-roots/root_mlaa.html) | `mlAta`/`mlAna` family (MW lemma *mlai*) |
| puṣp- | not a Whitney root — denominative present *puṣpyati* | `puzpya` (denominative of `puzpa`) |
| vad- | [root_vad.html](https://samskrtam.ru/whitney-roots/root_vad.html) | `vad` |
| phal- | [root_1_phal.html](https://samskrtam.ru/whitney-roots/root_1_phal.html) | `Pal` |
| sthā- | [root_sthaa.html](https://samskrtam.ru/whitney-roots/root_sthaa.html) | `sTA` |
| kṛ- | [root_1_k_r.html](https://samskrtam.ru/whitney-roots/root_1_k_r.html) | `kf` |

Full audit: [`docs/CONTENT_PROVENANCE_AUDIT_07.2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/develop/docs/CONTENT_PROVENANCE_AUDIT_07.2026.md).

## Authoring a new drill by hand

Create `public/exercises/match/<slug>/index.html`:

```html
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My drill</title>
  <link rel="stylesheet" href="../engine.css">
</head>
<body>
  <div class="page" style="max-width:1000px;margin:0 auto;padding:28px 20px"><div id="app"></div></div>
  <script src="../engine.js"></script>
  <script>
    MatchExercise.mount(document.getElementById("app"), {
      task: "Найдите пару для каждого элемента.",
      feedback: "Задание решено верно.",
      leftTitle: "Формы",     // optional column headings
      rightTitle: "Корни",
      perRound: 8,             // optional: sample N pairs each round (omit = all)
      shuffle: true,           // optional (default true)
      pairs: [
        { left: { text: "गच्छति" }, right: { text: "gam-", hint: "идти" } },
        { left: { text: "करोति" },  right: { text: "kṛ-",  hint: "делать" } }
        // 2+ pairs
      ]
    });
  </script>
</body>
</html>
```

**Config fields**

- `task` — instruction line above the board (optional).
- `feedback` — message shown when every pair is correct.
- `leftTitle` / `rightTitle` — column headings (default "Слева" / "Справа").
- `perRound` — if set, each round samples a random N pairs; "Заново" reshuffles the sample.
- `shuffle` — shuffle card order within each column (default `true`); the two columns are always shuffled independently.
- `pairs[]` — `{ left: {text, hint?}, right: {text, hint?} }`. Correct pairing is **positional**: `pairs[i].left` matches `pairs[i].right`. A `hint` on either side shows under that card when hints are toggled on. Left-column faces render in Devanagari, right-column faces in italic serif (IAST).

## Interaction & behaviour

- **Connect:** click a card, then click its partner in the other column — a
  coloured, numbered badge links them. Also drag one card onto a card in the
  opposite column. Dropping onto an already-linked card re-links it.
- **Unlink:** click a linked card to break its connection.
- **Keyboard:** Tab to a card, Enter/Space to select, Tab to its partner,
  Enter/Space to connect (Enter/Space on a linked card unlinks).
- Per-pair ✓/✕ marking on **Проверить**; score line ("Соединено N / M", then
  "Верно K / M"); **Заново** reshuffles both columns.
- Light and dark themes, honouring OS preference and an explicit `data-theme`
  toggle on `:root`.
- `prefers-reduced-motion` respected.

_Dr. Mārcis Gasūns_
