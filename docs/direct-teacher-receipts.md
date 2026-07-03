# Прямые платежи на личный счёт преподавателя — учёт и авто-зачёт

_Created: 03-07-2026 · Last updated: 03-07-2026_

> Статус: **слои 1–3 реализованы** (03-07-2026, MG go), issue
> [#270](https://github.com/gasyoun/Systema-Sanscriticum/issues/270). Слой 4 (UI
> калькулятора + учёт зачтённого) — открытый follow-up, см. §7. Ниже — схема
> миграции, инварианты и точные точки врезки. Пример-мотиватор обезличен
> (репозиторий публичный).

## 0. Что сделано / что осталось

| Слой | Содержание | Статус |
|---|---|---|
| 1 | Миграция `received_account`/`received_by_teacher_id`/`payer_note`; константы/скоупы/связь/guard на [`Payment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Payment.php); форма захвата в [`PaymentResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/PaymentResource.php) с проверкой совпадения валют | ✅ `9872903` |
| 2 | Исключение прямых платежей из выручки (4 точки [`TeacherSalaryService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php)) — снят двойной счёт | ✅ `61918f6` |
| 3 | `directReceiptsForTeacher()` + поля в `summaryForAll` + `$directOffset` в `blockPayoutTotal`; тест `DirectTeacherReceiptTest` (6) | ✅ `61918f6` |
| 4 | UI калькулятора: строка «Прямые платежи преподавателю (зачёт)» + **учёт уже зачтённого** (чтобы один платёж не зачёлся в двух выплатах) | ⏳ §7 |

**Следствие принятой модели (важно, MG в курсе):** «исключить из выручки + зачесть
номинал» воспроизводит цифру бухгалтера точно (начислено − номинал = к оплате), но
означает, что **школа НЕ берёт свою долю** с денег, собранных преподавателем напрямую —
преподаватель оставляет себе полный номинал. Это соответствует текущей практике учёта.

## 1. Проблема

Иногда студент оплачивает курс, который ведёт преподаватель, **напрямую на личный
(зарубежный) счёт преподавателя** — минуя кассу школы, иногда через посредника-
плательщика (платит одно лицо за другое). Бухгалтер учитывает это на уровне
человека: суммирует всё, что преподавателю **начислено** за отчётный период по его
курсам, и вычитает всё, что он **уже получил на руки** напрямую, — школа переводит
разницу.

Сейчас в системе:

- **Начисление** (валовое) считается корректно и совпадает с бухгалтером:
  формула калькулятора `(база × коэф%) × процент%` — см.
  [`TeacherSalaryService::blockPayoutTotal()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php)
  (коэффициент по умолчанию 92). Перерасчёты за прошлые блоки — механизм
  «поздние оплаты» (`availablePriorBlockPayments` / `priorBlocksTotal`).
- **Прямых платежей на личный счёт преподавателя система не различает.** В таблице
  `payments` нет полей «на чей счёт пришло», «кто фактически заплатил / через кого».
  Дата (`created_at`, задаётся вручную) и сумма (`amount` + справочные
  `foreign_amount`/`foreign_currency`) — есть; посредник и признак получателя —
  негде хранить. Вычет таких сумм из гонорара сейчас делается **вручную агрегатом**
  через поле «Удержание (₽)» или выплату-аванс — без даты, плательщика и привязки к
  конкретному платежу.

### Ключевой риск — двойной учёт

Если прямой платёж завести как обычный `Payment` на курсе преподавателя, он попадёт в
**выручку курса** и сгенерирует преподавателю **его же процент** сверху — при том, что
преподаватель уже держит **полную** сумму на руках. Поэтому прямой платёж должен быть
исключён из выручки-для-начисления и учтён **только** как зачёт по номиналу.

## 2. Схема данных (миграция, аддитивная, безопасная на живой таблице)

Новая миграция `..._add_direct_receipt_fields_to_payments.php`:

```php
Schema::table('payments', function (Blueprint $table) {
    // Куда физически пришли деньги. 'school' (касса) | 'teacher_personal' (личный счёт препода).
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
  `foreign_currency` должна совпадать с `payout_currency` преподавателя (иначе зачёт
  считать нельзя — валюты не сводятся молча).

## 3. Исключение из выручки (против двойного учёта)

Прямой платёж «мимо кассы» не является выручкой курса для начисления. Врезка — в тех
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
`status = paid` независимо от `received_account` — студент получает доступ. Отчёт
должников считает покрытие блоков по оплаченным платежам — прямой платёж по-прежнему
покрывает блок, поэтому студент **не** становится ложным должником. Проверить, что
[`DebtorsReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtorsReport.php)
не завязан на выручку и НЕ исключает `teacher_personal` (нам нужно, чтобы включал — для покрытия/долга).

## 4. Авто-зачёт (сбор вычета)

Новый метод — параллель `returnsForTeacher()` (тот собирает «Расход» помесячно):

