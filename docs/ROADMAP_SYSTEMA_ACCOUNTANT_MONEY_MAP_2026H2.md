# Дорожная карта: карта денег и большая книга бухгалтера (2026H2)

_Created: 21-08-2026 · Last updated: 21-08-2026_

Слой волн плана [PLAN_SYSTEMA_ACCOUNTANT_MONEY_MAP_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ACCOUNTANT_MONEY_MAP_2026H2.md). Решения там.

## Волна 1 — карта + пробелы в той же книге

Один PR. Порядок — в [IMPLEMENTATION_SYSTEMA_ACCOUNTANT_MONEY_MAP_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_ACCOUNTANT_MONEY_MAP_W1.md).

| № | Поставка | Разблокируется |
|---|---|---|
| 1.1 | HTML-постер `docs/accountant-money-map.html` + PNG `docs/screenshots/accountant-map/money-map-1600.png` (без ФИО и сумм) | ничем |
| 1.2 | Часть 0 в [ACCOUNTANT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ACCOUNTANT_CABINET_GUIDE_RU.md): карта, три слоя, «три похожие кнопки» | 1.1 |
| 1.3 | Новые сценарии Части I: взаимозачёт · история выплат · возврат ученику · депозит/бронь | 1.2, параллельно по тексту |
| 1.4 | Блок «вне кабинета»: выписка, чек НПД, ИП — без выдуманных пунктов меню | 1.3 |
| 1.5 | Публичный [accountant-guide.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/accountant-guide.md) — одна строка, что карта в кабинете | 1.2 |
| 1.6 | Coverage-тест: якоря новых сценариев есть в md; страница `AccountantGuide` по-прежнему `RoleGate::finance()` | 1.3 |
| 1.7 | PDF-сборщик, если `docs/build-accountant-guide.php` уже есть — пересобрать на стенде, не класть PDF в публичный репо | 1.2 + 1.3 |

Готовность: md без живых ФИО/номеров выплат, карта видна в `/admin/accountant-guide`, шесть старых сценариев на месте, очередь разметки не скопирована.

## Волна 2 — кадры новых сценариев (не блокер карты)

После мержа волны 1, если на стенде есть Chrome и фикстуры.

| № | Поставка | Разблокируется |
|---|---|---|
| 2.1 | Playwright-кадры взаимозачёта / истории выплат / возврата в `storage/app/guide-shots/accountant/` | мерж волны 1 |
| 2.2 | Вставка `![](screenshots/accountant/…)` в новые сценарии | 2.1 |

Нет Chrome — волна 2 откладывается записью в журнал. Текст волны 1 не блокируется.

## Вне рамок

- Туториалы юнит-экономики, стоимости лида, фондов прибыли, инвестмодели.
- Должники / реактивация (гид куратора).
- Продукт банк-сверки / выгрузка в 1C.
- Правка RoleGate и денежных таблиц.
- Имя сотрудника и живые суммы в публичном репозитории.
- Переписывание [H3214 (Grok 4.6) — Wave 3: accountant operational book in /admin](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3214-Grok_Systema-Sanscriticum_accountant-cabinet-guide-illustrated_21.08.26.md) с нуля.

_Dr. Mārcis Gasūns_
