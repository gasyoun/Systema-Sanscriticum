# VERIFICATION — koloda content pipeline wave-1

_Created: 31-07-2026 · Last updated: 31-07-2026_

Index: [PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md)

---

## Suite baseline

```bash
php artisan test tests/Feature/Srs tests/Unit/Srs
```

Must stay green after every deliverable. Full suite optional but preferred before merge.

---

## Acceptance per deliverable

| ID | Proof |
|---|---|
| W1-D1 | Import command exit 0 on fixture; total cards = 78; each card has form+label; re-run card count stable; one Feature test covers importer |
| W1-D2 | `--dry-run` prints counts; live run creates decks for all non-empty lessons; dual-read returns SRS cards; no duplicate cards on second run |
| W1-D3 | `GET /koloda?lang=hi` only Hindi public decks; `lang=sa` only Sanskrit; `lang=all` union; cabinet hub same filter |
| W1-D4 | After human export: validate.py 0; import creates expected level decks; `/koloda` lists new slugs |

## Manual smoke (prod/staging)

1. Incognito https://samskrte.ru/koloda — 200, decks visible.
2. Guest: flip ≤ `SRS_GUEST_TRIAL_CARDS`, see register wall.
3. Auth: open new content deck, grade Once/Good, stats increment.
4. Language tabs: Hindi Core appears under Хинди, not under Санскрит-only.

## Risks

| Risk | Mitigation |
|---|---|
| Memrise sunset before 6679375 export | Human @DO first; sibling exports already safe |
| Lesson JSON shapes diverge | front/back fallback; census before code |
| Watcher reverts WIP | watcher-safe-commit only |
| Filter hides decks with null language | Treat null as `sa` or `all`-only; document |

_Dr. Mārcis Gasūns_
