# Marathon 28-08 — LIVE runbook + evidence log

_Created: 30-07-2026 · Last updated: 30-07-2026_

**Umbrella ID:** SAMSKRTE-TIER0 · **Wave-1 handoff:** [H1939](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1939-Grok_Systema-Sanscriticum_marathon-28-08-wave1-live_30.07.26.md) · **Pack:** /ask samskrte.ru 30-07-2026 · **Stem:** *_SYSTEMA_SAMSKRTE_TIER0_*

Plan index: [PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md).  
Implementation: [IMPLEMENTATION_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md).  
Verification: [VERIFICATION_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md).  
Legacy activation: [MARATHON_ACTIVATION_CHECKLIST.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/MARATHON_ACTIVATION_CHECKLIST.md).

**Status:** `BLOCKED` (A–C green; D+E residual secrets/config) · target cohort **28-08-2026**

## Checklist (layers)

- [x] L1 Funnel content (landing + H1067 path)
- [x] L2 Money path (Tochka ₽500) — path to hosted checkout; no live charge completed
- [ ] L3 Notify TG — **blocked:** MarketingSetting bot token is not a real BotFather token
- [ ] L3 Notify email — **blocked:** Yandex SMTP 554 spam rejection
- [ ] Smoke A–E all green — A B C only (D17 ⇒ not LIVE)
- [x] DR1 Yandex backup (or PARK) — **PARK** off-site; local healthy
- [x] DR2 Uptime TG (or PARK) — **PARK** TG alert channel; uptime HTTP job itself green
- [x] DEP1/DEP2 deploy truth — auto-deploy cron + SHA match
- [x] ORS1 CTA surface — homepage → LandingPage slug 200

## Evidence log

Append-only. Format: `YYYY-MM-DD HH:MM TZ · actor/model · action · result · proof`.

```
30-07-2026 · plan authored · Grok 4.5 (grok-4.5) · /ask samskrte.ru layered plan · docs only · no prod run
30-07-2026 15:00 MSK · Grok 4.5 (grok-4.5) · H1939 wave-1 start · worktree Systema-Sanscriticum-h1939-33308 off origin/main · fence: no Payment/access code edits
30-07-2026 15:00 MSK · Grok 4.5 (grok-4.5) · Smoke A · PASS · curl -sI https://samskrte.ru/online/konsultaciya → HTTP 200 (paid track copy «500 ₽» present in body)
30-07-2026 15:01 MSK · Grok 4.5 (grok-4.5) · L1 apply-landing-copy a · PASS · php artisan marathon:apply-landing-copy a → LandingPage #12 slug konsultaciya-po-onlayn-kursam upserted
30-07-2026 15:01 MSK · Grok 4.5 (grok-4.5) · Smoke B · PASS · POST free track+quiz_goal=try → MarathonEnrollment id=2 contact=+79990150131 magnet set · day0_started_at filled · Lead+enrollment rows confirmed via tinker
30-07-2026 15:03 MSK · Grok 4.5 (grok-4.5) · Smoke C first attempt · FAIL (agent probe error, not Tochka outage) · payment_id=14071 status=failed · log: Tochka 400 Validation Error Client-email reserved domain example.invalid · fence: no code change
30-07-2026 15:11 MSK · Grok 4.5 (grok-4.5) · Smoke C · PASS · paid register + POST /online/konsultaciya/pay → 302 Location https://merch.tochka.com/order/?uuid=f13ab013-323b-4200-b73c-ca997757aa48 · live card charge NOT completed (D23)
30-07-2026 15:02 MSK · Grok 4.5 (grok-4.5) · Schedule Day-3 · PASS (data) · Schedule #1122 start 2026-07-15 → 2026-08-28 19:00:00 · Zoom link already set · zoom_recording_url still empty (post-live step)
30-07-2026 15:03 MSK · Grok 4.5 (grok-4.5) · Smoke D · FAIL/PARK · MarketingSetting tg_bot_username=samskrte_bot but tg_bot_token length=25, not BotFather shape (no colon) · all LandingBot tokens same class · config services.telegram bot=testpodpiska12_bot getMe OK (wrong bot for marathon magnet) · marathon:deliver-due → sent 0 · residual @DO: paste real @samskrte_bot token in Filament Marketing Settings (never invent)
30-07-2026 15:03 MSK · Grok 4.5 (grok-4.5) · Smoke E · FAIL/PARK · MAIL_MAILER=smtp host=smtp.yandex.ru from=rusamskrtam@yandex.ru · Mail::raw accepted then failed_jobs 554 5.7.1 Message rejected under suspicion of SPAM (ya.cc/1IrBc) · residual: SPF/DKIM/DMARC on sending domain (#504 / H1449 path) — not a code edit in W1
30-07-2026 15:00 MSK · Grok 4.5 (grok-4.5) · DR1 · PARK off-site · php artisan backup:list → local healthy 7 backups newest ~2d / 810MB; yandex_disk IsReachable ❌ Unauthorized · env has YANDEX_API_* (search) but not working YANDEX_DISK_LOGIN+APP_PASSWORD WebDAV · local path storage/app/Laravel/*.zip present
30-07-2026 15:00 MSK · Grok 4.5 (grok-4.5) · DR2 · PARK TG dry-fire · uptime-samskrte.yml schedule runs conclusion=success (e.g. run 30538741424) · repo Actions secrets list empty from agent; workflow_dispatch force_alert hit API TLS timeout this session · residual: set TELEGRAM_BOT_TOKEN+TELEGRAM_CHAT_ID Actions secrets once
30-07-2026 15:00 MSK · Grok 4.5 (grok-4.5) · DEP1 · PASS · SSH root@193.232.229.92 APP_DIR=/var/www/html · HEAD=93d1d81eb240cf5b0509e4d8f7affb46d99c7d94 equals origin/main at probe time · URL samskrte.ru production
30-07-2026 15:00 MSK · Grok 4.5 (grok-4.5) · DEP2 · PASS (agent fallback, not GHA) · root cron */30 systema-auto-deploy-run.sh · auto_deploy.log shows completed deploys (e.g. 5b157361→dd9ba5ae smoke 200) · GitHub Environment production secrets still total_count=0 → deploy.yml remains intentional no-op success (#828) — dual path documented; do not treat GHA green as deploy truth
30-07-2026 15:03 MSK · Grok 4.5 (grok-4.5) · ORS1 · PASS · https://samskrte.ru/ homepage card href https://samskrte.ru/konsultaciya-po-onlayn-kursam → HTTP 200 LandingPage after variant-a upsert · funnel register remains https://samskrte.ru/online/konsultaciya
30-07-2026 15:01 MSK · Grok 4.5 (grok-4.5) · Auth surface · PASS (GET) · /login 200 with CSRF meta + H1774 csrf refresh script · /shop/login GET 405 allow POST (expected for that route shape)
30-07-2026 15:15 MSK · Grok 4.5 (grok-4.5) · Verdict · BLOCKED not LIVE · A B C + DEP + ORS1 green; D E DR off-site/TG residual · no money/access code edited · no force-push · no secrets committed
```

