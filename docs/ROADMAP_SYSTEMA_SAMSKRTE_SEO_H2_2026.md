# ROADMAP — SAMSKRTE-SEO-H2 · samskrte.ru SEO · H2 2026

_Created: 16-08-2026 · Last updated: 30-08-2026_

> **Truth-pass 30-08-2026 (Fable 5 `claude-fable-5`, `/ask` H3760):** Waves 2–3 executed — их handoff'ы
> в архиве реестра ([H2918 (Grok 4.6, 🟡2 medium) — W2 contextual links](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2918-Grok_Systema-Sanscriticum_samskrte-seo-h2-w2_16.08.26.md),
> [H2935 (Grok 4.6, 🟡2 medium) — W3 llms.txt + technical leftovers](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2935-Grok_Systema-Sanscriticum_samskrte-seo-h2-w3_16.08.26.md)).
> Wave 4 остаётся prod-gated (`index_enabled` — человеческий шаг, DEPLOY_QUEUE); Wave 5 ждёт W4.

**Umbrella ID:** `SAMSKRTE-SEO-H2` · **Pack:** `/ask` samskrte.ru SEO 16-08-2026 · **Stem:** `*_SYSTEMA_SAMSKRTE_SEO_H2_*`

Index: [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md).  
Status ledger (P0/P1/P2 shipped vs gated): [SEO_ROADMAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_ROADMAP_2026.md).

Primary engine: **Yandex**. Secondary: Google. Goal rank (locked): (1) course sales (2) articles→funnel via samskrtam.ru (3) dictionary magnet.

---

## Waves

Each wave names what it unblocks. Do not start a later wave's *product* work before the earlier wave's acceptance is green, except where marked parallel-safe.

### Wave 1 — Money-page hygiene (shipped 16-08-2026)

**Unblocks:** unique SERP snippets for every public course; honest sitemap weights; a human-ready `/slovar` packet.

