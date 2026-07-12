# Поля атрибуции при регистрации — спецификация (H476)

_Created: 10-07-2026 · Last updated: 10-07-2026_

Спека по [CUSTDEV_NEXT_FOUR_DOCS_RULINGS_2026H2.md §3.3](https://github.com/gasyoun/Uprava/blob/main/custdev/CUSTDEV_NEXT_FOUR_DOCS_RULINGS_2026H2.md):
закрыть дыру «атрибуция обрывается на CRM» (возраст 7,1 %, e-mail 19,5 %, источника прихода
нет вовсе — [CUSTDEV §2](https://github.com/gasyoun/Uprava/blob/main/custdev/CUSTDEV_2026.md)).
Handoff: [H476](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H476-Sonnet_Systema-Sanscriticum_custdev_attribution_fields_spec_10.07.26.md).
Исполнено Fable 5 (`claude-fable-5`) 10-07-2026 — MG расширил модельный фильтр на эту сессию.

## 1. Что уже существовало (prior art — H267, не пересобиралось)

[PR #347](https://github.com/gasyoun/Systema-Sanscriticum/pull/347) (07-07-2026) уже закрыл
бо́льшую часть §3.3:

| Требование §3.3 | Состояние до H476 |
|---|---|
| UTM-захват | ✅ [`CaptureAttribution`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/CaptureAttribution.php) — UTM/click_id/реферер с первого визита, сессия |
| Связь «канал → пользователь → платеж» | ✅ [`AttributionService::applyToNewUser`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/AttributionService.php) во всех 6 ветках guest-регистрации + Lead-мэтчинг по email + [`ChannelConversionReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reports/ChannelConversionReport.php) |
| Год рождения | ✅ частично — необязательное поле на чекауте (`applyBirthYear`) |
| **Источник прихода (самоотчет)** | ❌ отсутствовал — единственный настоящий разрыв |

UTM видит только размеченные ссылки. Сарафан, органический поиск и «давно подписан на
Telegram» приходят без меток — это и есть слепая зона, которую закрывает самоотчет.

## 2. Дельта H476 (этот PR)

- **`users.signup_source`** — nullable `string(32)`, аддитивная миграция.
- **`AttributionService::SIGNUP_SOURCES`** — белый список: `telegram · youtube · vk ·
  search · friend · article · other`; **`applySignupSource()`** молча игнорирует всё вне
  списка (поле не должно уметь ломать регистрацию).
- **Один общий partial** [`partials/signup-source-select.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/signup-source-select.blade.php)
  («Откуда вы о нас узнали?», необязательный select, темная/светлая тема) — подключен в
  **двух** местах: guest-блок чекаута (рядом с уже существующим годом рождения) и
  guest-блок trial-модалки.
- Валидация: `nullable + Rule::in(...)` в `PaymentController` и `StoreTrialRequest`.

## 3. Чего в PR сознательно НЕТ (ограничение «каждое поле стоит конверсии»)

- **Год рождения НЕ добавлен в trial-модалку.** Trial — верх воронки; там добавлено ровно
  одно необязательное поле (источник). Год рождения остается на чекауте, где интент выше.
- **Deposit / PayPal-claim / marathon / social-auth формы не тронуты** — deposit и PayPal
  минимизируют трение при передаче денег; марафон владеет своей воронкой (H440); у
  social-auth формы нет.
- **Дашборда нет — сознательно** (§3.3 спеки-источника): данных еще не существует; дашборд
  появится после первых недель сбора, из committed-выгрузки, по правилу §2.1.

## 4. ПДн (B16)

Год рождения — персональные данные; собирался и до этого PR на чекауте, где форма уже
ссылается на политику конфиденциальности (согласие оформлено в существующем guest-блоке).
`signup_source` — маркетинговый самоотчет из фиксированного словаря без свободного текста;
хранится при аккаунте на тех же основаниях, что UTM-атрибуция H267, новых категорий ПДн не
вводит ([B16](https://github.com/gasyoun/Uprava/blob/main/dpo/ip-gasuns/B_lna/B16_pdn.md)).
Свободного поля «другое (уточните)» нет — намеренно: свободный текст = и трение, и ПДн-риск.

## 5. Деплой

Миграция аддитивная, nullable, без backfill — встает в X0-очередь деплой-блокера вместе с
миграциями H267/H204. До `php artisan migrate` поле в формах есть, но записи падать не
будут только после миграции — PR должен ехать в прод в одном окне с ней (стандартная
X0-очередь).

## 6. Как это читать через месяц

Первый срез: `SELECT signup_source, COUNT(*) ... GROUP BY signup_source` против
`utm_source` тех же пользователей — расхождение покажет, сколько канала UTM не видел.
Дальше — дашборд по правилу §2.1 (скрипт из committed-выгрузки, только агрегаты).

_Dr. Mārcis Gasūns_
