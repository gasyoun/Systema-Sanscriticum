# VERIFICATION — Wave 3 · Generated `/llms.txt` + cheap technical leftovers

_Created: 16-08-2026 · Last updated: 16-08-2026_

**Umbrella ID:** `SAMSKRTE-SEO-H2` · **Pack:** `/ask` samskrte.ru SEO 16-08-2026 · **Stem:** `*_SYSTEMA_SAMSKRTE_SEO_H2_*`

Index: [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md).  
Implementation: [IMPLEMENTATION_SYSTEMA_SAMSKRTE_SEO_H2_W3.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_SAMSKRTE_SEO_H2_W3.md).

---

## Acceptance

| Deliverable | Command / flow | Pass |
|---|---|---|
| Generated route | `GET /llms.txt` | 200; `Content-Type: text/plain; charset=utf-8` |
| Catalog truth | Feature test + live curl | ≥1 `is_visible` course as `/k/{slug}`; hidden course absent |
| Surfaces | Feature test | Lists `/`, `/online`, `/slovar`, published `/s/{slug}` |
| Fence | Feature test | Body does not list `/cabinet` or `/dvaram`; no `/slovar/{slug}` word URL |
| Not a dump | Diff | No committed static `public/llms.txt` as source of truth |
| Cache | Feature test | Course title change appears on the next GET (observer flush) |
| og:image dims | Curl `/` | `og:image:width` + `og:image:height` present |
| YouTube sameAs | Curl `/` | `https://www.youtube.com/@samskrtamru` in Organization `sameAs` (URL resolved 200 on 16-08-2026) |
| Schema regression | Curl one `/k/{slug}` | `"@type":"Course"` still present |
| Fence files | Diff | No `robots.txt`, Metrika, Payment/Tariff/checkout, or `is_indexable` writes |
| Deploy smoke | `deploy.sh` then curl `/llms.txt` | HTTP 200. Login and checkout still load (do not complete a payment) |

## Test strategy

- New Feature tests under `tests/Feature/Seo/`.
- Do not run the full suite unless a touched file sits outside Seo + the observer + the two Blades + routes. Pint on touched PHP.
- Articles via `Article::query()->create` (no Article factory). Dictionary word via the same create path as [DictionaryPageTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/DictionaryPageTest.php).
- No Dusk. No money-contour tests. No IndexNow. No `robots.txt` edit.

## Risks

| Risk | What to do |
|---|---|
| APP_URL in tests differs from samskrte.ru | Assert path fragments (`/k/slug`), not the host |
| Cache hides a catalog change | Observer must forget `llms.txt` with `sitemap.xml` |
| HSTS not one-line | Skip; do not invent a SecurityHeaders middleware this wave |
| YouTube URL dies later | Residual: drop the `sameAs` row in a later sitting; do not block W3 |
| Watcher reverts Blade | Scratchpad → one-shell land+commit |

W4–W5 stay out of this bar.

_Dr. Mārcis Gasūns_
