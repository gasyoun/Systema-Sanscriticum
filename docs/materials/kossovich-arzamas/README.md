# Kossovich Arzamas-material pack — «Россия и санскритский словарь»

_Created: 26-07-2026 · Last updated: 26-07-2026_

Source-of-truth pack for the second samskrte.ru longread in the genre of
[Arzamas materials/1100](https://arzamas.academy/materials/1100): «Россия и
санскритский словарь: Коссович против Бётлингка» — the Russian context of PWG
(the money, Uvarov's Latin, Kossovich's 1854 Sanskrit–Russian dictionary, the
Slavophile Sanskrit craze, Lamansky's 1879 feuilleton). Deliberately CUT from
the first piece ([pwg-arzamas](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/materials/pwg-arzamas),
DECISIONS L9 / FOLLOWUPS W2-5) and told separately.

Handoff: [H1696](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1696-Fable_Systema-Sanscriticum_kossovich-russia-arzamas-material_26.07.26.md) ·
Sibling: [H1620](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1620-Fable_Systema-Sanscriticum_pwg-arzamas-material_24.07.26.md)

## Contents

| File | Role |
|---|---|
| [SOURCE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/SOURCE.md) | Full essay, pure Markdown, 15 chapters as `## N.` headings |
| [meta.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/meta.json) | Article frontmatter: title, subtitle, excerpt, slug, meta_* |
| [FACTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/FACTS.md) | claim → source rows (K-*/KA-*) |
| [ASSETS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/ASSETS.md) | image rights table (B-*) |
| [bibliography.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/bibliography.md) | secondary works actually cited |
| [DECISIONS_LOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/DECISIONS_LOG.md) | autonomy defaults used (M-*) |
| [FOLLOWUPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/FOLLOWUPS.md) | queued tails (K-*) |
| [build_body.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/build_body.py) | SOURCE.md + meta.json → body.html |
| `body.html` | generated import payload — do not hand-edit |
| `rws/` | RuWritingStyles council reports |

## Rebuild / import

```bash
python docs/materials/kossovich-arzamas/build_body.py       # SOURCE.md -> body.html
php artisan materials:import-kossovich-arzamas              # upsert draft (is_published untouched)
php artisan materials:import-kossovich-arzamas --publish    # final, after gates green
php artisan test --filter=KossovichArzamasMaterialTest
```

`SOURCE.md` is deliberately HTML-free (org hook bans HTML in `.md`); figures are
Markdown images whose following italic line becomes the `<figcaption>`.

## Production runbook (if agent has no prod CLI)

1. Deploy the merged branch as usual.
2. On the server: `php artisan materials:import-kossovich-arzamas --publish`
3. **Then** re-import the FIRST piece so its ch. 16 link to this one goes live:
   `php artisan materials:import-pwg-arzamas` (idempotent, keeps published state).
4. Smoke: `https://samskrte.ru/s/rossiya-i-sanskritskiy-slovar` (TOC 15 items,
   4 images load) and the card on `https://samskrte.ru/online/materialy`.

_Dr. Mārcis Gasūns_
