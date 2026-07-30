# PWG Arzamas-material pack — «Петербургский словарь»

_Created: 26-07-2026 · Last updated: 30-07-2026_

> **⚠️ RETARGETED 30-07-2026 (MG, H1928): this piece publishes on the Arzamas
> website ONLY — never on samskrte.ru.** The prod article
> (`/s/peterburgskiy-slovar-pwg`, id 6) and its cover were deleted from
> samskrte.ru the same day; the `materials:import-pwg-arzamas` command and its
> test were removed from the repo. This pack stays as the editorial source of
> truth (incl. the H1862 referee verdicts); the handover copy for the Arzamas
> editors is `SOURCE.md` + the images. The «Rebuild / import» and «Production
> runbook» sections below are HISTORICAL — do not re-create the import path.
> Ch. 16's cross-link and ch. 20's CTA are samskrte-specific and need
> Arzamas-side adaptation.

Source-of-truth pack for the longread about PWG (Böhtlingk–Roth,
*Sanskrit-Wörterbuch*, «Большой Петербургский словарь») in the genre of
[Arzamas materials/1100](https://arzamas.academy/materials/1100).

Plan index: [PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md) ·
Handoff: [H1620](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1620-Fable_Systema-Sanscriticum_pwg-arzamas-material_24.07.26.md)

## Contents

| File | Role |
|---|---|
| [SOURCE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/SOURCE.md) | Full essay, pure Markdown, chapters as `## N.` headings |
| [meta.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/meta.json) | Article frontmatter: title, subtitle, excerpt, slug, meta_* |
| [FACTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/FACTS.md) | claim → source rows (ACCEPT A7) |
| [FACTS_REFEREE_VERDICTS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/FACTS_REFEREE_VERDICTS_2026.md) | hostile pre-publication referee (H1862): 136 verdicts, 14 corrections, 1 struck |
| [ASSETS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/ASSETS.md) | image rights table (ACCEPT A8) |
| [bibliography.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/bibliography.md) | secondary works actually cited |
| [DECISIONS_LOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/DECISIONS_LOG.md) | autonomy defaults used (PLAN §4) |
| [FOLLOWUPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/FOLLOWUPS.md) | council Minors + wave-2 hooks |
| [build_body.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/build_body.py) | SOURCE.md + meta.json → body.html (figures, CTA) |
| `body.html` | generated import payload — do not hand-edit |
| `rws/` | RuWritingStyles council reports (ACCEPT A9) |

## Rebuild / import

```bash
python docs/materials/pwg-arzamas/build_body.py     # SOURCE.md -> body.html
php artisan materials:import-pwg-arzamas            # upsert draft (is_published untouched)
php artisan materials:import-pwg-arzamas --publish  # final, after ACCEPT green
php artisan test --filter=PwgArzamasMaterialTest
```

`SOURCE.md` is deliberately HTML-free (org hook bans HTML in `.md`): figures are
Markdown images whose following line, when italic, becomes the `<figcaption>`;
`build_body.py` wraps them in `<figure>` and appends the soft CTA block.

## Production runbook (if agent has no prod CLI)

1. Deploy the merged branch as usual.
2. On the server: `php artisan materials:import-pwg-arzamas --publish`
3. Smoke: `https://samskrte.ru/s/peterburgskiy-slovar-pwg` (TOC ≥15 items, images load) and the card on `https://samskrte.ru/online/materialy`.

_Dr. Mārcis Gasūns_
