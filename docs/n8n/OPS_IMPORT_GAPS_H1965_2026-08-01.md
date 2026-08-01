# Ops note — n8n bridge import gaps (H1965)

_Created: 01-08-2026 · Last updated: 01-08-2026_

**Host:** `193.232.229.91` · `samskrtam50` · `/opt/n8n` · UI `https://context-ai.ru`  
**Executor:** Grok 4.5 (`grok-4.5`) · handoff [H1965](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1965-Grok_Systema-Sanscriticum_n8n-bridge-import-gaps_30.07.26.md)  
**Ladder:** `/n8n` **bridge · import-gaps** (product-gated)

## Goal (met)

| Check | Result |
|---|---|
| Each named gap **live+documented** XOR **explicit GTD defer** | **yes** — three rows below |
| No silent ON workflow mutation | **yes** — no import of placeholder graphs; no Active flips |
| No Laravel `.env` secret writes | **yes** — human-only residual |
| No secret values in git | **yes** |

## Live preflight (01-08-2026)

| Probe | Result |
|---|---|
| `docker ps` | `n8n-n8n-1` Up · image `n8nio/n8n:2.27.5` (H1961 pin) · `n8n-caddy-1` Up |
| Free disk | `/` ~33G avail (31% used) |
| Workflow COUNT | **76** (host Python on `/opt/n8n/storage/database.sqlite`) |
| `N8N_API_KEY` on host | **absent** (no public REST import path without human key) |
| `POST http://127.0.0.1:5678/webhook/schedule-sheet-sync` | **404** |
| `POST http://127.0.0.1:5678/webhook/vk-calendar-post` | **404** |
| `POST http://127.0.0.1:5678/webhook/monthly-schedule-post` | **404** (workflow exists but **inactive** — production webhook not registered) |

### Named gap inventory

| Gap | Live workflow | Active | Webhook path in nodes | Disposition |
|---|---|---|---|---|
| `schedule-sheet-sync` | **MISSING** (only stub `РАСПИСАНИЕ + ТАБЛ` `0ghTUdEZB1KnDPrD` OFF, unrelated UUID path) | — | not present | **GTD defer** — product not requesting Sheets export; `N8N_SCHEDULE_SHEET_WEBHOOK` empty in app env template usage |
| `vk-calendar-post` | **MISSING** | — | not present | **GTD defer** — `CONTENT_CALENDAR_AUTOPILOT` default OFF; import only when calendar autopilot staged ([DEPLOY_QUEUE №60](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)) |
| `monthly-schedule-post` | `Ежемесячный пост курсов → ВК + Telegram (monthly_schedule_post)` `eixPIvFjfPdOSrYo` | **OFF** | `monthly-schedule-post` | **GTD defer activate** — wait [H1959](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1959-Grok_Systema-Sanscriticum_n8n-sec-tg-token-rotate_30.07.26.md) token move out of URL + Header Auth + smoke; do **not** activate with inlined bot token |

## Why not import templates now

Handoff gate: **product-gated**. Work text: import when product needs them; **defer if not product-priority — document in GTD**.

1. **schedule-sheet-sync** template still has `ВСТАВЬТЕ_ССЫЛКУ_НА_ТАБЛИЦУ` + `REPLACE_WITH_YOUR_CREDENTIAL_ID`. Importing a non-runnable graph into the live UI without Google OAuth + sheet URL is clutter, not parity.
2. **vk-calendar-post** still has `REPLACE_VK_GROUP_ID` / `REPLACE_VK_GROUP_TOKEN`. Roadmap: import only when calendar autopilot staged; feature flags stay OFF until smoke.
3. **monthly** is already on the server OFF. Activation is blocked on credential hygiene (🔴 inlined Telegram token — H1959), not on missing import.
4. Wire Laravel env **only after human** (handoff fence). Agent did not write production `.env` on `.92` or n8n host secrets.

## When product re-arms (runbook pointers)

Full step lists (import → Header Auth → env keys → smoke) stay in:

- [docs/n8n/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/README.md) — schedule sheet · monthly · vk calendar sections  
- Templates: [`schedule-sheet-sync.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/schedule-sheet-sync.workflow.json) · [`vk-calendar-post.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/vk-calendar-post.workflow.json) · [`monthly-schedule-post.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/monthly-schedule-post.workflow.json)

**Mandatory before any activate:** Webhook **Header Auth** (`X-Webhook-Secret` name convention matching payments/clip); Laravel secret env **names only** (`N8N_SCHEDULE_SHEET_*` / `N8N_CALENDAR_POST_*` / `N8N_MONTHLY_SCHEDULE_*`); feature flags remain **OFF** until one authorized smoke.

## Residual human (Ivan / MG)

| # | Owner | Action |
|---|---|---|
| 1 | MG | Product: still need Filament → Google Sheets export? If yes → authorize import + Google OAuth + sheet URL |
| 2 | MG | Product: stage content calendar (`CONTENT_CALENDAR_ENABLED` → seed/fill → then only `CONTENT_CALENDAR_AUTOPILOT`) per DEPLOY_QUEUE №50–60 |
| 3 | Ivan | After H1959: strip inlined TG token from monthly workflow; Header Auth; one dry smoke; then decide activate |
| 4 | Ivan | Optional: mint `N8N_API_KEY` on host if future agent imports should use REST (currently absent) |

Tracking issue: [#999](https://github.com/gasyoun/Systema-Sanscriticum/issues/999) (assignee `@pe4kinsmart-tech`). Related hubs: [#666](https://github.com/gasyoun/Systema-Sanscriticum/issues/666), [#904](https://github.com/gasyoun/Systema-Sanscriticum/issues/904), [#982](https://github.com/gasyoun/Systema-Sanscriticum/issues/982).

## What was deliberately not done

- No import of placeholder JSON into live n8n  
- No activate of monthly / no ON graph edits  
- No Laravel money / payment path changes  
- No secret values committed  

_Dr. Mārcis Gasūns_
