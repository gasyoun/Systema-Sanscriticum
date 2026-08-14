# Free-intro CTA — next-date source of truth (H2365)

_Created: 07-08-2026 · Last updated: 15-08-2026_

## Goal

Site-wide shop banner shows a **live** next intro date. Empty upcoming schedule
omits the date line (H2760) — no «дата уточняется» placeholder. No hard-coded
promotional dates. One calendar — Systema DB — not a second ORS/WordPress calendar
that drifts.

## Source of truth

| Priority | Source | Fields | When used |
|---|---|---|---|
| 1 | Course trial session | `courses.trial_schedule_id` → `schedules.start` for `is_visible` courses | Designated intro/trial class already wired to trial purchase |
| 2 | Landing webinar | `landing_pages.webinar_date` (+ optional `webinar_label`) for `is_active` rows | Free webinar landings / lead forms |
| empty | — | — | UI **omits** the date line (H2760). `FALLBACK_LABEL` is not rendered |

Resolver: [`App\Support\NextIntroSession`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/NextIntroSession.php).

Consumer: [`resources/views/shop/partials/free-intro-banner.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/partials/free-intro-banner.blade.php), included from [`layouts/shop.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/shop.blade.php) (all `/online/*` shop surfaces).

Cache: 120 s key `shop_next_intro_session_v1` (disabled in `testing`). Call
`NextIntroSession::flushCache()` after bulk admin edits if a shorter lag is needed.

## Empty vs non-empty behaviour

| Case | Banner |
|---|---|
| Future trial schedule or webinar exists | «Ближайшее — {d MMMM Y, H:i}» + CTA to course or landing |
| No future row | Date line omitted + CTA «Смотреть курсы» |

Never invents a date string when the source is empty.

## Per-course

`NextIntroSession::forCourse($course)` returns the same shape for a single course’s
`trial_schedule` when its `start` is still in the future. Course pages already
surface the trial date via `ShopController` + `shop/show` trial CTA; this helper
is the shared API for any other surface that needs the same field.

## What is out of scope

- New paid mini-course SKU
- ESP email nurture wire
- Dual calendar in ORS-FAQ static wiki (static CTAs keep linking to `samskrte.ru/online`; the live date lives on the shop)

## Tests

`tests/Feature/Shop/FreeIntroBannerTest.php` — empty path, trial path, landing
fallback path, no hard-coded future year strings in the banner partial.

## Sales roadmap

ORS-FAQ [roadmap_samskrte_sales.md](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/roadmap_samskrte_sales.md) Phase 1 H3 checkbox is ticked against this ship.

_Dr. Mārcis Gasūns_
