# VERIFICATION — Noboring dozhim

_Created: 01-08-2026 · Last updated: 01-08-2026_

Index: [PLAN_Systema_NOBORING_DOZHIM_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_Systema_NOBORING_DOZHIM_2026H2.md)

## Acceptance criteria

| Deliverable | Proof command / flow |
|---|---|
| Baseline script | `php artisan dozhim:baseline --days=90` prints rate A, rate B, n≥1; committed |
| Open Deal on pending | Feature test: pending payment → Deal open when flag ON; silent when OFF |
| Payment still closes Deal | Existing DealTest paid path green |
| Unpaid queue | Feature test: aged open Deal appears in bucket; young does not |
| Templates | Seeder/test: ≥4 `dozhim` templates present |
| Follow-up auto | Test: aged Deal gets one FollowUpTask; second run no dup |
| Drip | Test: flag OFF sends 0; flag ON queues/sends with fake bus |
| Recovery route | Test: auth user with unpaid sees resume; flag OFF 404/hidden |
| Money fence | No diff in PaymentObserver grant; grant tests green |
| Style | `vendor/bin/pint --test` (or project standard) |

## Ship bar vs product success

| Bar | Definition |
|---|---|
| **Ship (agent)** | Tests green + baseline committed + flags default false + PRs landed |
| **Product success (human later)** | Rate A improves vs baseline after flags ON in staging then prod — **not** agent stop condition |

## Risks & spikes

| Risk | Mitigation |
|---|---|
| GC-C1 already done — H-A empty | Gap-fill open-on-pending; if already exists, H-A = audit memo + C2 only |
| Pending payments not in DB | Spike: map actual unpaid model (Payment status enum, promises) before coding |
| Drip spam | Throttle + flag + unsubscribe existing patterns |
| Money PR scope creep | H-C CTA only; real installment = other programme |
| Watcher reverts | Worktree + watcher-safe land |
| Dual denominator confusion | Report both; primary = Order/Payment intent |

## Spikes before H-A code (≤30 min)

1. SQL/sample: count payments by status last 90d.  
2. Confirm whether unpaid order = `payments.status=pending` or promise rows.  
3. Confirm Deal created only after paid (read observer `qualifiesAsSale` / open path).

_Dr. Mārcis Gasūns_
