# Astra verifier brief — P4 readiness (H5446 / H7263)

_Created: 25-10-2026 · Last updated: 25-10-2026_

Independent reviewer: **Codex Astra (`gpt-6-astra`)**, different family from the executor. This brief is self-contained: read it, then re-derive the load-bearing numbers from the primary artifacts. Missing verdict = `PENDING`, never `PASS`.

## What is being judged

Whether the evidence packet is sufficient to let H5446 (P4 money evidence migration and read cutover) **start**. This is not a judgment that P4 itself is implemented. The executor produced a report-only package: no `--apply` on prod, no flag flips, no payouts, no backup restore, no D21 dual confirmation.

## Artifacts (paths in the PR)

| Artifact | Path |
|---|---|
| Main report (part 1) | `docs/EVIDENCE_MONEY_P4_READINESS_H7263_25-10-2026.md` |
| Report part 2 (manifest, controls, scenarios, premortem) | `docs/EVIDENCE_MONEY_P4_READINESS_H7263_PART2_25-10-2026.md` |
| Redacted manifest | `docs/MONEY_P4_MANIFEST_2026-10-25.md` |
| Reference harness | `scripts/p4/readiness.py` (`model.py`, `engine.py`) |
| Harness notes | `scripts/p4/README.md` |
| Isolated-DB tests | `tests/p4/test_readiness.py` |
| This brief | `docs/MONEY_P4_ASTRA_VERIFIER_BRIEF_25-10-2026.md` |

Raw prod report JSONs are deliberately **not** committed (they contain payment ids and amounts). They are reproducible read-only with the commands in §5; their SHA-256 are in report §11.

## Load-bearing claims to re-derive

1. **P1/P3 divergence.** On one snapshot, P1 flags 2 001 rows `evidence_reused`; P3 flags 0. Claim: 46 repeated-key groups, 2 047 rows = 2 045 importer-label rows + 2 rows of one split payment; P1 flags all but the first per group (2 047 − 46 = 2 001); P3 excludes importer labels and collapses the split. No real repeat, no unknown. The P1 evidence doc's "132 transaction_id" is a different population (all paid rows incl. refunds/non-positive); today it is 135 groups.
2. **Overlap has no recipient evidence.** Claim: `payments` has no ИП field; `bank_statement_credits` has no account/ИП and covers only 2026-08-01…2026-09-28 (826 rows, one `tochka_api` import); period B is 2025-12-23…2026-02-27 with 569 inflows / 2 807 900.01 ₽ and zero bank coverage.
3. **Opening balances cannot reconcile.** Claim: student residue 452 999.69 ₽ (P1), debts 537 890.00 ₽ (194 pairs, 7 missing tariffs), cash `missing_source`, teacher payables unverified (0 terms; 105/105 `unresolved_rate`).
4. **Pre-ИП boundary.** Claim: 4 279 movements / 20 484 589.53 ₽ (49.4 % of 41 470 738.47 ₽) are before 2025-08-08 and outside D22; full-history migration would contradict D3, ignoring them loses carry-over.
5. **Real manifest apply.** Claim: 55 selected movements; 10 in B are `recipient_unproven` (non-admissible); apply writes 45 and quarantines 10; verify FAILs with expected 85 581 694 kop. vs actual 70 468 694 kop.
6. **Scenario tests.** Claim: 15/15 `unittest` tests pass, covering recipient substitution, mutually compensating errors, repeated evidence, input change after dry-run, new payment after snapshot, rerun replay, interruption/resume.
7. **Predecessors.** Claim: H5443 PASS; H5442 T+24h missing (UNVERIFIED) + late observation found PayPal 14613 paid `beyond_5`, 23 paid on groupless courses, 6 paid without membership; H5444 PASS with named `unresolved_rate` exceptions but 0 versioned terms (P4 gap); H5445 PASS on its dry-run acceptance but `MONEY_DAILY_RECONCILIATION=true` in prod while evidence says unset, 22/22 runs `incomplete` (P4 gap).

## Controls you must challenge (at least one)

- Recompute the 2 001 → 46-group arithmetic from the raw P1/P3 JSON and the snapshot. If any real repeat or unknown appears, the report's PASS on §4 is wrong.
- Recompute the 55 selected / 855 816.94 ₽ manifest and the 10 B exceptions. If the selected set or the bucket sums differ, §5–§6 are wrong.
- Verify the bank import coverage and the absence of a recipient column yourself (read-only SQL). Do not trust the report's schema summary.
- Run the harness tests and then deliberately mutate one of these: swap two recipients of equal amount (must FAIL with equal grand total), duplicate an `evidence_key` (must FAIL with `evidence_reused`), change an amount after dry-run (must REFUSE), add an unknown source id (must REFUSE).
- Check that `admissible` defaults to 0 and that a typed exception alone cannot yield PASS.

## Commands

```bash
# isolated tests (no prod)
python3 -m unittest discover -s tests/p4 -v

# observe prod read-only (no writes; run as www-data on the box)
ssh root@193.232.229.92 'cd /var/www/html && sudo -u www-data HOME=/tmp \
  php artisan money:ledger-backfill-report --json=/tmp/p1.json'
ssh root@193.232.229.92 'cd /var/www/html && sudo -u www-data HOME=/tmp \
  php artisan money:payout-package-compare --json'
ssh root@193.232.229.92 'cd /var/www/html && sudo -u www-data HOME=/tmp \
  php artisan money:reconcile-daily --all --json=/tmp/p3.json'

# rebuild the manifest from a read-only snapshot, then apply only to a local sqlite
python3 scripts/p4/readiness.py plan --snapshot snap.json --p1 p1.json --out manifest.json
python3 scripts/p4/readiness.py apply --manifest manifest.json --db /tmp/p4.sqlite --apply --run-id r1
python3 scripts/p4/readiness.py verify --manifest manifest.json --db /tmp/p4.sqlite
```

## Questions for the verdict

1. Is the P1/P3 divergence fully explained by importer labels + one split, or is any residual a real repeat that P3 misses?
2. Is period-only recipient attribution in A and C admissible under D22, and is its rejection in B consistent?
3. Is the 18 000 ₽ (P99.5) anomaly threshold defensible, or does it hide material rows below it (e.g. repeated small anomalies)?
4. Does the manifest's 55-row selection satisfy D3 ("opening balances + open debts + material anomalies only"), or does it silently reconstruct ordinary history / omit an open item?
5. Is the real-apply FAIL (10 `recipient_unproven`, 15 113 000 kop. gap) the correct readiness verdict, or should the packet be re-scoped before H5446 starts?
6. Are any of the 9 blockers in report §9 overstated or understated? In particular, check that H5444/H5445 are marked PASS on their own acceptance and that only their P4 inputs are called blocking.

## Verdict format

Record exactly one: `PASS` (packet sufficient; blockers accurately listed), `FAIL` (packet insufficient or a claim is wrong — name the claim and the corrected number), or `DISAGREE` (method/scope disagreement — name the alternative). Include: exact version (`gpt-6-astra`), commands run, at least one control you re-derived, and any limitation. Do not write `PASS` from a summary alone.

_к.ф.н. М.Ю. Гасунс_
