# ROADMAP — Online Sanskrit Games · 2026H2

_Created: 26-07-2026 · Last updated: 26-07-2026_

Index: [PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md)

**North star (D1):** tiered portfolio — free funnel games → cabinet skill drills → hub pedagogy.  
**Wave-1 player (D2):** A0 beginners; cards Cyrillic/IAST-first; UI RU+EN toggles (D13).  
**Wave-1 fence (D5):** no new engines, no audio, no multiplayer.  
**Metric (D6):** ≥15% of CTA clickers complete registration (after 5-play gate, D11).

---

## Non-goals (all waves unless a later plan re-opens)

- PvP, public competitive leaderboards, chat-in-game
- Audio/TTS-dependent games before the hub audio wave
- Paid freemium game tiers (courses monetise; games free with soft gate)
- Live sandhi/lemmatizer engines inside static drills (API-backed widgets are Wave 3+)
- Rebuilding Anki/Memrise as a desktop product
- Touching money contour / csl-orig

---

## Waves

### Wave 0 — Plan & invent (this pass) · **done when docs merge**

_Unblocked by: nothing._

- Layered PLAN / ROADMAP / ARCHITECTURE / IMPLEMENTATION / VERIFICATION
- Invent catalogue ≥15 games in three sections (below)
- Deferred handoffs minted; registry QUEUED behind Tier-0 money/GC (D22)

### Wave 1 — Free A0 funnel platform + engine-fill packs · **first executable wave**

_Unblocked by: Wave 0 merged; human `/go` when money/GC capacity free._

| Deliverable | Notes |
|---|---|
| Guest UUID + events | `telemetry.js` sends stable `guest_id`; `game_events` stores it; merge on auth |
| Gate = 5 plays / family | Extend `gate.js`; per-family counters in localStorage |
| ≥3 new A0 packs on existing engines | From § C engine-fill list (priority rows C1–C5) |
| Funnel report understands new events | `games:funnel` + Filament page still work |
| CTA→register instrumentation | Explicit `cta_click` → registration join for ≥15% metric |
| Smoke paths | Manual/Playwright path per new drill |

**Does not ship in Wave 1:** SRS onboarding import (Wave 2), csl-guides wrappers can start as link-outs if thin (prefer Wave 1b), any `needs-engine` game.

### Wave 1b — Dual surface (csl-guides) · optional parallel after 1

_Unblocked by: at least one Wave-1 pack live 7+ days on Systema (soft staging)._

- Thin LM pages that embed/link Systema drills
- Pack version hash / drift check script
- Align with H312–H315 fleet narrative without rewriting strategy

### Wave 2 — Continuity + cabinet skill drills

_Unblocked by: Wave 1 guest_id + events stable; SRS stack still flag-gated._

| Deliverable | Notes |
|---|---|
| Register → Onboarding SRS deck | Import “seen” lemmas (cap 20) into system deck; opt-out not required (D8) unless safety review demands modal — default silent import with one toast |
| Cabinet Practice strip | Short skill drills (not FSRS) under `/dvaram` or lesson-inline |
| Prana hooks | Use existing caps only — no economy rebalance |
| Lesson attachment | Optional `lesson_id` on drill packs for course authors |

### Wave 3 — Hub pedagogy + parked engines

_Unblocked by: Wave 2; audio wave if sound games; NLP playground maturity._

- Asset-pedagogy games from § A that need live lemmatizer / sandhi / reader
- Viral LM formats from § B that need image export / new interaction
- One new engine budget only when tagged `needs-engine` and prioritised
- A2–B1 packs (sandhi tables, subhāṣita cloze, paradigm match)

---

## Invent catalogue (three sections · ≥15)

IDs are stable for handoffs: `G-A##` asset-pedagogy · `G-B##` viral · `G-C##` engine-fill.  
**Wave** = earliest shippable wave under D5 fence. **Engine** = which Systema engine or `needs-engine`.

### A — Asset-mapped pedagogy (asset → rung → game)

| ID | Game | Rung | Asset fuel | Engine | Wave |
|---|---|---|---|---|---|
| G-A01 | **Звуки санскрита** — sort long vs short vowels (Cyrillic+IAST) | A0 | Phonology primer / sanskrit-util orthography | sort | 1 |
| G-A02 | **Корень или окончание?** — match morpheme labels on Cyrillic lemmas | A0 | Word-anatomy explainer (HUB A0) | match | 1 |
| G-A03 | **IAST ↔ кириллица** — pairs for marathon cohort | A0 | `sanskrit-util` + curated 40-word list | match | 1 |
| G-A04 | **Род существительных+** — expand genders pack | A1–A2 | Existing genders + DictionaryWord sample | sort | 1 |
| G-A05 | **Топ-корни RU** — root ↔ Russian gloss (A0 faces, no deva required) | A0–A1 | `roots_frequency_ru.tsv` (H1280/H1356) | match | 1 |
| G-A06 | **Окончания a-stem** — sort Nom/Acc/Gen… cards | A2 | Zaliznyak stem tags (E31) → static table | sort | 2 |
| G-A07 | **Сандхи-выбор** — 4 options, correct sandhied form | A2 | Hand-curated sandhi table (not live Heritage) | cloze | 2 |
| G-A08 | **Словарные сокращения** — m. f. n. √ match | A1 | OCR front-matter (F37) abbreviations | match | 2 |
| G-A09 | **Субхашита с пропуском** — graded saying cloze | B1 | Indische Sprüche (F33) + graded gloss | cloze | 3 |
| G-A10 | **Парадигма: найди форму** — person/number match | A2–B1 | VisualDCS / vidyut export slice | match | 3 |
| G-A11 | **Живой sandhi-split** — paste line → segments as game score | A2 | Heritage/Samsaadhanii API | **needs-engine** | 3+ |
| G-A12 | **Akṣara-tap** — tap syllables to hear | A1 | SanskritKaraoke (H7) + **audio gap** | **needs-engine** + audio | 3+ |

