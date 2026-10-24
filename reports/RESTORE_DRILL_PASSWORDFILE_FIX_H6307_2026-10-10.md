# H6307 — Restore-drill password-file contract: fix + versioning + install verification

_Created: 2026-10-10 · Handoff: [H6307](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6307-OxAlpha_Systema-Sanscriticum_restore-drill-password-file-repair_09.10.26.md) · Claim: OxAlpha (opencode/z-ai/glm-5.3-flash)_

## Gap (reproduced live, read-only)

The monthly drill `/usr/local/sbin/restore_drill.sh` (mtime 05-09-2026, unversioned, hand-placed
on samskrte .92) authenticates only via the legacy literal `RESTIC_PASSWORD`, while the working
restic lane carries `export RESTIC_PASSWORD_FILE=` in `/root/.restic-systema.env` (census:
[MATURITY_BACKUPS_DR_2026-10-09](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/reports/MATURITY_BACKUPS_DR_2026-10-09.md), channel 5 = AMBER). Live probe 2026-10-10 (read-only SSH, secret values never printed):

- `/root/.restic-systema.env` contains `export RESTIC_PASSWORD_FILE=` (×1) and **zero** `RESTIC_PASSWORD=`.
- Live drill report `/home/hermes/brief/drill_latest.md` (run 2026-10-01 03:14:01Z, cron `14 3 1 * *`):
  `SKIPPED-NO-ACCESS — не нашёл REPO/PASSWORD…` — the monthly guarantee is dead while the hourly
  backup itself is green.
- Source revision (live, byte-exact): sha256
  `ce94da8a880959427561f628f175d1eb3b855d3bda1c5e351753bbd6dd854e29`.

## Changed

| File | What |
|---|---|
| [scripts/server_guards/sbin/restore_drill.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/restore_drill.sh) | Fixed drill under version control. Credential order: `RESTIC_PASSWORD_FILE` (env/env-file/run-script) → validated (exists, readable, non-empty, group/other-readable = loud WARN) → legacy literal `RESTIC_PASSWORD` fallback. Missing/unreadable configured file = loud `FAIL` + P1 page (backup lane itself is likely dead). restic command failures (snapshots / restore) = `FAIL` + P1. Scratch dir is its own `mktemp` with `trap cleanup EXIT INT TERM` — removed on every exit path, sibling `restic_drill.*` dirs never touched. Secret values never echoed/argv'd (restic reads env/file). lane_lib missing → loud fallbacks, drill never dies. Portable knobs (`RESTIC_DRILL_ENV`, `RESTIC_RUN_SCRIPT`, `RESTIC_DRILL_LANE_LIB`, `RESTIC_DRILL_TMP_BASE`, `RESTIC_DRILL_INCLUDE`) keep prod defaults; prod behaviour otherwise unchanged (same brief file, same hermes lane, same v1 files-scope — full scratch-DB restore stays v2, NOT this handoff). |
| [scripts/server_guards/sbin/test_restore_drill.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/test_restore_drill.sh) | Behavioral suite, 24 checks, temp-dir only, stubbed lane_lib + stubbed/real restic. Covers: file-contract green path; missing file; unreadable file (auto-skip as root); snapshots command failure; restore command failure; cleanup confined to this run (foreign sibling untouched); legacy literal contract; zero password leaks across all artifacts; with a real restic — encrypted fixture restores through the drill to a matching sha256 (SKIP-NO-RESTIC when absent). |
| [scripts/server_guards/manifest.psv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/manifest.psv) | Managed-file row `sbin/restore_drill.sh|/usr/local/sbin/restore_drill.sh|755|critical` — from here `server_guards_apply.sh` installs it and `php artisan guards:verify` verifies idempotently (presence + divergence). |
| [.github/workflows/schedule-guard-test.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/schedule-guard-test.yml) | New job running the suite on every push/PR touching `scripts/server_guards/sbin/**` + weekly. |

