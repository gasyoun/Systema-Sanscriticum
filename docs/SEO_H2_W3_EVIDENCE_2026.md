# SEO H2 wave-3 evidence

_Created: 16-08-2026 · Last updated: 16-08-2026_

**Handoff:** [H2935 (Grok 4.6) — samskrte.ru SEO H2 wave-3 generated /llms.txt + cheap technical leftovers](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2935-Grok_Systema-Sanscriticum_samskrte-seo-h2-w3_16.08.26.md)
**PR:** [Systema-Sanscriticum#1778](https://github.com/gasyoun/Systema-Sanscriticum/pull/1778) merged as `a9fa3f5d`.
**Executor:** Grok 4.6 (`grok-4.6`).

## Code

| Gate | Result |
|---|---|
| Generated route | PASS — `GET /llms.txt` → `LlmsTxtController`, not `public/llms.txt` |
| Catalog | PASS — 87 visible `/k/` rows (2 tabs each); two published `/s/` articles; `/` `/online` `/slovar` |
| Fence | PASS — `/cabinet` and `/dvaram` absent; no `/slovar/{slug}` word URLs |
| PHPUnit `tests/Feature/Seo/` | PASS — 22 tests, 613 assertions (4 new in `LlmsTxtTest`) |
| Pint | PASS |
| CI PHP 8.3 | PASS on rerun of [#1778](https://github.com/gasyoun/Systema-Sanscriticum/pull/1778) (first run: GrammarLab parallel `manifest.json` flake, not W3) |
| HSTS / Referrer-Policy | SKIP — live had `X-Frame-Options` + `X-Content-Type-Options` only; a new middleware is not one-line |
| IndexNow | SKIP (residual default) |
| robots / Metrika / money / `is_indexable` | not touched |

## Prod deploy

`sudo bash deploy.sh` on `root@193.232.229.92` (`/var/www/html`): `85601112` → `a9fa3f5d`, caches rebuilt, php8.3-fpm reloaded, Horizon restarted. Smoke `https://samskrte.ru/` → **200**.

## Live curls (16-08-2026, after deploy)

| URL | Result |
|---|---|
| [https://samskrte.ru/llms.txt](https://samskrte.ru/llms.txt) | **PASS** — 200, `text/plain; charset=utf-8`; org header; 87 `/k/` courses; two `/s/` articles; no `/cabinet` or `/dvaram` |
| [https://samskrte.ru/](https://samskrte.ru/) | **PASS** — `#org` present; `@samskrtamru` in `sameAs`; `og:image:width` 1200 |
| [https://samskrte.ru/k/grammatika-po-kocerginoi-gr42](https://samskrte.ru/k/grammatika-po-kocerginoi-gr42) | **PASS** — `"@type":"Course"` still present |

W4–W5 not started.

_Dr. Mārcis Gasūns_
