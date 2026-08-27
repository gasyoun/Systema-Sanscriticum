# n8n ZOOM 1.4 — записи не ушли в Telegram-группы (18–20-08-2026)

_Created: 20-08-2026 · Last updated: 26-08-2026_

**Audience:** ops / agents. Diagnosis from the 20-08-2026 SOS (Grok 4.6 `grok-4.6`).  
**Runbook (что запускать при падении/задержке):** [RUNBOOK_N8N_RECORDING_STALL.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_N8N_RECORDING_STALL.md).  
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

## Watcher (H3209)

`php artisan recordings:gap-watch` — Kernel `dailyAt('08:00')` Europe/Moscow.

- Join: `schedules.start` date + `course_id` → published `lessons` with `video_url` / `rutube_url` / `youtube_url` / `recording_attached_at`.
- Default skips groups without `telegram_chat_id` (`--all` includes them).
- Staff meetings: `config/recording_gap.php` `skip_title_substrings` (env `RECORDING_GAP_SKIP_TITLE_SUBSTRINGS`), not a hardcoded SQL title.
- TG: `RECORDING_GAP_TELEGRAM_CHAT_ID` → same ids as `cabinet:probe`. Dedupe key `recording_gap:YYYY-MM-DD`.
- n8n: read-only `GET /api/v1/executions?workflowId=1EIqqNzMl5NNIxST&limit=3` with `N8N_API_KEY`. Empty key or timeout = skip-soft. The scheduled run never retries the workflow; the opt-in `--retry-failed` lane is separate (see Resolution below).

Reproduce the 18–20-08 gap on prod:

```
cd /var/www/html && php artisan recordings:gap-watch --dry --from=2026-08-18 --until=2026-08-20
```

If Laravel cannot reach n8n, SSH `.91` (not a cron):

```
sqlite3 /opt/n8n/storage/database.sqlite "SELECT id,status,startedAt FROM execution_entity WHERE workflowId='1EIqqNzMl5NNIxST' ORDER BY startedAt DESC LIMIT 1;"
```

Inactive twin `MtN1h7FdF3JTmrse` is not live.

## Resolution & follow-ups (24-08-2026)

The DeepSeek switch held; this 18–20 jam class (OpenRouter credits/TOS on `AI Agent1`) has not recurred.

**New class found on 23-08** — executions dying in ~20 s at the FIRST external step (`Get row(s) in sheet`, Google Sheets: 2×ECONNRESET + one proxy-CONNECT death via privoxy→socks-nl), leaving a lesson video unpublished until manual UI retries the next morning. Full forensics + fixes: [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) incident-log row 2026-08-23. Shipped:

1. **Workflow hardened on `.91`**: both Sheets nodes backoff 5×5s → 5×60s (workflow version `aef47bcb`, connections/nodes diff-clean, pre-patch backup `/root/wf_backup_pre_patch_1EIqqNzMl5NNIxST.json`).
2. **Opt-in retry lane** ([PR #2050](https://github.com/gasyoun/Systema-Sanscriticum/pull/2050)): `php artisan recordings:gap-watch --retry-failed` POSTs `executions/{id}/retry` only for error-executions whose runData stayed inside the pre-upload allow-list (no YouTube/Rutube duplication possible), with no-successful-retryOf and cache guards. Late failures stay human-only: resume from `AI Agent1`, never re-run from the webhook.
3. **Care department duplicate recipient** ([PR #2054](https://github.com/gasyoun/Systema-Sanscriticum/pull/2054)): same morning alert also goes to `RECORDING_GAP_CARE_TELEGRAM_CHAT_ID` («Отдел заботы | Рабочая группа» `-1002079934542`, MG 24-08); bot must be a chat member.
4. **Activated on prod per MG ruling 24-08**: `RECORDING_GAP_RETRY_FAILED_ENABLED=true`, `N8N_API_KEY` set in `.env` (REST leg no longer skip-soft), care chat wired and test-delivered. Backups: `.env.bak.n8n-api-key-20260824`.

## Alert doubling resolved (26-08-2026, H3557)

MG: «снова задвоилось» — the 25-08 gap alert arrived repeatedly. Prod forensics (schedule.log, laravel.log, Redis, deploys.log):

1. **Dedupe died with every deploy.** The day-key `recording_gap:YYYY-MM-DD` lived in the Redis app cache; ~20 auto-deploys on 25-08 flushed it between hourly `--stale` ticks, so each tick re-sent the same payload (14 exit-code-1 ERROR rows that day — every successful send returned FAILURE by design).
2. **Recipient fallback multiplied copies.** `RECORDING_GAP_TELEGRAM_CHAT_ID` was unset → fell back to the 3-id `CABINET_PROBE_TELEGRAM_CHAT_ID` list + care chat = four identical deliveries per send (MG saw his two personal accounts twice).

Fix ([H3557](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3557-OxAlpha_Systema-Sanscriticum_gap-alert-dedup-db-plus-group-links_26.08.26.md)): persistent dedupe in the new `recording_gap_alerts` table keyed by a sha256 fingerprint of the gap set (same incident = same key across the morning and stale windows, 36 h window, `--force` override); care copy prefixed «[Отдел заботы]»; successful send now exits SUCCESS; group names in alert lines are clickable t.me links (`App\Support\TelegramGroupLink`); `.92` `.env` got explicit `RECORDING_GAP_TELEGRAM_CHAT_ID=7961639774` (backup `.env.bak.recording-gap-chat-20260826`). Residual human op: course 399 / группа 125 recording for 25-08 still undelivered — resume from `AI Agent1` (courses 369/381 were delivered by exec 1753 on 26-08 morning).

_Dr. Mārcis Gasūns_
