---
tags: [meta]
---

# Метадок: CHECKLIST_TEACHER_PAYOUT_ROUTE_WALK_H5635_02-10-2026.md

_Created: 02-10-2026 · Last updated: 02-10-2026_

- **Жанр:** чек-лист-приёмка бизнес-процесса (паттерн AI Builders peer review п.2.3), первый в estate.
- **Источник:** handoff [H5635 (OxAlpha)](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5635-OxAlpha_Systema-Sanscriticum_business-checklist-agent-testing_02.10.26.md); процесс — проведение выплаты преподавателю (money-core, регрессии #271/H5007).
- **Соседи:** [ACCEPTANCE_TEACHER_PAYOUT_TRUTH_WAVE2_18-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ACCEPTANCE_TEACHER_PAYOUT_TRUTH_WAVE2_18-08-2026.md) (паттерн sqlite-фикстуры, данные), [app/Filament/Pages/TeacherSalaries.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/TeacherSalaries.php) (UI процесса), tools/h5635_payout_route_walk.php (прогон).
- **Обновлять когда:** меняется `recordManualPayout` / `TeacherPayoutPoster` / схема `teacher_payouts` — перезапустить прогон и обновить журнал.
- **Не устареет если:** маршруты расширятся (FX-ветка, wave1-off) — добавить строки в таблицу R-маршрутов, не переписывать.

_Гасунс_
