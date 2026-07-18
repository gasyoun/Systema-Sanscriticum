# Memrise course 6679375 export — expected shape

_Created: 11-07-2026 · Last updated: 18-07-2026_

Destination for the **P0 export** of Memrise course
[6679375 «Продлёнка по санскриту»](https://community-courses.memrise.com/community/course/6679375/prodlenka-po-sanskritu/)
(H569, [ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md](../../../../ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md)).
This directory is **empty on purpose** — P0 (the export itself) has not run yet.
Neither the pull nor the credential can be done by an agent unattended; a human
must run one of the two paths below.

## Primary path — the scripted runner (H1146)

```sh
MEMRISE_SESSION=<cookie value>  python scripts/memrise_export.py \
    --course 6679375 --out database/seeders/data/memrise_6679375
python scripts/memrise_export_validate.py database/seeders/data/memrise_6679375
```

`MEMRISE_SESSION` is a Memrise session cookie, read only from the environment
(never pass it as an argument — it would leak into shell history). The
credential must come from a human with a Memrise login; an agent session
cannot obtain or use one. The runner is **untested against live Memrise**
(no agent-held credentials) and may need adjustment on first human run if
Memrise's response shape differs from the documented API — if it fails
outright, fall back to CourseDump2022 below. Either way, always run
`memrise_export_validate.py` on the output before importing — it checks the
manifest/CSV contract with no network and no credentials, so a botched
export is caught immediately rather than at import time (possibly after the
course is gone).

## Fallback path — CourseDump2022

The [Eltaurus-Lt/CourseDump2022](https://github.com/Eltaurus-Lt/CourseDump2022)
Chrome extension, run by a human with a Memrise login. Its output still needs
to be reshaped into the `manifest.json` + level-CSV contract below before the
importer can read it; `memrise_export_validate.py` checks that reshaped
output the same way it checks the scripted runner's.

**P1 (the importer, `php artisan srs:import-memrise`) is already built and
tested** against a fixture at
[`tests/fixtures/memrise_sample/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/fixtures/memrise_sample) —
see [`ImportMemriseSrsDeckTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Srs/ImportMemriseSrsDeckTest.php).
It is manifest-driven, not hardcoded to any assumed Memrise column layout — so
whoever runs P0 does not need to match this exactly; they need to produce
**a `manifest.json` + one CSV per level in this directory**, then run:

```sh
php artisan srs:import-memrise database/seeders/data/memrise_6679375 --dry-run
php artisan srs:import-memrise database/seeders/data/memrise_6679375
```

## `manifest.json` contract

```json
{
  "course_id": "6679375",
  "course_name": "Продлёнка по санскриту",
  "source_url": "https://community-courses.memrise.com/community/course/6679375/prodlenka-po-sanskritu/",
  "language": "sa",
  "exported_at": "YYYY-MM-DD",
  "columns": {
    "devanagari": "<the CSV header name that holds Devanagari script>",
    "iast": "<the CSV header name that holds IAST transliteration>",
    "cyrillic": "<the CSV header name that holds Cyrillic transcription, if the export has one>",
    "translation": "<the CSV header name that holds the RU/EN meaning>",
    "alt_answers": "<the CSV header name that holds pipe-separated alternate accepted answers, if any>"
  },
  "levels": [
    {"index": 1, "name": "<level display name>", "file": "level_01.csv"},
    {"index": 2, "name": "<level display name>", "file": "level_02.csv"}
  ]
}
```

- The `columns` map is the whole point: the importer reads each CSV's real
  header row and looks up columns **by name** via this map — it never assumes a
  fixed column order or a guessed header spelling. Whatever CourseDump2022 (or
  the API fallback) actually names its columns, just point the map at them.
- Any `columns` entry can be omitted if the export has no such data (e.g. no
  `cyrillic` column) — the importer skips it, no error.
- `alt_answers`, if present, should be `|`-pipe-separated inside its CSV cell
  (e.g. `truthfulness|истина`) — the importer explodes it into a list, meant to
  feed the P2 typing-mode fuzzy match.
- **Keep the media files too** (audio/images), even though audio is deferred
  (D4) — store them alongside the CSVs in this same directory; re-fetching after
  Memrise's community-course sunset may be impossible. The importer does not
  touch media yet (that's P6); it only needs `manifest.json` + the level CSVs.
- One `manifest.json` per export; if a separate Hindi course is exported on the
  same P0 pass (per the roadmap's "also export any Hindi course" note), give it
  its own directory + `manifest.json` with `language: "hi"`.

_Dr. Mārcis Gasūns_
