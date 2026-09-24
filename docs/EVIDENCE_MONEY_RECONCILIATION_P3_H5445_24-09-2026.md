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

Заполняется после развёртывания (команде нужны таблицы `money_recon_*`).

_Гасунс_
