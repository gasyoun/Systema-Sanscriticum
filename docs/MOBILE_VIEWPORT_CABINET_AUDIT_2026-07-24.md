# Mobile viewport audit — student cabinet + PWA (H1488)

_Created: 24-07-2026 · Last updated: 24-07-2026_

**Executor:** Grok 4.5 (`grok-4.5`) via xAI — user-authorized override of Sonnet 5 lock on
[H1488](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1488-Sonnet_Systema-Sanscriticum_cabinet-mobile-viewport-audit-pwa_22.07.26.md).

**Roadmap:** [ROADMAP_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md) §5 Stream C, **C3** remainder
(«сплошной аудит студенческих страниц на мобильном вьюпорте + PWA-манифест»).

## Goal

Serve ~90 % mobile audience (CUSTDEV) without a native app: every student-cabinet
route usable at 320–390 px width, plus a valid installable PWA shell with offline fallback.

## Method

1. **Inventory** all auth student GET routes from `routes/web.php` (cabinet group).
2. **Prior-art delta** — do not re-open pages already cleared 01–02-07-2026 (see § Prior clears).
3. **Static structural review** of Blade/Livewire at mobile breakpoints (Tailwind `sm`/`md`/`lg`,
   overflow, fixed min-heights, header density).
4. **Targeted layout fixes** for real defects found.
5. **PWA shell** — `manifest.webmanifest` + service worker + offline page + student-layout
   registration; Feature smoke tests.
6. **Optional runtime Playwright** — [scripts/mobile_viewport_audit.mjs](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/mobile_viewport_audit.mjs)
   (needs local app + session cookie; not required for CI).

Viewports of record: **390×844** (iPhone 14), **360×740** (compact Android), **320×568** (SE / lower bound).

## Student routes inventory

| Route | Name | View / surface | Prior clear | H1488 status |
|---|---|---|---|---|
| `/dvaram` | `student.dashboard` | `dashboard` or hybrid `home` | 01-07 (layout OK); 02-07 tabs/cards fixed | Re-check hybrid home only — stack layout OK |
| `/calendar` | `student.calendar` | `calendar` | not cleared | **Pass** — cards stack `grid-cols-1`, CTA full-width |
| `/open-lessons` | `student.open-lessons` | `open-lessons` | not cleared | **Pass** — single-column on mobile |
| `/messages` | `student.messages` | `messages` + Livewire chat | not cleared | **Fixed** chat min-height (see defects) |
| `/library` | `student.library` | hybrid `library` (when flag) | hybrid 2026-07 new | **Pass** — flex-col, rail wraps |
| `/progress` | `student.progress` | hybrid `progress` | hybrid new | **Pass** — station list stacks |
| `/access` | `student.access` | hybrid `access` | hybrid new | **Pass** — debt cards stack |
| `/course/{slug}` | `student.course` | `course` / hybrid `course` | partial (cards) | **Pass** — lesson rows `min-w-0` + truncate |
| `/course/{slug}/lesson/{id}` | `student.lesson` | `lesson` | partial | **Pass** — block layout &lt;768 px; side col stacks |
| `/dvaram/srs` | `student.srs` | Livewire `srs-review` | not cleared | **Pass** — grade grid 2×2 on mobile |
| `/dvaram/srs/stats` | `student.srs.stats` | `srs-stats` | not cleared | **Pass** — stacked cards |
| `/dvaram/srs/decks` | `student.srs.decks` | Livewire deck editor | H1487 new | **Pass** — form grids collapse to 1 col |
| POST/telemetry/debt/prana | n/a | actions only | n/a | out of viewport scope |

Non-goals: admin/Filament pages, public shop/checkout (H1391 already fixed checkout), Capacitor store packaging (Wave 0–3 human-gated).

## Prior clears (do not re-audit)

From [`.ai_state.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.ai_state.md) (01–02-07-2026):

- `/dvaram` desktop+mobile — mature, no real defects (01-07).
- Dashboard tabs + course cards under data — three layout defects fixed (02-07): card grid
  breakpoints, homework alerts, false-positive “driver open on mobile” Playwright artifact.
- Promo landings (24 blocks) — adaptive, no H-overflow (01-07) — public, not C3 remainder.

## Defects found and fixed (this pass)

| # | Severity | Surface | Defect | Fix |
|---|---|---|---|---|
| D1 | Medium | `layouts/student` header | On &lt;375 px, header packed hamburger + title + prana + phone + email + shop + avatar → title crushed / risk of edge overflow | Hide contacts below `sm`; remove redundant mobile avatar (profile lives in sidebar) |
| D2 | Medium | Livewire `student-chat` | Fixed `min-height: 460px` + `70vh` overflows short mobile viewports (landscape / SE chrome) | `height: min(70vh, calc(100dvh - 11rem)); min-height: 280px` |
| D3 | Low | `layouts/student` main | No `overflow-x-hidden` on scroll region — rare wide child could scroll the shell | Add `overflow-x-hidden` on `<main>` |
| D4 | Blocker (C3) | PWA absent | No web manifest / SW / offline shell (roadmap mobile audit 12-07 noted none) | Ship `public/manifest.webmanifest`, `public/sw.js`, `public/offline.html`; link + register in student layout |

## PWA shell

| Asset | Role |
|---|---|
| [`public/manifest.webmanifest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/manifest.webmanifest) | `start_url` `/dvaram`, `display` standalone, theme `#E85C24`, icons via `logo.png` + favicon |
| [`public/sw.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/sw.js) | Precache shell only; **network-first navigations**; offline → `/offline.html` |
| [`public/offline.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/offline.html) | RU offline card + reload |
| Student layout | `theme-color`, apple-web-app meta, `rel=manifest`, SW register on load |

**Offline smoke (manual / Feature):**

1. Feature tests assert manifest JSON fields, offline HTML, SW body, and dashboard HTML contains `manifest` + `serviceWorker` — see `tests/Feature/PwaShellAssetsTest.php`.
2. Browser: open `/dvaram` once online → Application → Service Workers registered → DevTools Network offline → navigate → `offline.html` shows.

**Not claimed:** pixel-perfect maskable icons (logo reused); full offline lesson content cache (deliberately out of scope — authenticated HTML must not be precached).

## Residual / follow-ups

- Runtime Playwright pass against staging with a real student session
  (`node scripts/mobile_viewport_audit.mjs`) when deploy credentials allow.
- Generate dedicated 192/512 maskable icons from brand kit (MG branding gate on mobile roadmap Wave 0).
- Hybrid flag ON in prod is a separate release pack (H1582) — this audit covers both classic and hybrid blades.

## Acceptance map (H1488 DoD)

| Criterion | Evidence |
|---|---|
| Dated audit covers every student-cabinet route | This document § inventory |
| Real defects fixed | D1–D4 committed |
| Valid PWA manifest | `manifest.webmanifest` + `PwaShellAssetsTest` |
| Offline-load smoke | `offline.html` + SW + Feature test + manual steps above |

_Dr. Mārcis Gasūns_
