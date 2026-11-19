# Решения грилла MG: календари всего и вся — синхронность расписаний

_Created: 10-10-2026 · Last updated: 10-10-2026_

Грилл `/grillme` 10-10-2026 по заказу MG («Насколько развиты календари? Если меняется расписание — апдейт календарей проходит? Если меняется вручную календарь — расписания меняются синхронно?»). Домашняя работа Phase 0: [GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md) (truth-pass H3072 19-08-2026), живые пробы прода (маршрут `/calendar/feed/{user}/{token}.ics` отвечает 404 на битый токен — фид жив; `/api/public/schedule` 200), проверка `app/Observers/` (каскада Course→Schedule нет). Ответы MG зафиксированы в тот же проход.

## Контекст прохода (что показала домашняя работа)

Статус на 10-10-2026: Phase 1 из 4 отгружена — студенческий read-only webcal-фид ([CalendarFeedController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/CalendarFeedController.php), 04-07-2026): занятия `Schedule` + диапазоны `Course`/`CourseBlock` all-day + Zoom-ссылка, токен отзываемый. Админ/учительский «календарь» — Filament FullCalendar ([CalendarPage](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/CalendarPage.php)) поверх той же таблицы `schedules`, с Google не разговаривает. Преподавательский фид + VALARM — [H6347](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6347-OxAlpha_Systema-Sanscriticum_teacher-alarm-t10-samsung_10.10.26.md) заклеймен 10-10, в main не смёржен. Контент-календарь VK/ORS — отдельная живая ветка ([CalendarPublishService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Content/CalendarPublishService.php)), не предмет этого грилла. Ключевые дефекты синхронности: (1) правка дат `Course`/`CourseBlock` не двигает занятия `Schedule` — независимые таблицы, каскада нет; (2) обратного канала «внешний календарь → Systema» не существует вовсе (Phase 3 не построена); (3) Phase 2–3 заперты внешним гейтом — верификацией Google sensitive scope `calendar`, пакет подачи [H4434](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GOOGLE_CALENDAR_VERIFICATION_PACK_H4434.md) готов с 09-09, заявка не подана.

## Решения MG (4 карты)

| # | Вопрос | Решение MG |
|---|---|---|
| 1 | Подавать ли заявку Google на верификацию sensitive scope `calendar` (пакет H4434, ~40 мин walkthrough, недельный lead time) | **Подать сейчас** — ручной шаг MG в Google Console (GTD @DO); гейт Phase 2–3 |
| 2 | Правило сдвига занятий при правке дат блока (Phase 4) | **Перенос (translate)** — каждое занятие сдвигается на ту же дельту, что и граница блока; интервалы сохраняются |
| 3 | Что строить следующим по календарной оси | **Phase 4 сразу** — каскад Course-даты → занятия строится независимо от Google-верификации |
| 4 | Модель мастер-календаря админа (роадмап §9.2) | **Service account** — отдельный служебный календарь, принадлежащий приложению (требует расшаривания календаря на SA-email; OAuth-путь учителей не меняется) |

## Исполнение

- Ось Phase 4 (translate-каскад) — хендофф [H6369](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6369-OxAlpha_Systema-Sanscriticum_course-date-translate-cascade_10.10.26.md) (OxAlpha, effort medium), заминчен и заполнен в тот же проход.
- Подача Google-заявки — GTD `@DO (MG, ~40м)` в [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md); после подачи — `@WAITING` на Google, по одобрению минтуется лейн Phase 2 (OAuth push; модель мастер-календаря — service account, решение №4).
- Роадмап обновлён тем же PR: §6 сдвиг-рулинг закрыт, §9 вопросы закрыты, §7 Phase 4 помечена in-flight H6369.

_к.ф.н. М.Ю. Гасунс_
