# ROADMAP — Server uptime guardrails, both prod boxes (2026 H2)

_Created: 19-08-2026 · Last updated: 19-08-2026_

Four waves toward one bar: **no failure on either box goes unattended for more than
15 minutes** (D1). Cover, the twenty rulings, and the autonomy contract:
[PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md).

Order follows D2 — the Tier-0 revenue box's live hazards first, then `.91` parity, then the
cross-cutting watch layer, then the human-present security wave.

Every wave ends with: public smoke green, `guards:verify` green, and **24 h of observation
before the next wave starts** (D15).

---

## Wave 1 · The four live hazards on `.92` (immediate)

**Why first:** every item is a measured defect on the revenue box, today, not a hardening
idea. H-1 is actively consuming swap; H-2 means a monitor has been dead since at least
14-08; H-3 means the recovery story is a 9-day-old zip on the disk that would be lost with
the box.

Four deliverables, independently shippable:

- **W1a — `/tmp` tmpfs cap + aging (H-1).** Mount `/tmp` with an explicit `size=4G` on `.92`
  via a `tmpfs.mount` drop-in, add a `tmpfiles.d` rule aging entries out at 10 days, remove
  `/tmp/hindi_reasr` (7.6 G, untouched since 18-08) and `/tmp/whisvenv` (452 M, since 14-08).
  A new `manifest.psv` row + `guards:verify` check asserts the cap survives reboots and
  re-images. **Default if the cap breaks a running job:** raise to 8 G, log it, continue —
  never remove the cap.
- **W1b — repair `samudra-health-monitor` (H-2).** `/opt/samudra/logs` is `root:root` while
  the unit runs as the `samudra` user. Fix ownership, add `guards:verify` awareness of
  `systemctl --failed` so a permanently-failing unit is a warning rather than invisible.
- **W1c — backup truth (H-3).** Prove the `yandex_disk` destination actually receives (creds
  are set; delivery unverified). Make `backup:monitor` staleness reach Telegram. Add a guard
  row asserting an archive newer than `BACKUP_MAX_AGE_DAYS` exists. Investigate why the
  Monday 17-08 `backup:run` produced no file.
- **W1d — `nginx` restart policy (H-4).** `Restart=on-failure` + `RestartSec` drop-in, matching
  every other critical unit on the box.

**Unblocks:** nothing upstream — this is the root wave.
**Prerequisite:** none. **Fence:** no money code; no `.env` values.

---

## Wave 2 · `.91` brought to parity (Q3 2026)

**Why second:** the biggest relative gap. `.91` is at 100 % swap with 1.6 G available, no
`earlyoom`, no memory history, and — the part that matters most — **nothing that would tell
anyone it had died.**

Ported from `.92`'s pattern, Docker-aware (D5), living in this repo (D6) as
`scripts/server_guards_n8n/` with its own `manifest.psv` and a shared applier.

- **W2a — OS guards.** `earlyoom` with `.91`-appropriate thresholds and an `--avoid` list
  covering `dockerd`/`containerd`/`sshd`/`caddy`; `/tmp` cap at 2 G + aging (H-1's `.91` half,
  including removal of `/tmp/hindi_audio`, 7.6 G); `sysstat` + a `memwatch` equivalent so the
  box has a memory history at all; journald retention caps.
- **W2b — container guards.** `mem_limit` and `pids_limit` on both services; a real
  `healthcheck` for n8n (`/healthz`) and Caddy; pin `caddy:latest` to a digest;
  `/etc/docker/daemon.json` with `max-size`/`max-file` log rotation.
- **W2c — n8n backups (D11).** Nightly dump of `database.sqlite` (166 MB) + `credentials.json`
  to the same offsite destination Wave 1c proved, with staleness alerting. Read-only against
  the live n8n DB — the fence (D19) forbids mutating it.
- **W2d — a `guards:verify` equivalent for `.91`.** Presence/drift assertion for every row
  above, reachable from `.92` so one command answers "are both boxes guarded".

