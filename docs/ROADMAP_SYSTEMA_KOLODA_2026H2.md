# ROADMAP — Systema koloda (2026H2)

_Created: 31-07-2026 · Last updated: 19-08-2026_

> **Truth-pass 19-08-2026 (H3072, Opus 5 `claude-opus-5`):** у программы есть текущий план — [PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md).

Index: [PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md)

---

## What exists (as of 31-07-2026)

| Surface | Status |
|---|---|
| FSRS-6 + ReviewService + Livewire review | ✅ H211 |
| Public `/koloda`, cabinet `/dvaram/koloda`, 301 from `/srs` | ✅ live |
| Guest trial (session-only, soft wall) | ✅ H1981 |
| Per-deck URLs + language-aware tagline | ✅ H1981 |
| Test modes mc / typing / pairs / speed / difficult | ✅ H1988 (auth) |
| Filament + student deck editor | ✅ H1487 |
| Importers: Memrise, Kochergina L1, kosha B1, Anki | ✅ |
| System decks (marketing list): dictionary, cyrillic, roots×570, Kochergina L1, Hindi Core, kosha B1 | ✅ content varies by deploy seed |
| `SRS_ENABLED` default **true** | ✅ product decision 30-07-2026 |

---

## Waves

### W0 — Human archive (blocking only for «Продлёнка» deck)

- Export Memrise **6679375** with `scripts/memrise_export.py` + `MEMRISE_SESSION` (or CourseDump2022).
- Validate with `scripts/memrise_export_validate.py`.
- Commit under `database/seeders/data/memrise_6679375/`.
- **Unblocks:** W1-D4 import. Sibling courses already exported.

### W1 — Content pipeline (this PLAN)

| ID | Deliverable | Unblocked by |
|---|---|---|
| W1-D1 | Bühler grammar tables 6517849 → system deck(s), Option A + metadata | Export already on disk |
| W1-D2 | Migrate `Lesson.flash_cards` → lesson-tied SRS decks; dual-read then cutover | W1 schema map |
| W1-D3 | Catalog language filter (sa / hi / all) on public + cabinet hub | K4 ruling |
| W1-D4 | Import 6679375 via existing `srs:import-memrise` + deploy note | W0 human |

### W2 — Engagement depth (staged, not wave-1 mint priority)

- Daily-goal widget for N reviews/day; difficult/due badges.
- Retention curve per deck from `SrsReviewLog`.
- P2b on-screen Devanagari / live IAST→Devanagari.

### W3 — Mems + UGC (K3 post-moderation)

- `SrsMem` model; show on reveal; Filament report queue.
- Public deck publish with post-moderation (report → hide).

### W4 — Audio + paradigm grid

- Re-host Memrise audio (D4 deferred); self-hosted TTS per SRS_ROADMAP Wave 3.
- Grammar Option B (full paradigm card UI) on top of W1-D1 metadata.

---

## Non-goals

- Rebuild FSRS or SM-2.
- AnkiWeb sync protocol.
- Audio in W1.
- Pre-moderation of UGC (ruled out K3).
- Org-wide ask-batch of unrelated repos (this roadmap is Systema-only).

_Dr. Mārcis Gasūns_
