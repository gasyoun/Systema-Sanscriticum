# VisualDCS learner contact sheet — 1440 / 390

_Created: 13-08-2026 · Last updated: 18-08-2026_

H2482 acceptance: complete + sparse fixtures render at 1440px and 390px with
keyboard focus, contrast, reduced-motion and no overflow.
**Executed 18-08-2026 (H2869, Fable 5 `claude-fable-5`) — real browser
evidence, not only HTML assertions.**

## Browser evidence (Wave L step 8 — DONE)

Dusk suite (local, per
[docs/DUSK_LOCAL_WINDOWS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DUSK_LOCAL_WINDOWS.md)):

- [tests/Browser/VisualDcsLearnerEvidenceTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Browser/VisualDcsLearnerEvidenceTest.php)
  — complete + sparse fixture releases, every surface at **1440px и 390px**:
  screenshot, no-horizontal-overflow check (`scrollWidth` vs `clientWidth`),
  and a computed-style **WCAG AA contrast audit** of the surface container;
  plus a real-Tab keyboard pass asserting a visible `focus-visible` ring
  (computed `box-shadow`, not a CSS class in markup). 3 tests, 92 assertions.
- [tests/Browser/VisualDcsReducedMotionTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Browser/VisualDcsReducedMotionTest.php)
  — Chrome with `--force-prefers-reduced-motion` (asserted via
  `matchMedia`), same anchors still visible, no overflow. 1 test, 9 assertions.

Frames (18) are committed in
[docs/screenshots/visualdcs-learner/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/screenshots/visualdcs-learner):
guest preview, hub, verb/nominal/passage catalogs, linked and zero-link
passage pages, sparse-release verb + passage, reduced-motion hub + passage.

### Defects the audit caught (fixed same pass)

| Spot | Measured | Fix |
|---|---|---|
| Preview heading on dark shop layout (`text-gray-900` on `#0A0D14`) | **1.10:1** — invisible | `text-white` |
| Preview intro (`text-gray-500` on dark) | 4.02:1 | `text-slate-300` |
| Cabinet intros (`text-gray-500` on cream `#F4F1EA`) | 4.29:1 | `text-gray-600` |
| `text-gray-400` labels / «нет» on white cards | ~2.5:1 | `text-gray-500` |
| Small brand-orange links (`text-brand` on cream/white) | ~3.3:1 | dark text + `decoration-brand` underline |

### Known residue (a human decides — brand palette)

White text on the brand button background `#e85c24` («Найти», «Сохранить»)
measures **3.51:1** against the AA 4.5:1 bar for small text. This is the
site-wide button style, not a VisualDCS-local choice; the audit measures and
reports it (test does not fail on it). Changing the brand palette or button
typography is a product-identity decision for a human — recorded here, parked.

### Scope note

The audit covers the surface's own container (`.max-w-3xl` — every VisualDCS
view root). Site chrome (shop header/footer, cabinet menu) is shared across
the whole site and out of Wave L step 8 scope.

## Re-run

```
php artisan dusk tests/Browser/VisualDcsLearnerEvidenceTest.php
php artisan dusk tests/Browser/VisualDcsReducedMotionTest.php
```

Flags `VISUALDCS_VERB/NOMINAL/PASSAGE=true` go in `.env.dusk.local` (the
local Dusk env only — prod defaults stay OFF; the tests skip with an
instruction when the flags are off).

## Route inventory (what the frames show)

```
GET /visualdcs/verb/preview          guest, 1440 and 390
GET /dvaram/visualdcs                paid user
GET /dvaram/visualdcs/verb
GET /dvaram/visualdcs/nominal
GET /dvaram/visualdcs/passage
GET /dvaram/visualdcs/passage/{id}   complete (linked) and sparse (zero-link)
```

Flags stay OFF on prod until the baseline in
[VISUALDCS_LEARNER_BASELINE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VISUALDCS_LEARNER_BASELINE_2026.md)
has a 7-day follow-up. Activation (flag flip) is a human decision — Wave L
step 9.

_Dr. Mārcis Gasūns_
