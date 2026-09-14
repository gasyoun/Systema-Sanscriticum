# Cabinet-routes iPhone WebKit audit — 2026-09-05

Base: http://127.0.0.1:8000 · engine: WebKit (iPhone UA, touch) · H4117.

> **H4118 (05-09-2026, after-run):** повторный прогон того же раннера ПОСЛЕ
> P0-волны H4118 (Play CDN → скомпилированный app.css через Vite, dvh,
> safe-area, wire:loading/offline, 16px-пол, PHP 8.5 PDO-deprecation guard
> против quirks mode). Базовые цифры до правок: `*_H4117-baseline.md` — ox был
> 533–646 на всех авторизованных роутах. После: ox=0 везде, input<16px=0,
> wl/wo=1. tap<44 — остаток к P1 (гейт: подтверждённый список из аудита H4116).

Discovered: course=— · lesson=—

Runner failures (skips): 0 · console errors: 27

| viewport | route | status | overflowX | tap<44 | input<16px | wire:loading | wire:offline |
|---|---|---|---|---|---|---|---|
| iPhone14_390x844 | dashboard | 200 | 0 | 10 | 0 | 1 | 1 |
| iPhone14_390x844 | course | 404 (skip) | — | — | — | — | — |
| iPhone14_390x844 | lesson | 200 | 0 | 8 | 0 | 1 | 1 |
| iPhone14_390x844 | srs-koloda | 200 | 0 | 9 | 0 | 1 | 1 |
| iPhone14_390x844 | srs-stats | 200 | 0 | 7 | 0 | 1 | 1 |
| iPhone14_390x844 | calendar | 200 | 0 | 9 | 0 | 1 | 1 |
| iPhone14_390x844 | guide | 404 (skip) | — | — | — | — | — |
| iPhone14_390x844 | trial-public | 404 (skip) | — | — | — | — | — |
| Compact_360x740 | dashboard | 200 | 0 | 10 | 0 | 1 | 1 |
| Compact_360x740 | course | 404 (skip) | — | — | — | — | — |
| Compact_360x740 | lesson | 200 | 0 | 8 | 0 | 1 | 1 |
| Compact_360x740 | srs-koloda | 200 | 0 | 9 | 0 | 1 | 1 |
| Compact_360x740 | srs-stats | 200 | 0 | 7 | 0 | 1 | 1 |
| Compact_360x740 | calendar | 200 | 0 | 9 | 0 | 1 | 1 |
| Compact_360x740 | guide | 404 (skip) | — | — | — | — | — |
| Compact_360x740 | trial-public | 404 (skip) | — | — | — | — | — |
| iPhoneSE_320x568 | dashboard | 200 | 0 | 10 | 0 | 1 | 1 |
| iPhoneSE_320x568 | course | 404 (skip) | — | — | — | — | — |
| iPhoneSE_320x568 | lesson | 200 | 0 | 8 | 0 | 1 | 1 |
| iPhoneSE_320x568 | srs-koloda | 200 | 0 | 9 | 0 | 1 | 1 |
| iPhoneSE_320x568 | srs-stats | 200 | 0 | 7 | 0 | 1 | 1 |
| iPhoneSE_320x568 | calendar | 200 | 0 | 9 | 0 | 1 | 1 |
| iPhoneSE_320x568 | guide | 404 (skip) | — | — | — | — | — |
| iPhoneSE_320x568 | trial-public | 404 (skip) | — | — | — | — | — |

## Console errors (WebKit)

- [iPhone14_390x844] http://127.0.0.1:8000/dvaram: Viewport argument key "interactive-widget" not recognized and ignored.
- [iPhone14_390x844] http://127.0.0.1:8000/dvaram: Viewport argument key "interactive-widget" not recognized and ignored.
- [iPhone14_390x844] http://127.0.0.1:8000/dvaram: Failed to load resource: the server responded with a status of 404 (Not Found)
- [iPhone14_390x844] http://127.0.0.1:8000/c/grammatika-sanskrita-demo/u/1: Viewport argument key "interactive-widget" not recognized and ignored.
- [iPhone14_390x844] http://127.0.0.1:8000/dvaram/koloda: Viewport argument key "interactive-widget" not recognized and ignored.
- [iPhone14_390x844] http://127.0.0.1:8000/dvaram/koloda/stats: Viewport argument key "interactive-widget" not recognized and ignored.
- [iPhone14_390x844] http://127.0.0.1:8000/calendar: Viewport argument key "interactive-widget" not recognized and ignored.
- [iPhone14_390x844] http://127.0.0.1:8000/calendar: Failed to load resource: the server responded with a status of 404 (Not Found)
- [iPhone14_390x844] http://127.0.0.1:8000/guide: Failed to load resource: the server responded with a status of 404 (Not Found)
- [Compact_360x740] http://127.0.0.1:8000/dvaram: Viewport argument key "interactive-widget" not recognized and ignored.
- [Compact_360x740] http://127.0.0.1:8000/dvaram: Viewport argument key "interactive-widget" not recognized and ignored.
- [Compact_360x740] http://127.0.0.1:8000/dvaram: Failed to load resource: the server responded with a status of 404 (Not Found)
- [Compact_360x740] http://127.0.0.1:8000/c/grammatika-sanskrita-demo/u/1: Viewport argument key "interactive-widget" not recognized and ignored.
- [Compact_360x740] http://127.0.0.1:8000/dvaram/koloda: Viewport argument key "interactive-widget" not recognized and ignored.
- [Compact_360x740] http://127.0.0.1:8000/dvaram/koloda/stats: Viewport argument key "interactive-widget" not recognized and ignored.

Screenshots: storage/app/cabinet-ios-audit/ (not git). Raw JSON: docs/CABINET_IOS_WEBKIT_AUDIT_runtime.json.
