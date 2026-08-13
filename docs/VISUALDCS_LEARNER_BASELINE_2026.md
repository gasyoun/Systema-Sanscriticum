# VisualDCS native learner — pre-activation baseline

_Created: 13-08-2026 · Last updated: 13-08-2026_

H2482 shipped the importer, three `/dvaram/visualdcs` surfaces and
`external_learning_progress`. All three flags stay **OFF**. This file is the
pre-release baseline; 7 / 14 / 30-day reports compare against it. No lift
target is invented here.

## Snapshot (flags OFF, 13-08-2026)

| Metric | Baseline | How to re-measure |
|---|---|---|
| Eligible learners (paid, non-deposit/trial) | not activated | `Payment::paid()->real()` minus deposit/trial |
| First VisualDCS action ≤24h | 0 (routes 404) | `activity_events` types `visualdcs.progress.*` |
| Learning return (second session / user) | 0 | distinct days on `external_learning_progress.last_seen_at` |
| Support load attributed to VisualDCS | 0 | Helpdesk topic tag once activated |
| Paid conversion of preview visitors | n/a | `/visualdcs/{surface}/preview` + checkout |
| Revenue / active learner | n/a | qualifying Payment denominator (do not fork) |

## Activation (human only)

1. Import a pinned H2481 release (`visualdcs:import`).
2. Flip `VISUALDCS_VERB`, `VISUALDCS_NOMINAL`, `VISUALDCS_PASSAGE` independently.
3. At day 7 / 14 / 30 fill the same table. Scale / hold / revert from comparable
   cohorts — never from a promised lift.

_Dr. Mārcis Gasūns_
