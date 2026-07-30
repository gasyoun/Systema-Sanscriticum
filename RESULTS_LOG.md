# Results log

_Created: 30-07-2026 · Last updated: 30-07-2026_

Durable substantive-result tables for this repo. Newest first.

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
