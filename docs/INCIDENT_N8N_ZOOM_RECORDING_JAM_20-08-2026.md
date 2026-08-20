# n8n ZOOM 1.4 — записи не ушли в Telegram-группы (18–20-08-2026)

_Created: 20-08-2026 · Last updated: 20-08-2026_

**Audience:** ops / agents. Diagnosis from the 20-08-2026 SOS (Grok 4.6 `grok-4.6`).  
**Hosts:** n8n `root@193.232.229.91`; Laravel `root@193.232.229.92`.  
**Watcher residual:** [H3209](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3209-Grok_Systema-Sanscriticum_recording-gap-watcher-n8n_20.08.26.md).  
**Playbook row:** [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) incident log 2026-08-18 08:37 – 2026-08-20 13:21.

## What staff saw

Telegram groups had a lesson, then no recording by the next morning. Filament `/admin` returned HTTP 500 on login while the student cabinet stayed up. Those are **two classes**.

## Admin 500 (fixed before this note)

`touch(): Utime failed: Operation not permitted` at Laravel `BladeCompiler.php:215`. A handful of compiled views under `storage/framework/views` were `root:root` after the 19-08 21:01Z deploy died on host guards (`tmpfs-cap` / `backup-fresh`) and skipped `chown_compiled_views`. Same class as 17-08.

Re-checked 20-08 ~19:10Z: `GET /admin` = 302, `/admin/login` = 200. Fuse `storage/auto_deploy.disabled` was already gone. Backlog can be pasted in Filament.

## Recording jam (n8n, still a backlog)

Live workflow: **`ZOOM 1.4 (Final) + АДМИНКА ТЕСТ`**, id `1EIqqNzMl5NNIxST` (active).  
Inactive twin `ZOOM 1.4 (Final) + АДМИНКА` (`MtN1h7FdF3JTmrse`) still has `anthropic/claude-sonnet-4.5`.

Last **full** success: exec **1258** 17-08 08:06–10:29Z → lesson 1875 Кочергина гр.61 (`course_id` 434), `recording_attached_at` 17-08 13:29.

From 18-08 08:37Z every **long** run (~1.5 h) died on node **`AI Agent1`** (OpenRouter). Short ~40 ms `success` rows on the same workflow are webhook ACK/skip, not a posted lesson.

| exec | startedAt UTC | OpenRouter class | model in that execution |
|---|---|---|---|
| 1281, 1298, 1325, 1329, 1376, 1392 | 18-08 08:37 → 19-08 19:37 | **402** credits: requested up to 64000 tokens, account could afford 60971 | `anthropic/claude-sonnet-4.5` |
| 1423 | 20-08 11:42–13:21 | **403** `The request is prohibited due to a violation of provider Terms Of Service` | still `anthropic/claude-sonnet-4.5` |

Standalone **`таймкоды`** (`mkct0W3oFHftaBah`) switched to `deepseek/deepseek-v4-pro` at 20-08 06:42Z; manual execs 1407–1412 succeeded. Live ZOOM 1.4 TEST switched to the **same** model at **20-08 19:28Z** — after 1423. The next automatic Zoom webhook should use DeepSeek. 18–20 Aug does **not** auto-retry.

**Do not** Execute ZOOM 1.4 from the Zoom webhook / first node (duplicate YouTube/Rutube). Resume from **`AI Agent1`**, or POST `storeFromZoom` if the Laravel lesson row is missing.

Join key: `schedules.start` + `schedules.course_id` = `lessons.lesson_date` + `lessons.course_id`. `lessons.group_id` is NULL on these rows. `schedules` has no `lesson_id`.

### Gap at 20-08 19:11 UTC (no lesson+recording that course+date)

Groups with `telegram_chat_id`:

| schedule start | course_id | group_id | course |
|---|---|---|---|
| 20-08 12:00 | 334 | 72 | Йога-васиштха |
| 19-08 20:00 | 435 | 132 | Кочергина гр.62 |
| 19-08 08:00 | 401 | 126 | хинди ср 8:00 |
| 18-08 15:00 | 381 | 119 | Патанджали |
| 18-08 13:00 | 366 | 104 | хинди гр.5 |
| 18-08 12:00 | 369 | 107 | Кочергина гр.60 |
| 18-08 11:30 | 399 | 125 | Гита |
| 18-08 10:00 | 436 | 133 | продленка |

n8n container was Up 6 days; swap 2/2 GiB on `.91` is **not** the fail class. Anthropic on this OpenRouter account is not something an agent can unban; DeepSeek is the live workaround.

## Watcher (does not exist yet)

No `recordings:gap*` command. Spec and acceptance: [H3209](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3209-Grok_Systema-Sanscriticum_recording-gap-watcher-n8n_20.08.26.md) — daily 08:00 Europe/Moscow, alert if yesterday had a schedule and no matching recording, attach last `1EIqqNzMl5NNIxST` exec id + error class.

_Dr. Mārcis Gasūns_
