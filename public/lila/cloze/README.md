# Cloze (fill-in-the-blank) exercise engine

_Created: 11-07-2026 · Last updated: 11-07-2026_

Interactive "pick the right word for each blank" drills for Sanskrit learners,
served as static files from `public/lila/cloze/`. No build step, no
framework, no external network — the engine is one CSS + one JS file and works
offline and inside an `<iframe>`. Sibling family to
[`public/lila/sort/`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/sort/README.md);
the palm-leaf theme tokens are lifted from `sort/engine.css` so both match.

Originally a remake of the LearningApps exercise
[p4zkqq28t25](https://learningapps.org/display?v=p4zkqq28t25) ("Заполнить
пропуски" — «Выберите нужный глагол»), generalised into a reusable engine.

## Contents

| Path | What it is |
|---|---|
| [`engine.css`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/cloze/engine.css) | Shared styles — palm-leaf theme, light + dark |
| [`engine.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/cloze/engine.js) | The engine — `ClozeExercise.mount(container, config)` |
| [`index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/cloze/index.html) | Catalogue landing page |
| [`verb-fill/index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/cloze/verb-fill/index.html) | Drill: 11 verb blanks in a short Sanskrit passage |

## URLs (once deployed)

- Catalogue — `/lila/cloze/`
- Verb-fill drill — `/lila/cloze/verb-fill/`

## Authoring a new drill by hand

Create `public/lila/cloze/<slug>/index.html`:

```html
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My cloze drill</title>
  <link rel="stylesheet" href="../engine.css">
</head>
<body>
  <div class="page" style="max-width:900px;margin:0 auto;padding:28px 20px"><div id="app"></div></div>
  <script src="../engine.js"></script>
  <script>
    ClozeExercise.mount(document.getElementById("app"), {
      task: "Выберите нужный глагол.",
      feedback: "Отлично, верное решение!",
      shuffle: true,              // optional (default true) — shuffle each blank's options
      segments: [
        "गजः ",                                   // string = literal passage text
        { options: ["गच्छति","तिष्ठति","लसति"],   // object = a blank (dropdown)
          answer: 0,              // index into options, OR the correct value string
          gloss: "идет" },        // optional Russian hint (shown under hints toggle)
        "। नेत्रं ",
        { options: ["स्फुरति","वदति","विकसति"], answer: 0, gloss: "дрожит" },
        "॥"                                        // use "\n" inside a string for a line break
      ]
    });
  </script>
</body>
</html>
```

**Config fields**

- `task` — instruction line above the passage (optional).
- `feedback` — message shown when every blank is correct.
- `shuffle` — shuffle each blank's option order (default `true`). Correctness is
  tracked by value, not position, so you always list the **correct option first**
  (the LearningApps convention) regardless of shuffling.
- `segments[]` — the passage, in order. A **string** is literal text (embed `\n`
  for a line break); an **object** `{ options, answer?, gloss? }` is a blank.
- `segments[].options[]` — the dropdown choices (Devanagari), correct one first.
- `segments[].answer` — optional; an index into `options` **or** the correct value
  string. Omit to default to the first option.
- `segments[].gloss` — optional Russian hint, revealed by the hints toggle.

## Accessibility & behaviour

- Each blank is a native `<select>` with an `aria-label` (blank number + gloss),
  keyboard-operable out of the box.
- Per-blank ✓/✕ marking on **Проверить**; score line; **Заново** reshuffles the
  option order (answers still resolve correctly).
- Light and dark themes, honouring OS preference and an explicit `data-theme`
  toggle on `:root`.
- `prefers-reduced-motion` respected.

_Dr. Mārcis Gasūns_
