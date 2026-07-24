# PLAN — PWG «как Arzamas materials/1100» (Systema-Sanscriticum, 2026)

_Created: 24-07-2026 · Last updated: 24-07-2026_

Cover index for a layered `/ask` plan. Goal: ship one Russian pop-science longread
about the **Petersburger Wörterbuch (PWG, Böhtlingk–Roth)** on **samskrte.ru**,
in the genre of [Arzamas materials/1100 (Даль)](https://arzamas.academy/materials/1100) —
15–20 numbered chapters, rich illustration, soft editorial CTA — not a dictionary
how-to, not an academic paper rewrite.

Provenance: `/ask` interview 24-07-2026 (5 rounds, Grok 4.5 `grok-4.5`) + prior-art
audit of Arzamas 1100, Systema articles/Materials hub (H323/H387), PWG prefaces OCR,
csl-guides `pwg.mdx` / entry-anatomy, A36/A49.

---

## 1. The honest gap (one paragraph)

The org already owns **primary sources** (PWG prefaces DE/EN/RU under
[sanskrit-lexicon/PWG/prefaces](https://github.com/sanskrit-lexicon/PWG/tree/main/prefaces)),
**scholarly frames** (A36 Latin discretion-screen; A49 Petersburg layering scaffold),
**how-to-read surfaces** ([csl-guides entry-anatomy](https://github.com/sanskrit-lexicon/csl-guides/blob/main/docs/dictionaries/entry-anatomy.mdx),
specimen pages), and a **samskrte magazine layer** (`/s/{slug}` articles with auto-TOC
from `h2`, hero, JSON-LD; hub [`/online/materialy`](https://samskrte.ru/online/materialy)
from H387). What does **not** exist is a single Arzamas-grade **cultural essay** that
makes PWG legible to an educated Russian non-specialist — the Dahl-piece analogue.
Wave-1 closes that gap by writing the essay, locking every fact to a source row,
illustrating it under a rights pipeline, running RuWritingStyles councils, and
publishing via the existing Article pipeline (no new CMS).

---

## 2. Goal for this span (wave-1)

**Done** = live public URL `https://samskrte.ru/s/{slug}` with:

- 15–20 `h2` chapters (TOC auto-built by existing `articles/show.blade.php` JS)
- Rich image set (≈ one illustration per chapter) with rights manifest
- Soft end-of-article editorial CTA + card visible on Materials hub
- `FACTS.md` green (every factual sentence sourced)
- RuWritingStyles `sanskrit` **and** `indology` councils run; Majors fixed
- PHPUnit: import idempotent, show 200, ≥15 `h2` in body
- `is_published=true` (ACCEPT bar includes live publish)

**Non-goals (wave-1):** full academic survey; pwg_ru UI/pipeline; EN edition;
new Article model/migration; hard lead-magnet paywall; rebuilding entry-anatomy.

---

## 3. Decisions taken (interview 24-07-2026 — do not re-litigate)

| # | Decision | Ruling |
|---|---|---|
| D1 | Product | **Pop-science essay 15–20 chapters** (Arzamas 1100 genre) |
| D2 | Audience | **RU educated non-specialist** (Arzamas reader; not only Sanskrit students) |
| D3 | Language | **RU now; EN = wave-2** separate handoff |
| D4 | Home / URL | **samskrte.ru / Systema** — Article `/s/{slug}` + Materials hub |
| D5 | Out of scope | **No full academic rewrite; no pwg_ru UI** |
| D6 | Delivery tech | **Existing `Article` model** (no new tables); body HTML with `h2` |
| D7 | Structure | **One URL**, numbered chapters, sticky/sidebar TOC (already in show blade) |
| D8 | Visuals wave-1 | **Rich like Arzamas** (image nearly every chapter) |
| D9 | Fact canon | **Primary + secondary scholarship** (prefaces, Cardona-class refs, committed papers) — not Wikipedia-as-source |
| D10 | CTA | **Soft editorial CTA at end** + Materials hub discovery (H387 pattern) |
| D11 | Text source-of-truth | **`docs/` markdown + import command → Article`**; re-import is allowed; Filament = emergency only |
| D12 | Byline | **Dr. Mārcis Gasūns** (+ school publisher schema already on page) |
| D13 | Outline skeleton | **Arzamas-map adapted to PWG** (see ROADMAP + IMPLEMENTATION §outline) |
| D14 | Image rights | **PD XIX + Cologne scan thumbs + own SVG**; per-file rights row |
| D15 | Editorial pass | **RuWritingStyles `sanskrit` + `indology` councils** before publish |
| D16 | ACCEPT | **Published live** + Materials card + FACTS green + tests green |
| D17 | Fact-check bar | **Every factual sentence → FACTS.md row** |
| D18 | Automated checks | **PHPUnit import + show 200 + h2 count ≥15** |
| D19 | Council Majors | **Fix before publish; Minors → FOLLOWUPS, ship** |
| D20 | Ambiguity | **Pick plan default, log, continue** |
| D21 | Stop conditions | Rights-unclear image → drop image; unsourceable claim → cut/hedge; money/access code; watcher without safe-commit; csl-orig direct edit; pwg_ru paid run |
| D22 | Git / deploy | **Commit → PR → merge** (worktree off origin/main); production import + `is_published=true` when ACCEPT green |
| D23 | Fence | No money/payment/access code; no watcher-unsafe main-tree edits; no csl-orig; no live pwg_ru translation spend; no EN wave in same PR |
| D24 | Slug (default) | `peterburgskiy-slovar-pwg` (override only if collision) |
| D25 | Title (default working) | «Петербургский словарь: 20 фактов о PWG» / subtitle hooks curiosity (finalize in draft) |

---

## 4. Autonomy contract (verbatim — execution agents obey this)

- **On unplanned ambiguity (D20):** choose the option this plan marks as default (or the
  Recommended option from the interview table), write the decision + one-line rationale
  into `docs/materials/pwg-arzamas/DECISIONS_LOG.md`, continue. Do not open a blocking
  `@DECIDE` for wave-1 path items.
- **Stop conditions (D21):** halt the publish path (leave `is_published=false` or do not
  run production import) if (a) a claim cannot be sourced and cannot be honestly hedged,
  (b) an image has unclear rights (drop that image and proceed), (c) work would touch
  money/access/payment code, (d) production deploy credentials are missing and cannot be
  stubbed for tests, (e) RWS council returns an unfixed **Major** on register or fact.
  Log the stop in the handoff body; do not improvise around the fence.
- **Commit authority (D22):** handoff-scoped work may commit → open PR → merge when
  PHPUnit green and ACCEPT criteria for that step are met. Use a **worktree off
  `origin/main`** (Systema is contention-heavy + watcher-afflicted). Pathspec-limited
  commits; `/watcher-safe-commit` discipline for any write in the Systema tree.
- **Fence (D23):** do not edit payment webhooks, RoleGate finance, access grants,
  csl-orig, PWG source text, or start paid PWG→RU windows. Do not force-push. Do not
  publish EN. Do not add new Eloquent models for this feature.
- **Publish authority:** when FACTS green + councils Majors fixed + tests green, set
  `is_published=true` and ensure Materials hub lists the card (H387 aggregator — no
  extra registration if published Article is already included).

---

## 5. Layer docs (read in this order)

| Layer | Doc |
|---|---|
| Roadmap / waves | [ROADMAP_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md) |
| Architecture | [ARCHITECTURE_SYSTEMA_PWG_ARZAMAS_MATERIAL.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_PWG_ARZAMAS_MATERIAL.md) |
| Implementation | [IMPLEMENTATION_SYSTEMA_PWG_ARZAMAS_MATERIAL.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_PWG_ARZAMAS_MATERIAL.md) |
| Verification + risks | [VERIFICATION_SYSTEMA_PWG_ARZAMAS_MATERIAL.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_PWG_ARZAMAS_MATERIAL.md) |
| Metadoc | [PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.meta.md) |

---

## 6. Prior-art verdict (build only the gap)

| Piece | Exists? | Verdict |
|---|---|---|
| Arzamas 1100 genre reference | Yes (external) | **Reuse as UX/genre contract**, not copy text |
| Article + TOC + hero + JSON-LD | Yes, Systema | **Reuse** — no new longread CMS |
| Materials hub H387 | Yes | **Reuse** — published article auto-surfaces as «Текст» |
| PWG prefaces OCR DE/EN/RU | Yes, PWG repo | **Primary source feed** for chapters on program/method |
| entry-anatomy / specimens | Yes, csl-guides + SanskritLexicography | **Link out**, do not re-implement; optional one figure embed as static HTML snippet only if needed |
| A36 *obscaena Latine* | Yes, paper 5/5 | **One chapter, popularized** with numbers re-checked from committed paper |
| A49 Petersburg layering | Scaffold only | **Light family chapter**; do not wait for A49 finish |
| pwg_ru translation product | In progress | **Out of scope** (D5); at most one closing sentence + link if public surface exists |
| csl-guides `pwg.mdx` | Yes | **Link** for specialists; not the lay essay home |

---

## 7. Execution handoff (wave-1)

Starter line for a fresh session:

```
Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\docs\PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md and execute it.
```

Model tier for execution: **Fable 5 (`claude-fable-5`)** for prose + councils +
fact apparatus; **Sonnet 5 (`claude-sonnet-5`)** acceptable for import command +
PHPUnit only if prose already landed. Prefer one Fable session for end-to-end
wave-1 if context allows; else split: (A) research outline+FACTS skeleton,
(B) prose+images+councils, (C) import+tests+publish — all pointed at this PLAN.

---

_Dr. Mārcis Gasūns_
