# ROADMAP_SUPPORT_AUTOMATION_2026_2027.meta.md — метадок о `ROADMAP_SUPPORT_AUTOMATION_2026_2027`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Компаньон-метадок к [`ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md) — фиксирует то, что вокруг документа (зачем он есть, кто его читает, что в нём ещё уязвимо, как он эволюционирует), не пересказывая его содержание.

## Subject

- **Документ:** [`ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md)
- **Назначение:** годовой узкий roadmap автоматизации поддержки (Q3 2026 → Q2 2027) — как за год снять с людей автоматизируемую долю потока рабочего чата «Отдел заботы».
- **Аудитория:** MG (владелец продукта, решения по флагам/приватности), AI-агенты, реализующие S-тикеты по одному за сессию, кураторы как конечные бенефициары.
- **Формат/контракт:** плановый документ на 12 PR-размерных тикетов (S1–S12) по кварталам, каждый с 4-метричной приоритизацией, переиспользуемым кодом, agent-шагами и метрикой успеха; не заменяет общий продуктовый roadmap, а детализирует одно направление.

## Provenance

- **Subject created:** 06-07-2026.
- **Metadoc authored:** 13-07-2026 (H887, Opus 4.8 `claude-opus-4-8`).
- **Next hardening:** none (плановая ревизия — по итогам квартального deflection-отчёта S7, Q4 2026).

## Ranked improvement backlog

| # | Улучшение | Зачем | Статус |
|---|---|---|---|
| 1 | Привязать S-тикеты к живым H###-хэндоффам за пределами S3/S4 (S5–S12 без ID) | Без хэндоффа тикет не подхватывается автономной сессией — теряется трассируемость | parked (хэндоффы минтуются по мере старта тикета, не заранее) |
| 2 | Отразить статус P0/PR #333 при каждом изменении (флаг включён/выключен) | Документ утверждает «уже в проде-коде, выключен флагом» — легко устаревает | parked (следить при включении S1) |
| 3 | Синхронизировать проценты категорий с ретро-анализом S12 | Все доли (38.5 %, 60 %-цель) — снимок 06-07-2026; поток дрейфует | parked (обновляется годовым ретро-анализом S12, Q2 2027) |
| 4 | Свести перекрытие с зонтичным ROADMAP_TELEGRAM_SCALING (WS1) в один явный разрез | Три роадмапа (support / telegram-scaling / prana) частично пересекаются — риск двойного учёта тикетов | parked (нужно согласование владельца) |
| 5 | Добавить чек-лист «блокер прод-миграций снят?» перед S2/S3/S6 | Документ ссылается на блокер деплоя в `.ai_state.md` — состояние вне документа | parked (зависит от инфраструктурного статуса) |

## Known limitations / caveats

- **Снимок данных.** Вся количественная база (16 962 вопроса, 38.5 % автоматизируемых, помесячные ряды) — это LLM-классификация на 06-07-2026. Проценты и приоритеты стареют по мере смены сезона и продукта; S12 (годовой ретро-анализ) — встроенный механизм ревалидации, но между ним цифры считать ориентиром, не фактом.
- **Планово-условный документ.** S-тикеты зависят от ручных шагов (заполнение `telegram_chat_id`), снятия блокера прод-миграций и решений MG по фича-флагам/приватности. Roadmap описывает намерение, а не гарантированную последовательность.
- **Границы охвата.** Только support-автоматизация; общий продукт, геймификация (PRANA) и вся Telegram-поверхность живут в отдельных документах — метадок их не дублирует.

## Intended use / known misuse

- **Для чего:** выбрать следующий S-тикет и понять его контекст (что переиспользуется, какая метрика успеха, какие зависимости); свериться с приоритизацией перед реализацией.
- **Неверное прочтение:** не читать проценты и доли категорий как текущую истину — это бейзлайн 06-07-2026; не начинать реализацию тикета в обход `support-subsystem-map.md` (ground truth по коду) и без снятия блокера прод-миграций; не трактовать «автоответ студенту» как разрешённый — принцип «бот готовит черновик, человек отправляет» действует по всему документу.

## Maintenance & sunset plan

- **Кто/что держит живым:** квартальные deflection-отчёты (S7 и аналоги) корректируют план; годовой ретро-анализ (S12) ревалидирует доли и рождает преемника — roadmap 2027–2028.
- **Архив/конец:** документ считается отработавшим по завершении периода (30-06-2027) либо при выпуске roadmap 2027–2028 — тогда помечается `superseded by` новым документом и уходит в историю; отдельные S-тикеты закрываются по мере merge своих PR.

## Deprecation status

`active`

## Related documents

- [`docs/ROADMAP_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md)
- [`docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md)
- [`PRANA_ROADMAP.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/PRANA_ROADMAP.md)
- [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md)
- [`docs/access-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/access-self-service-spec.md)
- [`Uprava/telegram-zabota-export/ANALYSIS.md`](https://github.com/gasyoun/Uprava/blob/main/telegram-zabota-export/ANALYSIS.md)
- [H243 handoff](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H243-Fable_Systema-Sanscriticum_support_automation_year_roadmap_06.07.26.md)

## Revision history

| Date | Event | Who |
|---|---|---|
| 13-07-2026 | metadoc created (H887) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
