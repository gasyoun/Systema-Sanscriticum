# Каталог n8n — context-ai.ru (samskrtam50)

_Created: 30-07-2026 · Last updated: 01-08-2026_

Живой inventory инстанса **n8n** на `193.232.229.91` (`samskrtam50`, UI: `https://context-ai.ru`).  
Снято **30-07-2026** read-only с `database.sqlite` + host paths. Машинный снимок: [`_server_inventory_2026-07-30.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/_server_inventory_2026-07-30.json). Redacted exports: [`exports/`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/exports/).

Связанные артефакты этого `/ask`:

| Doc | URL |
|---|---|
| PLAN index | [PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md) |
| Credential audit | [CREDENTIAL_AUDIT_N8N_CONTEXT_AI_2026-07-30.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/CREDENTIAL_AUDIT_N8N_CONTEXT_AI_2026-07-30.md) |
| Lecture content engine (product) | [PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md) |
| Operator clips manual | [MANUAL_N8N_LECTURE_CLIPS_OPERATOR_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_N8N_LECTURE_CLIPS_OPERATOR_RU.md) |

**Не содержит** значений секретов. При повторной выгрузке — scrub перед git.

---

## 1. Host & runtime

| Item | Value |
|---|---|
| Host | `samskrtam50` · `193.232.229.91` |
| Public UI | `https://context-ai.ru` (Caddy → `n8n:5678`) |
| Install | `/opt/n8n` · `docker compose` (`n8nio/n8n:latest` + `caddy:latest`) |
| Data volume | `/opt/n8n/storage` → `/home/node/.n8n` |
| Media bind | `/data` → `/data` (`audio`, `clips`, `bookbuilder`) |
| Local port | `127.0.0.1:5678` only (not public) |
| Timezone env | `GENERIC_TIMEZONE` / `TZ` set (MSK expected) |
| Proxy | `HTTP(S)_PROXY` → privoxy → `socks-nl.service` SSH tunnel NL (`127.0.0.1:1080`) for YouTube/googlevideo |
| Prune | `EXECUTIONS_DATA_PRUNE` on; max age in `.env` |
| DB size | ~71 MB sqlite + WAL; **binary storage ~5.6 GB** (mostly ZOOM workflow `1EIqqNzMl5NNIxST` ~5.1 GB) |
| Workflows | **76** total · **5 Active** · 71 inactive |
| Credentials | **46** |
| Executions retained | 182 (17 error) at snapshot |

### docker-compose (shape)

```yaml
services:
  n8n:
    image: n8nio/n8n:latest
    ports: ["127.0.0.1:5678:5678"]
    volumes:
      - /opt/n8n/storage:/home/node/.n8n
      - /data:/data
    extra_hosts: ["host.docker.internal:host-gateway"]
  caddy:
    image: caddy:latest
    ports: ["80:80", "443:443"]
# Caddyfile: context-ai.ru { reverse_proxy n8n:5678 }
```

### Host scripts (вне n8n UI)

| Path | Role |
|---|---|
| `/opt/bookbuilder/process_book.sh` | aria2c pages → blank-page drop → mogrify → img2pdf → ocrmypdf rus+eng |
| `/opt/bookbuilder/fetch_page.py` | Playwright: page HTML + cookies for libfl viewer |
| `/opt/bookbuilder/auto_order.py` | Playwright: libfl login → cart → order → viewer URL (CLI args; do **not** put secrets in n8n) |
| `/opt/bookbuilder/auto_order_from_env.sh` | H1958: sources `/root/.libfl-env` (mode 600), calls `auto_order.py` with env login/password |
| `/root/.libfl-env` | Host-only keys `LIBFL_LOGIN` + `LIBFL_PASSWORD` (never commit) |
| `/opt/bookbuilder/requirements.txt` + `venv/` | playwright, img2pdf, ocrmypdf, … |
| `/root/.clip-env` | VK token + group id for lecture-clip SSH path (exists) |
| `/etc/systemd/system/socks-nl.service` | SOCKS5 tunnel for yt-dlp YouTube |
| privoxy | HTTP proxy front for n8n container |

---

## 2. Laravel ↔ n8n bridge

Источник env keys: `config/services.php` + `.env.example`. Live n8n status — snapshot 30-07-2026.

| Laravel env / caller | Expected n8n path | Live workflow | Live status | Gap |
|---|---|---|---|---|
| `N8N_CLIP_EXTRACT_WEBHOOK` · `DispatchLectureClipExtractionJob` | `/webhook/lecture-clip-extract` | `Lecture clip extract (H1452)` `GGs0G2azzkLqLbJj` | **ON** · Header Auth | 6 recent errors (no success in retention) |
| `N8N_CLIP_CALLBACK_SECRET` · callback → Laravel | n8n → `callback_url` | same workflow | ON | OK design; errors on ffmpeg/VK side |
| `N8N_PAYMENTS_WEBHOOK_URL` · payments export | `/webhook/payments` | `АДМИНКА+ТАБЛИЦА ОПЛАТ` `XWQHAwlxBAFe6xfj` | **ON** · **auth=none** | No Header Auth |
| `N8N_SCHEDULE_SHEET_WEBHOOK` · Filament schedule sync | `/webhook/schedule-sheet-sync` (template) | **MISSING** | — | JSON in repo not imported; stub `РАСПИСАНИЕ + ТАБЛ` only (UUID path, 1 node, off) |
| `N8N_MONTHLY_SCHEDULE_WEBHOOK` · `schedule:post-monthly` | `/webhook/monthly-schedule-post` | `Ежемесячный пост…` `eixPIvFjfPdOSrYo` | **OFF** | Imported, inactive; **C02 closed (H1959)** — Telegram via credential `@zapisi_ORSbot`, not URL token |
| `N8N_CALENDAR_POST_WEBHOOK` · `content:publish-due` | `/webhook/vk-calendar-post` | **MISSING** | — | Template in `docs/n8n/vk-calendar-post.workflow.json` not on server |
| `N8N_SOCIAL_POST_WEBHOOK` · `PublishSocialPostJob` | social_post path | **MISSING** | — | Wave-2 product; not imported |
| Zoom recording completed | Zoom webhook UUID path | `ZOOM 1.4 (Final) + АДМИНКА ТЕСТ` | **ON** · auth=none | Canonical prod ZOOM |
| Titles / lesson names from Laravel | webhook UUID | `ловим названия copy` | **ON** · auth=none | High traffic (123 success) |
| Lesson create from ZOOM | `POST https://samskrte.ru/api/lessons/from-zoom` | inside ZOOM workflow | ON | Reverse bridge (n8n→Laravel) |
| Transcript push | `PUT/POST …/api/lessons/{id}/transcript` | inside ZOOM | ON | Deepgram → admin |

Feature flags (Laravel, default OFF unless noted): `CLIP_MARKETING_ENABLED`, `CONTENT_FROM_LECTURES`, `CONTENT_AUTO_PUBLISH_PILOT`, `CONTENT_CALENDAR_ENABLED`, `CONTENT_CALENDAR_AUTOPILOT`.

---

## 3. Active workflows (deep dive)

### 3.1 `ZOOM 1.4 (Final) + АДМИНКА ТЕСТ` · `1EIqqNzMl5NNIxST` · ON

**Canonical prod ZOOM pipeline** (ruling D-ZOOM-1). 52 nodes. Dual webhooks (Zoom + secondary).  
Flow (simplified):

```
Zoom recording.completed webhook
  → validate token (Code/crypto)
  → download recording
  → YouTube upload + playlist + thumbnail
  → Rutube upload + playlist + short link (goo.su)
  → Telegram notify
  → create lesson POST samskrte.ru/api/lessons/from-zoom
  → yt-dlp audio on host (/data/audio) via SSH
  → Deepgram nova-3
  → OpenRouter AI agent (titles/metadata)
  → Google Drive TXT + Sheets mapping
  → transcript → samskrte.ru/api/lessons/{id}/transcript
  → optional delete Zoom cloud recordings (two Zoom accounts)
  → cleanup binaryData / audio
```

**Storage:** ~5.1 GB under workflow binary storage — prune candidate.  
**Risks:** webhook auth=none; dual Zoom credential delete; `:latest` image; SSH cleanup paths reference *other* workflow IDs (stale copy residue).  
**Exec:** 15 success / 6 error / 1 canceled (recent retention).  
**Export:** [`exports/zoom-1.4-admin-test.live.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/exports/zoom-1.4-admin-test.live.json)

Legacy siblings (OFF, archive-tag later): `ZOOM`, `ZOOM 1.2…`, `1.3…`, `1.4 (Final)`, `1.4 + АДМИНКА`, `1.4 copy`, `1.5 (test)`, `1.5 n.` — **do not activate**.

### 3.2 `Lecture clip extract (H1452)` · `GGs0G2azzkLqLbJj` · ON

8 nodes. Webhook path `lecture-clip-extract`, **Header Auth**, `responseMode=onReceived` (async — correct).  
SSH cut+upload using `/root/.clip-env`; callback to Laravel with separate Header Auth cred.  
**Exec:** 6 errors, 0 success in retention — **broken for production use until fixed**.  
**Export:** [`exports/lecture-clip-extract.live.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/exports/lecture-clip-extract.live.json)

### 3.3 `АДМИНКА+ТАБЛИЦА ОПЛАТ` · `XWQHAwlxBAFe6xfj` · ON

2 nodes: Webhook `payments` (auth=none) → Google Sheets appendOrUpdate.  
**Money-adjacent** — fence: plan must not change payment logic without human; **auth hardening is a separate gated handoff**.  
**Exec:** 9 success. Export: `admin-payments-sheet.live.json`.

### 3.4 `Ютуб список` · `b2LCmGsT8gKcQV6t` · ON

Daily 03:00 schedule → list playlists/items → filter new → append Google Sheet.  
**Exec:** 2 success. Export: `youtube-list.live.json`.

### 3.5 `ловим названия copy` · `egCkjS06dYtYcnmv` · ON

Webhook from Laravel (+ code allowlist of Telegram user IDs in inactive sibling pattern) → Sheets.  
**Highest volume:** 123 success. Export: `catch-titles-copy.live.json`.

---

## 4. Full workflow inventory (76)

Status · id · name · nodes · triggers · updated

| St | ID | Name | N | Triggers | Updated |
|---|---|---|---|---|---|
| ON | GGs0G2azzkLqLbJj | Lecture clip extract (H1452) | 8 | webhook | 2026-07-29 |
| ON | 1EIqqNzMl5NNIxST | ZOOM 1.4 (Final) + АДМИНКА ТЕСТ | 52 | webhook×2 | 2026-07-28 |
| ON | XWQHAwlxBAFe6xfj | АДМИНКА+ТАБЛИЦА ОПЛАТ | 2 | webhook | 2026-06-28 |
| ON | b2LCmGsT8gKcQV6t | Ютуб список | 6 | schedule | 2026-06-27 |
| ON | egCkjS06dYtYcnmv | ловим названия copy | 3 | webhook | 2026-07-28 |
| off | umx56MTx6vBYfJXQ | Content Harvester | 13 | schedule | 2026-02-05 |
| off | YFdGuNU66oEcYYCf | KINESCOPE | 8 | manual | 2026-01-17 |
| off | E5fMNFhDE2aF3txk | LMS ORS | 1 | webhook | 2026-02-13 |
| off | AlDrByBqiJ20a75W … 6ZwvfareQRIJoNTk | **My workflow** (21 items: base + 2–8, 10, 12–14, 16–24) | 1–19 | mixed | 2025-08 … 2026-06 |
| off | vOVGt2JUTtpQUHvO | Pars_TG_pars | 6 | executeWorkflow | 2025-08-28 |
| off | cUH4DMXr5onZSxis | Parse_TG_main | 5 | chatTrigger | 2025-08-28 |
| off | zRSYQq0aHTB9XLGx | Webinar Bot | 27 | webhook | 2026-06-19 |
| off | 0fUKbeECN1wBBRiD / L2wfA4ZZIIl2ZN3t / azxNm1xZIETuojXM | Webinar Bot — Registration (×3) | 10–26 | tg/webhook | 2026-05/06 |
| off | U66CimuvenCZKtSe | Webinar Bot — VK Survey (skeleton) | 9 | webhook | 2026-05-26 |
| off | nFN4rxzXoi0Qavw9 / a44v1zeK8d5SLtwf | Webinar Bot — VK Warming | 7–12 | schedule | 2026-06 |
| off | 99dzjNOSSRjxNX0w / E8szvLJXvswU2z9D | Webinar Bot — Warming / Sequence | 17 | schedule | 2026-05/06 |
| off | mgXS16QatfCvxlOV … ANlSfL2YJpA0vO5F | **ZOOM lineage** (8 inactive + 1 active above) | 9–51 | webhook | 2026-01…06 |
| off | 9Wi8Uvddxgow3zpn | logo animation | 28 | telegram | 2025-09-05 |
| off | i27PhDCFloVjxyx5 | rutube | 9 | manual | 2025-09-10 |
| off | SsnSwJD2IxgSrA7r / 8oJijJbvN1n0Ew9d | vk bot / copy | 10–13 | webhook | 2025-08/09 |
| off | HTg7FJUpHwjrbSYX | БОТ ТУКАН USERs | 13 | telegram | 2026-06-21 |
| off | eixPIvFjfPdOSrYo | Ежемесячный пост курсов → ВК + Telegram | 8 | webhook | 2026-06-23 |
| off | wGaKM3r6JXs3JhEv | Кросспостинг летописи (VK+TG) | 16 | schedule 10h+18h | 2026-07-28 |
| off | vAYmfuER3mIZ2iXy | ЛК | 11 | webhook | 2026-02-09 |
| off | zRuPbshhmRMU6YVI | ПИСАРЬ В ЧАТЫ | 5 | schedule | 2026-02-14 |
| off | 0ghTUdEZB1KnDPrD | РАСПИСАНИЕ + ТАБЛ | 1 | webhook | 2026-02-17 |
| off | gDewFLK9YiQk5MYy | СБОРКА КНИГ | 27 | manual | 2026-04-01 |
| off | qsSm7oBrEKCfV015 | СЕНЛЕР | 1 | webhook | 2026-01-23 |
| off | OxIMZUzGng5RbHrE | СООБЩЕНИЯ ОТ БОТА | 4 | manual | 2026-07-30 |
| off | yrz1bBEBWeFHSOok / lIDWsm2sqFkuBV23 | Сбор заявок (+ Logic Router) | 12–14 | telegram | 2026-02 |
| off | J2Q2OpZiWZKCm709 | автосторисы | 8 | webhook | 2025-09-07 |
| off | 6gatYC6rCvrUAAlk | видео бот | 7 | telegram | 2026-02-05 |
| off | f4CfmK5ckvhKECbs | вк авто | 9 | manual | 2026-01-22 |
| off | irHtMs8yAy3eIv9i | ловим названия | 6 | telegram | 2026-03-04 |
| off | lNKdx9us8ouWwD3n | названия для роликов | 15 | schedule | 2026-06-25 |
| off | OvMVNbm0L1myBptq | нарезка видео | 7 | telegram | 2026-03-23 |
| off | mkct0W3oFHftaBah | таймкоды | 17 | manual | 2026-07-30 |
| off | BsK3ShnzOWEPYVYG / KgnzSQcyR8iVRl8d | транскриб / +ллм | 18 | telegram | 2025-09…01 |
| off | T8scvz2KZpKNuF1B / 4IAHmDld7EfBum98 / nLnqV0SwhTaF9K0H | транскриб из таблицы (±таймкоды, Анатолий) | 19–22 | schedule ~23:35 | 2025-09…07 |
| off | FC8b5pBQguN1jYfN | удалить видео с ютуба | 2 | manual | 2026-03-08 |
| off | fPiFHptJ2KvA6IpN | чеки | 9 | telegram | 2025-09-05 |

Full fields (node type lists, code previews): JSON inventory file.

### Category rollup

| Category | Count | Notes |
|---|---|---|
| Active prod | 5 | See §3 |
| ZOOM lineage (inactive) | 8 | **Tagged** `archive`+`legacy` (H1963); never dual-activate |
| Transcript / timecodes | 6 | Sheet-driven + TG; shares yt-dlp/Deepgram patterns with ZOOM |
| Webinar bot family | 9+ | Registration/warming/VK skeletons — product contour, mostly off |
| Scratch `My workflow *` | 20 live | **Tagged** `archive`+`legacy` (H1963); catalog 30-07 said 21 — live count 20 |
| Social / crosspost | ~8 | monthly (off), летопись, vk bots, stories |
| Bookbuilder | 1 + host scripts | First-class product contour (security first) |
| Payments / checks | 2 | payments ON; чеки OFF |
| Video util | ~5 | kinescope, rutube, delete YT, нарезка |
| Other | rest | ЛК, СЕНЛЕР, logo animation, … |

---

## 5. Bookbuilder contour (first-class product; security-first)

**Workflow:** `СБОРКА КНИГ` `gDewFLK9YiQk5MYy` (OFF, manual).  
**Host:** `/opt/bookbuilder/*` + `/data/bookbuilder/books/{id}/`.

```
Google Sheet rows (book links)
  → libfl OAuth cookies
  → auto_order.py (Playwright order)
  → fetch_page.py (viewer HTML)
  → build URL list + cookies files in /tmp
  → process_book.sh (download, blank drop, compress, OCR PDF)
  → exiftool metadata
  → Google Drive upload
  → update sheet status
```

**Critical:** login/password historically **hardcoded in SSH node command** (see credential audit). Wave-1 docs only; rotation handoff is human-gated.

Export (redacted): [`exports/book-assembly.live.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/exports/book-assembly.live.json)

---

## 6. Node-type heatmap (instance-wide)

Top types: `httpRequest` 167 · `code` 123 · `googleSheets` 117 · `telegram` 86 · `ssh` 57 · `if` 44 · `switch` 38 · `youTube` 33 · `webhook` 31 · langchain `agent` 15 · `scheduleTrigger` 13 · Apify 8 · …

---

## 7. Gap table (docs/templates vs live)

| Gap | Severity | Evidence | Recommended action (later handoffs) |
|---|---|---|---|
| `schedule-sheet-sync` not imported | 🟠 | No workflow; Laravel env often empty | Import `docs/n8n/schedule-sheet-sync.workflow.json` + Header Auth |
| `vk-calendar-post` not imported | 🟠 | Template only | Import when `CONTENT_CALENDAR_AUTOPILOT` staging |
| `monthly-schedule-post` imported but OFF | 🟠 | Workflow exists, inactive | Activate after product smoke (token already on credential — H1959) |
| Telegram bot token inlined in monthly HTTP URLs | 🟢 fixed H1959 | Was live HTTP URLs; now `n8n-nodes-base.telegram` + `@zapisi_ORSbot` | Optional BotFather rotate of historical export token; leave workflow OFF until product smoke |
| libfl password in book SSH command | 🟢 fixed H1958 | Was live CLI args; now `auto_order_from_env.sh` + `/root/.libfl-env` (600) | Human: confirm one login still works |
| Lecture clip 6/6 errors | 🔴 | execution_entity | Debug SSH/ffmpeg/VK; dry-run one lesson |
| Payments webhook auth=none | 🔴 money-adj | node auth | Header Auth + Laravel secret (human-gated) |
| ZOOM webhooks auth=none | 🟠 | node auth | Zoom signature already in Code; still harden secondary webhook |
| `n8nio/n8n:latest` unpinned | 🟠 | compose | Pin digest/version |
| 5.6G binary storage | 🟡 | du | Prune old executions/binaryData offline |
| 20× My workflow + 8× ZOOM copies | 🟢 fixed H1963 | live sqlite tags | Tags applied; **no delete**; export list below |
| Social post workflow missing | 🟡 | product Wave 2 | Import when pilot armed |

---

## 7b. Tag set addendum — H1963 (01-08-2026)

**Goal:** make the UI operable by marking scratch + superseded ZOOM lineage without deleting anything or flipping Active.

### Tag set (canonical names)

| Tag name | Tag id (live) | Meaning |
|---|---|---|
| `archive` | `af7aa560d1a125ba` | Not production; keep for history / future export-before-purge |
| `legacy` | `f2e703e2a44a2070` | Superseded by a newer canonical workflow (or pure scratch) |
| `lessons` | `SRHUMzPCFXfM66rO` | Pre-existing (unrelated product tag; leave alone) |

Both `archive` and `legacy` are applied together to every H1963 target. Future agents filter UI by either tag.

### Scope rules

| Include | Exclude |
|---|---|
| All `My workflow*` (live **20**, all inactive) | Active set (5 workflows) — never touch |
| All **inactive** `ZOOM*` lineage (**8**) | Canonical **ON** `ZOOM 1.4 (Final) + АДМИНКА ТЕСТ` `1EIqqNzMl5NNIxST` |

**Not done (by design):** no deletes · no `active` flips · no node/graph edits · no `isArchived` mass-flip (4 ZOOM copies already had `isArchived=1` from earlier UI work).

### Smoke (host, 31-07-2026 UTC)

| Check | Result |
|---|---|
| `archive` + `legacy` tagged workflow count | **28** each (20 My + 8 ZOOM inactive) |
| Active set before/after | **unchanged** (5 workflows: Lecture clip · ZOOM 1.4+АДМИНКА ТЕСТ · payments · Ютуб список · ловим названия copy) |
| `workflow_entity` count | **76** (no deletes) |
| Canonical ZOOM tagged? | **no** |

DB backup pre-tag: `/opt/n8n/backups/h1963/database.sqlite.pre-tag.20260731T221126Z` on `193.232.229.91`.

### Tagged ID export

Full machine list: [`exports/h1963-archive-legacy-tagged-ids_2026-07-31.csv`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/exports/h1963-archive-legacy-tagged-ids_2026-07-31.csv).

| Group | Count | IDs (workflow_id) |
|---|---|---|
| My workflow* | 20 | `AlDrByBqiJ20a75W` `YfdiM8FSYDFXNobW` `hi6CqbX9UX0bQfaK` `l6j8t5RHyYtMI8vL` `4qNFy707GEtLZJpg` `2BdJGhwtCxRqGh9b` `kcrcj5GKWXuy6Lyk` `VhLIACWtrYwC7fJW` `xVu3aaw13DJfWcpr` `y2gRwFpkoqZjIcyj` `UOHueG3PiVupVh7O` `xGFWEoEvZraUBdkD` `EIUuWQF7x3gjIW3R` `Wm0mLBukW2TWqHLh` `3Mzz3V6oPQquD3Bz` `kABgakYrg6hcwPOd` `cXKrTSrWxCaVWJMB` `THnIiwwb4XVC7qns` `EBtkhJtLIU8JwP1g` `6ZwvfareQRIJoNTk` |
| ZOOM lineage (inactive) | 8 | `mgXS16QatfCvxlOV` `usc3QJOVj37bOCfM` `aiLHLvGPpR56g0o3` `iZAtDoeTV4eLRmA6` `MtN1h7FdF3JTmrse` `fDMCDXjS6ZWlkOwQ` `G1eqOsfzNGABo6nA` `ANlSfL2YJpA0vO5F` |

**UI note:** tags were written via sqlite (`tag_entity` + `workflows_tags`). If the browser still hides them, hard-refresh the editor or restart `n8n-n8n-1` once (no compose recreate).

**Count note vs 30-07 catalog:** inventory text said **21** My workflow*; live host on H1963 day has **20** (no `My workflow 3` / 9 / 11 / 15). Tagged = live set, not the stale 21.

---

## 8. How to regenerate this catalog

On the server (read-only copy of sqlite + scrub), or from a workstation with SSH:

```bash
# sketch — see PLAN IMPLEMENTATION for full script path
ssh root@193.232.229.91 'python3 /path/to/export_n8n_inventory.py'
# commit new _server_inventory_YYYY-MM-DD.json + bump this doc header
```

Never commit unredacted `credentials.json` or raw `.env`.

---

_Dr. Mārcis Gasūns_
