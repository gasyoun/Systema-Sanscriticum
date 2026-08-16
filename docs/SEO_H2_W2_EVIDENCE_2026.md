# SEO H2 wave-2 evidence

_Created: 16-08-2026 · Last updated: 16-08-2026_

**Handoff:** [H2918 (Grok 4.6) — samskrte.ru SEO H2 wave-2 contextual links to samskrtam.ru](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2918-Grok_Systema-Sanscriticum_samskrte-seo-h2-w2_16.08.26.md)
**PR:** [Systema-Sanscriticum#1774](https://github.com/gasyoun/Systema-Sanscriticum/pull/1774) merged as `2ff193e0`.
**Executor:** Grok 4.6 (`grok-4.6`).

## Code

| Gate | Result |
|---|---|
| Allowlist host | PASS — every URL starts with `https://samskrtam.ru/`; `_home` has 4 entries; donate slug is not a key |
| Lockfile | PASS — `seo:lock-samskrtam-related` wrote 35 rows, each `status=200` (16-08-2026). Windows PHP needed `--ca=` (cURL error 60 without a CA bundle); Linux prod uses the system store |
| PHPUnit `tests/Feature/Seo/` | PASS — 18 tests, 586 assertions (8 new in `SamskrtamRelatedTest`) |
| Pint on touched PHP | PASS |
| CI PHP 8.3 + MySQL 8.4 | PASS on #1774 |
| Runtime fetch | none — request path reads committed JSON + lock only |

## Prod deploy

`sudo bash deploy.sh` on `root@193.232.229.92` (`/var/www/html`): `bfce5543` → `2ff193e0`, caches rebuilt, php8.3-fpm reloaded, Horizon restarted. Smoke `https://samskrte.ru/` → **200**.

## Live curls (16-08-2026, after deploy)

| URL | Result |
|---|---|
| [https://samskrte.ru/](https://samskrte.ru/) | **PASS** — heading «Читать на samskrtam.ru»; four `_home` hrefs; `#org` JSON-LD present |
| [https://samskrte.ru/k/grammatika-po-kocerginoi-gr62](https://samskrte.ru/k/grammatika-po-kocerginoi-gr62) | **PASS** — `https://samskrtam.ru/p/kochergina/` present |
| [https://samskrte.ru/k/donat-na-razvitie-ors](https://samskrte.ru/k/donat-na-razvitie-ors) | **PASS** — related-reading block absent |
| Each rendered href re-GET | **PASS** — all HTTP 200 |

W3–W5 not started.

_Dr. Mārcis Gasūns_
