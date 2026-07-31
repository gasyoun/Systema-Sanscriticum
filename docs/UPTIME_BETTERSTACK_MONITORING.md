# Uptime monitoring — Better Stack (for agents)

_Created: 30-07-2026 · Last updated: 30-07-2026_

**Audience: agents** (Claude / Codex / ops automation). Env keys, cron paths,
smoke commands, inventory table — operate without re-deriving from chat.

**Humans (Russian):**
- Students/teachers: [samskrte.ru/uptime](https://samskrte.ru/uptime) · mirror
  [GitHub Pages /uptime/](https://gasyoun.github.io/Systema-Sanscriticum/uptime/) · tag
  [@rusamskrtam](https://t.me/rusamskrtam) — full RU:
  [UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md)
  (§1 students, §2 Ivan/Marcis only; Artem only via ops).

**Canonical inventory** of external uptime / silence monitoring for samskrte.ru,
samskrtam.ru, and Cologne CDSL. Provider: **Better Stack Uptime** (not
healthchecks.io — that path is abandoned after provider outage, 30-07-2026).

**How agents find this file (inbound pointers):** repo
[CLAUDE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md)
§ Ops / uptime ·
[README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/README.md)
(оглавление) ·
[DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)
H1794 ·
[server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md)
§3/§6 ·
[Uprava SERVER_OUTAGES.md](https://github.com/gasyoun/Uprava/blob/main/SERVER_OUTAGES.md)
(related link at top) · search `UPTIME_BETTERSTACK` / `hub_grep "Better Stack"`.

Team UI: [uptime.betterstack.com/team/t576984](https://uptime.betterstack.com/team/t576984)

**Do not commit heartbeat tokens or full ping URLs into git.** Tokens live only
in prod `.env` / `/etc/default/*` on the VPS. This doc names **env keys**,
**periods**, and **UI monitor ids** so agents can operate without secrets.

Related: [server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md)
(OS guards + why silence-based pulse matters) ·
[issue #891](https://github.com/gasyoun/Systema-Sanscriticum/issues/891)
(Better Stack wire-up) ·
[Uprava SERVER_OUTAGES.md](https://github.com/gasyoun/Uprava/blob/main/SERVER_OUTAGES.md)
(live board when an external host is down — not the config inventory).

---

## 1. Two kinds of check (do not conflate)

| Kind | Who initiates | Alerts when | Used for |
|---|---|---|---|
| **HTTP monitor** | Better Stack probes the URL | URL down / wrong status / keyword fail | Public pages |
| **Heartbeat** | **Our** machine POSTs to Better Stack | **Silence** (no ping in period+grace) or explicit `…/fail` | Cron, scheduler, cabinet pulse, optional Cologne probe-from-VPS |

Heartbeat URL shape (drop-in compatible with old healthchecks contract):

```text
https://uptime.betterstack.com/api/v1/heartbeat/<TOKEN>        # success
https://uptime.betterstack.com/api/v1/heartbeat/<TOKEN>/fail # explicit fail
```

App commands POST plain text (`ok` or a failure summary). GET also works for bare success pings.

---

## 2. Inventory

### 2.1 samskrte.ru (Systema VPS — **we own the host**)

Host: LXC / Beget VPS `193.232.229.92`, app `/var/www/html`.

| Name (UI) | Type | Target | Period / grace | Prod wiring |
|---|---|---|---|---|
| Site HTTP (existing) | HTTP | `https://samskrte.ru/` | per UI | Monitor [4751026](https://uptime.betterstack.com/team/t576984/monitors/4751026/edit) — status ≠ 200; optional keyword on live home |
| `samskrte heartbeat:ping` | Heartbeat | silence / fail from app | **5 min** / **10 min** | Env `HEARTBEAT_PING_URL` · cron www-data `*/5` → `systema-watchdog-run.sh "heartbeat:ping"` · artisan `heartbeat:ping` (Horizon check on fail → `/fail`) |
| `samskrte cabinet:probe` | Heartbeat | silence / fail from app | **15 min** / **20 min** | Env `CABINET_PROBE_PING_URL` · cron www-data `*/15` → `cabinet:probe` · critical unhealthy → `/fail`; soft failures use TG only (see soft cooldown) |

In-app Telegram from `cabinet:probe` (critical/soft + runbook) is **orthogonal**:
Better Stack = silence / external pulse; TG = detailed failure lines. Soft TG has
fingerprint cooldown (`CABINET_PROBE_TELEGRAM_SOFT_COOLDOWN`).

Smoke (on VPS):

```bash
cd /var/www/html
sudo -u www-data php artisan heartbeat:ping
sudo -u www-data php artisan cabinet:probe
# must not print «PING_URL пуст»; must report ping delivered
```

### 2.2 samskrtam.ru (other server — WordPress + static)

**No WordPress plugin on that host.** Better Stack **HTTP** monitors (world view)
plus optional **heartbeat from Systema VPS** (same pattern as Cologne §2.3): we
curl samskrtam from `193.232.229.92` and report success/`/fail`.

| Name (UI) | Type | URL / proof | Notes |
|---|---|---|---|
| samskrtam home (WP) | HTTP | `https://samskrtam.ru/` | status ≠ 200; keyword e.g. `Общество ревнителей санскрита` |
| samskrtam parallel-corpus | HTTP | `https://samskrtam.ru/parallel-corpus` | keyword `Параллельный санскритско-русский корпус` |
| samskrtam.ru check-from-samskrte | Heartbeat | silence from VPS probe | period **5 min** / grace **10 min** (like [Cologne 477235](https://uptime.betterstack.com/team/t576984/heartbeats/477235)) — **wired 30-07-2026** |

Settings for HTTP: status **other than 200**; contains keyword; optional
does-not-contain (`Error establishing a database connection`, `502 Bad Gateway`,
`Whoops`). Static paths need **their own** HTTP monitor — home WP 200 does not
prove `/parallel-corpus` nginx root is healthy.

**VPS-side probe (installed + cron on samskrte root, 30-07-2026):**

| Path | Role |
|---|---|
| `/usr/local/sbin/samskrtam-heartbeat.sh` | curl home + parallel-corpus + keywords → POST heartbeat or `/fail` |
| `/etc/default/samskrtam-heartbeat` | `SAMSKRTAM_HEARTBEAT_URL` (+ HOME/CORPUS URLs/keywords) — **not in git** |
| root crontab | `*/5 * * * * . /etc/default/samskrtam-heartbeat; /usr/local/sbin/samskrtam-heartbeat.sh >> /var/log/samskrtam-heartbeat.log 2>&1` |

Checks both URLs by default (`SAMSKRTAM_CHECK_CORPUS=1`). Smoke:

```bash
/usr/local/sbin/samskrtam-heartbeat.sh
# → OK: https://samskrtam.ru/ + https://samskrtam.ru/parallel-corpus/
```

### 2.3 Cologne CDSL (third-party — **we do not own the host**)

Site: `https://sanskrit-lexicon.uni-koeln.de/` (Universität zu Köln).

| Name (UI) | Type | What it proves |
|---|---|---|
| Cologne CDSL home | HTTP | World-visible homepage (monitor [4751491](https://uptime.betterstack.com/team/t576984/monitors/4751491)) — timeout **15–30 s**; keyword `Cologne Digital Sanskrit Dictionaries` |
| cologne CDSL check-from-samskrte | Heartbeat | **From our VPS** Cologne still returns keyword (not a cron on Köln) |

**VPS-side probe (installed 30-07-2026 on samskrte root):**

| Path | Role |
|---|---|
| `/usr/local/sbin/cologne-cdsl-heartbeat.sh` | curl homepage + keyword → POST heartbeat or `/fail` |
| `/etc/default/cologne-cdsl-heartbeat` | `COLOGNE_HEARTBEAT_URL` (+ optional CHECK_URL / KEYWORD / TIMEOUT) — **not in git** |
| root crontab | `*/5 * * * * . /etc/default/cologne-cdsl-heartbeat; /usr/local/sbin/cologne-cdsl-heartbeat.sh >> /var/log/cologne-cdsl-heartbeat.log 2>&1` |

Heartbeat UI: period **5 min**, grace **~10 min**.

When Cologne is down, also update
[SERVER_OUTAGES.md](https://github.com/gasyoun/Uprava/blob/main/SERVER_OUTAGES.md)
so scrapers stop re-probing blindly.

We **cannot** install cron on `uni-koeln.de`.

---

## 3. HTTP monitor checklist (any site)

1. URL = final HTTPS people use.  
2. Alert if status **other than 200** (baseline).  
3. **Contains** one stable on-page string.  
4. Optional **does not contain** error markers.  
5. Timeout ≥ 15 s for slow third parties (Cologne).  
6. Same notification channel (Telegram/email) as other monitors.

---

## 4. Agent playbook

| Goal | Do |
|---|---|
| «Is uptime configured?» | Read **this file**; do not re-derive from chat |
| «Wire a new heartbeat» | Create Heartbeat in Better Stack → put URL in prod env only → `config:clear` if Laravel env → smoke artisan or shell script |
| «Site X down?» | §5 FAQ + human runbook below; Cologne → also SERVER_OUTAGES |
| «healthchecks.io?» | **Obsolete** for this stack; use Better Stack URLs in the same env keys |
| «Install WP plugin for uptime?» | **No** — HTTP monitors are external |

Never paste live heartbeat tokens into issues, PRs, or this doc. Rotate in Better
Stack UI if leaked.

---

## 5. FAQ & human runbook (сайт / пульс «упал»)

### 5.1 Which alert is which?

| You saw | Means | First human step |
|---|---|---|
| Better Stack **HTTP** red on samskrte.ru | World cannot load homepage | §5.2 |
| Better Stack **HTTP** red on samskrtam.ru or `/parallel-corpus` | That URL from Better Stack PoP | §5.3 |
| Better Stack **HTTP** red on Cologne | Third-party down/slow | §5.4 |
| Heartbeat **silence** `heartbeat:ping` | No success ping in 5m+grace (VPS/cron/app dead or network to Better Stack) | §5.2 (scheduler pulse) |
| Heartbeat **silence** `cabinet:probe` | Probe cron not running or never finishing | §5.2 |
| Heartbeat **`/fail`** from cabinet:probe | Probe ran; critical surface unhealthy | TG has failure lines + runbook; §5.2 |
| Heartbeat silence/fail cologne or samskrtam **from-VPS** | From *our* VPS the probe failed (site or path to it) | §5.3 / §5.4; check VPS log |
| TG only: «soft-сбой» | Non-critical (guards/hybrid); site may still serve students | Inspect TG body; not full outage |
| TG: «Личный кабинет не работает» | Critical cabinet path | §5.2 |

### 5.2 samskrte.ru (we own the VPS) — human steps

Order matters: **confirm → classify → fix only what you own**.

1. **Confirm from your network**  
   `https://samskrte.ru/` and `https://samskrte.ru/login` in a browser.  
   Still open Better Stack incident page (which monitor/heartbeat).

2. **SSH** (if host answers):  
   ```bash
   ssh root@193.232.229.92
   systemctl status php8.3-fpm nginx --no-pager
   df -h /
   free -h
   cd /var/www/html && sudo -u www-data php artisan cabinet:probe
   sudo -u www-data php artisan heartbeat:ping
   tail -n 80 storage/logs/laravel.log   # or latest laravel-*.log
   tail -n 40 storage/logs/schedule.log storage/logs/watchdog.log
   ```

3. **Classify**  
   | Symptom | Likely | Human action |
   |---|---|---|
   | No SSH / host dead | LXC/Proxmox/hosting | **Артём (`@t3t3r1n`)** — only he restarts the container/host (see TG cabinet runbook text) |
   | SSH ok, nginx/fpm down | process | `systemctl restart php8.3-fpm nginx` (careful); re-check |
   | Disk full | `df` | free space; logs rotate |
   | OOM / load extreme | memory | [server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md); avoid blind `schedule:run` pile-up |
   | Site 200, cabinet:probe fails | app/auth/DB | TG failure lines; `laravel.log`; Filament «Здоровье кабинета» |
   | Heartbeat silence, site 200 | cron/www-data / PATH | `crontab -u www-data -l`; `systema-watchdog-run.sh`; env `HEARTBEAT_PING_URL` / `CABINET_PROBE_PING_URL` still set after deploy |
   | Auto-deploy stuck | breaker | `cat storage/auto_deploy.disabled`; only remove after root-cause |

4. **After recovery**  
   - Re-run `cabinet:probe` + `heartbeat:ping` until green.  
   - Confirm Better Stack incidents auto-resolve (or resolve in UI).  
   - If multi-hour outage: note in session / GTD; optional GitHub issue.

5. **Do not**  
   - Force-push or wipe DB.  
   - Delete `auto_deploy.disabled` without reading the reason.  
   - Assume samskrtam/Cologne share this VPS (they do not).

### 5.3 samskrtam.ru — human steps

1. Open `https://samskrtam.ru/` and `https://samskrtam.ru/parallel-corpus/` yourself.  
2. Better Stack: which monitor? HTTP home vs parallel-corpus vs VPS heartbeat.  
3. **WP host ≠ Systema VPS** — fix on the WordPress/static server (hosting panel, nginx, disk, PHP-FPM for WP).  
4. From Systema VPS (probe path):  
   ```bash
   /usr/local/sbin/samskrtam-heartbeat.sh
   tail -n 30 /var/log/samskrtam-heartbeat.log
   ```  
   Fail here but browser OK → network path from VPS only.  
5. No WP plugin to “restart” for Better Stack HTTP — external checks only.

### 5.4 Cologne (uni-koeln) — human steps

1. Confirm `https://sanskrit-lexicon.uni-koeln.de/` in browser (may be slow — wait).  
2. **We cannot reboot Köln.** Log outage for agents:  
   [Uprava SERVER_OUTAGES.md](https://github.com/gasyoun/Uprava/blob/main/SERVER_OUTAGES.md).  
3. VPS probe:  
   ```bash
   /usr/local/sbin/cologne-cdsl-heartbeat.sh
   tail -n 30 /var/log/cologne-cdsl-heartbeat.log
   ```  
4. Pause scrapes that hammer CDSL until recovered. Prefer mirrors/offline routes in FINDINGS when listed.

### 5.5 Short FAQ

| Q | A |
|---|---|
| Site red in Better Stack but I can open it? | PoP/keyword/timeout mismatch, or intermittent; re-check from phone network; inspect monitor keyword settings. |
| Heartbeat red but HTTP green? | Cron/probe on **our** VPS failed; site may still be up for users. Fix VPS side first. |
| HTTP red but heartbeat green? | Better Stack cannot reach site; VPS probe might still reach (or cached). Trust HTTP for “users in the world”. |
| Who restarts the Systema container? | **Артём (`@t3t3r1n`)** for host/LXC; app-level restarts only if you have root and know the risk. |
| Where are secrets? | Prod `.env` and `/etc/default/*-heartbeat` on VPS only — not git. |
| healthchecks.io? | Obsolete; same env keys, Better Stack URLs. |
| Soft TG every 15 min? | Should be cooled (fingerprint); if not, check deployed probe version / soft cooldown config. |

---

## 6. Historical note

Through 29-07-2026 docs referred to [healthchecks.io](https://healthchecks.io)
(`hc-ping.com/<uuid>`). App contract (`URL` + `URL/fail`) is unchanged; only the
provider and base host differ. Soft TG spam class fixed in H1941 / PR #880–#886
(crontab mirror, soft cooldown).

_Dr. Mārcis Gasūns_
