# SEO Roadmap — samskrte.ru (Общество ревнителей санскрита)

_Created: 05-07-2026 · Last updated: 30-08-2026_

> **Truth-pass 30-08-2026 (Fable 5 `claude-fable-5`, `/ask` H3760):** сверка против origin/main —
> P2-код по-прежнему готов и по-прежнему ждёт prod-включения `index_enabled` (человеческий шаг,
> DEPLOY_QUEUE); статусный хребет ниже не изменился. H2-исполнение живёт в
> [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md)
> (W2–W3 executed, W4 prod-gated — см. truth-pass в [ROADMAP_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_SAMSKRTE_SEO_H2_2026.md)).

**H2 execution pack (16-08-2026):** next work is [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md) (waves W1–W5, 25 interview rulings). This file stays the **P0/P1/P2 status ledger** — do not rebuild the spine below.

**Primary engine: Yandex. Secondary: Google.** Framing method: entity/semantic-SEO
concepts from [seobythesea.com](https://www.seobythesea.com) (Bill Slawski — Google-patent-derived:
entity salience, knowledge-graph triplets, topical authority) re-ranked for a Yandex-first
Russian course storefront.

---

## 0. The Yandex-first recalibration (read this before spending effort)

seobythesea material is almost entirely **Google-patent-derived**. It maps to our
**Google-second** channel and to the dictionary long-tail — it is *not* how Yandex, our
primary engine, ranks. Yandex is dominated by, in order:

