_Created: 24-08-2026 · Last updated: 05-09-2026_

# Cold restore drill — samskrtam150 archive 2026-08-22 — H3390 (PASS, with documented adaptations)

_Created: 24-08-2026 · Executor: OxAlpha (`opencode/x-preview-f-free`) · Handoff: [H3390](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3390-OxAlpha_Systema-Sanscriticum_cold-restore-drill-scratch91_23.08.26.md)_

## Verdict

**PASS** — the 22-08 archive's DB dump restores cleanly and its data reconciles
against live **exactly under the id-fence**; the extracted payload boots to HTTP 200
after the documented adaptations below. Two handoff premises were wrong and are
corrected here rather than papered over.

## What was drilled

- Archive: `/var/backups/samskrtam150/2026-08-22-23-56-24.zip` (2 005 804 065 B)
- Host: `.91` (samskrtam50), everything under `/var/scratch-drill/cold-h3390/`
- Isolated MariaDB instance: own datadir, `--skip-networking`, port 3307 localhost,
  buffer 128 M — n8n's server untouched
- PHP on hub: **8.4.24** (Debian 13 default) — handoff assumed 8.3; Laravel boots fine

## Results

| Leg | Result | Detail |
|---|---|---|
| unzip of archive | ✅ | 25 s → `db-dumps/mysql-laravel.sql` (165 MiB) + `var/www/html/storage/app` (6 257 files, 2.1 GB) |
| DB import (scratch instance) | ✅ | exit 0, wall **9 s**, sandbox-line stripped defensively |
| Schema census | ✅ | tables=180 · migrations=389 (archive state) |
| Money control vs live, ID-FENCED ≤ MAX(id)=14204 | ✅ EXACT | cold: count=9242 · SUM=26 824 971.78 — live: count=9242 · SUM=26 824 971.78 (byte-for-byte parity incl. per-row integrity implied by exact sum+count over the fenced range) |
| Users reconciliation | ✅ explained | cold=1022 · live=1023 · delta=1 · live users created after the archive window = exactly **1** |
| Artisan boot (`about`, route:list) | ✅ | clean environment output; **480 routes** |
| HTTP smoke via `artisan serve :8021` | ✅ | `GET /login → 200` · `GET / → 200` |
| Teardown | ✅ | DROP + scratch tree removed (`TEARDOWN_OK`); nothing outside `/var/scratch-drill/` changed except apt php packages (handoff-prescribed input) |

Wall-clock context: full pipeline (extract→import→migrate→smoke) ran in ~5 min on the hub.

## Handoff premise corrections (findings)

1. **The spatie archive contains NO application code/vendor** — only `db-dumps/`
   and `var/www/html/storage/app`. "vendor/ ships inside the archive" was false for
   this artifact. The boot leg therefore used **today's deployed code from `.92`**
   (tar relayed off-box), merged with the ARCHIVED storage. A spatie-era archive
   alone can restore DATA but not a booting app — one more reason the restic lane
   (which snapshots code+env+nginx too) is the primary estate.
2. **Relaying prod code carries secrets**: `/var/scratch-drill` tar included
   `bootstrap/cache/config.php` with production APP_KEY/DB/Telegram credentials.
   The scratch was torn down (transient exposure only), but any future code relay
   must `--exclude=bootstrap/cache`. Not a git leak — that path is untracked.
3. **Stale shipped caches silently override `.env`**: the archived/bootleg config
   cache made requests use old values until `optimize:clear`; it also breaks
   `key:generate` ("No APP_KEY variable found" even when the line exists). Cold-boot
   recipe: recreate `storage/framework/{cache/data,views,sessions,logs}`, write a
   full explicit `.env` with an `APP_KEY=` placeholder, insert a throwaway
   `base64:` key manually, then `optimize:clear`.
4. **Code-vs-archive schema drift is real and harmless if migrated**: today's code
   needed 8 pending migrations (incl. `season_notifications`, `decay_enabled`) before
   pages render — run `php artisan migrate --force` on the scratch as part of the drill.
5. Archive dump header carries no stated counts; reconciliation went against live
   instead (stronger control anyway).

## Command transcripts

Full logs: `.91:/var/log/systema-backup/cold-drill-h3390-p{2..11}.log`
(final green pass = p11). Key excerpts are quoted inline above.

## Fence statement

Scratch database created and dropped on `.91`; isolated instance never touched
n8n's databases; live `.92` accessed read-only via the SELECT-only `drill_ro`
tunnel from H3178's estate; nothing pruned, forgotten or deleted anywhere.

_Dr. Mārcis Gasūns_
