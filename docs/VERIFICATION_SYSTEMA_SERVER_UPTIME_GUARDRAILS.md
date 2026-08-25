# VERIFICATION — Server uptime guardrails: acceptance, drills, and risks

_Created: 19-08-2026 · Last updated: 25-08-2026_

Acceptance criteria per deliverable, the exact command that proves each, and the risks-and-spikes
register for
[PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md).

The standing bar (D13): **presence is not behaviour.** `guards:verify` proves a file is on the
box; only a fault-injection drill proves the guard fires. Both are required — and every drill
below runs in a human-present window.

---

## 1. Acceptance per deliverable

### Wave 1

| Item | Acceptance criterion | Proof command |
|---|---|---|
| W1a cap | `/tmp` shows an explicit `size=` at or below `TMP_TMPFS_SIZE` | `findmnt -no OPTIONS /tmp \| tr ',' '\n' \| grep size=` |
| W1a aging | Files older than `TMP_AGE_DAYS` are removed on the next clean run | `systemd-tmpfiles --clean --dry-run 2>&1 \| grep /tmp` |
| W1a effect | Swap usage falls and `MemAvailable` rises after the scratch is cleared | `free -m; swapon --show` before/after, both recorded in the wave log |
| W1a drill | Writing past the cap fails with `ENOSPC` and does **not** consume RAM | `dd if=/dev/zero of=/tmp/capdrill bs=1M count=6000; free -m; rm -f /tmp/capdrill` |
| W1b unit | `systemctl --failed` is empty | `systemctl --failed --no-pager` |
| W1b check | A deliberately-failed unit surfaces as a warning | create a throwaway `ExecStart=/bin/false` unit, start it, run `guards:verify`, expect it named; then remove |
| W1c facts | Per-destination backup age is known, offsite included | `sudo -u www-data env HOME=/tmp php artisan backup:list` |
| W1c alert | A stale backup produces a warning, an unreachable offsite a critical | temporarily set `BACKUP_MAX_AGE_DAYS=0`, run `guards:verify`, restore the value |
| W1c restore | A dump restores with plausible row counts on a scratch DB | documented in [docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md), run once on the dev machine |
| W1d policy | `Restart=on-failure`, `RestartSec=5s` | `systemctl show -p Restart -p RestartSec nginx` |
| W1d drill | nginx returns after `SIGKILL` and the site serves 200 | `systemctl kill -s SIGKILL nginx; sleep 8; curl -s -o /dev/null -w '%{http_code}' https://samskrte.ru/` |

### Wave 2

| Item | Acceptance criterion | Proof |
|---|---|---|
| `earlyoom` on `.91` | Installed, enabled, thresholds match the conf | `systemctl is-enabled earlyoom; cat /etc/default/earlyoom` |
| `earlyoom` drill | A deliberate memory hog is SIGTERMed before the kernel OOM-killer fires, and SSH stays responsive throughout | allocate in a loop under `systemd-run --scope`, watch `journalctl -u earlyoom -f` |
| `/tmp` cap `.91` | ~~`size=2G`~~ **невыполнимо из гостя** (H3182, 25-08-2026) — `findmnt` показал `uid=100000`, то есть tmpfs смонтирован ХОСТОМ через idmap LXC, а `df` — 126 ГиБ, половину памяти хоста, а не гостя. Ни remount, ни drop-in, ни fstab не действуют: сторона Proxmox, P5, та же стена, что на `.92`. Выполнимая часть строки: `hindi_audio` удалён и своп сошёл со 100 % | `findmnt -no OPTIONS /tmp; free -m` |
| Container limits | Both containers report a non-zero memory limit and a pids limit | `docker inspect --format '{{.Name}} {{.HostConfig.Memory}} {{.HostConfig.PidsLimit}}' $(docker ps -q)` |
| Healthchecks | Both containers report `healthy`, not `none` | `docker inspect --format '{{.Name}} {{.State.Health.Status}}' $(docker ps -q)` |
| Healthcheck drill | Stopping n8n's HTTP listener flips the container to `unhealthy` within one interval | pause the process inside the container, poll `docker inspect` |
| Caddy pinned | Image reference is a digest, not `latest` | `docker inspect --format '{{.Image}}' n8n-caddy-1` |
| Log caps | `/etc/docker/daemon.json` sets `max-size` and `max-file` | `cat /etc/docker/daemon.json; docker info --format '{{.LoggingDriver}}'` |
| n8n backup | A dump newer than 24 h exists at the offsite destination | listing command recorded in the wave log |
| n8n backup drill | The dumped sqlite opens and reports a workflow count matching the live instance | `sqlite3 <copy> 'select count(*) from workflow_entity;'` against the live count |
| `verify` for `.91` | One command reports drift for every `.91` manifest row | the new verify entry point, run from `.92` |

### Wave 3

| Item | Acceptance criterion | Proof |
|---|---|---|
| `.91` HTTP monitor | Better Stack shows the monitor green | UI + a deliberate 5-min stop of Caddy producing a red incident |
| `.91` heartbeat | Silence raises an incident within period + grace | disable the cron line for one period, confirm the incident, re-enable |
| Cross-probe | Each box's probe reports the other and `/fail`s when it cannot reach it | block the peer with a temporary route, confirm `/fail`, restore |
| Remediation R2 | A killed unit is restarted automatically and logged | kill a non-critical unit, watch the ladder act |
| Remediation R4 inert | Absent the token, the ladder logs `would restart` and escalates — and does **not** act | run the ladder with `PROXMOX_API_TOKEN` unset against a stubbed failure |
| Incident ledger | The four known 2026 events are present with MTTD/MTTR | read the file; the 18→19-08 row must show `guard = none` |

### Wave 4

