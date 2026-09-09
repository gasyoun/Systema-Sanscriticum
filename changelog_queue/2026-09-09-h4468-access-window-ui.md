_Created: 09-09-2026 · Last updated: 09-09-2026_

# H4468: Filament «Окна доступа» — куратор выдаёт окно из админке (OxAlpha z-ai/glm-5.3-flash, 09-09-2026)

Решения MG 09-09-2026 (вербатим): «Пакет 30 дней» · «Filament-кнопка (v1.1)» · «Скилл сразу». Постановка: после отсечки вечного доступа ([H4456](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H4456-OxAlpha_Systema-Sanscriticum_course-access-window-real-payments_09.09.26.md)) запросы «верните доступ» обрабатываются окнами, выдача — без SSH. PR [#2478](https://github.com/gasyoun/Systema-Sanscriticum/pull/2478) merged, прод задеплоен.

- **CourseAccessWindowsRelationManager** в карточке студента (каркас LessonAccessGrantsRelationManager): список окон + «Выдать окно» (дефолт +30 дней, forever-галочка = вечное именное исключение по слову MG) + «Снять»; действия только ADMIN/SUPER_ADMIN/MANAGER ([RoleGate::any](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/RoleGate.php)).
- **Скоуп-логика в модели**: `CourseAccessWindow::grantInScope` — окно только на курс из `config/access_window.php: enabled_course_ids = [327,336,396]` (Парибок; расширение = правка конфига) И купленный студентом; отказы `course_not_purchased` / `course_out_of_scope`. Деньги не тронуты.
- **Relation** `User::courseAccessWindows()`; RM зарегистрирован после LessonAccessGrants.
- **Тесты**: [CourseAccessWindowsRelationManagerTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Access/CourseAccessWindowsRelationManagerTest.php) 8/8 (grant in-scope, отказ не-купившему, отказ вне скоупа даже купившему, forever, снятие, регистрация, manager видит таблицу, teacher 403+RoleGate) + регрессия 28/28; CI 18/18; Pint clean.
- **Playbook-сиблинг**: Uprava [docs/CARE_ACCESS_WINDOW_PLAYBOOK_2026-09-09.md](https://github.com/gasyoun/Uprava/blob/main/docs/CARE_ACCESS_WINDOW_PLAYBOOK_2026-09-09.md).

_Др. Mārcis Gasūns_
