# Ops note — prune n8n binary storage (H1962)

_Created: 01-08-2026 · Last updated: 01-08-2026_

**Host:** `193.232.229.91` · `samskrtam50` · `/opt/n8n` · UI `https://context-ai.ru`  
**Executor:** Grok 4.5 (`grok-4.5`) · handoff [H1962](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1962-Grok_Systema-Sanscriticum_n8n-hygiene-prune-storage_30.07.26.md)  
**Finding closed:** catalog/credential **C16** / “5.6G binary storage” (was 🟡; measured **6.6G** at run time)

## Goal (met)

| Check | Result |
|---|---|
| `du -sh /opt/n8n/storage/storage` under prior 5.6G | **yes** — **1.4G** after (was **6.6G** before) |
| Documented delta | **−5.2G** binary under `storage/storage` |
| Active workflows still listed | **yes** — same five (COUNT=5) |
| Workflow definitions | **untouched** — `workflow_entity` still **76** |
| healthz | **200** on `http://127.0.0.1:5678/healthz` |

## Before / after

| Metric | Before (01-08-2026 ~03:09 UTC) | After (~03:10 UTC) |
|---|---|---|
| `/opt/n8n/storage/storage` | **6.6G** | **1.4G** |
| Root disk used (`/`) | 15G / 49G (**31%**) | 9.2G / 49G (**20%**) |
| ZOOM `1EIqqNzMl5NNIxST` binary tree | 6.1G (16 exec dirs) | 1.4G (2 exec dirs) |
| Inactive `mkct0W3oFHftaBah` (таймкоды) | 307M | **removed** (binary tree only) |
| Inactive `T8scvz2KZpKNuF1B` (транскриб из таблицы) | 192M | **removed** (binary tree only) |

Host inventory + result log: `/opt/n8n/backups/h1962/` (`PLAN.txt`, `RESULT.txt`, `du-*-before/after-*`, `df-*-before/after-*`).

## What was deleted (binary only)

**Not deleted:** workflow JSON / `workflow_entity` rows / Active flags / sqlite execution history rows.

1. **Inactive workflow binary trees** (workflows remain OFF in UI, definitions intact):
   - `/opt/n8n/storage/storage/workflows/mkct0W3oFHftaBah/` (таймкоды, `active=0`)
   - `/opt/n8n/storage/storage/workflows/T8scvz2KZpKNuF1B/` (транскриб из таблицы, `active=0`)
2. **14 older ZOOM execution binary dirs** under  
   `/opt/n8n/storage/storage/workflows/1EIqqNzMl5NNIxST/executions/{13,14,15,16,17,24,34,35,52,54,77,137,141,240}/`

## What was kept (Active ZOOM)

Canonical Active ZOOM: `1EIqqNzMl5NNIxST` · `ZOOM 1.4 (Final) + АДМИНКА ТЕСТ` · still **ON**.

| Exec id | Status | startedAt (UTC) | Binary kept |
|---|---|---|---|
| **351** | success | 2026-07-31 19:00 | **yes** (~625M) — latest success |
| **173** | success | 2026-07-30 11:24 | **yes** (~807M) — prior success |

UI may show missing binary attachments for pruned older executions; that is expected.

## Active set after prune (COUNT=5)

| id | name |
|---|---|
| `GGs0G2azzkLqLbJj` | Lecture clip extract (H1452) — ffmpeg + VK + Laravel callback |
| `1EIqqNzMl5NNIxST` | ZOOM 1.4 (Final) + АДМИНКА ТЕСТ |
| `XWQHAwlxBAFe6xfj` | АДМИНКА+ТАБЛИЦА ОПЛАТ |
| `b2LCmGsT8gKcQV6t` | Ютуб список |
| `egCkjS06dYtYcnmv` | ловим названия copy |

## Prefer `/data` (ephemeral)

Host already mounts `/data` into the n8n container (`/data:/data` in compose). Layout (small today):

| Path | Role |
|---|---|
| `/data/clips` | lecture-clip / media landing |
| `/data/audio` | audio scratch |
| `/data/bookbuilder` | book assembly outputs |

**Policy:** write large media to `/data/...` and delete after the workflow finishes; do **not** rely on n8n’s per-execution `binary_data` under `storage/storage/workflows/*/executions/` for long retention — that path refilled ZOOM to multi‑GB in ~one week.

Optional hard retention (not applied this pass — would recreate compose):  
`EXECUTIONS_DATA_PRUNE=true` + `EXECUTIONS_DATA_MAX_AGE=<hours>` + `EXECUTIONS_DATA_PRUNE_MAX_COUNT=<n>` in n8n env.

## Smoke (host)

```sh
curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:5678/healthz
# expect 200
sqlite3 /opt/n8n/storage/database.sqlite "SELECT COUNT(*) FROM workflow_entity WHERE active=1;"
# expect 5
du -sh /opt/n8n/storage/storage
# expect ~1.4G (will grow again with new ZOOM runs)
```

**Not done (by design):** live ZOOM dry execution (dual webhooks + production event path). Graph + Active flag unchanged; binary retention is independent of node logic.

## Residual / next hygiene

| Item | Note |
|---|---|
| Re-growth after next ZOOM lectures | re-run same keep-N-successes prune, or enable `EXECUTIONS_DATA_PRUNE*` |
| Pin `caddy:latest` | still optional (out of H1961/H1962) |
| Archive-tags | already done [H1963](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1963-Grok_Systema-Sanscriticum_n8n-hygiene-archive-tags_30.07.26.md) |

_Dr. Mārcis Gasūns_
