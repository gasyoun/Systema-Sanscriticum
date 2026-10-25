# H5442 residual — classification of access rows flagged by the wave observer

_Created: 25-10-2026 · Last updated: 25-10-2026_

Read-only classification of the two access counters produced by
[`scripts/ops/h5442_wave_observe.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/ops/h5442_wave_observe.php)
over the window **2026-09-24 16:22:51 → 2026-10-25 17:43:29** (745.34 h, the new
observation dated 25-10-2026 — the T+24h receipt does not exist, see the addendum in
[EVIDENCE_MONEY_P0_WAVE_ROLLOUT_H5442_24-09-2026.md](EVIDENCE_MONEY_P0_WAVE_ROLLOUT_H5442_24-09-2026.md)).

Nothing was changed: no payment, access, membership, tariff or status was written by
this pass. Identifiers only — no names, no contact data, no amounts.

## Counter 1 — `paid_without_group_membership` (6 rows)

| Payment | Course | Class | Evidence |
|---|---|---|---|
| 14249 | 448 | **real access non-delivery (open, owned)** | Payment was created for course 436, then moved 436 → 447 (02-09) → 448 (28-09) by the admin UI. The buyer has no `group_user` row in course 448's group and no `course_user` row for 448. Already recorded as a live student without access on 28-09-2026 ([payout reestr](evidence/payout_reestr-28-09-2026-final-r2.md)) and routed to the curator by [PAYROLL_ACCESS_PLAYBOOK_2026-09-29.md](../../../Uprava/docs/PAYROLL_ACCESS_PLAYBOOK_2026-09-29.md). Still open on 25-10-2026. |
| 14386, 14387, 14388 | 367 | **observer false positive (internal)** | The buyer is the `super_admin` account; these are internal self-purchases, already classified as such on 28-09-2026. An admin reaches the course through the panel, not through `group_user`. |
| 14432 | 452 | **acceptable (access not yet due)** | Course 452 is `format=enrolling` (not started); the payment was moved 450 → 452 on 18-10-2026. Group assignment happens when the stream starts. Watch at start: if the course turns live and no group is attached, this becomes real non-delivery. |
| 14577 | 455 | **conditional / not yet due — with a data defect** | A zero-amount `promise_grant_#10` row (`is_conditional=1`, `linked_promise_id=10`). `payment_promises` #10 is still `active`, `promised_at=2026-10-31`, `actual_paid_at=null`, `fulfilled_payment_id=null`. No access is due before the promise date, so the missing membership is not a delivery failure — but a `paid` payment with no `payment_audits` row and no fulfilled promise is a bookkeeping anomaly that must be reconciled. |

## Counter 2 — `paid_on_groupless_course` (23 rows)

| Payments | Course | Class | Evidence |
|---|---|---|---|
| 14463–14484 (22) | 386 `Прочие затраты (Технический)` | **observer false positive (expense rows)** | Tariff `salary_payout`, negative amounts: teacher-salary mirror rows written into `payments`, not purchases. A technical expense course has no groups by design and never grants access. |
| 14261 | 409 | **historical row** | Paid 04-06-2025 on an archived, hidden course (`is_active=0`, `is_visible=0`); the student's `course_user` row is `Покинул` with the note that the group was disbanded. Not a live delivery failure. |

## Summary

| Class | Rows |
|---|---|
| Real access non-delivery, open, already owned by the curator | 1 (14249) |
| Observer false positive (admin / internal self-purchase) | 3 |
| Observer false positive (expense, `salary_payout`) | 22 |
| Acceptable: access not yet due (`enrolling`) | 1 (14432) |
| Conditional promise grant, not yet due, bookkeeping defect | 1 (14577) |
| Historical (archived course, student left) | 1 (14261) |

**Observer defect fixed in the same pass:** the script counted `salary_payout` expense
rows and admin accounts as "paid without access", and looked for typed exceptions in
`payments.claim_meta` instead of the `money_recon_exceptions` queue. The hardened
script classifies those cases explicitly and keeps the one real row (14249) flagged.

_Dr. Mārcis Gasūns_
