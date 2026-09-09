
## UPDATE 09-09 ~19:45 МСК — массовая отсечка исполнена (второй рулинг MG)

MG: «A (рекомендую) — окна только по запросу, все остальным закрываем доступ к
Парибку» → 345 пар user×course (курсы 327/336/396) отрезано окнами ends_at в
прошлом; исключения — 6476 (окно до 27-09) и учитель 5834 (staff, @DECIDE).
Деньги не тронуты; клуб (club_included) не затронут. Пробы: живого потока нет
(396 max lesson 2025-03-14), платежей 30д = 0. Курсировка: `php artisan
access:set-window <user> <course> --until="..." --by=1`; откат `--revoke`.
Census/детали: Uprava reports/CENSUS_PARIBOK_ETERNAL_ACCESS_09-09-2026.md.

## UPDATE 2 (09-09 поздно вечером) — v1.1 UI + лейн-скилл исполнены

Решения MG (вербатим): «Пакет 30 дней» · «Filament-кнопка (v1.1)» · «Скилл сразу» →
- Filament: [PR #2478](https://github.com/gasyoun/Systema-Sanscriticum/pull/2478) merged, прод; Настя (manager) — Users → студент → «Окна доступа»; скоуп config/access_window.php [327,336,396]; логика CourseAccessWindow::grantInScope (только купившим).
- Лейн-скилл — в Uprava (care_qa_lane SKILLS['window'], класс window, шаблон 30 дней; H4469).
- Полный playbook: Uprava docs/CARE_ACCESS_WINDOW_PLAYBOOK_2026-09-09.md.

_Dr. Mārcis Gasūns_
