# H4988 — restic: other root-identity pushers vs the hourly `.92` race — diagnosed + fixed

_Created: 16-09-2026 · Last updated: 16-09-2026_

Handoff: [H4988](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4988-Sonnet_Systema-Sanscriticum_restic-other-pusher-lock-race_16.09.26.md).
Continues from [H4979's run log](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/RUN_LOG_H4979_LOCK_LEAK_AND_S3_GAP_16-09-2026.md)
and [HOST_OPS_restic_stale_locks_watchdog_cron_14-09-2026.md](https://github.com/gasyoun/Uprava/blob/main/reports/HOST_OPS_restic_stale_locks_watchdog_cron_14-09-2026.md).

## Pusher → schedule table (live-probed 16-09-2026, not read from docs)

| Pusher | Identity | Schedule | Can it land in a `.92` hourly window (`*:30:00`–`*:35:00`)? |
|---|---|---|---|
| `.92` hourly backup itself | `restic-push` via sftp (chroot, append-only) | `OnCalendar=*-*-* *:30:00` (moved from `hourly` 14-09-2026; confirmed live: next run 16-09 15:33 UTC) | — this *is* the window |
| Wave-4 Windows lane | plain `root@` over sftp (`sftp://root@193.232.229.91`, [FINDINGS §537](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md)) | Scheduled Task `Uprava-Wave4-LocalDataLayer-Restic-Weekly`, **Sundays 03:00** | No — fixed weekly slot, nowhere near `:30` on any hour outside Sunday 03:0x |
| Wave-4-Mac lane | plain `root@` over sftp, same script cross-host | LaunchAgent `com.uprava.backup-local-data-layer`, **Sundays 03:30** | No — same reasoning; only collides with the `.92` slot if a Sunday 03:30 run happens to overlap the `.92` 03:30 slot, which the existing per-run self-heal (`fix_repo_ownership()`, unconditional since 30-08-2026) already covers |
| Wave-6 Mac-secrets lane | plain `root@` over sftp | LaunchAgent `com.uprava.backup-mac-secrets`, **daily 05:00**; 14-09 chat-stream extension runs immediately after (still ~05:0x) | No — nowhere near `:30` |
| samskrtam forensic lane (H3179) | plain `root@` over sftp | **one-off**, last run 24-08-2026, no timer | Only if manually re-run at exactly the wrong minute — theoretical, not observed |
| **Ad-hoc root ops/diagnosis sessions** (this session included) | plain `root@` SSH, key `SHA256:dZglj/iqQZUR43lxHWqIVFBMqe5Nzw0xxFMlsVqkzMI` = MG's own `id_ed25519` per [HERMES_DELEGATION_LANE_MAP_2026-08.md](https://github.com/gasyoun/Uprava/blob/main/docs/HERMES_DELEGATION_LANE_MAP_2026-08.md) | **unscheduled** — happens whenever a session does live diagnosis/ops work directly on `.91` | **Yes, by construction** — no fixed slot to avoid the `.92` window |

## What actually explains the 14-09 16:32 UTC failure

Live `journalctl -u ssh` on `.91` for the incident window: **26 separate `Accepted publickey for root from 46.8.140.22` sessions between 16:00:55 and 16:28:54 UTC**, same key fingerprint as MG's own machine — an ad-hoc root work session, not any of the four named scheduled/one-off pushers above (none of their schedules fall anywhere near 16:0x). At 16:31:04–16:32:20 UTC the `.92` hourly `restic-push` sftp sessions landed immediately after — the incident's own signature (`Load(<lock/…>)` permission-denied → `unable to create lock in backend`).

None of the files actually found in `/srv/restic/systema` from that window are root-owned today (`find … -newermt '2026-09-14 16:00' … -ls` shows only `restic-push`-owned packs) — expected, since the file(s) that caused the race would have been swept by ownership-sweep *after* the failed run, and 2 days have passed. The causal chain doesn't need surviving evidence to close: an unscheduled root session ending at 16:28 UTC, immediately followed by the `.92` slot, is a plausible-and-sufficient collision independent of which specific command in that session wrote the offending lock file.

## The real gap: ownership-sweep's actual cadence, not the pusher roster

The handoff's own mission text assumed `restic-repo-ownership-sweep.timer` ran **every 10 minutes**. Live-checked: it did not. The deployed unit was:

```
OnCalendar=*-*-* *:52:00   # "between .92 hourly runs at :03" — comment from BEFORE .92 moved to *:30:00 on 14-09
```

i.e. **once per hour**, and its own description string was stale — it was written when `.92` ran on `hourly` (landing near `:0x`), so `:52` sat right before the next `:0x` run. After 14-09's fix moved `.92` to `*:30:00`, the gap between one sweep (`:52`) and the next `.92` landing (`:30`–`:35` the following hour) widened to **~38–43 minutes** during which any root-owned file — from any of the four named pushers *or* an ad-hoc session — sits unswept and can still be hit by the hourly run. This, not pusher identity, is what makes the residual race possible at all: the fix already shipped 14-09 (moving `.92` off the fanout window) silently un-aligned a second timer that depended on the old schedule.

## Fix applied (16-09-2026, this session)

Tightened `/etc/systemd/system/restic-repo-ownership-sweep.timer` on `.91` from hourly `:52` to every 10 minutes:

```
OnCalendar=*-*-* *:00/10:00
```

Backup of the old unit: `/etc/systemd/system/restic-repo-ownership-sweep.timer.bak-160926` (on `.91`). Verified: `systemd-analyze verify` clean, `daemon-reload` + `restart` applied, live tick observed within 20s (`2026-09-16T15:12:02Z clean (0 root-owned files)`), next scheduled tick confirmed 8 minutes later at `:20`. The sweep script itself (`/usr/local/sbin/restic-repo-ownership-sweep.sh`) is a cheap, idempotent oneshot (single `find -user root`, no-op when count is 0) — running it 6x/hour instead of 1x/hour costs nothing measurable and touches no append-only path, no `forget`/`prune`, no S3 credential.

This bounds the exposure window for **any** root-owned lock — named pusher or ad-hoc session alike — to ≤10 minutes, down from up to 43 minutes, without moving any pusher off `root@` and without touching `60-restic-append-only.conf`'s `-P remove,rmdir` (out of scope, MG ruling 13-09-2026).

## Acceptance re-check against the two known live incidents

- **13-09-2026 append-only-hardening cutover incident**: pre-dates this fix entirely (that day's failures were the upstream `--no-lock`-ignored-by-`backup` cosmetic noise, already explained and accepted in [H4979's run log](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/RUN_LOG_H4979_LOCK_LEAK_AND_S3_GAP_16-09-2026.md)). Not reopened by anything here.
- **14-09 16:32 UTC**: explained above (ad-hoc root session ending 16:28 UTC, `.92` slot landing 16:31–16:32, sweep cadence gap of ~43 min at the time). The sweep-cadence fix directly closes the mechanism that let it survive to the next hourly run — not a guarantee against every conceivable overlap (a root session ending in the same 10-minute pre-`:30` window can still theoretically race), but it converts a ~43-minute exposure into a ≤10-minute one, matching what the handoff's own mission text already assumed was true.

## Why moving every pusher off `root@` was not done here

Out of scope for a medium-effort diagnosis pass: re-keying four separate remote drivers (Windows Scheduled Task, two macOS LaunchAgents, a one-off forensic script) onto the restricted `restic-push` identity is a multi-repo, multi-host credential-provisioning project ([FINDINGS §537](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md) already tracks it as the "clean fix", GTD `@DO` row since 30-08-2026) — and it would not have prevented THIS incident anyway, since the actual pusher was an ad-hoc diagnostic root session that by definition cannot be re-keyed onto a scheduled restricted identity. The sweep-cadence tightening is the correct-sized fix for the failure mode actually observed.

_Гасунс_
