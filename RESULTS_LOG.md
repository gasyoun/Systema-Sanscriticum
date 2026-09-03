# Results log

_Created: 30-07-2026 · Last updated: 03-09-2026_

Durable substantive-result tables for this repo. Newest first.

## Release coverage census — all 279 CHANGELOG versions tagged and released (03-09-2026)

_Model: Opus 5 (`claude-opus-5`)._ H3692's close-out ([PR #2207](https://github.com/gasyoun/Systema-Sanscriticum/pull/2207), `84031c39`, 30-08-2026) landed the `[1.90.35]` CHANGELOG section, the RESULTS_LOG table below and the `.ai_state` stamp, but never cut the tag or the GitHub release — so both docs linked to a 404 for four days. Tag `v1.90.35` (annotated) now points at `84031c39`, the commit carrying its own CHANGELOG section, and the release is published with `--latest=false` so `v1.90.51` stays `Latest`: [v1.90.35](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.90.35).

The same census over `v1.90.35`–`v1.90.51` shows this was not a one-off. Every version in the band has a `## [1.90.x]` CHANGELOG section; **3 have no git tag at all** and **14 have no GitHub release**. Only 1.90.35 (fixed here), 1.90.50 and 1.90.51 are complete.

| Version | CHANGELOG section | git tag | GitHub release |
|---|---|---|---|
| 1.90.35 | ✅ | ✅ (retro-tagged 03-09-2026) | ✅ (published 03-09-2026) |
| 1.90.36 | ✅ | ✅ | ✅ |
| 1.90.37 | ✅ | ✅ | ✅ |
| 1.90.38 | ✅ | ✅ | ✅ |
| 1.90.39 | ✅ | ✅ | ✅ |
| 1.90.40 | ✅ | ✅ | ✅ |
| 1.90.41 | ✅ | ✅ | ✅ |
| 1.90.42 | ✅ | ✅ | ✅ |
| 1.90.43 | ✅ | ✅ | ✅ |
| 1.90.44 | ✅ | ✅ | ✅ |
| 1.90.45 | ✅ | ✅ | ✅ |
| 1.90.46 | ✅ | ✅ | ✅ |
| 1.90.47 | ✅ | ✅ | ✅ |
| 1.90.48 | ✅ | ✅ | ✅ |
| 1.90.49 | ✅ | ✅ | ✅ |
| 1.90.50 | ✅ | ✅ | ✅ |
| 1.90.51 | ✅ | ✅ | ✅ |

**Why it stays invisible.** A CHANGELOG heading is written by the release commit, so a session that stops after the commit leaves a version that looks shipped in every document that matters and exists nowhere in `git tag` or the releases page. Nothing reads back the other direction: no gate compares `## [x.y.z]` headings against `git ls-remote --tags`, so the drift only surfaces when a human clicks a release link written by an earlier pass. Matching known trap: a `cut_release` tag is lightweight, so `git push --follow-tags` skips it and the tag never leaves the machine even when that step did run.

**Repair recipe (per version).** Tag the commit that carries the version's own CHANGELOG section (`git log --oneline --grep="<version>"` finds it), annotate it, push `refs/tags/<tag>` explicitly — never via `--follow-tags` — confirm with `git ls-remote --tags origin`, then `gh release create <tag> --verify-tag --notes-file <section> --latest=false`. The `--latest=false` is not optional: a retro-published historic release otherwise demotes the real newest one.

**Backfilled 03-09-2026, same session.** All 14 versions now carry a tag and a release. Tags `v1.90.39`, `v1.90.42` and `v1.90.44` were created at the `chore(release):` commit that introduced each version's own CHANGELOG section (ancestry checked against both neighbouring tags before pushing) and pushed as explicit `refs/tags/` refs; all 14 releases were published from their CHANGELOG section with `--latest=false`, and `v1.90.51` still holds `Latest`.

