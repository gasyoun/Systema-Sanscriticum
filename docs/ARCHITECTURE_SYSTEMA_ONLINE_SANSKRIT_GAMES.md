# ARCHITECTURE — Online Sanskrit Games

_Created: 26-07-2026 · Last updated: 26-07-2026_

Index: [PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md)

---

## 1. Component boundaries

```
┌─────────────────────────────────────────────────────────────┐
│  Acquisition surfaces                                        │
│  /exercises/* (Systema, canonical)  │  csl-guides LM pages   │
│  static HTML + engine.js + data.js  │  embed/link wrappers   │
└───────────────┬─────────────────────┴───────────┬───────────┘
                │ gate.js (5 plays/family)          │
                │ telemetry.js → POST /api/games/event
                ▼
┌───────────────────────────────────┐
│  game_events (+ guest_id, user_id) │
│  games:funnel · Filament funnel UI │
└───────────────┬───────────────────┘
                │ on first authenticated event after register
                ▼
┌───────────────────────────────────┐     ┌──────────────────────┐
│  SrsOnboardingFromGames (Wave 2)  │────►│  Saraswati FSRS SRS  │
│  system deck, cap 20 “seen” cards │     │  /dvaram/srs         │
└───────────────────────────────────┘     └──────────────────────┘
                │
                ▼ (Wave 2)
┌───────────────────────────────────┐
│  Cabinet skill drills (not FSRS)  │
│  short grammar/script games       │
│  optional lesson_id attachment    │
└───────────────────────────────────┘
```

**Prana** (optional Wave 2): call existing `PranaService` hooks only; no rate rebalance (fence).

---

## 2. Data model

### 2.1 Pack fixture (canonical, Systema)

Per family, prefer the roots pattern:

| Artifact | Role |
|---|---|
| `database/seeders/data/<pack>.tsv` or JSON | Source of truth for generators |
| `scripts/build_<pack>_drill_data.py` | Emits `public/exercises/<family>/data.js`; supports `--check` |
| `public/exercises/<family>/<slug>/index.html` | Mounts engine with config |

**Card face policy (A0):** primary `cyrillic` + `iast`; `devanagari` optional/secondary; `translation_ru` required for gloss games; EN gloss optional behind UI toggle.

### 2.2 Telemetry (extend H1360)

`game_events` columns (conceptual — migration only if missing):

| Field | Purpose |
|---|---|
| `guest_id` | UUID from localStorage (D10) |
| `user_id` | nullable; set when authenticated |
| `drill` / `family` | which game |
| `event` | `started` · `completed` · `gate_hit` · `cta_click` · `item_seen` (for SRS seed) |
| `payload` JSON | item ids/lemmas seen, score, locale |

**Gate state:** localStorage key per family, e.g. `exercises.plays.<family> = N` with N≤5 free (D11). Soft nudge, not DRM.

### 2.3 SRS onboarding (Wave 2)

| Entity | Rule |
|---|---|
| System `SrsDeck` slug | `onboarding-from-games` |
| Card source | Distinct lemmas from `item_seen` / completed payloads for this `guest_id`→`user_id` |
| Cap | 20 cards |
| Idempotency | `firstOrCreate` by deck+lemma; one import pass per user |
| Flag | Behind existing `SRS_ENABLED` — deck invisible until flag on |

---

## 3. Interfaces & contracts

### 3.1 Engine mount (unchanged pattern)

```js
// sort
SortExercise.mount(el, { task, groups, perRound, shuffle, locale? });
// match
MatchExercise.mount(el, { task, pairs, perRound, shuffle, locale? });
// cloze
ClozeExercise.mount(el, { task, blanks, choices, locale? });
```

**Locale (D13):** `locale: 'ru' | 'en'` from a tiny toggle writing `localStorage.exercises.locale`; copy tables in pack config for task/feedback strings.

### 3.2 Telemetry contract

```http
POST /api/games/event
{ "guest_id": "uuid", "drill": "sort/genders", "family": "sort",
  "event": "completed", "payload": { "items": ["deva", ...], "score": 1 } }
```

Throttled, CSRF-exempt as today; web guard. Authenticated requests also set `user_id`.

### 3.3 Auth probe

`GET /api/games/auth` → `{ authenticated: bool }` (existing).

### 3.4 csl-guides wrapper contract (D17)

- Link or iframe to Systema absolute URL of the drill
- Optional frozen `data` hash in page meta: `data-pack-version="<sha256 of data.js>"`
- Drift script: fetch Systema `data.js` hash vs meta; fail CI if mismatch when mirror mode

---

## 4. Build vs reuse (per major piece)

| Piece | Decision |
|---|---|
| Engines | **Reuse** sort/match/cloze/ligatures/roots |
| Gate | **Extend** play counter 1→5 per family |
| Telemetry | **Extend** guest_id + item_seen + cta metrics |
| Pack content | **New fixtures** generated from existing org data |
| SRS | **Reuse** stack; **new** import command + deck |
| Dual surface | **Wrappers**, not forked engines |
| Live NLP games | **Out of Wave 1** — Wave 3 / needs-engine |

---

## 5. Security & product safety

- Games are static + public APIs already scoped; no new auth surface beyond guest UUID
- Never send PII in payloads (no names/emails in `item_seen`)
- Share cards (Wave 2) generate client-side images only; no user photo upload
- Money fence: zero interaction with payments/tariffs
- Feature flags: any cabinet/SRS surfacing remains OFF by default

---

## 6. Failure modes

| Mode | Mitigation |
|---|---|
| Guest clears localStorage | Server events still hold history for UUID until cleared; new UUID = new guest |
| Register without prior play | Onboarding deck empty — no-op import |
| Dual-surface drift | Hash check script (D17) |
| Bad RU gloss for beginners | ≥20-row spot-check before ship (D15) |
| Engine temptation | Catalogue tag `needs-engine` → Wave 3+ only (D16) |

---

_Dr. Mārcis Gasūns_
