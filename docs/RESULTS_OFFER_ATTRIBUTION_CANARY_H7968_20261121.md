# Атрибуция «клик по офферу кабинета → подтверждённая оплата» — активация прода и t0-канарейка (H7968, GTD row `YMR`)

_Created: 21-11-2026 · Last updated: 21-11-2026_ · _к.ф.н. М.Ю. Гасунс_

**Хендофф-источник:** [H7968 (GLM lane)](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H7968-GLM_Systema-Sanscriticum_cabinet-offer-paid-attribution_10.11.26.md) · код доставки: [PR #4321](https://github.com/gasyoun/Systema-Sanscriticum/pull/4321) (merged 19-11-2026, default-OFF, «дремлет» на проде) · ченджлог: [PR #4344](https://github.com/gasyoun/Systema-Sanscriticum/pull/4344) · закрытие с отложенной канарейкой: [H8519](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H8519-GLM_Uprava_h7968-close-completion_20.11.26.md)

**Авторизация активации (human-gated):** Uprava GTD row `YMR` (minted 20-11-2026, [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md)) — триггер строки «MG authorizes activation (human-gated)» исполнился: MG назвал строку и дал «go» в чате 21-11-2026; активация выполнена под этой авторизацией по прецеденту делегированного флипа DEPLOY №52.

**Executor:** GLM 5.3 (opencode-go/glm-5.3) · прод-доступ SSH `root@193.232.229.92` · **только агрегаты, без экспорта ПДн** (COUNT/DISTINCT-скаляры; в отчёте нет сумм, контактов и student-level строк).

---

## 1. Активация (прод .92, T0 = 2026-11-21 03:34:29 UTC / 06:34:29 МСК)

| Шаг | Команда | Результат |
|---|---|---|
| 0. Пре-флип проверка | `sudo -u www-data php artisan cabinet:baseline --days=1` | «флаг cabinet_offer_attribution **OFF** — offer.purchase не пишется» (03:33 UTC); клики за сутки: 2, без атрибуции: 2 |
| 1. Бэкап | `cp -p .env .env.bak-2111-before-h7968-attribution` | `root:webteam 640`, 23978 B — снимок ДО ключа |
| 2. Флаг | `printf '\nCABINET_OFFER_ATTRIBUTION=true\n' >> .env` | строка 621, ровно одна; владелец/режим сохранены (`root:webteam 640`) |
| 3. Кэш конфига | `sudo -u www-data php artisan config:cache` | «Configuration cached successfully»; `bootstrap/cache/config.php` → Nov 21 03:34 |
| 4. Воркеры | `sudo -u www-data php artisan queue:restart` | все `horizon:work` пересобрались свежими PID от 03:34 (Nov-21); `horizon:status` — running |
| 5. Живой стейт | `php artisan cabinet:baseline --days=30` (шаг ниже) | «флаг cabinet_offer_attribution **ON**» — чтение кэшированного конфига тем же бутстрапом, что и приложение |
| 6. Смоук | `GET /` + `GET /login` | 200 / 200; `laravel-2026-11-21.log` байт-в-байт без новых записей после флипа |

Конфиг-ключи: [`features.cabinet_offer_attribution`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php) = `env('CABINET_OFFER_ATTRIBUTION', false)`; окно = [`cabinet_offer_attribution_window_days`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php) = `env('CABINET_OFFER_ATTRIBUTION_WINDOW_DAYS', 7)`; env задокументирован в [`docs/ENVIRONMENT_VARIABLES.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ENVIRONMENT_VARIABLES.md).

**Откат** (осознанный шаг человека, если решат выключить): `CABINET_OFFER_ATTRIBUTION=false` (или восстановить `.env.bak-2111-before-h7968-attribution`) + `php artisan config:cache` + `php artisan queue:restart`. Уже написанные к этому моменту `offer.purchase`-строки остаются безвредной телеметрией.

## 2. t0-канарейка: `php artisan cabinet:baseline --days=30` (03:34:30 UTC, флаг ON)

Окно отчёта: с 2026-10-22 06:34:29. Вывод команды дословно (агрегаты):

| Событие §4 | Источник | Всего | Уник. студентов |
|---|---|---|---|
| cabinet.home.view | activity_events | 5125 | 137 |
| cabinet.continue.click | activity_events | 162 | 72 |
| cabinet.homework.rework.click | activity_events | 0 | 0 |
| lesson.mark.mastered | activity_events | 82 | 38 |
| course.tab.view | activity_events | 672 | 106 |
| library.shelf.view | activity_events | 68 | 24 |
| library.rail.jump | activity_events | 1 | 1 |
| path.station.view | activity_events | 1450 | 19 |
| path.station.lit.impression | activity_events | 0 | 0 |
| offer.impression | activity_events | 3132 | 37 |
| offer.click | activity_events | 71 | 9 |
| offer.purchase | activity_events | **0** | **0** |
| access.renewal.start | activity_events | 56 | 36 |
| access.renewal.complete | activity_events | 3 | 2 |
| lesson.view.heartbeat | lesson_views | 189 | 85 |
| cabinet.live.zoom.click | schedule_join_clicks | 15 | 9 |

**Секция атрибуции (H7968), дословно:**

> Атрибуция офферов (H7968): флаг cabinet_offer_attribution **ON**. Правило связки: пользователь + оффер (course при наличии) + confirmed paid; окно «клик → подтверждение оплаты» — **7 сут. от подтверждения**.
>
> | Атрибутированных покупок (offer.purchase) | Уник. студентов |
> |---|---|
> | **0** | **0** |
>
> Клики офферов (offer.click) за окно отчёта: **71**, из них без атрибуции: **71** (ноль — тоже результат).
>
> Это НАБЛЮДАЕМЫЕ атрибутированные конверсии, НЕ причинный revenue lift: связка «пользователь+окно» причинность не измеряет, влияние оффера на выручку не измерялось.

**Чтение t0:** атрибутированных покупок **0** — ожидаемое честное t0-состояние, не сбой: `PaymentTelemetryObserver` ([PR #4321](https://github.com/gasyoun/Systema-Sanscriticum/pull/4321)) пишет `offer.purchase` только при живых переходах статуса в `Payment::PAID_STATUSES` ПОСЛЕ активации; бэкфилла нет. Знаменатели, из которых будет накапливаться атрибуция: 30-дневные `offer.impression` 3132 (37 уник.) и `offer.click` 71 (9 уник.). Нулевой unmatched на t0 — 71/71 — записан, а не пропущен.

Пре-флип контроль (та же команда до активации, 03:33 UTC): флаг OFF, offer.purchase не пишется; суточное окно: клики 2, без атрибуции 2 — инструмент считал и до флипа, флип не «включил счётчик», а включил СВЯЗКУ.

## 3. Premortem (написан до флипа, исполнен тем же проходом)

- **.env повреждается** → чистый append (inode и `root:webteam 640` сохранены; без `sed -i`-rename-ловушки) + бэкап-снимок ДО ключа + восстановление `cp` (владельца файла сохраняет). Ранний сигнал: `config:cache` exit code.
- **config:cache сбой/полукэш** → сигнал = artisan exit code + немедленный readout; восстановление: `.env` из бэкапа + повторный `config:cache`; floor = удалить `bootstrap/cache/config.php` (проверенный uncached-режим бокса, прецедент DEPLOY-рецептов).
- **Fail-silent класс: воркеры со старым конфигом** (Horizon держит конфиг в памяти) → `queue:restart` в том же батче + проверка свежих PID (подтверждено: 03:34). Без этого атрибуция тихо теряла бы webhook-подтверждения оплат.
- **t0 attributed = 0** → не отказ, а фиксируемое t0-состояние (H4934: записано, не пропущено).
- **152-ФЗ** → отчёт агрегатный по конструкции ([CabinetBaselineReport](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/CabinetBaselineReport.php): COUNT/DISTINCT, без сумм/контактов; кросс-семейный верификатор H7968 подтвердил «no amount/email fields»); личные данные не покидают бокс, в git идут только агрегаты.
- **Загрязнение платёжного потока** → спроектировано вне риска: обсервер read-only + never-throws self-guard, 8/8 focused-тестов + money-соседи зелёные на merge-коммите; лог после флипа чист.

Точка невозврата: отсутствует — полный откат одной строкой (флаг false + `config:cache` + `queue:restart`).

## 4. Остаток (минтован отдельной GTD-строкой в тот же проход)

- **Недельный readout ≈ 28-11-2026:** повторный `php artisan cabinet:baseline` на .92 — накопленные attributed purchases vs clicks incl. zero/unmatched + окно; фрейминг «наблюдаемое, не причинное». К этому моменту связка накапливает события ≥7 суток.
- Канарейка перечитывается при каждом следующем лендинге в денежной плоскости (дисциплина own-data-canary), если затрагивает оффер-поверхность кабинета.
