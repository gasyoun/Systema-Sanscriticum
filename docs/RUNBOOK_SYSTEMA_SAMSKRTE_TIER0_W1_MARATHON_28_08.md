# Marathon 28-08 — LIVE runbook + evidence log

_Created: 30-07-2026 · Last updated: 16-08-2026_

> **Пересверено на проде 16-08-2026 ([H2865](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2865-Opus_Systema-Sanscriticum_28aug-integrated-launch-gate_16.08.26.md), Opus 5).
> Вердикт `BLOCKED` ниже — снимок 30-07 и УСТАРЕЛ по трём строкам: D (Telegram) теперь
> PASS с живыми доказательствами, «бот-админ канала» закрыт, канал вебхуков доказан.
> Открытым остаётся E (почта).** Актуальный интегрированный вердикт по марафону И
> членству — [docs/LAUNCH_GATE_28_08_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LAUNCH_GATE_28_08_2026.md).
> Журнал доказательств ниже — append-only, поэтому старые строки не переписаны.

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
16-08-2026 09:06 MSK · Opus 5 (claude-opus-5) · H2865 re-verification start · read-only prod probes only · no money/access flag touched
16-08-2026 08:31 MSK · Opus 5 (claude-opus-5) · DEP1 · PASS · prod HEAD 4d0f12f2eedfbdfacdc7c8b09d85ee64095e91f1 == origin/main (v1.89.53) · APP_ENV=production · migrate:status pending=0
16-08-2026 08:40 MSK · Opus 5 (claude-opus-5) · Smoke D token · PASS (was FAIL 30-07) · MarketingSetting tg_bot_token is now a real BotFather token · getMe ok id=8722284265 username=samskrte_bot · the 25-char placeholder is GONE
16-08-2026 08:42 MSK · Opus 5 (claude-opus-5) · Webhook channel · PASS · getWebhookInfo → https://103.112.71.201/api/webhooks/telegram-magnet pending_update_count=0 · entry node is the DOCUMENTED one (docs/telegram-userbot-inventory.md §4.3), not a stale host
16-08-2026 08:44 MSK · Opus 5 (claude-opus-5) · Tunnel liveness · PASS · tg-reverse.service active+enabled since 05-08 · GET через входной узел → HTTP 405 (Laravel method-not-allowed на POST-роуте) за 0.29s = апдейты доходят до приложения · CAVEAT NRestarts=593 (флапает)
16-08-2026 08:48 MSK · Opus 5 (claude-opus-5) · Drip live proof · PASS · laravel-2026-08-1*.log: warm-tail Day 8/9/10/11/12/13 sent, по одному в день 10–15.08, enrollment #5 · движок реально шлёт в Telegram
16-08-2026 08:49 MSK · Opus 5 (claude-opus-5) · Drip reliability · CAVEAT · 2 падения scheduled-command за 7 дней (14.08 warm-tail, 15.08 deliver-due), причина cURL 28 timeout к api.telegram.org · 15-мин ретрай добрал (Day 12 ушёл в 00:15 после падения в 00:00)
16-08-2026 08:52 MSK · Opus 5 (claude-opus-5) · Scheduler · PASS · cron живёт в crontab **www-data** (`* * * * * /usr/local/sbin/systema-schedule-run.sh`), НЕ в root — root-крон держит только авто-деплой · schedule.log пишется сейчас
16-08-2026 08:55 MSK · Opus 5 (claude-opus-5) · Channel admin · PASS (был OPEN 30-07) · getChatMember(@samskrte, bot) status=administrator can_post_messages=true · пост №1 ушёл 04-08 (marathon_channel_posts_sent run_key=once) · пост №2 в кроне на 28-08 10:00 MSK
16-08-2026 08:58 MSK · Opus 5 (claude-opus-5) · Smoke E · FAIL (без изменений с 30-07) · failed_jobs: 20 отказов 554 5.7.1 «Message rejected under suspicion of SPAM», последний 06-08-2026 12:27 · в текущем окне ещё и «Expected response code 250 but got empty code»
16-08-2026 09:00 MSK · Opus 5 (claude-opus-5) · Membership prereq · PARTIAL · membership:rehearse: курс #444 «Клуб» PASS, 3 тарифа со сроком PASS, клубная группа PASS, **полка записей FAIL (0 курсов club_included)**, флаг WARN (OFF, ожидаемо)
16-08-2026 09:02 MSK · Opus 5 (claude-opus-5) · Shelf root cause · NO-GO · `membership:club-catalogue` (сухой прогон) → «подходящих курсов 0» · Course::sellsRecordings() требует features.course_recordings_sales (OFF) И is_completed=true (0 из 100 активных) · голый --apply в DEPLOY_QUEUE был бы no-op
16-08-2026 09:04 MSK · Opus 5 (claude-opus-5) · HTTP smoke · PASS · /online/konsultaciya 200 · /konsultaciya-po-onlayn-kursam 200 · /online 200 · / 200 · /klub 404 (флаг OFF — корректное предпусковое состояние)
16-08-2026 09:05 MSK · Opus 5 (claude-opus-5) · Money/access flags · UNCHANGED · club_membership=false membership_cancellation=false membership_free_tier=false · club_memberships=0 · free_tier_grants=0 · ни один флаг не тронут
```

## PARK / residual secrets

| Item | Needed for | Status |
|---|---|---|
| Yandex WebDAV app password (`YANDEX_DISK_LOGIN` + `YANDEX_DISK_APP_PASSWORD` on **server** `.env`) | DR1 off-site | **PARK** — local backup OK; WebDAV Unauthorized |
| Real BotFather token for `@samskrte_bot` in Filament Marketing Settings (`tg_bot_token`) | D (Day 1 drip) + channel posts admin | **✅ CLOSED 16-08-2026 (H2865)** — real token installed, `getMe` ok, drip proven sending (warm-tail Days 8–13) |
| SPF/DKIM/DMARC + clean Yandex sender reputation for `rusamskrtam@yandex.ru` (or chosen mailbox) | E transactional mail | **OPEN** — SMTP configured but 554 spam; tracks [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504) / H1449 |
| GitHub Actions `TELEGRAM_BOT_TOKEN` + `TELEGRAM_CHAT_ID` | DR2 TG dry-fire | **PARK** — uptime HTTP job already green without them |
| GitHub Environment `production` secrets `DEPLOY_HOST`/`DEPLOY_USER`/`DEPLOY_SSH_KEY` + required reviewers | GHA deploy.yml truth (#828) | **OPEN @DO human** — agent path is auto-deploy cron (DEP2 PASS without these) |
| Tochka live test policy (complete ₽500 with real card) | optional full C success-callback | **NOT RUN** — hosted page reached; charge left for ops-safe window |
| Make magnet bot admin of `@samskrte` channel | H1936 channel posts | **✅ CLOSED 16-08-2026 (H2865)** — `getChatMember` status=administrator, `can_post_messages=true`; post #1 sent 04-08 |
| Club shelf `courses.club_included` (which recordings the ₽1 500 club covers) | Membership launch 28-08 | **OPEN @DO human — NEW, found 16-08-2026 (H2865)** — 0 courses on the shelf; the documented `--apply` is a no-op, needs an explicit `--course=` list |

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
