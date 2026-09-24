# Денежный реестр P1: append-only движения, распределения и обязательства с инвариантами в БД (Opus 5.5 `claude-opus-5-5`, 24-09-2026)

H5443 — ядро нового денежного учёта, выключенное (флаг `features.money_ledger_core`, `MONEY_LEDGER_CORE`, по умолчанию ВЫКЛ); прежние чтения денег не тронуты. PR [#2844](https://github.com/gasyoun/Systema-Sanscriticum/pull/2844).

- Три таблицы `money_movements`, `money_allocations`, `money_obligations` — только добавление, суммы в целых копейках; 9 триггеров MariaDB/MySQL/SQLite держат неизменяемость проведённых строк, потолок возврата, однократное использование доказательства оплаты и связанные исправления.
- `LedgerService` (запись), `LedgerProjection` (проверки целостности), команда `money:ledger-backfill-report` — только отчёт, ничего не пишет.
- Пять независимых проверок, 1–4 нашли дефекты (исправлены и закреплены тестами), пятая — PASS. Реестр `OK (57 tests, 567 assertions)` на SQLite и MariaDB 11.8.6; CI MySQL 8.4 тоже гоняет реестр.
- Доказательства: [EVIDENCE_MONEY_LEDGER_CORE_P1_H5443_24-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/EVIDENCE_MONEY_LEDGER_CORE_P1_H5443_24-09-2026.md). Хвост вне объёма: H5474 — 10 платежей с `received_account='teacher'` и доля учителя в прямых оплатах.

_Гасунс_