**Unblocks:** W3 (a cross-host probe needs something to report about).
**Prerequisite:** none. **Fence:** Active workflows, the n8n DB, `/opt/n8n/.env` — untouched.

---

## Wave 3 · The watch layer and remediation (Q3 2026)

**Why third:** guards that nobody hears about only convert a crash into a hang. This is the
wave that actually buys the 15-minute bar.

- **W3a — `.91` on Better Stack (D3, free tier).** HTTP monitor for the public n8n/Caddy
  surface; a heartbeat driven from `.91` itself. Silence is the alert — it survives full
  container death, which is the only class an in-box guard can never cover.
- **W3b — reciprocal cross-host probes (D7).** `.92` probes `.91` and `.91` probes `.92`, each
  reporting to its own heartbeat, using the existing `cologne-cdsl-heartbeat.sh` /
  `samskrtam-heartbeat.sh` template. Better Stack silence says *that* something died; the
  cross-probe says *what*, within 5 minutes.
- **W3c — the remediation ladder (D8/D9).** A guard may `systemctl restart` a hung unit and
  `docker restart` a container failing its healthcheck. The container-restart rung is written
  now and ships **inert**: absent `PROXMOX_API_TOKEN`, it logs `would restart <ct>` and
  escalates to Telegram instead. One env line activates it the day P1 lands.
- **W3d — the incident ledger (D14).** A dated log with detected-at / notified-at /
  recovered-at / class, so MTTD and MTTR are numbers rather than impressions. Backfill the
  four known 2026 events (24-07, 28-07, 05-08 breaker loop, 18→19-08 throttle) as the seed.

**Unblocks:** Wave 4's rollback discipline reuses W3b's out-of-band reachability check.
**Prerequisite (non-blocking):** P1 Proxmox token; P2 alerting-bot confirmation.

---

## Wave 4 · Firewall and SSH hardening — human-present only (Q4 2026)

**Why last, and why different:** both boxes run `nftables` input policy `accept`. Default-deny
is correct and a botched ruleset locks us out of a production box that has no console —
recoverable only through Artem. D12 rules this a **separate, reviewed, human-present wave**.
An unattended agent never runs it.

- **W4a — default-deny `nftables` on `.91` first** (the box whose loss costs least), with an
  `at`-scheduled flush armed *before* the ruleset is applied, and out-of-band reachability
  confirmed from `.92` before the rollback timer is disarmed.
- **W4b — the same on `.92`**, only after `.91` has run clean for a week.
- **W4c — `.91` SSH hardening + fail2ban**, matching `.92`'s `10-hardening.conf`.

**Prerequisite (blocking, P4):** a human-present window. This is the one wave that legitimately
waits.

---

## Quarterly layout

| Period | Wave | Milestone |
|---|---|---|
| Now | W1 | Four measured defects on the revenue box closed; swap pressure gone |
| Q3 2026 | W2 | `.91` guarded, backed up, and observable for the first time |
| Q3 2026 | W3 | Both boxes alert within 15 min of any death; remediation ladder live; MTTD/MTTR measurable |
| Q4 2026 | W4 | Default-deny firewall on both boxes; `.91` SSH hardened |
| End | — | **Acceptance rehearsal** (D16): drive one box into failure, time detection→notification against the 15-minute bar |

---

## Non-goals (considered, ruled out — do not re-propose)

- **High availability / standby host / DB replication.** D1 chose a detection bar, not
  redundancy; D3 chose free tiers. A second box is a different span.
- **Proxmox host configuration.** D4 — swap sizing, container auto-start, host watchdog are
  Artem's, tracked as GTD rows, never agent work.
- **Raising `cron.service` memory caps to relieve throttling.** H3121 ruled this explicitly
  wrong and the fence repeats it.
- **A second guard applier or a new ops repo.** D6 — extend what exists.
- **n8n workflow changes of any kind.** D19 fences them; the estate is already catalogued.
- **`->runInBackground()` on the MTProto commands.** Ruled out in
  [server-resource-guards.md §2.4](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md)
  and unchanged by this plan.

_Dr. Mārcis Gasūns_
