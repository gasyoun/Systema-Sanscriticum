# SEO H2 wave-1 evidence

_Created: 16-08-2026 · Last updated: 16-08-2026_

**Handoff:** [H2893 (Grok 4.6) — samskrte.ru SEO H2 wave-1 money-page hygiene](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2893-Grok_Systema-Sanscriticum_samskrte-seo-h2-w1_16.08.26.md)
**PR:** [Systema-Sanscriticum#1761](https://github.com/gasyoun/Systema-Sanscriticum/pull/1761) merged as `a059eff2`.
**Executor:** Grok 4.6 (`grok-4.6`).

## Code

| Gate | Result |
|---|---|
| PHPUnit `tests/Feature/Seo/` | PASS (CSV uniqueness, artisan dry-run/apply/reset/BOM/hidden slug, sitemap priorities, homepage + `/online` copy) |
| Pint on touched PHP | PASS |
| CI PHP 8.3 + MySQL 8.4 | PASS on #1761 |
| `dictionary:mark-core-indexable` write | **not run** (packet only) |

## Prod deploy

`sudo bash deploy.sh` on `root@193.232.229.92` (`/var/www/html`): already at `a059eff2`, caches rebuilt, php8.3-fpm reloaded, Horizon restarted. Smoke `https://samskrte.ru/` → **200**.

## CSV apply

```
php artisan seo:fill-course-meta database/data/seo/course_meta_h2.csv --dry-run
# exit 0 · 86 write / 1 skip-unchanged (start-chteniya already matched)
php artisan seo:fill-course-meta database/data/seo/course_meta_h2.csv
# Applied 86 meta update(s).
php artisan cache:forget sitemap.xml
```

Rollback: `php artisan seo:fill-course-meta --reset-slugs=<slug>`.

## Live curls (16-08-2026, after apply)

| URL | Result |
|---|---|
| [https://samskrte.ru/](https://samskrte.ru/) | **PASS** — title `Курсы санскрита с нуля — Общество ревнителей санскрита`; description no longer «образовательная платформа…»; `#org` JSON-LD present |
| [https://samskrte.ru/k/grammatika-po-kocerginoi-gr62](https://samskrte.ru/k/grammatika-po-kocerginoi-gr62) | **PASS** — title `Кочергина гр.62 — живой \| Общество ревнителей санскрита`; `"@type":"Course"` present |
| [https://samskrte.ru/online](https://samskrte.ru/online) | **PASS** — title `Курсы санскрита онлайн \| Общество ревнителей санскрита` ≠ homepage |
| [https://samskrte.ru/sitemap.xml](https://samskrte.ru/sitemap.xml) donate | **PASS** — `donat-na-razvitie-ors` priority `0.3` |
| [https://samskrte.ru/login](https://samskrte.ru/login) | **PASS** — HTTP 200 (no checkout completed) |

W2–W5 not started.

_Dr. Mārcis Gasūns_
