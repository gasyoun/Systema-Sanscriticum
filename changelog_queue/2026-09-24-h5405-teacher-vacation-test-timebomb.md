# Тест команды «Каникулы»: часы закреплены на 15-09-2026 — снята дата-бомба, красившая CI на каждом PR с 24-09 (H5405, Fable 5.1 `claude-fable-5-1`, 24-09-2026)

Найдено при посадке H5040 (PR docs-only): [`TeacherVacationCommandTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/TeacherVacationCommandTest.php) держит абсолютные фикстуры «с 23.09 по 06.10», а [`VacationCommandService.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Telegram/VacationCommandService.php) по дизайну переносит прошедшую дату без года на следующий год. В полночь на 24-09-2026 «23.09» стало прошлым: `main` зеленый 23-09 19:01 UTC, тот же тест красный 24-09 02:11 UTC (2 из 8: `toDateString()` on null; `is_on_vacation` false).

- `setUp()` закрепляет часы `travelTo(2026-09-15 12:00)`: 23.09 всегда впереди с запасом ≥5 дней, 01.08 (тест переноса на следующий год) всегда позади. Сервис не тронут — сторона ошибки фикстурная, правка кода перезарядила бы бомбу.
- Доказательство: `phpunit --filter TeacherVacationCommandTest` — до правки 2 красных из 8 на 24-09, после — 8/8 зеленых. Класс: FINDINGS §349 (абсолютная фикстура против `now()`-фильтра), рецепт `/ci-timebomb-defuse`.

_Гасунс_
