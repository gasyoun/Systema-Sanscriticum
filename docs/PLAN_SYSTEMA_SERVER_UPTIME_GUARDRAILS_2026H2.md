# PLAN — Server uptime guardrails across both prod boxes (2026 H2)

_Created: 19-08-2026 · Last updated: 19-08-2026_

Cover/index for a layered `/ask` execution plan spanning **two production LXC guests**:
`193.232.229.92` (`samskrtam150` — Systema/samskrte.ru, Samudra, kosha) and
`193.232.229.91` (`samskrtam50` — n8n + Caddy, context-ai.ru). It answers one question —
**"what guardrails exist on both servers, and what would it take for a server to stop going
down unattended?"** — and pins every decision a builder needs so four waves can execute
without a human reachable.

Provenance: `/ask` interview 19-08-2026 (5 rounds, 20 rulings, MG) preceded by a live
read-only SSH audit of both hosts, by Opus 5 (`claude-opus-5`).

Metadoc: [PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.meta.md)

---

## 1. The honest audit (the answer to "what's there today")

Measured live 19-08-2026, not read off documentation.

### 1.1 `193.232.229.92` — heavily guarded

Everything below is real, verified, and codified in git
([scripts/server_guards/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/scripts/server_guards)
+ [scripts/server_guards.conf](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards.conf),
applied by [server_guards_apply.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_apply.sh),
asserted by [VerifyServerGuards.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/VerifyServerGuards.php)).
Why each exists: [server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md).

| Layer | What is on `.92` |
|---|---|
| Early OOM | `earlyoom` — SIGTERM <10 % avail, SIGKILL <5 %, `--prefer ^php[0-9.]*$`, `--avoid` mariadbd/nginx/sshd/redis/supervisord |
| cgroup budgets | `cron.service` 2G/3G · `supervisor` 3G/4G · `systema-madeline-daemon` 768M/1G · `TasksMax=200` · `OOMPolicy=kill` |
| PHP ceilings | CLI `memory_limit=768M` (was `-1`) · `pm.max_children=12` · `pm.max_requests=500` |
| Overlap protection | `flock -n` + `timeout 900s` in `systema-schedule-run.sh`, stale-lock TTL reclaim, `9>&-` fd hygiene in all three wrappers |
| Independent watchdogs | `cabinet:probe` `*/15` and `heartbeat:ping` `*/5` as **separate cron lines**, own locks, own timeouts — cannot be silenced by a hung scheduler |
| External pulse | Better Stack: 1 HTTP monitor + 2 heartbeats (silence-based) — [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) |
| Self-healing deploy | `*/30` auto-deploy with health gate, auto-rollback when no migrations, self-rechecking soft breaker (H2149) |
| Telemetry | rsyslog · journald 3 G/180 d · sysstat 1/min · `memwatch` 1/min + pressure dump <25 % |
| Drift detection | `guards:verify` on every `cabinet:probe` and every `deploy.sh` |
| Security | fail2ban (sshd jail) · `PasswordAuthentication no` · `PermitRootLogin prohibit-password` |

### 1.2 `193.232.229.91` — essentially unguarded

| Layer | State on `.91` |
|---|---|
| Early OOM | **absent** — `earlyoom` not installed |
| cgroup / container budgets | **absent** — both containers run `mem=0`, no `pids_limit` |
| Container healthchecks | **absent** — `health=none` on `n8n-n8n-1` and `n8n-caddy-1` |
| Image pinning | `n8nio/n8n:2.27.5` pinned · **`caddy:latest` unpinned** |
| Docker log caps | **absent** — no `/etc/docker/daemon.json`, no `max-size` |
| Watchdogs / cron | **none** — `no crontab for root`, no systemd timers beyond distro defaults |
| External pulse | **none** — `.91` appears in no monitor inventory; nothing alerts if it dies |
| Backups | **none current** — stale `/opt/n8n/backups`; 166 MB live `database.sqlite` + `credentials.json` unprotected |
| Telemetry | journald only (1.5 G); no sysstat, no memwatch, no memory history |
| Security | **no fail2ban**, no `sshd_config.d` hardening file (distro defaults only) |
| Firewall | `nftables` input policy `accept` — same as `.92` |

