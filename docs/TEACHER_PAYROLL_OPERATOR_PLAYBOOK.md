_Created: 27-09-2026 · Last updated: 27-09-2026_

# Teacher payroll operator playbook

This playbook answers one recurring question: **as of this moment, whom should
we pay, how much, through which channel, what was the last actual transfer, and
how many calendar days have passed since it?**

The workflow is read-only. It never creates `payments` or `teacher_payouts`.
Maria sends RUB transfers, MG sends PayPal/foreign transfers (Xoom only for Edgar), and a human records
the confirmation through the existing accountant-cabinet workflow.

## 1. Refresh evidence

Before every run:

1. Pull the Tochka Open Banking statements for every enabled RUB account and
   import their credits through the append-only bank-credit importer. A manual
   bank CSV is a fallback, not the primary route.
2. Export PayPal student receipts for the whole payroll window and reconcile
   them to student notifications/claims by transaction ID. Foreign amount and
   currency without a PayPal transaction reference are unresolved evidence,
   not a verified receipt and not zero.
3. Keep Xoom evidence only for Edgar's payout line. Never require Xoom evidence
   for another teacher and never use it as evidence of student receipts.
4. Update the private payout-sheet evidence and its SHA-256 hashes in
   `storage/app/private/payroll/evidence-manifest.json`.
5. Run the transfer-day reconciliation and resolve or classify its exceptions.

Missing, stale, future-dated, malformed, or replayed evidence is `incomplete`,
never zero. Affected positive obligations remain held.

## 2. Produce today's register

Run from the production application directory:

```bash
php artisan payroll:readiness --on=now --export=auto
```

The command prints all 23 teachers with disposition, **current-window amount**,
amount basis, channel, last actual transfer date and amount, and days since that
transfer. It also writes the dated private JSON package under
`storage/app/private/payroll/`. Do not copy this personal payroll data into a
public repository or chat.

Never read `legacy_candidate_rub` as money due. It is retained only to expose
what the old all-time calculator would have said. When `amount_state` is
`partial_current_window`, `excluded_prior_rub` is historical revenue whose
payout coverage is not structured enough to prove whether it is paid. The row
stays held until that history is backfilled; only the current-window estimate is
shown in the main amount column.

A fixed/seasonal teacher with no block completed inside the current window is
zero for this run (`no_completed_block_in_current_window`). An open-ended rate
record must never manufacture a monthly salary on its own.

Read dispositions literally:

- `payable`: verified positive amount eligible for human transfer;
- `held`: positive amount blocked by the listed exception;
- `zero`: calculated, no current positive obligation;
- `inactive`: no taught course in the current calculator;
- `outside_calculator`: manual classification required.

Confirm that the census is exactly 23 and that every released row has zero
holds. If funding is insufficient, use the printed order: oldest `due_on`, then
teacher ID. Keep the remaining obligation visible.

For every `held` row, compare `legacy_candidate_rub` with the current-window
amount. A large difference is evidence of missing historical payout coverage,
not evidence of arrears. Backfill the payout-to-block breakdown before releasing
that line.

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
2. MG transfers rows whose channel is `paypal_mg`; `xoom_mg` is Edgar only.
3. Record the bank/PayPal confirmation through the existing cabinet workflow.
4. Reconcile confirmed transfers to bank/PayPal evidence. Channel totals must
   equal the approved export, with no duplicate, missing, or unexplained payout.

## 5. Stop conditions

Do not transfer a row when its disposition is not `payable`, evidence is not
fresh, the fingerprint changed, funding is not `funded`, or any hold remains.
Escalate only the affected line; clean lines remain eligible.

_Гасунс_
