# Soft server alerts — agent playbook + cause catalog

_Created: 02-08-2026 · Last updated: 21-08-2026 (H3227 cgroup MiB fingerprint)_

**Audience:** agents (Grok / Claude / Codex) and ops.  
**Scope:** Telegram soft path from `cabinet:probe` («Кабинет: soft-сбой …»),  
`storage/auto_deploy.disabled`, tracked dirty on prod, and related guard warnings.  
**Not in scope:** critical cabinet outage (HTTP/Auth/Filament red) — that is a  
different TG title and severity.

**Teacher forward:** **NO-GO** while soft dominates. Prod census 06-08-2026  
(~5d of `cabinet_probe_runs`): **0 critical / 107 soft-only** —  
[CENSUS_CABINET_PROBE_SOFT_VS_CRITICAL_2026-08-06.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CENSUS_CABINET_PROBE_SOFT_VS_CRITICAL_2026-08-06.md).

Canonical long form for OOM/cron history:  
[server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md).  
Deploy dirty-gate:  
[deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md).  
Handoff that shipped this playbook + safe remediate + webhook skeleton:  
[H2148](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2148-Grok_Systema-Sanscriticum_soft-alert-auto-remediate-abc_02.08.26.md).

---

## 1. One-line rule

| Signal | Meaning | Panic? |
|---|---|---|
| Soft TG: `Кабинет: soft-сбой (guards)` | Guard / auto-deploy warning; site often still 200 | **No** |
| Soft TG: other scopes (`hybrid`, …) | Optional surfaces failed | **No** |
| Critical TG: `Личный кабинет не работает` | Auth/smoke paths red | **Yes** — host/app triage |
| Better Stack / external monitor red | External pulse failed | **Yes** if HTTP down |

Soft ≠ outage. Do not escalate soft auto-deploy fuse to Artem / host-down runbooks  
unless smoke/health is also red.

---

## 2. How the alert is produced

```
root cron */30  →  systema-auto-deploy-run.sh  →  deploy.sh / health / breaker file
www-data */15   →  cabinet:probe  →  guards:verify  →  soft or critical TG
```

- Soft chat: `CABINET_PROBE_TELEGRAM_SOFT_CHAT_ID` (often `@rusamskrtam` via bot  
  `TELEGRAM_BOT_TOKEN` — historically `@testpodpiska12_bot`).
- Critical chat: `CABINET_PROBE_TELEGRAM_CHAT_ID` / `ADMIN_TELEGRAM_ID`.
- Soft anti-spam (H2335 / H3227): **normalized fingerprint** (fuse TS/SHA → tag class;
  host guards `guards/<name>:` collapse to the **name**, not live RSS/MiB/age, via
  [`SoftFailureFingerprint`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ServerGuards/SoftFailureFingerprint.php))
  + **sticky** same class until green; optional re-nudge
  (`CABINET_PROBE_TELEGRAM_SOFT_REMINDER_HOURS`, default **24**; `0` = once until green).
  New soft class fires immediately. `--force-alert` bypasses.
  Legacy minute `SOFT_COOLDOWN` no longer gates re-sends (hourly spam while fuse stuck).
  `cabinet:probe --dry` must not POST the soft webhook (H3227).
- Runbook block in the TG body is from `config/cabinet_probe.php` → `runbook`.

Code:

- [`ProbeCabinetHealth`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ProbeCabinetHealth.php)
- [`SoftFailureFingerprint`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ServerGuards/SoftFailureFingerprint.php)
- [`ServerGuardsAuditor::auditAutoDeploy`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ServerGuards/ServerGuardsAuditor.php)
- [`systema-auto-deploy-run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/systema-auto-deploy-run.sh)
- [`deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh) (dirty-gate H2066)

---

## 3. Cause catalog (tags / symptoms)

Use the **tag in the breaker line** or the **guards message** as the primary key.  
Severity is what `guards:verify` / TG already use (H2066 + H2104).