**One sentence:** `.92` has a mature, git-codified, self-verifying guard system built from four
real incidents; `.91` has a Docker `restart: unless-stopped` policy and nothing else.

### 1.3 Four live hazards found during the audit

These are not hypotheses — each was measured on 19-08-2026.

| # | Hazard | Evidence | Box |
|---|---|---|---|
| H-1 | **`/tmp` is tmpfs sized at 126 G (host RAM) with no cap and no aging.** Abandoned scratch is pinning RAM and has consumed all swap. | `.91`: `/tmp/hindi_audio` = 7.6 G against **8 G total RAM**; `shared=5875 MB`, **swap 2047/2048 MB (100 %)**, avail 1.6 G. `.92`: `/tmp/hindi_reasr` 7.6 G + `whisvenv` 452 M; swap 6226/8192 MB. Directories untouched since 15-08 and 18-08. | both |
| H-2 | **`samudra-health-monitor.service` fails on every run.** A monitor that cannot write its own log is not monitoring. | `systemctl --failed` → `PermissionError: [Errno 13] … '/opt/samudra/logs/health_monitor.log'`, exit 1, every 15 min | `.92` |
| H-3 | **One backup exists, 9 days old, on the same container disk.** | `storage/app/Laravel/2026-08-10-02-01-48.zip` is the only archive; the Monday 17-08 weekly run left no file. Yandex Disk creds are set but delivery unverified. `.91` has no backup at all. | both |
| H-4 | **`nginx.service` has `Restart=no`.** Every other critical unit restarts; the one serving the site does not. | `systemctl show -p Restart nginx` → `no` | `.92` |

H-1 is the most dangerous: `.91` is roughly one large allocation away from the exact
memory livelock that took `.92` down on 24-07 and 28-07-2026 — and unlike `.92` it has no
`earlyoom` to fire first and no alerting to tell anyone afterwards.

### 1.4 Why the existing guards did not prevent the 18→19-08 outage

Worth stating because it shapes the whole roadmap. Prod stood for **seven hours** with
8.5 GiB free on the host ([§9 of server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md), H3121).
Every existing check reported healthy. The failure was **cgroup throttling**, not memory
exhaustion — a class no guard was watching. `guards:verify` learned `cgroup` and
`scheduler-stamp` checks the next morning.

The generalisable lesson, and the reason this plan exists: **guards cover the failure classes
that already happened.** The value of a span like this is closing classes *before* they bill
seven hours.

---

## 2. Goal for this span

Four waves such that **no failure on either box goes unattended for more than 15 minutes**
(D1) — either it self-heals, or a human is told. Not high availability; not zero downtime.
The measurable end-state is a single end-to-end rehearsal (D19) in which a box is deliberately
driven into a failure it is now supposed to survive, and detection→notification is timed.

---

## 3. Decisions taken (interview 19-08-2026, MG — do not re-litigate)

