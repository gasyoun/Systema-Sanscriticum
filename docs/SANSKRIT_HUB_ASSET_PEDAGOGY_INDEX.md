# Sanskrit-HUB — Asset → Pedagogy → NLP Use-Case Index

_Created: 10-07-2026 · Last updated: 12-08-2026_

**Purpose.** The single map that answers *"we already have asset X — what can a learner or an
NLP developer actually DO with it on [samskrtam.ru/sanskritHUB](https://samskrtam.ru/)?"* Every
row takes a **real, existing asset** (grounded in
[`SanskritLexicography/FEATURES_INDEX.md`](https://github.com/gasyoun/SanskritLexicography/blob/master/FEATURES_INDEX.md):
44 dictionaries · 21 interfaces · 37 data assets · 14 tools · 4 external stacks) and pins it to
(a) the **learner rung** it serves, (b) the **NLP capability** it powers, and (c) one or more
**invented product use-cases** — concrete features to build into the hub.

This is the "what exists → what we build" bridge. The learner ladder itself is in
[`SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md);
the build sequence is in
[`ROADMAP_SANSKRIT_HUB_NLP_2026_2028.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SANSKRIT_HUB_NLP_2026_2028.md);
the layered picture is
[`SANSKRIT_HUB_ARCHITECTURE.svg`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ARCHITECTURE.svg).

> **Positioning (locked 10-07-2026):** unified platform — a **learner-facing pedagogy track on
> top** and an **open NLP data/API layer underneath**; the courses feed the corpus, the corpus
> powers the tools. Home: `samskrtam.ru/sanskritHUB`, integrated in
> [`Systema-Sanscriticum`](https://github.com/gasyoun/Systema-Sanscriticum). Model: **open core
> + paid courses** — data, dictionaries and morphology API free (drives authority, SEO,
> citations); revenue from the courses on top.

---

**Ограничение на делегирование ИИ поверх этих активов.** Где выход можно проверить
детерминированным активом (`sanskrit-util`, словари CDSL, DCS, браузер парадигм), операция
идёт **через актив, а не через языковую модель** — модель формулирует и объясняет, но не
устанавливает факт языка. Правило и перечень режимов отказа (сандхи, морфология,
транслитерация, перевод, этимология, ведийский акцент) — в
[ARCHITECTURE_AI_NATIVE_PEDAGOGICAL_DESIGN_MODEL.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_AI_NATIVE_PEDAGOGICAL_DESIGN_MODEL.md) §5.
Статус: сформулировано, **на санскритских данных не проверено**.

---

## 0. The eight layers (see the diagram)

| Layer | What it is | Anchor assets |
|---|---|---|
| **L0 Script & transliteration** | IAST ⇄ SLP1 ⇄ Devanāgarī, normalization keys | `sanskrit-util` (L1), csl-orig source |
| **L1 Lexical** | 44 dictionaries, collapse, union headwords, lookup API | CDSL (G1), kosha (G3), C-SALT API (G2), `union_headwords` (C13) |
| **L2 Morphology & roots** | lemmatize, segment, generate paradigms, root/class | DCS (E25/E27), vidyut (M14), Heritage (M11), Samsaadhanii (M10), `mw_roots` (B5), Zaliznyak index (E31), Whitney crosswalks (B9–B11) |
| **L3 Corpus & frequency** | attested forms, frequency bands, citations, texts | DCS freq (E26), Indische Sprüche (F33), VedaWeb (M13), `<ls>` citation graph (E38) |
| **L4 Alignment & translation** | word-aligned parallel text, TM, MT | `corpus_lexicon` (A1), 3-layer glossary (A2), `mw_en_tm` (A3), mw_ru/pwg_ru kit (L7), DharmaMitra (M12) |
| **L5 API & data hub** | one endpoint per capability, downloadable datasets | kosha manifest + directory (J21), C-SALT/Kosh API (G2), data releases |
| **L6 Learn track** | courses, SRS, quizzes, reader, karaoke — consumes L0–L5 | Systema LMS (K19), SanskritKaraoke (H7), intent quiz (`ShopController`), **«Старт чтения»** ([PRODUCT_START_CHTENIYA_AKRO_STYLE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PRODUCT_START_CHTENIYA_AKRO_STYLE_2026.md)) |
| **L7 Portal / hub** | search + reader + dashboards + learn, one brand | samskrtam.ru (H4), SanskritRussian glossary (H5), the new `sanskritHUB` surface |

---

## 1. Script & phonology assets → A0–A1 rungs

| Asset (ID) | Learner rung | NLP capability | Invented use-case for the hub |
|---|---|---|---|
| `sanskrit-util` (L1) | A0–A1 | Transliteration L0 | **Live transliteration playground**: type in Cyrillic/IAST/HK → see Devanāgarī + SLP1 side by side; the A0 marathon cohort (истинно нулевая, cyrillic-only) uses it before ever meeting a glyph. Also the input normalizer behind *every* search box. |
| SanskritKaraoke (H7) | A1–A2 | Meter/akṣara segmentation | **Akṣara trainer**: verse → syllable wave-diagram → tap each akṣara to hear it; gamified devanāgarī reading for the January UGC cohort (recitation). |
| OCR'd front-matter (F37) | A1 | — | **"How to read a dictionary" onboarding**: the faithful title-page/abbreviation OCR becomes an interactive tour of what `m.`, `√`, `cf.` mean before a learner opens MW. |
| ortho-drift maps (F34) | A2 | Spelling normalization | **Forgiving search**: 19th-c. → modern spelling maps mean a learner who types an old/variant spelling still lands on the entry. |

## 2. Lexical assets (dictionaries) → A2–C2 rungs

| Asset (ID) | Learner rung | NLP capability | Invented use-case |
|---|---|---|---|
| CDSL 44 dicts (G1) | B1–C2 | Multi-dict lookup L1 | **Click-any-word gloss** in the reader: one tap → collapsed entry across all 44 dicts, scan-anchored. |
| kosha collapse (G3) | B1–B2 | Translator-first merge | **"One clean answer" mode**: MW+PWG+AP90 merged into a single graded gloss for learners who don't want 44 raw entries. |
| C-SALT / Kosh API (G2) | (dev) | Lexical API L5 | **Public `/lookup` endpoint** — the open-core hook that makes us the default backend other Sanskrit apps call. |
| `union_headwords` (C13) | B1 | Headword index | **Autocomplete + "is this a word?"**: 323k union headwords power spell-assist and the reader's known-word highlighting. |
| Eng→Skt dicts (BOR, AE, MWE) | A2–B1 | Reverse lookup | **Composition helper**: learner writes in English → candidate Sanskrit words for early sentence-building drills. |
| Skt→Skt (SKD, VCP) | C1–C2 | Monolingual gloss | **Pandit mode**: Sanskrit-only definitions for advanced learners weaning off translation. |
| Indische Sprüche (F33) | B1 | Graded reading corpus | **Subhāṣita-of-the-day** with click-gloss + audio — a public-domain, self-contained first-reading unit. |

## 3. Morphology & root assets → A2–C1 rungs (the NLP core)

| Asset (ID) | Learner rung | NLP capability | Invented use-case |
|---|---|---|---|
| DCS `form2lemma` (E27) + lemma summary (E25) | A2–B2 | Lemmatization L2 | **Cascade lemmatizer API** stage 1: 408k attested form→lemma. The reader's "what word is this?" for any inflected token. |
| vidyut (M14) + `vidyut_form2lemma` (E29) | A2–C1 | Lemmatize + generate | **Paradigm generator widget**: any noun/verb → full inflection table; cascade lemmatizer stage 2 (forms DCS missed). |
| Sanskrit Heritage (M11) + `heritage_forms_oracle` (D19) | B1–C1 | Segment + morphology | Cascade lemmatizer stage 3 + **sandhi splitter**: paste a saṃhitā line → segmented words. |
| Samsaadhanii / SCL (M10) | B2–C2 | Pāṇinian parse | **Dependency-parse view**: kāraka roles over a sentence — the advanced grammar rung. |
| `mw_roots` (B5) + etymology tables (B6/B7) | B2–C2 | Root inventory L2 | **Root explorer**: 2,113 MW roots → derivations, 10-dict agreement, affix entropy. |
| Whitney crosswalks (B9–B11) | C1 | Root-class verdicts | **Verb-class trainer** — but honestly gated: unaccented DCS can't split class I vs VI, so surface the ambiguity, don't fake certainty. |
| Zaliznyak grammar index (E31) | B1–B2 | Stem-class tagging | **Auto-declension drills**: 98,639 headwords tagged by stem-class → generate targeted noun-ending exercises. |
| VisualDCS paradigm browser (I10) | A2–B1 | Paradigm display | **Flashcard mode** already built — wire it straight into the learn track's verb unit. |

## 4. Corpus & frequency assets → graded learning + difficulty scoring

| Asset (ID) | Learner rung | NLP capability | Invented use-case |
|---|---|---|---|
| kosha frequency layer (E26) / DCS freq (E25) | all | Frequency bands L3 | **Frequency-ordered SRS decks**: "learn the 2,000 most frequent lemmas in corpus order" — the single highest-leverage vocabulary path. Also the input to difficulty scoring. |
| VisualDCS verb-form freq (I9) | B1–C1 | Verb usage stats | **"Which forms actually occur"**: stop drilling forms that never appear; Pareto-prioritized conjugation practice. |
| `<ls>` citation graph (E38) | C1–C2 | Text-citation network | **"Where is this quoted"**: 828k citations → jump from a dictionary sense to the classical text that attests it. |
| `dcs_ppp_verified` (B12) | B2 | Attested participles | **Real-usage examples**: show the corpus-attested past-passive-participle with counts, not a textbook stub. |
| VedaWeb (M13) | C1 | Accented Vedic | **Vedic accent track**: the only accented Rigveda source — unlocks the C1 pitch-accent rung. |

## 5. Alignment & translation assets → reading + self-hosted MT

| Asset (ID) | Learner rung | NLP capability | Invented use-case |
|---|---|---|---|
| `corpus_lexicon` (A1) | B1–C1 | Word-aligned Sa↔Ru L4 | **Interlinear reader**: 1.09M aligned pairs → hover any word in a Sa text for its Russian rendering in context (not just a dictionary gloss). Also the **training/eval set** for our own MT. |
| 3-layer glossary (A2) + site (H5) | A2–B2 | Ranked gloss | **Graded gloss**: surface → lemma → root fallback with 87% coverage; the reader's default gloss when a full entry is overkill. |
| `mw_en_tm` (A3) | (dev) | EN translation memory | Powers EN-language learn track + the EN eval set. |
| mw_ru / pwg_ru kit (L7) | B2–C2 | Dictionary MT | **Russian dictionary layer**: MW/PWG in Russian for the primary (Russian-speaking) audience. |
| RussianRamayana reader (H6) | B2 | Parallel reader | **Flagship graded text**: Book IV Sa–Ru parallel reader → template for every graded reader we add. |
| Samudra Manthanam corpus (H4) | B1–C2 | Parallel corpus search | **Concordance search**: regex/stem search across parallel Sa–Ru texts — advanced reading + research. |
| DharmaMitra (M12) | (dev) | External MT/OCR | Call for OCR + baseline MT; reuse their error taxonomy — don't clone. |

## 6. Interfaces already live → fold into the hub, don't rebuild

| Interface (ID) | Rung / audience | Fold-in use-case |
|---|---|---|
| Systema LMS (K19) | learn track host | The hub's course engine, SRS, access control, payments — **the `sanskritHUB` surface is built here.** |
| «Старт чтения» (Akro-style pilot) | A0–B1 cohort packaging | 5-week paid funnel in Systema (SKU + packs + cohort SRS) — register [PRODUCT_START_CHTENIYA_AKRO_STYLE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PRODUCT_START_CHTENIYA_AKRO_STYLE_2026.md); code H2105–H2111. |
| CSL App Flutter (K18) | all, offline | **Offline companion**: 50+ dicts in the pocket; sync SRS decks. |
| CSL Observatory (I8) | (meta) | **"State of Sanskrit data" dashboard** — a trust/authority signal for the NLP-hub positioning. |
| BookIndex / Zalizniakiada (I11) | C1–C2 | Sound-law simulator + KWIC as an advanced-phonology module. |
| CommentaryStrategies (J14) | C2 | Commentary-comparison essays as the reading-tradition capstone. |
| csl-guides (J13) | dev/scholar | Deep per-dictionary docs — the "how to cite / what is this dict" reference. |
| kosha directory (J21) | dev | The **downloadable-datasets storefront** for the open-core layer + schema.org Dataset JSON-LD (SEO). |

---

## 7. Invented "we-are-#1-for-NLP" capabilities (net-new, built on the above)

These are the features that turn a pile of assets into *the* Sanskrit NLP hub. Each names the
assets it stands on; sequencing is in the roadmap.

1. **Unified `/nlp` API** — one call per capability: `/transliterate`, `/lemmatize` (DCS→vidyut→Heritage cascade with a confidence score), `/segment` (sandhi split), `/parse` (Samsaadhanii kāraka), `/generate` (vidyut paradigm), `/lookup` (44-dict collapse), `/frequency`, `/meter`. Open free tier + paid bulk tier.
2. **Reader-as-a-service** — paste ANY Sanskrit text → segmented, lemmatized, click-gloss, frequency-banded, difficulty-scored, optional audio. The single most reusable surface; embeddable widget.
3. **Difficulty scorer + graded-reader generator** — score any text from frequency bands + rare-form density; auto-assemble reading sets at a target level (feeds the learn track directly).
4. **Frequency-ordered SRS** — auto-built decks in true corpus-frequency order, per band, per text; the vocabulary spine of the whole progression.
5. **Sanskrit NLP benchmark + leaderboard** — public eval sets for segmentation, lemmatization, and Sa↔Ru/En MT built from `corpus_lexicon` + DCS gold; the credibility play that makes researchers cite us.
6. **Trained models** — fine-tuned lemmatizer/segmenter/MT on our aligned data, released open-weight; the data → model flywheel.
7. **Etymology & root explorer** — 10-dict oracle as an interactive graph.
8. **Meter/chandas identifier** + **NER over MBh/Purāṇa indexes** (INM, PUI, PE, MCI) — "who/what is this name."
9. **Audio/TTS layer** — the biggest current gap (SanskritKaraoke has NO audio); recitation + akṣara sound → unblocks A1 and the January recitation cohort.
10. **Data-hub release cadence** — every derived dataset published via the kosha manifest with a DOI, Dataset JSON-LD, and a license tier.

---

## 8. Coverage gaps (honest, so nobody re-derives them)

- **No audio anywhere** — recitation/akṣara sound must be produced (TTS or recorded); blocks A1/A2 fully. (See [`project_sanskrit_karaoke`].)
- **Accent** — outside VedaWeb (M13) our data is unaccented; class I/VI and IV/passive verb splits are *not* recoverable from DCS alone. Surface ambiguity, never fabricate.
- **Tense conflation** — DCS UD `Tense=Past` conflates aorist/perfect (E25 caveat).
- **No Sanskrit-native beginner grammar in the learn track yet** — the A0–A2 rungs are pedagogy to *build*, not assets to *fold in*.

---

_Sources: [`FEATURES_INDEX.md`](https://github.com/gasyoun/SanskritLexicography/blob/master/FEATURES_INDEX.md)
(44 dicts · 21 interfaces · 37 data · 14 tools · 4 external stacks, each verified with a real
sample row). Positioning locked by MG 10-07-2026._

_Dr. Mārcis Gasūns_
