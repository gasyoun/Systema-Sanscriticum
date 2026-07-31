# Memrise course 6679375 — «Продлёнка по санскриту»

_Created: 11-07-2026 · Last updated: 31-07-2026_

Seed package for [Memrise community course 6679375](https://community-courses.memrise.com/community/course/6679375/prodlenka-po-sanskritu/)
(H569 / H1993). Exported 31-07-2026 via `scripts/memrise_export.py` + human
`MEMRISE_SESSION`; validated with `scripts/memrise_export_validate.py` (exit 0).

| Field | Value |
|---|---|
| Course id | `6679375` |
| Name | Продлёнка по санскриту |
| Language | `sa` |
| Levels | 10 (`level_01.csv` … `level_10.csv`) |
| Rows | 166 (12+19+15+8+16+22+19+23+16+16) |
| Columns | `col_a`, `col_b` (mapped by importer) |

## Import (local / prod)

Requires `SRS_ENABLED=true`. Idempotent `firstOrCreate` — safe to re-run.

```sh
python scripts/memrise_export_validate.py database/seeders/data/memrise_6679375
php artisan srs:import-memrise database/seeders/data/memrise_6679375 --dry-run
php artisan srs:import-memrise database/seeders/data/memrise_6679375
```

Creates one `SrsDeck` per level + `DictionaryWord` + `SrsCard` rows. Students:
`/dvaram/srs` (or koloda URL when flag on).

Staff authoring (teachers + admins, H2049): Filament → **Допматериалы** →
**SRS — колоды** / **SRS — карточки**.

## Re-export

```sh
# Windows PowerShell — cookie only in env, never argv
$env:MEMRISE_SESSION = "<sessionid_2 from DevTools>"
python scripts/memrise_export.py --course 6679375 --out database/seeders/data/memrise_6679375
python scripts/memrise_export_validate.py database/seeders/data/memrise_6679375
```

Rights: community course seed for internal SRS; not a redistributable product pack
without a human ruling. Rights uncertainty is not a stop (standing policy 30-07-2026).

_Dr. Mārcis Gasūns_
