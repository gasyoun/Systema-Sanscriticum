# Roadmap — Sanskrit-HUB & Sanskrit NLP (2026 Q3 → 2028 Q2)

_Created: 10-07-2026 · Last updated: 19-08-2026_

> **Truth-pass 19-08-2026 (H3072, Opus 5 `claude-opus-5`):** заголовок врал о собственном файле — «Last updated: 10-07-2026» стоял на файле, который правился 15-08-2026. Дата исправлена. Из пяти потоков сдвинулся ровно поток A: [/transliterate](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php) с каскадным лемматизатором (H1463, 23-07) и публичная обёртка [/sanskritorium](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php) (H2763, 15-08). Потоки B–E не двигались — документ остаётся планом, не отчётом.

**Goal.** Make [samskrtam.ru/sanskritHUB](https://samskrtam.ru/) **the number-one place for
Sanskrit on the internet** — a unified platform where a **learner-facing pedagogy track (A0→C2)**
sits on top of an **open Sanskrit-NLP data/API layer**, on an **open-core + paid-courses** model.
Free data → authority, SEO, citations; courses → revenue. Built inside
[`Systema-Sanscriticum`](https://github.com/gasyoun/Systema-Sanscriticum) (Laravel LMS, K19),
consuming the ~85-repo asset base ([`FEATURES_INDEX.md`](https://github.com/gasyoun/SanskritLexicography/blob/master/FEATURES_INDEX.md)).

Companion docs:
[asset→pedagogy index](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md) ·
[learner progression](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md) ·
[architecture](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ARCHITECTURE.svg).

---

## Five parallel workstreams

| # | Workstream | Owns | North-star metric |
|---|---|---|---|
| **A** | NLP API & data hub | The `/nlp` endpoints, cascade lemmatizer, benchmarks, model releases | external API calls / month; datasets cited |
| **B** | Learn track & pedagogy | A0→C2 rungs, SRS, reader, quizzes, cohorts | paying students; rung-completion rate |
| **C** | Portal & SEO | `sanskritHUB` surface, search, JSON-LD, page rank | organic sessions; Yandex/Google position |
| **D** | Corpus & content | Graded readers, audio, aligned texts, glossaries | reader texts live; audio coverage |
| **E** | Community & partnerships | vidyut/Heritage/Samsaadhanii/DharmaMitra/Cologne, contributions, citations | integrations; inbound contributors |

Priority order when quarters collide: **B (revenue) → A (moat) → C (reach) → D → E**, per the org
Tier-0 rule. But A is the differentiator that makes C credible — never starve it.

---

## Year 1 — Foundation & flywheel (2026 Q3 → 2027 Q2)

### Q3 2026 (Jul–Sep) — Skeleton + first cohort
*Theme: stand up the surface and ship the pedagogy pilot.*
- **B:** Run the **28 Aug marathon** (A0, Cyrillic-only) — H440 Phase 2. Diagnostic quiz → placement seed. `Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H440-Sonnet_Systema-Sanscriticum_marathon_diagnostic_3day_09.07.26.md and execute it.`
- **A:** **Cascade lemmatizer v0** (DCS E27 → vidyut E29 → Heritage D19) behind one internal function; `/transliterate` live (sanskrit-util L1).
  - ✅ **First slice shipped (H1463, 23-07-2026)** — Grok 4.5 (`grok-4.5`) under user-authorized Opus-lock override: flag `hub_transliterate` (default OFF), `/transliterate` playground + vendored `sanskrit-util`, `App\Services\Nlp\CascadeLemmatizer` (DCS stage on 341-form Nala/DCS-attested slice; vidyut/Heritage interface-stubbed). **Claude/Opus: verify after** (cascade order, key normalizer, slice provenance). PR link in CHANGELOG.
  - ✅ **Public wrapper (H2763, 15-08-2026)** — Grok 4.5 (`grok-4.5`): `/sanskritorium` is the indexable public path over the same view + JS. Flag stays OFF for `/transliterate`.
- **C:** `sanskritHUB` landing page on samskrtam.ru; Dataset JSON-LD from the kosha directory (J21); SEO playbook §9.
- **D:** Wire **Indische Sprüche** (F33) as the first B1 reading unit.
- **KPI gate:** marathon runs; lemmatizer answers a form end-to-end; landing indexed.

### Q4 2026 (Oct–Dec) — Lexical + morphology API GA
*Theme: the open-core hook goes public.*
- **A:** **`/nlp` API public beta** — `/lemmatize` (cascade + confidence), `/lookup` (kosha collapse G3 + C-SALT G2), `/frequency` (E26). Free tier + rate limit.
- **B:** **A0–A1 learn track live**; **frequency-ordered SRS** decks (E26) auto-built; VisualDCS flashcards (I10) folded in.
- **C:** Reader MVP — paste text → segment + click-gloss (CDSL G1). Embeddable widget v0.
- **D:** **Audio spike** — decide TTS vs recorded; ship akṣara sound for A1 (fills the #1 gap).
- **KPI gate:** first external app calls `/lookup`; ≥1 paid course sold off the marathon tail.

### Q1 2027 (Jan–Mar) — Corpus, alignment, graded reading
*Theme: reading becomes the product.*
- **B:** **January cohort** (Devanāgarī writing + recitation UGC); **A2–B1 track**; sandhi splitter (M11/M10) in lessons.
- **A:** **Difficulty scorer** + **graded-reader generator** (frequency bands + rare-form density).
- **D:** **Interlinear reader** over `corpus_lexicon` (A1, 1.09M pairs); RussianRamayana (H6) as the flagship B2 text.
- **C:** Reader-as-a-service GA; per-text SRS.
- **KPI gate:** any pasted text gets a difficulty score + graded deck; 2nd cohort retained.

### Q2 2027 (Apr–Jun) — Benchmarks: the credibility play
*Theme: become citable — "the #1 for NLP" milestone.*
- **A:** **Sanskrit NLP benchmark + public leaderboard** — segmentation, lemmatization, Sa↔Ru/En MT eval sets from `corpus_lexicon` (A1) + DCS gold; paper-grade methodology.
- **A:** **Paradigm generator** (vidyut M14) + **sandhi/segment** endpoints public.
- **B:** **B2 track**; **etymology explorer** (oracle B7) as an advanced module.
- **E:** Announce benchmark to indology + NLP communities (IndologyScholars network I12); invite baselines.
- **KPI gate:** ≥1 external group submits to the leaderboard; benchmark dataset released with DOI.

**End-of-Year-1 state:** public `/nlp` API, reader-as-a-service, A0–B2 learn track, a citable
benchmark, audio for A1. The flywheel (courses → corpus → tools → SEO → learners) is turning.

---

## Year 2 — Depth, scale, dominance (2027 Q3 → 2028 Q2)

### Q3 2027 (Jul–Sep) — Vedic, commentary, data-hub cadence
- **B/D:** **C1 track** — Vedic accent (VedaWeb M13), Grassmann (GRA), commentary apparatus (J14).
- **A:** **Meter/chandas identifier** (vidyut chandas); **NER** over MBh/Purāṇa name indexes (INM/PUI/PE/MCI).
- **A:** **Data-hub release cadence** — every derived dataset via kosha manifest, DOI + Dataset JSON-LD + license tier.
- **KPI gate:** C1 cohort; ≥5 datasets released on a schedule.

### Q4 2027 (Oct–Dec) — Scholar workbench + mobile
- **B:** **C2 track** — multi-dict philology (CDSL G1, csl-atlas), Pandit mode (SKD/VCP), evidence-graded gloss authoring.
- **C:** **CSL App (K18)** integration — offline dicts + SRS sync; hub goes mobile.
- **A:** **Paid API tier + dataset licensing** launches (open core stays free; bulk/commercial paid).
- **KPI gate:** first paid API/licensing revenue; full A0→C2 ladder navigable.

### Q1 2028 (Jan–Mar) — Models: the data→model flywheel
- **A:** **Train + release open-weight models** — lemmatizer/segmenter/MT fine-tuned on our aligned corpus; beat the leaderboard baselines with our own entry.
- **D:** **Audio coverage** to B1 (recited subhāṣitas); TTS for arbitrary text.
- **E:** Formal partnerships — Ambuda/vidyut, Heritage (Huet), Samsaadhanii (Kulkarni), DharmaMitra, Cologne — cross-linking + co-citation.
- **KPI gate:** ≥1 model released with a model card + eval; partner cross-links live.

### Q2 2028 (Apr–Jun) — Consolidation & sustainability
- **C:** **SEO dominance pass** — entity `@id` spine across the whole hub; target #1 for core Sanskrit queries (RU + EN).
- **A/E:** Benchmark v2; citations tracked; the hub is the default backend other Sanskrit apps call.
- **B:** Full-funnel review — A0→C2 conversion; pricing/packaging of the open-core + courses model.
- **KPI gate (year-2 north star):** the hub is cited in ≥3 external papers/tools; open-core revenue covers infra; the ladder A0→C2 is complete and populated.

---

## Dependencies & sequencing (the critical path)

```
sanskrit-util (have) ─► /transliterate ─► Reader-as-a-service ─► every rung B1+
DCS+vidyut+Heritage (have) ─► cascade /lemmatize ─► /segment ─► difficulty scorer ─► graded reader
corpus_lexicon (have) ─► interlinear reader + MT eval set ─► benchmark ─► trained models
frequency (have) ─► SRS ─► vocabulary spine (all rungs)
AUDIO (GAP) ─► A1/A2 fully unblocked          ◄── highest-leverage missing piece
```

**Everything reads-heavy is already built or buildable from existing assets.** The two genuine
*new-capability* investments are **audio** (D) and **trained models** (A, Year 2). Everything else
is integration + pedagogy authoring.

## Risks / honest caveats

- **Audio** is on the critical path for beginners and does not exist — do not let it slip past Q4 2026.
- **Accent/tense gaps** (DCS unaccented; aorist/perfect conflation) — the C1 grammar rung must surface ambiguity, not fake it.
- **Systema is watcher-afflicted** — all hub code lands via `/watcher-safe-commit` or a worktree; never leave edits uncommitted.
- **Don't rebuild** — every capability above maps to an existing asset; check FEATURES_INDEX / SHARED_CODE before writing anything.

## Open @DECIDE (mirror to GTD)

1. **Domain form:** `samskrtam.ru/sanskritHUB` path vs a subdomain vs a dedicated brand later?
2. **Sequencing bias:** API-first (moat) or learn-first (revenue) when Q4 2026 forces a choice?
3. **Audio:** TTS vs recorded reciter (cost vs authenticity)?
4. **Licensing:** which datasets stay fully open vs move behind the paid/commercial tier?
5. **Bridge language:** Russian-only, or an English track in parallel from B1?

---

_Dr. Mārcis Gasūns_
