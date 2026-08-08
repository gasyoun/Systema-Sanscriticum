# Varnamālā pilot — interactive Devanāgarī akṣara toy

_Created: 08-08-2026 · Last updated: 08-08-2026_

**Handoff:** [H2436 (Grok 4.5) — Varnamala pilot: interactive Devanagari akshara toy (Metamorphabet-inspired)](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2436-Grok_Systema-Sanscriticum_varnamala-pilot-metamorphabet_08.08.26.md)  
**Surface:** [`/lila/varnamala/`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/varnamala/)  
**Inspiration:** [Metamorphabet](https://metamorphabet.com) (Vectorpark) — touch → morph → word + voice. Not a fork; no shared engine.

---

## 1. Product one-liner

Трогаешь **акшару** — она «оживает» и через 2–3 превращения становится **простым санскритским словом** с этим звуком: деванагари, IAST, кириллическое чтение, RU-глосса.

Это **игрушка-введение** (toy), не drill-сортировка. Drill-семейства (`sort` / `match` / ligatures) остаются рядом; Varnamālā — вход в **узнавание формы** до «разложи по группам».

---

## 2. Why not 1:1 Metamorphabet

| English ABC | Devanāgarī |
|-------------|------------|
| 26 letters | ~47+ base + mātrā + conjuncts |
| Letter ≈ onset phoneme | Abugida: क already *ka* |
| A → apple | क → कमल (not English “k-word”) |

**Scope fence for this pilot:** 10 akṣara × 3 morph stages. No mātrā, no conjuncts, no full varnamālā.

---

## 3. Pilot akṣara set (10)

Chosen for: early pedagogy (vowels first), high pictureability, distinct shapes, no rare lexemes.

| # | Deva | IAST | Sound (RU) | Morph 1 (wake) | Morph 2 (hint) | Morph 3 (word) | Word | RU |
|---|------|------|------------|----------------|----------------|----------------|------|-----|
| 1 | अ | a | а | soft bounce | grows ears / mane | horse | अश्व *aśva* | лошадь |
| 2 | आ | ā | а̄ | stretch wide | sun disk | sky | आकाश *ākāśa* | небо |
| 3 | इ | i | и | lean left | crescent | moon | इन्दु *indu* | луна |
| 4 | उ | u | у | drop down | hump | camel | उष्ट्र *uṣṭra* | верблюд |
| 5 | क | ka | ка | vertical stem | petal opens | lotus | कमल *kamala* | лотос |
| 6 | ग | ga | га | loop fills | trunk curls | elephant | गज *gaja* | слон |
| 7 | च | ca | ча | curve snaps | rim spins | wheel | चक्र *cakra* | колесо |
| 8 | त | ta | та | crossbar | branch | tree | तरु *taru* | дерево |
| 9 | न | na | на | two pillars | wave | river | नदी *nadī* | река |
| 10 | म | ma | ма | bowl closes | fan of feathers | peacock | मयूर *mayūra* | павлин |

**Word criteria:** concrete noun, short (≤3 syllables preferred), beginner-safe, imageable. Source of truth for runtime: [`public/lila/varnamala/data.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/varnamala/data.js).

---

## 4. Interaction loop (Metamorphabet-shaped)

```
[ strip of 10 akṣara ] → pick one
        │
        ▼
   stage 0: pure glyph (idle, soft float)
        │  tap / poke
        ▼
   stage 1: “wake” (scale, color, particle)
        │  tap
        ▼
   stage 2: hint shape (CSS/SVG proxy → later Rive art)
        │  tap
        ▼
   stage 3: word card (deva + IAST + cyrillic + RU) + optional TTS
        │
        ▼
   mark akṣara “seen” → next unread or free explore
```

**Complete one “play” (gate/telemetry):** visitor has finished stage 3 on **all 10** once in the session, *or* advances through ≥5 distinct akṣara to stage 3 (pilot policy: **≥5 distinct completes** = one play for gate counting — generous toy, not exam).

---

## 5. Toolchain (designer → ship)

| Layer | Tool | Owner |
|-------|------|--------|
| Content table | `data.js` (+ this brief) | pedagogy |
| Letter forms (modular strokes) | Figma / Illustrator | designer |
| Interactive motion | **Rive** (state machine: idle→poke1→poke2→word) | designer + eng |
| Runtime shell | static `/lila/varnamala/` + gate/telemetry | eng (this pilot) |
| Audio | recorded voice later; Web Speech / self-hosted TTS stub now | eng |

**This PR:** CSS/SVG **proxy** morphs so the loop is playable **before** Rive art. Rive assets drop into `public/lila/varnamala/rive/` later without changing the content schema.

### Designer handoff (Rive)

Per akṣara file `rive/{iast}.riv` (or one multi-artboard file):

- Inputs: `poke` (trigger), `stage` (number 0–3)
- Artboards: glyph, morph1, morph2, word-icon
- Hit box: full letter bounding box
- Export: Rive runtime for web; shell loads when `USE_RIVE=true` and file present

---

## 6. Data schema (`VARNAMALA_PILOT`)

```js
{
  id: "ka",
  devanagari: "क",
  iast: "ka",
  cyrillic: "ка",
  morphs: [
    { stage: 1, label_ru: "…", label_en: "…", visual: "wake" },
    { stage: 2, label_ru: "…", label_en: "…", visual: "hint-lotus" },
    { stage: 3, label_ru: "…", label_en: "…", visual: "word" }
  ],
  word: {
    devanagari: "कमल",
    iast: "kamala",
    cyrillic: "камала",
    translation_ru: "лотос",
    translation_en: "lotus"
  },
  rive: "ka"   // optional artboard / file key
}
```

---

## 7. Systema integration

| Piece | Rule |
|-------|------|
| Family path | `/lila/varnamala/` → gate family = `varnamala` |
| Free plays | 5 per family (existing `gate.js`) |
| Telemetry | `data-drill="varnamala"` `data-band="pilot"` |
| Catalogue | card on [`public/lila/index.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/index.html) |
| Card faces | Devanāgarī **primary** (exception to A0 “cyrillic primary” — script toy) |
| Money | none |

---

## 8. Non-goals (pilot)

- Full 47-akṣara mālā  
- Mātrā / sandhi / conjunct morphs  
- Rive production art in this PR  
- App Store binary  
- Scoring / leaderboard  
- Auto-generate morphs from fonts  

---

## 9. Success criteria (go / no-go after pilot)

| Signal | Go |
|--------|-----|
| Session | Child/adult taps ≥3 akṣara without instruction |
| Pedagogy | Can name sound + one word for ≥5 of 10 after play |
| Product | ≥1 designer iteration path on Rive without rewiring shell |
| Funnel | gate + telemetry fire like other lila families |

**No-go:** if people only “read the word list” and never want a second poke → drop toy form, keep as static flash.

---

## 10. Next build waves

| Wave | Deliverable |
|------|-------------|
| **W0 (this)** | brief + `data.js` + CSS shell + catalogue + H2436 |
| **W1** | designer Rive for 2 letters (क, म) end-to-end |
| **W2** | Rive for remaining 8; TTS voice pass |
| **W3** | expand to ~33 vyanjana **or** mātrā layer (pick one) |
| **W4** | optional ORS-FAQ / samskrtam landing embed |

---

## 11. Provenance

- Model: Grok 4.5 (`grok-4.5`) session 08-08-2026  
- Games architecture: [ARCHITECTURE_SYSTEMA_ONLINE_SANSKRIT_GAMES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_ONLINE_SANSKRIT_GAMES.md)  
- Metamorphabet process notes: Motionographer interview (Patrick Smith) — hand-crafted per letter, not data-driven morph library  

_Dr. Mārcis Gasūns_
