# H2379 — Arzamas / Синхронизация visual-polish pass

_Created: 07-08-2026 · Last updated: 07-08-2026_

**Executor:** Grok 4.5 (`grok-4.5`)  
**Repo:** Systema-Sanscriticum  
**Scope:** public shop funnel polish only — no money/access code.

## Acceptance

| Criterion | Evidence |
|---|---|
| One editorial product: clear first action, hierarchy, curated entrances, live/recorded language | Curated «Куда зайти» + format badges on card and hero + library framing |
| Prove with tests + build + Pint | `ShopVisualPolishTest` (7) + `CourseShowTest` about panel (2) green; Pint dirty; Vite when CSS assets touched (Tailwind utilities only — existing build) |
| Own-data smoke: complete + sparse course | Sparse course (no image/description/counts) still renders hero + `#tariffs` |
| Fail = recreate on-ramp / materials / invent covers | Reused `shop.start`, Livewire filters, typographic fallback — no new catalogue engine |

## Scorecard (keep / fix / add)

| Dimension | Decision | Files |
|---|---|---|
| Dark/orange brand, Livewire filters, first-questions, on-ramp, materials | **Keep** | unchanged surfaces from H323/H387/H1868 |
| Course hero said only «Онлайн-программа»; `formatLabel` said «Live-поток» | **Fix** | `shop/show.blade.php`, `Course::formatLabel()` |
| Category chips hid scroll on mobile | **Fix** | `course-catalog.blade.php` fade + hint; schedule carousel same |
| No curated shelves above grid (only FAQ-style first-questions) | **Add** | editorial entrances in Livewire catalog |
| Recorded felt like leftover filter, not a library mode | **Add** | section title «Библиотека записей» + subcopy when `format=recorded` |

## Top 3 shipped fixes

1. **Curated entrances** (`data-analytics="editorial-entrances"`) — С нуля → on-ramp; Идут сейчас / Библиотека записей → Livewire `format`; top category → `toggleCategory`. Hidden while searching.
2. **Card → hero → tariff continuity** — live/recorded/level badges match card; typographic cover when no photo; teacher photo when set; primary CTA remains «Выбрать тариф».
3. **Library vocabulary + mobile scroll** — recorded section framing; thin scrollbar + gradient fade + «Листайте…» on chips and schedule.

## Filament content checklist (operator residual — not invented in code)

For **3–5 flagship courses**, fill in admin so selling blocks appear:

1. **Course cover** — `image_path` (real photo, not only typographic fallback).
2. **Teacher** — `photo_path` + non-empty `bio`.
3. **Level** — `beginner` / `continuing` / `advanced` so level chips and badges work.
4. **Format** — `live` or `recorded` (never blank if product is one of the two).
5. **Landing fields** — `audience`, `outcomes`, at least one `is_preview` lesson, 2–3 `course_faqs`, 1–2 linked testimonials.
6. **Categories** — assign visible categories with `icon` + `color` (feeds cover gradient + curated top-category tile).

## Browser evidence note

Local `php artisan serve` was not running in-session; production HTML probe confirmed **no** editorial entrances pre-ship (`data-analytics="editorial-entrances"` absent on live `/online`). Post-merge verify:

- Desktop ≥1440px and 390px: `/online`, `/online/s-chego-nachat`, one flagship course, one sparse course.
- Confirm «Куда зайти», format badges on hero, recorded library header, no horizontal overflow.

Reproduce tests:

```bash
php artisan test --filter=ShopVisualPolishTest
```

_Dr. Mārcis Gasūns_