## Install manifest (expected state on the box)

- Managed file: `scripts/server_guards/sbin/restore_drill.sh` → `/usr/local/sbin/restore_drill.sh`, mode `755`, importance `critical`.
- Expected sha256 of the installed file (repo bytes, branch h6307-drain):
  `c89a99c25cfde4fd090d1f20b99686c2d6efbf549036d1ac9900077c880e9492`
- Scheduler command (already managed, unchanged):
  `14 3 1 * * /usr/local/sbin/restore_drill.sh` — source
  [scripts/server_guards/cron/root.crontab line 55](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/cron/root.crontab),
  re-applied to root's crontab by `server_guards_apply.sh` (three-lane rule 11-09-2026: cron lines are edited in the repo copy only).
- Idempotent verification after install: `php artisan guards:verify` (manifest-driven) + spot-check `bash /usr/local/sbin/restore_drill.sh` → expect the green FILE-contract line in `/home/hermes/brief/drill_latest.md`.

## Checks (before/after, exact commands and results)

- Failing-first / after, same suite, two targets:

```sh
# BEFORE — byte-exact live copy (/tmp/old_restore_drill_live.sh, sha ce94da8a…):
RESTORE_DRILL_UNDER_TEST=/tmp/old_restore_drill_live.sh \
  bash scripts/server_guards/sbin/test_restore_drill.sh
# → exit 1; "summary: pass=9 fail=15 skipped=0"

# AFTER — the fixed script:
bash scripts/server_guards/sbin/test_restore_drill.sh
# → exit 0; "summary: pass=24 fail=0 skipped=0" (macOS host, real restic via Homebrew)
```

- Real-restic case (case 9) ran locally with restic present: repo init → backup of a gz
  fixture → the drill restored it into its scratch and reported the matching sha256; report
  line: `GREEN — целостность сэмпла OK: dump.sql.gz sha256=…== fixture sha`. CI (ubuntu,
  no restic) will print `SKIP-NO-RESTIC` for case 9 — portable-only evidence there, labeled as such.
- Live prod evidence stays read-only: the SKIPPED-NO-ACCESS report above and the contract
  census. Nothing was installed on prod, no schedule changed, no live data touched.

## Unchanged

- Backup lane itself (`systema-restic-run.sh`, `.restic-systema.env`, timers, retention) — H6124's ownership.
- Cron schedule `14 3 1 * *` — already managed, already correct.
- S3 off-site leg and spatie-encryption gaps (RED channels) — out of scope, unchanged.

## Delivery status

- **Prepared code**: this PR (branch `h6307-drain`).
- **Reviewed preview**: independent security review — see `## Verifier` in the handoff file.
- **Live operation**: NOT done and NOT authorized by this brief. Installing on .92 =
  `deploy.sh` + `bash scripts/server_guards_apply.sh` + `php artisan guards:verify`, then the
  scheduled 1st-of-month run — a human/approval step per the production install boundary
  (same route as H4929/H4590 residuals).

## Risks

- The drill's legacy `eval` fallback for `export RESTIC_PASSWORD=…` from the run script is kept
  for prod parity; the value stays in env only (tests prove no leak). The preferred FILE path
  never evals.
- `critical` severity on the manifest row means a missing/diverged drill pages via `guards:verify`.
- If a future lane moves the creds env path, the drill follows `RESTIC_DRILL_ENV`, but the
  default is pinned to the prod path — drift would surface as SKIPPED-NO-ACCESS again (loud).

## Inspect

```sh
bash scripts/server_guards/sbin/test_restore_drill.sh          # 24/24
KEEP_TMP=1 bash scripts/server_guards/sbin/test_restore_drill.sh   # artifact tree kept
php artisan guards:verify                                       # after install on the box
```

_к.ф.н. М.Ю. Гасунс · GLM-5.3-Flash (z-ai), lane OxAlpha/opencode_
