# Varnamālā · Rive assets (W1+)

_Created: 08-08-2026 · Last updated: 08-08-2026_

Drop exported `.riv` files here. The web shell probes each path; **missing file → CSS morph proxy** (no hard fail).

## W1 files (first designer pass)

| File | Akṣara | Word |
|------|--------|------|
| `ka.riv` | क | कमल |
| `ma.riv` | म | मयूर |

## Contract (must match engine)

| Item | Exact name |
|------|------------|
| State machine | `Varnamala` |
| Number input | `stage` — `0` idle · `1` wake · `2` hint · `3` word |
| Trigger (optional) | `poke` — fire on each tap (juice); stage still drives state |
| Artboard | default artboard of the file (or name = file key `ka` / `ma`) |
| Canvas | square-ish letter area; soft cream/dark that matches Lila palette |
| Hit area | full artboard (shell canvas is the click target) |

### Suggested state machine graph

```
[ idle / stage=0 ]
      │  stage == 1
      ▼
[ wake / stage=1 ]
      │  stage == 2
      ▼
[ hint / stage=2 ]   ← partial object (petal / feather)
      │  stage == 3
      ▼
[ word / stage=3 ]   ← icon + keep glyph small or off
```

Transitions: **number conditions on `stage`**, not only one-shot triggers — so Next / strip navigation can jump to stage 0 without replaying the whole chain.

### Export

1. Rive Editor → Export → **Runtime** `.riv`
2. Place under this folder with the exact filename
3. Hard-refresh `/lila/varnamala/pilot/` — footer line shows **Rive** vs **CSS**

### Palette (match Systema Lila)

- Accent: `#a8451f` (light) / `#e07a4e` (dark)
- Panel: `#faf5e8` / `#26201a`
- Ink: `#2a2118` / `#efe6d4`
- Prefer flat soft 3D (Vectorpark-adjacent), not heavy texture

### Non-goals for W1

- All 10 letters (only `ka` + `ma`)
- Mātrā / conjuncts
- Audio inside Rive (shell uses SpeechSynthesis)

Runtime wiring: [`../rive-bridge.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/varnamala/rive-bridge.js) · product brief: [docs/VARNAMALA_PILOT_BRIEF_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VARNAMALA_PILOT_BRIEF_2026.md)

_Dr. Mārcis Gasūns_
