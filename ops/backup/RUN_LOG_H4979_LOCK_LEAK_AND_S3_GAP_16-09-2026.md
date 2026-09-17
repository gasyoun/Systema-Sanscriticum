# H4979 — restic lock-leak noise + S3 single-destination gap: diagnosed, not new work

_Created: 16-09-2026 · Last updated: 16-09-2026_

Handoff: [H4979](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4979-Sonnet_Systema-Sanscriticum_restic-sftp-lock-leak-and-single-destination_16.09.26.md).
Prior related commit on this repo the same day: [ddd47cd3](https://github.com/gasyoun/Systema-Sanscriticum/commit/ddd47cd3a243b03397ed65afc97768cf852319a7)
(prod health audit, recorded the same two findings without fixing them).

## What H4979 asked

Stop the SFTP lock-remove noise on `.91` and either provision S3 or record
the single-destination gap as an accepted risk.

## What was already true before this session touched anything

Both findings the handoff minted against are **already diagnosed and mostly
already fixed**, one day before H4979 was minted, by
[H4691](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H4691-Opus_Uprava_guard-breaker-retrofit-wave_14.09.26.md)
/ GTD 0b8+0b9 (14-09-2026, OxAlpha), full writeup:
[HOST_OPS_restic_stale_locks_watchdog_cron_14-09-2026.md](https://github.com/gasyoun/Uprava/blob/main/reports/HOST_OPS_restic_stale_locks_watchdog_cron_14-09-2026.md).

- **Root cause of the noise (confirmed by a fresh live repro today, 16-09):**
  restic 0.18.0's `backup` command does not honor `--no-lock` (upstream code
  path `openWithAppendLock` never reads `gopts.NoLock`) — every run still
  writes a lock file. The `.91` append-only sshd hardening from 13-09-2026
  (`60-restic-append-only.conf`, `-P remove,rmdir` — MG ruling, protects the
  whole snapshot history from a `.92` root compromise) then refuses the
  removal. Live reproduction today: a throwaway backup via the production
  wrapper args left `Remove(<lock/…>) failed: … permission denied` in the log
  every single time, regardless of whether `--no-lock` is placed before or
  after the `backup` subcommand.
- **This is structural, not a misconfiguration.** Eliminating the log line to
  a literal zero would require either weakening `-P remove,rmdir` (undoes the
  13-09 ransomware-hardening MG ruling — a security-class change, not in this
  handoff's edit scope and not decided here) or an upstream restic fix.
  Nobody has done either. The line is cosmetic: the run still completes
  (`snapshot … saved` happens before the failed removal), and the daily
  `restic-forget.timer` (06:40 UTC, runs `unlock --remove-all` locally on
  `.91` as `restic-push`, bypassing the sftp restriction entirely) resets the
  lock directory to empty every day — confirmed live: `/srv/restic/systema/locks`
  held 6 files at 09:42 UTC today, all created since this morning's 06:40
  forget run, none older.
- **What was NOT cosmetic — actual run failures — is already fixed.** 5 runs
  hit `overall_rc=1` in the 7 days before 14-09 07:06 UTC (the H4691/0b8/0b9
  landing time): a *different* mechanism — the daily `.91` Google-Drive fanout
  (`restic copy`, running as root) held a `root:root 0400` lock on the source
  repo for its ~10-minute copy window, and an hourly `.92` run landing inside
  that window couldn't even *read* the existing lock to check staleness
  (`Load(<lock/…>) permission denied` ×8 → `unable to create lock in
  backend` → both lanes FAIL). H4691 added `--no-lock` to the fanout's
  `restic copy` (the read-lock branch *does* honor the flag, unlike backup)
  and moved the hourly `.92` timer off the fanout window (`hourly` →
  `*:30:00`). Verified live today: **8 consecutive `overall_rc=0` runs**
  02:31–09:32 UTC, 16-09-2026, zero `sftp=FAIL`.
- **One residual real failure survived the 14-09 fix**: 14-09 16:32 UTC, same
  signature (`Load` failures → `unable to create lock in backend`), at a time
  that matches neither the fanout window (06:0x) nor the forget window
  (06:40). This means some *other* root-identity pusher into the same repo
  (the estate has three: the Wave-4 Windows lane, the one-off samskrtam
  forensic lane, and the Mac-secrets daily lane — all push as `root@` over
  plain SSH, not through the restricted `restic-push` account) left a
  root-owned lock file that the `restic-repo-ownership-sweep.timer` (10-minute
  cadence on `.91`) hadn't yet chowned back before the next hourly run landed.
  Zero occurrences since (41h clean as of this write-up). Closing this fully
  would mean tightening the sweep interval below 10 minutes or moving every
  other pusher onto the restricted account — both are changes outside this
  repo and outside H4979's edit scope (sshd/ownership on `.91` +
  `systema-restic-run.sh` on `.92` only). Left open, tracked below.

## What this session did

Live read-only diagnosis on `.92` and `.91` (root SSH to `.91` is direct and
unaffected by the 13-09 hardening — only the `restic-push` SFTP account is
chroot/append-only-restricted): confirmed the above with a fresh reproduction,
confirmed the 14-09 fix is holding (8/8 green runs today), found the one
post-fix outlier. No sshd config, no repo ownership, and no
`systema-restic-run.sh` change was made — the existing design is sound and
already covers the acceptance bar this handoff can reach without weakening
the append-only protection. Filed the residual root-owned-lock race as
[H4988](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4988-Sonnet_Systema-Sanscriticum_restic-other-pusher-lock-race_16.09.26.md)
rather than guessing at a fix under this handoff's tighter scope.

## S3 second destination

Not a gap needing a new decision: [OFFSITE_S3_ACTIVATION_2026-09.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/OFFSITE_S3_ACTIVATION_2026-09.md)
already records MG's 24-08-2026 ruling — approved, all repo-side tooling
built (`systema-restic-run.sh` reads `/root/.restic-s3.env` when present,
`systema-restic-s3-verify.sh --init` ready), activation gated only on MG's own
~20-minute console step (Yandex Object Storage bucket + Object-Lock + a
PutObject-only key) on or after ≈24-09-2026. Filling `.restic-s3.env` is a
credential-handling step this session does not perform (prohibited: pasting
S3 credentials is a human action per H4979's own edit scope).

Also worth noting: the mission's "single-destination" framing is stale by one
copy — [BACKUPS.md](https://github.com/gasyoun/Uprava/blob/main/BACKUPS.md)
records a live Google Drive fanout of the same repo since 29-08-2026, so today
there are already two copies (`.91` SFTP + Drive), not one; S3 Object-Lock is
the third, *immutable*, leg — the one that survives a compromise of both
existing copies' credentials, which is why it stays worth finishing on
schedule rather than being waved off as already-covered.

_Гасунс_
