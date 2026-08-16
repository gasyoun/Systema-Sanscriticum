# GEO / AI-search audit — samskrte.ru (16-08-2026)

_Created: 16-08-2026 · Last updated: 16-08-2026_

**Score:** 66 / 100 · **Model:** Grok 4.6 (`grok-4.6`) seo-geo specialist · **Engine:** Yandex-primary.

Input to H2 **W3** (and W1 copy hygiene). Does **not** override [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md) rulings.

---

## How this maps to locked H2 rulings

| Specialist suggestion | H2 ruling | What W3/W1 actually does |
|---|---|---|
| Static `/llms.txt` from a paste outline | H2-8 / H2-13: **generated route** from live Course | Ship `GET /llms.txt` querying visible courses. May include the org/fact header from §outline below as **template text**, not a committed dump as source of truth |
| Comment AI-bot groups / block CCBot+Bytespider in `robots.txt` | H2-24 fence + residual: **do not edit robots AI rules** | Leave `User-agent: *`. Student Disallows stay |
| YouTube `sameAs` | Already an open human `@DO` on [SEO_ROADMAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_ROADMAP_2026.md) | W3 may add `https://www.youtube.com/@samskrtamru` to Organization `sameAs` if the URL still resolves |
| Three 140-word definition blocks | H2-5 / H2-18: homepage + `/online` copy open; no invented stats | W1 may add a sourced org paragraph on `/` and fix **5 000+ vs 7 000**. Article H2 rewrite is W3 (samskrtam stays the only long-form *archive*; existing `/s/zachem-sanskrit` may be tightened) |
| Keep `/slovar/*` noindex | P2 D1/D2 | Unchanged |
| Do not add FAQPage for Google | Playbook + H2 fence | Unchanged |
| Do not blanket-block AI crawlers | Aligns with fence | Unchanged |

---

## Live facts

- `robots.txt`: one `User-agent: *`. GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Yandex, CCBot, Bytespider all inherit the same allow minus cabinet/checkout.
- `/llms.txt`, `/llms-full.txt`, `/ai.txt`, `/.well-known/rsl.xml`: **404**.
- `/s/zachem-sanskrit` is the only page likely to be quoted (lede, byline, Article+Person JSON-LD). Homepage is a sales lede; Kochergina `/k/` has credentials but no extractable “what this course is” paragraph (dates look like two months).
- **Entity inconsistency:** homepage/newsletter «5 000+ учеников» vs article «ОРС ≈ 7 000 участников». Reconcile to one official figure + date in W1 copy (H2-18: no invented stats — pick the number already used on the homepage unless a human supplies a counted replacement).
- YouTube channel [Ревнитель Санскрита](https://www.youtube.com/@samskrtamru) exists and is **not** in Organization `sameAs` (VK + Telegram only).
- No ruwiki / Wikidata item for ОРС or Гасунс. Out of H2 agent scope (notability / human).

---

## Suggested `llms.txt` header (template for the generated route)

Not a committed dump. The controller may emit this header, then one line per visible `Course`.

- Org: Общество ревнителей санскрита · [https://samskrte.ru](https://samskrte.ru)
- Do not confuse with [samskrtam.ru](https://samskrtam.ru) (archive / bio).
- Cite pages: `/s/zachem-sanskrit`, flagship `/k/grammatika-po-kocerginoi-gr42`, `/online`, [samskrtam.ru/marcis-gasuns](https://samskrtam.ru/marcis-gasuns/).
- Do not cite `/slovar/*`, `/cabinet`, `/course/`, `/checkout/` as articles.

---

## Platform odds (specialist)

Alice / YandexGPT: best chance, article only. GigaChat / ChatGPT: low unsolicited until a Wikipedia/Wikidata item exists. Google AI Overviews ignore `llms.txt`.

_Dr. Mārcis Gasūns_
