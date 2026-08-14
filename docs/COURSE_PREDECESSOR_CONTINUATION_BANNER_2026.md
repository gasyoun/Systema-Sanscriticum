# Course predecessor continuation banner (H2333)

_Created: 06-08-2026 · Last updated: 14-08-2026_

## Problem

Some programme streams are split across several `courses` rows (e.g. Hindi gr.5 lists recordings **from lesson 13**, while lessons 1–12 live under gr.3). Students who only open the live group ask curators “where is the beginning?” even when they already have access to the archive course.

This is **not** access/payment recovery (`student.access` / «почему закрыто?»). Access can be fine; navigation across shells is missing.

## Solution

| Piece | Role |
|---|---|
| `courses.predecessor_course_id` | FK → course shell that holds the earlier lessons |
| `courses.continues_from_lesson` | Display number N (“from lesson N”); optional — inferred from first lesson title |
| Admin (`CourseResource`) | Curator sets predecessor once per continuation shell; A→B→C is one hop per shell |
| `CourseContinuationBanner` | Builds student-facing payload (enrolled? → deep link to lesson 1) — **1-hop only** |
| `ProgrammeShellGraph` (H2442) | Multi-hop walk for playlist / future languages; cycle-safe |
| Cabinet UI | Banner on classic + hybrid course pages; short hint on dashboard cards |

## Student copy (register)

- Calm, no emoji, «вы» lowercase.
- State facts: this shell starts at N; beginning is in course X; button to lesson 1 when enrolled.

## Ops

After deploy, run migrations. Hindi prod seed is **in the migration** for ids 366→356 (no-op if missing). Other languages/streams: set predecessor in Filament. Optional гр. 2 (416) is **not** auto-linked — see [HINDI_PROGRAMME_SHELL_GRAPH_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/HINDI_PROGRAMME_SHELL_GRAPH_2026.md).

## Non-goals

- Merging live Zoom schedules into one course.
- Telegram archive discovery.
- Full Phase 2 “Где записи?” help hub (still future; this is the high-ROI slice).
- Multi-hop student banner (playlist consumes the graph instead).

## Related

- [access-self-service-spec.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/access-self-service-spec.md) — Phase 2 mentioned «где записи»; this ships the continuation-shell gap without waiting for that hub.
- [HINDI_PROGRAMME_SHELL_GRAPH_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/HINDI_PROGRAMME_SHELL_GRAPH_2026.md) — H2442 multi-hop walk + Hindi seed/ops.
- Incident shape: VicSable / user 6494 (2026-08-06) — gr.5 from #13, archive on gr.3.

_Dr. Mārcis Gasūns_