**The band was not the whole of it, and the rest was closed the same day.** A full audit over all 279 CHANGELOG versions, run after the first backfill, found 4 more versions with no git tag (`v1.90.30`, `v1.90.29`, `v1.90.25`, `v1.90.19`) and 6 with no release (those four plus `v1.90.21` and `v1.0.0` — the repo's very first version). All ten were repaired in the same session by the same recipe.

**Final state: every one of the 279 CHANGELOG versions has a tag and a release**, verified by the shipped gate over the whole history (`--since 1.0.0 --check`, exit 0, `missing git tag: 0 · missing release: 0`), with `v1.90.51` still holding `Latest`. Twenty releases were published and eleven tags created across the session. Where a version's CHANGELOG section exceeded the release-notes size limit its bullets were condensed and the notes say so — the CHANGELOG remains the full record.

**A parser warning worth more than the count.** A first pass at this audit reported *128* missing releases — wrong by a factor of twenty. It matched `gh release list`'s **name** column against `v<version>`, and this repo's release names routinely differ from their tags (`1.90.32` without the `v`, `v1.90.22 — аптайм волна 4 подготовлена…` with a title appended). Release identity is the **tag**, never the display name; an audit keyed on the name invents a backlog that does not exist. The shipped gate reads the tag column.

**The gate that would have caught it** now ships as [`scripts/changelog_release_coverage.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/changelog_release_coverage.py): it reads CHANGELOG headings, `git tag -l` and `gh release list`, and reports or (`--check`) fails on any version missing either. Its floor is now `1.0.0` — with the backlog closed there is nothing left to except, so the default window is the entire file — and it says «UNKNOWN — gh unavailable» rather than a false all-clear when `gh` cannot answer (that degradation fired twice for real during this session's flaky network). It is **not wired into CI** — deliberately left, since the release job would need a `gh` token with release-read scope.


## H3692 guest /register attribution fields (30-08-2026)

_Model: Grok 4.6 (`grok-4.6`)._ PR: [#2206](https://github.com/gasyoun/Systema-Sanscriticum/pull/2206) (`d8635007`). Release: [v1.90.35](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.90.35). Handoff: [H3692 (Grok 4.6, 🟡2 medium) — Guest `/register` collects signup_source and birth_year](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3692-Grok_Systema-Sanscriticum_guest-register-attribution-fields_29.08.26.md). Reclaimed stale Sonnet 5 sidecar (>6h). Deploy `.92`: `sudo bash deploy.sh` `4145f1bd → d8635007`. Tests: 10/10 `GuestRegisterTest` (46 assertions). No new flag, no new columns. Prod enable remains a separate `.env` + `config:cache` step.

| Gate | Result |
|---|---|
| Flag default | `features.guest_registration` false (`GUEST_REGISTRATION_ENABLED`) |
| ON GET form | shows `signup_source` + `birth_year` |
| ON POST telegram + 1990 | both persist |
| ON POST birth_year=1800 | user created, year null (non-blocking) |
| ON POST UTM session | `utm_source` copied via `applyToNewUser` |
| Flag OFF GET/POST (tests) | 404, zero users |
| Prod SHA | `d8635007` |
| Prod env | `GUEST_REGISTRATION_ENABLED=ABSENT` (OFF) |
| Live GET `/register` | 404 |
| Homepage smoke | `https://samskrte.ru/` 200 |

## H3693 referral loyalty CTA flag OFF (29-08-2026)

_Model: Grok 4.6 (`grok-4.6`)._ PR: [#2201](https://github.com/gasyoun/Systema-Sanscriticum/pull/2201) (`57b1bd71`). Release: [v1.90.34](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.90.34). Handoff: [H3693 (Grok 4.6, 🟡2 medium) — Referral CTA at homework/certificate/course-complete flag OFF](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3693-Grok_Systema-Sanscriticum_referral-loyalty-cta-flag-off_29.08.26.md). Took over a live Sonnet 5 sidecar (human authorized). Deploy `.92`: `sudo bash deploy.sh` `4120a527 → 57b1bd71`. Tests: 10/47 `ReferralLoyaltyCtaTest` + `ReferralAskSurfacesTest` green. Partial reused verbatim (H1294). Public `/verify` not touched. `partner.enabled` stays OFF.

| Gate | Result |
|---|---|
| Flag default | `features.referral_loyalty_cta` false (`REFERRAL_LOYALTY_CTA`) |
| Homework accepted, flag OFF | no `referral-loyalty-cta-homework`; no «Порекомендовать школу» on lesson page |
| Homework accepted, flag ON | existing partial visible; H1294 voice (no награда/бонус/заработок) |
| Homework submitted, flag ON | inject hidden |
| Dashboard course-complete, flag OFF | no `referral-loyalty-cta-course-complete`; cabinet H1294 include still shown |
| Dashboard course-complete, flag ON | inject shown |
| Incomplete course, flag ON | course-complete inject hidden |
| Certificate list, flag OFF | no `referral-loyalty-cta-certificate` |
| Certificate list, flag ON | inject shown |
| Public `/verify`, flag ON | no invite; `partner.enabled` false |
| Prod SHA | `57b1bd71` |
| Prod env | `REFERRAL_LOYALTY_CTA=ABSENT` (OFF) |
| Homepage smoke | `https://samskrte.ru/` 200 |

Prod enable remains a separate `.env` + `config:cache` step.

## H3650 membership OG stills — live smoke (29-08-2026)

_Model: Grok 4.6 (`grok-4.6`)._ PR: [#2194](https://github.com/gasyoun/Systema-Sanscriticum/pull/2194) (`7689e17c`). Release: [v1.90.33](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.90.33). Handoff: [H3650 (Grok 4.6, 🟡2 medium) — OG stills for 01-09 membership surfaces via Grok Imagine](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3650-Grok_Systema-Sanscriticum_autumn-membership-og-imagine_28.08.26.md). Deploy on `.92`: `sudo bash deploy.sh` `80fa6c09 → 7689e17c`. Tests: 22/22 (`MembershipOgImageTest` + Club landing + commerce feed + homepage meta). Duplicate PR [#2195](https://github.com/gasyoun/Systema-Sanscriticum/pull/2195) closed. Imagine backgrounds + exact overlay of house `logo.png` and existing page titles; autumn 1:1 Imagine pass invented letter-forms, so the square was cropped from the clean landscape.

| Surface | URL | Result |
|---|---|---|
| Club OG (primary `/klub` `og:image`) | https://samskrte.ru/images/og-membership-club.webp | PASS `image/webp` 1200×630, 18308 B |
| Basic OG (second `/klub` `og:image`) | https://samskrte.ru/images/og-membership-basic.webp | PASS `image/webp` 1200×630, 17902 B |
| Autumn calendar OG | https://samskrte.ru/images/og-membership-autumn.webp | PASS `image/webp` 1200×630, 40052 B |
| Club 1:1 | https://samskrte.ru/images/og-membership-club-1x1.webp | committed + deployed (1200×1200) |
| Basic 1:1 | https://samskrte.ru/images/og-membership-basic-1x1.webp | committed + deployed (1200×1200) |
| Autumn 1:1 | https://samskrte.ru/images/og-membership-autumn-1x1.webp | committed + deployed (1200×1200) |
| Live `/klub` HTML | https://samskrte.ru/klub | 200; head names club + basic paths, width 1200, height 630 |
| Live `/osen-2026` HTML | https://samskrte.ru/osen-2026 | 404 — `membership_public_feed` flag OFF; image URL itself is live |
| Home preview | https://samskrte.ru/ | still `og-main-preview.jpg`; no membership OG path |

## H3281 student manuals vs catalog + guest HTTP (28-08-2026)

_Model: Grok 4.6 (`grok-4.6`)._ Full prose: [docs/CENSUS_STUDENT_LOGGED_IN_MANUALS_22-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CENSUS_STUDENT_LOGGED_IN_MANUALS_22-08-2026.md). Probe 20:43 UTC, GET, no cookies, max-redirs 0. Prod catalog ids via artisan on `.92`.

| Book | URL | Guest HTTP | Intended login-required | Catalog row id |
|---|---|---|---|---|
| Как пользоваться кабинетом | https://samskrte.ru/dvaram/help | 302 → /login | yes | 1 student |
| Как сдавать домашнее задание | https://samskrte.ru/faq/dz | 200 | no | 5 homework |
| Почему баланс праны уменьшился | https://samskrte.ru/help/prana-balance | 302 → /login | yes | 6 prana |
| Онбординг (checklist) | https://samskrte.ru/dvaram | 302 → /login | yes | none |
| Каталог документации | https://samskrte.ru/admin/documentation | 302 → /admin/login | yes (admin) | n/a |


## H3380 trial live — rusamskrtam second session + auto-reply activation (23-08-2026)

_Model: OxAlpha (`opencode/x-preview-f-free`)._ PRs: [#2011](https://github.com/gasyoun/Systema-Sanscriticum/pull/2011) (infra + v1) · [#2021](https://github.com/gasyoun/Systema-Sanscriticum/pull/2021) (small-talk v2). Handoff: [H3380](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3380-OxAlpha_Systema-Sanscriticum_rusamskrtam-second-session-autoreply-trial_23.08.26.md).

| Step | Result |
|---|---|
| MG interactive login | `telegram-support:login --account=rusamskrtam` — session written, row id=3 enabled |
| Gotcha 1 | session dir created root-owned by the login shell → sync as www-data got `Permission denied`; fixed `chown 33:33`. Runbook rule: future logins via `sudo -u www-data php artisan telegram-support:login ...` |
| Gotcha 2 | `config:cache` freezes env — per-process env override of the sync ceiling does NOT work; temporary `TELEGRAM_SUPPORT_SYNC_TIMEOUT_SECONDS=600` appended to prod .env for first-pass backlog (per-session locks make this safe; revisit at week-3 review) |
| First pass | watchdog killed at 120 s on heavy group history (`phase=history:-1003671345641`); after chown+600 s ceiling: `ok`, cursors set, incremental runs light |
| Live | schedule ticks every 5 min autonomously (15:06, no errors); support lane untouched (per-session lock/phase/cooldown keys) |
| Flags ON | `SUPPORT_DM_AUTO_REPLY` · `SUPPORT_AUTO_REPLY_TEMPLATES` · `SUPPORT_AUTO_ACK`; gate `auto_reply_enabled=1` on rusamskrtam only; registry updated |
| Smoke lesson | «Намо намах!» → ack boilerplate. v2 (#2021): pure greeting → warm reply once per window (`kind=greeting`), pure thanks → silent skip, greeting+question → normal pipeline |
| First real `dm_auto_sent` (measured 30-08-2026, OxAlpha `opencode/z-ai/glm-5.3-flash`) | **Fired 27-08-2026** — events 1222 / 1229 / 1248 in `support_ai_reply_events`, all `kind=ack`, `via=support_dm_auto_reply`, account `support(2)` (the main lane, per the [#2045](https://github.com/gasyoun/Systema-Sanscriticum/pull/2045) pivot). Three **different** conversations (245/246/247), one ack each — the «more than one ack per series» fail condition is not triggered, and no LLM text reached an outgoing message. |
| `kind=template` over the whole trial | **Never fired — and not a bug in the template branch.** All eight `dm_hinted` events since activation carry `category=null`: the intent classifier assigned no D/E/F category, so the canned replies had no eligible input. The W3 verdict therefore cannot be «do templates work» — the first question is why the classifier is silent. |
| Prod drift re-check 30-08-2026 | None. `features.support_dm_auto_reply` / `support_auto_reply_templates` / `support_auto_ack` = true/true/true; rows `harvester(1) 0/0` · `support(2) 1/1` · `rusamskrtam(3) 0/0` (the 26-08 fix held, auto-heal did not resurrect the parked row); `telegram-support:healthcheck --dry` green. |

## S9 template drafts — activation on H2339 census texts + first measurement (23-08-2026)

_Model: OxAlpha (`opencode/x-preview-f-free`)._ Full prose: [docs/S9_TEMPLATE_DRAFTS_ACTIVATION_2026-08-23.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/S9_TEMPLATE_DRAFTS_ACTIVATION_2026-08-23.md).

| Fact | Value |
|---|---|
| H2339 seeder on prod | had **never run** — executed 23-08: 12 canreplies (id 12–26) + 4 dozhim stubs created, idempotent |
| Flag `SUPPORT_TEMPLATE_DRAFTS` | already true since 30-07-2026 (session instruction); untouched |
| Bindings after pass | one per category: D→D2 «куда оплатить» · E→E1 «forgot-password» · F→F2 «сдать ДЗ»; old generic blanks unbound; E2 never auto-bound (`{login_link}` is curator-manual) |
| `answer_template_drafted` | 10 all-time (9 = 30-07 activation burst, 1 real 03-08 cat=F) |
| `answer_llm_drafted` | **0 ever** — template path fully replaced LLM on D/E/F; no LLM denominator → A/B not computable yet |
| Template draft outcomes | accepted 0 · edited 0 · discarded 2 |
| Suggestion mix by facts.type | recording 27 · zoom 16 · template 10 · schedule 2 · untyped 17 |
| Audit trail | all binding changes in `message_template_audits` id 24–33 as «Система» |

Next proof: re-query drafted/outcome counters in ~2–4 weeks; volume ~1 draft/week means acceptability verdicts need weeks, not days. Known gaps: `{course}` renders empty in suggester drafts; manual canreply sends are uninstrumented; 456 uncategorized topics dilute the next census.

## Student manuals behind login (22-08-2026)

_Model: Grok 4.6 (`grok-4.6`)._ Full prose: [docs/CENSUS_STUDENT_LOGGED_IN_MANUALS_22-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CENSUS_STUDENT_LOGGED_IN_MANUALS_22-08-2026.md).

| Kind | Title | URL | Login? |
|---|---|---|---|
| Product book | Как пользоваться кабинетом | [https://samskrte.ru/dvaram/help](https://samskrte.ru/dvaram/help) | yes |
| Short help | Почему баланс праны уменьшился | [https://samskrte.ru/help/prana-balance](https://samskrte.ru/help/prana-balance) | yes |
| Checklist | Онбординг | [https://samskrte.ru/dvaram](https://samskrte.ru/dvaram) | yes |
| Public FAQ | Как сдавать ДЗ | [https://samskrte.ru/faq/dz](https://samskrte.ru/faq/dz) | no |
| Staff map | student-manual.md | [blob](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) | n/a |
| Games HTML | lila-games-manual.html | [blob](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/lila-games-manual.html) | n/a |

## H2988 — telegram-support:sync watchdog timeout cluster (17-08-2026)

_Model: Grok 4.6 (`grok-4.6`)._ Baseline window: prod `laravel-2026-08-16.log` + `laravel-2026-08-17.log` (app tz Europe/Moscow). Query: `Telegram support sync timed out`.

| Metric | Value |
|---|---|
| Timeouts 16-08 | 10 (23:25, 23:28, 23:31, 23:34, 23:37, 23:40, 23:43, 23:46, 23:50, 23:54) |
| Timeouts 17-08 | 8 (00:01, 00:07, 00:15, 00:25, 00:33, 00:36, 00:43, 01:04) |
| 24h count (to 17-08 ~08:40) | **18** |
| After 01:04 same day | 0 (healthy 11–41 s, typical 36–37 s) |
| `session_busy` / dead IPC / AUTH_RESTART / EMFILE | 0 / 0 / 0 / 0 |
| First kill | `killed_processes=2`, removed `ipcState.php` |
| Later kills | `killed_processes=1`, no files removed |
| Root class | DC stall (same class as H1915, now correctly killed) + immediate cold-start after `cleanUpAfterTimeout` daemon reap |
| File:line | [`MadelineSyncWatchdog.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Telegram/MadelineSyncWatchdog.php) `exit(75)`; [`SyncTelegramSupport.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SyncTelegramSupport.php) `killDaemons()`; hang is `API::start()` or `messages->getHistory()` in [`TelegramSupportSyncService.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/TelegramSupportSyncService.php) |
| Not this | session lock wait · reply-drain · roster collision · raised ceiling · `runInBackground()` |
| Fix | 600 s post-timeout cooldown; phase breadcrumb on the timeout log |
| PR / SHA | [PR #1795](https://github.com/gasyoun/Systema-Sanscriticum/pull/1795) `91469e72` |
| Deploy | PASS 17-08-2026: `7af6a6bc → 91469e72`; homepage 200; `cabinet:probe` 1699 ms OK; `guards:verify` OK |
| Live config | `php artisan config:show services.telegram_support.sync_timeout_cooldown_seconds` = **600** |
| Release | [v1.89.65](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.89.65) |
| Tests | `MadelineSyncGuardsTest` 9 passed, 1 skipped (pcntl on Windows); `EXIT_TIMED_OUT = 75` asserted |

Reproduce count: `grep -c 'Telegram support sync timed out' /var/www/html/storage/logs/laravel-YYYY-MM-DD.log`. Tests: `php artisan test --filter=MadelineSync`. Next proof: same grep tomorrow vs the 18-hit baseline.


## H2482 — native VisualDCS Wave L (13-08-2026)

_Model: Grok 4.6 (`grok-4.6`)._ Three surfaces, importer, entitlement matrix, progress.

| Gate | Result |
|---|---|
| Flags default OFF | verb / nominal / passage all `false` |
| Import complete fixture | promotes `vdcs-fixture-complete-20260813` |
| Reimport same release | no-op |
| Corrupt SHA-256 | rejected; previous release stays promoted |
| Rollback | restores prior manifest |
| Public preview | full-tier only (`ah`), no attested (`abhibhañj`) |
| Unpaid / expired / deposit | preview only |
| Paid-full / partial / admin | attested visible |
| Progress upsert | one row, attempts increment, second device resumes |
| Independent rollback | one flag 404s only that surface |

Reproduce: `php artisan test --filter=VisualDcs` after `visualdcs:import tests/fixtures/visualdcs/complete`.


## H1946 — Synthetic prod user 6858 (H1939 pay probe) (30-07-2026)

_Model: Grok 4.5 (`grok-4.5`)._ Ops false-alarm: Telegram onboarding chat
(`@testpodpiska12_bot` → first-login ping) fired for a non-student.

| Field | Value |
|---|---|
| User id | **6858** |
| Admin card | https://samskrte.ru/admin/users/6858/edit |
| Name | `H1939 Pay 20260730150332` |
| Email | `h1939.pay.20260730150332@example.invalid` |
| Kind | **Synthetic / live pay probe** — not a real student |
| Origin | H1939 marathon/wave1 live smoke; prod probe script reused `User::find(6858)` for Tochka link-create (no real student mailbox) |
| First-login ping | 30.07.2026 15:03 — legitimate `OnboardingNotifier::firstLogin`, not spam |

**Convention for future probes:** name `H### …` + email under
`@example.invalid` (RFC reserved; **not** `@example.com` — Faker uses that).
First-login pings for those domains are still sent (so a probe login is visible)
but prefixed with `🧪 SYNTHETIC / TEST`. Weekly onboarding digest excludes
`@no-email.com` and `@example.invalid`.

**Human residual (card note):** paste into the student `note` field on
https://samskrte.ru/admin/users/6858/edit so the admin card itself is labeled:

> SYNTHETIC / TEST — H1939 live pay probe (not a student). Email @example.invalid. Do not contact. Keep for payment smoke only.

_Dr. Mārcis Gasūns_

## H1917 — Scheduler-hang chaos drill (30-07-2026)

_Model: Sonnet 5 (`claude-sonnet-5`)._ Live drill on production
(`root@193.232.229.92`), authorized after notifying the server owner
(Артём, `@t3t3r1n`). Full scenario: [H1917](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1917-Sonnet_Systema-Sanscriticum_scheduler-hang-chaos-drill_29.07.26.md).
Parent: [H1904](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1904-Opus_Systema-Sanscriticum_server-oom-scheduler-pileup-guards_29.07.26.md),
[docs/server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md).

**Method:** a temporary `debug:hang {--seconds}` artisan command + a temporary
wrapper copy (same lock file, timeout, reaper logic as
[`systema-schedule-run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/systema-schedule-run.sh))
launched manually to contend for the real scheduler lock, so the drill exercises
the actual production guard path rather than a simulation. Both removed after
the drill; nothing in the guards themselves was changed (out of scope, see
[H1914](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1914-Opus_Systema-Sanscriticum_codify-server-guards-in-repo-drift-verify_29.07.26.md)).

### Run 1 — capped (`SYSTEMA_SCHEDULE_MAX_SECONDS=900`, default)

Started 08:28:47Z, minute-by-minute `avail`/`procs`/`php` from `/var/log/memwatch.log`, HTTP from a 60s-interval probe of `https://samskrte.ru/`.

| Minute (UTC) | avail (MB) | procs | php | `schedule_run` marker | HTTP |
|---|---|---|---|---|---|
| 08:29 | 14711 | 74 | 23 | 0 | 200 |
| 08:30–08:39 | 14605–14720 | 71–83 | 22–25 | 0 (SKIP every minute) | 200 |
| 08:40–08:43 | 14615–14624 | 75–77 | 23–24 | 0 | 200 |
| 08:44 (TIMEOUT fires 08:44:42Z) | 14615 | 77 | 24 | 0 | 200 |
| 08:45–08:48 | 14667–14678 | 75–83 | 23–25 | 0 | 200 |

- **Killed within cap:** ✅ `2026-07-30T08:44:42Z TIMEOUT: debug:hang exceeded 900s (rc=124) — reaping its children` — ~896s after the hang grabbed the lock.
- **No process pile-up:** ✅ one `SKIP: previous schedule:run still holds the lock` line every single minute 08:30–08:43Z; `php` count stayed in a 22–25 band the whole window (no monotonic growth).
- **Site never dropped:** ✅ HTTP 200 on every one of the 20 probes across the run.
- **`cabinet:probe` ran during the hang:** ✅ — but via the *separate* watchdog cron line (`systema-watchdog-run.sh "cabinet:probe" cabinet 120`, syslog-confirmed invocation at 08:30:01Z, independent lock). **Not** via the in-Kernel `$schedule->command('cabinet:probe')` entry — that copy's own 15-minute slot (11:30 local) is missing from `schedule.log` for this window, confirming it goes silent exactly when the scheduler is stuck. See "Follow-up" below.

### Run 2 — uncapped (`SYSTEMA_SCHEDULE_MAX_SECONDS=99999`)

Started 08:53:42Z, manually killed 08:56:12Z (~2.5 min — long enough to confirm no premature/false-positive kill and no lock contention regression; further duration adds nothing since the synthetic hang is pure `sleep` and does not grow memory).

- **No wrapper timeout fired** (expected — cap disabled): ✅ `SKIP:` lines continued every minute (08:54Z, 08:55Z) with no `TIMEOUT:` until the manual `pkill -TERM` at 08:56:12Z (logged as `rc=143`, correctly reaped).
- **avail/php stayed flat:** avail 14605→14614 MB, php 23–25 — no growth (expected: a pure-`sleep` hang doesn't consume memory, so this run cannot exercise `MemoryMax`/`earlyoom` as a genuine second-tier catch — see limitation below).
- **Site stayed up:** ✅ HTTP 200 throughout.
- **Second-tier defenses — verified by configuration, not triggered live:** `cron.service` has `MemoryAccounting=yes` / `MemoryMax=3221225472` (3 GiB cgroup cap); `earlyoom` is configured `-m 10,5` (SIGTERM at 10% avail / SIGKILL at 5%), avoiding core daemons and preferring to kill `php*`. **Limitation:** a synthetic hang that only sleeps cannot push `avail` down, so this drill did not — and safely could not, without deliberately spiking real memory on prod — prove these fire in anger. Flagged as an open gap for a follow-up drill under tighter supervision (e.g. a staging replica) rather than exercised here.

### After-state (09:00Z, ~4 min after cleanup)

`free -m`: avail 14535/16384 MB (89%, matches baseline); `pgrep -fc artisan`=20, `pgrep -fc php`=29 (both within the pre-drill 16–24 / 22–29 bands); `php artisan list debug` no longer shows `debug:hang`; `https://samskrte.ru/` → 200.

### Follow-up shipped in the same PR

`Kernel.php`'s in-process `cabinet:probe` schedule entry (added before the
watchdog line existed) is dead weight that this drill proved goes silent
exactly during the failure mode it's meant to catch — removed, with
[`tests/Feature/CabinetProbeTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CabinetProbeTest.php)'s
`test_registered_in_the_schedule_with_config_cron` replaced by
`test_not_registered_in_the_in_process_schedule` to lock in the new intent.
The real guard remains the independent `systema-watchdog-run.sh` cron line
(unchanged, out of scope for this handoff).

_Dr. Mārcis Gasūns_
