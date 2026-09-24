_Created: 24-09-2026 · Last updated: 24-09-2026_

# Evidence — money ledger core P1 (H5443), MariaDB proof, tests, legacy findings

Handoff: [H5443 (Opus 5) — append-only money ledger core](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5443-Opus_Systema-Sanscriticum_money-p1-append-only-ledger-core_24.09.26.md). Executor: Opus 5.5 (`claude-opus-5-5`). Contract: [MONEY_LEDGER_CORE_P1_CONTRACT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONEY_LEDGER_CORE_P1_CONTRACT_2026.md). Gate waiver: MG ruled in chat on 24-09-2026 to go ahead without waiting for H5442's T+24h observation («let us ignore it and move forward»). H5442 itself stays open until that check.

## 1. Prod engine facts (read-only probes, 24-09-2026)

1. The prod database runs MariaDB `11.8.6-MariaDB-0+deb13u1` with `log_bin=0`, `REPEATABLE-READ` isolation and `innodb_snapshot_isolation=1`. Because the binlog is off, creating a trigger needs no SUPER privilege or `log_bin_trust_function_creators`.
2. The app DB user holds `ALL PRIVILEGES ON laravel.*`, which includes TRIGGER. So `deploy.sh` → `migrate` can create the nine triggers.
3. A first attempt ran the proof on the prod host in a separate scratch DB. The session's auto-mode classifier stopped it after the code had been copied to `/tmp/h5443`, so no database was created. The proof below was run instead on the **same engine version** in a local container (`mirror.gcr.io/library/mariadb:11.8.6`, `--skip-log-bin`, REPEATABLE-READ). That container defaults to `innodb_snapshot_isolation=ON`, the same as prod.

## 2. MariaDB 11.8.6 proof — [scripts/h5443_ledger_mariadb_proof.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/h5443_ledger_mariadb_proof.sh)

| Step | Result |
|---|---|
| Full migrate on scratch (all 465 migrations) | 3 `money_*` tables, 9 triggers |
| Rollback of the P1 migration | 0 tables, 0 triggers; `payments` column set byte-identical (MD5 of name+type list) |
| Dry run: `migrate --pretend` of the P1 migration | 3 CREATE TABLE + 9 CREATE TRIGGER printed; 0 tables created |
| Re-migrate | 3 tables, 9 triggers |
| Race, `service` (two top-level refunds of 6000 against a 10000 receipt) | one OK; the other `ledger: refunds exceed the source payment (remaining 4000, asked 6000)`; family net 4000 |
| Race, `stale` (both hold an old snapshot, service called inside) | one OK; the other aborted by MariaDB 1020 «Record has changed since last read» — nothing written; family net 4000 |
| Race, `raw` (both hold an old snapshot, bare INSERT, trigger only) | one OK; the other aborted by 1020; family net 4000 |
| Same three races with `innodb_snapshot_isolation=OFF` | service: one OK + clean «refunds exceed»; stale: one OK + clean «refunds exceed»; raw: one OK + trigger `1644 ledger: refunds exceed the source payment`; family net 4000 each time |
| `integrityBreaches()` after the races | empty; `balanced: true` |
| Ledger feature tests on MariaDB | `OK (27 tests, 414 assertions)` |

The first run found one real defect, fixed in the same PR. When the MariaDB 1020 abort surfaced through the service, the call did not retry. `LedgerService::write()` now runs `DB::transaction($fn, 3)`. A top-level call retries against fresh data, and the retry ends as the ordinary refund-cap rejection shown in the `service` row. A call nested inside the caller's own transaction gets a `DeadlockException`, and the caller must retry its whole transaction.

## 3. Tests (SQLite, the CI driver)

1. `tests/Feature/Ledger/LedgerCoreTest.php`: 24 tests, including a seeded property test (160 random operations) checked against an independent integer model. `tests/Feature/Ledger/LedgerBackfillReportTest.php`: 3 tests. Result: `OK (27 tests, 414 assertions)`.
2. `tests/Unit/KopecksTest.php` — `OK (16 tests, 1025 assertions)`.
3. The full suite ran locally with `php -d memory_limit=2G vendor/bin/phpunit`, rebased on `origin/main` `9c51aa25`. It took 9 min 12 s and had 6 failures, none in money or ledger code:
   1. Three fail identically on a clean `origin/main` checkout of the same Mac: `GrammarLabImportSearchTest::test_entitled_search_json_and_compare_and_bookmark`, `CatalogPrettyUrlsTest::test_teacher_facet_resolves_by_name_and_filters_courses` and `VitrinaWaitlistPageTest::test_teacher_links_use_natural_name_and_short_facet_resolves`.
   2. Three `SplitGroupMathTest` timestamp-parse datasets fail only inside the full run and pass in isolation on both the branch and main. That points to an ordering or timezone leak between tests; the backup code is untouched here.

## 4. Legacy data findings for the backfill (read-only prod probe earlier in this session; counts only)

1. **412 `Расход` rows carry no `refund_of_payment_id`.** Legacy has no refund linkage for them, so the ledger cannot enforce the D12 cap retroactively. The backfill report lists them under `outflow_without_source_link`, and P4 must link or adjudicate them.
2. **132 `transaction_id` values are shared by more than one paid row.** The ledger's one-time evidence rule would reject every repeat. The report lists them as `evidence_reused`.
3. **10 paid rows have `received_account='teacher'`** instead of the constant `teacher_personal`. Legacy code compares against the constant, so today these rows count as **school** receipts. That may be a live misclassification. The report flags them as `received_account_not_teacher_personal`. The fix is outside P1.

## 5. What is not proven here

1. The shadow backfill (`money:ledger-backfill-report --shadow`) has not yet run on real prod data. It ran against synthetic fixtures in tests and against the scratch engine. After deploy the command exists on prod: the plan mode is read-only, and the shadow mode writes only inside a transaction that always rolls back.
2. Production reads have not switched, and ledger writes are not enabled: `MONEY_LEDGER_CORE` stays unset (false).

_Гасунс_
