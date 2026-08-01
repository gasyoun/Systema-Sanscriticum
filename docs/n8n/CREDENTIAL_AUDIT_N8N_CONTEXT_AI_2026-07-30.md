# Credential audit — n8n context-ai.ru

_Created: 30-07-2026 · Last updated: 31-07-2026_

Read-only audit of secrets posture on `samskrtam50` / `https://context-ai.ru`.  
**No secret values are written here or in git exports.** Findings only: severity × location × remediation × owner.

Companion: [CATALOG](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/CATALOG_N8N_SERVER_CONTEXT_AI_2026-07-30.md) · [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md)

---

## Scope

- 46 rows in `credentials_entity` (types + names only inspected)
- Hardcoded secrets in workflow node parameters (Code / SSH / HTTP URL)
- Webhook authentication modes on Active + Systema-bridge workflows
- Host files: `/opt/n8n/.env` keys, `/root/.clip-env` presence, bookbuilder scripts
- Laravel-side `N8N_*` secrets (names only)

Out of scope for this memo: actually rotating tokens (human / gated handoff).

---

## Severity legend

| Dot | Meaning |
|---|---|
| 🔴 | Exploitable or already leaked into workflow JSON; rotate ASAP |
| 🟠 | Missing auth / weak pattern on reachable surface |
| 🟡 | Hygiene / naming / dual copies; not immediately exploitable |

---

## Findings table

| ID | Sev | Location | Finding | Remediation | Owner |
|---|---|---|---|---|---|
| C01 | 🟢 **REMEDIATED (H1958, 31-07-2026)** was 🔴 | Workflow `СБОРКА КНИГ` · SSH node `автозаказ` | Was: libfl login+password as CLI args. **Now:** node runs only `/opt/bookbuilder/auto_order_from_env.sh "<url>"`; secrets in `/root/.libfl-env` (mode 600) keys `LIBFL_LOGIN`/`LIBFL_PASSWORD`; live sqlite has **zero** password/login strings; git export scrubbed | Residual: human confirms one login/order path works; Jul-25 sqlite `.bak-*` may still hold the **pre-rotate** secret — shred offline when safe; stale `/opt/n8n/workflows.json` still had login email until next full re-export | H1958 Grok 4.5 |
| C02 | 🔴 | Workflow `Ежемесячный пост…` · HTTP Telegram URLs | **Bot token embedded in URL** `api.telegram.org/bot<token>/…` (not Telegram credential) | Rotate Telegram bot token via BotFather; switch nodes to official Telegram node or header; never put token in URL string | Human |
| C03 | 🟢 | Active `АДМИНКА+ТАБЛИЦА ОПЛАТ` webhook `payments` | **H1960 remediated 01-08-2026**: Header Auth credential `payments-webhook (H1960)`; Laravel `N8N_PAYMENTS_WEBHOOK_SECRET` → `X-Webhook-Secret` | Unauth reject + one authorized append smoke | Ops (secret only on hosts, not git) |
| C04 | 🔴 | Active lecture-clip executions | 6/6 errors — pipeline armed in UI but not delivering; may leave partial VK state | Debug with one dry-run; confirm `/root/.clip-env` scopes (`video`); do not disable without noting | Ops + agent read-only logs |
| C05 | 🟠 | Active ZOOM webhooks (2 paths) | Node-level auth=none (Zoom CRC may be in Code) | Keep CRC; add shared-secret header on non-Zoom secondary webhook; restrict source IPs if possible | Ops |
| C06 | 🟠 | Active `ловим названия copy` webhook | auth=none; high traffic | Header Auth + Laravel secret | Ops |
| C07 | 🟠 | Docker image `n8nio/n8n:latest` | Unpinned upgrades can break nodes / auth behavior overnight | Pin version or digest in compose; staged upgrade | Ops |
| C08 | 🟠 | n8n `.env` contains `VK_ACCESS_TOKEN` / `VK_VIDEO_GROUP_ID` | Code nodes often **cannot** read `$env` (`N8N_BLOCK_ENV_ACCESS_IN_NODE`); false sense of config | Prefer host file (clip-env) or proper credentials; document which path is live | Ops |
| C09 | 🟠 | Host SSH private key credential `n8n` | SSH from container to host for yt-dlp/ffmpeg — powerful | Ensure key is host-only, no password reuse; audit authorized_keys; command allowlist long-term | Ops |
| C10 | 🟠 | Multiple Zoom OAuth credentials (6 named) | `Zoom account` ×4 + `Zoom ОРС` + `Zoom Цыди` | Inventory which still used by Active ZOOM; revoke unused | Human |
| C11 | 🟡 | Unnamed credentials | `Unnamed credential`, `Unnamed credential 2/3/4`, generic Header Auth 1–4 | Rename to purpose (`clip-callback`, `payments-webhook`, …) | Ops |
| C12 | 🟡 | 8 Telegram bot credentials | Many bots; unclear which production | Spreadsheet: bot username → workflows → status | Ops |
| C13 | 🟡 | Dual Google Drive/Sheets OAuth | `Google Drive account` + `Гугл диск`; Sheets + `аккаунт МЮ` | Consolidate or document owner | Human |
| C14 | 🟡 | OpenAI + OpenRouter + Anthropic + YANDEX GPT + Apify + Baserow + Supabase×2 | Broad third-party surface | Confirm still needed; rotate keys on schedule | Human |
| C15 | 🟡 | Backup files on host | `/opt/n8n/database.sqlite.bak-*` (Jul 25) + `credentials.json` export in `/opt/n8n/` | Encrypt or remove stale backups; `credentials.json` must not be world-readable or in git | Ops |
| C16 | 🟢 fixed H1962 | Workflow binary storage 5.6G | Was 6.6G; old media under `storage/storage/workflows/*` | Pruned to **1.4G** (−5.2G); kept ZOOM exec 351+173; inactive binary trees removed | [OPS_PRUNE_STORAGE_H1962](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/OPS_PRUNE_STORAGE_H1962_2026-08-01.md) |
| C17 | 🟠 | Deepgram / OpenRouter / goo.su / Rutube tokens in ZOOM HTTP nodes | Some via credentials, some may be query/header — re-check on edit | Prefer credential store; scrub on export | Ops |
| C18 | 🟡 | Laravel `.env` empty webhooks | `N8N_SCHEDULE_SHEET_WEBHOOK=` etc. often empty | Intentional inert OR misconfig — verify per env (staging/prod) | Human |

