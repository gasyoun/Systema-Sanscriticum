# Private «Мой хинди» SRS deck (H2445)

_Created: 14-08-2026 · Last updated: 14-08-2026_

## What ships

A student who already owns at least one Hindi programme lesson can add lemmas
from that lesson's transcript drills into a **private** deck named «Мой хинди»
(`my-hindi`). Review uses the existing koloda path
(`/dvaram/koloda/my-hindi`) when `srs.enabled` is already on. This handoff
never flips the global SRS switch and never writes the public Hindi Core deck.

| Piece | Role |
|---|---|
| [`HindiMySrsDeck`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/HindiMySrsDeck.php) | Deck factory, note type, lemma/gloss from a drill item, dedupe key |
| [`HindiMySrsDeckController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Student/HindiMySrsDeckController.php) | `POST` item id (drills) or lesson id (playlist) |
| Playlist / drills UI | «+ в колоду» — client posts ids only |
| Koloda | Existing `SrsController` lists the private deck to its owner |

Flag: `features.hindi_my_srs_deck` / `HINDI_MY_SRS_DECK`, default **OFF**.

Access: `HindiProgrammePlaylist::canAccessLesson` — same group / grant / paid-key
rules as [H2441](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2441-Grok_Systema-Sanscriticum_hindi-programme-playlist-one-list_08.08.26.md).
The feature does not write payments or grants.

## How a card is built

The client never posts lemma text. The server looks up the H2443 drill item
and copies:

| Field | Source |
|---|---|
| `lemma` | Drill item `lemma` (Hindi headword; vocab_pick inherits it from the source item) |
| `gloss` | Russian answer when it differs from the lemma, else the source sentence |
| `item_id` / `lesson_id` / `course_slug` | Provenance |

Dedupe key = normalised lemma. Translate `kitāb` and the vocab_pick of the
same word become one card.

## Enable on prod

Leave **OFF** until a human flips it after auto-deploy:

```
HINDI_MY_SRS_DECK=true
php artisan config:cache
```

Rollback: `false` + `config:cache`. No migration.

_Dr. Mārcis Gasūns_
