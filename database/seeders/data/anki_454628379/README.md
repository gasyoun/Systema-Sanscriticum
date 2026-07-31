# Anki seed: Hindi Core 100 (AnkiWeb 454628379)

_Created: 30-07-2026 · Last updated: 30-07-2026_

Pilot package for the Anki → Systema native SRS pipeline (H1970).

| Field | Value |
|---|---|
| Source | [AnkiWeb shared deck 454628379](https://ankiweb.net/shared/info/454628379) |
| Title | Hindi Core 100 – Basic words (EN HI with audio) |
| Language | `hi` |
| Rows | 202 notes (level_01.csv) |
| Media | 202 audio + 202 images under `media/` |
| Contract | Same as Memrise export: `manifest.json` + `level_*.csv` |

## Import

```sh
php artisan storage:link   # once per environment if public/storage missing
python scripts/anki_export_validate.py database/seeders/data/anki_454628379
php artisan srs:import-anki database/seeders/data/anki_454628379 --dry-run
php artisan srs:import-anki database/seeders/data/anki_454628379
```

Creates system deck slug `anki-454628379-level-1` (idempotent). Publishes media to
`storage/app/public/srs/anki_454628379/` for the review UI audio/image player.

## Study (students)

1. `SRS_ENABLED=true` in `.env`
2. Log in → **`/dvaram/koloda`** → select the deck
3. Agent ops manual: [docs/MANUAL_AGENT_ANKI_SRS_IMPORT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_AGENT_ANKI_SRS_IMPORT.md)

## Regenerate from .apkg

```sh
python scripts/ankiweb_download_deck.py --deck-id 454628379 --out storage/app/imports/anki_454628379
python scripts/anki_apkg_to_srs_export.py \
  --apkg storage/app/imports/anki_454628379/*.apkg \
  --out database/seeders/data/anki_454628379 \
  --course-id 454628379 --language hi \
  --source-url https://ankiweb.net/shared/info/454628379
```

## Rights

Public AnkiWeb shared deck. Recorded source URL; rights uncertainty is not a stop for internal Systema import. Not a redistributable product pack without a human ruling.

## Skill

`/anki-srs-import` — reuse for other public decks.

_Dr. Mārcis Gasūns_
