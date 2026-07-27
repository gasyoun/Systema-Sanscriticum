# lila-games-manual.meta.md — метадок о `lila-games-manual.html`

_Created: 27-07-2026 · Last updated: 27-07-2026_

Метадок-спутник к [`lila-games-manual.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/lila-games-manual.html) — зачем документ существует, кто его читает и как он живёт, без пересказа содержания.

## Purpose

- **Документ:** [`lila-games-manual.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/lila-games-manual.html)
- **Зачем:** единое HTML-руководство по бесплатным играм **Лила** (`/lila/`): каталог, жесты, доступ гость/студент, ссылки для кураторов. Печать и чтение в браузере (тот же визуальный класс, что [`teacher-guide.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/teacher-guide.html)).
- **Не дублирует:** продуктовый roadmap и architecture (PLAN / ROADMAP / ARCHITECTURE online games) — только операторско-студенческий «как играть».

## Audience

1. Студенты и гости samskrte.ru
2. Преподаватели / кураторы (ссылки в чат, мини-план урока)
3. Поддержка — быстрый ответ «где игры и почему гейт»

## Provenance

- **Handoff:** HTML-мануал по запросу session 27-07-2026 (после H1710 + rename `/exercises` → `/lila/` PR #741)
- **Model:** Grok 4.5 (`grok-4.5`)
- **Sources of truth for facts:** live `public/lila/**` catalogue, `docs/student-manual.md` §12, `gate.js`, family READMEs

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Синхронизировать §4 при добавлении новой игры в `public/lila/` | Каталог в HTML иначе устаревает | parked |
| 2 | Опционально: ссылка на мануал с `/lila/index.html` (футер) | Один клик с каталога | parked |
| 3 | PDF/print-smoke (Chrome print → PDF) после крупных правок | Печать — заявленный use-case | parked |
| 4 | EN toggle / EN edition | План games D13 bilingual UI; мануал пока RU-only | parked |

## Limitations

- Не покрывает Filament «Воронка тренажёров» / `games:funnel` (админ-телеметрия).
- Не является API-доком `POST /api/games/event`.
- Wave-2+ invent catalogue (G-A/B/C) — в PLAN/ROADMAP, не в этом мануале, пока пакеты не shipped.

## Related docs

- [`student-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) §12 — краткая карта
- [`teacher-guide.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/teacher-guide.html) — визуальный twin (учебная панель)
- [`PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md)
- [`marketing/lila-telegram-posts.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/lila-telegram-posts.md)

## Revision history

| Date | Change |
|---|---|
| 27-07-2026 | v1.0 — initial HTML manual for `/lila/` post-rename |

_Dr. Mārcis Gasūns_
