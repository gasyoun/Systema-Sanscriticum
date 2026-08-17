# Soft server alerts — agent playbook + cause catalog

_Created: 02-08-2026 · Last updated: 17-08-2026 (compiled Blade root:755 /admin 500)_

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
- Soft anti-spam (H2335): **normalized fingerprint** (fuse TS/SHA → tag class via
  [`SoftFailureFingerprint`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ServerGuards/SoftFailureFingerprint.php))
  + **sticky** same class until green; optional re-nudge
  (`CABINET_PROBE_TELEGRAM_SOFT_REMINDER_HOURS`, default **24**; `0` = once until green).
  New soft class fires immediately. `--force-alert` bypasses.
  Legacy minute `SOFT_COOLDOWN` no longer gates re-sends (hourly spam while fuse stuck).
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
| `[blocked-preflight]` | `deploy.sh` exit 1; HEAD unmoved; health clean | usually 200 | only if remaining dirty is origin-equal (or none) | `rm` fuse while diverging dirty exists | see §4.1 |
| `[blocked-dirty]` | same class, dirty-explicit label (if present) | 200 | same as preflight | blind checkout of diverging paths | §4.1 |
| `[timeout-alive]` | deploy/rollback 124/137; smoke still 200 | 200 | clear fuse if tree clean + smoke OK | force endless redeploy | check `auto_deploy.log`; npm skip (H2104) |
| `[rolled-back]` | post-health fail; auto-rollback; soft | 200 on old SHA | none (leave fuse until bad commit fixed) | re-enable without fix on main | fix main → then clear fuse |
| breaker **without** soft tag | fuse after hard fail / bad health | maybe down | **none** | clear fuse without health proof | critical path §7 of resource-guards |
| `tracked dirty … <paths>` | working tree dirty (not only `public/docs/*.pdf`) | 200 | discard paths with empty `git diff origin/main -- path` | discard paths with non-empty diff | PR unique hotfix; no `nano config/` on VPS |
| root cron missing `systema-auto-deploy-run.sh` | auto-deploy silently dead | 200 | none | invent cron from memory | `server_guards_apply.sh` + mirror |
| earlyoom / MemAvailable / OOM | resource pressure | maybe down | none | random process kills | [server-resource-guards.md §7](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md) |
| hybrid soft surfaces | `/library` etc. optional | 200 elsewhere | none | “fix” by disabling hybrid blindly | feature flags / hybrid docs |
| `touch(): Utime failed` / root-owned `storage/framework/views` | Filament `/admin` HTTP 500; `cabinet:probe` critical; public `/` and `/login` 200 | /admin 500 | `chown -R www-data:www-data storage/framework/views` | disable probe; `nano` compiled views; restart healthy php-fpm | `deploy.sh` `artisan optimize` as root compiles Blade; php-fpm cannot `touch()`; next deploy.sh chowns after optimize |

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
