_Created: 25-10-2026 · Last updated: 25-10-2026_

# H7268 — типизированный вердикт по строке `promise_grant_#10` (payment 14577)

Исполнитель: DeepSeek v4.1 Flash (`deepseek/deepseek-flash`), worker 1, opencode.
Файл-хендофф: [H7268](https://github.com/gasyoun/Uprava/blob/main/handoffs/H7268-DeepSeek_Systema-Sanscriticum_promise-grant-payment-hygiene_25.10.26.md) (остаток H5442).
Контракт: [docs/money-access-core-manual.md §1.6, §640](money-access-core-manual.md).

## 1. Вердикт

**`LEGIT_ACCESS_MARKER_SILENT_AUDIT_GAP`** — законная строка доступа «под обещание»;
денег не приносит, выручкой не читается; единственный пробел — отсутствие
`created`-следа в `payment_audits` (документированное следствие `withoutEvents()`
в `ConditionalAccessGranter`), восстанавливается append-only.

Строка 14577 **не** является ни выручкой, ни дефектом денег. Изменять её
`status`, сумму или доступ не требуется и не разрешено этой задачей.

| Поле | Факт (прод, 25-10-2026) | Вердикт против контракта |
|---|---|---|
| `user_id` / `course_id` | 6714 / 455 | совпадают с обещанием #10 |
| `amount` | `0.00` | ✅ доступ без денег |
| `status` | `paid` | ✅ статус — это и есть access-key гейта (`Payment::PAID_STATUSES`) |
| `is_conditional` | `1` | ✅ исключена из каждого денежного агрегата (`Payment::real()`) |
| `tariff` | `block_1` | ✅ доступ-образный тариф |
| `linked_promise_id` / `transaction_id` | `10` / `promise_grant_#10` | ✅ называют одно обещание |
| `first_paid_at` | `2026-10-08 17:02:55` | ✅ H1645-защита видит строку |
| `payment_audits` | 0 строк | ⚠️ пробел следа (см. §3) |
| `Payment::real()` | строка отсутствует | ✅ в выручку не попадает |
| обещание #10 | `status=active`, `promised_at=2026-10-31`, `amount=4800.00`, `actual_paid_at=null` | ✅ долг ещё открыт; строка — маркер отсрочки, а не оплата |
| группы курса | у студента нет группы курса 455 (группа 152, создана 29-09-2026), `course_user` пуст | ⚠️ доступ-слой, НЕ деньги (см. §4) |

## 2. Census — все строки `promise_grant_#<id>` на проде (read-only)

```
#13819 promise=6  amount=0.00 status=paid cond=1 first_paid=2026-06-29 14:36:22 audits=3 groups=1 course_user=1
#14066 promise=7  amount=0.00 status=paid cond=1 first_paid=2026-07-30 12:17:57 audits=0 groups=0 course_user=0
#14577 promise=10 amount=0.00 status=paid cond=1 first_paid=2026-10-08 17:02:55 audits=0 groups=0 course_user=0
#14635 promise=11 amount=0.00 status=paid cond=1 first_paid=2026-10-16 13:17:53 audits=0 groups=1 course_user=1
```

- 4 строки, все контрактны по деньгам: `amount=0`, `is_conditional=1`, `status` в
  `PAID_STATUSES`, `linked_promise_id` = номер из `transaction_id`.
- `0` строк попадают в `Payment::real()` (проверено отдельным запросом) — ни одна
  не читается как выручка.
- `3` строки без `payment_audits` → ровно те, что подлежат append-only бэкфиллу.

## 3. Что делает поставленный код

1. [`PromiseGrantClassifier`](../app/Services/Promises/PromiseGrantClassifier.php) —
   read-only классификатор. Кандидаты: `is_conditional=1` **или** `transaction_id LIKE 'promise_grant_#%'`
   **или** `linked_promise_id IS NOT NULL` (потеря флага тоже ловится). Проверяет
   флаг, статус, нулевую сумму, выручко-скоуп, соответствие `transaction_id ↔ linked_promise_id`,
   существование обещания, совпадение студента и курса, доступ-образный тариф,
   `first_paid_at`, наличие следа. Вердикты: `LEGIT_ACCESS_MARKER`,
   `LEGIT_ACCESS_MARKER_SILENT_AUDIT_GAP`, `HUMAN_REQUIRED`, `DEFECT`; у каждого —
   `repair: none|audit_backfill|human`.
2. `php artisan payments:classify-promise-grants [--id=*] [--json=path]` — отчёт,
   exit `1` при `DEFECT`/`HUMAN_REQUIRED`, печатает доказательство «только чтение»
   (счётчики `payments`, `payment_audits`, `group_user`, `course_user` до/после).
3. `php artisan payments:backfill-promise-grant-audits [--apply] [--id=*]` —
   **единственная** запись: одна строка `payment_audits` (`action=created`, снимок
   полей + `audit_backfill` с основанием) на контрактную строку без следа.
   `payments` не обновляется (никакого UPDATE), доступ (`group_user`,
   `course_user`, `lesson_access_grants`) и деньги (суммы, депозит, ЗП) не
   трогаются. Дефектные строки и строки, требующие человека, **пропускаются**,
   а не «чинятся» молча. Идемпотентно.
4. Тесты: `tests/Feature/Promises/PromiseGrantClassifierTest.php` (6) и
   `tests/Feature/Promises/PromiseGrantAuditBackfillTest.php` (4).

### Инвариант (проверяется тестами и командой)

> Каждая строка `promise_grant_#N` обязана: иметь `is_conditional=1`, `amount=0`,
> `status ∈ PAID_STATUSES`, `tariff ∈ {full, lesson, block_N}`,
> `linked_promise_id=N`, `first_paid_at≠NULL`, обещание N того же студента и курса,
> и отсутствовать в `Payment::real()`.

## 4. Открытые хвосты (не в этой задаче)

1. **Доступ-слой, не деньги.** У 14577 и 14066 студент не в группе своего курса
   (`course_user` тоже пуст), хотя `ConditionalAccessGranter::grantForPromise()`
   вызывает `grantAccess()`. Уроки при этом открыты ключом `block_1`
   (`getUserUnlockedTariffs` читает `payments`), поэтому это не «оплачено без
   доступа», а расхождение дашборда/группового контура. Лечится оператором
   (запись в группу) — **грант доступа этой задачей не выдавался**.
2. **Payment 14249** оставлен куратору, как требует хендофф; не трогался.
3. **Применение бэкфилла на проде** — после мержа PR и деплоя: сухой прогон,
   затем `--apply` (ожидается 3 строки: 14066, 14577, 14635).
4. **Класс money:** закрытие ✅ — только после PASS другой сессии в `## Verifier`
   файла-хендоффа.

## 5. Проверка (для верификатора)

```bash
# локально / CI
php artisan test --filter=PromiseGrantClassifierTest
php artisan test --filter=PromiseGrantAuditBackfillTest

# прод, read-only
php artisan payments:classify-promise-grants
php artisan payments:backfill-promise-grant-audits          # dry-run
```

Прод-проба §1/§2 выполнена read-only через `php artisan tinker` (`DB::table()->select()`,
никаких `update`/`insert`), PII не выгружалась: только id, суммы, статусы, даты.
