# Sanskrit-HUB — Learner Progression (A0 → C2)

_Created: 10-07-2026 · Last updated: 01-08-2026_

**Purpose.** A CEFR-shaped ladder for learning Sanskrit on
[samskrtam.ru/sanskritHUB](https://samskrtam.ru/), where **every rung is powered by a real
asset** we already have (or a clearly-named gap to build). This is the pedagogy spine that the
paid courses sell and the free NLP layer serves. Asset IDs resolve in
[`SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md)
against [`FEATURES_INDEX.md`](https://github.com/gasyoun/SanskritLexicography/blob/master/FEATURES_INDEX.md).

**Design constraint (from custdev, [`CUSTDEV_2026.md`](https://github.com/gasyoun/Uprava/blob/main/custdev/CUSTDEV_2026.md)):**
this audience's #1 objection is **TIME** → every rung is own-pace, ~15 min/day, evergreen (no
artificial urgency). The **first cohort (28 Aug 2026) is истинно нулевая** — cannot read or
write Devanāgarī → **A0 is Cyrillic-only**; script writing / recitation UGC is deferred to the
**January 2027 cohort**.

---

## The ladder at a glance

| Rung | Name | Can do | Gate to next | Powered by |
|---|---|---|---|---|
| **A0** | Устройство слова (Cyrillic) | Hear how a Sanskrit word is built; phonology in transliteration | Read 20 words in IAST | `sanskrit-util` (L1), intent quiz, OCR front-matter (F37) |
| **A1** | Script | Read + (later) write Devanāgarī, akṣara, mātrā | Read a śloka aloud slowly | SanskritKaraoke (H7), transliteration playground, **audio (gap)** |
| **A2** | Sandhi & first forms | Split simple sandhi; a-stem nouns; present tense | Parse a 4-word sentence | sandhi splitter (M11/M10), paradigm browser (I10), Zaliznyak index (E31), vidyut (M14) |
| **B1** | First reading | Read subhāṣitas / fables with click-gloss | Finish 50 subhāṣitas | Indische Sprüche (F33), glossary (A2/H5), kosha (G3), frequency SRS (E26) |
| **B2** | Classical text | Read epic prose/verse with grammar support | Read a Rāmāyaṇa sarga | RussianRamayana (H6), `corpus_lexicon` interlinear (A1), Samudra Manthanam (H4) |
| **C1** | Vedic & commentary | Accented Vedic; follow a commentary | Read an RV hymn + its commentary | VedaWeb (M13), GRA, commentary apparatus, Whitney roots (B9–B11) |
| **C2** | Scholar / lexicographer | Multi-dict philology, etymology, Skt-only defs | Write an evidence-graded gloss | CDSL all-dicts (G1), csl-atlas, etymology oracle (B7), SKD/VCP |

**Paid cohort packaging (A0–B1):** the 5-week «Старт чтения» Akro-style pilot packages script → first continuous prose on Systema (Hitopadeśa-0 / subhāṣita interim). Register: [PRODUCT_START_CHTENIYA_AKRO_STYLE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PRODUCT_START_CHTENIYA_AKRO_STYLE_2026.md). Distinct from the marathon 28-08 A0 diagnostic funnel.

---

## A0 — Устройство слова (Cyrillic-only, zero prerequisites)

**Learner reality.** Cannot read a single glyph. Russian-speaking. Time-anxious. The 28 Aug 2026
marathon cohort lives here.

**Learning outcomes.** Sanskrit is agglutinative-ish and sound-driven; a "word" is root + affixes;
the sound system (vowel length, retroflex, aspiration) — all shown in **Cyrillic + IAST**, never
Devanāgarī yet.

**Assets / features.**
- **Intent quiz** ([`ShopController.php:102–166`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/ShopController.php)) — routes the learner to a goal/path, not H313.
- **Transliteration playground** (`sanskrit-util`, L1) — type Cyrillic → see IAST/SLP1; feel vowel length.
- **Word-anatomy explainer** — a single frequent word (e.g. *namas*, *deva*) decomposed live.
- **Front-matter tour** (F37) — what dictionary abbreviations mean, so B1 isn't a wall.

**Gap to build:** the A0 lesson content itself (Cyrillic) + the marathon diagnostic flow (already handoff'd, H440).

## A1 — Script

**Outcome.** Read (Aug cohort) and eventually write (Jan cohort) Devanāgarī: akṣara as CV units,
mātrā, conjuncts, the virāma.

**Assets / features.**
- **Akṣara trainer** on SanskritKaraoke (H7) — verse → syllable wave; tap to hear (needs audio).
- **Transliteration playground** flipped: Devanāgarī → IAST self-test.
- **Handwriting UGC** (Jan 2027 only) — upload a written akṣara; deferred per cohort plan.

**Gap:** **audio** (biggest single blocker across A0–A2) and a stroke-order module.

## A2 — Sandhi & first forms

**Outcome.** Undo external sandhi in a short line; recognize a-stem noun cases and the present
indicative; the idea of a verbal root + class.

**Assets / features.**
- **Sandhi splitter** (Heritage M11 / Samsaadhanii M10) — paste 3–4 words → segments.
- **Paradigm browser / flashcards** (VisualDCS I10) — 6 roots × 9 tenses, already built.
- **Auto-declension drills** (Zaliznyak index E31) — 98,639 stem-tagged headwords → targeted noun-ending exercises.
- **Paradigm generator** (vidyut M14) — any stem → full table on demand.

## B1 — First reading

**Outcome.** Read curated easy verses with support; ~500–1,000 word active vocabulary.

**Assets / features.**
- **Subhāṣita units** (Indische Sprüche F33, 7,537 public-domain sayings) with click-gloss.
- **Graded gloss** (3-layer glossary A2, 87% coverage) + **kosha "one clean answer"** (G3).
- **Frequency-ordered SRS** (E26) — learn the top-N lemmas in corpus order, spaced repetition.
- **Difficulty scorer** picks verses at exactly this level.

## B2 — Classical text

**Outcome.** Read a real epic passage with an interlinear safety net; parse compounds.

**Assets / features.**
- **RussianRamayana parallel reader** (H6) — flagship graded text; template for more.
- **Interlinear reader** over `corpus_lexicon` (A1) — hover any word for its in-context Russian.
- **Concordance** (Samudra Manthanam H4) — see a word across the parallel corpus.
- **Attested examples** (`dcs_ppp_verified` B12) — real forms with counts, not stubs.

## C1 — Vedic & commentary

**Outcome.** Handle pitch accent; read a Rigveda hymn; follow a Sanskrit commentary.

**Assets / features.**
- **Vedic accent track** (VedaWeb M13) — the only accented RV source we have.
- **Grassmann RV dictionary** (GRA) for hymn-by-hymn vocabulary.
- **Commentary apparatus** (CommentaryStrategies J14, Sundara apparatus) — text ↔ commentary.
- **Root-class honesty** (Whitney crosswalks B9–B11) — show what accent *can't* disambiguate.

## C2 — Scholar / lexicographer

**Outcome.** Do philology: compare 44 dictionaries, trace etymology, read Skt-only definitions,
produce an evidence-graded gloss.

**Assets / features.**
- **All-dict compare** (CDSL G1) + **alignment confidence** (csl-atlas C17).
- **Etymology & root explorer** (oracle B7, `mw_roots` B5, 10-dict agreement).
- **Pandit mode** (SKD, VCP — Skt→Skt) — definitions without a bridge language.
- **BookIndex sound-law simulator** (I11) for diachronic phonology.

---

## Cross-rung mechanics (the same three, everywhere)

1. **Reader-as-a-service** — the one surface reused at every rung from B1 up; only the source text and gloss depth change.
2. **Frequency-ordered SRS** — the vocabulary spine; the deck is auto-cut from whatever the learner is reading.
3. **Difficulty scorer** — keeps every learner in their i+1 band automatically.

## Open pedagogy decisions (→ GTD @DECIDE)

- **Placement:** does the intent quiz also *place* a returning learner on the ladder, or only route to a course?
- **Bridge language:** Russian-first throughout, or English track in parallel from B1?
- **Certification:** do rung-gates issue certificates (Systema already has `CertificateService`)?
- **Audio source:** TTS vs recorded reciter for the A1/A2 audio gap — cost/authenticity trade-off.

---

_Dr. Mārcis Gasūns_
