# RUN LOG — append-only restic push path (13-09-2026)

_Created: 13-09-2026 · Last updated: 13-09-2026_

MG question that started this: «как сделать так, чтобы при атаке потерять
максимум час данных?» — the hourly restic lane already gave a ≤1 h RPO against
failure, but **not against attack**: the repo password (`/root/.restic-pass`) and
the SFTP push key both live on the .92 pusher, so root on .92 could
`restic forget --prune` the whole history away on .91. MG ruling the same day:
append-only now; the Yandex Object Storage Object-Lock leg stays on its planned
≈24-09-2026 date.

## What changed

### .91 (backup host)

| Change | Evidence |
|---|---|
| `Match User restic-push` → `ForceCommand internal-sftp -P remove,rmdir` (append-only push path), managed source [`scripts/server_guards/sshd/60-restic-append-only.conf`](../scripts/server_guards/sshd/60-restic-append-only.conf) | `sftp put` OK, `sftp rm` → `remote delete …: Permission denied` |
| Standing canary `/srv/restic/systema/PROBE_APPEND_ONLY_13-09-2026.txt` — cannot be removed over the push path | present, 18 B, owned restic-push |
| Retention moved here: [`systema-restic-forget.sh`](systema-restic-forget.sh) runs as **restic-push (uid 999)** — `restic unlock --remove-all` + `forget … --prune`, same policy (`--keep-hourly 12 --keep-daily 14 --keep-weekly 8 --keep-monthly 12`) | `lane=forget-host91 status=OK` 06:41:52Z; `ROOT-OWNED=0`, `LOCKS=0` after |
| Units [`restic-forget.service`](restic-forget.service) / [`restic-forget.timer`](restic-forget.timer) — daily 06:40 UTC | `systemctl list-timers` shows the timer |
| Password copy readable by the repo owner: `/home/restic-push/.restic-pass` (0400, 999:988) | installed, `ls -la` verified |

### .92 (pusher)

| Change | Evidence |
|---|---|
| [`systema-restic-run.sh`](systema-restic-run.sh): both SFTP backup lanes get `--no-lock` | `run_complete overall_rc=0 systema=OK samudra=OK sftp=OK` |
| `size_sanity_check.py`: `snapshots` and `ls` get `--no-lock`; script now versioned in Uprava [`tools/backup_w2/push_side/size_sanity_check.py`](https://github.com/gasyoun/Uprava/blob/main/tools/backup_w2/push_side/size_sanity_check.py) | standalone run: 0 locks created, exit 0 |
| `restic-forget.timer` **disabled** (cannot prune through an append-only path) + "MOVED to .91" banner in the old script | `Removed …/timers.target.wants/restic-forget.timer` |

## Two defects found while landing this (both fixed)

1. **Lockless is mandatory on an append-only path.** restic's `backup`/
   `snapshots`/`ls` create lock files and remove them at exit; `remove` is now
   denied, so every lockless-omitted call left a stale lock that the next
   `forget` waited on for 30 minutes (`repo already locked`). Fix: `--no-lock`
   on every restic invocation from .92; `unlock --remove-all` at the head of the
   .91 retention script (safe — the only writer is a single lockless timer).
2. **Root-side prune re-poisons the repo** (§537 class): the first .91 forget
   ran as root and rewrote index files as `root:root 0400`; the restic-push
   reader then died with `Open /systema/index/…: permission denied` and the
   hourly lane failed. Fix: retention runs as restic-push; the existing
   `restic-repo-ownership-sweep.timer` remains the belt.

## Verification (live, 13-09-2026)

- Wrapper green → .91 forget OK (no root-owned files, no locks) → wrapper green
  again (acid test: retention no longer poisons the reader).
- `rm` probe denied; `put` allowed.
- Root pivot from .92 to .91 was probed and is **closed**
  (`root@192.168.200.91: Permission denied (publickey)`), so the SFTP account is
  the only .92→.91 path.
- Snapshots intact: 16 entries, newest `systema` 06:38:32Z, `samudra` 06:38:32Z.

## Rollback

```bash
# .91 — drop the append-only filter
ssh root@193.232.229.91 "sed -i 's/ -P remove,rmdir//' /etc/ssh/sshd_config.d/60-restic-append-only.conf && sshd -t && systemctl restart ssh"
# .92 — remove --no-lock and re-enable the local retention timer
ssh root@193.232.229.92 "sed -i 's/ --no-lock backup / backup /g' /usr/local/sbin/systema-restic-run.sh; systemctl enable --now restic-forget.timer"
```

## Residuals

1. **S3 immutable offsite** (Object Lock) stays on the MG-ruled ≈24-09-2026
   date — [OFFSITE_S3_ACTIVATION_2026-09.md](OFFSITE_S3_ACTIVATION_2026-09.md).
   It is the only leg that survives compromise of **both** servers.
2. Re-provision check: the append-only block is now in the managed
   `server_guards` manifest (`sshd/60-restic-append-only.conf`, severity
   critical), so `php artisan guards:verify` covers it; re-run the `sftp rm`
   probe after any server rebuild.

_Dr. Mārcis Gasūns_
