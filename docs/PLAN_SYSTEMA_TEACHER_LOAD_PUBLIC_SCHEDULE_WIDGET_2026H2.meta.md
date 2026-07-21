# Metadoc — PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md

_Created: 21-07-2026 · Last updated: 21-07-2026_

## Purpose

Execution-ready plan for two linked Systema-Sanscriticum deliverables: an admin teacher-load
analytics page (groups per teacher, by direction) and a reusable public-widget mechanism for
embedding interactive Systema data on WordPress, proven against the live
`samskrtam.ru/raspisanie/` page. Authored via `/ask` (heavyweight interview + layered plan) so a
fresh session/agent can execute it unattended.

## Audience

Whoever picks up the wave-1 execution handoffs (H-A, H-B — see IMPLEMENTATION doc), and MG when
reviewing the seeded `Category` taxonomy or approving the live WordPress embed step.

## Provenance

- Requested in chat 21-07-2026 after a prior-turn research pass established that no
  teacher×group×direction admin report exists today.
- Authored via the `/ask` skill: prior-art audit (Schedule/Category/Course/Teacher models,
  existing IcsFeedBuilder/FeedToken infra, the live Google Calendar embed on samskrtam.ru) → a
  4-round `AskUserQuestion` interview (goal/priorities → architecture forks → implementation/
  tech → verification/autonomy) → this layered doc set.
- Model: Sonnet 5 (`claude-sonnet-5`).
- Built in an isolated worktree (`../Systema-Sanscriticum-teacher-load-plan`, branch
  `docs/teacher-load-public-schedule-plan`) per the repo's guarded-main-tree convention — not
  edited directly in the shared checkout.

## Ranked improvement backlog

1. **Live-embed the widget on `samskrtam.ru/raspisanie/`** — blocked on WP credentials +
   in-session go-ahead (decision 15); the highest-value follow-through once unblocked.
2. **Wire a second WP consumer** (e.g. a samskrte.ru page) once the first embed proves the
   pattern — deliberately deferred, not wave 1.
3. **Denormalize teacher↔group / add a canonical `direction_id` to `Group`** if the on-the-fly
   query (decisions 5–6) turns out too slow at a larger catalogue size — explicitly deferred,
   not premature-optimized in wave 1.
4. **MG's review pass on the seeded `Category` draft** — the seeder is a starting point, not a
   final taxonomy; expect renames via `CategoryResource`.

## Limitations

- No WordPress codebase or credentials were available at authoring time — the WP-side
  integration mechanism (decision 8, custom iframe widget) was chosen partly *because* it needs
  no WP-side code, not purely on design merit; if credentials materialize and a native plugin
  later looks more attractive, that's a legitimate re-open, not a plan defect.
- The `CategorySeeder` draft list (IMPLEMENTATION §Step 1) was mined from a single scrape of
  `samskrtam.ru/raspisanie/` on 21-07-2026 — course names/slugs may have since changed.
- Group→direction "counts once per direction for multi-category groups" (decision 5) is a
  deliberate simplicity tradeoff, not a proven-correct business rule — flagged as a risk in
  VERIFICATION §4, not silently assumed safe.

## Related docs

- [ROADMAP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_2026H2.md), [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE.md), [IMPLEMENTATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE.md), [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE.md) (siblings of this plan).
- [SAMSKRTE_SAMSKRTAM_SYNC_ANALYSIS.md](https://github.com/gasyoun/Uprava/blob/main/site-mockups/SAMSKRTE_SAMSKRTAM_SYNC_ANALYSIS.md) — the separate, out-of-scope brand/design sync initiative this plan's widget-hosting choice incidentally touches (decision 11).

## Revision history

| Date | Change |
|---|---|
| 21-07-2026 | Initial authoring via `/ask` (4-round interview, all decisions ruled, autonomy-readiness gate passed). |

_Dr. Mārcis Gasūns_
