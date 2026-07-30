# Deflection baseline 2026 — support:topic-ranking prod run (H592)

_Created: 30-07-2026 · Last updated: 30-07-2026_

**Command:** `php artisan support:topic-ranking --months=6 --json`, run on prod
(`root@193.232.229.92`, `/var/www/html`) 30-07-2026, via [`SupportTopicRanking`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SupportTopicRanking.php).
Window: `2026-01-30` → `2026-07-30`, channel `all`, mode `rollup-weighted`.

## Real category order (by `deflection`, descending)

| # | Category | chat_days | queries | unanswered | curator_min | web_share | deflection |
|---|---|---|---|---|---|---|---|
| 1 | `schedule` | 617 | 597 | 11 | 7,774 | 0.01 | **617** |
| 2 | `payment` | 538 | 1,157 | 20 | 14,953 | 0.01 | **538** |
| 3 | `materials` | 106 | 268 | 2 | 2,707 | 0.01 | **106** |
| 4 | `technical` | 69 | 111 | 5 | 435 | 0.00 | **69** |
| 5 | `refund` | 30 | 59 | 2 | 905 | 0.00 | **30** |
| 6 | `certificate` | 4 | 12 | 0 | 66 | 0.00 | **4** |
| 6 | `uncategorized` | 1,000 | 878 | 87 | 24,707 | 0.01 | **4** |
| 8 | `access` | 628 | 1,040 | 24 | 8,739 | 0.01 | **1** |

`human_replies` and `ai_sent` are 0 across every real category (auto-reply is not live
yet — expected, `support_unified_reply`/`support_answer_suggester` are still behind
flags per [ROADMAP_TELEGRAM_SCALING_2026_2027.md §3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md)).
`uncategorized` and `access` carry the highest `curator_min`/`chat_days` volume but low
`deflection` scores — the ranking metric is not simply query count, see the command's
own weighting.

## Confirming vs. the A/B/C assumption (roadmap §4.2)

[ROADMAP_TELEGRAM_SCALING_2026_2027.md §4.2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md#42-автономность-бота--ослабить-точечно-меняет-жёсткий-принцип)
names three safe factual auto-answer categories: **A** "Zoom/ссылка", **B** "ссылка на
запись", **C** "расписание" (schedule) — with D1's gate starting from **A (Zoom)**.

**Does not confirm as assumed:**
- The live `support:topic-ranking` taxonomy has **no `zoom` or `recording-link`
  category** — only `schedule` (= C) exists as a directly comparable bucket. A/B appear
  to be sub-cases folded into `schedule`, `materials`, or `uncategorized` rather than
  their own tracked categories, or they are not yet distinguishable in the data.
- **`schedule` (C) is the #1 category by deflection (617)**, not `A` (Zoom) — so if D1's
  "start with A" plan assumed Zoom would be the top/first-measured category, the
  baseline instead puts **C ahead of A** by this metric. `payment` (§4.2 explicitly
  excludes payment from auto-answer — "цена/оплата... остаётся draft-only") is a close
  second at 538, well ahead of everything else.
- Recommend the `/decision-record` (D1) gate reconcile the promotion order against this
  real ranking — either retarget D1 to start with `schedule` (measured #1, and already
  in the safe-category list), or get `SupportTopicRanking`'s taxonomy extended to
  distinguish Zoom-link / recording-link queries from the buckets they currently fall
  into before scoring A/B individually.

## Reproduce

```sh
ssh root@193.232.229.92 "cd /var/www/html && php artisan support:topic-ranking --months=6 --json"
```

_Dr. Mārcis Gasūns_
