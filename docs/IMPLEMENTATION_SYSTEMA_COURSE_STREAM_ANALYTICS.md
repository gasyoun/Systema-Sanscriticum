# IMPLEMENTATION — сравнение потоков курса, пошагово по файлам

_Created: 18-08-2026 · Last updated: 18-08-2026_

Слой «как строить» плана [PLAN_SYSTEMA_COURSE_STREAM_ANALYTICS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_COURSE_STREAM_ANALYTICS_2026.md). Границы компонентов — в [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_COURSE_STREAM_ANALYTICS.md), приёмка — в [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_COURSE_STREAM_ANALYTICS.md).

## Перед первым шагом

Репозиторий под guard'ом главного дерева, и внешний watcher откатывает незакоммиченное. Работать в собственном worktree и приземлять правки через [`/watcher-safe-commit`](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md):

```
git fetch origin
git worktree add -b feat/<slug>-h<id>-<pid> ../Systema-Sanscriticum-h<id>-<pid> origin/main
```

Волна 2 дополнительно идёт через [`/money-pr-land`](https://github.com/gasyoun/claude-config/blob/main/commands/money-pr-land.md) с маркером `money-contour: no-auto-merge` в теле PR.

Замер «до» снять сразу и сохранить — он же эталон сверки (§1 PLAN).

---

# Волна 1 — экран сравнения (самомержимый PR)

## Шаг 1. Миграция: колонка семьи

**Файл:** `database/migrations/2026_08_18_100000_add_course_family_to_courses_table.php`

`course_family` — `string(190)`, nullable, с индексом, после `slug`. Откат — `dropColumn`. Ни один существующий запрос колонку не читает; поведение живого контура не меняется.

Зависимости: нет. Дальше всё опирается на этот шаг.

## Шаг 2. Модель и карточка курса

**Файлы:** `app/Models/Course.php`, `app/Filament/Resources/CourseResource.php`

- в `$fillable` добавить `course_family`;
- скоуп `scopeInFamily(Builder $q, string $family)`;
- в `CourseResource` — текстовое поле «Семья потоков» рядом со слагом, с `helperText`: заполняется автоматически по названию, ручное значение всегда побеждает; курсы с одинаковым значением встают в одну таблицу сравнения.

Зависит от шага 1.

## Шаг 3. Fuzzy-матчер

**Файл:** `app/Support/CourseFamilyMatcher.php`

Чистый класс, без обращения к БД:

- `familySlug(string $title): string` — снять хвосты `(N поток, ГОД)`, `часть N`, `ГОД в записи`, `в записи`; нормализовать регистр; транслитерировать в слаг;
- `streamRole(Course $course): string` — `live`, если есть блоки и активные тарифы; `recording`, если ни блоков, ни тарифов, но есть оплаченные платежи;
- `ordinal(string $title, ?Carbon $firstPaymentAt): int` — номер из «(N поток…)», иначе по дате первого платежа.

Зависит от: ничего. Тестируется юнит-тестами без БД.

## Шаг 4. Команда бэкфила

**Файл:** `app/Console/Commands/BackfillCourseFamilies.php` — сигнатура `courses:backfill-families {--apply}`

Без `--apply` печатает таблицу «курс → предлагаемая семья → роль → номер» и **ничего не пишет**. С `--apply` заполняет только пустые `course_family`; заполненные не трогает никогда.

`sys.stdout` здесь не при чём — это PHP; но правило про кодировку остаётся: вывод в UTF-8, без BOM.

Зависит от шагов 1–3.

## Шаг 5. Сервис сравнения

**Файл:** `app/Services/CourseStreamComparisonReport.php` — метод `forFamily(string $family): array`, контракт возврата в §5 ARCHITECTURE.

Правила, которые нельзя нарушить:

- «оплатил блок N» берётся **только** через `Payment::coversBlockHalf` — второй копии правила доступа не заводить;
- не-выручные тарифы исключаются через `TeacherSalaryService::NON_REVENUE_TARIFFS`, а не своим списком;
- участники по блокам считаются вызовом `CourseBlockParticipantsReport::forCourse` на каждый курс семьи;
- начисленная ЗП читается из `TeacherSalaryService`; внутрь сервиса не лезть;
- `salary.paid_out`, `remainder`, `attribution_confirmed` в волне 1 приходят из `TeacherPayoutReconciliation` в предварительном режиме: `attribution_confirmed = false`, пока нет подтверждённой разметки.

Зависит от шагов 1–3.

## Шаг 6. Предварительная сверка выплат

**Файл:** `app/Services/TeacherPayoutReconciliation.php`

- `accrued` — сумма из `TeacherSalaryService` по всем курсам семьи;
- `paid_out` — сумма подтверждённых строк `teacher_payouts` плюс подтверждённые атрибуции «Расходов» (в волне 1 подтверждённых нет, поэтому в базу попадают только явно привязанные к преподавателю платежи);
- `remainder = accrued − paid_out`;
- `attribution_confirmed` — true, только если по семье нет ни одного неразобранного «Расхода».

Сервис **ничего не пишет**. Он читает.

Зависит от шага 5.

## Шаг 7. Страница

**Файлы:** `app/Filament/Pages/CourseStreamComparison.php`, `resources/views/filament/pages/course-stream-comparison.blade.php`

Шаблон — существующая [`CourseBlockParticipants`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/CourseBlockParticipants.php): публичное свойство с `wire:model.live`, `getViewData()`, вся арифметика в сервисе.

- `canAccess()` и `shouldRegisterNavigation()` → `RoleGate::accounting()`;
- группа меню «Финансы», подпись «Потоки курса»;
- селектор семьи; по умолчанию — `kashmirskij-shivaizm` (решение раунда 2: Марии при первом входе не надо ничего выбирать);
- пять блоков: ученики по блокам и удержание; деньги; ЗП и остаток; пересечение потоков; отток поимённо;
- у каждой цифры `helperText`: что это, откуда взято, чему не равно;
- над колонками посещаемости — плашка покрытия вида «данные о посещаемости есть по 8 из 79 человек (10 %)»;
- строка остатка при `attribution_confirmed = false` печатается со словом «предварительно» и ссылкой на очередь подтверждения.

Зависит от шагов 5–6.

## Шаг 8. Гейт старого экрана

**Файлы:** `app/Filament/Pages/CourseBlockParticipants.php`, `docs/accountant-guide.md`

`RoleGate::adminOnly()` → `RoleGate::accounting()` в обоих методах. В §4i инструкции переписать абзац: сейчас там сказано, что бухгалтер этот дашборд не видит, — после правки видит именно он, а обычный админ теряет. В §1 «карта меню» и «чего бухгалтер НЕ видит» внести то же изменение, иначе инструкция станет врать зеркально.

Зависит от: ничего. Делать последним шагом кода, чтобы правка документации попала в тот же коммит.

## Шаг 9. Экспорт в Excel

**Файл:** `app/Filament/Exports/CourseStreamComparisonExporter.php` + действие «Экспорт» на странице.

Форма — как у [`TeacherSalariesExporter`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Exports/TeacherSalariesExporter.php). Один лист: строка на студента, колонки — блоки каждого потока, роль в семье, суммы. Плашка покрытия выносится отдельной строкой заголовка, а не теряется.

Зависит от шага 5.

## Шаг 10. Документация для Марии

**Файлы:** `docs/accountant-guide.md` (новый § «Потоки курса»), `docs/MANUAL_ACCOUNTANT_COURSE_STREAMS_RU.md` (памятка), сиблинг `.meta.md` к памятке.

Памятка — одна страница, сценарий «ответить на вопрос про 3-й поток за 5 минут»: куда зайти, что выбрать, какие четыре цифры смотреть, какая из них предварительная и почему, что делать дальше.

Зависит от шагов 7–9.

---

# Волна 2 — правда о деньгах (PR без мержа)

## Шаг 11. Курс записей получает преподавателя

**Файл:** `app/Console/Commands/RepairRecordingCourseSalary.php` — `salary:repair-recording-courses {--apply}`

Находит курсы с ролью `recording`, у которых есть оплаченные платежи, но пусты `teacher_id`/`salary_type`; берёт условия у живого потока той же семьи; без `--apply` только печатает. Для курса 424 это `teacher_id=14`, `salary_type=percent`, `salary_value=30`.

## Шаг 12. Связка пользователя и преподавателя

**Файл:** `app/Console/Commands/LinkTeacherUsers.php` — `salary:link-teacher-users {--apply}`

Сопоставляет `users` и `teachers` по ФИО, печатает пары, с `--apply` проставляет `users.teacher_id`. Для пользователя 6760 — `teacher_id=14`. Только после этого «Взаимозачёт» вообще видит Ворошилова.

Совпадение по ФИО — ровно тот случай, где авто может ошибиться на однофамильце: команда обязана печатать пары и требовать `--apply`, а не срабатывать молча.

## Шаг 13. Даты блоков

**Файл:** `app/Console/Commands/BackfillCourseBlockDates.php` — `courses:backfill-block-dates {--apply}`

Выводит `starts_at`/`ends_at` блоков из расписания и дат уроков; блоки без источника оставляет пустыми и называет их в отчёте. Восемь блоков курсов 332/375 — цель.

## Шаг 14. Очередь подтверждения атрибуции

**Файлы:** миграция `teacher_payout_attribution_suggestions`, `app/Models/TeacherPayoutAttributionSuggestion.php`, `app/Filament/Resources/TeacherPayoutAttributionSuggestionResource.php`, команда-детектор `salary:detect-payout-attributions`

Форма копируется с [`PaymentPromiseSuggestionResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/PaymentPromiseSuggestionResource.php). Поля: `payment_id`, предполагаемый `teacher_id`, уверенность, основание (курс, дата, сумма), статус `pending|confirmed|rejected`, кто и когда подтвердил.

Детектор размечает семь «Расходов» курсов 332/375. Ресурс на `RoleGate::accounting()` — подтверждает Мария. **Агент строки в `teacher_payouts` и `payments` не создаёт**: подтверждение меняет только статус предложения, перенос в выплатной реестр запускает человек отдельным действием.

## Шаг 15. Остаток становится подтверждённым

**Файл:** `app/Services/TeacherPayoutReconciliation.php` (доработка)

Подтверждённые атрибуции входят в `paid_out`; `attribution_confirmed` становится true, когда неразобранных «Расходов» по семье не осталось. Слово «предварительно» с экрана уходит само.

## Шаг 16. PDF-акт

**Файлы:** `resources/views/pdf/teacher-settlement-act.blade.php`, действие на странице

`barryvdh/laravel-dompdf`, образец использования — [`CertificateService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/CertificateService.php). Одна страница: преподаватель, семья потоков, по каждому потоку выручка → ставка → начислено, отдельной строкой «записи прошлого потока», выплачено, остаток.

Отдельная строка внизу — **«решение о доплате сверх остатка»**, пустая. Так решение человека не растворяется в расчёте (§4 PLAN).

---

## Порядок и параллельность

Шаги 1 → 2 → 3 → 4 строго последовательны. Шаг 5 ждёт 3, шаг 6 ждёт 5, шаги 7 и 9 ждут 6. Шаги 8 и 10 независимы и делаются последними в волне 1. Волна 2 целиком ждёт слияния волны 1 человеком.

_Dr. Mārcis Gasūns_
