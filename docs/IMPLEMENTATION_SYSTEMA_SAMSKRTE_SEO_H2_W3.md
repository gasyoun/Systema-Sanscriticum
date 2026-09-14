# IMPLEMENTATION — Wave 3 · Generated `/llms.txt` + cheap technical leftovers

_Created: 16-08-2026 · Last updated: 16-08-2026_

**Umbrella ID:** `SAMSKRTE-SEO-H2` · **Wave-3 handoff:** [H2935 (Grok 4.6) — samskrte.ru SEO H2 wave-3 generated /llms.txt + cheap technical leftovers](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2935-Grok_Systema-Sanscriticum_samskrte-seo-h2-w3_16.08.26.md) · **Pack:** `/ask` samskrte.ru SEO 16-08-2026 · **Stem:** `*_SYSTEMA_SAMSKRTE_SEO_H2_*`

Index: [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md).  
Architecture §5: [ARCHITECTURE_SYSTEMA_SAMSKRTE_SEO_H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_SAMSKRTE_SEO_H2.md).  
Acceptance: [VERIFICATION_SYSTEMA_SAMSKRTE_SEO_H2_W3.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_SAMSKRTE_SEO_H2_W3.md).  
GEO snapshot: [SEO_GEO_AUDIT_SAMSKRTE_16.08.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_GEO_AUDIT_SAMSKRTE_16.08.26.md).

Systema is guarded **and** watcher-afflicted. Worktree off `origin/main`. Do **not** edit `public/robots.txt`, Metrika snippets, Payment/Tariff/checkout, or prod `is_indexable`. Do **not** start W4/W5.

Ordered steps.

---

### Step 0 — Worktree + fence + W1 catalog truth

1. `git fetch origin`. Session-unique worktree off `origin/main`.
2. Confirm W1 is on `origin/main` (`seo:fill-course-meta` exists). Course list for `/llms.txt` is the same `is_visible` set as the sitemap.
3. Fence: no `public/robots.txt`, no Metrika, no money/checkout, no `dictionary:mark-core-indexable` write, no AI-crawler `User-agent` blocks.

### Step 1 — Generated route

**Files:** `app/Http/Controllers/LlmsTxtController.php`; route `GET /llms.txt` next to `/sitemap.xml` in [routes/web.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php) (before catch-all).

- `Content-Type: text/plain; charset=utf-8`.
- Cache key `llms.txt`, one hour (same idea as sitemap).
- Body: org name + URL + `#org` facts already on the homepage (template header from the GEO audit, not a committed dump as source of truth).
- Then surfaces: `/`, `/online`, `/slovar`, every `Article::published()` as `/s/{slug}`.
- Then one line per `Course::where('is_visible', true)`: title, `/k/{slug}`, format.
- Omit student areas. Omit `/slovar/{slug}` until those URLs are actually indexable.

### Step 2 — Cache flush

Extend [SitemapCacheInvalidator.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/SitemapCacheInvalidator.php) so Course/Article/LandingPage created/updated/deleted also `Cache::forget('llms.txt')`. No second observer.

### Step 3 — Cheap technical leftovers (fenced)

Do only what is one-line or already a missing twin of shipped markup:

- `og:image:width` / `og:image:height` on surfaces that already emit `og:image` but lack dimensions (homepage, slovar layout, promo legacy).
- Organization `sameAs` + YouTube `https://www.youtube.com/@samskrtamru` if the URL still HTTP-resolves.
- HSTS / Referrer-Policy: **only** if a one-line nginx/Laravel add exists. Live (16-08-2026) has `X-Frame-Options` + `X-Content-Type-Options` and no HSTS; a new middleware is not one-line → **skip**.
- IndexNow: residual default **skip**.
- Do not edit `robots.txt`. Do not add AI-crawler blocks.

### Step 4 — Tests + changelog + PR

1. PHPUnit `tests/Feature/Seo/LlmsTxtTest.php` + homepage og/sameAs assertions.
2. Pint on touched PHP.
3. [CHANGELOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CHANGELOG.md) `[Unreleased]` Added bullet. `/cut-release` after merge.
4. PR, merge (`gasyoun/*` always-merge). Remove the worktree after the push is confirmed.

### Step 5 — Deploy + live curl

On the known Systema host: [deploy.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh). Then curl (PASS/FAIL):

- `https://samskrte.ru/llms.txt` — 200, `text/plain`, ≥1 visible `/k/` course, no `/cabinet` or `/dvaram` as listed surfaces
- `https://samskrte.ru/` — `#org` still present; YouTube in `sameAs`; `og:image:width`
- one `/k/{slug}` — Course JSON-LD still present (regression)

W4–W5 are **out of this handoff**. Stop after the evidence note.

_Dr. Mārcis Gasūns_
