# Признание выручки по методу начисления (accrual)

_Created: 08-07-2026 · Last updated: 02-09-2026_

Ground-truth по фазе B плана noboring [`/cases/education`](https://noboring-finance.ru/cases/onlayn-schkola-viveli-sobstvennika-iz-operacionki/) ([H258](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H258-Opus_Systema-Sanscriticum_revenue_recognition_accrual_06.07.26.md)). Решение MG 06-07-2026: LMS ведет выручку **и кассово, и по начислению**, а не только кассой.

## Зачем

Кейс онлайн-школы «Аура»: школа признавала всю выручку годового курса сразу при оплате, а преподавателям платила весь год → искаженная помесячная прибыль и системный риск кассового разрыва («прибыль есть, а денег нет»). Лекарство финдиректора — **признавать выручку по методу начисления**: годовой курс, оплаченный сразу, отражается долями по месяцам занятий, а не целиком в месяц оплаты.

## Что где лежит

| Слой | Файл | Роль |
|---|---|---|
| Таблица-субледжер | [`revenue_schedules`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_08_130000_create_revenue_schedules_table.php) | строка = доля суммы платежа, признаваемая в месяце `period_month` (`YYYY-MM`) |
| Модель | [`RevenueSchedule`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/RevenueSchedule.php) | read-only взгляд на строку графика |
| Общий алгоритм атрибуции | [`BlockMonthRecognition`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/BlockMonthRecognition.php) | единственный источник раскладки для ОБОИХ потребителей: `coveredBlockNumbers()`, `distribute()`, `attribute()` |
| Алгоритм раскладки | [`RevenueRecognitionService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/RevenueRecognitionService.php) | `sharesForPayment()` — платеж → `['YYYY-MM' => сумма]`; `attributionForPayment()` — то же плюс ИМЯ механизма |
| Перепись атрибуции | [`recognition:attribution-audit`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/AuditRecognitionAttribution.php) | только чтение: чем признана каждая строка и что изменит сторож штампованного прогона |
| Персистентность + своды | [`RevenueScheduleService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/RevenueScheduleService.php) | `regenerateFor()`, `backfillAll()`, `recognizedForMonth()`, `deferredRevenueAsOf()` |
| Генерация | [`PaymentObserver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentObserver.php) | `created`/`updated` → `regenerateFor()` |
| Бэкофилл истории | [`revenue:backfill-schedule`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/BackfillRevenueSchedule.php) | `--dry-run` — план без записи |
| Витрина | [`FinanceCockpit`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/FinanceCockpit.php) → [`FinanceCockpitReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/FinanceCockpitReport.php) | вкладка «ОПиУ (начисление)» + строка «отложенная выручка» |

## Алгоритм признания

Тот же, что в начислении ЗП преподавателям ([`TeacherSalaryService::recognizedShares`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php)):

1. **Override** — если у платежа задан `salary_recognition_month` (`YYYY-MM`), вся сумма признается в этом месяце.
2. **Иначе** — платеж раскладывается на покрытые блоки (`start_block`/`end_block`; `full`/депозит/legacy → все блоки курса), доля за блок признается в месяце `CourseBlock.starts_at`.
3. **Fallback** — блок без даты или платеж без курса/блоков (разовый, короткий продукт) → месяц `created_at`.
4. **Сторож штампованного прогона** (H3951, флаг, дефолт OFF) — см. раздел ниже.

Раскладка живёт в общем [`BlockMonthRecognition`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/BlockMonthRecognition.php); `RevenueRecognitionService` и `TeacherSalaryService` — два потребителя ОДНОГО алгоритма, а не две его копии.

**Каждая строка называет свой механизм.** `attributionForPayment()` (выручка) и `recognizedAttribution()` (ЗП) возвращают `['shares' => …, 'mechanism' => …, 'stamped' => bool]`, где механизм — одна из констант `BlockMonthRecognition`:

| Константа | Значение | Что означает |
|---|---|---|
| `BY_COLUMN` | `column` | ручной `salary_recognition_month` — вся сумма в один месяц |
| `BY_BLOCKS` | `blocks` | раскладка по месяцам покрытых блоков |
| `BY_CREATED` | `created` | платёж без курса/блоков → месяц оплаты |
| `BY_STAMPED_RUN` | `blocks_stamped_run` | покрытые блоки — штамп бэкофилла → месяц оплаты (только при флаге ON) |

Смешивать механизмы молча нельзя: строка обязана сказать, чем именно она признана.

## Инварианты

- **Σ долей одного платежа = сумме платежа.** Поэтому Σ признанной выручки за всё время == Σ кассовой выручки ([`revenueForWindow`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/FinanceCockpitReport.php)) — вкладки ОПиУ сходятся, accrual лишь перераспределяет ту же сумму между месяцами.
- **Набор платежей-выручки идентичен кассовому:** `paid` + `real` (не conditional) + `schoolReceived` + не `Расход`/`salary_payout` + `amount > 0`. Депозиты/пробные ВХОДЯТ (реальные деньги курса; при покупке зачитываются — двойного счета нет). Прямые платежи преподавателю — не выручка.
- **Субледжер, а не источник истины.** `regenerateFor` = delete+insert на платеж; команда `revenue:backfill-schedule` пересобирает всё идемпотентно → дрейф лечится полным пересбором. Кассовая вкладка и ДДС остаются кассовыми (нужны для сверки с банком).

## Отложенная выручка (deferred revenue)

`deferredRevenueAsOf($period)` = получено кассой по конец периода − **возвращено ученикам** (привязанные возвраты, H352) − признано накопительно (`period_month <= $period`). Это «замороженный» разрыв между кассой и прибылью:

- **> 0** — аванс за неоказанную услугу (норма для предоплаты годового курса вперед), обязательство перед студентами.
- **< 0** — выручка признана раньше кассы (поздняя оплата уже прошедших блоков): начисленная, но еще не полученная выручка; помечается в UI отдельно.

Вычет возвратов активен только при включенном реверсе (`revenue.reverse_unrecognized_on_refund`); без него отложенная выручка = касса − признано, как раньше.

## Решения финдира (08-07-2026)

Оба открытых вопроса H258 закрыты MG 08-07-2026 (H352):

1. **Признание — линейно по месяцам блоков** (по прогрессу отклонено). Это и есть текущий дефолт [`RevenueRecognitionService::sharesForPayment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/RevenueRecognitionService.php): сумма делится поровну на покрытые блоки, доля признается в месяце начала блока. Кода не потребовало.

2. **Реверс непризнанного остатка при возврате — ДА**, модель **«отдельный `Расход`, заработанное остается»**:
   - Возврат средств ученику заводится **отдельным платежом `Расход`** (как и раньше, виден в ДДС), а поле `refund_of_payment_id` указывает исходную оплату.
   - Признание выручки исходного платежа **усекается по месяц возврата**: уже отработанные месяцы остаются выручкой, непризнанный будущий остаток сторнируется ([`RevenueScheduleService::resolveShares`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/RevenueScheduleService.php)).
   - **Отложенная выручка вычитает возвращенное** — иначе завышала бы обязательство на уже возвращенную сумму (Σ: касса − возвраты − признано).
   - Всё за флагом `revenue.reverse_unrecognized_on_refund` в [`config/revenue.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/revenue.php) (дефолт **OFF** = поведение до H352). Включает финдир через `.env` без деплоя, затем `php artisan revenue:backfill-schedule` пересобирает историю.

**Инвариант при реверсе:** для платежа с возвратом `Σ график ≤ сумма платежа` (равенство — когда возврата нет); разница = сторнированный непризнанный остаток. Для платежей без возврата инвариант `Σ = сумма` держится.

**Что осталось за рамками (по проекту, не баг):** автосвязь `Расход`→исходный платеж заводится вручную в админке (селектор в `PaymentResource`); эвристический матчинг по сумме/дате НЕ делаем. Возврат через смену статуса исходного платежа на `canceled` по-прежнему удаляет весь график (услуга не оказана) — это другой сценарий, не частичный возврат.

## Чем чревато включение реверса (флаг ON на проде с 12-07-2026)

`REVENUE_REVERSE_UNRECOGNIZED_ON_REFUND=true` включен на проде 12-07-2026 вместе с
бэкофиллом истории. Последствия, о которых должен помнить каждый, кто читает
финансовые страницы:

1. **История меняется задним числом.** `revenue:backfill-schedule` пересобирает
   графики всех платежей, поэтому «ОПиУ (начисление)» прошлых месяцев для платежей
   с привязанными возвратами уже НЕ совпадает с тем, что финдир видел до включения.
   Вслед за ОПиУ едут все производные: EBITDA, «Фонды прибыли» (фаза D — фондирование
   считается от прибыли месяца), «KPI делегирования», дефолты «Инвест-решения».
   Сравнивать отчеты «до/после 12-07-2026» без поправки на реверс нельзя.

2. **Реверс срабатывает только при ручной привязке возврата.** Триггер — поле
   `refund_of_payment_id` у платежа-`Расход` (селектор в `PaymentResource`).
   Незалинкованный возврат флаг молча игнорирует: график исходного платежа остается
   полным → будущие месяцы показывают выручку, которой не будет, а отложенная
   выручка завышена на возвращенную сумму. **Дисциплина линковки возвратов в
   админке — обязательное условие честности цифр**; сам флаг ее не обеспечивает.

3. **Вкладки ОПиУ расходятся на сумму сторнированных остатков — осознанно.**
   Инвариант «Σ признанной выручки == Σ кассовой» для платежей с возвратом
   ослаблен до `Σ график ≤ сумма платежа`. При сверке кассовой и начисленной
   вкладок расхождение ровно на сторнированные непризнанные остатки — норма,
   а не дрейф субледжера.

4. **Выключение обратно — тоже ретроактивно.** Флаг OFF + `revenue:backfill-schedule`
   вернет доисторическое поведение (полные графики, отложенная выручка без вычета
   возвратов) — но опять перепишет историю. Любое переключение флага = событие
   уровня финдира с фиксацией даты, а не тумблер для экспериментов.

5. **Не путать с отменой платежа.** Смена статуса исходного платежа на `canceled`
   удаляет весь график (услуга не оказана) независимо от флага — реверс касается
   только частичных возвратов отдельным `Расход`-платежом.

## Сторож ШТАМПОВАННОГО ПРОГОНА блоков (H3951, флаг дефолт OFF)

`REVENUE_RECOGNITION_STAMPED_BLOCK_RUN_GUARD` / [`config/revenue.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/revenue.php).
**Дефолт `false` = поведение до H3951 байт-в-байт.**

### Дефект

Дата старта блока — не всегда событие расписания. Массовый импорт проставляет
десяткам блоков ОДИН И ТОТ ЖЕ день (класс ловушек [Uprava FINDINGS §621](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md):
«одинаковая дата у десятков строк — отметка миграции, а не событие жизненного
цикла»). На проде 02-09-2026: у курса 266 блоки 1–50 стоят штампом `2025-03-14`
(тот же штамп — на курсах 334/356/357/366), а блоки 51–70 идут настоящим
28-дневным циклом. Предоплата августа 2026 на 36 блоков вперёд покрывает блоки
1–36, целиком внутри штампа, — и вся сумма признаётся на **17 месяцев назад**, в
закрытый период. ЗП преподавателя за месяц прихода денег показывает по этой
строке ноль.

Запись такой даты уже запрещена: [`BackfillCourseBlockDates`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/BackfillCourseBlockDates.php)
отказывается проставлять дату, когда все блоки курса свелись к одному дню.
Сторож H3951 — **читающая** половина того же правила: данные, записанные до
запрета, всё ещё лежат в базе.

### Предикат — ПОБЛОЧНЫЙ, не покурсовой

`BlockMonthRecognition::coveredRunIsStamped($covered, $blockDates)` истинен, когда
покрытых блоков ≥ 2, каждый датирован и все даты совпадают. Покурсовой предикат
(«весь курс на одной дате») проверен на проде и отвергнут: курс 266 покурсово НЕ
вырожден (70 датированных блоков на 21 дате), то есть промахнулся бы мимо самого
дефекта, зато срабатывал бы на курсах, к дефекту отношения не имеющих.
Одноблочные платежи (основная масса популяции) предикат не трогает по построению.

### Что делает флаг

- **OFF (дефолт):** доли считаются ровно как раньше, механизм по-прежнему
  `BY_BLOCKS`, но поле `stamped` уже `true` — **аудит видит затронутые строки ДО
  того, как поведение поменяется**.
- **ON:** такой платёж признаётся механизмом `BY_STAMPED_RUN` — вся сумма в месяц
  оплаты. Месяц оплаты для штампованного прогона — **тоже не истина, а НАЗВАННЫЙ
  запасной вариант**: он лишь перестаёт уносить деньги в закрытый период на
  полтора года назад. Ручной `salary_recognition_month` бьёт сторож в обоих
  состояниях.

Месяц не выдумывается никогда: отсутствующая атрибуция остаётся `NULL` и падает в
именованный запасной вариант, а не в подогнанный под баланс месяц.

### Как посмотреть дельту

```bash
php artisan recognition:attribution-audit          # таблицы
php artisan recognition:attribution-audit --json   # машинный вывод
```

Команда строго читающая: она не пишет ни в `payments`, ни в `teacher_payouts`, ни
в `salary_recognition_month`. Три секции — перепись механизмов по всей популяции,
поимённо строки со сменой месяцев, и ЗП по преподавателям «до / после» за
затронутые месяцы. Секция 3 берёт СВЕЖИЙ экземпляр `TeacherSalaryService` на
каждое состояние флага: сервис мемоизирует сводку, и переключение конфига на
прогретом объекте сравнило бы два одинаковых ответа из кэша.

Пины: [`BlockMonthRecognitionTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Unit/BlockMonthRecognitionTest.php)
(предикат + `attribute()` в обоих состояниях флага, включая форму курса 266),
[`TeacherSalaryAccrualTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/TeacherSalaryAccrualTest.php)
(дефолт OFF ничего не меняет; ON переносит предоплату в месяц оплаты; настоящее
расписание не трогается; override выигрывает).

_Dr. Mārcis Gasūns_
