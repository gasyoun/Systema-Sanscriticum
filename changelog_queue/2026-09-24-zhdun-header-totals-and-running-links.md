# «Список ожидания»: шапка считает ждунов и показывает, что уже идёт осенью 2026

_Created: 24-09-2026 · Last updated: 24-09-2026_

- На `/online/zhdun` в шапке появились два итога и разница режимов: **«Под вопросом (ждун)»** — сколько всего строк-курсов и преподавателей в списке, и **«Уже идут осенью 2026»** — сколько курсов и преподавателей уже занимаются, **с точными ссылками на карточку каждого идущего курса** (без «в записи»). Правило MG 24-09-2026.
- Разница объяснена тут же: ждун — будущая группа, которой ещё нет, старт «не раньше» ждёт кворума голосов и оплат; «уже идут» — настоящие группы с занятиями по расписанию. Формулировка про позднее присоединение — канон сайта («записи помогают догнать», `shop/partials/first-questions.blade.php`).
- Счётчики **динамические, не хардкод**: ждун считается по тем же строкам `course_waitlist_items` (`is_listed`), «уже идут» — по видимым live-курсам, чьё расписание `underway` (`CourseCadence::isUnderway`: группа началась и ещё не кончилась, расписание — источник правды). Записи (`format=recorded`) и будущие наборы в итог не попадают.
- Прод-данные по заказу MG: строка ждуна «Цифровая грамотность» (id 3) — цена блока 10 000 → **25 000 ₽**.
- Резидуал: подпись «осенью 2026» статична, а множество курсов динамично — пересмотреть после 01-12-2026 (GTD `@DO`).
- Тесты: [`VitrinaWaitlistPageTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/VitrinaWaitlistPageTest.php) +1 (итоги ждуна, итоги идущих, ссылки только на underway, запись и будущий набор не попадают); 21 тест файла, единственный провал `test_teacher_links_use_natural_name_and_short_facet_resolves` предсуществующий (воспроизводится на `origin/main` без этой правки). Связанный файл — [`CourseFavoritesTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CourseFavoritesTest.php) + [`WaitlistGuestVoteAfterLoginTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/WaitlistGuestVoteAfterLoginTest.php) (24/24) — зелёные.

_Гасунс_
