# EVIDENCE H8245 — B2B-оплата «за группу целиком» (b2b_group + виртуальные ключи группы)

_Исполнение: 14-11-2026 · GLM 5.3 Flash (`zai-coding-plan/glm-5.3-flash`) · ветка `h8245-b2b-adara-group-payment` · PR [#4165](https://github.com/gasyoun/Systema-Sanscriticum/pull/4165). Money-контур: ревью MG, авто-merge запрещён._

## Проверки (реальные прогоны на этом дереве, Windows, PHP 8.3.36)

### 1. Профильный сьют `B2bGroupPaymentTest`

```
$ php vendor/bin/phpunit tests/Feature/Payments/B2bGroupPaymentTest.php
PHPUnit 11.5.56 · Runtime: PHP 8.3.36
........                                                            8 / 8 (100%)
Time: 00:19.229, Memory: 122.00 MB
OK (8 tests, 29 assertions)
```

8 кейсов money-контура: OFF-инертность флага (ученики группы заперты), виртуальный
ключ `full` ученику группы под ON + чужой группе не открывается, диапазонный b2b-платёж
даёт только `block_1`, плательщик без побочек (ни групп/course_user/писем/праны/Telegram,
Bus: не отправлены студенческие джобы), откат b2b-платежа НЕ детачит учеников но закрывает
ключи, начисление 30% ЗП от b2b как от обычной оплаты, блок-калькулятор атрибутирует
платёж группе только под флагом, должники снимают покрытые пары только под флагом.

### 2. Регрессия money-контура

```
$ php vendor/bin/phpunit --filter "CheckoutPriceTest|TochkaWebhookTest|TeacherSalary"
PHPUnit 11.5.56 · Runtime: PHP 8.3.36
.....                                                              70 / 70 (100%)
Time: 01:46.802, Memory: 298.00 MB
OK, but there were issues! (PHPUnit Deprecations: 3332 — pre-existing noise, 0 failures)
Tests: 70, Assertions: 266
```

### 3. Форматирование

```
$ php vendor/bin/pint --dirty --test
{"tool":"pint","result":"passed"}
```

## Независимая верификация (H4235, cross-family seat)

Explorer-субагент (отдельная сессия `ses_e2701d345ffe0T96oX6hJkz3T7`, read-only по коду
ветки) проверил 5 клеймов money-контура по file:line — **VERDICT: PASS**:

| # | Клейм | Вердикт | Evidence |
|---|---|---|---|
| 1 | Payment: константа/`isB2bGroup()`/`processB2bGroupPaid()` только curator-notify; отсечки `grantsCourseAccess`+`accessGrantingPaymentsForUser` | PASS | `app/Models/Payment.php:354,365-368,1169-1177,1816,1835` |
| 2 | `B2bGroupEntitlement::extraTariffKeysFor` — `[]` при OFF; `full`/`block_N` только членам группы с оплаченным b2b | PASS | `app/Services/Payments/B2bGroupEntitlement.php:50-52,65-98` |
| 3 | `StudentController::getUserUnlockedTariffs` мёржит ключи сервиса | PASS | `app/Http/Controllers/StudentController.php:64-74` |
| 4 | Блок-калькулятор OR-фильтр только под флагом; `DebtorsReport` guard — второй `AND NOT EXISTS` (не OR), биндинги в правильном порядке | PASS | `app/Services/TeacherSalaryService.php:691-695`; `app/Services/DebtorsReport.php:354,373,396` |
| 5 | Filament: опция+группа только под флагом; `features.b2b_group_payment` env `B2B_GROUP_PAYMENT` default false | PASS | `app/Filament/Resources/PaymentResource.php:130-132,182-183`; `config/features.php:1538` |

## Находка во время исполнения (fixed in-branch)

Первый вариант должников-guard'а был `OR EXISTS (...)` — приоритет операторов в SQL
(`OR` слабее `AND`) оставлял неплательщиков должниками даже под флагом. Поймано
собственным тестом `test_debtors_report_excludes_b2b_covered_students_only_under_flag`
(7/8 → фикс: второй `AND NOT EXISTS` → 8/8). Коммит фикса в ветке.

## Границы (что этот прогон НЕ доказывает)

- Прод-деплой и включение флага (`B2B_GROUP_PAYMENT=true` + `config:cache`) — human/ops
  шаг после merge; до флипа b2b_group-платёж — инертная денежная строка (доказано тестом OFF).
- Завод фактического платежа Алексея (нал/счёт) — ops в Filament, денег в этой ветке нет.
- CI-прогон (MySQL/Linux) — после пуша PR.

_Гасунс_
