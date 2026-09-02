# Paypal links board: оглавление + фильтр до 01-07-2026 (OxAlpha z-ai/glm-5.3-flash, 02-09-2026)

Доска [/admin/paypal-links](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/PaypalLinksBoard.php) (MG 02-09-2026):

- **Оглавление сверху**: при >1 группах наверху доски — якорный список курсов (`#course-{slug}`, скролл к секции, `scroll-mt-24`).
- **Фильтр даты**: курсы со стартом раньше 01-07-2026 не показываются. Дата старта = самая ранняя `Schedule.start` (напрямую по `course_id` или через группы, как в `Course::upcomingSchedules()`); курс без расписания остаётся, чтобы новые не-расписанные курсы не терялись. В оглавлении рядом с названием — дата старта.
- Тесты: [PaypalLinksBoardTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/PaypalLinksBoardTest.php) — 6 passed (старый скрыт / новый показан / без расписания остаётся / старт через группу).
- Деплой: prod samskrte.ru 02-09-2026, smoke PASS (view компилируется, guest 302 на /admin/paypal-links, home 200).