### B — Viral / share lead-magnet formats

| ID | Game | Hook | Asset fuel | Engine | Wave |
|---|---|---|---|---|---|
| G-B01 | **Имя в деванагари** | Shareable image / identity | `sanskrit-util` (H312) | thin tool / **needs-engine** if free-type | 1b–3 |
| G-B02 | **Серия вспышек (word streak)** | Retention streak share | Freq headwords + glosses (H315) | timed match or **needs-engine** | 2–3 |
| G-B03 | **Субхашита дня** | Daily card share | Indische Sprüche | static page + share meta | 2 |
| G-B04 | **Угадай ранг корня** | Curiosity / corpus authority | roots frequency ranks | cloze/sort bands | 1 |
| G-B05 | **«Мой первый корень»** finish card | End-of-drill share image | roots top-25 | canvas/share (light) | 2 |
| G-B06 | **Level-quiz as game** | Placement + vanity score | existing marathon level-quiz | already lives; polish only | 1b |

### C — Engine-fill packs (ship on existing engines only)

| ID | Pack | Family | Data source | Wave | Priority |
|---|---|---|---|---|---|
| G-C01 | A0 vowel length sort | sort | curated 24 pairs | 1 | **P0** |
| G-C02 | A0 IAST↔Cyrillic match (40) | match | curated + util-checked | 1 | **P0** |
| G-C03 | Kochergina L1 free match (subset of lesson-1 deck) | match | memrise/Kochergina fixture H1431 | 1 | **P0** |
| G-C04 | Roots top-25 RU-only faces | match | existing roots data.js | 1 | **P1** |
| G-C05 | Ligatures top-10 with Cyrillic hints | ligatures | existing ligatures data | 1 | **P1** |
| G-C06 | Root rank guess (top-25) | cloze | roots ranks | 1 | **P1** |
| G-C07 | Genders pack v2 (+12 nouns) | sort | DictionaryWord sample | 1 | **P2** |
| G-C08 | Noun↔pronoun agreement v2 | sort | existing noun-pronoun | 2 | P2 |
| G-C09 | Verb person sort (present) | sort | static paradigm table | 2 | P2 |
| G-C10 | Dictionary abbrev match | match | F37 abbrev list | 2 | P2 |

**Count:** A12 + B6 + C10 = **28** invent rows (≥15 required). Wave-1 shippable without new engines: **G-A01–A05, G-B04, G-C01–C07** (and polish G-B06).

---

## Priority for first executable handoffs (when Tier-0 frees)

1. **Platform:** guest UUID + 5-play gate + CTA metric (IMPLEMENTATION steps 1–4)
2. **Packs P0:** G-C01, G-C02, G-C03
3. **Packs P1:** G-C04, G-C05, G-C06
4. **Wave 2 handoff (later):** onboarding SRS deck import
5. **Wave 1b:** csl-guides wrappers after live packs

---

## Handoffs (deferred)

Minted 26-07-2026; **QUEUED** — do not auto-execute (D22).

| Scope | Handoff |
|---|---|
| Wave 1 platform + P0 packs | [H1678](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1678-Sonnet_Systema-Sanscriticum_online-sanskrit-games-w1-platform-p0_26.07.26.md) |
| Wave 1 P1 packs | [H1679](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1679-Sonnet_Systema-Sanscriticum_online-sanskrit-games-w1-p1-packs_26.07.26.md) |
| Wave 2 SRS onboarding + cabinet strip | [H1680](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1680-Sonnet_Systema-Sanscriticum_online-sanskrit-games-w2-srs-onboarding_26.07.26.md) |
| Wave 1b csl-guides | not minted — open when packs live 7d |

Starters: see PLAN index § Handoffs.

---

## Dependencies & reuse map

```
kosha roots_frequency / Whitney RU ──► roots data.js ──► G-C04/C06, G-A05
Kochergina / Memrise CSV ────────────► G-C03, SRS decks
DictionaryWord ──────────────────────► G-C07, system SRS
public/lila engines ────────────► all Wave-1 packs
game_events + gate.js ───────────────► funnel KPI (D6)
Srs* models ─────────────────────────► Wave-2 onboarding deck only
csl-guides LM fleet ─────────────────► Wave-1b wrappers (D7/D17)
```

---

_Dr. Mārcis Gasūns_
