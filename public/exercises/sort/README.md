# Sort-into-groups exercise engine

_Created: 10-07-2026 · Last updated: 10-07-2026_

Interactive "drag each card into the right group" drills for Sanskrit learners,
served as static files from `public/exercises/sort/`. No build step, no
framework, no external network — the engine is one CSS + one JS file and works
offline and inside an `<iframe>`.

Originally a remake of the LearningApps exercise
[pak1wixvk25](https://learningapps.org/display?v=pak1wixvk25) ("Сортировка по
родам"), generalised into a reusable engine + authoring tool.

## Contents

| Path | What it is |
|---|---|
| [`engine.css`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/sort/engine.css) | Shared styles — palm-leaf theme, light + dark, 2–8 group palettes |
| [`engine.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/sort/engine.js) | The engine — `SortExercise.mount(container, config)` |
| [`index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/sort/index.html) | Catalogue landing page |
| [`genders/index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/sort/genders/index.html) | Drill: 24 nouns sorted into masculine / feminine / neuter |
| [`generator/index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/sort/generator/index.html) | In-browser authoring tool → live preview + export |

## URLs (once deployed)

- Catalogue — `/exercises/sort/`
- Genders drill — `/exercises/sort/genders/`
- Generator — `/exercises/sort/generator/`

## Authoring a new drill by hand

Create `public/exercises/sort/<slug>/index.html`:

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
    SortExercise.mount(document.getElementById("app"), {
      task: "Распределите слова по группам.",
      feedback: "Задание выполнено верно.",
      perRound: 6,            // optional: sample N items per group each round (omit = all)
      shuffle: true,          // optional (default true)
      groups: [
        { label: "Group A", sub: "optional subtitle", image: "optional-url.png",
          items: [ { text: "अजः", hint: "ajaḥ · козёл" }, { text: "गजः" } ] },
        { label: "Group B", items: [ { text: "बाला", hint: "bālā · девочка" } ] }
        // 2–8 groups
      ]
    });
  </script>
</body>
</html>
```

**Config fields**

- `task` — instruction line above the board (optional).
- `feedback` — message shown when every card is correct.
- `perRound` — if set, each round shows a random N items per group; "Заново" reshuffles the sample.
- `shuffle` — shuffle card order (default `true`).
- `groups[].label` / `.sub` / `.image` — group heading, subtitle, optional background image.
- `groups[].items[]` — `{ text, hint? }`. `text` is the card face (Devanagari), `hint` shows under it when hints are toggled on.

Group colours are assigned automatically by position (first three keep the
gender green / gold / terracotta); up to eight groups are supported.

## Authoring without touching code

Open the [generator](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/sort/generator/index.html):
fill in groups and items (one per line, `text | hint`), press **Собрать и
показать** for a live preview, then **Скачать HTML** for a ready-to-host
self-contained drill file, or **Копировать JSON** to paste a config into a
hand-authored page.

## Accessibility & behaviour

- Works with mouse (drag), touch (tap card → tap group), and keyboard (Tab to a
  card, Enter/Space to select, then activate a group).
- Per-card ✓/✕ marking on **Проверить**; score line; **Заново** reshuffles.
- Light and dark themes, honouring OS preference and an explicit
  `data-theme` toggle on `:root`.
- `prefers-reduced-motion` respected.

_Dr. Mārcis Gasūns_
