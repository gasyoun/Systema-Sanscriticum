# VisualDCS native learner — pre-activation baseline

_Created: 13-08-2026 · Last updated: 19-08-2026_

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

## Activation (human authorizes; agent runs the recipe)

**ACTIVATED 19-08-2026, ~02:17 MSK (H3116, Fable 5 `claude-fable-5`, MG
«import, flip»).** `visualdcs:import` reported `units=39482`; all three flags
ON; smoke: `/visualdcs/{verb,nominal,passage}/preview` → 200 at 130–165 ms;
no new memory errors in nginx log. Day-7 report due ~26-08-2026 (fill the
Snapshot table above).

First attempt 18-08-2026 (H3116): flags flipped before catalog units existed →
request-path json_decode of the 26-МБ payload OOM-killed php-fpm
(`memory_limit=128M`), all surfaces 500, flags rolled back within minutes.
Since H3116 the catalog serves from `visualdcs_units` (materialized at
import); the import step below is therefore **mandatory before any flip**.

1. Import the H2499 pin (`php artisan visualdcs:import <dir with manifest>`).
   The command prints `units=…` — for v1 expect **39 482** (7 689 verb +
   31 753 nominal + 40 passages). `units=0` → do NOT flip; re-run import.
2. Flip `VISUALDCS_VERB`, `VISUALDCS_NOMINAL`, `VISUALDCS_PASSAGE`
   independently in `.env`, then `php artisan config:cache` **and**
   `systemctl reload php8.3-fpm` (prod OPcache runs
   `validate_timestamps=0` — without the reload the web SAPI keeps the old
   config; deploy.sh step 5).
3. Smoke: `/visualdcs/{verb,nominal,passage}/preview` → 200; no
   `memory size exhausted` lines in `/var/log/nginx/error.log`.
4. At day 7 / 14 / 30 fill the same table. Scale / hold / revert from
   comparable cohorts — never from a promised lift.

_Dr. Mārcis Gasūns_
