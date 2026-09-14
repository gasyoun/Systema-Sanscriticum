# Uptime monitoring — Better Stack (for agents)

_Created: 30-07-2026 · Last updated: 31-08-2026_

**Audience: agents** (Claude / Codex / ops automation). Env keys, cron paths,
smoke commands, inventory table — operate without re-deriving from chat.

**Humans (Russian):**
- Students/teachers: [samskrte.ru/uptime](https://samskrte.ru/uptime) · mirror
  [GitHub Pages /uptime/](https://gasyoun.github.io/Systema-Sanscriticum/uptime/) · tag
  [@rusamskrtam](https://t.me/rusamskrtam) — full RU:
  [UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md)
  (§1 students, §2 Ivan/Marcis only; Artem only via ops).

**Canonical inventory** of external uptime / silence monitoring for samskrte.ru
(`.92`), samskrtam.ru, Cologne CDSL, and — since 26-08-2026 — the n8n host
`.91`/context-ai.ru plus the reciprocal cross-probes between the two boxes
(§2.4–2.5). Provider: **Better Stack Uptime** (not healthchecks.io — that path is
abandoned after provider outage, 30-07-2026).

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

**Homework upload synthetic check (H37xx):** `cabinet:probe`'s student branch also
writes+reads+deletes one file through `HomeworkService::recordSubmission(finalize:
false)` on a **dedicated sandbox lesson** (`CABINET_PROBE_HOMEWORK_COURSE` /
`_LESSON_ID`, `config/cabinet_probe.php`) — set these only after creating a
throwaway `is_free=true` lesson, never a real student-facing one. It catches
route/config/storage/DB regressions in the store path, but is an **in-process**
call inside the artisan process — it does **not** exercise real nginx/php-fpm, so
it cannot catch a `client_max_body_size`/`upload_max_filesize`/`post_max_size`
mismatch (the class of incident that caused the silent 64MB wall on 04-08-2026,
see `config/homework.php`). A real black-box HTTP probe for that class is
deferred, same as Playwright above.

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

### 2.4 `.91` samskrtam50 — n8n / Caddy (**we own the host**) — W3a, PARTLY PENDING

Host: LXC guest `193.232.229.91`, private `192.168.200.91`. Public surface is
**Caddy serving `context-ai.ru`** (public DNS → `193.232.229.91`, verified
26-08-2026). n8n itself listens on `127.0.0.1:5678` only — it is **not**
reachable from the private interface, so an external monitor can only ever prove
*Caddy*, never *n8n*.

Until 26-08-2026 `.91` appeared in **no** monitor inventory at all: if it had
died, nothing would have said so. That is what W3a closes.

| Name (UI) | Type | Target | Period / grace | Status |
|---|---|---|---|---|
| `.91 n8n public (Caddy)` | HTTP | `https://context-ai.ru/` | per UI | ⏳ **not created yet — human** |
| `.91 heartbeat` | Heartbeat | silence from `.91` | **5 min** / **10 min** | ⏳ **not created yet — human** |
| `.91 peer-probe → .92` | Heartbeat | silence / explicit `/fail` | **5 min** / **10 min** | ⏳ **not created yet — human** |
| `.92 peer-probe → .91` | Heartbeat | silence / explicit `/fail` | **5 min** / **10 min** | ⏳ **not created yet — human** |

**Why these four are still open.** Creating a Better Stack object needs the team
UI or an API token, and there is **no** Better Stack API token on either box —
measured 26-08-2026: prod env holds only heartbeat *ping* URLs
(`HEARTBEAT_PING_URL`, `CABINET_PROBE_PING_URL`, `COLOGNE_HEARTBEAT_URL`,
`SAMSKRTAM_HEARTBEAT_URL`), no admin credential. An agent cannot create them and
must not invent a way to.

**Free-tier check first (S4/R6).** Count existing monitors against the tier
before creating four more. If short, **create the heartbeats and skip the HTTP
monitor** — silence detection survives total container death, an HTTP monitor
does not.

**The one human action, per heartbeat created:** paste its ping URL into the
box's env file and nothing else — the probe picks it up on its next 5-minute
tick, no restart, no redeploy:

```bash
# on .91 (and the mirror line on .92, with that box's own heartbeat URL)
install -m 600 /dev/null /etc/default/systema-peer-probe
printf 'PEER_PROBE_HEARTBEAT_URL=https://uptime.betterstack.com/api/v1/heartbeat/<TOKEN>\n' \
  >> /etc/default/systema-peer-probe
```

Until that file exists the probe still runs and still records the verdict
locally — it says so out loud rather than pretending:
`heartbeat SKIP: /etc/default/systema-peer-probe не задаёт PEER_PROBE_HEARTBEAT_URL`.

### 2.5 Reciprocal cross-probes `.91` ↔ `.92` (W3b — **wired 26-08-2026**)

Better Stack silence says *something* died; the cross-probe says *what*, because
both boxes are guests of one Proxmox host and from outside a dead container, a
dead host and a broken network look identical.

| Path | Role |
|---|---|
| [`scripts/server_guards/sbin/systema-peer-probe.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/systema-peer-probe.sh) | **ONE** script for both boxes — `.91` installs it through `../server_guards/` rows in its own manifest, so there is no second copy to drift |
| `/usr/local/sbin/systema-peer-probe.sh` | installed copy (both boxes) |
| `/etc/default/systema-peer-probe` | `PEER_PROBE_HEARTBEAT_URL` — **not in git**, see §2.4 |
| `systema-peer-probe.timer` | every 5 min, `RandomizedDelaySec=30` |
| `/var/log/systema-peer-probe.log` | durable verdict, one line per tick |

**Measured 26-08-2026, not assumed.** The boxes talk over the private
`192.168.200.0/24` (ping 0.05 ms); public port 22 between them is closed both
ways. `.92 → https://context-ai.ru/` pinned with `--resolve` to
`192.168.200.91` → **200**. `.91 → https://samskrte.ru/` pinned to
`192.168.200.92` → **200** (the bare private IP gives nginx **404** without the
right `Host`, so the probe must go by name).

`--resolve` is not decoration: on `.92` `context-ai.ru` already resolves to the
*private* address (split-horizon), and a DNS change would silently turn "the
neighbour is alive" into "the public route is alive" — a different claim behind
the same green light.

**The pair is deliberately asymmetric in exactly one line.** `.91` unreachable is
**warning** on `.92` (automation stops; sales do not) and never fails `.92`'s own
heartbeat — one fault must not raise two incidents. `.92` unreachable is
**critical** on `.91` and does fail its heartbeat: that is the product itself
being down.

Timers, not cron lines. On 18-08-2026 `cron.service` hit its shared 2 GiB cgroup
budget and strangled every cron job at once (H3121 — ledger row №10, MTTD
7 h 33 m, `guard = none`). A watchdog in that cgroup shares the fate of what it
watches.

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
   | Auto-deploy stuck | breaker | `cat storage/auto_deploy.disabled`; only remove after root-cause. Soft tags `[rolled-back]` / `[blocked-preflight]` / `[timeout-alive]` = site alive (H2066/H2104) — `rm` fuse after smoke; **not** Artem. Hard breaker (no tag) = may need host/app triage. deploy.sh skips `npm` when asset paths unchanged (`FORCE_NPM=1` to force). After changing `scripts/server_guards/sbin/*`, run `sudo bash scripts/server_guards_apply.sh` once so `/usr/local/sbin` matches (else `guards:verify` drift). |

4. **After recovery**  
   - Re-run `cabinet:probe` + `heartbeat:ping` until green.  
   - Confirm Better Stack incidents auto-resolve (or resolve in UI).  
   - If multi-hour outage: note in session / GTD; optional GitHub issue.

5. **Do not**  
   - Force-push or wipe DB.  
   - Delete `auto_deploy.disabled` without reading the reason (and without smoke 200).  
   - Call Artem for `[timeout-alive]` / auto-deploy fuse while SSH works — that is app-level.
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
