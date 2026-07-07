# Roadmap: Interactive Lead-Magnet Fleet 2026 (Q3 2026 →)

_Created: 07-07-2026 · Last updated: 07-07-2026_

> Narrow roadmap for a **4-part interactive lead-magnet (ЛМ) fleet** aimed at the widest
> top-of-funnel for the samskrte.ru course business: a transliterator tool, a level quiz, a
> plain-language beginner article, and a word game — each free, bilingual (RU+EN), and wired
> into the Systema funnel from day one. Sibling to the general
> [`docs/ROADMAP_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md)
> (whose **C — Content / acquisition** stream this extends without duplicating) and to
> [`docs/ROADMAP_CONTENT_AI_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_CONTENT_AI_2026_2027.md)
> (which owns the *content-ops inbox* slice — this owns *acquisition lead magnets*, the
> top of the same funnel). The **executable build specs** live as four handoffs in the
> management hub — this doc is the durable *why/what*, the handoffs are the *how* per build
> session.

**Origin:** an ED Global круглый-стол marketing post on lead magnets, shared by MG
07-07-2026 → four rulings captured via structured questions (§2). Strategy + roadmap
authored by **Opus 4.8** (`claude-opus-4-8`). The interactive LMs are cheap to build *now*
because AI has collapsed the cost; they are worth **us** building because our canonical
Sanskrit data is a moat nobody else can copy (§1).

---

## 1. The thesis — why interactive LMs, why us

The круглый-стол's operative line: *«Благодаря расцвету ИИ можно очень быстро создавать
сложные ЛМ бесплатно… Игры, калькуляторы, тесты — то, что привлечёт.»* Interactive lead
magnets (calculators, tests, games) used to be expensive; AI has made them cheap.

**Our unfair advantage:** a competitor can clone a transliterator UI in an afternoon — they
**cannot** clone the dictionary/corpus behind it. Our 44 dictionaries, canonical transcoders
([`sanskrit-util`](https://github.com/sanskrit-lexicon/sanskrit-util)), and DCS frequency
tables are the data moat. That is the entire reason these four LMs are worth building here
rather than buying a generic quiz plugin. Each LM doubles as a public showcase of the
lexicon work **and** a mouth of the samskrte.ru funnel.

---

## 2. MG's decisions (07-07-2026)

| Fork | Ruling |
|---|---|
| Target audience (ЦА) | **Complete beginners** — the widest top-of-funnel for samskrte.ru courses |
| Formats | **All four**, one build each: tool · quiz · article · game |
| Host + language | **Bilingual** — build once in csl-guides (EN), embed in Systema RU storefront |
| Funnel depth | **Full funnel from day one** — capture → free first webinar → tripwire → flagship |
| RU embed host | **[`Systema-Sanscriticum`](https://github.com/gasyoun/Systema-Sanscriticum)** — capture posts to its own lead store |
| Webinar tooling | **Zoom** — the доходимость bridge is a dated free first webinar class |
| Product-ladder entry | **Tripwire first, or a FREE first webinar class** — never a straight jump to the flagship |

---

## 3. The four lead magnets

Build home for all four is [`csl-guides`](https://github.com/sanskrit-lexicon/csl-guides)
(Docusaurus; already embeds React widgets and has opt-in quiz telemetry from H288). Build the
widget once, ship EN on csl-guides (public), embed RU into the Systema-Sanscriticum storefront
where capture + funnel live.

| # | LM | Funnel job | Backing data asset (consume, don't rebuild) | Build handoff |
|---|---|---|---|---|
| 1 | **"Your name in Devanagari" transliterator** | Widest top-of-funnel + shareable image | SLP1↔IAST↔Devanagari↔HK transcoder ([`sanskrit-util`](https://github.com/sanskrit-lexicon/sanskrit-util)) | [H312](https://github.com/gasyoun/Uprava/blob/main/handoffs/H312-Sonnet_csl-guides_beginner-lm-transliterator-tool_07.07.26.md) (anchor, Sonnet 5) |
| 2 | **"What's your Sanskrit level?" quiz** | Best warmth-**segmenter** (score → email) | frequency-ranked headwords (VisualDCS) + H288 quiz telemetry | [H313](https://github.com/gasyoun/Uprava/blob/main/handoffs/H313-Sonnet_csl-guides_beginner-lm-level-quiz_07.07.26.md) (Sonnet 5) |
| 3 | **Plain-language beginner article** | The cheap "strong LM"; routes to the other three | preface/beginner explainer content | [H314](https://github.com/gasyoun/Uprava/blob/main/handoffs/H314-Fable_csl-guides_beginner-lm-first-word-article_07.07.26.md) (Fable 5) |
| 4 | **Word / flashcard-streak game** | Retention + virality (loose direct conversion) | frequency-ranked headwords + dict glosses ([`csl-websanlexicon`](https://github.com/sanskrit-lexicon/csl-websanlexicon)) | [H315](https://github.com/gasyoun/Uprava/blob/main/handoffs/H315-Sonnet_csl-guides_beginner-lm-word-game_07.07.26.md) (Sonnet 5) |

**Build order:** H312 → H313 → H314 → H315 (tool proves the rails; quiz proves segmentation;
article stitches them; game adds retention).

---

## 4. The resolved funnel ladder — build to this

```
free LM (no gate to try)
   └─> soft email/lead capture → Systema-Sanscriticum lead store
         └─> FREE first Zoom webinar class  ← доходимость bridge, dated, no price
               └─> tripwire (cheap paid intro)
                     └─> flagship course (full price)
```

The widget only owns the first two rows (the free tool + a capture/CTA). The webinar,
tripwire and flagship are configured on the Systema side — so each widget build is fully
specified without waiting on product-ladder configuration.

### Three caveats from the круглый-стол, baked into the design

1. **Cold traffic + LM often doesn't pay back** → v1 targets the **warm** samskrte.ru list /
   ORS-FAQ funnel, not paid cold ads.
2. **LM-grabbers attend webinars badly (доходимость)** → every LM *actively hands off* to a
   dated free Zoom webinar; no dead-end result screens.
3. **Tripwire → flagship gap** *(«после трипваеров флагман покупать не хотят»)* → the ladder
   is designed so the free webinar and tripwire **feed** the flagship, and the LMs never ask
   for the flagship straight off a free toy.

---

## 5. How to start a build

Each LM is one build session. Paste the matching one-line starter into a fresh chat
(`cd` into `C:\Users\user\Documents\GitHub\csl-guides`):

```
Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H312-Sonnet_csl-guides_beginner-lm-transliterator-tool_07.07.26.md and execute it.
```

H312 is the anchor — its §0 carries the shared strategy and the resolved funnel ladder that
H313/H314/H315 reference. Start there.

---

_Dr. Mārcis Gasūns_
