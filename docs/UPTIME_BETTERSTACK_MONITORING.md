# Uptime monitoring — Better Stack (inventory for agents)

_Created: 30-07-2026 · Last updated: 30-07-2026 (samskrtam VPS heartbeat script)_

**Canonical inventory** of external uptime / silence monitoring for samskrte.ru,
samskrtam.ru, and Cologne CDSL. Provider: **Better Stack Uptime** (not
healthchecks.io — that path is abandoned after provider outage, 30-07-2026).

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
| samskrtam.ru check-from-samskrte | Heartbeat | silence from VPS probe | period **5 min** / grace **10 min** (like [Cologne 477235](https://uptime.betterstack.com/team/t576984/heartbeats/477235)) |

Settings for HTTP: status **other than 200**; contains keyword; optional
does-not-contain (`Error establishing a database connection`, `502 Bad Gateway`,
`Whoops`). Static paths need **their own** HTTP monitor — home WP 200 does not
prove `/parallel-corpus` nginx root is healthy.

**VPS-side probe (script on samskrte root; wire cron after heartbeat URL exists):**

| Path | Role |
|---|---|
| `/usr/local/sbin/samskrtam-heartbeat.sh` | curl home + parallel-corpus + keywords → POST heartbeat or `/fail` |
| `/etc/default/samskrtam-heartbeat` | `SAMSKRTAM_HEARTBEAT_URL=…` (not in git) |
| root crontab (when wired) | `*/5 * * * * . /etc/default/samskrtam-heartbeat; /usr/local/sbin/samskrtam-heartbeat.sh >> /var/log/samskrtam-heartbeat.log 2>&1` |

Checks both URLs by default (`SAMSKRTAM_CHECK_CORPUS=1`). Smoke:

```bash
# after /etc/default/samskrtam-heartbeat has SAMSKRTAM_HEARTBEAT_URL
/usr/local/sbin/samskrtam-heartbeat.sh
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
| «Site X down?» | Check Better Stack UI; for Cologne also SERVER_OUTAGES; for samskrte also `cabinet:probe` / logs |
| «healthchecks.io?» | **Obsolete** for this stack; use Better Stack URLs in the same env keys |
| «Install WP plugin for uptime?» | **No** — HTTP monitors are external |

Never paste live heartbeat tokens into issues, PRs, or this doc. Rotate in Better
Stack UI if leaked.

---

## 5. Historical note

Through 29-07-2026 docs referred to [healthchecks.io](https://healthchecks.io)
(`hc-ping.com/<uuid>`). App contract (`URL` + `URL/fail`) is unchanged; only the
provider and base host differ. Soft TG spam class fixed in H1941 / PR #880–#886
(crontab mirror, soft cooldown).

_Dr. Mārcis Gasūns_
