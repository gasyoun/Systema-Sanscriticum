# Ops note — lecture-clip extract fix (H1964)

_Created: 01-08-2026 · Last updated: 01-08-2026_

**Host:** `193.232.229.91` · `context-ai.ru` · workflow `GGs0G2azzkLqLbJj`  
**Executor:** Grok 4.5 (`grok-4.5`) · handoff [H1964](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1964-Grok_Systema-Sanscriticum_n8n-bridge-fix-lecture-clip_30.07.26.md)  
**Issue:** [#666](https://github.com/gasyoun/Systema-Sanscriticum/issues/666) (Ivan / pe4kinsmart-tech)

## Goal (met)

| Check | Result |
|---|---|
| ≥1 success n8n execution | **yes** — execution **376** (`success`, 2026-08-01) |
| Laravel `lecture_clips` callback rows | **yes** — lesson **1802**, new row id **6** (`H1964 dry-run clip`, spans 2–12 s) |
| `/root/.clip-env` present (VK keys) | **yes** — `VK_ACCESS_TOKEN`, `VK_VIDEO_GROUP_ID` (mode 600) |
| yt-dlp + ffmpeg on host | **yes** — yt-dlp `2026.06.09`, ffmpeg `7.1.4` |
| Marketing flag flipped by agent | **no** — `CLIP_MARKETING_ENABLED` was already `true` on `.92` |

## Pre-fix error census (retention before H1964)

| Exec | Status | Failure class |
|---|---|---|
| 121 | error | `$env` blocked (`access to env vars denied`) — already mitigated live by `/root/.clip-env` path |
| 122–124, 126 | error | `Expand spans`: `unexpected action: missing` — body was probe `{"probe": true}` (not Laravel) |
| 125 | error | Full cut+VK **succeeded** (5 clips uploaded); **Laravel callback** failed with **HTTP 503 Privoxy** (`Connect failed`) |

## Root causes

1. **Probe noise** — health/probe POSTs to the production webhook produced 4 of 6 “errors” without a real clip payload.
2. **Callback path** — n8n container uses `HTTP(S)_PROXY` → privoxy (`host.docker.internal:8118`) → SOCKS tunnel. On 29-07-2026 privoxy returned 503 for `https://samskrte.ru/…`; on 01-08-2026 the same CONNECT path returns **200** (socks-nl + privoxy both `active`).
3. **Env access** — already fixed earlier: VK secrets live only in `/root/.clip-env` on the SSH host, not in Code-node `$env`.

## Fixes applied (01-08-2026)

1. **Dry-run proof** — seeded short synthetic `/data/clips/src-1802.mp4`, one span (2–12 s), webhook with real `N8N_CLIP_EXTRACT_SECRET` (value not logged). Pipeline: Expand → SSH cut+VK → Aggregate → Laravel callback → cleanup. Callback response: `{ok: true, received: 1, created: 1}`.
2. **Probe guard** — live `Expand spans` Code node now returns `[]` for `probe` / `health` / `body.probe === true` instead of throwing. DB backup before edit: `/opt/n8n/backups/database.sqlite.h1964.*`. n8n container restarted once to reload Active workflow.
3. **No marketing flag change** — flag already ON; no new human gate required for callback write path.
4. **SSH `cwd` hardening (dual-run residual, same day)** — both SSH nodes (`Нарезать и залить в VK`, `Убрать исходник`) had `cwd=/data/clips`. The n8n SSH node **cd’s before the command body**, so a missing directory fails the node even when the shell script starts with `mkdir -p /data/clips`. Live nodes now use **`cwd=/data`** (command still writes under `/data/clips/...`). Nodes backup: `/opt/n8n/backups/h1964/workflow_GGs0G2azzkLqLbJj_nodes.pre-*`. Host still ensures `/data/clips` exists (mode 755).

## Live stack (verified)

| Item | State |
|---|---|
| n8n image | `n8nio/n8n:2.27.5@sha256:d53243d06c7f7de81910ac922ff55ed4b58c9c3c761d7f2f8443d0567990def3` |
| privoxy | `active` on `127.0.0.1:8118` (+ docker bridges) |
| socks-nl | `active` on `127.0.0.1:1080` |
| workflow stats (post-fix) | `production_success=1`, prior `production_error=6` kept historical |
| Laravel lesson 1802 clips | 6 rows (ids 1–5 from 29-07-2026 real lesson spans; id 6 = H1964 dry-run) |

## Residual / operator notes

- **Privoxy fragility:** if Laravel callback 503s again, check `systemctl status socks-nl privoxy` before re-debugging the workflow. Prefer keeping app-domain reachability healthy rather than removing the YouTube proxy (yt-dlp still needs it).
- **Probes:** use `{"probe": true}` or `"action":"health"` — they no longer error the workflow (empty item list ends the run cleanly).
- **SSH cwd:** keep `cwd=/data` (or any always-present path); never point cwd at a path the same command is supposed to create.
- **Secrets:** never commit `VK_*`, `N8N_CLIP_*`. Values stay on `.91` (`/root/.clip-env`) and `.92` (`/var/www/html/.env`).
- **Git template:** `docs/n8n/lecture-clip-extract.workflow.json` may lag the live probe guard + cwd pin until next redacted re-export; live truth is workflow id `GGs0G2azzkLqLbJj`.
- **Host ffmpeg smoke (no VK):** synthetic black 2s cut on host returns `FFMPEG_CUT_OK` (verifies yt-dlp/ffmpeg stack independent of webhook).

## Reproduce (names only)

1. Ensure `/root/.clip-env` and Active workflow.
2. POST `https://context-ai.ru/webhook/lecture-clip-extract` with header `X-Webhook-Secret` = Laravel `N8N_CLIP_EXTRACT_SECRET`, body `action=clip_lecture` + `lesson_id` + `spans[]` + `callback_url` + video URL.
3. Confirm n8n execution `success` and `lecture_clips` rows for that `lesson_id`.

_Dr. Mārcis Gasūns_
