# Metadoc — SCHEDULE_KINDS_CANON_ANONS_SITE_09-10-2026.md

_Created: 09-10-2026 · Last updated: 09-10-2026_

## Purpose

Канон пяти видов расписания института + зафиксированные решения грилла 09-10-2026 (что показывать на сайте и что анонсировать). Якорь для исполнителя [H6313](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6313-OxAlpha_Systema-Sanscriticum_schedule-kinds-kind-field-filters_09.10.26.md) и будущих анонс-кампаний.

## Audience

Исполнитель H6313 (OxAlpha); сессии анонс-планирования (anons:* команды); MG.

## Provenance

- Заказ MG в чате 09-10-2026: «какие виды расписания есть по нашему институту? Это нужно для анонсов и сайта»; прогон /grillme — Phase 0 домашка (grep по Systema-Sanscriticum: Schedule/ScheduleGenerator/PublicSchedulePageController/PublicScheduleController/AdvanceTrialSchedule/CalendarFeedController; hub_grep Uprava по «расписание»), затем 4 карточки AskUserQuestion — 4/4 решены MG тем же проходом.
- Model: GLM 5.3 Flash (`zai-coding-plan/glm-5.3-flash`).
- Ворктри `~/Documents/GitHub-worktrees/Systema-Sanscriticum/Systema-Sanscriticum-h6313-62867`, ветка `feature/schedule-kinds-kind-filters-h6313` от origin/main (3bd81836) — guarded main-tree репо не тронут.

## Limitations

- Числа фида (275 строк / 16 курсов) — срез 09-10-2026; живой, но меняется ежедневно.
- «Анонсировать все 4 вида» — политика; механика отбора поводов в анонс-движке (AnonsPlacement/AnonsPublication) здесь не проектируется — только привязка к `kind` после посадки H6313.
- iCal-фид персональный (по токену) — в канон публичных видов он не входит, указан как поверхность.

## Related docs

- [H6313 — handoff исполнителя](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6313-OxAlpha_Systema-Sanscriticum_schedule-kinds-kind-field-filters_09.10.26.md).
- Семейство public-schedule поверхностей: [PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md) и его [.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.meta.md), [ARCHITECTURE_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE.md).

## Revision history

| Date | Change |
|---|---|
| 09-10-2026 | Создан при грилле 09-10 (домашка + пробы прода + 4/4 решения MG). |

_к.ф.н. М.Ю. Гасунс_
