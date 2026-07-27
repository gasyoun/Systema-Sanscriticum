# Match-the-pairs exercise engine

_Created: 11-07-2026 · Last updated: 11-07-2026_

Interactive "connect each item on the left to its partner on the right" drills
for Sanskrit learners, served as static files from `public/lila/match/`. No
build step, no framework, no external network — the engine is one CSS + one JS
file and works offline and inside an `<iframe>`.

A remake of the LearningApps exercise
[psatvg8jk25](https://learningapps.org/display?v=psatvg8jk25) ("Найди пару для
глагола", LearningApps tool 71 · matching pairs), generalised into a reusable
engine. Sibling to the
[sort-into-groups engine](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/sort/README.md) —
it shares the same palm-leaf theme tokens, accessibility model, and offline
guarantees, but is a **different engine type** (matching, not grouping).

## Contents

| Path | What it is |
|---|---|
| [`engine.css`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/match/engine.css) | Shared styles — palm-leaf theme, light + dark, per-link colour badges |
| [`engine.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/match/engine.js) | The engine — `MatchExercise.mount(container, config)` |
| [`index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/match/index.html) | Catalogue landing page for the match family |
| [`verb-roots/index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/match/verb-roots/index.html) | Drill: 11 verbal roots ↔ their 3rd-person-singular present forms |

## URLs (once deployed)

- Top-level exercises catalogue — `/lila/`
- Match catalogue — `/lila/match/`
- Verb-roots drill — `/lila/match/verb-roots/`

## Authoring a new drill by hand

Create `public/lila/match/<slug>/index.html`:

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
