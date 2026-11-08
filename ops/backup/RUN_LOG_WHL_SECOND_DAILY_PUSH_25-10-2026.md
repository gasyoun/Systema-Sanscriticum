# RUN LOG — second daily restic push at 15:30Z (25-10-2026)

_Created: 25-10-2026 · Last updated: 25-10-2026_

Trigger: GTD row `WHL` (Uprava). MG ruling **«add second»** — take option (c) of the card, a second
daily push at 15:30Z, instead of restoring the hourly cadence.

## 1. Why the cadence changed at all

[`#3038`](https://github.com/gasyoun/Systema-Sanscriticum/issues/3038) / H6352-r9 cut the pusher from
hourly to daily on 17-10-2026 because the hourly pair (systema ~9.4 GiB + samudra ~5.4 GiB) added
~0.2 GB of new packs per hour (~2.7 GB/day) to a **non-expandable** disk on `.91`; the «4–6 пушей в
сутки» variant was rejected in the same ruling. That left a 24 h RPO, which contradicts the standing
13-09-2026 ruling «при атаке терять максимум час».

On 25-10-2026 MG resolved the contradiction by adding a **second** push, not by going back to hourly:
two runs a day give RPO ≤12 h for one extra run instead of 23.

## 2. What changed

| File | Change |
|---|---|
| [`restic-backup.timer`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/restic-backup.timer) | `OnCalendar` gains `*-*-* 15:30:00` beside `03:30:00`; the #3038 rationale is kept and annotated, not deleted |
| [`systema-restic-run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/systema-restic-run.sh) | dump-cadence comment corrected (1 dump/run ⇒ DB RPO now follows the timer, ≤12 h); summary alert text no longer says "hourly" |

`RandomizedDelaySec=600` and `Persistent=true` were kept, so the second trigger inherits up to 10 min
of jitter and a missed slot is caught up after downtime.

## 3. A drift this pass found in the same file family

The versioned `ops/backup/systema-restic-run.sh` was **17 lines behind the bytes actually running on
vps92**. Three production behaviours existed only on the box:

- the single-flight `flock` guard (`#3126`) — added after an out-of-band manual run held the repo
  exclusive lock for ~9 h and every timer run then failed with "repo already locked";
- `-o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=3` on the SFTP leg — bounds a
  hung push to ~60 s instead of forever;
- `export HOME=/root`.

A rebuild of `.92` from this repo would have silently dropped all three, i.e. restored the 9-hour-lock
failure mode. The live bytes were synced into the repo in this pass, so box and repo now agree
(sha256 `c9ee5095…b09f` on both sides). Same class as the `install.sh` defect the sibling Uprava pass
found on the hub: *a versioned unit is not a durable record until it is diffed against the box.*

## 4. Deploy on vps92 — provenance, not assumption

Both files were installed from the PR branch bytes with the previous copies kept beside them
(`*.whl-20261025`), then `systemctl daemon-reload`:

| File | sha256 (repo = box, after install) |
|---|---|
| `/etc/systemd/system/restic-backup.timer` | `5ff6a4378a50451b3c902b22bfd494ee456b84b2806dcaada40c0266dc3234a5` |
| `/usr/local/sbin/systema-restic-run.sh` | `c9ee50952e1a3e99961f8670f3c3b5dec73bc4df7ad6c0305fe8d7333bf6b09f` |

`systemctl list-timers` after the reload — the second slot is armed, jitter included:

```
NEXT                         LEFT  LAST                         PASSED       UNIT
Sun 2026-10-25 15:39:00 UTC  8h    Sun 2026-10-25 03:30:26 UTC  3h 37min ago restic-backup.timer
```

## 5. One real run, and the disk cost measured rather than argued

`systemctl start restic-backup.service` (the exact command the timer runs), 07:08Z:

```
added 66.432 MiB (10.005 MiB stored) → snapshot 37ae3f40 saved   lane=systema backup=OK
added  80.735 KiB (42.021 KiB stored) → snapshot 5957b603 saved  lane=samudra backup=OK
lane=systema size_sanity=OK     lane=samudra size_sanity=OK
run_complete overall_rc=0 systema=OK samudra=OK sftp=OK s3=SKIP reason=no-/root/.restic-s3.env
```

The second push therefore costs **~10.1 MB of new packs**, not the ~0.2 GB the hourly era added per
run — the estimate the wrapper had already written down ("~10 MB packs/run") is confirmed on live
bytes, and it is ~20× cheaper than a naive reading of the #3038 figures would suggest.

Disk on `.91`, before → after this run:

| | before | after | delta |
|---|---|---|---|
| repo bytes | 25 116 440 688 | 25 126 980 432 | **+10.54 MB** |
| free bytes | 9 078 185 984 | 9 067 614 208 | **−10.57 MB** |
| `df` | 38G/49G, 8.5G free (82%) | same | — |

At ~10 MB per extra run the second push adds ~10 MB/day of inflow against a daily `forget --prune`
(06:40Z, `--keep-hourly 3 --keep-daily 3 --keep-weekly 1`), so the disk headroom that motivated
#3038 is not consumed. **Standing caution:** `.91` is at 82% with 8.5 GB free and cannot be expanded;
this is a measured margin, not a guarantee. If free space drops below ~4 GB, cut `--keep-daily` or
drop the second slot — the timer change is one line either way.

Deadman view after the run: `2026-10-25T07:10:04Z deadman complete lanes=14 stale_now=3 new_alerts=0
repaged=0 undelivered=0 witness=ok` — the three stale lanes are the already-tracked non-restic ones
(`probe-journeys`, two Windows lanes); both restic-push lanes read fresh.

## 6. What is NOT claimed

- The 15:30Z **scheduled** slot was not observed firing in this pass (armed 8 h out). The run above is
  the same unit and the same command, so the lane is proven; the schedule is proven by
  `list-timers` and by two `OnCalendar=` triggers that systemd accepted.
- RPO is **≤12 h**, not the ≤1 h of the 13-09 wording. MG chose this trade-off explicitly; the old
  wording should be restated to match (Uprava GTD row `WHL` closed with this ruling).

_Dr. Mārcis Gasūns_