**Shipped:** [H2893 (Grok 4.6) — samskrte.ru SEO H2 wave-1 money-page hygiene](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2893-Grok_Systema-Sanscriticum_samskrte-seo-h2-w1_16.08.26.md) · [PR #1761](https://github.com/gasyoun/Systema-Sanscriticum/pull/1761) · [v1.89.57](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.89.57) · evidence [SEO_H2_W1_EVIDENCE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_H2_W1_EVIDENCE_2026.md). Live curls PASS. P2 write still fenced.

- Unique `meta_title` / `meta_description` for **every** sitemap `/k/{slug}` (title ≤60, description ≤160). Store in existing columns. Source of truth = committed UTF-8 CSV + `seo:fill-course-meta`.
- Tighten homepage (`resources/views/main.blade.php`) and `/online` (`shop/index`) RU copy so title/description/H1 say «курсы санскрита с нуля» without a visual redesign.
- SitemapController rules: slug contains `donat` → priority 0.3; `format === recorded` → 0.6; other visible courses → 0.8. Home stays 1.0.
- Write the P2 wave-1 packet (exact artisan, `--reset`, sitemap submit, log-row template). **Do not run** `dictionary:mark-core-indexable` without `--dry-run`.
- PHPUnit on artisan + sitemap + meta fallback. After merge: `deploy.sh` + curl homepage, one `/k/`, `/online`. Then prod apply the CSV.

**Handoff:** wave-1 only (minted with this pack).

### Wave 2 — Contextual links to samskrtam.ru · ✅ executed (H2918 archived, сверка 30-08-2026)

**Handoff:** [H2918 (Grok 4.6) — samskrte.ru SEO H2 wave-2 contextual links to samskrtam.ru](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2918-Grok_Systema-Sanscriticum_samskrte-seo-h2-w2_16.08.26.md). Execute via `/go 2918` in a Grok chat. Do not start W3 here.

**Unblocks:** Yandex/YATI depth without a second essay archive.

- Related-reading blocks on `/k/{slug}` and the homepage that point **only** at samskrtam.ru URLs the agent has fetched as HTTP 200.
- No new `/s/` essays on samskrte. `/s` may stay as the thin existing index (two articles).
- Allowlist of live URLs committed next to the block. Dead link = fail.

**Depends on:** W1 merge (so money pages are the ones receiving links). Links themselves do not depend on the CSV apply.

### Wave 3 — GEO + technical · ✅ executed (H2935 archived, сверка 30-08-2026)

**Handoff:** [H2935 (Grok 4.6) — samskrte.ru SEO H2 wave-3 generated /llms.txt + cheap technical leftovers](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2935-Grok_Systema-Sanscriticum_samskrte-seo-h2-w3_16.08.26.md). Do not start W4/W5 here.

Evidence snapshot (16-08-2026, score 66/100): [SEO_GEO_AUDIT_SAMSKRTE_16.08.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_GEO_AUDIT_SAMSKRTE_16.08.26.md). Generated `/llms.txt` stays; do not replace it with a static dump.

**Unblocks:** AI citation surface + crawl hygiene that does not touch Metrika.

- Public route `GET /llms.txt` listing org facts + live public courses (cached). Live is 404 today.
- Technical leftovers that are cheap and fenced: HSTS/Referrer-Policy only if one-line nginx/Laravel; `og:image` dimensions if missing; do **not** edit Metrika; do **not** change student `robots` Disallows; do **not** add AI-crawler blocks.
- IndexNow: skip unless it is a one-file add (residual default: skip).

**Depends on:** W1 catalog truth (course list for llms.txt is the same `is_visible` set).

### Wave 4 — P2 human indexation waves

**Unblocks:** dictionary magnet (goal #3).

- Human watches Yandex.Webmaster and runs the W1 packet (`--limit=435` ≈ 805 rows).
- Agent may sit with the human, update [SEO_P2_INDEXATION_WAVE_LOG_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_P2_INDEXATION_WAVE_LOG_2026.md), and submit the sitemap chunk. Agent still must not write `is_indexable` alone.
- Later prefixes (`--limit=2000`, full list) only after a written Webmaster reading on the previous wave.
- `dictionary:match-wikidata --write` stays human-reviewed (spot-check found 2/5 conceptual FPs).

**Depends on:** W1 packet existing. Does **not** depend on W2/W3.

### Wave 5 — Measurement

**Unblocks:** knowing whether W1–W4 moved organic.

- If GSC or Yandex Webmaster/Metrika API credentials appear in [SECRETS_INDEX.md](https://github.com/gasyoun/Uprava/blob/main/docs/SECRETS_INDEX.md): pull organic clicks/impressions / indexed URL counts. Catalog the filename only, never the value.
- If credentials are absent: write `docs/SEO_H2_KPI_TEMPLATE_2026.md` (organic visits, ИКС, indexed `/k/`, `/slovar` wave rows) and park the pull. Human paste fills it.
- No new Filament SEO dashboard this H2.

**Depends on:** something live to measure (W1 deploy at minimum).

---

## Non-goals (considered and ruled out)

- Rebuilding Organization / WebSite / Course / Offer / BreadcrumbList / FAQPage.
- Indexing all 11,892 dictionary rows, or running wave 1 without Webmaster.
- New long-form essays on samskrte.ru `/s`.
- Canonical-collapsing Kochergina group URLs.
- English product or hreflang.
- Removing FAQPage (Google retired the rich result 07-05-2026; Yandex still consumes it).
- HowTo schema.
- Touching Metrika, student `robots` Disallows, Payment/Tariff/checkout, club/28-08 flags.
- samskrtam.ru WordPress / `/ors-seo` rungs (different site, different skill).
- Mixing this pack with [SAMSKRTE-TIER0](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md) marathon 28-08 work.

---

## Decisions taken

See the H2-1…H2-25 table in [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md). P2 D1–D4 remain in [DECISIONS_seo_p2_dictionary_pages.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_seo_p2_dictionary_pages.md).

_Dr. Mārcis Gasūns_