| Tag / pattern | Symptom | Site | Safe auto (ops:soft-remediate) | Never auto | Human / agent next step |
|---|---|---|---|---|---|
| 2026-08-23 ~12:30–12:45+ UTC (ongoing at write time) | **HOST DOWN** — `.92` total loss mid-session | Minutes before: full SSH triage green (crontab/findmnt/logs, probe = tmpfs-cap only, deploys green 12:00:33Z «health чист smoke 200»). Then `ssh` TCP connect → `Permission denied` (WSAEACCES at SYN stage), escalating within minutes to ICMP + 22 + 443 all dead. Confirmed from TWO vantages: local box (Test-NetConnection fail) AND external fetcher (GET https://samskrte.ru transport error) → down globally, not a local lockout. Peer `.91` SSH-reachable throughout → our network fine; same-subnet sibling alive while `.92` black. H3385 fix (drop chat `197649919` from prod `.env`) NOT applied — prod went unreachable before any edit/backup; clean state, nothing half-done. | **Triage:** sos Phase 0 BatchMode-fail lane → host-down = Artem (`@t3t3r1n`) only; no Proxmox access exists agent-side (P1 token still missing, D9 restart lane inert by design). MG handed ready-to-paste Artem TG. No restart attempts possible or made. Watch for Better Stack alerts already firing on their side. | ox-alpha (`opencode/x-preview-f-free`) | class: total host loss mid-session — ssh WSAEACCES then full black; peer-alive check separates our net from theirs |
|
| 2026-08-23 16:00–16:40 UTC (re-diagnosis) | **NOT total loss — public IP black, VM healthy.** Re-probe lane: `.92` ping/TCP/HTTPS fail from MG's Windows box AND external webfetcher, BUT restic push landed a fresh snapshot at `16:01:17Z` via private LAN; `.91` vantage: ping .92 OK (0.2 ms), LAN IP `192.168.200.92` serves HTTP/1.1 200, PUBLIC `193.232.229.92` dead even same-subnet; `.91→.92` ssh root denied (only restic-push authorized) so no local firewall inspection. Verdict class: upstream null-route / provider anti-DDoS / routing block on the public IP while the VM itself is fine. Data estate safe: restic repo on `.91` healthy (80 snapshots, 19G/49G). Artem away a month → restart-lane inert; fix = provider ticket/panel only. MG handed prepared creds template `~/.secrets/env.hosting-panel` + ready RU ticket text. | ox-alpha (opencode/x-preview-f-free) | class: public-IP null-route with healthy VM — peer-LAN probe discriminates it from real host loss | 2026-08-23 15:20–15:35 | host/ops guards sticky: tmpfs-cap only; MG's paste also carried a stale `auto-deploy` row | HTTP 200; php-fpm up 2w5d, nginx up 3d; `/` fs 31%, avail 13 Gi (swap 2.6/8 Gi residual of the 19-08 storm); prod HEAD `6388a3b7`. crontab root **does** contain the `*/30 systema-auto-deploy-run.sh` line — deploys green every 30 min, last 12:00:33Z «health чист (mem 14215MB, smoke 200)»; this morning's transient is resolved, the pasted row was stale. tmpfs-cap unchanged: `/tmp` tmpfs without `size=`, mounted host-side (`uid=100000`, `nr_inodes` only) — Artem P5, not fixable in-container. **New smell:** Telegram admin-notifier 403 storm — chat `197649919` blocked the bot (`Forbidden: bot was blocked by the user`): **1023 rows on 23-08** (9 on 22-08, 70 on 21-08, 0 on 20-08), burst shape ~15 rows per 12 s event fan-out; same chat fails `cabinet:probe tg`; id sits in prod `.env` `ADMIN_TELEGRAM_ID` (line 84) + `CABINET_PROBE_TELEGRAM_CHAT_ID` (line 201) alongside three delivering chats. Zero app-code exceptions today; bare `laravel.log` absent as usual (daily logs only). | **Triage:** no deploy, no Artem page (SSH live, app green; tmpfs-cap remains the standing P5 ask, not an outage page). No restarts («just in case» banned). Residual minted [H3385](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3385-Sonnet_Systema-Sanscriticum_tg-admin-notifier-blocked-chat-403-storm_23.08.26.md) — drop vs unblock `197649919` is MG's ruling before any `.env` edit. | ox-alpha (`opencode/x-preview-f-free`) | class: dead chat_id left in admin-notifier list → unbounded 403 retry log spam |
| `[blocked-preflight]` | `deploy.sh` exit 1; HEAD unmoved; health clean | usually 200 | only if remaining dirty is origin-equal (or none) | `rm` fuse while diverging dirty exists | see §4.1 |
| `[blocked-dirty]` | same class, dirty-explicit label (if present) | 200 | same as preflight | blind checkout of diverging paths | §4.1 |
| `[timeout-alive]` | deploy/rollback 124/137; smoke still 200 | 200 | clear fuse if tree clean + smoke OK | force endless redeploy | check `auto_deploy.log`; npm skip (H2104) |
| `[rolled-back]` | post-health fail; auto-rollback; soft | 200 on old SHA | none (leave fuse until bad commit fixed) | re-enable without fix on main | fix main → then clear fuse |
| breaker **without** soft tag | fuse after hard fail / bad health | maybe down | **none** | clear fuse without health proof | critical path §7 of resource-guards |
| `tracked dirty … <paths>` | working tree dirty (not only `public/docs/*.pdf`) | 200 | discard paths with empty `git diff origin/main -- path` | discard paths with non-empty diff | PR unique hotfix; no `nano config/` on VPS |
| root cron missing `systema-auto-deploy-run.sh` | auto-deploy silently dead | 200 | none | invent cron from memory | `server_guards_apply.sh` + mirror |
| earlyoom / MemAvailable / OOM | resource pressure | maybe down | none | random process kills | [server-resource-guards.md §7](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md) |
| hybrid soft surfaces | `/library` etc. optional | 200 elsewhere | none | “fix” by disabling hybrid blindly | feature flags / hybrid docs |
| `touch(): Utime failed` / root-owned `storage/framework/views` | Filament `/admin` HTTP 500; `cabinet:probe` critical; public `/` and `/login` 200 | /admin 500 | `chown -R www-data:www-data storage/framework/views` | disable probe; `nano` compiled views; restart healthy php-fpm | `deploy.sh` artisan-as-root compiles Blade; php-fpm cannot `touch()`. Chown after optimize **and** after probe, **before** `fail` (H3194: `fail` is `exit 1`) |
| host-only `guards/tmpfs-cap` / `backup-fresh` as SOS | 🚨 «кабинет не работает» while HTTP 200; deploy `--fail-on-critical` trips fuse; TG storm after `optimize:clear` | 200 | do **not** `rm` fuse as the fix; H3197 splits HTTP vs host | treat host guards as cabinet-down; `cache:clear` as the TG store | SOS + Better Stack `/fail` + deploy fail = HTTP/cabinet only; host/ops sticky; TG state file not cache; `deploy.sh --no-alert` |

**Allowed dirty without blocking deploy:** `public/docs/*.pdf` only (legal PDFs).

**Origin-equal dirty:** H2066 `deploy.sh` already `git checkout HEAD -- path` when  
working tree for that path matches `origin/main`. Safe remediate reuses the same rule.

---

## 4. Agent ladders

### 4.0 Prefer the automated safe path (after B ships)

#### Operator smoke (local / CI — no prod side effects)

One-liner residual of H2148 / H2187. Run **before** any VPS apply:

```sh
# Fixture dry-run suite + artisan default dry-run (exit 0 on clean tree)
php artisan test --filter=SoftRemediate && php artisan ops:soft-remediate --dry-run --json
```

Committed fixtures (breaker lines + expected status contracts):  
[`tests/fixtures/soft_remediate/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/fixtures/soft_remediate).  
PHPUnit: `SoftRemediateCommandTest` (H2148) + `SoftRemediateDryRunFixturesTest` (H2187).  
Default without `--apply` is always dry-run — never auto-remediates prod.

#### On the VPS (after dry-run review)

As root or www-data with deploy rights, **never** invent money ops:

```sh
cd /var/www/html
sudo -u www-data php artisan ops:soft-remediate --dry-run
# review JSON / lines
sudo -u www-data php artisan ops:soft-remediate
sudo -u www-data php artisan guards:verify
sudo -u www-data php artisan cabinet:probe
```

Exit codes (contract):

| Code | Meaning |
|---|---|
| 0 | healthy already, or safe actions applied |
| 1 | needs human / diverging dirty / hard breaker — no destructive clear |
| 2 | misconfiguration / tool error |

`--apply-breaker-clear` is **only** honored when the command itself proves:  
soft-tagged breaker **and** no blocking diverging dirty **and** smoke optional OK.  
Dry-run with `--apply-breaker-clear` still **does not** unlink the fuse (H2187 fixture).

### 4.1 Manual ladder — blocked-preflight + tracked dirty

```sh
ssh -o BatchMode=yes -o ConnectTimeout=10 root@193.232.229.92
cd /var/www/html
cat storage/auto_deploy.disabled
git status --porcelain --untracked-files=no
git fetch origin
# for each dirty path:
git diff origin/main -- <path>
```

| `git diff origin/main -- path` | Action |
|---|---|
| empty | safe: leave for `deploy.sh` auto-discard **or** `git checkout HEAD -- path`; then `rm -v storage/auto_deploy.disabled`; `bash deploy.sh` |
| non-empty | **stop** — unique prod hotfix. Copy diff → PR → main. Do **not** clear fuse until either merged (diff empty) or intentional discard after salvage |

Worked case 01-08-2026 (`config/marathon_landing_copy.php`):  
[server-resource-guards.md §8.1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md).

### 4.2 Critical cabinet / host

Use the TG runbook +  
[UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md)  
and resource-guards §7. Soft remediate **must not** claim success on critical fails.

---

## 5. Prevention (so the class does not repeat)

| Need | Do | Do not |
|---|---|---|
| Landing / channel copy | PR → `main` → auto-deploy | `nano` / editor on VPS tracked files |
| Marathon testimonial | env / MarketingSetting where wired | tracked `config/marathon_landing_copy.php` on prod |
| Legal PDFs | `public/docs/*.pdf` | other tracked paths |
| Hotfix urgency | PR + wait ≤30 min auto-deploy, or one `deploy.sh` from **clean** tree | partial checkout + leave dirty forever |
| After soft fix | `guards:verify` + `cabinet:probe` green | only delete breaker and walk away |
| `scripts/server_guards/sbin/*` or conf changed on `main` | once on VPS: `sudo bash scripts/server_guards_apply.sh` then `guards:verify` (auto-deploy keeps code via deploy exit 0, does **not** rollback — #1143) | clear soft fuse without apply when reason is GUARDS DRIFT / managed-file (restarts loop) |

Open a Systema issue, assign `@pe4kinsmart-tech` (Ivan), for host/ops units  
(see n8n / ssh skill standing rule).

---

## 6. Incident log (append-only)

Agents: after **any** soft-guard triage on prod, add one row (newest on top).  
Do not invent rows for chat-only speculation.

| When (UTC) | Tag / fingerprint | Paths / SHA | Outcome | By | Notes |
|---|---|---|---|---|---|
| 2026-08-24 03:40–04:35 UTC | host/ops guards re-triage #2 (same paste class as ≈00:40 row): backup-fresh ×2 critical + tmpfs-cap + cgroup soft; auto-deploy soft FLAPS false | prod HEAD `aad16074` (deployed 23-08 23:30Z by wrapper itself, health clean); cabinet app-surface healthy all pass (`/` fine, probe failures = guards only). **auto-deploy claim STALE:** crontab line `*/30 systema-auto-deploy-run.sh` present live whole window; guard intermittently reports «нет строки» (06:45 MSK tick) then not — flappy read race, not a real outage. **cgroup soft decoded:** memory.stat = anon 1.9 MiB, **file cache 1.74 GB** — the ≥80% is page cache from cron children doing GB-scale IO, plus a 4-day-old orphaned `telegram-harvest:sync` PID 2789415 (20-08 05:16 MSK run never exited; ignored SIGTERM, killed -9; its sibling failure class: `ors_faq_peers.json` root:root 640 since 19-08 blocked both daily runs exit 1 Permission-denied — `chown -R www-data storage/app/telegram-harvest` fixed). **backup-fresh REAL:** raw PROPFIND `/Laravel/` holds only part-39-of-39 of group `2026-08-24-02-02-32` (2.01 GiB local, healthy) — parts 01–38 absent; scheduled `resume-yandex-parts` (daily 04:10 MSK) started 01:11 UTC on this group, warned part-38 at 01:20, **died silently mid-run** (no exception/completion line; earlyoom runs `--prefer ^php$` but journal shows no kills that night; LXC guest cannot see host-side OOM — cron.service 2 GiB cap remains suspect #1). Manual resume launched 03:51 UTC from sshd scope (outside cron cap): **strace caught the stall live** — TLS fd in sendto() EAGAIN loops, kernel send buffer full, zero bytes leaving for minutes = Yandex-side congestion stall, a third subclass after black-hole-2xx (22–23-08) and non-persistence-after-verify (00:40 row). tmpfs-cap unchanged (Artem P5). | **Triage:** no deploy, no Artem page (SSH live, site up). Residuals minted [H3410](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3410-Sonnet_Systema-Sanscriticum_yandex-offsite-resilience_24.08.26.md) (off-site resilience: resume out of cron cap, PUT stall timeouts, per-part observability, cadence, @DECIDE alternate remote) + [H3411](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3411-Sonnet_Systema-Sanscriticum_harvest-sync-harden_24.08.26.md) (harvest hardening: withoutOverlapping/timeout, root-invocation guard, hang watchdog). Off-host protection unchanged: restic hourly to `.91` per 23-08 rows. | ox-alpha (`opencode/x-preview-f-free`) | class: silent-death of scheduled resume needs a loud-failure shutdown hook; guard «crontab line missing» can flap under concurrent managed-file rewrites; page-cache-dominated cgroup% overstates throttle risk but the cap still kills long php children |
| 2026-08-24 ≈00:40 UTC | host/ops guards sticky re-triage live: tmpfs-cap + backup-fresh ×3 | prod HEAD `bcc897e6`; cabinet healthy (`/` 31%, fpm up 20 d, mem/swap fine); probe critical = guards only | **Triage:** site never down; no deploy, no Artem page (SSH live). **New measurement on the [H3371](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3371-OxAlpha_Systema-Sanscriticum_backup-yandex-webdav-uplink-truncation-fix_23.08.26.md) lane:** today's three full-archive split-uploads (local zips ~1.86 GB from runs 07:14–12:16 UTC; groups failed 11:29 / 12:34 / 13:00 UTC, each at/near `part-38-of-39` «verify missing» after 2xx PUTs) left **zero surviving parts** — raw PROPFIND of `/Backups/systema-sanscriticum/Laravel/` holds only the two Aug-13 obrezki + probe bins + the 18.59 MB DB-only `2026-08-23-21-31-59.zip` that landed WHOLE at 22:04Z. So the small-single-file class survives while GB-scale groups do not, and parts that PASSED fresh-process verify at PUT time are gone 8+ h later ⇒ not listing lag — a non-persistence class the per-part post-PUT verifier cannot catch. `resumeOffsite()` correctly no-ops here (it resumes only groups with ≥1 remote part). Off-host protection meanwhile intact: restic hourly to `.91` green (snapshot `e9103fd5` 20:01Z, 5.43 GiB, covers storage/app + DB dump + samudra; S3 leg still SKIP, no env file); morning SHA-verified relay copy still the newest confirmed-good off-site full archive. tmpfs-cap unchanged — host-side Proxmox mount (foreign uid=), Artem P5. | ox-alpha (`opencode/x-preview-f-free`) | class: group-completeness gate must be a DELAYED full-group PROPFIND (+ auto-retry of the whole group), not per-part verify right after PUT; else pick the lane: rework split-upload or unblock [H2648](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2648-OxAlpha_Systema-Sanscriticum_yandex-disk-resumable-upload-stall_13.08.26.md) OAuth→S3 |
| 2026-08-23 follow-up ≈20:05 UTC | **RESOLVED**: restic lane green ×3 — rootlock row below closed | MG correction accepted: `.91` was NEVER inaccessible — `192.168.200.91` (repo side) is the same box as public `root@193.232.229.91` (hostname `samskrtam50`, dual-homed eth0), regular access per `/n8n` skill; only the `restic-push` service account is sftp-chrooted, not us. Root actor identified by evidence: **restore-verification session** — `/srv/restore-tmp/` (root, created 17:09–17:17Z) holds `restore.log` «Restored 7281 files/dirs (2.247 GiB) + 26 (5.433 GiB)» + `RESTORE_DONE`; no cron/timer/process on .91, no recent interactive SSH (wtmp last 05-08) ⇒ entered without sshd trace (Proxmox-host console class) or from a parallel agent lane; it self-cleaned its locks/index by 19:33–19:41Z. My fix: chowned remaining **45 uid0 data packs** → 999:988 (`find -uid 0 -exec chown`, content untouched). Result: three consecutive green runs 19:24:51 / 19:45:12 / 20:01:21 UTC `overall_rc=0 systema=OK samudra=OK sftp=OK`; unit `inactive` (clean); [H3396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3396-Sonnet_Systema-Sanscriticum_restic-sftp-rootlock-poisoning_23.08.26.md) flipped done in registry ([Uprava ea121a69e](https://github.com/gasyoun/Uprava/commit/ea121a69e)). Open residuals: S3 offsite leg still SKIPs (`/root/.restic-s3.env` absent) so restic redundancy is single-noggined; `.91` sshd has `PasswordAuthentication yes` under ~2970 brute attempts/day (root itself is `without-password`) — hardening candidate for Artem/Ivan, NOT changed unilaterally. backup-fresh ×3 + tmpfs-cap remain as before (own lanes). | ox-alpha (`opencode/x-preview-f-free`) | class: restore-test writer must run as uid999 (or chown after) on a shared restic repo; "sftp-only" applies to the service account, never assume the host is unreachable |
| 2026-08-23 ≈17:09–19:25 UTC | host/ops guards sticky: `failed-units` (restic-backup.service, new class) + tmpfs-cap + backup-fresh ×3 | prod HEAD `43136001` (#2027, H3371 split-upload listing-lag fix, auto-deployed 18:31Z). **restic lane:** hourly wrapper `/usr/local/sbin/systema-restic-run.sh` green through 17:01Z, failing from 18:01Z — repo `sftp:restic-push@192.168.200.91:/systema` (client=uid999/gid988) poisoned by **foreign root-owned files**: stale locks 17:09+17:37×2 (`-r-------- root:root`, uid999 can't even read them to judge staleness), then fresh locks 19:09/19:14 and an **index** `ea7b212b1a…` written root at 19:21 during our run window → Go retry panic wrapper, `Load(<index>) permission denied`. Cadence ~5 min ⇒ live root-side restic session/job ON .91 started between 17:01–18:01Z (restore test or host cron). Ruled out on `.92`: own unit (writes land uid999), `restic-forget.timer` (daily 05:00), crontab (none), NFS/fuse mounts (none). Cleared the three stale locks via SFTP rm (locks dir group-writable); did NOT touch the root-owned index under an active writer. **backup-fresh ×3 legit:** zero complete part-groups ≥ threshold on yandex_disk — `/Laravel/` holds only Aug-13 obrezki (2×11.7 MiB); H3371's post-deploy test archive `2026-08-23-21-31-59.zip` (18.6 MB DB-only, parts=1) landed 21:41 MSK as «ожидающая» per the known WebDAV listing lag — resume job `04:10` owns it; guard correctly refuses sub-threshold groups. tmpfs-cap unchanged (Artem P5). Cabinet itself healthy (probe 2.0 s, fpm/nginx up weeks, `/` 31%). | **Triage:** no deploy of ours, no Artem page yet — SSH live, site never down. Residual minted [H3396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3396-Sonnet_Systema-Sanscriticum_restic-sftp-rootlock-poisoning_23.08.26.md): Artem identifies/stops/re-userspaces the .91 job + `chown -R 999:988 /systema`; then verify 2 consecutive `overall_rc=0 … sftp=OK`. Side gap noted in H3396: S3 offsite leg still SKIPs (`/root/.restic-s3.env` absent, H3175 contract) so one poisoned repo currently takes out ALL restic redundancy. Do not whack-a-mole-delete root files while their writer is live. | ox-alpha (`opencode/x-preview-f-free`) | class: foreign root writer on a shared restic repo makes every uid999 client run fatal; ownership discipline on the server side is the only durable fix |
| 2026-08-23 follow-up ≈19:10 UTC | **RESOLVED**: morning «HOST DOWN» row reclassified | Box never died: uptime continuous since 29-07, nginx since 19-08, **zero** journal start/stop events in the window; Artem verified reachability from multiple locations (incl. T2 mobile) during our blackout. Real class = **provider null-route / anti-DDoS block (Пудлинк)**: outbound LAN lived (backups flowed), public inbound blackholed; lifted ≈18:50 UTC — diagnosis per [SERVER_INCIDENT_MANUAL.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_INCIDENT_MANUAL.md); Artem's multi-location checks came after the lift. Lesson: single-vantage black ≠ host-down — cross-check a second vantage BEFORE escalating to Artem. Full analysis: [INCIDENT_SAMSKRTE92_HOST_DOWN_23-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_SAMSKRTE92_HOST_DOWN_23-08-2026.md), ledger rows №5/№6. Post-recovery: [H3385](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3385-Sonnet_Systema-Sanscriticum_tg-admin-notifier-blocked-chat-403-storm_23.08.26.md) applied — chat `197649919` removed from both prod `.env` lists, notifier test delivered to both live chats, zero new 403s (day counter frozen at 747), backup `.env.bak-h3385`. | ox-alpha (`opencode/x-preview-f-free`) | class: transit-path outage misread as host-down; box logs are the tiebreaker |
| 2026-08-23 05:45–07:45 | host/ops guards critical ×4 sticky: tmpfs-cap + backup-fresh ×3; +1 transient `auto-deploy` soft at 07:15Z | HTTP 200 local; php-fpm up 19d, nginx up 3d (restart counter 1 since 19-08); `/` fs 27%, avail 13 Gi; prod HEAD `e3140ff7` (#1989). All four criticals are guards, zero app exceptions. **New hard measurement:** raw `curl -T` PUT of 100 MiB random file to webdav.yandex.ru died mid-transfer at **32 309 248 B (~31 MiB) after 247 s (~130 KB/s)**; incomplete object never persisted server-side (PROPFIND empty, DELETE 404). PROPFIND with prod `.env` creds → **207** (creds valid). Remote estate: newest completed archive still the 11.21 MiB obrezok `Laravel/2026-08-13-15-00-17.zip`; another session's 50 MiB `probe-50mb.bin` landed 23-08 00:10 — transfers possible, GB-scale not survivable (~4.5 h at this rate). Local chain healthy: five ~2.0 GB zips landed 22-08 (prior session's manual runs); `backup:monitor` red on BOTH legs: local 12.55 GB > 4.88 GB limit, yandex age 10 d. One-off 07:14 `backup:run` died `ZipArchive::close(): Permission denied` via `LiveTreeZip` — not reproduced on later writes; open question, not scoped here. | **Triage:** no deploy, no Artem page (SSH live). Interim off-site copy RESTORED by relay `.92` → MG Windows box (`C:\Users\user\Backups\samskrtam150\`) → `root@193.232.229.91:/var/backups/samskrtam150/`, SHA256 `313FA3C14DDF2A0F7B88998E05F6CE702B4790B7D87A67C4D660F9BD78390120` identical on all three sides — «no live off-site destination» answered for today, automation still broken. Residuals minted: [H3371](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3371-OxAlpha_Systema-Sanscriticum_backup-yandex-webdav-uplink-truncation-fix_23.08.26.md) (fix the yandex leg: enable shipped H3175 S3 leg first, resumable/part-split fallback; extends blocked-on-OAuth [H2648](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2648-OxAlpha_Systema-Sanscriticum_yandex-disk-resumable-upload-stall_13.08.26.md)) + [H3372](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3372-OxAlpha_Systema-Sanscriticum_backup-local-retention-prune_23.08.26.md) (retention prune). tmpfs-cap unchanged = Artem P5 ([H3181](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3181-Opus_Systema-Sanscriticum_uptime-w1-live-hazards-tmpfs-healthmon-backup-nginx_19.08.26.md)). `auto-deploy` flap self-cleared — crontab line present, guards re-applied 04:00Z per auto_deploy.log. | ox-alpha (`opencode/x-preview-f-free`) | class: 3rd log row of the yandex WebDAV throughput/truncation family — stop treating per-run, the LEG needs a decision (S3 vs resumable); monitor still calls an 11 MiB fresh-dated obrezok «healthy» (size floor lives in the guard, not in backup:monitor); Windows agent note: pwsh strips `$()`/`$var` inside ssh one-liners — multi-step probes go via scp'd script per sos.md Phase 1 |
| 2026-08-22 14:20-15:05 | host/ops guards sticky: tmpfs-cap + backup-fresh ×4 | HTTP 200 site-wide; php-fpm/nginx up 18d; `/` 18%; prod HEAD `c75659c8` (#1964). Backup chain reconstructed from logs: Aug 10 weekly run local OK 1.33 GB, yandex 401 Unauthorized (pre-creds); Aug 13 creds fixed - manual runs: 2.74 GB zip instant WebDAV **413**, 11.21 MB test PUT hung exactly 600 s then «Empty reply» (= the two ~11.7 MiB yandex truncations, guard «(9)»); Aug 17 02:00 weekly run died at scan `RecursiveDirectoryIterator ... telegram-harvest/pilot/manifests Permission denied` (perms re-fixed same day 04:23Z per ctime); Aug 19 21:34 run died mid-zip `ZipArchive::close(): Unexpected length of data` (same day as the 7.6 GiB scratch swap storm); Aug 22 17:01+17:09 MSK manual runs landed fresh local archives (17.92 MB test + full 1.87 GB / 7193 files, central directory reads clean). Probe kept saying local stale 12d because ShellSystemInspector caches destination stats 3600 s (`server_guards.backup_destinations`) - key flushed, re-probe green on local. | **Triage:** no deploy, no Artem page (SSH live). Local copy fresh + verified readable; remaining criticals are known human/host lanes: yandex off-site = [H2648](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2648-OxAlpha_Systema-Sanscriticum_yandex-disk-resumable-upload-stall_13.08.26.md) BLOCKED until `YANDEX_DISK_OAUTH_TOKEN` (`cloud_api:disk.write`) lands in prod `.env`; tmpfs-cap = Artem P5 ([H3181](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3181-Opus_Systema-Sanscriticum_uptime-w1-live-hazards-tmpfs-healthmon-backup-nginx_19.08.26.md)). Fuse `auto_deploy.disabled` already removed. Residual smells: `BACKUP_ARCHIVE_PASSWORD` unset so archives sit unencrypted at rest; spatie destination lives inside the backed-up source tree (`storage/app/Laravel` under included `storage/app`). | ox-alpha (`opencode/x-preview-f-free`) | class: multi-day backup-fresh cascade + 3600 s guard-cache lag masked a recovered local destination |
| 2026-08-21 06:00–06:12 | host/ops TG every */15 after H3197 sticky | HTTP 200. Failures: `guards/cgroup` live RSS 1802–2029 МиБ / 2048, plus stable `tmpfs-cap` + `backup-fresh` (11 МиБ yandex stub, local 11d). `SoftFailureFingerprint` hashed the **MiB number**, so each tick looked like a new class. SOFT_CHAT empty → 4 critical chat_ids. `telegram-harvest:sync` still in `cron.service` cgroup (~1.7 GiБ). | **Code:** H3227 collapse `guards/<name>:` to the name; `--dry` no longer POSTs the soft webhook. Site was never down. | Grok 4.6 (`grok-4.6`) | class: sticky fingerprint included live cgroup RSS |
| 2026-08-18 08:37 – 2026-08-20 13:21 | n8n ZOOM 1.4 TEST recordings jam (TG groups empty) | Live wf `1EIqqNzMl5NNIxST` `ZOOM 1.4 (Final) + АДМИНКА ТЕСТ`. Long runs die on `AI Agent1` / OpenRouter: exec 1281–1392 **402 credits** (`64000` max_tokens vs `60971` affordable) on `anthropic/claude-sonnet-4.5`; exec **1423** **403 TOS** (`violation of provider Terms Of Service`). Last full success exec **1258** 17-08 08:06–10:29Z (Кочергина гр.61 lesson 1875). `таймкоды` switched to `deepseek/deepseek-v4-pro` 20-08 06:42Z (manual 1407–1412 success); live ZOOM model switched **20-08 19:28Z** (after 1423). Laravel: no `lessons`+recording for 18–20 Aug courses with TG chats. `/admin` 302 / `/admin/login` 200 at 19:10Z (Blade-touch 500 is the earlier row). | **Triage 20-08 ~19:30Z:** do not re-run ZOOM from webhook (duplicate YT/Rutube). Resume failed execs from `AI Agent1` now that the live model is DeepSeek. Backlog can be pasted in Filament. Watcher residual [H3209](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3209-Grok_Systema-Sanscriticum_recording-gap-watcher-n8n_20.08.26.md). Did not page Artem (both SSH hosts up). n8n swap was 2/2 GiB — not the fail class. | Grok 4.6 (`grok-4.6`) | class: OpenRouter Anthropic credits then TOS; lesson join is course_id+lesson_date |
| 2026-08-20 19:46 | H3197 host-only guards not SOS; TG sticky file | SOS spam: HTTP 200, 🚨 from tmpfs-cap/backup-fresh; `optimize:clear` wiped file-cache cooldown; 3 chat_ids × deploy retries ≈ 10 messages, some ~1 min apart. Prod HEAD after deploy `0337f6a4`. | **Landed:** [PR #1903](https://github.com/gasyoun/Systema-Sanscriticum/pull/1903). SOS + Better Stack `/fail` + `--fail-on-critical` = HTTP/cabinet only. Host guards → sticky `host/ops`. TG state `storage/app/cabinet_probe_tg_state.json`. `deploy.sh --no-alert`. Fuse `auto_deploy.disabled` removed after deploy exit 0. One `host/ops` TG at landing (running script was pre-`--no-alert`); sticky thereafter. | Grok 4.6 (`grok-4.6`) | class: host-guard SOS + cache-clear spam |
| 2026-08-20 00:00–18:28 | critical `cabinet:probe` Filament `/admin` HTTP 500 | `touch(): Utime failed: Operation not permitted` at `BladeCompiler.php:215`; 97 errors (every */15 watchdog). 8 compiled views `root:root` 755 (`*.php` + leftover `*.blade.php`). User 6856. Public `/` 200, `/login` 200, `/admin/login` 200, `/dvaram` 302. Prod HEAD then `49c4fa56` (H3181). SSH up; php-fpm/nginx/disk OK. | **Triage 20-08 18:28Z:** same class as 17-08. Cause: 19-08 21:01Z `deploy.sh` `cabinet:probe --fail-on-critical` died on `tmpfs-cap` + `backup-fresh` (not HTTP); `fail` is `exit 1`, so the post-probe `chown_compiled_views` never ran. Live fix: `chown` of those 8 files → www-data; re-probe as www-data: Filament `/admin` gone from the failure list. Code failsafe H3194: chown **before** `fail` when probe is critical. Fuse `auto_deploy.disabled` **left in place** — probe still critical on tmpfs (Artem P5, H3181) and backup-fresh (H3174 in flight). Do not `rm` the fuse while `--fail-on-critical` would still fail. Did **not** page Artem (SSH live, not host-down). | Grok 4.6 (`grok-4.6`) | class: root-compiled Blade after failed probe; public smoke 200 does not see /admin |
| 2026-08-17 05:15–05:45 | critical `cabinet:probe` Filament `/admin` HTTP 500 | `touch(): Utime failed: Operation not permitted` at `BladeCompiler.php:215`; 660 compiled views `root:root` 755 after `deploy.sh` `artisan optimize`. User 6856 (probe login). Public `/` 200, `/login` 200, `/dvaram` 302. Prod HEAD then `91ed66ae`. | **Triage 17-08 05:57Z:** php-fpm/nginx/disk OK; latest probe already green (compiled files readable until next recompile). `chown -R www-data:www-data storage/framework/views` (661 files). Re-probe 1594 ms all-OK. Failsafe: deploy.sh chowns views after every root `optimize`. Leftover `telegram-support:sync` watchdog is H2988 (Grok 4.6) — telegram-support:sync still hits 120s watchdog after H1915, not this turn. | Grok 4.6 | class: root-compiled Blade; public smoke 200 does not see /admin |
| 2026-08-16 23:30 | critical `cabinet:probe` manager+student `/dvaram` HTTP 500 | `ClubEntitlement::activeTierFor` `get(['tier_code'])`; SQLSTATE 42S22 on `club_memberships`; user 6856. Recovered 23:45 without deploy. Prod HEAD then `ea1eb6bd`. | **Triage 17-08 04:10Z:** site live; column present; `MEMBERSHIP_TIERED=false`; php-fpm/nginx/disk OK; `cabinet:probe` 1634 ms green. Harvest `pilot/` was `root:700` — `chown www-data` so `storage:check`/`backup:run` stop throwing. Code failsafe H2971. | Grok 4.6 | class: dark membership SELECT of a new column; public smoke 200 does not see /dvaram |
| 2026-08-15 08:30–08:57 | `[rolled-back]` ×2 (08:30 then 08:44 auto-retry) | Horizon master `memory_limit` 64 MB; attempted `bd70a1a5` (#1727 banner). Rollback SHA `3ae0854e`. | **Triage:** site always 200; root cause is Horizon master exit 12 (`Using 65/64MB`), ~5054 restarts from 04:59Z, not the banner commit. [#1729](https://github.com/gasyoun/Systema-Sanscriticum/pull/1729) (`378f5376`) raised master to 128 (`HORIZON_MEMORY_LIMIT`). Fuse already gone and HEAD=`origin/main`=`378f5376` at 08:57 (deploy.sh, not the wrapper). This session rebuilt `config:cache` (live had drifted to 256 from a gitignored hotfix) → 128, restarted Horizon, `cabinet:probe` + `guards:verify` green. | Grok 4.6 | class: Laravel default master 64 MB too small for this app boot; health_check greps RUNNING during crash-loop and rolls back a healthy SHA |
| 2026-08-07 19:30 | tracked dirty `6795e22d` (#1193) | `app/Console/Commands/ImportStartChteniyaCohortSrsDeck.php` mid-deploy of `58efc74b` (H2106 trim fix) | **2026-08-08 triage:** self-healed same minute — `deploy.sh` discarded origin-equal dirty + OK `58efc74b`. Fuse never set. Prod green HEAD=`e29df61b`; removed leftover untracked `.bak.h2106.20260807192313`. Closed #1193. | Grok 4.5 | class: probe race during origin-equal dirty window + prod hotpatch bak; do not edit tracked PHP on VPS |
| 2026-08-05 05:30–09:00 | `[rolled-back]` ×4 then `[blocked-preflight] auto-retry исчерпан (3/3)` | `/usr/local/sbin/systema-schedule-run.sh` vs repo after #1109 / `5a3b6035` (1.87.2); base `50e751b5` | **2026-08-06 triage:** site always 200; root cause = managed-file drift (reaper removed in #1109, sbin not re-applied). `server_guards_apply` path already refreshed sbin mtime 07:32Z 06-08; fuse **already absent**; retries file absent; `guards:verify` + `cabinet:probe` exit 0; HEAD=`origin/main`=`9561a1db`. No `rm` needed. | Grok 4.5 | class: template change without `server_guards_apply.sh` → deploy exit 1 → auto-rollback loop → soft fuse |
| 2026-08-01 ~19:30 | `[blocked-preflight]` + dirty | `config/marathon_landing_copy.php` @ 852da14b stuck | later PR #1045 → fuse clear + deploy → 447bc544 | ops/H2147 | FINDINGS §280 |
| 2026-08-02 | playbook + catalog authored | — | docs H2148 A | Grok 4.5 | this file |

---

## 7. Auto pipeline (C) — webhook → issue → agent

Soft path can also POST a structured payload (see  
[`docs/ops/SOFT_ALERT_WEBHOOK.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ops/SOFT_ALERT_WEBHOOK.md)):

1. `cabinet:probe` soft-only → optional HTTP webhook (env-gated, default off).  
2. n8n (`.91`) or GitHub Action opens/updates issue `ops-soft-alert`.  
3. Agent runner on the **dev machine** (not root daemon on prod) triages with this playbook  
   and may run `ops:soft-remediate --dry-run` over SSH; apply only allowlisted safe steps.  
4. Diverging dirty / money / migrations → human only.

**Default remains: fuse stays until safe proof.** Full autonomous “Grok clears every TG” is out of policy.

---

## 8. Related hubs

- [Uprava FINDINGS §280](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md) — class write-up  
- [Uprava DANGER_FACTS — Systema](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md)  
- Deploy: [deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md)  
- Guards history: [server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md)

_Dr. Mārcis Gasūns_
