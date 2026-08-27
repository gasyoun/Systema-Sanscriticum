# Журнал волны 2: harvest FAQ и квизы преподавателя/бухгалтера

_Created: 27-08-2026 · Last updated: 27-08-2026_

Исполнение [H3244 (Grok 4.6, 🔴3 hard) — Wave 2: per-book FAQ harvest and teacher/accountant cabinet-mastery banks](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3244-Grok_Systema-Sanscriticum_product-docs-faq-mastery_21.08.26.md). Helpdesk в репозитории не держит accepted-корпус: фикстуры `SupportAnswerSuggestion` живут только внутри тестов и в прод не коммитятся. Живые ФИО и суммы из прод-очереди не копировались.

## Счёт Части IV

| Книга | Baseline 21-08-2026 | После волны 2 | Откуда прирост |
|---|---|---|---|
| [STUDENT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_GUIDE_RU.md) | 7 | 11 | 4 вопроса из `why` банка `student` (H3215), дедуп с уже бывшими семью |
| [CURATOR_ADMIN_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CURATOR_ADMIN_GUIDE_RU.md) | 9 | 11 | 2 вопроса из `why` банка `curator` (magic-ссылка в личку; «войти как») |
| [ACCOUNTANT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ACCOUNTANT_CABINET_GUIDE_RU.md) | 7 | 10 | 0 в этой волне; книга уже была длиннее baseline (не ужимали) |
| [TEACHER_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_CABINET_GUIDE_RU.md) | 9 | 9 | 0; чек-листы не считаются вопросами FAQ |

## Helpdesk

Accepted `SupportAnswerSuggestion` в git: **0 новых FAQ**. Квизы всё равно собраны из Части IV и человеческих дистракторов книги (запрет «выдумать варианты без написанного в гиде не-так»).

## Квизы

| Audience | Маршрут | Гейт (без правки RoleGate) | Вопросов | Порог |
|---|---|---|---|---|
| teacher | `/admin/teacher-mastery` | `RoleGate::seesTeacherSurfaces()` | 8 | 7 |
| accountant | `/admin/accountant-mastery` | `RoleGate::finance()` | 6 | 5 |

Каталог `/admin/documentation` колонка «Проверка» резолвит curator, student, teacher, accountant.

## Решения по дефолту плана

1. Пустой harvest helpdesk → не стоп (решение 22).
2. `cabinet_mastery_attempts.audience` уже есть в миграции H3215 — отдельная миграция не нужна.
3. Квиз преподавателя не расширяет `TeacherGuide::canAccess()` (редактор лекций / `teacher_id`): handoff фиксирует `seesTeacherSurfaces()`. Чистый `role=teacher` получает 403 — это гейт H3219, не баг волны 2.

## Приёмка (локально, Grok 4.6 `grok-4.6`, 27-08-2026)

| Проверка | Результат |
|---|---|
| `php artisan test --filter=CabinetMastery` | 9 tests, 155 assertions |
| `php artisan test --filter=ProductDocsFaqMasteryTest` | 7 tests, 98 assertions |
| `php artisan test --filter=ProductDocsCatalog` | 11 tests, 41 assertions |
| Pint (7 PHP files) | clean |

## Выкладка

| Шаг | Факт |
|---|---|
| Feature PR | [#2146](https://github.com/gasyoun/Systema-Sanscriticum/pull/2146) merge `14fcc456` |
| Changelog cut | [v1.90.24](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.90.24) via [#2147](https://github.com/gasyoun/Systema-Sanscriticum/pull/2147) `c997ddf0` |
| Prod `deploy.sh` | `5caaf621` → `14fcc456`; homepage 200; `cabinet:probe --fail-on-critical --no-alert` OK |
| Smoke guest GET `/admin/teacher-mastery` | **302** → `/admin/login` |
| Smoke guest GET `/admin/accountant-mastery` | **302** → `/admin/login` |

Handoff closed on Uprava origin/main against [#2146](https://github.com/gasyoun/Systema-Sanscriticum/pull/2146).

_Dr. Mārcis Gasūns_
