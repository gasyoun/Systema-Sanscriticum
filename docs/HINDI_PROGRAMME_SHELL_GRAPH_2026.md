# Hindi programme shell graph (H2442)

_Created: 14-08-2026 · Last updated: 14-08-2026_

## Problem

H2333 is **one hop**: `courses.predecessor_course_id` plus a cabinet banner. VicSable
(user 6494) holds Hindi **гр. 2 / гр. 3 / гр. 5** across years. A playlist
([H2441](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2441-Grok_Systema-Sanscriticum_hindi-programme-playlist-one-list_08.08.26.md))
needs the **full chain**, cycle-safe, without merging course rows or touching money/access.

## Solution

| Piece | Role |
|---|---|
| [`ProgrammeShellGraph`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/ProgrammeShellGraph.php) | Walks `predecessor_course_id`, expands missing ancestors, orders earliest → latest |
| Programme key | `config/programme.{key}` (today: `hindi`) — category slug, course ids, optional title/slug likes |
| Admin | Same Filament field as H2333; helper text documents A→B→C; save rejects a cycle |
| H2441 playlist | `HindiProgrammePlaylist::orderedShells()` delegates here |

No new table. No student UI in this handoff. Flag `features.hindi_programme_playlist` stays **OFF**.

## Prod Hindi shells (samskrte.ru)

| id | Shell | Predecessor | Seeded? |
|---|---|---|---|
| 416 | гр. 2 | — (optional: set 356 → 416 in Filament if pedagogy says гр. 3 continues гр. 2) | listed in `HINDI_PROGRAMME_COURSE_IDS` |
| 356 | гр. 3 | — until an admin links it | listed |
| 366 | гр. 5 (from #13) | **356** | yes — [H2333 migration](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_08_06_120000_add_predecessor_to_courses_table.php) (no-op if ids missing) |

Do **not** auto-write 356 → 416. гр. 2 / гр. 3 / гр. 5 can be parallel year-groups, not a
lesson-number continuation. The playlist still unions all three via
[`config/programme.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/programme.php)
ids + title/slug `хинди` / `hindi` / `xindi`. The predecessor walk is only for true
continuations.

## Admin: link A→B→C

1. Open `/admin/courses` for the **later** shell (e.g. гр. 5).
2. «Начало программы (предшественник)» → earlier shell (гр. 3).
3. «С какого занятия этот поток» → e.g. 13.
4. Repeat on the middle shell if it continues a still earlier one (гр. 3 → гр. 2).
5. A cycle (A→B→A or a course pointing at itself) is rejected on save.

The student banner (H2333) stays 1-hop. The graph is what the playlist consumes.

## API (H2441)

```php
app(ProgrammeShellGraph::class)->orderedShells('hindi'); // all Hindi seeds + ancestors
app(ProgrammeShellGraph::class)->walkFrom($course);      // this shell + ancestors
app(ProgrammeShellGraph::class)->wouldCycle($id, $pred); // admin guard
```

Missing / inactive predecessors are skipped. A cycle terminates (visited set + hop cap 50).

## Non-goals

- Playlist UI (H2441).
- Merging Zoom groups or course rows.
- Payments, grants, tariff keys.
- Auto-seeding гр. 2 as predecessor of гр. 3.

## Related

- [COURSE_PREDECESSOR_CONTINUATION_BANNER_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/COURSE_PREDECESSOR_CONTINUATION_BANNER_2026.md) — 1-hop banner
- Incident: VicSable / user 6494 — paid 416, 356, 366 (pred=356)

_Dr. Mārcis Gasūns_
