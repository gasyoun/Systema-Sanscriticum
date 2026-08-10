# Varnamālā (`/lila/varnamala/`)

_Created: 08-08-2026 · Last updated: 08-08-2026_

Metamorphabet-inspired **interactive Devanāgarī akṣara toy** for Systema Lila.

| Path | Role |
|------|------|
| [docs/VARNAMALA_PILOT_BRIEF_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VARNAMALA_PILOT_BRIEF_2026.md) | product + designer brief |
| `data.js` | 10 akṣara × 3 morphs × word + `rive` config |
| `engine.js` / `engine.css` | CSS morph shell + Rive canvas host |
| `rive-bridge.js` | probe `.riv` + drive SM `Varnamala` / input `stage` |
| `pilot/` | playable pack |
| `rive/` | designer drops `ka.riv`, `ma.riv` (W1); see [rive/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/varnamala/rive/README.md) |

**Gate family:** `varnamala` (first path segment under `/lila/`).  
**Telemetry:** `data-drill="varnamala"` `data-band="pilot"`.

**Not** a drill (sort/match). No scoring. Designer polish path: Figma strokes → **Rive** state machines → drop into `rive/` without changing `data.js` schema.

_Dr. Mārcis Gasūns_
