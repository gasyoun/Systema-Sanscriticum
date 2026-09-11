# Guard pack install (H4194): RAM/swap/load + queue-lag + compromise-integrity

_Created: 06-09-2026 · Last updated: 06-09-2026_

SLI-3 mandate ([AGENT_FLEET_SLO_2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/AGENT_FLEET_SLO_2026.md)
§1): three OS/data surfaces that [`cabinet:probe`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ProbeCabinetHealth.php)
never reads — free RAM/swap/load1 (L1 `/tmp` scratch ate swap, L8 load
average 370), queue depth (a stalled `queue:work` ships no emails while
every HTTP surface stays green — the N2 shape), and admin-count/webroot
`.php` inventory (C1 15+1 rogue admins, C2/C3 droppers, `galex_patch.php`
backdoor, all past green HTTP-200 monitors). Census rows:
[PLATINUM_BLINDSPOT_CENSUS_06-09-2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/PLATINUM_BLINDSPOT_CENSUS_06-09-2026.md)
S1/S2/S3.

## What landed in this repo (code only — no prod install)

1. Three artisan commands, following the [`CheckStorageUsage.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/CheckStorageUsage.php)
   idiom (`--dry`, table print, Filament DB notification to
   `super_admin`/`admin`):
   - [`guards:resources`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/CheckResourceGuards.php) —
     mem available / swap used / load1 vs `config/guard_pack.php` thresholds
     (swap > 25 % RAM, load1 > 2× cores, mem avail < 15 %).
   - [`guards:queue-lag`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/CheckQueueLag.php) —
     pending-jobs count + oldest-job age per queue from the `jobs` table
     (database queue driver); CRITICAL at age > 30 min.
   - [`guards:compromise-integrity`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/CheckCompromiseIntegrity.php) —
     (a) admin/super_admin count vs a baseline JSON file (first run writes
     it), (b) webroot `.php` file inventory vs a baseline JSON file (new
     file → CRITICAL). Baselines are **never** auto-updated by the probe
     itself — `--write-baseline` is a deliberate human/deploy step, so an
     attacker who adds an admin cannot get the next run to silently accept
     it as the new norm.
2. Config: [`config/guard_pack.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/guard_pack.php)
   (thresholds, baseline paths) — thresholds are calibration placeholders,
   see § Calibrate below.
3. Tests: [`tests/Feature/GuardPackTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/GuardPackTest.php) —
   15 cases, synthetic `/proc/meminfo` / `/proc/loadavg` / `/proc/cpuinfo`
   fixtures via `FakeSystemInspector`, a synthetic `jobs` row, and temp
   baseline files. Healthy-silent + `--dry` + each alert path covered per
   command.
4. Cron template + config keys (repo-tracked, **not yet applied to `.92`**):
   `WATCHDOG_RESOURCES_*` / `WATCHDOG_QUEUE_LAG_*` / `WATCHDOG_COMPROMISE_*`
   in [`scripts/server_guards.conf`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards.conf)
   and three new lines in
   [`scripts/server_guards/cron/app-user.crontab`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/cron/app-user.crontab),
   each a **separate cron line through `systema-watchdog-run.sh`** — same
   reasoning as `cabinet:probe`/`heartbeat:ping`: a guard must not share the
   fate of the scheduler chain it is meant to catch when that chain hangs
   (docs/server-resource-guards.md §3).

## Why not inside `cabinet:probe` or `ServerGuardsAuditor`

`ServerGuardsAuditor` audits **presence/drift of static OS config**
(systemd units, managed files, crontab shape) — it does not sample live
counters (mem/load) or query the app DB (`jobs`, `users`). Bolting live
sampling and DB reads onto that class would be the "third framework" the
mission explicitly warns against; three standalone commands in the
`CheckStorageUsage.php` idiom is the smaller, already-precedented shape.

## Install (MG-gated — root@.92 SSH)

None of the following has been run against prod. Every step below is the
GTD `@DO` row in
[GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md)
(H4194 install).

```sh
ssh root@193.232.229.92
cd /var/www/html
git fetch origin && git log -1 origin/main   # confirm this PR is on main
bash deploy.sh                               # ships the three commands + config
bash scripts/server_guards_apply.sh          # installs the three new cron lines
sudo -u www-data env HOME=/tmp php artisan guards:resources --dry
sudo -u www-data env HOME=/tmp php artisan guards:queue-lag --dry
sudo -u www-data env HOME=/tmp php artisan guards:compromise-integrity --dry
# first real (non---dry) run writes the two baselines:
sudo -u www-data env HOME=/tmp php artisan guards:compromise-integrity
cat storage/app/server_guards/admin_baseline.json
cat storage/app/server_guards/webroot_php_baseline.json
```

### Calibrate before trusting alerts

`config/guard_pack.php` thresholds are carried over from the incident
narrative (docs/server-resource-guards.md §1/§9), not measured against
`.92`'s current steady state. After the `--dry` runs above, compare their
printed tables against `docs/server-resource-guards.md` §7 norms (`avail`
≈ 14–15 GiB, `procs` ≈ 60–70) and adjust
`GUARD_PACK_MEM_AVAILABLE_RATIO_CRITICAL` /
`GUARD_PACK_SWAP_RATIO_CRITICAL` / `GUARD_PACK_LOAD1_PER_CORE_CRITICAL` /
`GUARD_PACK_QUEUE_OLDEST_MAX_MINUTES` env vars if the defaults would have
fired on a known-healthy day.

### Rebasing the compromise baseline after a legitimate change

Adding a real admin or shipping a new `public/*.php` entrypoint will fire
`guards:compromise-integrity` once — that is by design (a human confirms
the change is legitimate, not the probe). Accept it as the new norm:

```sh
sudo -u www-data env HOME=/tmp php artisan guards:compromise-integrity --write-baseline
```

Class: [Uprava FINDINGS](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md).

_Auto-generated by OxAlpha (opencode/z-ai/glm-5.3-flash), H4194._
