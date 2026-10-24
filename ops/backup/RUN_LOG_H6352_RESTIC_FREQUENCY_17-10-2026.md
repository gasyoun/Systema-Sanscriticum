# H6352-r9 — restic ingest cut at source: hourly → daily (#3038)

_Created: 17-10-2026 · Last updated: 17-10-2026_

Handoff: [H6352](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6352-OxAlpha_Systema-Sanscriticum_systema-optimisation-grill-money-wave_10.10.26.md) (money wave, executed) — this is residual **r9**, ruling 12 of the 10-10 grill. Issue: [#3038](https://github.com/gasyoun/Systema-Sanscriticum/issues/3038). Backup-semantics context: [RUN_LOG_H4979_LOCK_LEAK_AND_S3_GAP_16-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/RUN_LOG_H4979_LOCK_LEAK_AND_S3_GAP_16-09-2026.md) · [RUN_LOG_H4988_OTHER_PUSHER_LOCK_RACE_16-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/RUN_LOG_H4988_OTHER_PUSHER_LOCK_RACE_16-09-2026.md).

## What changed

`restic-backup.timer` on **vps92** (`/etc/systemd/system/restic-backup.timer`) moved from
hourly (`*-*-* *:30:00`) to **daily 03:30 UTC** (`RandomizedDelaySec=600`, `Persistent=true`).
The run script and service unit are unchanged: one full dump+push cycle per day for both
lanes (systema ~9.4 GiB, samudra ~5.4 GiB).

Repo copy synced in the same pass — [ops/backup/restic-backup.timer](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/restic-backup.timer).
`.91` retention/append-only hardening untouched (laser 13-09), Laravel/money code untouched.

## Why (measured fact, not inference)

- `.91` restic repo grows **~2.7 GB/day** because the hourly pair re-uploads a fresh DB dump:
  ~0.2 GB of new packs per pair-hour (issue #3038).
- Disk on `.91` cannot be expanded (MG 05-10) — the only lever is ingest.
- Grill ruling 12 (10-10-2026): cut at the source, hourly → daily. The 4–6 pushes/day
  variant (RPO 4–6 h) was considered and **not** chosen; accepted trade-off is **RPO 24 h**.
- Restic history on GDrive (1 928 snapshots since 19-08) keeps deep history; `.91` keeps
  `--keep-hourly 3 --keep-daily 3 --keep-weekly 1` per the 05-10 retention trim.

## Live state (proof)

```
$ systemctl list-timers restic-backup.timer --no-pager
NEXT                        LEFT LAST                         PASSED UNIT                ACTIVATES
Sun 2026-10-18 03:32:42 UTC 15h  Sat 2026-10-17 11:32:27 UTC 38min ago restic-backup.timer restic-backup.service

$ systemctl cat restic-backup.timer
[Timer]
OnCalendar=*-*-* 03:30:00
RandomizedDelaySec=600
Persistent=true
```

Baseline before the change (`restic stats --json`, vps92 → sftp `.91`, 2026-10-17T12:10Z):

```json
{"total_size":527755095554,"total_file_count":547699,"snapshots_count":87}
```

Smoke (proof the pipeline still works on the new schedule): one full cycle by hand after
the unit edit — `systemctl start --no-block restic-backup.service`, 12:17:5xZ:

```
2026-10-17T12:17:57Z lane=systema dest=sftp size_sanity=OK
2026-10-17T12:17:58Z lane=samudra dest=sftp size_sanity=OK
2026-10-17T12:17:58Z run_complete overall_rc=0 systema=OK samudra=OK sftp=OK s3=SKIP
```

`restic snapshots --latest 3` right after shows the two fresh snapshots:
`b75b9337 2026-10-17 12:17:53 systema` and `aa0fa8c9 2026-10-17 12:17:56 samudra`.
Next scheduled cycle: 18-10 03:3xZ.

## Verification (next 24 h)

Re-run the same stats on 18-10 after ~04:10Z and record the delta here:

```sh
ssh root@193.232.229.92 'RESTIC_PASSWORD_FILE=/root/.restic-pass RESTIC_REPOSITORY=sftp:restic-push@192.168.200.91:/systema \
  restic -o "sftp.command=ssh restic-push@192.168.200.91 -i /root/.ssh/id_restic_push -s sftp" stats --json'
```

Expected: one daily snapshot instead of 24, and a 24-h `total_size` delta an order of
magnitude below the ~2.7 GB/day baseline. Disk alert on `.91` (`com.gasyoun.vps91-disk-watch`,
85%) stays the tripwire.

## Rollback

```sh
ssh root@193.232.229.92 'cat > /etc/systemd/system/restic-backup.timer <<"EOF"
[Unit]
Description=Run restic-backup.service hourly

[Timer]
OnCalendar=*-*-* *:30:00
RandomizedDelaySec=300
Persistent=true

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload && systemctl restart restic-backup.timer'
```

## Risks / open

- **RPO is now 24 h** for both lanes. If MG wants 4–6 h instead, the one-line change is
  `OnCalendar=*-*-* 00,04,08,12,16,20:30:00` (6/day) — same unit, no code.
- The Sunday `restic check` offset rationale in H6320 assumed a `*:30` hourly writer. With a
  daily writer the exclusive-lock window is wider, not narrower — the sweep-before-check
  chain (06:40/06:55) still clears stale locks; no action needed.
- `backrest` UI (H6320) attaches the repo read-only; no competing schedule was created.
