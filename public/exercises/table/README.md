# Table (grid-fill) exercise engine

_Created: 26-07-2026 · Last updated: 26-07-2026_

Interactive "place each form into the right cell of a paradigm table" drills
for Sanskrit learners, served as static files from `public/exercises/table/`.
No build step, no framework, no external network — one CSS + one JS file,
offline and iframe-safe.

Originally a remake of LearningApps tool **270** (table / grid fill), e.g.
[pibs0sxdj25](https://learningapps.org/display?v=pibs0sxdj25) and
[phhtmewp325](https://learningapps.org/display?v=phhtmewp325). Sibling to
[`sort/`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/sort/README.md),
[`match/`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/match/README.md),
[`cloze/`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/cloze/README.md).
Palm-leaf theme tokens lifted from `sort/engine.css`.

## Contents

| Path | What it is |
|---|---|
| [`engine.css`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/table/engine.css) | Shared styles — palm-leaf theme, light + dark |
| [`engine.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/table/engine.js) | The engine — `TableExercise.mount(container, config)` |
| [`index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/table/index.html) | Catalogue landing page |
| [`verb-conjugation-grid/`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/table/verb-conjugation-grid/index.html) | Present-tense grid: 4 verbs × person/number |
| [`masc-i-nominative/`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/table/masc-i-nominative/index.html) | Nominative of masc. -i stems (sg/du/pl) |

## Authoring

```js
TableExercise.mount(document.getElementById("app"), {
  task: "Заполните таблицу.",
  feedback: "Здорово, ты верно выполнил задание.",
  fixedRows: 1,   // top row = headers
  fixedCols: 1,   // left column = row labels
  cells: [
    ["Глагол", "идти", "читать"],
    ["अहम्", "गच्छामि", "पठामि"],
    ["त्वम्", "गच्छसि", "पठसि"]
  ]
});
```

Header cells stay fixed. Answer cells start empty; correct values become a
shuffled card pool. Place by click-card-then-cell or drag-and-drop.

## Porting more tables

Use [`/learningapps-port`](https://github.com/gasyoun/claude-config/blob/main/commands/learningapps-port.md)
and decode `https://learningapps.org/data?jsonp=1&id=<id>` (tool 270).

_Dr. Mārcis Gasūns_
