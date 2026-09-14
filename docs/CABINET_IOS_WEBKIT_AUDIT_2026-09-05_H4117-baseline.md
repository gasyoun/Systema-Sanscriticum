# Cabinet-routes iPhone WebKit audit — 2026-09-05

Base: http://127.0.0.1:8000 · engine: WebKit (iPhone UA, touch) · H4117.

Discovered: course=— · lesson=—

Runner failures (skips): 0 · console errors: 27

| viewport | route | status | overflowX | tap<44 | input<16px | wire:loading | wire:offline |
|---|---|---|---|---|---|---|---|
| iPhone14_390x844 | dashboard | 200 | 646 | 10 | 1 | 0 | 0 |
| iPhone14_390x844 | course | 404 (skip) | — | — | — | — | — |
| iPhone14_390x844 | lesson | 200 | 622 | 5 | 1 | 0 | 0 |
| iPhone14_390x844 | srs-koloda | 200 | 533 | 6 | 0 | 0 | 0 |
| iPhone14_390x844 | srs-stats | 200 | 646 | 4 | 0 | 0 | 0 |
| iPhone14_390x844 | calendar | 200 | 646 | 6 | 0 | 0 | 0 |
| iPhone14_390x844 | guide | 404 (skip) | — | — | — | — | — |
| iPhone14_390x844 | trial-public | 404 (skip) | — | — | — | — | — |
| Compact_360x740 | dashboard | 200 | 646 | 10 | 1 | 0 | 0 |
| Compact_360x740 | course | 404 (skip) | — | — | — | — | — |
| Compact_360x740 | lesson | 200 | 646 | 5 | 1 | 0 | 0 |
| Compact_360x740 | srs-koloda | 200 | 563 | 6 | 0 | 0 | 0 |
| Compact_360x740 | srs-stats | 200 | 646 | 4 | 0 | 0 | 0 |
| Compact_360x740 | calendar | 200 | 646 | 6 | 0 | 0 | 0 |
| Compact_360x740 | guide | 404 (skip) | — | — | — | — | — |
| Compact_360x740 | trial-public | 404 (skip) | — | — | — | — | — |
| iPhoneSE_320x568 | dashboard | 200 | 646 | 10 | 1 | 0 | 0 |
| iPhoneSE_320x568 | course | 404 (skip) | — | — | — | — | — |
| iPhoneSE_320x568 | lesson | 200 | 646 | 5 | 1 | 0 | 0 |
| iPhoneSE_320x568 | srs-koloda | 200 | 603 | 6 | 0 | 0 | 0 |
| iPhoneSE_320x568 | srs-stats | 200 | 646 | 4 | 0 | 0 | 0 |
| iPhoneSE_320x568 | calendar | 200 | 646 | 6 | 0 | 0 | 0 |
| iPhoneSE_320x568 | guide | 404 (skip) | — | — | — | — | — |
| iPhoneSE_320x568 | trial-public | 404 (skip) | — | — | — | — | — |

## Console errors (WebKit)

- [iPhone14_390x844] http://127.0.0.1:8000/dvaram: pageerror: SyntaxError: Unexpected token '<'
- [iPhone14_390x844] http://127.0.0.1:8000/dvaram: pageerror: SyntaxError: Unexpected token '<'
- [iPhone14_390x844] http://127.0.0.1:8000/dvaram: Failed to load resource: the server responded with a status of 404 (Not Found)
- [iPhone14_390x844] http://127.0.0.1:8000/c/grammatika-sanskrita-demo/u/1: pageerror: SyntaxError: Unexpected token '<'
- [iPhone14_390x844] http://127.0.0.1:8000/dvaram/koloda: pageerror: SyntaxError: Unexpected token '<'
- [iPhone14_390x844] http://127.0.0.1:8000/dvaram/koloda/stats: pageerror: SyntaxError: Unexpected token '<'
- [iPhone14_390x844] http://127.0.0.1:8000/calendar: pageerror: SyntaxError: Unexpected token '<'
- [iPhone14_390x844] http://127.0.0.1:8000/calendar: Failed to load resource: the server responded with a status of 404 (Not Found)
- [iPhone14_390x844] http://127.0.0.1:8000/guide: Failed to load resource: the server responded with a status of 404 (Not Found)
- [Compact_360x740] http://127.0.0.1:8000/dvaram: pageerror: SyntaxError: Unexpected token '<'
- [Compact_360x740] http://127.0.0.1:8000/dvaram: pageerror: SyntaxError: Unexpected token '<'
- [Compact_360x740] http://127.0.0.1:8000/dvaram: Failed to load resource: the server responded with a status of 404 (Not Found)
- [Compact_360x740] http://127.0.0.1:8000/c/grammatika-sanskrita-demo/u/1: pageerror: SyntaxError: Unexpected token '<'
- [Compact_360x740] http://127.0.0.1:8000/dvaram/koloda: pageerror: SyntaxError: Unexpected token '<'
- [Compact_360x740] http://127.0.0.1:8000/dvaram/koloda/stats: pageerror: SyntaxError: Unexpected token '<'

