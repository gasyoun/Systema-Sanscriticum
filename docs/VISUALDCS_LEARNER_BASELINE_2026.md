# VisualDCS native learner — pre-activation baseline

_Created: 13-08-2026 · Last updated: 15-08-2026_

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

## Published pin (H2499 keep #110)

Local consume target is the dual-run-confirmed release
`vdcs-learner-v1-20260809`. Hashes live in
[`tests/fixtures/visualdcs/published-v1-pin.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/fixtures/visualdcs/published-v1-pin.json).
Import (does not flip flags):

```text
php artisan visualdcs:import C:/Users/user/Documents/GitHub/VisualDCS/visual/contracts/v1
```

Catalog pages paginate (50) and do not embed paradigm cells in the index.
Flags stay **OFF** until a human activation decision.

## Activation (human only)

1. Import the H2499 pin (`visualdcs:import` on the sibling `visual/contracts/v1`).
2. Flip `VISUALDCS_VERB`, `VISUALDCS_NOMINAL`, `VISUALDCS_PASSAGE` independently.
3. At day 7 / 14 / 30 fill the same table. Scale / hold / revert from comparable
   cohorts — never from a promised lift.

_Dr. Mārcis Gasūns_
