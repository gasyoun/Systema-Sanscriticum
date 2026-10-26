_Created: 24-09-2026 · Last updated: 24-09-2026_

# Доказательства P3 — ежедневная сверка и правило возврата D10 (H5445)

Исполнение: [H5445 (Opus 5) — daily reconciliation, exception queue, refund access rules and control totals](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5445-Opus_Systema-Sanscriticum_money-p3-daily-reconciliation-access_24.09.26.md), Opus 5.5 (`claude-opus-5-5`). Контракт: [MONEY_RECONCILIATION_P3_CONTRACT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONEY_RECONCILIATION_P3_CONTRACT_2026.md).

## Ворота

P1 (H5443) слит в main как [#2844](https://github.com/gasyoun/Systema-Sanscriticum/pull/2844) и развёрнут на проде: прод HEAD `80c58528`, миграция `2026_09_24_150000_create_money_ledger_core_tables` — Ran (проба `php artisan migrate:status` по ssh, 24-09-2026). Переменные `MONEY_*` в прод-`.env` не заданы — все денежные флаги выключены.

## Тесты

| Набор | SQLite | MariaDB 11.8.6 (docker, scratch) |
|---|---|---|
| `DailyReconciliationTest` (17) | зелёный | зелёный |
| `RefundAccessPolicyTest` (12) | зелёный | зелёный |
| `RefundFormD10Test` (4) | зелёный | зелёный |
| `MoneyCronScheduleHooksTest` | зелёный | зелёный |
| ядро P1: `LedgerCoreTest`, `LedgerBackfillReportTest`, `LedgerVerifierFindingsTest` | зелёный | зелёный |

MariaDB: 100 тестов, 747 проверок. В CI оба набора P3 добавлены в задание MySQL 8.4.

Полная денежная регрессия (`--filter='Payment|Paypal|Checkout|Refund|Deposit|Promise|Payout|Salary|Tariff|Debt|Ledger|Money|Recon|Access'`, 1333 теста): три красных, из них:

1. `LedgerCoreTest::test_migration_rolls_back_and_reapplies…` — вызван P3 (триггеры сверки начинались с `money_` и попадали в счёт триггеров ядра); исправлено префиксом `recon_`, 24/24 зелёные.
2. `MoneySliSyntheticPayCommandTest` (2) — красные и на чистом `origin/main` `80c58528` до P3; к этой ветке не относятся.

## Что нашла проверка на настоящей MariaDB

`LIKE 'block\_%' ESCAPE '\'` — синтаксическая ошибка в MySQL/MariaDB (обратная косая экранирует закрывающую кавычку); SQLite её пропускал. С включённым флагом D10 страницы уроков падали бы. Заменено переносимым `SUBSTR(tariff, 1, 6) = 'block_'`.

## Независимый верификатор

Sonnet 5 (Explore, отдельный контекст, только чтение): **PASS**. Два замечания SHOULD-FIX исправлены в этой же ветке:

1. Гонка двух `--persist` с одинаковым входом давала необработанное нарушение уникальности — теперь это `replay`; попутно найдено, что повтор наследовал посчитанные «новые исключения» и слал бы алерт (`$report + $seen` → `$seen + $report`), и что каст `date` писал день как `Y-m-d 00:00:00` в SQLite. Тест `test_concurrent_run_with_same_input_is_a_replay_not_a_crash`.
2. N+1 в сборщике для `--all`: условия промокодов, ранги погашений и семьи возвратов собираются пакетными запросами. Тест `test_expired_or_exhausted_promo_is_never_applied_silently`.

## Прод: сухой прогон

Развёрнуто 24-09-2026 штатной обёрткой `systema-auto-deploy-run.sh`: [#2848](https://github.com/gasyoun/Systema-Sanscriticum/pull/2848) → прод `75a77158` (19:38Z), исправление [#2851](https://github.com/gasyoun/Systema-Sanscriticum/pull/2851) → `e7d80922` (20:19Z); оба раза «health чист, smoke 200». Миграция `2026_09_24_170000_create_money_reconciliation_tables` — Ran.

**Планировщик.** `php artisan schedule:list` показывает `35 4 * * * php artisan money:reconcile-daily --persist --scheduled`. Плановый вызов вручную при выключенном флаге — `features.money_daily_reconciliation OFF — плановая сверка no-op`, exit 0, ничего не записано.

**Первый прогон всей истории** (`money:reconcile-daily --all`, только чтение, от `www-data`) нашёл дефект классификации: 2047 строк `reused_evidence`, и ни одна не была настоящим повтором.

1. 2045 строк — метка легаси-импорта `Мульти-оплата (Блоки N-M)` (`ImportAcademyData`), 46 разных меток; одна метка стоит у 1242 оплат 182 студентов.
2. 2 строки — одна оплата одного студента, разложенная на два блока с общим «номером» `80€`.

Исправлено в [#2851](https://github.com/gasyoun/Systema-Sanscriticum/pull/2851): метка импорта не доказательство; повтор — ключ у разных студентов или курсов либо дважды за один тариф.

**Прогон всей истории после исправления** (прод `e7d80922`):

| Показатель | Значение |
|---|---|
| Строк в сверке | 8251 (41 399 279.47 ₽) |
| `matched` | 6800 строк, 34 461 445.80 ₽ |
| `unallocated` | 21 строка, 284 735.69 ₽ |
| `exception:impossible_access` | 1429 строк, 6 650 097.98 ₽ |
| `exception:currency_amount_mismatch` | 1 строка, 3 000.00 ₽ |
| Вне сверки | 709 нулевых access-only, 470 расходов без связи с оплатой |
| Тождества | `rows_classified`, `kopecks_classified`, `ledger_student_money` — все true |
| Источники | 6 present; `bank_statement` missing; `ledger` dark; `payout_packages` legacy → статус `incomplete` |
| Отпечаток входа | `33de8d802ad30c4c17cdfc96843933ab3ebe724865fc9dff03ab32c3710e3252` |
| Контрольная сумма | `2e75443538b0ca641f40c467f2c2f41140ee8bc1715d0f6ce4b1ac3275abbd52` |
| Записано в `money_recon_*` | 0 прогонов, 0 исключений |

`impossible_access` — 1429 оплаченных строк по 26 курсам без групп доступа; последняя такая оплата — 23-03-2026. Это ровно состояние, которое `Payment::grantAccess` называет «оплачено, но доступа нет» (fail-closed H2304), поэтому правило оставлено: сверка видит историю честно. Ежедневный прогон берёт только один день, и шторма из этой истории не будет.

**Прогон одного дня** `--date=2026-09-23`: 6 строк (61 600.00 ₽), 5 `matched`, 1 `unallocated`, находок 0; повторный запуск дал тот же отпечаток `675395335b68a7a5c05e6dd1106817aecb6c2968a31abbad07a67916b2d67190` и ту же контрольную сумму `7b51ce3de6862405eac1da636b401f80b466527a64eee3f2061900df7240433a` — результат воспроизводим.

Флаги `MONEY_DAILY_RECONCILIATION` и `MONEY_REFUND_ACCESS_RULES` на проде не заданы — выключены; включение — отдельный ops-шаг.

_Гасунс_

## Addendum — 25-10-2026: four separate states, not one green light

The 24-09-2026 record above is left intact. This addendum re-probes current prod and
splits the single "deployed" claim into four independently checkable states. No flag
was changed by this pass.

### 1. «Команда исправна» — PASS

Read-only rerun of a saved day, prod HEAD `b7e01ea`:

```
sudo -u www-data HOME=/tmp php artisan money:reconcile-daily --date=2026-10-24 --dry-alert
```

Exit 0; `status=incomplete`; identities `rows_classified`, `kopecks_classified`,
`ledger_student_money`, `ok` all `true`; input fingerprint `56666e7b…`; control
checksum `b4c166919a57eb2dff911ee79194e898a44b8839c80741765462d4a99045988a` — **equal**
to the stored checksum of run #22. No drift, nothing written. The command is healthy;
exit 0 is explicitly *not* treated as proof of completeness (the status says
`incomplete`).

### 2. «Расписание исполняется» — PASS

- Cron: `* * * * * /usr/local/sbin/systema-schedule-run.sh` (www-data crontab).
- `php artisan schedule:list` → `35 4 * * * php artisan money:reconcile-daily --persist --scheduled`.
- `storage/logs/schedule.log` 25-10-2026 04:35:01 → `Running ['artisan' money:reconcile-daily --persist --scheduled] 521.90ms DONE`.
- `money_recon_runs` holds 22 persisted runs, `mode=scheduled`, each created at
  04:35:02, business dates 2026-09-28 … 2026-10-24.

### 3. «Источники полны» — FAIL

| Source | State | Evidence |
|---|---|---|
| `bank_acquiring`, `paypal`, `manual`, `teacher_direct`, `refund`, `webhook_journal`, `payout_packages` | present | run #22 `sources` JSON |
| `bank_statement` | **missing** | one import only: `bank_statement_imports` #1 covers 2026-08-01 … 2026-09-28 (826 rows), imported 28-09-2026. Nothing covers 2026-09-29 … 2026-10-25 (27 days). `money:import-statement-credits` is a **manual** command: the accountant exports the CSV and states the covered period (`--from/--to`); it is intentionally not on the schedule. |
| `ledger` | **dark** | every run carries `"P1 ledger is dark (MONEY_LEDGER_CORE off, no rows) — evidence cannot be matched against the subledger until P4 cutover"` |

So an exit-0 scheduled run does **not** mean the sources are complete: each day is
honestly recorded as `incomplete`.

### 4. «Сверка завершена» — FAIL

Every saved run has `status=incomplete`; none is `complete`. Four typed exceptions are
open, all created 28-09-2026 / 14-10-2026: three from the single bank-statement import
(`currency_amount_mismatch` ×2 — QR-settlement and acquiring-aggregate controls — and
`unknown_purpose` for two unclassified credits) and one from payment 14613
(`currency_amount_mismatch`, `paid_beyond_5_percent`). No exception was auto-resolved.

### Run-journal gaps

22 runs cover 27 business days. Missing: 2026-09-30 … 2026-10-03 (the flag was not yet
armed — the first persisted run is 28-09, and a duplicate 29-09 exists) and
2026-10-21, 2026-10-22 (unexplained; the host rebooted 2026-10-22 15:08, after those
mornings, and `schedule.log` was rotated, so no direct receipt survives). These are
recorded as gaps, not backfilled.

The arithmetic closes only with the duplicate: 27 business dates − 6 uncovered days =
21 covered dates, and 22 runs = 21 dates + a second run on 2026-09-29. Reading "22 runs"
as 22 covered dates would overstate coverage by one day; the earlier wording did not say
that the 22nd run is the duplicate.

### Dependency on the dark core → H5446

The `ledger` source cannot become present while `MONEY_LEDGER_CORE` is off; that is the
P4 cutover owned by **H5446** (its gate is `handoff:H5442,H5443,H5444,H5445`). The
dependency is one-way, not circular: H5446 requires P0–P3 **terminal**, and H5445 was
closed terminal on 24-09-2026; P3's own acceptance rule is "a missing source is
loud/incomplete, never zero", so a per-run `status=incomplete` is the designed honest
state, not a precondition H5445 must clear before P4. The earlier "genuine circularity"
wording is withdrawn (26-10-2026): no task text makes "each reconciliation complete" a
gate for P4, and the P4 gate reads handoff status, not run status. The four-state split
stands: P3 is healthy and executing, sources are incomplete, runs are honestly
incomplete, and `ledger` can only appear at the H5446 cutover.

_Dr. Mārcis Gasūns_
