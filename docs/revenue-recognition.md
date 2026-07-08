# Признание выручки по методу начисления (accrual)

_Created: 08-07-2026 · Last updated: 08-07-2026_

Ground-truth по фазе B плана noboring [`/cases/education`](https://noboring-finance.ru/cases/onlayn-schkola-viveli-sobstvennika-iz-operacionki/) ([H258](https://github.com/gasyoun/Uprava/blob/main/handoffs/H258-Opus_Systema-Sanscriticum_revenue_recognition_accrual_06.07.26.md)). Решение MG 06-07-2026: LMS ведёт выручку **и кассово, и по начислению**, а не только кассой.

## Зачем

Кейс онлайн-школы «Аура»: школа признавала всю выручку годового курса сразу при оплате, а преподавателям платила весь год → искажённая помесячная прибыль и системный риск кассового разрыва («прибыль есть, а денег нет»). Лекарство финдиректора — **признавать выручку по методу начисления**: годовой курс, оплаченный сразу, отражается долями по месяцам занятий, а не целиком в месяц оплаты.

## Что где лежит

| Слой | Файл | Роль |
|---|---|---|
| Таблица-субледжер | [`revenue_schedules`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_08_130000_create_revenue_schedules_table.php) | строка = доля суммы платежа, признаваемая в месяце `period_month` (`YYYY-MM`) |
| Модель | [`RevenueSchedule`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/RevenueSchedule.php) | read-only взгляд на строку графика |
| Алгоритм раскладки | [`RevenueRecognitionService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/RevenueRecognitionService.php) | `sharesForPayment()` — платёж → `['YYYY-MM' => сумма]` |
| Персистентность + своды | [`RevenueScheduleService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/RevenueScheduleService.php) | `regenerateFor()`, `backfillAll()`, `recognizedForMonth()`, `deferredRevenueAsOf()` |
| Генерация | [`PaymentObserver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentObserver.php) | `created`/`updated` → `regenerateFor()` |
| Бэкофилл истории | [`revenue:backfill-schedule`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/BackfillRevenueSchedule.php) | `--dry-run` — план без записи |
| Витрина | [`FinanceCockpit`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/FinanceCockpit.php) → [`FinanceCockpitReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/FinanceCockpitReport.php) | вкладка «ОПиУ (начисление)» + строка «отложенная выручка» |

## Алгоритм признания

Тот же, что в начислении ЗП преподавателям ([`TeacherSalaryService::recognizedShares`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php)):

1. **Override** — если у платежа задан `salary_recognition_month` (`YYYY-MM`), вся сумма признаётся в этом месяце.
2. **Иначе** — платёж раскладывается на покрытые блоки (`start_block`/`end_block`; `full`/депозит/legacy → все блоки курса), доля за блок признаётся в месяце `CourseBlock.starts_at`.
3. **Fallback** — блок без даты или платёж без курса/блоков (разовый, короткий продукт) → месяц `created_at`.

Держится **отдельным сервисом** (а не общим методом с ЗП), чтобы денежно-критичный `TeacherSalaryService` не трогать; при будущей унификации — свести оба потребителя в один источник алгоритма.

## Инварианты

- **Σ долей одного платежа = сумме платежа.** Поэтому Σ признанной выручки за всё время == Σ кассовой выручки ([`revenueForWindow`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/FinanceCockpitReport.php)) — вкладки ОПиУ сходятся, accrual лишь перераспределяет ту же сумму между месяцами.
- **Набор платежей-выручки идентичен кассовому:** `paid` + `real` (не conditional) + `schoolReceived` + не `Расход`/`salary_payout` + `amount > 0`. Депозиты/пробные ВХОДЯТ (реальные деньги курса; при покупке зачитываются — двойного счёта нет). Прямые платежи преподавателю — не выручка.
- **Субледжер, а не источник истины.** `regenerateFor` = delete+insert на платёж; команда `revenue:backfill-schedule` пересобирает всё идемпотентно → дрейф лечится полным пересбором. Кассовая вкладка и ДДС остаются кассовыми (нужны для сверки с банком).

## Отложенная выручка (deferred revenue)

`deferredRevenueAsOf($period)` = получено кассой по конец периода − **возвращено ученикам** (привязанные возвраты, H352) − признано накопительно (`period_month <= $period`). Это «замороженный» разрыв между кассой и прибылью:

- **> 0** — аванс за неоказанную услугу (норма для предоплаты годового курса вперёд), обязательство перед студентами.
- **< 0** — выручка признана раньше кассы (поздняя оплата уже прошедших блоков): начисленная, но ещё не полученная выручка; помечается в UI отдельно.

Вычет возвратов активен только при включённом реверсе (`revenue.reverse_unrecognized_on_refund`); без него отложенная выручка = касса − признано, как раньше.

## Решения финдира (08-07-2026)

Оба открытых вопроса H258 закрыты MG 08-07-2026 (H352):

1. **Признание — линейно по месяцам блоков** (по прогрессу отклонено). Это и есть текущий дефолт [`RevenueRecognitionService::sharesForPayment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/RevenueRecognitionService.php): сумма делится поровну на покрытые блоки, доля признаётся в месяце начала блока. Кода не потребовало.

2. **Реверс непризнанного остатка при возврате — ДА**, модель **«отдельный `Расход`, заработанное остаётся»**:
   - Возврат средств ученику заводится **отдельным платежом `Расход`** (как и раньше, виден в ДДС), а поле `refund_of_payment_id` указывает исходную оплату.
   - Признание выручки исходного платежа **усекается по месяц возврата**: уже отработанные месяцы остаются выручкой, непризнанный будущий остаток сторнируется ([`RevenueScheduleService::resolveShares`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/RevenueScheduleService.php)).
   - **Отложенная выручка вычитает возвращённое** — иначе завышала бы обязательство на уже возвращённую сумму (Σ: касса − возвраты − признано).
   - Всё за флагом `revenue.reverse_unrecognized_on_refund` в [`config/revenue.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/revenue.php) (дефолт **OFF** = поведение до H352). Включает финдир через `.env` без деплоя, затем `php artisan revenue:backfill-schedule` пересобирает историю.

**Инвариант при реверсе:** для платежа с возвратом `Σ график ≤ сумма платежа` (равенство — когда возврата нет); разница = сторнированный непризнанный остаток. Для платежей без возврата инвариант `Σ = сумма` держится.

**Что осталось за рамками (по проекту, не баг):** автосвязь `Расход`→исходный платёж заводится вручную в админке (селектор в `PaymentResource`); эвристический матчинг по сумме/дате НЕ делаем. Возврат через смену статуса исходного платежа на `canceled` по-прежнему удаляет весь график (услуга не оказана) — это другой сценарий, не частичный возврат.

_Dr. Mārcis Gasūns_
