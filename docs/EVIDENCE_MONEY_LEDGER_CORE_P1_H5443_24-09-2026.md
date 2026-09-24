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
| Ledger feature tests on MariaDB (after the review fixes) | `OK (57 tests, 567 assertions)` |

The first run found one real defect, fixed in the same PR. When the MariaDB 1020 abort surfaced through the service, the call did not retry. `LedgerService::write()` now runs `DB::transaction($fn, 3)`. A top-level call retries against fresh data, and the retry ends as the ordinary refund-cap rejection shown in the `service` row. A call nested inside the caller's own transaction gets a `DeadlockException`, and the caller must retry its whole transaction.

## 3. Tests (SQLite, the CI driver)

1. `tests/Feature/Ledger/LedgerCoreTest.php`: 24 tests, including a seeded property test (160 random operations) checked against an independent integer model. `tests/Feature/Ledger/LedgerBackfillReportTest.php`: 3 tests. `tests/Feature/Ledger/LedgerVerifierFindingsTest.php`: 30 tests pinning all five review rounds (section 5). Ledger total `OK (57 tests, 567 assertions)`.
2. `tests/Unit/KopecksTest.php` — `OK (16 tests, 1025 assertions)`.
3. The full suite ran locally with `php -d memory_limit=2G vendor/bin/phpunit`, rebased on `origin/main` `9c51aa25`. It took 9 min 12 s and had 6 failures, none in money or ledger code:
   1. Three fail identically on a clean `origin/main` checkout of the same Mac: `GrammarLabImportSearchTest::test_entitled_search_json_and_compare_and_bookmark`, `CatalogPrettyUrlsTest::test_teacher_facet_resolves_by_name_and_filters_courses` and `VitrinaWaitlistPageTest::test_teacher_links_use_natural_name_and_short_facet_resolves`.
   2. Three `SplitGroupMathTest` timestamp-parse datasets fail only inside the full run and pass in isolation on both the branch and main. That points to an ordering or timezone leak between tests; the backup code is untouched here.
4. CI also runs the three ledger classes in the MySQL 8.4 job (`.github/workflows/ci.yml`). That container keeps MySQL's default binlog on, so `CREATE TRIGGER` from a non-SUPER user fails with error 1419; the job now sets `log_bin_trust_function_creators = 1` first. Prod MariaDB runs `log_bin=0` and needs nothing. The job passed on PR #2844.
5. Deploy (24-09-2026, ~18:58Z): `deploy.sh` moved prod `3ad754bf → 7d807e5b`, smoke 200, cabinet probe OK. A read-only probe right after: migration batch 228 Ran, 9 `money_*` triggers, the three ledger tables empty, `MONEY_LEDGER_CORE` absent from `.env` (flag off), 9452 legacy `payments` rows untouched.

## 4. Legacy data findings for the backfill (read-only prod probe earlier in this session; counts only)

1. **412 `Расход` rows carry no `refund_of_payment_id`.** Legacy has no refund linkage for them, so the ledger cannot enforce the D12 cap retroactively. The backfill report lists them under `outflow_without_source_link`, and P4 must link or adjudicate them.
2. **132 `transaction_id` values are shared by more than one paid row.** The ledger's one-time evidence rule would reject every repeat. The report lists them as `evidence_reused`.
3. **10 paid rows have `received_account='teacher'`** instead of the constant `teacher_personal`. The code trace (24-09-2026) shows the legacy payroll counts them as course revenue, so the teacher gets a percentage of them, while it never deducts them as direct receipts. The teacher is therefore **over-paid** on these rows, and the school loses. The school-balance readers exclude them at the same time, so the summary and the per-block calculator disagree. The report flags them as `received_account_not_teacher_personal`. The fix, together with a wider question about the teacher's share of correctly marked direct receipts, is [H5474 (Opus 5) — fix 10 payments with received_account='teacher' + rule on teachers' share of direct receipts](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5474-Opus_Systema-Sanscriticum_received-account-teacher-rows-and-direct-receipt-fairness_24.09.26.md).

