_Created: 27-09-2026 · Last updated: 27-09-2026_

# Teacher payroll operator playbook

This playbook answers one recurring question: **as of this moment, whom should
we pay, how much, through which channel, what was the last actual transfer, and
how many calendar days have passed since it?**

The workflow is read-only. It never creates `payments` or `teacher_payouts`.
Maria sends RUB transfers, MG sends PayPal/foreign transfers, and a human records
the confirmation through the existing accountant-cabinet workflow.

## 1. Refresh evidence

Before every run:

1. Import a bank statement covering the required evidence start through the
   live cutoff.
2. Refresh the PayPal/Xoom balance snapshot.
3. Update the private payout-sheet evidence and its SHA-256 hashes in
   `storage/app/private/payroll/evidence-manifest.json`.
4. Run the transfer-day reconciliation and resolve or classify its exceptions.

Missing, stale, future-dated, malformed, or replayed evidence is `incomplete`,
never zero. Affected positive obligations remain held.

## 2. Produce today's register

Run from the production application directory:

```bash
php artisan payroll:readiness --on=now --export=auto
```

The command prints all 23 teachers with disposition, due amount, channel, last
actual transfer date, and days since that transfer. It also writes the dated
private JSON package under `storage/app/private/payroll/`. Do not copy this
personal payroll data into a public repository or chat.

Read dispositions literally:

- `payable`: verified positive amount eligible for human transfer;
- `held`: positive amount blocked by the listed exception;
- `zero`: calculated, no current positive obligation;
- `inactive`: no taught course in the current calculator;
- `outside_calculator`: manual classification required.

Confirm that the census is exactly 23 and that every released row has zero
holds. If funding is insufficient, use the printed order: oldest `due_on`, then
teacher ID. Keep the remaining obligation visible.

## 3. Recheck immediately before each transfer

Copy the approved package fingerprint and rerun:

```bash
php artisan payroll:readiness --on=now --expect-fingerprint=<APPROVED_SHA256>
```

A mismatch means the approval is stale. Stop that transfer, regenerate the
private export, review it, and approve the new fingerprint. Never reuse the old
amount.

## 4. Human transfer and confirmation

1. Maria transfers rows whose channel is `tochka_maria`.
2. MG transfers rows whose channel is `paypal_mg`.
3. Record the bank/PayPal confirmation through the existing cabinet workflow.
4. Reconcile confirmed transfers to bank/PayPal evidence. Channel totals must
   equal the approved export, with no duplicate, missing, or unexplained payout.

## 5. Stop conditions

Do not transfer a row when its disposition is not `payable`, evidence is not
fresh, the fingerprint changed, funding is not `funded`, or any hold remains.
Escalate only the affected line; clean lines remain eligible.

_Гасунс_