---

## Credential inventory (type · name only)

| Type | Name |
|---|---|
| anthropicApi | Anthropic account |
| apifyApi | Apify account |
| baserowApi | Baserow account |
| googleCalendarOAuth2Api | Google Calendar account |
| googleDocsOAuth2Api | Google Docs account |
| googleDriveOAuth2Api | Google Drive account · Гугл диск |
| googleSheetsOAuth2Api | Google Sheets account · аккаунт МЮ |
| googleSheetsTriggerOAuth2Api | Google Sheets Trigger account |
| httpBasicAuth | Unnamed credential 2 · 4 |
| httpHeaderAuth | Clip callback secret (H1452) · Header Auth account · 2 · 3 · 4 |
| httpQueryAuth | Query Auth account |
| oAuth2Api | Unnamed credential · 3 |
| openAiApi | OpenAi account · YANDEX GPT |
| openRouterApi | OpenRouter account · OpenRouter ОРС |
| sshPassword | docker |
| sshPrivateKey | n8n |
| supabaseApi | Supabase account · 2 |
| telegramApi | @courses657bot · @samskrtamru_bot · Telegram account · 2 · 3 · WEBINAR17 · rolic · rusamskrtam · zapisi-ORSbot |
| wordpressApi | Wordpress account |
| youTubeOAuth2Api | YouTube account · ОРС YouTube |
| zoomOAuth2Api | Zoom account · 2 · 3 · 4 · Zoom ОРС · Zoom Цыди |

---

## Positive controls already present

- Lecture-clip webhook uses **Header Auth**; callback uses separate Header Auth cred (H1452 design).
- `/root/.clip-env` exists for VK video upload (correct pattern vs `$env` in Code).
- n8n listens on **127.0.0.1:5678** only; TLS via Caddy.
- Execution data prune enabled.
- Git exports in this pass were scrubbed (bot tokens, passwords, long tokens).

---

## Immediate human checklist (not agent-executed)

1. **Rotate** libfl password used by bookbuilder; remove plaintext from `СБОРКА КНИГ`.
2. **Rotate** Telegram bot token that was URL-inlined in monthly post workflow.
3. Decide: enable Header Auth on `payments` and titles webhooks (money/comms impact).
4. Confirm whether `credentials.json` + sqlite `.bak-*` under `/opt/n8n` should be shredded or encrypted offline.
5. Fix lecture-clip error streak before marketing flag ON.

---

_Dr. Mārcis Gasūns_

