_Created: 26-08-2026 · Last updated: 05-09-2026_

# corpora/

Masked frozen snapshots ONLY. Raw `dialog_*.txt` never lands here or anywhere
in this repo (PII policy:
[ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md)).

## Freeze protocol (H3527)

1. Copy the local `ors_faq/dialogs/dialog_*.txt` snapshot OUT of
   the shared ORS-FAQ tree into a private dir FIRST. It is **untracked, NOT
   gitignored** (H3563/H3703) — an untracked tree does NOT survive
   `git clean -fd`, and before H3703 nothing protected it. (Since H3703 the
   ORS-FAQ `.gitignore` covers `ors_faq/dialogs/`, so plain `git clean -fd`
   now spares it — but `clean -x`/`-X` still deletes it. Copy out first,
   always.)
2. `python tools/mask_corpus.py census --src <dir>` — compare vs the census.
3. `python tools/mask_corpus.py mask --src <dir> --out corpora/<kind>/<date>-masked.jsonl`
4. `python tools/mask_corpus.py validate --in <file>` — must print PASS (0 hits).
5. `python tools/mask_corpus.py sample --in <file> --out <checklist>.md`
6. A human signs the 50-message checklist with verdict CLEAN BEFORE commit;
   any suspected leak = STOP, do not commit.
7. Commit the masked jsonl + signed checklist; `wc -l` must equal the census.

## Census of record

| snapshot | kind | export date | dialogs |
|---|---|---|---|
| `eval/2026-07-05-masked.jsonl` | eval | 05-07-2026 | 2621 |
| `train/2026-08-22-masked.jsonl` | train | 22-08-2026 | 2776 |

Source: ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md; new snapshots = a new
freeze version, never an overwrite.

_Dr. Mārcis Gasūns_