1. **Behavioral factors** (dwell, return-to-SERP, click satisfaction) via Metrika — **already live**:
   [`layouts/articles.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/articles.blade.php)
   runs Metrika with `webvisor`, `clickmap`, `accurateTrackBounce`. This is the single biggest Yandex lever and it is shipped.
2. **YATI / neural text relevance** — depth and topical coverage of the Russian text, not markup.
3. **Host authority (ИКС)** + Yandex.Webmaster / Yandex Business registration.
4. A *narrow* schema set Yandex actually consumes for SERP features: **FAQPage, BreadcrumbList, Organization, prices/Offer**.

**Consequence for the "deep entity-graph + Wikidata triplets" ambition:** it is worth doing,
but it pays off on **Google + the dictionary long-tail**, not on the Yandex primary. It is
therefore sequenced as **P2**, *after* the cheap Yandex + commercial wins — not first.

---

## 1. Current state — already solid (do NOT rebuild)

| Asset | Where | Notes |
|---|---|---|
| Sitemap | [`SitemapController.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/SitemapController.php) | home, `/online`, articles index, landing pages, courses, articles, with `lastmod`/`priority`. Hourly cache. Clean. |
| robots.txt | [`public/robots.txt`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/robots.txt) | Correctly blocks the **student** area (`/course/{slug}`, [`routes/web.php:161`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php#L161)) while leaving **sales** pages `/online/kursy/{slug}` ([`routes/web.php:67`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php#L67)) crawlable. No accidental blocking. |
| Article schema | [`articles/show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/articles/show.blade.php) | `Article` JSON-LD; layout supplies canonical + OG + `og:image` dims. |
| FAQ schema | [`promo/blocks/faq_block.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/promo/blocks/faq_block.blade.php) | `FAQPage` JSON-LD — Yandex-consumable. |
| Behavioral analytics | article + main layouts | Yandex Metrika (webvisor) + VK/mail.ru pixel. |

---

## 2. Gaps, ranked by leverage × goal

Goals, ranked by MG: **(1) course sales · (2) articles→funnel · (3) dictionary traffic magnet.**

| ID | Gap | Serves goal | Engine | Effort |
|---|---|---|---|---|
| **P0-a** | No `Course`/`Offer`/`Product` schema on sales pages — [`shop/show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/show.blade.php) has ₽ prices but zero structured offer markup | 1 sales | Yandex prices + Google | S |
| **P0-b** | No site-wide `Organization` + `WebSite` JSON-LD (no `sameAs`, no logo entity, no `SearchAction` sitelinks-searchbox). The `@id` spine everything else links to | all | both | S |
| **P0-c** | No `BreadcrumbList` schema anywhere → loses breadcrumb SERP rows on both engines | 1 + 2 | both | S |
| ~~**P1-a**~~ ✅ | Article author now `Person` (Org fallback) + `mainEntityOfPage` ([`show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/articles/show.blade.php)); `author.url` deferred (no author entity yet) — E-E-A-T signal added | 2 articles | Google | S |
| **P1-b** | Topical-authority / internal-linking gaps in articles — YATI rewards depth + cluster linking. The real Yandex *content* lever | 2 articles | Yandex primary | M (ongoing) |
| **P2** | Dictionary corpus completely unexposed — [`DictionaryWord.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DictionaryWord.php) (`devanagari`/`iast`/`cyrillic`/`translation`) has **no routes, no pages, not in sitemap**. The traffic magnet — greenfield | 3 dictionary | Google long-tail | **L** |

---

## 3. P0 batch — ship first (one session, high ROI, both engines)

**Status: ✅ Shipped 05-07-2026 (handoff H193).** JSON-LD wired via a shared
[`partials/schema-breadcrumbs.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/schema-breadcrumbs.blade.php)
+ per-page blocks. All three validate as JSON. YouTube `sameAs` shipped in W3.
One follow-up stays open as GTD `@DO`: Yandex.Webmaster/Business registration.

All three are small, low-risk, and help sales *now*.

1. ✅ **`Organization` + `WebSite` JSON-LD in the base layout** ([`main.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/main.blade.php)):
   - ✅ `Organization` with `@id` (`https://samskrte.ru/#org`), `name`, `url`, `logo`, `sameAs` → VK + Telegram + YouTube (`https://www.youtube.com/@samskrtamru`, W3).
   - ✅ `WebSite` with `potentialAction` → `SearchAction` (target `…/online?search={search_term_string}`).
   - ⏳ Register the same Organization in **Yandex.Webmaster + Yandex Business** (human `@DO`).
2. ✅ **`Course` + `Offer` JSON-LD on** [`shop/show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/show.blade.php):
   - ✅ `Course` (`name`, `description`, `provider` → the `@id` Organization) + one `Offer` per active tariff (`price`, `priceCurrency: RUB`, `availability`, `url`). Drives price-rich results.
   - ✅ `hasCourseInstance` (`courseMode: online` + `courseWorkload` from `hours_count`, plus `location`/`startDate`/`endDate`/`instructor` when known) → qualifies for Google's **Course Info** carousel. Emitted only when a workload is derivable, so a course without hours degrades to a valid basic `Course`.
3. ✅ **`BreadcrumbList` JSON-LD** on article + course + landing pages (Home → section → item), via the shared partial.

**Do NOT** touch behavioral analytics (already optimal) or the sitemap structure (correct).

---

## 4. P1 — E-E-A-T + the Yandex content lever

- **P1-a:** ✅ **done** — Article `author` now emits `Person` (with `Organization` fallback when
  `author_name` is empty) + `mainEntityOfPage`, and `publisher`/fallback-author link the `https://samskrte.ru/#org`
  spine, in [`articles/show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/articles/show.blade.php).
  `author.url` deferred: no per-author profile/entity exists yet — add an `author_url` (or author entity)
  field first, then populate it here.
- **P1-b (ongoing, highest Yandex payoff):** topical clustering — group articles into
  RU Sanskrit topic hubs, add contextual internal links between related articles/courses/dictionary
  entries. This feeds YATI relevance and behavioral depth. Not a one-shot; a content discipline.

---

## 5. P2 — dictionary entity pages + semantic triplets (the deep seobythesea work)

**Prioritized per MG (05-07-2026): "in scope soon".** This is the only real home for the
entity-graph / Wikidata-`sameAs` ambition. Scoped as its own project, NOT started in the P0 pass.

### 5.1 What it is
Public word pages `/slovar/{slug}` over
[`DictionaryWord`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DictionaryWord.php)
(`devanagari` ↔ `iast` ↔ `cyrillic` ↔ `translation`, grouped by
[`Dictionary`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Dictionary.php)),
each carrying:

- `DefinedTerm` / `DefinedTermSet` JSON-LD, `inLanguage: sa`, `inDefinedTermSet` → the dictionary.
- The concrete **semantic triplets**: *word* — `sameAs` → **Wikidata/DBpedia** concept; *word* — `inLanguage` → `sa`; *word* — `isPartOf` → dictionary `@id`.
- `@id`-linked graph so words ↔ articles ↔ courses share one entity spine (P0-b Organization at the root).

### 5.2 Build checklist (L-sized) — ✅ Wave 0 built 05-07-2026 (all `noindex,follow`)
- ✅ Routes `/slovar` + `/slovar/{slug}` (before catch-all) + `slug`/`wikidata_qid` columns + `makeHeadwordSlug()`/auto-slug on `DictionaryWord`.
- ✅ Index page `/slovar` + per-dictionary `?dict=` listing; [`DictionaryPageController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/DictionaryPageController.php) + blades.
- ✅ Canonical per-headword page (D3 collapse) + `DefinedTerm` JSON-LD (`inLanguage: sa`, `inDefinedTermSet`, conditional `sameAs`) via [`partials/schema-defined-term.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/schema-defined-term.blade.php) + OG + `BreadcrumbList`.
- ✅ Sitemap word-chunk in [`SitemapController.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/SitemapController.php) — **built but gated on `dictionary_seo.index_enabled` (withheld in Wave 0, D2)**.
- ✅ Cross-link related words (feeds P1-b); course/article cross-links = a later enrichment wave.
- ✅ **Wave-1 mechanisms built offline (H210, 08-07-2026 — ready to flip on deploy):**
  - Track A / D1 — curated-core allowlist: `dictionary_words.is_indexable` column + [`dictionary:mark-core-indexable`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MarkCoreIndexable.php) (feeds it from the «Сборное ядро» / DCS-attested list by headword slug); [`DictionaryWord::isIndexable()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DictionaryWord.php) now gates on the allowlist under `curated_only`. Default-closed — flipping `index_enabled` still indexes nothing until the list is fed.
  - Track B / D4 — Wikidata `sameAs` matcher: [`WikidataSameAsMatcher`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Seo/WikidataSameAsMatcher.php) + [`dictionary:match-wikidata`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MatchWikidataSameAs.php) (exact Devanāgarī signal + P31 instance-of filter; **propose-only, `--write` after a spot-check**). Spot-check + residual-FP analysis: [`docs/WIKIDATA_SAMEAS_SPOTCHECK.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/WIKIDATA_SAMEAS_SPOTCHECK.md).
- ✅ **Wave machinery completed + measured on prod (H210, 15-08-2026, Opus 5 `claude-opus-5`):** the allowlist the command had never been given now ships as [`database/data/seo/seo_core_headwords_dcs_lexical_cores.txt`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/data/seo/seo_core_headwords_dcs_lexical_cores.txt) (7,120 IAST headwords consumed from the DCS lexical cores, **ordered** tier 2 pril10 → tier 3 pril5 → tier 4 sbornoe), and `dictionary:mark-core-indexable` gained `--limit=N` so a D2 wave is a prefix of that file. Dry-run of the **deployed** command against prod (16-08-2026): wave 1 (`--limit=435`) = **805 rows**, `--limit=2000` = 2,884, full list = 4,773 of 11,892. Log: [`docs/SEO_P2_INDEXATION_WAVE_LOG_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_P2_INDEXATION_WAVE_LOG_2026.md).
- ⏳ **Deploy-gated activation (human, prod):** `DICTIONARY_SEO_INDEX_ENABLED` is **already true** on prod, but `is_indexable` is 4 and `wikidata_qid` is 0, so nothing indexes yet and `/slovar` still emits `noindex, follow`. Remaining: run `dictionary:mark-core-indexable database/data/seo/seo_core_headwords_dcs_lexical_cores.txt --limit=435` **while watching Yandex.Webmaster** (D2's back-off signal — an agent has no Webmaster account), submit the sitemap chunk, record the wave row; and review then `dictionary:match-wikidata --write` (D4 — the spot-check found 2 of 5 exact matches conceptually wrong, so that review is not skippable).

### 5.3 ⚠️ Risk — thin-content / over-indexation on Yandex
Thousands of near-empty word pages (headword + one gloss) can trigger Yandex **thin-content /
low-quality** demotion and dilute host authority. Mitigations, decide before launch:
- Only expose words with a substantive `translation` (min length / quality gate); `noindex` the rest.
- Enrich pages (etymology, cross-refs, usage) before indexing, or roll out in indexation waves.
- Consider `<meta robots="noindex,follow">` on stubs until enriched.

**This risk is why P2 is a scoped project with its own gate, not a P0 quick win.**

---

## 6. Priority summary

1. **P0 batch** — Organization+WebSite spine, Course/Offer schema, BreadcrumbList. Cheap, helps sales now, both engines. → handoff **H193**.
2. **P1** — Person authors + `mainEntityOfPage`; begin the article topical-cluster/internal-link discipline (Yandex content lever).
3. **P2** — dictionary entity pages + Wikidata `sameAs` triplets, with the thin-content gate. The deep seobythesea work.

Human actions (Yandex.Webmaster + Yandex Business registration, VK/TG/YouTube `sameAs` URLs)
mirrored to [`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md).

_Dr. Mārcis Gasūns_