Screenshots: storage/app/cabinet-ios-audit/ (not git). Raw JSON: docs/CABINET_IOS_WEBKIT_AUDIT_runtime.json.

## Findings (H4117, 05-09-2026)

### F1 — CRITICAL root cause: runtime Tailwind Play CDN serves a partially-broken build (v3.4.17)

`cdn.tailwindcss.com` now 302-redirects to `/3.4.17` (Cloudflare-cached, verified 05-09). The JIT it runs in-browser generates only ~128-129 rules for cabinet markup and **never generates `hidden`, `md:*`, `sm:*` utilities** (verified: rule walk at t=0/3s/8s — count stable at 128, zero responsive/hidden rules; markup uses them). Consequences, reproduced on **both WebKit and Chromium** (i.e. not engine-specific — this hits real iPhones AND desktop narrow windows):

- Student shell (`layouts/student.blade.php:284` `h-screen w-full` wrapper) renders at desktop width → 1036px on a 390px viewport → **horizontal overflow ox=646px on every authed route × every viewport** (the constant 646 = a bare `<b>` at right=646 + shell at right=1036).
- `/login` card computes `width: 0` at 390px (x=389, past the right edge) and the submit button becomes an unclickable 32×88 sliver — Playwright click fails with `<html> intercepts pointer events`; the login entry point is unusable on iPhone.
- H1488's identical audit passed (exit 0) on 24-07-2026 with the same cabinet code — the regression came from the CDN side, not the repo.

**Fix direction (H4118 P0-0):** drop the runtime CDN — compile Tailwind via Vite and self-host the stylesheet. Expect most overflow/tap-target findings to collapse once utilities actually apply; re-run this audit after the swap.

### F2 — static defects confirmed by code audit (feed H4118)

1. `env(safe-area-inset-*)` used 0 times despite `viewport-fit=cover` (layout:5) — PWA-standalone notch overlap.
2. `h-screen`/`100vh` without dvh strategy: layout:284, lesson:65, support-chat:174 (one dvh fix exists: livewire/student-chat:3).
3. Zero `wire:loading`/`wire:offline` in all student views (audit confirms wl=0 wo=0 on every route incl. Livewire SRS).
4. `lesson-heartbeat.js:120-122` silently drops accumulated seconds on fetch failure.
5. `session lifetime=120min` (config/session.php:34) — mid-study logout; ruling pending (GTD).
6. Native `<video>` without `playsinline` (lesson:767); legacy Telegram layout `user-scalable=no` (student.blade.php:5).
7. Input font-size 14px on dashboard text input (iOS focus-zoom; <16px floor missing).

### F3 — audit runner notes

- `guide`/`trial`/demo-course routes are 404 on the seeded local dataset — reported as n/a, not failures.
- The 27 console errors in the JSON are artifacts of probing those 404 routes (404-page HTML parsed as JS) — real routes show zero WebKit console errors after `npm run build`.
- Screenshots: `storage/app/cabinet-ios-audit/` (gitignored). Raw: `CABINET_IOS_WEBKIT_AUDIT_runtime.json`.