## 5. Independent money review — FAIL, fixed, re-reviewed

The first independent review (read-only Explore subagent, 24-09-2026) returned **FAIL** with two blockers, two majors and four minors. All eight are fixed in this PR, each pinned by a test in `LedgerVerifierFindingsTest` and checked on both layers (service and a raw INSERT that bypasses it):

1. **Blocker.** A D16 offset could be reversed or corrected by itself, which drops the school's obligation to the teacher while the student payment stays live. Fix: the service refuses both. New triggers allow an offset's reversal only after its receipt is reversed and refuse any correction in either chain. The receipt's reversal now runs first, then the offset's. The breach `direct_receipt_offset_mismatch` checks chain nets (was existence only).
2. **Blocker.** `deallocate(..., via:)` accepted any movement, so one payment's allocation could be removed with another payment's money. Fix: the carrier must be the target movement itself, or a reversal or correction of its chain, or a refund of its root, with the opposite sign (service + trigger + breach `allocation_reversal_outside_chain`). New trigger and breach: a payment family can remove from an obligation only what it put in (`family_obligation_negative`).
3. **Major.** A refund could shrink a delivered block and un-recognise revenue. Fix: on a delivered obligation, a negative allocation is allowed only from a `reversal` of an erroneous posting. The backfill's refund LIFO skips delivered obligations.
4. **Major.** `--shadow` held every family inside one transaction, so shared FK locks on `users`, `courses` and `teachers` lasted the whole run while `TrackUserActivity` updates `users` on each request. Fix: one `shadow()` rollback per family.
5. **Minor.** `applyDeposit` grouped by movement and broke on a reversed funding receipt. Fix: live, non-reversed rows only, each capped by what its payment family holds.
6. **Minor.** The models did not check the flag. Fix: `creating` (and obligation `updating`) call `LedgerWritesDisabled::guard()`, and the shadow depth is static, so a fresh service instance sees the shadow run.
7. **Minor.** Identity triggers: student money must name its student, a refund or compensation must belong to its source's student, and a reversal or correction to its chain's student.
8. **Minor.** Two `(float)` casts in `LegacyLedgerMapper` became a float-free `positiveDecimal()` string check.

The second review (a fresh read-only subagent) returned **FAIL** with one major finding plus smaller gaps. All of them are fixed and pinned in `LedgerVerifierFindingsTest`:

1. **Major (N1).** A delivered block could still be emptied through three routes: a free allocation on a reversal row, a deallocation carried by another chain member's reversal, and a same-amount correction followed by a refund. Fixes:
   1. A reversal carries only the mirrors of the row it reverses. The trigger and the breach `reversal_allocation_not_a_mirror` enforce this, and `allocate()` refuses reversal movements.
   2. A deallocation carried by a reversal must reverse exactly its target movement.
   3. A correction of an inflow inherits the allocations of the row it replaces, capped by what the family still holds. Delivered obligations go first.
   4. A refund that does not name the obligations it reduces may take only the family's unallocated residue.
2. **Found by the property test.** Refunded money could still fund an obligation. Fix: a payment family can allocate no more than it holds, i.e. its net minus what it has already allocated. This is enforced by the service, a trigger and the breach `family_over_allocated`.
3. **N3.** A reversal or correction keeps the teacher and course of its chain, and a compensation must name its student.
4. **N4.** `markDelivered()` refuses a date in the future.
5. **N5.** `shadow()` checks that the transaction level did not change inside the callback, and refuses a commit made there. The backfill report records a lock conflict as the anomaly `shadow_skipped: lock conflict` and does not abort.
6. **N2, a known limitation, pinned.** A correction of a payment whose refund already reduced an obligation is refused until that refund is reversed. The correct order is: reverse the refund, correct the payment, then record the refund again. The refusal is safe, and the test documents the sequence.

