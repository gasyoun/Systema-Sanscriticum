# ARCHITECTURE — SAMSKRTE-SEO-H2 · samskrte.ru SEO

_Created: 16-08-2026 · Last updated: 16-08-2026_

**Umbrella ID:** `SAMSKRTE-SEO-H2` · **Pack:** `/ask` samskrte.ru SEO 16-08-2026 · **Stem:** `*_SYSTEMA_SAMSKRTE_SEO_H2_*`

Index: [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md).

---

## 1. Surfaces and who owns the string

| Surface | Live URL | SEO string owner | Do not invent |
|---|---|---|---|
| Homepage | `/` | Blade [main.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/main.blade.php) title / description / H1 | New sections, visual redesign, Metrika |
| Catalog | `/online` | Blade [shop/index.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/index.blade.php) + card titles from Course | New catalog engine |
| Course money page | `/k/{slug}` | `courses.meta_title` / `meta_description` (fallback = `title` / stripped `description`) | New SEO table |
| Named landings | `/geography`, `/hindi`, … | Existing LandingPage meta fields | New landing CMS |
| Articles | `/s`, `/s/{slug}` | Existing Article schema; **no new essays** | samskrtam archive clone |
| Dictionary | `/slovar`, `/slovar/{slug}` | Built; `noindex` until human wave | Prod `is_indexable` write |
| AI file | `/llms.txt` (404 today) | **New** public route, Course query | Static committed dump as source of truth |
| Sitemap | `/sitemap.xml` | [SitemapController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/SitemapController.php) | New `sitemap_priority` column |

Dual-site contract: samskrte.ru sells; samskrtam.ru explains. W2 links flow **out** to samskrtam. No `rel=canonical` across hosts unless a later sitting rules it.

---

## 2. Unique titles (W1)

`shop/show.blade.php` already does `@section('title', $course->meta_title ?: $course->title)`. The gap is empty columns, not missing code.

```
database/data/seo/course_meta_h2.csv
  slug,meta_title,meta_description
php artisan seo:fill-course-meta database/data/seo/course_meta_h2.csv --dry-run
php artisan seo:fill-course-meta database/data/seo/course_meta_h2.csv
```

Rules:

- One row per sitemap `/k/{slug}` (`Course::where('is_visible', true)`).
- Titles unique across the file and across already-filled rows. ≤60 chars. Descriptions unique. ≤160 chars.
- Kochergina groups stay separate URLs; differentiate by group number, year, live vs запись.
- Donate (`slug` contains `donat`) gets a non-course title («Донат на развитие ОРС») and stays in the sitemap at 0.3.
- UTF-8, no BOM. Dry-run prints would-write / skip / conflict and exits non-zero on duplicate or over-length.
- Apply is idempotent. Rollback = re-apply a previous CSV, or `NULL` the columns for those slugs (`--reset-slugs=`).
- JSON-LD `Course.name` stays `course.title` (product name). SERP `<title>` is `meta_title`.

---

## 3. Sitemap weights (W1)

Extend the existing course chunk. Select `slug`, `updated_at`, `format`. No migration.

| Condition | priority |
|---|---|
| Home | 1.0 (unchanged) |
| `/online` | 0.9 (unchanged) |
| Visible course, `format === 'recorded'` | 0.6 |
| Visible course, slug contains `donat` | 0.3 (wins over recorded) |
| Other visible courses | 0.8 |
| `/slovar` index | 0.5 (already; only if `index_enabled`) |
| Word URLs | 0.4 + `isIndexable()` (already; still withheld until flags are set) |

Cache key `sitemap.xml` is hourly — after deploy, `artisan cache:forget sitemap.xml` (or rely on `optimize:clear` inside `deploy.sh`).

---

## 4. Contextual links (W2)

A Blade partial, e.g. `partials/samskrtam-related.blade.php`, included from `shop/show` and `main`.

- Input: a committed allowlist `database/data/seo/samskrtam_related.json` (`course_slug` → list of `{url, anchor}`).
- Build-time / test-time: each `url` must start with `https://samskrtam.ru/` and have been recorded as HTTP 200 in `samskrtam_related.lock.json` (fetched once, committed).
- Runtime: render only lock-listed URLs. No live HTTP from the request path.
- Homepage gets a short «читать на samskrtam.ru» strip from the same file (`"_home"` key).

---

## 5. Generated `/llms.txt` (W3)

New route, not a committed dump:

- `GET /llms.txt` → `LlmsTxtController`, `Content-Type: text/plain; charset=utf-8`.
- Cache ~1 hour (same idea as the sitemap).
- Body: org name, URL, `#org` facts already on the homepage, then one line per `Course::where('is_visible', true)` (`title`, `/k/{slug}`, format).
- Omit student areas. Omit `/slovar/{slug}` until those URLs are actually indexable.
- Also list `/` `/online` `/slovar` and the two existing `/s/` articles.

---

## 6. P2 packet (W1 write, W4 execute)

Do not change [dictionary_seo.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/dictionary_seo.php) defaults from the agent path. The packet is a doc (see IMPLEMENTATION step 5) that restates the already-measured command from [SEO_P2_INDEXATION_WAVE_LOG_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_P2_INDEXATION_WAVE_LOG_2026.md):

```
php artisan dictionary:mark-core-indexable database/data/seo/seo_core_headwords_dcs_lexical_cores.txt --limit=435
```

`--reset` is the reversal. Agent halt if this runs without a human Webmaster watch.

---

## 7. Build-vs-reuse

Reuse: Course columns, shop/show title binding, SitemapController, P0 `@id` spine, P2 gates, deploy.sh, watcher-safe-commit, playbook.

Build: CSV + artisan, sitemap priority predicates, homepage/online copy edits, W2 partial + allowlist, W3 route, W1 P2 packet prose, W5 KPI template.

_Dr. Mārcis Gasūns_