| # | Decision | Ruling |
|---|---|---|
| D1 | Uptime bar | **No unattended outage >15 min** — self-heal or notify. Not HA, not 2-min auto-recovery |
| D2 | Order | **`.92` hazards first, then `.91` parity** — revenue-weighted |
| D3 | Budget | **Free tiers only** — reuse the existing Better Stack team and Yandex Disk; no new vendor |
| D4 | Host boundary | **In-container only.** Proxmox/host-side asks (swap sizing, HA watchdog) become human GTD rows for Artem (`@t3t3r1n`), never agent work |
| D5 | `.91` architecture | **Port the `server_guards` pattern, Docker-aware** — manifest + applier + verify, plus compose-level limits and healthchecks |
| D6 | Config home | **Systema-Sanscriticum**, alongside the existing guards |
| D7 | Watch topology | **Better Stack silence + reciprocal cross-host probes** — each box probes the other and reports to its own heartbeat |
| D8 | Remediation authority | **Full auto-remediation including container restart** (see D9 for how the Proxmox gap is handled) |
| D9 | Proxmox gap | **Build the restart lane, keep it inert until a token exists.** Absent `PROXMOX_API_TOKEN`, the guard logs `would restart` and escalates to Telegram. Token request → GTD row for Artem. Nothing blocks |
| D10 | `/tmp` fix | **Cap tmpfs (`size=`) + age-out via `tmpfiles.d`, both boxes**; delete the two current directories; a guard row asserts the cap persists |
| D11 | Backups | **Verify offsite + staleness alerting + restore drill, AND add n8n backups** — nightly dump of `database.sqlite` + `credentials.json` offsite |
| D12 | Firewall | **Yes, but a separate reviewed wave** with an auto-rollback timer and a human-present window — never unattended |
| D13 | Proof bar | **Fault-injection drill per guard class, on a live box**, human-present window. Presence ≠ behaviour |
| D14 | Incident discipline | **Dated incident log with MTTD/MTTR** — without it there is no way to know whether D1 is met |
| D15 | Regression bar | **Smoke + `guards:verify` + 24 h observation** before starting the next wave |
| D16 | System test | **One end-to-end "kill the box" rehearsal at the end**; its measured number is the span's acceptance criterion |
| D17 | On ambiguity | **Pick the plan's marked default, log it, press on.** Never stall, never ask, never a third path |
| D18 | Stop conditions | Public smoke non-200 after a change · deleting data not proven regenerable |
| D19 | Fence | Money/access code paths · Active n8n workflows and the n8n database |
| D20 | Authority | **Commit → PR → merge freely; apply to prod inside the wave.** A guard in git guards nothing |

### 3.1 Two tensions the interview resolved, recorded so nobody re-opens them

- **D8 (restart the container) vs D4 (in-container only).** Reconciled by D9: the lane is
  built and shipped inert. The agent writes the code; the human supplies the grant. Neither
  ruling was weakened.
- **D12 (firewall yes) vs D17 (never stall).** Reconciled by scope: the firewall is Wave 4 and
  is explicitly **not** an unattended wave. An agent running Waves 1–3 unattended never touches
  `nftables`, so the "never stall" contract is never tested against it.

### 3.2 Plan-marked default for a fork the interview did not name

D18's stop list did not include "a step that could sever SSH". Rather than leave it dangling
(a Phase-4 gate failure), this plan **marks the default**: any change to `sshd_config`,
`sshd_config.d/*`, `nftables`, or interface configuration is **Wave 4 only, human-present,
with an `at`-scheduled rollback armed before the change**. An agent in Waves 1–3 that finds
itself about to touch one of those files applies D17 by choosing this default — skip it,
log it, continue.

---

## 4. The autonomy contract (verbatim — the execution agent obeys this)

- **On an unplanned ambiguity (D17):** choose the option this plan or the IMPLEMENTATION doc
  marks as the default, write the decision plus one line of rationale into the wave handoff's
  log, and continue. Do not stall, do not ask, do not improvise a third path.
- **Stop conditions (D18) — halt the wave and leave the box for a human if:**
  (a) a public smoke (`https://samskrte.ru/`, `https://samskrte.ru/login`) returns non-200
  after a change made by this wave — roll the change back first, then halt;
  (b) a step would delete data not proven regenerable. `/tmp` entries older than the age rule
  are proven regenerable and are fair game; anything under `/opt`, `/var/www`,
  `/var/lib/docker`, or any database is not.
- **Commit authority (D20):** each wave handoff authorises commit → PR → merge with no
  confirmation ask, **and** authorises applying the result to the live box in the same pass
  (`server_guards_apply.sh` + `guards:verify`), per the standing deploy-without-re-asking rule.
