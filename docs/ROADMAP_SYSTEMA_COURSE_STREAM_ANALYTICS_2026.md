# ROADMAP — аналитика потоков курса и сверка с преподавателем (2026)

_Created: 18-08-2026 · Last updated: 31-08-2026_

> **Truth-pass 30-08-2026 (Fable 5 `claude-fable-5`, `/ask` H3760):** Волны 1–2 отгружены —
> на `origin/main` живут `CourseStreamComparison` (страница+сервис+экспорт), `TeacherPayoutAttributionSuggestion`,
> `TeacherPayoutReconciliation`, команды `RepairRecordingCourseSalary` / `LinkTeacherUsers`. Волна 3 заминчена:
> [H3761 (Opus 5, 🔴3 hard) — Stream analytics W3: диагностика webinar_attendances + Zoom-бэкфилл](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3761-Opus_Systema-Sanscriticum_stream-analytics-w3-attendance-repair_30.08.26.md).
> Контракт автономии: [PLAN_UPRAVA_ASK_CLAUDE_SYSTEMA_ROADMAP_MINT_2026-08.md](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_UPRAVA_ASK_CLAUDE_SYSTEMA_ROADMAP_MINT_2026-08.md).

Слой «волны» плана [PLAN_SYSTEMA_COURSE_STREAM_ANALYTICS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_COURSE_STREAM_ANALYTICS_2026.md). Решения и контракт автономии — там, здесь не повторяются.

## Как волны разведены по полномочиям

Правило раунда 19: чтение мержится само, запись в **боевые денежные данные** идёт отдельным PR без мержа. Граница проведена так:

- **Миграции, явно описанные в этом плане** (добавление колонки `courses.course_family`, таблица предложений по атрибуции) — часть самомержимой волны. Стоп-условие 3 запрещает боевые данные «помимо миграций, описанных в плане», то есть описанные — разрешены.
- **Правка существующих строк** — курс 424, `users.teacher_id`, `course_blocks.starts_at`, любые `payments`/`teacher_payouts` — волна 2, PR с маркером `money-contour: no-auto-merge`.

Волна 1 обязана быть полезной **до** того, как человек вольёт волну 2: экран показывает цифры и честно помечает, чего ещё не хватает.

---

## Волна 1 — экран сравнения потоков (самомержимая) · ✅ отгружена (сверка 30-08-2026)

**Что получает Мария:** страница «Потоки курса» в админке; выбор курса → колонки по всем его потокам; пять блоков метрик; кнопка «Экспорт» в Excel; подсказки у каждой цифры.

| Поставка | Разблокирует |
|---|---|
| Колонка `courses.course_family` + поле в карточке курса | всё остальное — без группировки сравнивать нечего |
| Команда `courses:backfill-families` (fuzzy, `--apply`) | заполняет 332/375/424 одной семьёй `kashmirskij-shivaizm` |
| Сервис `CourseStreamComparisonReport` | экран, экспорт и обе выгрузки волны 2 |
| Страница `CourseStreamComparison` на `RoleGate::accounting()` | ответ Марии |
| Перевод `CourseBlockParticipants` на `RoleGate::accounting()` + правка §4i инструкции | поимённая матрица «студент × блок» становится видна бухгалтеру |
| `CourseStreamComparisonExporter` | Excel |
| Раздел в инструкции бухгалтера + памятка «за 5 минут» | второй в жизни вход Марии в админку |

**Чего в волне 1 сознательно нет.** Строка «остаток по преподавателю» показывается, но помечена «предварительно, атрибуция не подтверждена» — потому что четыре «Расхода» на 332 проведены на «Системные расходы» и до подтверждения человеком не являются доказанной ЗП. Колонки посещаемости отрисовываются с плашкой покрытия (~10 %).

## Волна 2 — правда о деньгах (PR без мержа) · ✅ код отгружен (сверка 30-08-2026)

**Что закрывает:** превращает «≈ 3 445 ₽ предварительно» в подтверждённую цифру и не даёт дыре повториться с записями 2-го потока.

