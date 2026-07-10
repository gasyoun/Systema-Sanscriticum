# Прямые платежи на личный счет преподавателя — учет и авто-зачет

_Created: 03-07-2026 · Last updated: 03-07-2026_

> Статус: **все 4 слоя реализованы** (03-07-2026, MG go), issue
> [#270](https://github.com/gasyoun/Systema-Sanscriticum/issues/270) закрыт. Ниже —
> схема миграции, инварианты и точные точки врезки. Пример-мотиватор обезличен
> (репозиторий публичный).

## 0. Что сделано / что осталось

| Слой | Содержание | Статус |
|---|---|---|
| 1 | Миграция `received_account`/`received_by_teacher_id`/`payer_note`; константы/скоупы/связь/guard на [`Payment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Payment.php); форма захвата в [`PaymentResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/PaymentResource.php) с проверкой совпадения валют | ✅ `9872903` |
| 2 | Исключение прямых платежей из выручки (4 точки [`TeacherSalaryService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php)) — снят двойной счет | ✅ `61918f6` |
| 3 | `directReceiptsForTeacher()` + поля в `summaryForAll` + `$directOffset` в `blockPayoutTotal`; тест `DirectTeacherReceiptTest` (6) | ✅ `61918f6` |
| 4 | UI калькулятора: пикер «Прямые платежи преподавателю (зачет)» + **учет уже зачтенного** (`settledDirectReceiptIds` читает `breakdown.direct_receipts`; удаление выплаты освобождает платеж); зачет в валюте выплаты через курс; тест +2 (8 всего) | ✅ `6c41553` |

**Следствие принятой модели (важно, MG в курсе):** «исключить из выручки + зачесть
номинал» воспроизводит цифру бухгалтера точно (начислено − номинал = к оплате), но
означает, что **школа НЕ берет свою долю** с денег, собранных преподавателем напрямую —
преподаватель оставляет себе полный номинал. Это соответствует текущей практике учета.

## 1. Проблема

Иногда студент оплачивает курс, который ведет преподаватель, **напрямую на личный
(зарубежный) счет преподавателя** — минуя кассу школы, иногда через посредника-
плательщика (платит одно лицо за другое). Бухгалтер учитывает это на уровне
человека: суммирует всё, что преподавателю **начислено** за отчетный период по его
курсам, и вычитает всё, что он **уже получил на руки** напрямую, — школа переводит
разницу.

Сейчас в системе:

- **Начисление** (валовое) считается корректно и совпадает с бухгалтером:
  формула калькулятора `(база × коэф%) × процент%` — см.
  [`TeacherSalaryService::blockPayoutTotal()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php)
  (коэффициент по умолчанию 92). Перерасчеты за прошлые блоки — механизм
  «поздние оплаты» (`availablePriorBlockPayments` / `priorBlocksTotal`).
- **Прямых платежей на личный счет преподавателя система не различает.** В таблице
  `payments` нет полей «на чей счет пришло», «кто фактически заплатил / через кого».
  Дата (`created_at`, задается вручную) и сумма (`amount` + справочные
  `foreign_amount`/`foreign_currency`) — есть; посредник и признак получателя —
  негде хранить. Вычет таких сумм из гонорара сейчас делается **вручную агрегатом**
  через поле «Удержание (₽)» или выплату-аванс — без даты, плательщика и привязки к
  конкретному платежу.

### Ключевой риск — двойной учет

Если прямой платеж завести как обычный `Payment` на курсе преподавателя, он попадет в
**выручку курса** и сгенерирует преподавателю **его же процент** сверху — при том, что
преподаватель уже держит **полную** сумму на руках. Поэтому прямой платеж должен быть
исключен из выручки-для-начисления и учтен **только** как зачет по номиналу.

## 2. Схема данных (миграция, аддитивная, безопасная на живой таблице)

Новая миграция `..._add_direct_receipt_fields_to_payments.php`:

```php
Schema::table('payments', function (Blueprint $table) {
    // Куда физически пришли деньги. 'school' (касса) | 'teacher_personal' (личный счет препода).
    $table->string('received_account')->default('school')->after('status');
    // Какой преподаватель держит деньги (только для teacher_personal). null иначе.
    $table->foreignId('received_by_teacher_id')->nullable()->after('received_account')
        ->constrained('teachers')->nullOnDelete();
    // Плательщик/посредник свободным текстом: «S. через V., чек №…». Дата чека = created_at.
    $table->string('payer_note')->nullable()->after('received_by_teacher_id');
    $table->index(['received_by_teacher_id', 'received_account']);
});
```

Down-миграция снимает FK, индекс и три колонки. Бэкфилл не требуется: у всех
существующих строк `received_account = 'school'` по умолчанию; исторические прямые
платежи заводятся/редактируются вручную с выставлением флага.

### Модель `Payment`

- В `$fillable` добавить `received_account`, `received_by_teacher_id`, `payer_note`.
- Константы: `RECEIVED_SCHOOL = 'school'`, `RECEIVED_TEACHER = 'teacher_personal'`.
- Скоупы `scopeSchoolReceived()` / `scopeTeacherReceived()`.
- Связь `receivedByTeacher(): BelongsTo`.
- **Инвариант** (guard в `saving` + правила формы Filament): если
  `received_account = teacher_personal`, то `received_by_teacher_id` обязателен и
  `foreign_currency` должна совпадать с `payout_currency` преподавателя (иначе зачет
  считать нельзя — валюты не сводятся молча).

## 3. Исключение из выручки (против двойного учета)

Прямой платеж «мимо кассы» не является выручкой курса для начисления. Врезка — в тех
же местах, где уже исключаются `NON_REVENUE_TARIFFS`
([`TeacherSalaryService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php)):

| Метод | Что изменить |
|---|---|
| `computeCourseRevenue()` (reject `$real`) | добавить `\|\| $p->received_account === Payment::RECEIVED_TEACHER` |
| `blockGroupRevenueDetail()` (query) | добавить `->where('received_account', Payment::RECEIVED_SCHOOL)` |
| `blockFreeStudentCount()` (query) | то же (консистентность) |
| `availablePriorBlockPayments()` (цикл по платежам) | пропускать `RECEIVED_TEACHER`, как `NON_REVENUE_TARIFFS` |
| `coursePayments()` | **не менять** — грузит все; фильтры выручки исключают точечно |

**Доступ и долги НЕ трогаем.** `grantAccess()`/`enrollInCourse()` срабатывают по
`status = paid` независимо от `received_account` — студент получает доступ. Отчет
должников считает покрытие блоков по оплаченным платежам — прямой платеж по-прежнему
покрывает блок, поэтому студент **не** становится ложным должником. Проверить, что
[`DebtorsReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtorsReport.php)
не завязан на выручку и НЕ исключает `teacher_personal` (нам нужно, чтобы включал — для покрытия/долга).

## 4. Авто-зачет (сбор вычета)

Новый метод — параллель `returnsForTeacher()` (тот собирает «Расход» помесячно):

```php
/**
 * Прямые платежи, полученные преподавателем на личный счет, признанные в окне —
 * автоматический зачет в счет выплаты по НОМИНАЛУ в валюте выплаты преподавателя.
 * Признание — по дате чека (created_at) / override, как у returnsForTeacher (кассовое
 * событие, не accrual по блокам).
 *
 * @return array{total: float, currency: ?string, lines: list<array{
 *   course_title:string, amount:float, currency:string, date:?string,
 *   payer_note:?string, student:?string, mismatch:bool }>}
 */
public function directReceiptsForTeacher(Teacher $teacher, $start = null, $end = null): array
```

Логика:

1. По всем курсам преподавателя (`allTaughtCourses`) перебрать `coursePayments`, взять
   `received_account = teacher_personal` **и** `received_by_teacher_id = $teacher->id`.
2. Месяц признания: `salary_recognition_month ?: created_at->format('Y-m')`; фильтр
   `monthInWindow($month, $start, $end)`.
3. Сумма зачета: `foreign_amount`, когда `foreign_currency === teacher->payout_currency`;
   иначе строка помечается `mismatch = true` и в total **не** идет (ее видно в UI как
   требующую ручной конвертации — молча валюты не мешаем).
4. Вернуть `total` (в `payout_currency`), сам `currency` и построчную детализацию
   (курс, сумма, дата чека, плательщик, студент) — это и есть ответ «какого числа и
   сколько».

### Куда total подается

- **`summaryForAll()`** — добавить `direct_receipts_period` и `direct_receipts_all_time`
  (в `payout_currency` преподавателя). Свести с рублевым `balance` **нельзя молча**
  (валюты разные), поэтому:
  - `balance` (₽) по начислению оставляем как есть;
  - `direct_receipts_*` показываем **отдельной строкой в валюте выплаты**, а нетто «к
    переводу» считаем в валюте выплаты в момент выплаты — так же, как это делает
    бухгалтер (нетит в €). Это рекомендованный вариант (без неоднозначности курса).
    Альтернатива — конвертировать €→₽ по дате и вычесть из `balance` — отвергнута:
    плодит зависимость от курса и расходится с человеко-считаемым нетто.
- **Калькулятор выплат** ([`TeacherSalaries`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/TeacherSalaries.php)) —
  вместо «слепого» ручного «Удержание» добавить **отдельную предзаполняемую строку
  «Прямые платежи преподавателю (зачет)»**: тянется из `directReceiptsForTeacher()` за
  окно выплаты, показывается построчно (дата/плательщик/курс), вычитается по номиналу.
- **`blockPayoutTotal()`** — добавить отдельный параметр `$directOffset = 0.0`
  (вычитается как есть, без коэффициента и процента), чтобы в аудите зачет-по-прямым-
  платежам не смешивался со штрафами/удержаниями.
- **Аудит `TeacherPayout.breakdown`** — сохранять массив `direct_receipts` (те же
  строки), чтобы в записи о выплате было видно, **какие датированные платежи** зачтены.

## 5. Инварианты и тесты

- Прямой платеж **не** попадает в `computeCourseRevenue` (нет двойного начисления) —
  тест на паре (курс препода + прямой платеж): начисление не меняется.
- Прямой платеж **открывает доступ** и **не делает студента должником** — тест на
  `DebtorsReport` + `grantAccess`.
- `directReceiptsForTeacher()` суммирует только свою валюту, `mismatch` не входит в total.
- Нетто «к переводу» в валюте выплаты = начислено − выплачено − прямые зачеты —
  сверка с разобранным кейсом (обезличенно).
- Инвариант формы: `teacher_personal` без `received_by_teacher_id` / с чужой валютой — отклоняется.

## 6. Объем и порядок

1. Миграция + поля/константы/скоупы на `Payment` + правило формы (S).
2. Исключение из выручки (4 точки) + тест на не-двойной-учет (S).
3. `directReceiptsForTeacher()` + подача в `summaryForAll` и калькулятор + `breakdown` (M).
4. UI: строка «Прямые платежи преподавателю (зачет)» в калькуляторе и колонка в ведомости (M).

Денежный контур — правило репозитория: осторожный review на каждое изменение
(см. [`.ai_state.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.ai_state.md) Now-B/Now-C).

## 7. Слой 4 — UI калькулятора + учет зачтенного ✅ (реализовано `6c41553`)

> Доставлено 03-07-2026. Ниже — исходная спека, воплощенная как есть: пикер
> «Прямые платежи преподавателю (зачет)» в калькуляторе, `settledDirectReceiptIds()`
> / `availableDirectReceipts()` в сервисе, снимок в `breakdown.direct_receipts`,
> зачет в валюте выплаты через курс. Тесты — `DirectTeacherReceiptTest` (8).
> UX-полировка (03-07-2026): при выборе преподавателя пикер **по умолчанию
> отмечает все** доступные прямые платежи (зачитываем целиком; ненужные снимают
> вручную) — через `$set('direct_receipt_ids', …)` в `afterStateUpdated` поля препода.

Слои 1–3 дают: захват прямого платежа (дата/плательщик/валюта), исключение из выручки
и `directReceiptsForTeacher()` — зачет **виден** в сводке
([`summaryForAll`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php),
поля `direct_receipts_*`), но **не вычитается автоматически при постинге выплаты**.
`blockPayoutTotal()` уже принимает `$directOffset`, но калькулятор
[`TeacherSalaries`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/TeacherSalaries.php)
его пока не подает — сейчас зачет делается прежним ручным «Удержанием».

Слой 4 (отдельный review — денежный контур):

1. **Учет зачтенного** (критично, иначе двойной зачет). По образцу авансов
   (`TeacherPayout::settled_at`) и `paidShareKeys()` для поздних оплат: пометить,
   какие прямые платежи уже вошли в выплату. Вариант — `settled_at`/`settled_payout_id`
   на `payments` для строк `teacher_personal`, либо снимок `direct_receipts` (id
   платежей) в `TeacherPayout.breakdown` + фильтр «еще не зачтенных» в калькуляторе.
2. **Строка калькулятора** «Прямые платежи преподавателю (зачет)» — предзаполняется
   из `directReceiptsForTeacher()` (только еще-не-зачтенные, в валюте выплаты),
   построчно (дата/плательщик/курс/студент), подается в `blockPayoutTotal()` как
   `$directOffset`, снимок пишется в `breakdown['direct_receipts']`.
3. **Идемпотентность/откат:** удаление выплаты снимает пометку «зачтено» (как
   `TeacherPayout::deleting` снимает зеркало-платеж).
4. **Тесты:** один платеж не зачитывается дважды; удаление выплаты возвращает его в
   пул; строки с mismatch валюты в авто-зачет не попадают.

До слоя 4 зачет остается ручным («Удержание»), но теперь виден и посчитан в сводке.

_Dr. Mārcis Gasūns_
