# Реализация волны 1: карта + пробелы книги бухгалтера

_Created: 21-08-2026 · Last updated: 21-08-2026_

План: [PLAN_SYSTEMA_ACCOUNTANT_MONEY_MAP_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ACCOUNTANT_MONEY_MAP_2026H2.md). Watcher-safe commit. Ветка от `origin/main`. Не править чужие гейты.

## S1 — положить постер

Файлы:

- [docs/accountant-money-map.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/accountant-money-map.html)
- [docs/screenshots/accountant-map/money-map-1600.png](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/screenshots/accountant-map/money-map-1600.png)

Источник уже снят 21-08-2026 (`npx playwright screenshot`, viewport 1680×1240). Копировать в репо. Не перерисовывать image model. Если HTML правят — переснять той же командой.

Зависимости: нет.

## S2 — Часть 0 в книге

Файл: [docs/ACCOUNTANT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ACCOUNTANT_CABINET_GUIDE_RU.md).

После «Как читать пометки» / перед «Вход в панель» вставить:

- заголовок «Часть 0. Карта денег»
- один абзац: три слоя, живые суммы не на карте
- `![](screenshots/accountant-map/money-map-1600.png)`
- короткий список «три похожие кнопки» (зарплаты / разметка / opex / возврат / взаимозачёт)

`MarkdownGuide` уже переписывает `src` через `SHOT_PREFIX`. Карта лежит в git под другим префиксом — либо расширить резолвер так, чтобы `screenshots/accountant-map/` брался из `docs/`, либо вставить относительный путь, который рендерер уже умеет для git-raw (как студенческий гид). **Не ломать** storage-путь кадров H3214. Если резолвер понимает только один prefix — добавить второй, явный, для map.

Зависимость: S1.

## S3 — четыре сценария в Части I

Тот же md, тот же регистр (шаги, пометки Безопасно / Видно студенту / Необратимо, «если пошло не так»). Учебные `1000 ₽`.

1. **Взаимозачёт** — `/admin/mutual-settlements`, `accounting()`. Сверить две суммы, зафиксировать акт. Не перевод.
2. **История выплат** — журнал, фильтр, экспорт. Ручная строка — исключение.
3. **Возврат ученику** — «Финансы», тариф возврата, сумма минус. Доступ не отзывается сам; это администратор.
4. **Депозит / бронь** — тариф брони/предоплаты. Не путать с opex и не гасить дважды.

Кадры этих экранов в волне 1 **не обязательны** (волна 2). Текст без картинки лучше выдуманного PNG.

Зависимость: S2 (оглавление обновляется вместе).

## S4 — вне кабинета

Короткий подраздел Части III или новая Часть II½:

- выписка отвечает на вопрос разметки
- чек НПД — не кнопка в «Зарплатах»
- ИП / налог / банк — статья opex «Налоги, банк, прочее»
- два кабинета — не проводите во второй

Зависимость: S3.

## S5 — публичная карта меню

[docs/accountant-guide.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/accountant-guide.md): в шапке одна фраза, что **карта денег** открывается в кабинете, не здесь. Без слоёв, без ФИО.

Зависимость: S2.

## S6 — тест

Расширить существующий coverage-тест книги (якоря `взаимозачёт`, `история выплат`, `возврат`, `карта денег`; файл PNG существует; `AccountantGuide::canAccess` по-прежнему `RoleGate::finance()`). Не сравнивать гейты очереди — это уже закрыто отдельным тестом H3084.

Зависимость: S3.

## S7 — журнал / changelog / метадок книги

Одна строка в changelog, bump Last updated у [ACCOUNTANT_CABINET_GUIDE_RU.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ACCOUNTANT_CABINET_GUIDE_RU.meta.md). Watcher-safe commit. PR в `main`.

Зависимость: S1–S6.

_Dr. Mārcis Gasūns_