| Поставка | Разблокирует |
|---|---|
| Команда `salary:repair-recording-courses --apply` — курсу 424 проставить `teacher_id=14`, `salary_type=percent`, `salary_value=30` | 15 300 ₽ начинают начисляться |
| Команда `salary:link-teacher-users --apply` — `users.teacher_id=14` пользователю 6760 | «Взаимозачёт» впервые видит Ворошилова |
| Команда `courses:backfill-block-dates --apply` — `starts_at`/`ends_at` 8 блоков из расписания и уроков | признание ЗП по месяцам блоков перестаёт схлопываться |
| Таблица + ресурс `TeacherPayoutAttributionSuggestion` — агент предлагает, Мария подтверждает | семь «Расходов» получают проверяемую разметку |
| Сервис `TeacherPayoutReconciliation` | строка «начислено / выплачено / остаток» становится подтверждённой |
| PDF-акт по преподавателю (`barryvdh/laravel-dompdf`, уже в зависимостях) | разговор с Ворошиловым |

**Прямо запрещено внутри волны 2:** самому создавать строки в `teacher_payouts` и `payments`. Агент готовит предложения; перенос подтверждённых в выплатной реестр запускает человек из админки.

## Волна 3 — починка сбора посещаемости (отдельно) · ✅ диагностика отгружена (H3761, 31-08-2026)

Не нужна для ответа Марии, нужна для того, чтобы 3-й поток не был так же слеп.

Исходная постановка волны — «на 27 уроков курсов 332/375 заведено 2 расписания и
`webinar_attendances` пуст» — **на боевой базе не подтвердилась ни в одной части**.
Полный разбор с цифрами: [DIAGNOSIS_SYSTEMA_STREAM_ATTENDANCE_31-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DIAGNOSIS_SYSTEMA_STREAM_ATTENDANCE_31-08-2026.md).

| Пункт волны | Результат |
|---|---|
| разобраться, почему `webinar_attendances` пуст | Он не пуст: 1218 строк, 67 занятий, 11 курсов. Пуста **идентификация**: 96 % строк приходят из Zoom без почты, и плашка покрытия по вебинарам даёт `webinar_users = 0` при 229 собранных строках на курсе 375 |
| «2 расписания на 27 уроков» | У 375 — 14 занятий двух генераций с непересекающимися датами; у 332 — **ноль занятий**, вся таблица `schedules` начинается с 25-03-2026, а первый поток шёл с 09-2025 |
| резолв `zoom_meeting_id` из общей ссылки курса | Проверен, работает. У 332 нет ни ссылки, ни id — резолвить не из чего. Новая команда закрывает цепочку курс → ссылка курса → ссылка занятия |
| добэкфилить, где данные Zoom живы | Отгружена [`attendance:backfill-streams`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/BackfillStreamAttendance.php) — только вставки, рантайм-запрет UPDATE/DELETE ([`InsertOnlyGuard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/InsertOnlyGuard.php)), занятия задним числом не заводит |
| честно признать период, где данных нет | Слепой список в диагнозе: 332 — весь первый поток (в Zoom данные есть, в системе некуда класть); 375 — занятие 24-06-2026 (в Zoom запуска не существует) |

**Три развилки решены человеком 31-08-2026 и отгружены в том же проходе:**

1. **Идентификация — «чини, и пропиши в руководствах».** Назад: [`attendance:link-participants`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/LinkWebinarParticipants.php) заводит связки «имя в Zoom → пользователь» в отдельной таблице (не трогая `webinar_attendances`), плашка покрытия считает и через них. Сопоставление по наборам токенов с транслитом ([`ZoomNameMatcher`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ZoomNameMatcher.php)); неоднозначное не угадывается. На боевых данных курса 375: узнаны 10 плательщиков из 28, было 0. Вперёд: причина — подпись в Zoom, поэтому она записана в руководства [ученика](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_GUIDE_RU.md), [куратора](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CURATOR_ADMIN_GUIDE_RU.md) и [бухгалтера](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_ACCOUNTANT_COURSE_STREAMS_RU.md).
2. **Занятия первого потока — «заводи, человек сейчас ничего не видит».** `attendance:backfill-streams --create-lessons` заводит занятие под подтверждённый запуск Zoom, только вставками; обязателен `--slot`, иначе за урок был бы принят любой запуск общей комнаты.
3. **Занятие 24-06-2026 — «не было».** Подтверждено; в Zoom запуска на эту дату нет. Строка 643 остаётся следом, посещаемости у неё не будет.

## Не-цели

- Не менять [`TeacherSalaryService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php) и арифметику начисления — отчёт её читает.
- Не трогать гейты доступа других экранов, кроме двух названных.
- Не выплачивать деньги и не решать за человека размер доплаты.
- Не удалять, не переименовывать и не сливать курс 424.
- Не строить прогноз спроса на 3-й поток — план даёт факты, решение об анонсе человеческое.

_Dr. Mārcis Gasūns_
