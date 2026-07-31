# Agent manual — AnkiWeb → Systema native SRS

_Created: 30-07-2026 · Last updated: 30-07-2026_

How agents import a **public AnkiWeb shared deck** into Systema’s FSRS engine and
how students **run** it. Distilled from H1970 + media follow-up.

**Skill (full phase runbook):** [`/anki-srs-import`](https://github.com/gasyoun/claude-config/blob/main/commands/anki-srs-import.md)  
**Repo:** [Systema-Sanscriticum](https://github.com/gasyoun/Systema-Sanscriticum)  
**Tier:** Sonnet / Grok for Basic Front/Back decks; Opus/Fable if note templates are exotic.

---

## What “done” means

| Layer | Command / place | Result |
|---|---|---|
| Seed package | `database/seeders/data/anki_<id>/` | manifest + CSV + media (committed) |
| DB rows | `php artisan srs:import-anki …` | system deck + cards; media → public disk |
| Student UI | `SRS_ENABLED=true` + `/dvaram/koloda` | review loop with audio/image when present |

Seed files alone do **not** create studyable cards. Import + flag are required.

---

## Pipeline (repeat for any public deck id)

Work in a **worktree** (Systema is main-tree-guarded + watcher-afflicted).

```sh
# 1) Download (Playwright — curl will fail on AnkiWeb SPA)
python scripts/ankiweb_download_deck.py \
  --deck-id <ID> \
  --out storage/app/imports/anki_<ID>

# 2) Convert .apkg → export contract
python scripts/anki_apkg_to_srs_export.py \
  --apkg storage/app/imports/anki_<ID>/*.apkg \
  --out database/seeders/data/anki_<ID> \
  --course-id <ID> \
  --language <hi|sa|…> \
  --source-url https://ankiweb.net/shared/info/<ID>

# 3) Validate (exit 0 required)
python scripts/anki_export_validate.py database/seeders/data/anki_<ID>

# 4) Import into Laravel DB (+ publish media to public disk)
php artisan storage:link   # once per deploy if missing
php artisan srs:import-anki database/seeders/data/anki_<ID> --dry-run
php artisan srs:import-anki database/seeders/data/anki_<ID>
```

**Idempotent keys:** note type `anki_<ID>`, deck slug `anki-<ID>-level-N`, words by
romanization/devanagari. Re-import re-publishes media and refreshes `audio`/`image`
field paths without duplicating cards.

**Public disk layout:** `storage/app/public/srs/anki_<ID>/<basename>`  
**URL:** `/storage/srs/anki_<ID>/<basename>` (needs `storage:link`)

---

## How students run the deck

1. Env: `SRS_ENABLED=true` (default **true** since 30-07-2026; `false` darks the surface — see `config/srs.php`).
2. Log in as a student.
3. Open **`/dvaram/koloda`** (cabinet nav «Карточки» when flag is on).
4. Select the system deck (Anki decks sort with other system decks first).
5. Front: Devanagari + romanization + **audio** (autoplay when available).  
   Reveal: **image** (if any) + translation.  
   Grade: 1–4 / Again–Hard–Good–Easy (FSRS). Keyboard: Space/Enter reveal, 1–4 grade.
6. New cards/day: `SRS_NEW_PER_DAY` (default 20).

| URL | Role |
|---|---|
| `/koloda` | Public hub (guest trial) |
| `/koloda/{slug}` | Public per-deck trial |
| `/dvaram/koloda` | Cabinet hub / review |
| `/dvaram/koloda/stats` | Stats |
| `/dvaram/koloda/decks` | Student private decks (not required for system Anki decks) |

Legacy `/srs` and `/dvaram/srs` **301** → `/koloda` / `/dvaram/koloda`.

**Prod:** after deploy of the seed + this import code, run `srs:import-anki` on the
VPS (auto-deploy alone does **not** import).

---

## Pilot (Hindi Core 100)

| Field | Value |
|---|---|
| AnkiWeb | [454628379](https://ankiweb.net/shared/info/454628379) |
| Seed | [`database/seeders/data/anki_454628379/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/database/seeders/data/anki_454628379) |
| Deck slug | `anki-454628379-level-1` |
| Rows | ~202 notes + audio/images |

```sh
php artisan srs:import-anki database/seeders/data/anki_454628379
```

---

## Code map

| Piece | Path |
|---|---|
| Download | `scripts/ankiweb_download_deck.py` |
| Convert | `scripts/anki_apkg_to_srs_export.py` |
| Validate | `scripts/anki_export_validate.py` |
| Artisan import | `app/Console/Commands/ImportAnkiSrsDeck.php` (`srs:import-anki`) |
| Media helper | `app/Services/Srs/SrsMedia.php` |
| Review UI | `app/Livewire/SrsReview.php` + `resources/views/livewire/srs-review.blade.php` |
| Flag | `config/srs.php` → `SRS_ENABLED` |
| Fixture tests | `tests/Feature/Srs/ImportAnkiSrsDeckTest.php`, `SrsReviewTest`, `tests/Unit/Srs/SrsMediaTest.php` |

---

## Rights

Public AnkiWeb shared decks: **rights uncertainty is not a stop** (standing policy).
Record `source_url` in `manifest.json` + seed README. Do not treat committed seeds as a
redistributable product pack without a human ruling.

---

## Gotchas

1. **Playwright required** for AnkiWeb download — not curl.
2. **`storage:link`** or audio/image 404 in the browser.
3. **`SRS_ENABLED` default false** — import can run while UI stays 404.
4. **Romanization ≠ IAST** — stored in `iast` field for identity; do not “fix” with broken transcoder helpers.
5. **Watcher** on Systema — land commits atomically (`/watcher-safe-commit`).
6. Large media packages: slow push is normal.

---

## Verification (agent)

```sh
python scripts/anki_export_validate.py database/seeders/data/anki_<ID>
php artisan test --filter=ImportAnkiSrsDeck
php artisan test --filter=SrsMedia
php artisan test --filter=SrsReview
php artisan srs:import-anki database/seeders/data/anki_<ID> --dry-run
# with SRS_ENABLED=true and a student session: open /dvaram/koloda, pick deck, hear audio
```

_Dr. Mārcis Gasūns_