The third review (another fresh read-only subagent) returned **FAIL** with two blockers, one major and four minors. It confirmed the earlier fixes hold. All seven are fixed and pinned (`test_f1_…` to `test_f7_…`):

1. **Blocker (F1).** Correcting a refund upward got round the unallocated-residue rule and took money that funds a delivered block. Fix: after any correction, the service refuses if the payment family would hold less than it has allocated. The whole operation rolls back.
2. **Blocker (F2).** Correcting a refund that had reduced an obligation raised recognised revenue using refunded money, because the refund's reversal put the reduction back and nothing re-applied it. Fix: an outflow correction now carries the refund's reductions onto the correction, just as an inflow correction carries allocations. On a delivered obligation the trigger allows such a carry only up to what the refund's reversal put back, so the operation can never lower recognised revenue below where it started.
3. **Major (F3).** A commit inside `shadow()` was noticed only after the fact. Fix: a `TransactionCommitting` listener refuses a real (level-1) COMMIT during a shadow run before it reaches the database. A `TransactionCommitted` listener marks a nested commit below the shadow's level, so `commit(); beginTransaction()` is caught as well.
4. **Minor (F4).** The trigger now refuses a delivery date more than one day ahead. The one-day slack covers time zones: the app writes Moscow time, and SQLite counts in UTC. The service keeps the exact "not in the future" check.
5. **Minor (F5).** Documented honestly in the contract, which now has a section on what only the service holds. A refund above the residue and a reversal without its mirrors cannot be refused by a single-row trigger, because of insert order. The service refuses them inside one transaction, and `integrityBreaches()` detects them in the database.
6. **Minor (F6).** New `rerecordDirectTeacherReceipt()`. A reversed direct receipt is re-recorded under a derived evidence key `rerecord:<id>:<sha1>`, which exists exactly once per reversed original. The operator never has to invent a new key.
7. **Minor (F7).** Keys, types and currencies use a binary NO PAD collation on MariaDB (`utf8mb4_nopad_bin`), so they compare exactly, as in SQLite and PHP.

The fourth review returned **FAIL** with one major and one minor finding:

1. **Major.** `correct()` of an already-reversed refund acted as a new refund against a delivered block. Recognised revenue fell from 10000 to 6000, and no breach reported it. Fix: a reversed row is void and is not corrected. The service refuses unless the reversal is this correction's own `<key>:reversal` (an idempotent replay). The trigger `ledger: a reversed row is not corrected` compares the keys (`||` on SQLite, `CONCAT` on MariaDB). The allocation exception for delivered obligations now joins only the correction's own reversal. The public `reverse()` refuses the reserved suffix `:reversal`, and `integrityBreaches()` reports `correction_reversal_without_correction`.
2. **Minor.** If a hostile `shadow()` callback rolled back the levels itself, the PDO transaction stayed open while Laravel reported level 0. Fix: `shadow()` rolls back such an orphaned transaction.

The fifth review returned **PASS** on HEAD. It left two notes, both taken:

- A raw DB-owner forgery of a correction pair ends in rows identical to a legitimate correction, and is reported while it is half done. The contract now lists this as the fourth rule held only by the service.
- `correct()` refuses a key longer than 182 bytes, so its `:reversal` half fits the 191-byte column and never fails as a raw "data too long" error.

The final verdict is recorded in the handoff's `## Verifier` section.

## 6. What is not proven here

1. The shadow backfill (`money:ledger-backfill-report --shadow`) has not yet run on real prod data. It ran against synthetic fixtures in tests and against the scratch engine. After deploy the command exists on prod: the plan mode is read-only, and the shadow mode writes only inside a transaction that always rolls back.
2. Production reads have not switched, and ledger writes are not enabled: `MONEY_LEDGER_CORE` stays unset (false).

_Гасунс_
