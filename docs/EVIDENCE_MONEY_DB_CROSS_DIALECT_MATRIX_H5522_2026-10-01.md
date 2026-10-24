# Evidence — H5522: Money DB cross-dialect invariant acceptance matrix (2026-10-01)

Matrix suite: `tests/Feature/Money/MoneySchemaAcceptanceMatrixTest.php` (3 probes, 16 assertions).
Defect sources: Systema PRs #2855 (H5444 payout packages) and #2859 (H5480 bank statement credits), both closed/merged.

## Matrix axes

| Axis | Values exercised |
|---|---|
| Write path | ORM (Eloquent date-cast) vs raw `DB::table()->insert/update` |
| Value shape | date `Y-m-d` vs datetime `Y-m-d H:i:s` into DATE columns |
| Dialect | SQLite (`:memory:`) locally + MySQL 8.4 in CI (`ci.yml` job `mysql-finance-tests`) |
| Migration order | forward `up()`, dependency-correct rollback `down()`, forward rebuild |

## Exact CI commands

SQLite leg (runs in the main CI test job, phpunit.xml default):

```sh
php artisan test --filter=MoneySchemaAcceptanceMatrixTest
```

MySQL/MariaDB leg (ci.yml `mysql-finance-tests`, mysql:8.4, `DB_CONNECTION=mysql`,
`log_bin_trust_function_creators=1`; filter extended by this handoff to attach the
matrix plus the payout/statement suites to the finance lane):

```sh
php artisan test --filter='TochkaWebhookTest|MutualSettlementTest|FinanceIdempotencyTest|FinanceLockingMySqlTest|LedgerCoreTest|LedgerVerifierFindingsTest|LedgerBackfillReportTest|DailyReconciliationTest|RefundAccessPolicyTest|BankStatementCreditsTest|PayoutPackageTest|PayoutRefundAndDirectReceiptTest|MoneySchemaAcceptanceMatrixTest'
```

## Fixture transcript (SQLite leg, `MATRIX_TRANSCRIPT=1`)

```
[MATRIX] driver=sqlite orm_period_start='2026-09-01 00:00:00' date_part='2026-09-01'
```

This is the #2855 defect class in one line: Eloquent date-casts write datetime
strings; raw inserts write `Y-m-d`. The `tpp_bi` period-uniqueness rule must
compare through `date(...)` (both dialects) or the ORM/raw pair never collides.
On MySQL the DATE column truncates by itself; the normalization is idempotent there.

Local matrix + affected money suites (SQLite): **58 passed, 219 assertions**
(`BankStatementCreditsTest|PayoutPackageTest|PayoutRefundAndDirectReceiptTest|DailyReconciliationTest|MoneySchemaAcceptanceMatrixTest`).

## Mutation receipts (each defect reintroduced → RED → reverted → GREEN)

### M1 — period uniqueness (`tpp_bi` date() normalization removed)

Patch: `date(p.period_start) = date(NEW.period_start) AND date(p.period_end) = date(NEW.period_end)`
→ `p.period_start = NEW.period_start AND p.period_end = NEW.period_end`.
Result: **RED** — ORM duplicate accepted:

```
Tests\Feature\Money\MoneySchemaAcceptanceMatrixTest
⨯ period uniqueness holds for orm date and raw datetime writes
1   tests\Feature\Money\MoneySchemaAcceptanceMatrixTest.php:137
Tests:    1 failed  (4 assertions)
```

Reverted → GREEN (3 passed, 16 assertions).

### M2 — statement-day coverage (covering condition loosened to overlap)

Patch in `BankStatementControl::source()`: `covers_to >= to.subSecond()` → `covers_to >= from`.
Result: **RED** — a partial-day import (00:00–11:59:59) counted as coverage:

```
⨯ statement day coverage requires one full day import
1   tests\Feature\Money\MoneySchemaAcceptanceMatrixTest.php:163
    partial-day import must never count as coverage
Tests:    1 failed  (3 assertions)
```

Reverted → GREEN.

### M3 — FK rollback order (parent dropped before child in `down()`)

Patch: `2026_09_25_090000_create_bank_statement_credit_tables.php::down()` drops
`bank_statement_imports` before `bank_statement_credits`, with a seeded child row.
Result: **RED** — rollback dies on the FK:

```
SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed
Tests:    1 failed  (3 assertions)
```

Reverted → GREEN. The green path proves: wrong-order parent-first drop is
refused on the live schema, the real `down()` rolls back child-first with rows
present, and `up()` rebuilds tables plus working append-only triggers.

## Repairs

None: all three historical defects are already fixed on `main` (inside #2855/#2859
or follow-ups); each mutation re-introduction was demonstrated RED and reverted.
No currently reproducible defect outside those PRs was found by the matrix.
