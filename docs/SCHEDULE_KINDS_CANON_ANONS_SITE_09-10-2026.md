# Виды расписания института — канон для анонсов и сайта (грилл 09-10-2026)

_Created: 09-10-2026 · Last updated: 09-10-2026_

## Зачем этот документ

MG 09-10-2026 заказал срез «какие виды расписания есть по нашему институту» — как основу для анонсов и сайта. Прогон через /grillme: домашка по коду Systema-Sanscriticum, живые пробы прода 09-10-2026, затем 4 карточки решений — все решены MG тем же проходом. Документ фиксирует канон и решения; исполнительная единица — [H6313](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6313-OxAlpha_Systema-Sanscriticum_schedule-kinds-kind-field-filters_09.10.26.md).

## Канон: 5 видов (рулинг MG 09-10-2026)

Все строки расписания живут в одной таблице `schedules` ([app/Models/Schedule.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Schedule.php)); виды различаются флагами и контекстом:

1. **Обычное регулярное занятие** — генерируется генератором расписания ([app/Services/Schedule/ScheduleGenerator.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Schedule/ScheduleGenerator.php)) по недельным слотам; нумерация «занятие N», недельный ритм (cadence).
2. **Обзорное занятие** — флаг `is_overview` (H4328, миграция `2026_09_07_100000_add_is_overview_to_schedules_table.php`): «Обзорное занятие (не в счёт N)», в полном Telegram-посте идёт отдельным блоком, в нумерацию уроков не попадает. Обзорное ≠ пробное.
3. **Пробное платное занятие** — курс с платной пробой закреплён за одним событием расписания (`trial_schedule_id`); когда занятие началось, проба авто-переезжает на следующее занятие той же группы (`trial:auto-advance`, флаг `TRIAL_AUTO_ADVANCE`; [app/Console/Commands/AdvanceTrialSchedule.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/AdvanceTrialSchedule.php)). В публичном фиде пробное уже выражено полями `bookable`/`book_token`.
4. **Разовое / нерегулярное** — «Открытые занятия и вебинары» без недельного ритма; на /raspisanie всегда сортируются вниз списка (H5548).
5. **Кураторская шкала уроков** — отдельная таблица `lessons` (`lesson_date`), нумерация «Урок N из total» по учебнику; живёт для групп без слотов расписания (семейство Кочергиной), страница `/raspisanie/kochergina` (H5233), живость группы = запись урока не старше 14 дней.

Вебинары — не отдельный вид строки: те же строки расписания с Zoom-полями (встреча, ссылка на запись) и посещаемостью.

## Поверхности расписания (проба прода 09-10-2026)

| Поверхность | Что это | Статус на 09-10-2026 |
|---|---|---|
| samskrte.ru/raspisanie | публичная страница (H4340), флаг `SCHEDULE_FULL_POST_ENABLED` | HTTP 200, жива |
| /widgets/schedule | встраиваемый виджет для samskrtam.ru/raspisanie | жив |
| /api/public/schedule | публичный фид: фильтры direction/teacher, кэш 5 минут | 275 строк, 16 курсов; признака вида в фиде нет |
| /raspisanie/kochergina | карточки живых групп Кочергиной с канвой (H5233) | жива |
| Telegram-пост расписания | полный пост по группам обучения (H4328, [PR #2421](https://github.com/gasyoun/Systema-Sanscriticum/pull/2421)), обзорные отдельным блоком | жив |
| iCal-фид студента | личный webcal по токену (Google Calendar Phase 1; [app/Http/Controllers/CalendarFeedController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/CalendarFeedController.php)) | жив |
| Кабинет студента | предстоящие/прошедшие занятия + напоминания | жив |

Не путать с (другие сущности, не расписание занятий): контент-календарь маркетинга `ContentCalendarSlot`, график платежей `RevenueSchedule`, штатное расписание (H3994).

## Решения грилла (4 карточки, ответы MG 09-10-2026)

| Вопрос | Решение MG |
|---|---|
| Какой список видов — канон | 5 видов (включая кураторскую шкалу уроков) |
| Поле `kind` в /api/public/schedule + бейдж на /raspisanie | Да, добавить |
| Какие виды становятся анонсами | Все 4 вида расписания занятий |
| Фильтры на /raspisanie | Направление + преподаватель |

Семантика `kind` для H6313: «обзорное» (`is_overview=true`), «разовое» (курс без недельного ритма — та же семантика irregular, что в сортировке [app/Http/Controllers/PublicSchedulePageController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicSchedulePageController.php)), «обычное» (остальное). Пробные полем `kind` не дублируются — в фиде они уже видны по `bookable`/`book_token`.

## Исполнение и стык с анонсами

- [H6313](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6313-OxAlpha_Systema-Sanscriticum_schedule-kinds-kind-field-filters_09.10.26.md) (executor OxAlpha): kind в фид + бейдж на странице + фильтры направление/преподаватель.
- Анонс-система ([AnonsPlacement](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/AnonsPlacement.php), [AnonsPublication](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/AnonsPublication.php) — каналы, креативы, UTM) видов расписания сейчас не различает. После посадки H6313 анонс-кампании отбирают поводы по `kind`: в анонс-план входят **все 4 вида** занятий (рулинг MG; напоминания по обычным занятиям остаются в чатах групп — `classes:remind-upcoming`, они не заменяют анонсы).
- Кураторская шкала (вид 5) — не строка фида: в анонсах и на сайте она выражается страницей /raspisanie/kochergina и канвой «Урок N из total».

## Основания (доказательства)

- Живые пробы прода 09-10-2026: GET samskrte.ru/raspisanie → 200 («Расписание занятий | Общество ревнителей санскрита»); GET /api/public/schedule → 275 будущих строк, 16 курсов, ключи строки: book_token, bookable, course, directions, end, group, novelty, start, teacher, time, title, weekday — поля вида нет.
- Код: [Schedule.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Schedule.php), [ScheduleGenerator.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Schedule/ScheduleGenerator.php), [AdvanceTrialSchedule.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/AdvanceTrialSchedule.php), [PublicSchedulePageController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicSchedulePageController.php), [PublicScheduleController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Api/PublicScheduleController.php), [CalendarFeedController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/CalendarFeedController.php).

_к.ф.н. М.Ю. Гасунс_
