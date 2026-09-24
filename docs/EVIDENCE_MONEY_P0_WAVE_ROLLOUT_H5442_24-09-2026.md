# Evidence — money P0 wave rollout on prod (H5442, 24-09-2026)

_Created: 24-09-2026 · Last updated: 24-09-2026_

The H5442 handoff (Opus 5.5, 🔴3 hard) covers the live money P0 defects and their safe rollout, under epic E017. Executor: Opus 5.5 (`claude-opus-5-5`). Decision record: [DECISIONS_MONEY_LEDGER_AND_RECONCILIATION_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DECISIONS_MONEY_LEDGER_AND_RECONCILIATION_2026.md). Rollout ruling D5 (MG): «сначала отчет о затрагиваемых строках, затем включить целиком и сутки наблюдать исключения».

## Timeline (UTC)

1. 10:40:20 — execution started; gap matrix re-proved against `main` and against H5007 ([PR #2624](https://github.com/gasyoun/Systema-Sanscriticum/pull/2624)).
2. 12:45:48 — [PR #2819](https://github.com/gasyoun/Systema-Sanscriticum/pull/2819) merged (squash `fe417d9d`). All 8 required checks were green. The independent verifier ([comment](https://github.com/gasyoun/Systema-Sanscriticum/pull/2819#issuecomment-5814381061), Sonnet 5 `claude-sonnet-5`) returned PASS with no blockers.
3. ~12:46 — `deploy.sh` run on prod: `ba4dff00 → fe417d9d`, exit 0. Migration `2026_09_24_120000_add_money_p0_replay_keys` ran. The site smoke returned 200 and `cabinet:probe` reported OK.
4. 12:47:01 — **baseline report** (flag off): `money:p0-wave-report --json=storage/app/h5442_report_before.json`, `db_writes: 0`.
5. 13:22:51 — **complete wave enabled**: `PAYMENT_FIX_WAVE1=true` in prod `.env` (backup `.env.bak-h5442-20260924T132250Z`) + `config:cache`. Live config: `payment_fix_wave1=true`; fail-closed access is implied by the wave in code.
6. 13:23:04 — **after report** (flag on): `storage/app/h5442_report_after.json`, `db_writes: 0`, the same counts as the baseline. The wave changes new events only; existing rows are untouched.
7. 13:47:53 — **T0 observation** ([h5442_wave_observe.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/ops/h5442_wave_observe.php)): every counter is zero (table below). `cabinet:probe` is OK under the wave.
8. 2026-09-25 ~13:30 — **T+24h observation**, then the verdict below. H5442 closes only after this step.

## Gap matrix (current `main` before this PR)

| Item | Before | After (wave on) |
|---|---|---|
| H1 PayPal claim amount/currency (D4/D20) | any amount auto-paid for trusted students | ±5% vs tariff price in the claim's currency; outside the tolerance, or with no price in that currency → `pending` |
| H2 replay key | same-day heuristic (H5007, flagged) | `payments.claim_replay_key`, DB-unique |
| H8 fail-closed access | flag present, OFF on prod | on with the wave; a trusted claim on a group-less course → `pending` + `no_access_groups` |
| MEDIUM #12 refund withheld again (D11) | yes | withheld once |
| MEDIUM #15 two packages per block (D13) | possible | `teacher_payouts.settlement_key`, DB-unique + refusal |
| MEDIUM #13 EUR ignores advances (D14) | yes | EUR derived from the final RUB |
| negative RUB result / prior-block base | unfloored | floored + typed exception |
| direct-receipt boundary on `since` | not a defect (re-proved) | pinned by a test |
| H3/H4/H5/H6/H7 | fixed by H5007 | unchanged (H3/H4/H7 now live with the wave) |

## Baseline report — affected rows (ids only)

1. **PayPal claims:** 7 in scope (`beyond_5`: 4, `no_expected_price`: 3). One of them, payment 14206 (claimed 100 EUR against today's price of 90 EUR, +11.1%), was auto-trusted and would stay `pending` under the wave. The other six were paid by manual confirmation. Existing rows are not changed.
2. **Duplicate claims:** none. **Refunds withheld more than once:** none (0 refund lines recorded).
3. **Block payout packages:** 6, with 0 duplicates and 0 negative payouts.
4. **Payout runs:** 17 teachers; the result changes for 5, and 5 exceptions appear under the wave.
   1. Teacher 3: `payable_eur` falls from 578.26 to 45.27. The old formula ignored advances (MEDIUM #13), and 45.27 is the final 4,079.14 ₽ converted at the rate.
   2. Teacher 16: `payable_eur` falls from 330.21 to 11.68, for the same reason.
   3. Teachers 4, 7 and 10: `negative_prior_block_base` is floored. There are 5 typed exceptions, each carrying `unabsorbed_rub` (−2,719.5 ×3, −2,945.7, −3,337.2). `payable_rub` rises by 5,629.37 / 813.02 / 921.07.
   4. `payout:run` is a read-only report: real payouts are still recorded by a person in «Зарплаты преподавателей», so none of these numbers moves money by itself.
5. **Access fail-closed:** 33 courses have no groups; 6 of them are sellable ([284, 339, 395, 418, 442, 446]). Their 1,429 paid rows sit on archived courses. Live probe of the six sellable courses: **0 paid rows in the last 90 days**. The latest paid rows are 2026-03-17 (course 418) and 2025-12-31 (course 395); courses 284, 339, 442 and 446 have never had a paid row. Course 446 is the cabinet-probe sandbox (it only hosts the homework-upload lesson). The money-SLI fixture attaches its own group, so it is not affected.

## Observation counters

| Counter | T0 (0.42 h) | T+24h |
|---|---|---|
| payments created | 0 | _pending_ |
| PayPal claims / with replay key | 0 / 0 | _pending_ |
| typed reconciliation exceptions | 0 | _pending_ |
| paid while beyond ±5% or unpriced | 0 | _pending_ |
| paid on a group-less course | 0 | _pending_ |
| paid without group membership (silent access) | 0 | _pending_ |
| duplicate paid (user+course+tariff+day) / replay-key dupes | 0 / 0 | _pending_ |
| block payouts / settlement-key dupes / negative | 0 / 0 / 0 | _pending_ |
| log: fail-closed throws / unique violations / RuntimeException | 0 / 0 / 0 | _pending_ |

## Verdict

_pending the T+24h observation._ PASS requires all of the following:

- no unexplained invariant breach
- no duplicate money effect
- no silent access success
- no negative ruble result without a linked exception
- `db_writes: 0` on every report run

## Rollback

1. Delete the `PAYMENT_FIX_WAVE1=true` line from prod `.env`.
2. Run `sudo -u www-data php artisan config:cache`.

The migration columns stay (nullable, additive) and are harmless when the flag is off.

_Гасунс_
