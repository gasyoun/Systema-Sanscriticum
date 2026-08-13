# VisualDCS learner contact sheet — 1440 / 390

_Created: 13-08-2026 · Last updated: 13-08-2026_

H2482 acceptance: complete + sparse fixtures render at 1440px and 390px with
keyboard focus, contrast, reduced-motion and no overflow.

## What was verified in tests (not screenshots)

Feature tests render both fixture releases as HTML and assert:

- hub / verb / nominal / passage return 200 for a paid user;
- public preview returns 200 for guests when the surface flag is on;
- list links expose `focus-visible:ring`;
- passage show page uses `overflow-x-hidden` / `break-words`;
- one disabled flag 404s only that surface.

## Manual contact sheet (when flags are on in a local `.env`)

```
GET /visualdcs/verb/preview          guest, 1440 and 390
GET /dvaram/visualdcs                paid user
GET /dvaram/visualdcs/verb
GET /dvaram/visualdcs/nominal
GET /dvaram/visualdcs/passage
GET /dvaram/visualdcs/passage/{id}   complete (linked) and sparse (zero-link)
```

Check: Tab order reaches every CTA, contrast of gray-500 on white, no horizontal
scroll at 390px, `prefers-reduced-motion` does not hide content.

Flags stay OFF on prod until the baseline in
[VISUALDCS_LEARNER_BASELINE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VISUALDCS_LEARNER_BASELINE_2026.md)
has a 7-day follow-up.

_Dr. Mārcis Gasūns_
