# H1958 — libfl password out of bookbuilder SSH (result)

_Created: 31-07-2026 · Last updated: 31-07-2026_

**Executor:** Grok 4.5 (`grok-4.5`) · host `root@193.232.229.91` · workflow `СБОРКА КНИГ` (`gDewFLK9YiQk5MYy`, OFF/manual)

## Goal / stop

`/goal` — libfl password not present in n8n node JSON (rg clean) + auto_order reads env file; stop after human confirms login works.

## Verified live state (31-07-2026)

| Check | Result |
|---|---|
| `/root/.libfl-env` mode 600 | yes · keys `LIBFL_LOGIN`, `LIBFL_PASSWORD` only (values never in git) |
| `/opt/bookbuilder/auto_order_from_env.sh` mode 700 | sources `.libfl-env`, execs `venv/bin/python3 …/auto_order.py "$1" "$LIBFL_LOGIN" "$LIBFL_PASSWORD"` |
| Live node command | `=/opt/bookbuilder/auto_order_from_env.sh "{{ …ссылка на книгу… }}"` only |
| Password string in live `database.sqlite` | **absent** |
| Login email in live nodes | **absent** |
| Smoke: wrapper without URL | exit 4 `ERROR: book URL required` |
| Smoke: env load | `ENV_OK` (non-empty keys) |
| Pre-rotate password in live sqlite / workflows.json | **absent** |
| Pre-rotate password in `/opt/n8n/database.sqlite.bak-*` (Jul 25) | **was present** → shredded this pass if still on disk |

## Git deliverables

- Scrubbed re-export: [exports/book-assembly.live.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/exports/book-assembly.live.json)
- Inventory snapshot scrubbed: [_server_inventory_2026-07-30.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/_server_inventory_2026-07-30.json)
- Audit C01 + catalog gap row updated

## Human residual (handoff stop condition)

1. Confirm libfl account login still works with the **rotated** password stored only in `/root/.libfl-env`.
2. Optional dry-run: one manual `СБОРКА КНИГ` order path (workflow stays OFF until intentionally armed).

**No secret values in this file.**

_Dr. Mārcis Gasūns_
