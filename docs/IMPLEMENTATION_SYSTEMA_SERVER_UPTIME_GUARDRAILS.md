# IMPLEMENTATION — Wave 1: the four live hazards on `193.232.229.92`

_Created: 19-08-2026 · Last updated: 19-08-2026_

File-level, step-ordered build sequence for Wave 1 of
[PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md).
Waves 2–4 get their own implementation docs when their handoffs are picked up; authoring all
four now would guess at state that Wave 1 changes.

**Read the autonomy contract in §4 of the PLAN before step 1.** Every "default:" line below is
what D17 means by "the plan's marked default" — take it, log it, continue.

---

## 0. Preconditions (once, before step 1)

```bash
cd /c/Users/user/Documents/GitHub/Systema-Sanscriticum
git fetch origin
git worktree add -b <branch> ../Systema-Sanscriticum-h<id>-<pid> origin/main
```

Systema is a guarded main tree with an active watcher that reverts uncommitted working-tree
changes — author in the worktree and land with
[`/watcher-safe-commit`](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md).
Never edit the shared checkout.

Confirm the baseline before changing anything, so the 24 h observation window (D15) has a
"before":

```bash
ssh root@193.232.229.92 'free -m; swapon --show; du -sh /tmp; systemctl --failed --no-pager'
```

---

## 1. W1a — `/tmp` tmpfs cap and aging (hazard H-1)

**The defect.** `/tmp` is tmpfs sized at 126 G — half the *host's* RAM, not the container's.
Anything written there is a RAM claim with no ceiling. Measured 19-08-2026: `/tmp/hindi_reasr`
7.6 G (untouched since 18-08) plus `/tmp/whisvenv` 452 M, with the container's swap at
6226/8192 MB. On `.91` the same pattern has consumed 100 % of swap. Debian's stock
`systemd-tmpfiles-clean` has not aged these out, and no guard asserts a size.

### Step 1.1 — add the numbers to the single source of truth

File: [scripts/server_guards.conf](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards.conf)

```
# ── /tmp как tmpfs: потолок и старение (H-1, 19-08-2026) ────────────────────
# /tmp смонтирован tmpfs размером в ПОЛОВИНУ ПАМЯТИ ХОСТА (126 ГиБ), а не
# контейнера: любая запись туда — это заявка на RAM без потолка. 7.6 ГиБ
# брошенного аудио-скретча держали своп на 76 %.
TMP_TMPFS_SIZE=4G
TMP_TMPFS_MODE=1777
TMP_AGE_DAYS=10
```

Keep the `KEY=value` discipline: no `$` substitution, no backticks, `#` only at line start —
the file is parsed by both bash and PHP.

### Step 1.2 — the mount drop-in template

New file: `scripts/server_guards/systemd/tmp.mount.d/99-systema-size.conf`

```ini
[Mount]
Options=mode=@@TMP_TMPFS_MODE@@,strictatime,nosuid,nodev,size=@@TMP_TMPFS_SIZE@@
```

**Default if `tmp.mount` is not a systemd-managed mount on this container** (LXC images vary —
`/tmp` may come from `/etc/fstab` or the container config): write the same `size=` option into
`/etc/fstab` instead, add that path to the manifest, log the divergence, continue. Do **not**
skip the cap.

### Step 1.3 — the aging rule

New file: `scripts/server_guards/tmpfiles/systema-tmp.conf`

```
# Тип d с возрастом: чистить содержимое /tmp старше @@TMP_AGE_DAYS@@ суток.
d /tmp 1777 root root @@TMP_AGE_DAYS@@d
```

### Step 1.4 — register both in the manifest

File: [scripts/server_guards/manifest.psv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/manifest.psv)

```
systemd/tmp.mount.d/99-systema-size.conf|/etc/systemd/system/tmp.mount.d/99-systema-size.conf|644|critical
tmpfiles/systema-tmp.conf|/etc/tmpfiles.d/systema-tmp.conf|644|warning
```

`critical` for the cap (its absence is an unbounded RAM claim), `warning` for aging
(observability-grade — its absence causes slow growth, not an outage).

### Step 1.5 — the verify check

File: [app/Console/Commands/VerifyServerGuards.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/VerifyServerGuards.php)

