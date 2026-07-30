# IMPLEMENTATION — Wave 1 · Marathon 28-08 live

_Created: 30-07-2026 · Last updated: 30-07-2026_

Index: [PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md).  
Evidence log: [MARATHON_28_08_LIVE_RUNBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MARATHON_28_08_LIVE_RUNBOOK.md).  
Legacy steps: [MARATHON_ACTIVATION_CHECKLIST.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/MARATHON_ACTIVATION_CHECKLIST.md) (content still valid; **prod ownership superseded by D7** — agent may run deploy when keys allow).

Ordered steps. Each depends on prior unless marked *parallel*.

---

### Step 0 — Worktree + claim (meta)

1. `git fetch origin` in Systema; worktree off `origin/main` (session-unique name).
2. Open/update [MARATHON_28_08_LIVE_RUNBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MARATHON_28_08_LIVE_RUNBOOK.md) Evidence section with start timestamp + model tier+version.
3. **Fence check:** do not open Payment/access files for edit.

### Step 1 — L1 Funnel content

1. Confirm marathon routes/views on `main` (H440); no product rewrite.
2. Ensure Filament `LandingPage` for `MARATHON_LANDING_SLUG` (default `konsultaciya-po-onlayn-kursam`) exists **or** document plain `/online/konsultaciya` acceptable + schedule H1067 publish path (landing A then B if still pending).
3. Publish/comms pack H1067: execute remaining publish steps from DEPLOY_QUEUE / issue [#814](https://github.com/gasyoun/Systema-Sanscriticum/issues/814) without inventing copy.
4. `curl -sI https://samskrte.ru/online/konsultaciya` (or final slug) → 200; log.
5. **ORS CTA (parallel):** pick one live surface (FAQ answer, bot template, or Systema help link); ensure URL to marathon landing; document path in runbook.

### Step 2 — Deploy truth (parallel with Step 1 after code-only fixes)

1. Inspect [`.github/workflows/deploy.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/deploy.yml) + issue [#828](https://github.com/gasyoun/Systema-Sanscriticum/issues/828): if deploy is no-op, either (a) wire Environment secrets + deploy user SSH when credentials exist, or (b) document **agent fallback**: after each merge, SSH `sudo bash deploy.sh` and log SHA.
2. Quarantine flaky prod-coupled tests if they block CI noise ([#865](https://github.com/gasyoun/Systema-Sanscriticum/issues/865) CabinetProbe): mark skipped/isolated with comment + issue link — **do not** block real deploy path (D20).
3. Ship any workflow/test quarantine via PR (non-money).

### Step 3 — Prod baseline: migrate + deploy current main

1. On server (agent if SSH works): `cd` app root → `sudo bash deploy.sh` (or documented path).
2. `php artisan migrate --force` if deploy does not already.
3. Confirm `php artisan schedule:list` includes `marathon:deliver-due`, `backup:run`, etc.
4. Log `git rev-parse HEAD` + migrate status in Evidence.

### Step 4 — L2 Money path (activate only)

1. Verify env: `TOCHKA_*` present (values not printed to git).
2. Ensure marathon paid checkout route works on staging or one **real** ₽500 test payment policy: if live charge ambiguous → **STOP** (D23). Prefer existing test/small amount procedures already used by ops; do not invent refunds.
3. Smoke: start checkout → Tochka hosted page loads → (complete only if safe) success callback.
4. **No code changes** to Payment/access.

### Step 5 — L3 Notify · Telegram

1. Filament Marketing Settings: `tg_bot_username` / `tg_bot_token` for `@samskrte` (or current bot) — if agent cannot Filament, set via tinker/env only if already the house path; never commit tokens.
2. Create/confirm Day-3 `Schedule` + `MARATHON_SCHEDULE_ID` + `config:clear` (checklist §5).
3. Test enrollment → `/start` → Day 1 within ~15 min (`marathon:deliver-due` can be run once manually: `php artisan marathon:deliver-due`).

### Step 6 — L3 Notify · SMTP (H1449 path)

1. If mailbox/SPF/DKIM credentials exist on server or agent vault: set real `MAIL_*` (not mailpit); `config:clear`.
2. Send password-reset or marathon mail to a controlled inbox; log Message-ID / delivery.
3. Optionally enable `EMAIL_CAMPAIGNS` only after single transactional success — still no bulk to full list in W1.
4. If secrets missing: **PARK** email leg (D26); TG must still pass for partial notify — full A–E needs email (D17) so “fully live” waits or agent uses only available channel and marks email PARK (cannot claim full live).

### Step 7 — DR · Backup off-site

1. If `YANDEX_DISK_LOGIN` + `YANDEX_DISK_APP_PASSWORD` settable: set in server `.env` (not git); `config:clear`.
2. Run `php artisan backup:run` once (or wait for Monday); confirm local archive + Yandex path `/Backups/systema-sanscriticum`.
3. If secrets absent: verify **local** backup still works; PARK off-site; log.

### Step 8 — DR · Uptime Telegram

1. Set GitHub Actions secrets `TELEGRAM_BOT_TOKEN` + `TELEGRAM_CHAT_ID` if agent has admin rights on repo; else PARK.
2. `workflow_dispatch` uptime or wait for schedule; force a dry-fire if workflow supports it; log alert or green checks.

### Step 9 — Auth surface on prod

1. Confirm latest login/CSRF fixes from main are deployed (issues around Kostina login).
2. Smoke `GET/POST /login` and `/shop/login` without 419 English raw mismatch.
3. No money code.

### Step 10 — Full smoke A–E + close

1. Execute verification doc commands end-to-end.
2. Fill runbook checklist; set status LIVE or BLOCKED with PARK list.
3. Update [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) rows closed by this pass; GTD human rows only for true residual secrets if any remain after agent-max.
4. PR this docs set + any non-money code; merge; deploy.

---

## File touch map (expected)

| Path | Action |
|---|---|
| `docs/MARATHON_28_08_LIVE_RUNBOOK.md` | Evidence throughout |
| `docs/PLAN_*` / `ROADMAP_*` / `ARCHITECTURE_*` / `IMPLEMENTATION_*` / `VERIFICATION_*` | This plan pack |
| `.github/workflows/deploy.yml` and/or tests quarantine | Only if fixing #828/#865 |
| `.env` on **server** | Secrets — never commit |
| Application PHP money files | **Forbidden** |

---

_Dr. Mārcis Gasūns_