```php
/**
 * Прямые платежи, полученные преподавателем на личный счёт, признанные в окне —
 * автоматический зачёт в счёт выплаты по НОМИНАЛУ в валюте выплаты преподавателя.
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
3. Сумма зачёта: `foreign_amount`, когда `foreign_currency === teacher->payout_currency`;
   иначе строка помечается `mismatch = true` и в total **не** идёт (её видно в UI как
   требующую ручной конвертации — молча валюты не мешаем).
4. Вернуть `total` (в `payout_currency`), сам `currency` и построчную детализацию
   (курс, сумма, дата чека, плательщик, студент) — это и есть ответ «какого числа и
   сколько».

### Куда total подаётся

- **`summaryForAll()`** — добавить `direct_receipts_period` и `direct_receipts_all_time`
  (в `payout_currency` преподавателя). Свести с рублёвым `balance` **нельзя молча**
  (валюты разные), поэтому:
  - `balance` (₽) по начислению оставляем как есть;
  - `direct_receipts_*` показываем **отдельной строкой в валюте выплаты**, а нетто «к
    переводу» считаем в валюте выплаты в момент выплаты — так же, как это делает
    бухгалтер (нетит в €). Это рекомендованный вариант (без неоднозначности курса).
    Альтернатива — конвертировать €→₽ по дате и вычесть из `balance` — отвергнута:
    плодит зависимость от курса и расходится с человеко-считаемым нетто.
- **Калькулятор выплат** ([`TeacherSalaries`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/TeacherSalaries.php)) —
  вместо «слепого» ручного «Удержание» добавить **отдельную предзаполняемую строку
  «Прямые платежи преподавателю (зачёт)»**: тянется из `directReceiptsForTeacher()` за
  окно выплаты, показывается построчно (дата/плательщик/курс), вычитается по номиналу.
- **`blockPayoutTotal()`** — добавить отдельный параметр `$directOffset = 0.0`
  (вычитается как есть, без коэффициента и процента), чтобы в аудите зачёт-по-прямым-
  платежам не смешивался со штрафами/удержаниями.
- **Аудит `TeacherPayout.breakdown`** — сохранять массив `direct_receipts` (те же
  строки), чтобы в записи о выплате было видно, **какие датированные платежи** зачтены.

## 5. Инварианты и тесты

- Прямой платёж **не** попадает в `computeCourseRevenue` (нет двойного начисления) —
  тест на паре (курс препода + прямой платёж): начисление не меняется.
- Прямой платёж **открывает доступ** и **не делает студента должником** — тест на
  `DebtorsReport` + `grantAccess`.
- `directReceiptsForTeacher()` суммирует только свою валюту, `mismatch` не входит в total.
- Нетто «к переводу» в валюте выплаты = начислено − выплачено − прямые зачёты —
  сверка с разобранным кейсом (обезличенно).
- Инвариант формы: `teacher_personal` без `received_by_teacher_id` / с чужой валютой — отклоняется.

## 6. Объём и порядок

1. Миграция + поля/константы/скоупы на `Payment` + правило формы (S).
2. Исключение из выручки (4 точки) + тест на не-двойной-учёт (S).
3. `directReceiptsForTeacher()` + подача в `summaryForAll` и калькулятор + `breakdown` (M).
4. UI: строка «Прямые платежи преподавателю (зачёт)» в калькуляторе и колонка в ведомости (M).

Денежный контур — правило репозитория: осторожный review на каждое изменение
(см. [`.ai_state.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.ai_state.md) Now-B/Now-C).

## 7. Открытый follow-up (слой 4): UI калькулятора + учёт зачтённого

Слои 1–3 дают: захват прямого платежа (дата/плательщик/валюта), исключение из выручки
и `directReceiptsForTeacher()` — зачёт **виден** в сводке
([`summaryForAll`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php),
поля `direct_receipts_*`), но **не вычитается автоматически при постинге выплаты**.
`blockPayoutTotal()` уже принимает `$directOffset`, но калькулятор
[`TeacherSalaries`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/TeacherSalaries.php)
его пока не подаёт — сейчас зачёт делается прежним ручным «Удержанием».

Слой 4 (отдельный review — денежный контур):

1. **Учёт зачтённого** (критично, иначе двойной зачёт). По образцу авансов
   (`TeacherPayout::settled_at`) и `paidShareKeys()` для поздних оплат: пометить,
   какие прямые платежи уже вошли в выплату. Вариант — `settled_at`/`settled_payout_id`
   на `payments` для строк `teacher_personal`, либо снимок `direct_receipts` (id
   платежей) в `TeacherPayout.breakdown` + фильтр «ещё не зачтённых» в калькуляторе.
2. **Строка калькулятора** «Прямые платежи преподавателю (зачёт)» — предзаполняется
   из `directReceiptsForTeacher()` (только ещё-не-зачтённые, в валюте выплаты),
   построчно (дата/плательщик/курс/студент), подаётся в `blockPayoutTotal()` как
   `$directOffset`, снимок пишется в `breakdown['direct_receipts']`.
3. **Идемпотентность/откат:** удаление выплаты снимает пометку «зачтено» (как
   `TeacherPayout::deleting` снимает зеркало-платёж).
4. **Тесты:** один платёж не зачитывается дважды; удаление выплаты возвращает его в
   пул; строки с mismatch валюты в авто-зачёт не попадают.

До слоя 4 зачёт остаётся ручным («Удержание»), но теперь виден и посчитан в сводке.

_Dr. Mārcis Gasūns_