Add a `tmpfs-cap` check alongside the existing `cgroup` and `scheduler-stamp` checks: read
`/proc/mounts`, find `/tmp`, assert an explicit `size=` present and ≤ `TMP_TMPFS_SIZE`.
Absent `size=` → **critical**. Present but larger → **warning** (someone raised it deliberately;
say so, don't fail the box).

Mirror the existing `ShellSystemInspector` usage — and remember `HOME=/tmp` is required when
running as `www-data`, or `Process::run()` silently returns null and the command reports 14
phantom missing guards on a healthy machine.

### Step 1.6 — apply and clear the current scratch

```bash
ssh root@193.232.229.92
cd /var/www/html && bash deploy.sh                    # code lands; exit 0 on guard drift is EXPECTED
bash scripts/server_guards_apply.sh                   # installs the new managed files
systemctl daemon-reload && systemd-tmpfiles --create
rm -rf /tmp/hindi_reasr /tmp/whisvenv                  # 8.0 G, both untouched >24 h
systemctl restart tmp.mount 2>/dev/null || echo "tmp.mount not systemd-managed — see 1.2 default"
```

**Stop condition check (D18b):** `/tmp/hindi_reasr` and `/tmp/whisvenv` are ASR scratch — Whisper
output and a throwaway virtualenv, both regenerable from their pipelines and both untouched for
over a day. They are inside the age rule and inside the fence. Anything else in `/tmp` that is
newer, or any path outside `/tmp`, is **not** covered by this step: leave it, log it, continue.

Expect `MemAvailable` to rise and swap usage to fall within minutes. Record both in the wave log.

---

## 2. W1b — repair `samudra-health-monitor` (hazard H-2)

**The defect.** The unit has failed on every run since at least 14-08:

```
PermissionError: [Errno 13] Permission denied: '/opt/samudra/logs/health_monitor.log'
```

`/opt/samudra/logs` is `root:root`; the timer's `ExecStart` runs the venv python as the
`samudra` user. The health monitor for the public search surface has therefore been dead for
days and nothing said so — `guards:verify` does not look at `systemctl --failed`.

### Step 2.1 — fix the ownership

```bash
ssh root@193.232.229.92
chown -R samudra:samudra /opt/samudra/logs
systemctl start samudra-health-monitor.service
systemctl status samudra-health-monitor.service --no-pager
```

**Default if it still fails for a different reason:** capture the traceback into the wave log,
leave the unit failing, and continue to step 2.2 — the `failed-units` check is what makes the
next failure visible, and it is worth more than the individual fix.

### Step 2.2 — teach `guards:verify` about failed units

Add a `failed-units` check: run `systemctl --failed --no-legend`, report each unit at
**warning** severity with its name in the message. Warning rather than critical is deliberate —
a failed unit is a dead guard, not necessarily a dead site, and the soft/critical distinction in
[SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md)
exists precisely so that "something is wrong" does not read as "the cabinet is down".

Add an allowlist key so a knowingly-broken unit can be muted with a reason rather than by
deleting the check:

```
# Юниты, чей провал уже разобран и осознанно терпится. Пустой список — норма.
FAILED_UNITS_ALLOWLIST=""
```

### Step 2.3 — the Samudra half of the fix belongs upstream

`health_monitor.py` should not die on a log-write failure. Open an issue against
[SamudraManthanam](https://github.com/gasyoun/SamudraManthanam) for a `try/except` around
`_append_log` that degrades to stderr. Not a Wave 1 deliverable — this repo does not own that
file. **Default: file the issue, do not edit the other repo.**

---

## 3. W1c — backup truth (hazard H-3)

**The defect.** Exactly one archive exists: `storage/app/Laravel/2026-08-10-02-01-48.zip`
(1.4 GB, dated 09-08). `backup:run` is `weeklyOn(1, '02:00')`, so Monday 17-08 should have
produced a second — it did not, and `backup:monitor` (daily, `BACKUP_MAX_AGE_DAYS=8`) did not
make that visible to anyone. `config/backup.php` writes to `['local', 'yandex_disk']`;
`YANDEX_DISK_LOGIN` and `YANDEX_DISK_APP_PASSWORD` are both non-empty, so offsite *may* be
working — but there is no evidence either way, and a local-only backup shares its fate with the
box it protects.

### Step 3.1 — establish the facts before changing anything

```bash
ssh root@193.232.229.92
cd /var/www/html
grep -rn "backup" storage/logs/laravel-2026-08-1*.log | tail -40   # did 17-08 run and fail?
sudo -u www-data env HOME=/tmp php artisan backup:monitor           # what does it say TODAY?
sudo -u www-data env HOME=/tmp php artisan backup:list              # per-disk truth incl. yandex
```

`backup:list` is the decisive command — it reports per-destination newest-archive age. If
`yandex_disk` shows archives, the offsite half already works and this step is verification, not
repair.

### Step 3.2 — make staleness reach a human

Whatever 3.1 shows, the failure mode "the weekly run silently produced nothing" must become
loud. Add a `backup-fresh` check to `guards:verify` asserting a newest archive younger than
`BACKUP_MAX_AGE_DAYS` **per destination**, critical when the offsite destination is stale or
unreachable, warning when only `local` is behind.

```
BACKUP_MAX_AGE_DAYS=8
BACKUP_REQUIRE_OFFSITE=1
```

### Step 3.3 — one tested restore path, written down

A backup nobody has restored is a hypothesis. Restore `2026-08-10-02-01-48.zip`'s SQL dump into
a scratch database **on the developer machine, never on prod**, confirm row counts on `users`,
`payments`, and `lessons`, and write the exact commands into a new section of
[docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md).

**Fence check:** this step reads production data. It does not write to prod, does not copy
`.env` values, and the scratch database is destroyed after. Do not attach the dump to an issue,
a PR, or any public surface — it contains student personal data
(`~/.claude/rules/staff-instructions-live-in-the-cabinet.md`).

**Default if the archive is corrupt or incomplete:** that is a critical finding, not a blocked
step — record it, raise it in the wave log as the wave's headline result, and continue to W1d.

---

## 4. W1d — nginx restart policy (hazard H-4)

**The defect.** `systemctl show -p Restart nginx` → `no`. Every other critical unit on the box
restarts (`redis-server`, `tg-tunnel`, `tg-reverse`, `earlyoom` = `always`; `php8.3-fpm`,
`mariadb`, `supervisor`, `samudra`, `kosha` = `on-failure`/`on-abnormal`). The one process that
serves the site is the one that stays dead.

### Step 4.1 — the drop-in

New file: `scripts/server_guards/systemd/nginx.service.d/99-systema-restart.conf`

```ini
[Service]
Restart=on-failure
RestartSec=5s
```

`on-failure`, not `always`: a clean `systemctl stop nginx` during maintenance must stay stopped,
or an operator loses the ability to take the site down deliberately.

### Step 4.2 — register it

```
systemd/nginx.service.d/99-systema-restart.conf|/etc/systemd/system/nginx.service.d/99-systema-restart.conf|644|critical
```

Note the existing trap recorded in `server_guards.conf`: two drop-ins defining the same
directive resolve by filename sort order, not intent. `99-` prefix and one file per concern.

### Step 4.3 — prove it (this is a D13 drill, not a config change)

```bash
ssh root@193.232.229.92
systemctl kill -s SIGKILL nginx        # simulate a crash, not a stop
sleep 8
systemctl is-active nginx              # expect: active
curl -s -o /dev/null -w '%{http_code}\n' https://samskrte.ru/   # expect: 200
```

Human-present window (D13). If `is-active` is not `active` after 10 s, `systemctl start nginx`
immediately, then diagnose — **the stop condition in D18a applies: a non-200 public smoke halts
the wave.**

---

## 5. Landing the wave

Order matters — the applier is a separate, deliberate step from the deploy
([issue #1143](https://github.com/gasyoun/Systema-Sanscriticum/issues/1143)): shipping code must
never silently change system config.

```bash
# in the worktree
./vendor/bin/pint
php artisan test --filter=VerifyServerGuards
bash -n scripts/server_guards_apply.sh
git add -A && git commit && git push -u origin <branch>
gh pr create --fill && gh pr merge --squash --auto
```

Then, in the same pass (D20 — a guard in git guards nothing):

```bash
ssh root@193.232.229.92
cd /var/www/html && bash deploy.sh
bash scripts/server_guards_apply.sh
sudo -u www-data env HOME=/tmp php artisan guards:verify   # HOME=/tmp is mandatory
sudo -u www-data env HOME=/tmp php artisan cabinet:probe
```

Append one row per hazard to the incident ledger with `guard = none` — all four were found by
a human audit, not by a guard, which is exactly the fact Wave 3's ledger exists to count.

Then **wait 24 hours** (D15) watching `memwatch.log`, `schedule.log`, and swap before starting
Wave 2.

---

## 6. Step dependency graph

```
1.1 conf numbers ──► 1.2 mount drop-in ──┐
                 └─► 1.3 tmpfiles rule ──┼─► 1.4 manifest ──► 1.5 verify check ──► 1.6 apply + clear
                                          │
2.1 chown ───────────────────────────────┼─► 2.2 failed-units check ──► 2.3 upstream issue
                                          │
3.1 establish facts ─────────────────────┼─► 3.2 backup-fresh check ──► 3.3 restore drill
                                          │
4.1 nginx drop-in ──► 4.2 manifest ──────┴─► 4.3 kill drill ──► 5. land
```

W1a, W1b, W1c, and W1d are independent below the manifest — they may be built in any order and
land in one PR. Only §5 requires all four.

_Dr. Mārcis Gasūns_
