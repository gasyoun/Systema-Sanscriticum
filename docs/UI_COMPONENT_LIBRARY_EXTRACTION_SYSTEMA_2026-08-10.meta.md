# Metadoc — UI_COMPONENT_LIBRARY_EXTRACTION_SYSTEMA_2026-08-10.md

_Created: 10-08-2026 · Last updated: 10-08-2026_

Companion record for
[UI_COMPONENT_LIBRARY_EXTRACTION_SYSTEMA_2026-08-10.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UI_COMPONENT_LIBRARY_EXTRACTION_SYSTEMA_2026-08-10.md).

## Purpose

Answers one question with measurement instead of intuition: which high-level UI components are worth
pulling into a Systema component library, and in what order. Exists so the next session does not
re-count 330 Blade templates to rediscover that the repo's problem is colour drift rather than
copy-paste duplication.

## Audience

Whoever executes H2541 Phase 1 (tokens) — and any session tempted to start by extracting a Button.
The subject doc exists largely to redirect that instinct.

## Provenance

| Field | Value |
|---|---|
| Handoff | H2541 (Opus 5) — Systema UI component library: token collapse + piecemeal `x-ui` primitive extraction, with a parallel React mirror for Claude Design |
| Model | Opus 5 (`claude-opus-5`) |
| Date | 10-08-2026 |
| Tree measured | `2eeb144b` on `origin/main` |
| Trigger | A `/design-sync` invocation that could not run — the skill found no Storybook, no `*.stories.*`, and no `.tsx`/`.jsx`/`.vue` in the repo, so shape detection failed and the sync was refused rather than faked. |

## Limitations — read before trusting the numbers

- **Counts are `grep`-level, not AST-level.** A `rounded-xl` inside a comment or a Blade `@php` block
  counts the same as one on a live element. Magnitudes are sound; exact figures are not.
- **"Near-duplicate" clustering was done by eye** over `uniq -c` output, not by an edit-distance
  metric. The 33-in-5-clusters figure for inputs is a judgement call.
- **Only the student/public surface was swept** for utility spread. Colour counts are repo-wide, so the
  two sets are not directly comparable.
- **No visual verification.** Nothing was rendered. The claim that four modals lack focus traps is
  inferred from 5 `sr-only` / 30 `role=` across ~150 templates, not from testing them.
- **Phase 3 is unvalidated.** The shared-`tokens.json` scheme is a design, not something built here.

## Ranked improvement backlog

1. Replace grep counts with an AST/Blade-parser pass before Phase 2 sizing is trusted for estimates.
2. Actually test the four modals for focus-trap and escape behaviour — upgrade the accessibility claim
   from inference to evidence, or retract it.
3. Resolve the two open colour questions (`#E3122C`; the dark-skin palettes) — they block a clean
   Phase 1 close.
4. Decide Phase 3 on evidence: is Claude Design generation wanted? If not, cut it from the plan.
5. Add a per-primitive call-site inventory (exact file:line lists) so each Phase 2 PR is pre-scoped.

## Revision history

| Date | Model | Change |
|---|---|---|
| 10-08-2026 | Opus 5 (`claude-opus-5`) | Created. Measurement sweep + ranked 7-primitive plan + Phase 3 architectural ruling (React cannot be the runtime under Livewire). |

_Dr. Mārcis Gasūns_
