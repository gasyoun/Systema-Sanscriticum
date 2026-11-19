# Поиск каталога по фамилии преподавателя — диагноз и фикс (H7589)

_Created: 01-11-2026 · Last updated: 01-11-2026_

## Симптом (живая проба 31-10-2026, ~23:00 MSK)

Ввод фамилии преподавателя («Леонов») в свободный текст поиска на [/online](https://samskrte.ru/online) возвращал пустую выдачу, хотя у преподавателя есть и активные курсы, и курсы в записи. POST /livewire/update с search=Леонов отдавал empty-state 23,3 KB.

## Причина — два слоя

1. **Скоуп поиска смотрел только на title.** [CourseCatalog::baseQuery()](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Livewire/Shop/CourseCatalog.php) делал `title LIKE '%q%'`; имя преподавателя в свободном тексте не участвовало (оно доступно только фасетом teacherId).
2. **Связь курс↔преподаватель двойная.** Основной преподаватель живёт в `courses.teacher_id` (FK, связь `teacher()`), со-преподы — в pivot `course_teacher` (связь `teachers()`, pivot основного не содержит). Прод-форма: Teacher 17 «Леонов Максим Владимирович» — pivot **пуст (0)**, его 3 видимых курса (333 «Санскритская литература (2025)», 378 «Ведийская литература (2026)», 459 «Философия санкхьи») висят на FK. Первый фикс ([#3675](https://github.com/gasyoun/Systema-Sanscriticum/pull/3675), только pivot) поэтому не менял выдачу Леонова; tinker-проба на проде вскрыла FK-слой.

## Фикс

[v2 (#3677)](https://github.com/gasyoun/Systema-Sanscriticum/pull/3677) зеркально повторяет спаривание [scopeForTeacher](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Course.php): `title OR pivot teachers.name LIKE OR teacher_id IN (name-матч через подзапрос)`, под одним `where()` — фасеты держат AND-семантику, LIKE-escape `%`/`_` сохранён. Тесты: [CourseCatalogTeacherSearchTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Shop/CourseCatalogTeacherSearchTest.php) — 3 кейса (pivot-форма, титульный контроль, FK-форма как на проде).

## Прод-проверка (после деплоя 3044475, 01-11-2026)

| Проба | Результат |
|---|---|
| q=Леонов | 200, 51,7 KB, все 3 курса Леонова в выдаче |
| q=Хануман (контроль) | 200, карточки на месте (2 курса × 3 вхождения ссылок) |
| q=% (LIKE-escape) | 200, без 500 |

Независимый верификатор DeepSeek v4.1 Flash: PASS (воспроизвёл прод-пробу сам).

## Гочи сессии (для следующих прогонов)

- **CI PR гоняет кэшированный merge-ref:** три «детерминированных» красных прогона тестировали merge-коммит, собранный с красным на тот момент main (чужой флейк TeachersPublicPagesTest); рераны не пересобирают merge. Лечение — `gh pr update-branch`, не реран. Сигнал: локально зелёный + main прыгает.
- **Деплой-окно:** авто-деплой каждые 30 мин тянет origin/main; слот :30, промелькнувший за минуту до мерджа, переносит выкладку на следующий слот — проверять HEAD на сервере, а не угадывать.

## Остатки

- `GUARD_WAIVERS="tmpfs-cap"` в [server_guards.conf](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards.conf) — прод-гварды сообщают «waiver не понадобился: tmpfs-cap нет среди находок — убрать»; по собственному комментарию конфига секция снимается после хост-фикса. Снятие — отдельный ход с пробой guards:verify.
- Глобальный поиск в шапке (клиентский индекс + /poisk) — заглушка H7590 в очереди Uprava.

_к.ф.н. М.Ю. Гасунс_
