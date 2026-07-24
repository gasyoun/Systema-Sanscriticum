# ARCHITECTURE — PWG Arzamas-style material

_Created: 24-07-2026 · Last updated: 24-07-2026_

Parent index: [PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md)

---

## 1. Component boundaries

```
[Primary sources]          [Secondary scholarship]       [Org papers]
 PWG/prefaces/*             Cardona / standard refs        A36, A49 scaffold
 csl-orig PWG samples       encyclopedias only as          csl-atlas stats
 Cologne about              leads to primary
        \                        |                              /
         \                       v                             /
          -----> [docs/materials/pwg-arzamas/] <--------------
                      SOURCE.md  (chapter markdown)
                      FACTS.md   (claim → source rows)
                      ASSETS.md  (image rights)
                      body.html  (rendered HTML snapshot)
                              |
                              v
                 [Artisan: materials:import-pwg-arzamas]
                              |
                              v
                    [articles table / Article model]
                     slug, title, subtitle, excerpt,
                     body (HTML), cover_path, author_name,
                     reading_time, is_published, meta_*
                              |
              +---------------+----------------+
              v                                v
   GET /s/{slug}                         GET /online/materialy
   articles/show.blade.php               MaterialsController
   hero + {!! body !!} + TOC from h2     card type «Текст»
   soft CTA block at end of body
```

**No new models.** Curator `Media` + `article_inline_images` optional for gallery;
preferred wave-1 path = images as static files under `public/images/materials/pwg/`
(or storage disk already used by Article covers) referenced from HTML `body`.

---

## 2. Data contracts

### 2.1 Source markdown (`SOURCE.md` or `chapters/*.md`)

- One logical document; chapters as `## N. Title` matching site `h2`
- Russian register: educated lay (Arzamas), not academic apparatus in main flow
- Footnote-style asides allowed as `<aside class="article-note">` or italic parentheticals
- External links: full URLs (Cologne, csl-guides, GitHub blob for prefaces)

### 2.2 FACTS.md

Table columns (mandatory):

| claim_id | chapter | claim_text | source_type | source_ref | status |
|---|---|---|---|---|---|

- `source_type`: `primary_preface` | `primary_entry` | `paper` | `secondary` | `org_stat`
- `status`: `verified` | `hedged` | `cut`
- ACCEPT requires zero rows in draft without `verified` or `hedged`; no silent invent

### 2.3 ASSETS.md

| asset_id | chapter | file | source_url | license | alt_ru | used |
|---|---|---|---|---|---|---|

- PD XIX portraits/title pages; Cologne scan thumbs with attribution; SVG diagrams (CC-BY school)
- Unclear rights → `used=no` and chapter ships without that figure

### 2.4 Article row (import)

| Field | Value |
|---|---|
| `slug` | `peterburgskiy-slovar-pwg` (D24) |
| `title` / `subtitle` / `excerpt` | from SOURCE frontmatter |
| `body` | HTML with ≥15 `h2`, figures, end CTA |
| `author_name` | `Dr. Mārcis Gasūns` |
| `reading_time` | computed from body word count (~200 wpm) |
| `is_published` | `true` only after ACCEPT |
| `category_id` | existing materials/articles category if present; else null + log |
| `meta_title` / `meta_description` | SEO RU ≤60 / ≤160 chars |

---

## 3. Interfaces reused (do not rebuild)

| Interface | Location | Role |
|---|---|---|
| `Article` model | `app/Models/Article.php` | Persistence |
| Show view + TOC JS | `resources/views/articles/show.blade.php` | Render; TOC from `h2` |
| Materials hub | `MaterialsController` + `shop/materials.blade.php` | Discovery |
| Watcher-safe commit | org skill | All Systema file writes |
| RWS councils | RuWritingStyles CLI | `sanskrit` + `indology` |

---

## 4. Build-vs-reuse summary

| Need | Decision |
|---|---|
| Longread chrome (hero, TOC, progress, JSON-LD) | **Reuse** Article show |
| Magazine discovery | **Reuse** H387 Materials |
| How to read an entry | **Link** csl-guides entry-anatomy |
| Historical claims | **Author** new prose; **cite** prefaces + papers |
| Import path | **Build small** artisan command + test (only new code) |
| Image CDN | **Reuse** public/ or existing media disk |

---

## 5. Security / rights notes

- Article body is `{!! !!}` trusted HTML from admin import — import path must not
  accept untrusted remote HTML; only repo-controlled `body.html`
- No PII; no student data
- Image licenses recorded; no modern copyrighted photos without clearance
- Soft CTA may link catalog / «с чего начать» — not payment endpoints with new logic

---

_Dr. Mārcis Gasūns_
