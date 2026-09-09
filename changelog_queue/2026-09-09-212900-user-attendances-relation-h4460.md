_Created: 09-09-2026 · Last updated: 09-09-2026_

# H4460: кабинет 500 — User::attendances() BadMethodCallException (09-09-2026)

Кабинет падал 500-й для любого студента с курсом-учебником на канве (H4435): рецидивирующие `Call to undefined method App\Models\User::attendances()` в laravel-2026-09-*.log — userIds 5862/6804/6571/5836, 78 строк за 09-09 (резидуал из [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md), строка 16:36). H4435 завела двух потребителей (`StudentController::show` канва по курсам + `UserResource::attendanceCanvas` на ViewUser), но связь на модели `User` не объявила — `BadMethodCallException` на первом же студенте с ненулевой канвой.

- **Фикс** ([User.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/User.php)): связь `attendances(): HasMany` → `WebinarAttendance` (FK `webinar_attendances.user_id` существовал с 26-06, обратная `user()` тоже); без миграций — только объявление связи.
- **Тесты**: [UserAttendancesRelationTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/UserAttendancesRelationTest.php) 3/3 — связь существует, питает канву H4435 (lookup факта по `schedule_id` группы), скоупится по пользователю; канва-потребители зелёные (`KanvaReportTest` + `KanvaBlocksMoneyForecastTest` + `CabinetProbeTest` 34/34), Unit 492/492; Pint чист. [PR #2470](https://github.com/gasyoun/Systema-Sanscriticum/pull/2470) → `38500501`.
- **Прод**: деплой `0b8ffa08 → 38500501` (deploy.sh, смоук 200 + cabinet:probe «Кабинет жив»); после деплоя (21:22 MSK) — **0** новых строк исключения в дневном логе (последняя 21:04:01 MSK, до фикса).
- **Попутно**: восстановлен прорядок CHANGELOG (утечка 1.62.2→1.63.0→1.62.1 ломала pre-push guard всем прямым пушам) и перегенерирован docs/ENVIRONMENT_VARIABLES.md (сдвиг строк services.php).
_Dr. Mārcis Gasūns_