## PARK / residual secrets

| Item | Needed for | Status |
|---|---|---|
| Yandex WebDAV app password (`YANDEX_DISK_LOGIN` + `YANDEX_DISK_APP_PASSWORD` on **server** `.env`) | DR1 off-site | **PARK** — local backup OK; WebDAV Unauthorized |
| Real BotFather token for `@samskrte_bot` in Filament Marketing Settings (`tg_bot_token`) | D (Day 1 drip) + channel posts admin | **OPEN @DO** — current value is 25-char non-token placeholder; LandingBots same class |
| SPF/DKIM/DMARC + clean Yandex sender reputation for `rusamskrtam@yandex.ru` (or chosen mailbox) | E transactional mail | **OPEN** — SMTP configured but 554 spam; tracks [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504) / H1449 |
| GitHub Actions `TELEGRAM_BOT_TOKEN` + `TELEGRAM_CHAT_ID` | DR2 TG dry-fire | **PARK** — uptime HTTP job already green without them |
| GitHub Environment `production` secrets `DEPLOY_HOST`/`DEPLOY_USER`/`DEPLOY_SSH_KEY` + required reviewers | GHA deploy.yml truth (#828) | **OPEN @DO human** — agent path is auto-deploy cron (DEP2 PASS without these) |
| Tochka live test policy (complete ₽500 with real card) | optional full C success-callback | **NOT RUN** — hosted page reached; charge left for ops-safe window |
| Make magnet bot admin of `@samskrte` channel | H1936 channel posts | **OPEN** (Telegram-side only; prior handoff) |

## Final verdict

| Field | Value |
|---|---|
| LIVE / BLOCKED | **BLOCKED** (D17: A–E not all green) |
| A–E | A PASS · B PASS · C PASS (to hosted page, no charge) · D FAIL/PARK · E FAIL/PARK |
| DR | DR1 PARK off-site (local OK) · DR2 PARK TG secrets (HTTP uptime OK) |
| Date | 30-07-2026 |
| Executor | Grok 4.5 (`grok-4.5`) · H1939 |
| Prod SHA | `93d1d81eb240cf5b0509e4d8f7affb46d99c7d94` |
| SSH host | `193.232.229.92` (docs also mention `31.129.104.252` — closed on agent path) |

### What a human does next (residuals only)

1. Filament → Marketing Settings: set real `tg_bot_token` for `samskrte_bot` (BotFather). Re-run `php artisan marathon:deliver-due` after a test `/start` on the deep link.
2. DNS SPF/DKIM for the mailbox used in `MAIL_FROM_*`; re-test one transactional mail; then only flip `EMAIL_CAMPAIGNS` if wanted (not required for W1 single-mail).
3. Set Yandex Disk WebDAV app password on server `.env`; `php artisan backup:run` once; confirm `yandex_disk` healthy in `backup:list`.
4. Optional: fill GitHub Environment `production` deploy secrets so `deploy.yml` is not a success-no-op (#828); auto-deploy cron already covers DEP2.
5. Optional ops-safe: one real ₽500 Tochka payment on merch.tochka.com and confirm `payment.success` + `paid_at` on enrollment.

---

_Dr. Mārcis Gasūns_
