# H6320 — Backrest UI over restic on vps92: deploy run log

_Created: 09-10-2026 · Last updated: 10-10-2026_

Handoff: [H6320](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6320-OxAlpha_Systema-Sanscriticum_backrest-restic-drill-ui_09.10.26.md) (executor: GLM 5.3 (GLM-5.3-Flash), ZCode lane; filename tier OxAlpha retained as provenance). Continues the 05-10 prod-readiness audit's backup findings; backup-semantics context in [RUN_LOG_H4979_LOCK_LEAK_AND_S3_GAP_16-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/RUN_LOG_H4979_LOCK_LEAK_AND_S3_GAP_16-09-2026.md) and [RUN_LOG_H4988_OTHER_PUSHER_LOCK_RACE_16-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/RUN_LOG_H4988_OTHER_PUSHER_LOCK_RACE_16-09-2026.md).

## What was deployed (live on vps92 = samskrtam150, 100.85.73.83, 2026-10-09 ~21:04–21:20 UTC)

- garethgeorge/backrest v1.14.1 single binary at `/usr/local/bin/backrest`, systemd unit [backrest.service](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/backrest.service) (enabled, `Restart=on-failure`), pinned to the system restic via `BACKREST_RESTIC_COMMAND=/usr/bin/restic` (0.18.0 — same binary the hourly jobs use; backrest warns it wants ≥0.19.1, warn-only).
- Listener: `127.0.0.1:9898` (backrest default). vps92's tailscaled runs userspace netstack (no `/dev/net/tun`), which forwards tailnet connections to localhost — so the UI is reachable as `http://100.85.73.83:9898/` from the tailnet and from nowhere else. No 0.0.0.0 binding anywhere.
- Auth: single user `mg`, bcrypt (`passwordBcrypt` stored as base64 of the ASCII `$2b$` hash — backrest's `checkPassword` base64-decodes the field before bcrypt-compare; a raw hash string silently fails as "invalid password"). Credentials live in `/root/.backrest-ui-credentials` (0600) on vps92 — deliberately NOT committed.
- Config: `/root/.config/backrest/config.json` (committed as a template with placeholders: [backrest-config-template.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/backrest-config-template.json)). Restic password never leaves the env: `RESTIC_PASSWORD_FILE=/root/.restic-pass` passed via repo env. The sftp identity is routed through root's `~/.ssh/config` `Host 192.168.200.91` stanza (User `restic-push`, `IdentityFile /root/.ssh/id_restic_push`) — backrest splits `repo.flags` on whitespace, so a quoted `sftp.command` flag cannot survive; the ssh_config route is the deterministic path.
- Existing repo attached read/restore-only: `systema-sftp` = `sftp:restic-push@192.168.200.91:/systema`, guid `4dabb1b9…cd260a`. 97 snapshots listed through the UI API on first attach. No backup plan was created — the hourly `restic-backup.timer` stays the only backup writer (no competing schedule, no changes to backup semantics).
- Telegram alerting: repo hook `CONDITION_ANY_ERROR` → `/usr/local/sbin/backrest-hook-notify.sh` (committed: [backrest-hook-notify.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/backrest-hook-notify.sh)) → existing `hermes_notify.sh page` sink. Proven live on 21:16 UTC: the first check attempt errored and backrest logged `operationRunHook status: STATUS_SUCCESS` — the alert left the box through the real relay.

## Acceptance evidence

- **UI 200, authed, Tailscale-only**: from the Mac over tailscale — unauthenticated `GetConfig` → **401**; login page → **200**; authenticated `GetSummaryDashboard` (Bearer JWT from `/v1.Authentication/Login`) → **200** with `repoSummaries`. On-box `ss -tlnp` shows the only listener on `127.0.0.1:9898`.
- **Restore drill**: full restore of snapshot `994958bf` (systema lane, 20:34:30 UTC) to `/tmp/backrest-drill-H6320/` — 16,144 files/dirs, 5.602 GiB in 15 s; 3 sampled files sha256-matched against independent `restic dump` reads (MATCH ×3), plus a supplementary 3 non-empty-file sample (MATCH ×3; the original "smallest file" sample was a 0-byte lock file and was re-sampled). All three primary samples also byte-match their live on-disk sources. Transcript committed verbatim: [reports/backrest-drill-h6320-2026-10-09.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/reports/backrest-drill-h6320-2026-10-09.md). The `/tmp` (tmpfs) copy was removed after evidence capture.
- **Health-check schedule**: `checkPolicy` cron `7 7 * * 0` `CLOCK_UTC` (Sundays 07:07) — see the slot rationale below. Healthcheck row **GREEN**: check operation 11 `STATUS_SUCCESS` at 21:44 UTC 09-10-2026, after a one-time sanctioned `restic unlock --remove-all` sweep on `.91` (same mechanism as the daily `restic-forget.timer`; 40 stale locks removed, lock dir empty, no backup in flight — the 21:30 hourly run had completed at 21:30:52). API evidence committed: [reports/backrest-ops-green-h6320-2026-10-09.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/reports/backrest-ops-green-h6320-2026-10-09.json) (ops 7 ERROR → 8 RunHook SUCCESS → 11 SUCCESS).

## Finding: restic check needs an exclusive lock the append-only channel cannot clear

The first scheduled check failed with `unable to create lock in backend: repository is already locked` — by a **stale** lock: the 20:34:33 hourly run completed OK at 20:34:35 but could not remove its lock (the `.91` sftp hardening `60-restic-append-only.conf` `-P remove,rmdir`, MG ruling 13-09-2026, denies lock removal over sftp — diagnosed in H4979). `restic check` takes an **exclusive** lock and refuses to run under any existing lock, and `restic unlock` over sftp is denied by the same hardening. So any check run between hourly windows still dies on the last run's stale lock.

The estate's sanctioned reset is the daily `restic-forget.timer` 06:40 UTC running `restic unlock --remove-all` **locally on `.91`** as `restic-push` (bypassing sftp restrictions; H4979). Within H6320's scope that mechanism was used once by hand today (17:4x→21:44 UTC window: 40 stale locks removed, then check op 11 went `STATUS_SUCCESS`) — no hardening was weakened and no backup semantics changed.

**Schedule-offset rationale (the "written schedule-offset finding" the handoff asks for):** check cron **Sunday 07:07 UTC** — after the 06:40 `.91` lock sweep (stale locks empty), after the Wave-6 Mac-secrets lane (05:00 + chat-stream extension ~05:0x per the H4988 pusher table), clear of the Wave-4 Sunday 03:00/03:30 lanes, and 23 minutes before the next `*:30` hourly backup window. `restic check` on this repo takes minutes; 23 min headroom keeps the exclusive lock clear of the hourly writer.

- Dependency to watch: the Sunday slot is only green **because** the 06:40 sweep empties the lock dir first. If that sweep ever breaks, the weekly check fails and the Telegram hook pages — the alert is the designed tripwire. The follow-up decision (human, MG) would be whether `.91` gains a pre-check unlock step in the 07:0x window; that touches the hardened channel and is deliberately NOT done here (scope fence: no backup-semantics changes; H6306/H3371 untouched).
- Watch item: first scheduled run is **Sunday 12-10-2026 07:07 UTC** — it exercises the full chain (sweep → exclusive lock → green row) with no human in the loop.

## Gotchas hit during deploy (for the next lane)

1. backrest v1.14 stores config in `config.json` (written on first `SetConfig`), but requires `instance` to be set — without it the orchestrator FATAL-loops on `instance_id is required` before binding the port. Set `instance: "vps-samskrte92"`.
2. `auth.users[].passwordBcrypt` must be **base64 of the ASCII bcrypt hash**, not the hash string (source: `internal/auth/auth.go checkPassword` base64-decodes before compare).
3. `repo.flags` entries are whitespace-split — use an ssh_config Host stanza for sftp identity instead of `sftp.command` flags. `RESTIC_SFTP_COMMAND` in `repo.env` did not propagate either.
4. `SetConfig` requires repo `guid` unless `auto_initialize` — take it from `restic cat config --json` field `id`.
5. jq is not installed on vps92; python3 (with bcrypt) is.

_Dr. Mārcis Gasūns_