- **The fence (D19, hard):**
  - Never edit money/access code — `PaymentObserver`, the Tochka `WebhookController`,
    checkout, installments, receivables, prana wallet, `Payment::grantAccess()`, tariffs.
  - Never mutate Active n8n workflows, the n8n database, or `/opt/n8n/.env`. Guard work stops
    at the container boundary; reading container metadata is fine, writing workflow state is not.
  - Never raise `MemoryHigh`/`MemoryMax` on `cron.service` to "fix" throttling — H3121 ruled
    that explicitly wrong. The cap is correct; the fix is correcting whose budget is spent.
  - Never read, write, or copy secret **values** from `.env` or `/etc/default/*-heartbeat`.
    Guards reference env **keys**.
  - Never touch `sshd_config`, `nftables`, or interface config outside Wave 4 (§3.2).

---

## 5. Layer links

| Layer | File |
|---|---|
| Roadmap / waves | [ROADMAP_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md) |
| Architecture | [ARCHITECTURE_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md) |
| Implementation (Wave 1) | [IMPLEMENTATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md) |
| Verification + risks | [VERIFICATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md) |

Prior art this plan **consumes rather than rebuilds** (prior-art check, 19-08-2026):

| Asset | Owner | This plan's verdict |
|---|---|---|
| `server_guards` manifest + applier + `guards:verify` | Systema-Sanscriticum | **Reuse and extend** — do not write a second applier |
| Better Stack heartbeat contract (`URL` + `URL/fail`) | [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) | **Reuse** — `.91` gets heartbeats on the same team, same env-key discipline |
| VPS-side probe pattern (`cologne-cdsl-heartbeat.sh`, `samskrtam-heartbeat.sh`) | `.92` `/usr/local/sbin` | **Reuse as the template** for the reciprocal cross-host probes (D7) |
| Soft-breaker + `ops:soft-remediate` philosophy | [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) | **Reuse the severity model** for new guard rows |
| n8n estate catalog + credential audit | [docs/n8n/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/n8n) | **Consume** — the workflow inventory is done; this plan adds only host guards |
| Spatie backup (`backup:run` / `:clean` / `:monitor`) | `app/Console/Kernel.php` | **Reuse** — the machinery exists; Wave 2 fixes delivery and alerting, not the tool |

Nothing in this plan rebuilds an existing asset.

---

## 6. What is explicitly out of scope

- **High availability / failover.** D1 chose a 15-minute detection bar, not redundancy.
- **Proxmox host configuration.** D4 — host swap, container auto-start, host-level HA are
  GTD rows for Artem, not deliverables here.
- **n8n workflow content.** D19 fences it; the estate is already catalogued elsewhere.
- **Application performance.** This span is availability, not latency.

---

## 7. Human prerequisites (none block Waves 1–3)

| # | Ask | Owner | Blocks? |
|---|---|---|---|
| P1 | Proxmox API token (scoped: `VM.PowerMgmt` on the two CTs) or a restricted host key | Artem (`@t3t3r1n`) | **No** — D9 ships the lane inert |
| P2 | Confirm the alerting Telegram bot; `@testpodpiska12_bot` still reads as a test bot | MG | No — existing channel keeps working |
| P3 | Host-side swap sizing review for `.91` (2 G against 8 G RAM is thin) | Artem | No |
| P4 | A human-present window for Wave 4 (firewall) | MG | **Yes, for Wave 4 only** |
| P5 | **`/tmp` tmpfs `size=` on the host side for both CTs** — measured 19-08-2026 (H3181): the tmpfs is mounted by the host outside the container's user namespace (`uid=100000`), so `remount` from inside dies on `Invalid uid`, and neither a `tmp.mount` drop-in nor `/etc/fstab` can reach it. The cap is a Proxmox-side setting | Artem (`@t3t3r1n`) | **No** — the aging rule and the `tmpfs-cap` alarm are in place; the cap itself is not |

These land as rows in [Uprava/GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md).

_Dr. Mārcis Gasūns_
