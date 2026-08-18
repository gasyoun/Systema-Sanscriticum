# ARCHITECTURE — сравнение потоков курса и сверка с преподавателем

_Created: 18-08-2026 · Last updated: 18-08-2026_

Слой «границы и схема» плана [PLAN_SYSTEMA_COURSE_STREAM_ANALYTICS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_COURSE_STREAM_ANALYTICS_2026.md).

## 1. Что уже есть — вердикт «строить или переиспользовать»

Проверка прошла до проектирования: `hub_grep` по «сравнение потоков / когорта / отчёт бухгалтеру» — ноль попаданий, то есть межрепозиторного владельца у этой задачи нет. Внутри Systema картина такая.

| Кусок | Что уже есть | Вердикт |
|---|---|---|
| «Оплатил блок N» | [`Payment::coversBlockHalf`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Payment.php), зеркалит `Lesson::unlockingKeys` | **Переиспользовать.** Второй копии правила доступа не заводить |
| Участники и оплаты по блокам одного курса | [`CourseBlockParticipantsReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/CourseBlockParticipantsReport.php) | **Переиспользовать как поток-строитель.** Новый сервис вызывает его на каждый курс семьи и складывает результаты в колонки |
| Начисление ЗП, accrual по месяцам блоков, список не-выручных тарифов | [`TeacherSalaryService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php), константа `NON_REVENUE_TARIFFS` | **Только читать.** Внутрь не лезем — под фенсом |
| Сверка «заплатил как ученик / начислено как преподавателю» | [`MutualSettlementService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/MutualSettlementService.php) | **Переиспользовать** там, где нужна сумма, уплаченная человеком школе |
| «Агент предложил → человек подтвердил в админке» | [`PaymentPromiseSuggestion`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/PaymentPromiseSuggestion.php) + [`PaymentPromiseSuggestionResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/PaymentPromiseSuggestionResource.php) + курсор детекции | **Копировать форму**, не изобретать свою очередь подтверждений |
| Выгрузка в Excel | 8 экспортёров, в т.ч. [`TeacherSalariesExporter`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Exports/TeacherSalariesExporter.php) | **Переиспользовать механизм** Filament Exporter |
| PDF | `barryvdh/laravel-dompdf` в зависимостях, каталог `resources/views/pdf`, образец в [`CertificateService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/CertificateService.php) | **Переиспользовать.** Новой зависимости не добавлять |
| Сравнение потоков бок о бок | **нет ничего** | **Строить.** Это единственная действительно новая часть |

## 2. Модель данных: «поток» — это `Course`

В платформе поток уже смоделирован как отдельная строка `courses` — так заведены 332, 375 и 424. Новых сущностей «поток» не вводим.

Добавляется одна колонка:

```
courses.course_family  VARCHAR(190) NULL, INDEX
```

`NULL` = курс ни в какую семью не входит (сегодня это все курсы, кроме трёх шиваитских). Колонка аддитивна: ни один существующий запрос её не читает, поведение живого контура не меняется.

**Почему колонка, а не таблица.** Решение раунда 5. Семья — это ярлык группировки, а не сущность со своим жизненным циклом: у неё нет ни статуса, ни дат, ни владельца. Таблица потребовала бы второй миграции, ресурса и CRUD — больше поверхности под баги ради нуля дополнительных фактов. Колонка видна в БД глазами и откатывается одним `DROP COLUMN`.

**Почему не `predecessor_course_id`.** Существующее поле означает «продолжение с урока N» — цепочку внутри одной программы. Поток 2 не продолжает поток 1, он его повторяет. Смешение двух смыслов сломало бы баннер продолжения в кабинете.

## 3. Определение семьи: fuzzy + перебивка человеком

`App\Support\CourseFamilyMatcher` — чистая функция, без обращения к БД, покрывается юнит-тестами.

Порядок разрешения на каждом курсе:

1. **Заполненный `course_family` побеждает всегда.** Человек прав по определению; авто его не перетирает.
2. Иначе — нормализовать заголовок: убрать хвост «(N поток, ГОД)», «часть N», «ГОД в записи», «в записи», привести к нижнему регистру, транслитерировать в слаг.
3. Совпали нормализованные слаги — одна семья.

На боевых данных это даёт `kashmirskij-shivaizm` для всех трёх курсов: «Кашмирский шиваизм (1 поток, 2025)», «(2 поток, 2026)» и «2025 в записи» после снятия хвостов сходятся.

**Роль потока внутри семьи** выводится отдельно и тоже перебивается человеком:

| Роль | Признак |
|---|---|
| `live` | есть блоки и активные тарифы |
| `recording` | нет ни блоков, ни тарифов, есть оплаченные платежи (курс 424) |

Порядковый номер потока берётся из «(N поток…)», при отсутствии — из даты первого платежа. Это тот случай, где авто заведомо ошибётся на нестандартном названии; поэтому поле в карточке курса обязательно, а команда бэкфила по умолчанию работает в режиме отчёта и пишет только с `--apply`.

## 4. Компоненты и границы

```
CourseFamilyMatcher  (Support, чистый)
        │  слаг семьи + роль потока
        ▼
CourseStreamComparisonReport  (Service)
        ├── на каждый курс семьи → CourseBlockParticipantsReport   (люди по блокам)
        ├── Payment::paid + coversBlockHalf                        (деньги по блокам)
        ├── TeacherSalaryService (только чтение)                   (начислено)
        └── TeacherPayoutReconciliation (волна 2)                  (выплачено, остаток)
        │
        ├──────────────► CourseStreamComparison        (Filament Page, accounting())
        ├──────────────► CourseStreamComparisonExporter (Excel)
        └──────────────► TeacherSettlementActPdf        (волна 2, dompdf)
```

Страница не считает **ничего**: вся арифметика в сервисе, как в `MutualSettlements`. Это условие проверяемости — сервис прогоняется тестом и консольной командой сверки, страница только рисует.

## 5. Контракт сервиса

`CourseStreamComparisonReport::forFamily(string $family): array` возвращает:

```
family, family_title,
streams[]:  course_id, title, role (live|recording), ordinal,
            participants_total, paid_any, revenue, avg_check, discount_total,
            blocks[]:  number, title, starts_at, paid_whole, paid_half1, paid_half2, revenue,
            retention_first_to_last,
            salary: scheme, rate, accrued, paid_out, remainder, attribution_confirmed (bool),
            attendance: covered_users, total_users, coverage_ratio
crossover:  s1_to_s2[], recording_buyers[], recording_buyers_not_in_live[],
            dropped_between_blocks[ {block_from, block_to, users[]} ],
            dropped_between_streams[ {from, to, users[]} ],
            bought_all_never_watched[]
totals:     accrued_all, paid_out_all, remainder_all, remainder_confirmed (bool)
```

`attribution_confirmed = false` до подтверждения разметки человеком; интерфейс обязан в этом случае писать «предварительно», а не выдавать сумму за факт.

## 6. Доступ

Оба экрана — `RoleGate::accounting()` (бухгалтер и супер-админ). Решение раунда 2, с осознанной ценой: обычный админ теряет «Участники по блокам», которые видит сегодня. Правится и §4i в [accountant-guide.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/accountant-guide.md), где сейчас утверждается обратное — иначе инструкция станет врать в ту же сторону, только зеркально.

Данных о ЗП преподавателя на экране достаточно, чтобы гейт `any()` был неуместен: суммы вознаграждения конкретного человека — не для менеджера.

## 7. Отток: два среза и честное покрытие

- **По деньгам** (основной): купил блок N, не купил блок N+1 → поимённо. Полный сигнал за оба потока.
- **По посещаемости** (вспомогательный): отметки Zoom и просмотры уроков. Сегодня покрытие ~10 % (8 из 79 человек, 0 отметок посещаемости), поэтому колонка **обязана** нести плашку покрытия. Пустая колонка без плашки читается как «никто не ходил» — это была бы ложь в отчёте бухгалтера.
- **«Купил всё, но не смотрел»** — отдельный список: оплачены все блоки, `lesson_views` пуст. При нынешнем покрытии список будет почти совпадать со списком плательщиков; плашка объясняет, почему.

## 8. Что нарочно не проектируется

- Прогноз спроса на 3-й поток. Данных две точки; экстраполяция по двум точкам — это украшение, а не факт.
- Автоматическое начисление доплаты. Отчёт называет остаток; выплату заводит человек.
- Общий «конструктор когорт». Задача — сравнить потоки одного курса, а не построить BI.

_Dr. Mārcis Gasūns_
