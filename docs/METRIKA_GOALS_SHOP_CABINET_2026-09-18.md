# Яндекс.Метрика: цели внешнего сайта и кабинета — план по рулингу MG 18-09-2026

_Created: 18-09-2026 · Last updated: 18-09-2026_

## Рулинг (транскрипция MG, 18-09-2026)

> «А кто нам мешает отслеживать внутри кабинета, тоже Яндекс Метрика. Не вижу противоречий, может там можем придумать цели и отслеживать их, проанализируя какие цели необходимо добавить и во внешнем сайте, и внутри кабинета.»

Отменяет дефолт «кабинет не тегируем». Неизменное условие: **никаких записей полных сессий залогиненных** (152-ФЗ, вебвизор в кабинете выключен).

## Текущее состояние (живые пробы + код, 18-09-2026)

| Поверхность | Состояние | Источник |
|---|---|---|
| Магазин (`layouts/shop`: `/k/*`, `/checkout/*`, `/online`) | счётчик `106964341` стоит, **`webvisor:true`, `clickmap:true`** — подтверждено curl `/online` | [partials/shop-metrika.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/shop-metrika.blade.php) |
| Главная `/` | **счётчика НЕТ** (curl дважды, с cache-bust — ноль `mc.yandex`) — дыра вершины воронки; vhost, отдающий `/`, на `.92` не найден — вопрос открыт | проба 18-09-2026 |
| Кабинет (`layouts/student`) | счётчика нет — grep по вьюхам | [layouts/student.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/student.blade.php) |
| Оплата | `reachGoal('payment_success')` на success-странице (двойной контур: session-id и shop-counter) | [payment/success.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/payment/success.blade.php) |
| Промо-лендинги | per-page `yandex_metrika_id` | [layouts/promo.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/promo.blade.php) |
| Реестр целей/событий | `funnel_events` с именами целей Метрики; `first_cabinet_action` — **`metrika_goal: null`** (сознательный остаток старого дефолта) | [config/analytics.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/analytics.php) |
| Кабинетная телеметрия | серверные события §4 в [ActivityEvent.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/ActivityEvent.php) + клиентский белый список 9 событий (POST `student.telemetry`) | [CabinetTelemetryController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Api/CabinetTelemetryController.php) |
| ⛔ Известный дефект эмиттеров | `cabinet.continue.click`, `course.tab.view`, `offer.impression/click` в гибриде ~в ноль при растущем трафике — эмиттеры потеряны (P0 [H4134](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RESULTS_CABINET_ADOPTION_KPI_POSTFLIP_H4134_20260905.md)) | CHANGELOG 1.90.61 |
| API-канал Метрики | `METRIKA_OAUTH_TOKEN` в `~/.secrets/metrika.env` **истёк (401 «Неавторизованный пользователь», проба 18-09-2026)** — management/stat API недоступны; env-счётчик верный (`106964341`). Недельный GSC+Metrika отчёт (launchd Mon 07:40) сейчас пишет `unavailable` | Uprava [tools/pull_metrika_weekly.py](https://github.com/gasyoun/Uprava/blob/main/tools/pull_metrika_weekly.py) |

Важно: H2378 философия сохраняется — `activity_events` остаётся first-party truth (гостей туда нельзя, `user_id NOT NULL`), Метрика — браузерный прокси и воронка для Директа.

## Архитектура (ответ на «не вижу противоречий» — их и нет при трёх условиях)

1. **Один счётчик** `106964341` и во внешнем контуре, и в кабинете — воронка `course_page_view → begin_checkout → payment_success → кабинетные цели` живёт в одном отчёте, аудитория не делится.
2. **Кабинет: `webvisor:false`, `clickmap:false`** в `ym(..., "init", {...})` на кабинетных страницах. Вебвизор пишется только там, где тег инициализирован с `webvisor:true` — то есть **ни одной записи сессий залогиненных**, что и требовал исходный запрет.
3. **Магазин: вебвизор только гостям** — `webvisor => !auth()->check()`. Сейчас `webvisor:true` пишёт и залогиненных студентов на `/k/*`, `/checkout/*` — та самая 152-ФЗ-чувствительность, закрывается одной строкой.
4. **Никакого PII в Метрике**: только имена целей (правило уже в shop-партиале); без `userParams`, без `setUserID`.
5. Цели — по готовым событиям `activity_events`/`schedule_join_clicks` (мост-bridge), новые таблицы и новые серверные писатели не нужны.

## Цели: внешний контур (счётчик 106964341)

**Уже в конфиге — проверить, что заведены в UI Метрики:**

| Цель | Поверхность | Механика |
|---|---|---|
| `course_page_view` | `/k/{slug}` (+ legacy 301) | reachGoal + URL |
| `begin_checkout` | `/checkout/{tariff}` | reachGoal + URL |
| `payment_success` | `/payment/success` | reachGoal |
| `card_impression` | `/online` каталог (CTA A/B) | reachGoal |
| `next_step_click` | `/online/next-step/*` | reachGoal |
| `sample_play` | `/k/{slug}/preview` | reachGoal |

**Новые:**

| Цель | Поверхность | Механика | Зачем |
|---|---|---|---|
| `lead_form_submit` | POST `/leads/store` → страница благодарности/flash ([LeadController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/LeadController.php), UTM пишется) | URL-цель на thanks-страницу или reachGoal во flash-view | **Директ-цель №1** (цена лида) |
| `telegram_click` | внешние ссылки `t.me/@rusamskrtam` | trackLinks + reachGoal на клик | контакт-канал после H1982 (email убит) |
| — фикс, не цель | главная `/` без счётчика | выяснить, кто отдаёт `/` (vhost/upstream), поставить тег или редирект-политику | атрибуция вершины воронки |

## Цели: кабинет (тот же счётчик, `webvisor:false`)

Имена целей = имя события с точками → подчёркивания (`lesson.mark.mastered` → `lesson_mark_mastered`).

### Tier 1 — launch (эмиттеры живые, доказаны H4134/кодом)

| Цель | Источник | Эмиттер | Зачем |
|---|---|---|---|
| `first_cabinet_action` | `FIRST_CABINET_ACTION` | сервер (свертка) — **flip `metrika_goal: null` → имя цели** | активация после оплаты |
| `cabinet_home_view` | `CABINET_HOME_VIEW` | сервер, [StudentController:224](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/StudentController.php) | MAU/ретеншн |
| `lesson_open` | `TYPE_LESSON_OPEN` | сервер, [TrackLessonViewJob](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/TrackLessonViewJob.php) | ядро обучения |
| `lesson_complete` | `TYPE_LESSON_COMPLETE` | сервер | прогресс |
| `lesson_mark_mastered` | `LESSON_MARK_MASTERED` | сервер | субъектный прогресс |
| `library_shelf_view` | `LIBRARY_SHELF_VIEW` | telemetry-мост (жив: 69/21 за 14 д) | «Записи» |
| `path_station_view` | `PATH_STATION_VIEW` | telemetry-мост (жив: 1678/16) | лестница чтения |
| `access_renewal_start` | `ACCESS_RENEWAL_START` | telemetry-мост | начало продления |
| `access_renewal_complete` | `ACCESS_RENEWAL_COMPLETE` | сервер, [PaymentTelemetryObserver](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentTelemetryObserver.php) | **повторная оплата = LTV** |
| `zoom_join_click` | `schedule_join_clicks` | мост; проверить объём перед созданием цели | живые занятия |

### Tier 2 — после починки эмиттеров (блокер: P0 H4134) или по объёму

`cabinet_continue_click`, `course_tab_view`, `offer_impression`, `offer_click` (апсейл-мост к Директ ROAS — заводить сразу после починки), `note_saved`, `material_download`, `library_rail_jump`.

### Tier 3 — НЕ тегируем

`session_timeout`, `reinvite_48h_sent` (операционные), `reading.token.lookup` (низкий объём), `visualdcs.*` (внешний тренажёр со своей телеметрией), `lesson.view.heartbeat` (шум).

## Implementation plan (по PR-ам)

1. `partials/cabinet-metrika.blade.php`: тот же `106964341`, init `{webvisor:false, clickmap:false, trackLinks:true, accurateTrackBounce:true}` + `window.shopReachGoal` — include одной строкой в `layouts/student.blade.php`.
2. `shop-metrika`: `webvisor => !auth()->check()`.
3. `config/analytics.php`: cabinet-цели в `funnel_events`; flip `first_cabinet_action.metrika_goal`.
4. Мост: клиентский telemetry-JS дублирует whitelist-событие в `reachGoal`; серверные события — `data-metrika-goal` маркеры на blade + лоадер 5 строк.
5. Главная `/`: найти отдающий vhost (на `.92` в `sites-enabled`/`conf.d` не найден — проверить второй хост/статический корень), повесить тег.
6. **Цели в Метрике:** проверить 6 старых целей, завести внешние 2 + кабинетные Tier 1 (~10 шт., тип «JavaScript-событие»). Канал: management API (`POST /management/v1/counter/106964341/goals`) после обновления `METRIKA_OAUTH_TOKEN` — тогда это agent-doable; без токена — UI Метрики (логины только MG).

## Verification

- curl кабинета под тест-студентом: в HTML `ym(106964341, "init"` с `webvisor:false`, строки `webvisor:true` нет; `activity_events` пишутся как раньше (контрольный `cabinet:probe` зелёный).
- curl `/online`: счётчик и `reachGoal` не регрессировали; гостевая сессия по-прежнему с вебвизором.
- Через 7 дней: кабинета-цели в Метрике сопоставимы с `activity_events` по тренду (Метрика ожидаемо ниже — adblock/браузеры); сравнивать тренды, не абсолюты.

## Риски / границы

- Adblock и iOS-антитрекинг съедают часть браузерных событий — truth остаётся в `activity_events`, Метрика — для воронки Директа.
- Tier 2 до починки эмиттеров H4134 будет читаться нулём — это дефект измерения, не отказ студентов.
- Доступ в аккаунт Метрики — только MG (создание целей в UI).

_Гасунс_
