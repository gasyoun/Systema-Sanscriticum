# Kossovich Arzamas-material pack — «Россия и санскритский словарь»

_Created: 26-07-2026 · Last updated: 26-07-2026_

Source-of-truth pack второго samskrte.ru-лонгрида в жанре
[Arzamas materials/1100](https://arzamas.academy/materials/1100): **«Россия и санскритский
словарь: Коссович против Бётлингка»** — русский контекст Большого Петербургского словаря
(Уваров и латынь, славянофильская санскритомания, санскрито-русский словарь Коссовича
1854 г., фельетон о ста тысячах рублей, судьба торса по A43).

Handoff: [H1696](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1696-Fable_Systema-Sanscriticum_kossovich-russia-arzamas-material_26.07.26.md) ·
Первая заметка цикла: [pwg-arzamas](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/materials/pwg-arzamas)
(этот сюжет сознательно вырезан из неё — её FOLLOWUPS W2-5, DECISIONS_LOG L9).

## Contents

| File | Role |
|---|---|
| [SOURCE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/SOURCE.md) | Полное эссе, чистый Markdown, главы `## N.` (16 h2) |
| [meta.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/meta.json) | Фронтматтер статьи: title, subtitle, excerpt, slug, meta_* |
| [FACTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/FACTS.md) | claim → source (все строки verified/hedged) |
| [ASSETS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/ASSETS.md) | таблица прав на иллюстрации |
| [bibliography.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/bibliography.md) | реально использованные источники |
| [DECISIONS_LOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/DECISIONS_LOG.md) | автономные дефолты M1–M11 |
| [FOLLOWUPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/FOLLOWUPS.md) | хвосты wave-2 |
| [build_body.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/build_body.py) | SOURCE.md + meta.json → body.html |
| `body.html` | generated import payload — do not hand-edit |
| `rws/` | отчёты советов RuWritingStyles |

## Rebuild / import

```bash
python docs/materials/kossovich-arzamas/build_body.py       # SOURCE.md -> body.html
php artisan materials:import-kossovich-arzamas              # upsert draft
php artisan materials:import-kossovich-arzamas --publish    # final, after ACCEPT green
php artisan test --filter=KossovichArzamasMaterialTest
```

## Production runbook (if agent has no prod CLI)

1. Deploy the merged branch as usual.
2. On the server: `php artisan materials:import-kossovich-arzamas --publish`
3. Smoke: `https://samskrte.ru/s/rossiya-i-sanskritskiy-slovar` (TOC ≥12 items, images load)
   and the card on `https://samskrte.ru/online/materialy`.
4. После публикации — взаимная ссылка из первой заметки (FOLLOWUPS KW2-5).

_Dr. Mārcis Gasūns_
