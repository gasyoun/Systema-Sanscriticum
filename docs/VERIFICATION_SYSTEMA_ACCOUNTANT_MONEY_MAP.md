# Приёмка: карта денег и большая книга бухгалтера

_Created: 21-08-2026 · Last updated: 21-08-2026_

План: [PLAN_SYSTEMA_ACCOUNTANT_MONEY_MAP_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ACCOUNTANT_MONEY_MAP_2026H2.md).

## Волна 1

| Поставка | Команда / факт | Pass |
|---|---|---|
| HTML-постер в репо | файл `docs/accountant-money-map.html` | есть, русский, без ФИО сотрудников и номеров выплат |
| PNG карты | `docs/screenshots/accountant-map/money-map-1600.png` | открывается, текст читается, совпадает со слоями плана |
| Часть 0 в книге | grep якоря «Карта денег» в `ACCOUNTANT_CABINET_GUIDE_RU.md` | картинка вставлена |
| Новые сценарии | якоря взаимозачёт / история выплат / возврат / депозит или бронь | четыре штуки |
| Вне кабинета | абзац про выписку и НПД | есть, без выдуманного URL экрана |
| Старые шесть | оглавление H3214 на месте | ни один не удалён |
| Очередь не скопирована | в md нет таблицы живых `payment_id` | pass |
| Гейт | `AccountantGuide::canAccess` = `RoleGate::finance()` | не сужен, не расширен |
| HTTP | GET `/admin/accountant-guide` под accountant/admin = 200; гость/студент = 302/403 | после деплоя |
| Публичный map | `accountant-guide.md` указывает в кабинет | без слоёв и сумм |

## Риски

| Риск | Что делать |
|---|---|
| `MarkdownGuide` не резолвит `screenshots/accountant-map/` (ждёт только storage) | Второй prefix в резолвере; не класть карту в storage |
| Playwright переснимет постер и съедет вёрстка | Не image-model; править HTML, снимать той же командой |
| Агент скопирует очередь разметки «для полноты» | Стоп решения 13 плана |
| Watcher сожрёт md до коммита | `/watcher-safe-commit` |

## Шлюз автономности

Волна 1 имеет архитектуру, шаги S1–S7, таблицу pass и контракт. Блокирующих развилок нет: все 15 решений записаны с дефолтом. Вердикт: **PASS**.

_Dr. Mārcis Gasūns_