| Item | Acceptance criterion | Proof |
|---|---|---|
| `nftables` `.91` | Input policy `drop` with an explicit allowlist; SSH survives | `nft list ruleset`; a **new** SSH session opened from a second terminal *before* the rollback timer is disarmed |
| Rollback armed | An `at` job that flushes the ruleset exists **before** the ruleset is applied | `atq`; confirm the job body |
| `.92` `nftables` | Same, only after `.91` has run clean for 7 days | as above |
| `.91` SSH | `PasswordAuthentication no`, `PermitRootLogin prohibit-password`, fail2ban active | `sshd -T \| grep -E 'passwordauthentication\|permitrootlogin'; fail2ban-client status` |

---

## 2. The span's acceptance criterion (D16)

Everything above is per-guard. The span itself is accepted or rejected by **one measured number**:

> Drive one box into a failure it is now supposed to survive. Record `detected_at` and
> `notified_at`. **The span passes if notification reached a human within 15 minutes** (D1).

Rehearsal design:

| Choice | Ruling |
|---|---|
| Which box | `.91` — an outage there costs automation, not students |
| Which failure | Memory exhaustion to the point of unresponsiveness — the exact class that took `.92` down twice and that `.91` is closest to today |
| Who is present | A human, watching, with `.92`-side SSH open as the out-of-band path |
| Abort | Any impact on `.92` or on a public surface aborts immediately |
| Recorded | A ledger row with `class = oom`, MTTD, MTTR, and which guard caught it |

If the number exceeds 15 minutes, the honest outcome is a **named gap and a follow-up wave**,
not a re-definition of the bar.

---

## 3. Risks and spikes

### 3.1 Risks that could bite during execution

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | **The `/tmp` cap breaks a running pipeline.** Something legitimately needs >4 G of scratch; the Hindi ASR work evidently did | medium | a job fails loudly | Marked default in IMPLEMENTATION §1.2: raise the cap, log it, never remove it. Wave 2 offers an on-disk scratch path as the real answer |
| R2 | **The `hindi_*` directories are not abandoned.** Both are >24 h untouched, but "untouched" is not "unwanted" | low | rework for whoever owns that pipeline | They are ASR scratch, regenerable from source audio. Sizes and mtimes are recorded in the PLAN's audit table before deletion, so the loss is legible |
| R3 | **`nftables` locks us out of a box** | low (Wave 4 only) | severe — recovery requires Artem | D12: human-present, `.91` first, `at`-scheduled flush armed *before* apply, out-of-band check from the peer. Never unattended |
| R4 | **A container memory limit strangles n8n** and workflows start failing silently | medium | automation degrades | Set the limit generously above observed steady state, add the healthcheck **first** so degradation is visible, and watch for one full execution cycle |
| R5 | **Auto-remediation restart-loops** a genuinely broken service, masking the fault | medium | a real failure looks like flapping | Borrow H2149's answer: a retry counter in its own file, reset only by a clean success, and an explicit "gave up" message. Never silent |
| R6 | **Better Stack free-tier limits** are hit by the new monitors | low | a monitor silently not created | Count the existing 5 against the tier before Wave 3; if short, prioritise heartbeats over HTTP monitors — silence detection is worth more |
| R7 | **`guards:verify` gains so many checks it goes noisy**, and warnings stop being read | medium | the H2149 failure mode, one level up | Every new check ships with its severity argued in the doc, and an allowlist key so a known-broken thing is muted *with a reason* rather than by deletion |

### 3.2 Risks the plan accepts and does not mitigate

Stated so nobody mistakes silence for coverage:

- **Both boxes share one Proxmox host.** A host event takes both. Detection is Better Stack
  silence; recovery is Artem. D4 puts it out of scope, and no amount of in-container work
  changes it.
- **`.91`'s 2 G swap against 8 G RAM is thin.** P3 asks Artem to review it. Until then, `.91`'s
  margin after the `/tmp` fix is better but not generous.
- **The remediation ladder cannot restart a container** until P1 lands. The lane exists; the
  grant does not.

### 3.3 Spikes needed before committing to a later wave

| Spike | Question it answers | Blocks |
|---|---|---|
| S1 | Does `yandex_disk` actually receive? `backup:list` answers it in one command | W1c's shape — verification vs repair |
| S2 | ~~Is `/tmp` on these LXC guests systemd-managed (`tmp.mount`) or fstab/container-config?~~ **ANSWERED 19-08-2026 on `.92`: neither.** The host mounts it outside the container's user namespace (`uid=100000`); systemd merely *adopts* it (`journalctl -b -u tmp.mount` empty, `/etc/fstab` unconfigured), and `remount` from inside fails with `Invalid uid '100000'` even given an explicit `uid=0`. **Both of IMPLEMENTATION §1.2's defaults are therefore inoperative here** — the cap is host-side, now P5. Detail: [server-resource-guards.md §10.1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md) | W1a's mechanism — resolved, and it changed the answer |
| S3 | What is n8n's real steady-state memory ceiling across a full day, including the heaviest workflow? | W2b's `mem_limit` value. **Do this before setting a limit**, not after |
| S4 | Does Better Stack's free tier have room for 3 more monitors? | W3a/W3b prioritisation |

S3 is the one that genuinely must precede its wave: a memory limit guessed too low converts an
uptime improvement into an outage.

---

## 4. What "done" looks like for the span

- Every deliverable in §1 has a green proof **and** a passed drill.
- The incident ledger exists, is seeded with the four known 2026 events, and every row carries
  a `guard` value — including `none`.
- The §2 rehearsal has been run and its number recorded.
- Human prerequisites P1–P4 are GTD rows with owners, not footnotes.
- [server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md)
  has a new section pointing at this plan, so the next incident responder finds it from the
  document they already know.

_Dr. Mārcis Gasūns_
