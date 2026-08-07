# Tochka settlement status matrix (#1103 × #1146)

_Created: 07-08-2026 · Last updated: 07-08-2026_

**Handoff:** [H2337](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2337-Grok_Systema-Sanscriticum_tochka-settlement-status-matrix-1103-1146_07.08.26.md) (**Grok 4.5**) — lock hold ≠ paid after the #1146 soft-back.  
**Code:** [`WebhookController::handleTochkaWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/WebhookController.php)  
**Tests:** [`tests/Feature/Webhooks/TochkaWebhookTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Webhooks/TochkaWebhookTest.php)  
**Prod accept (live traffic):** [docs/ops/PROD_ACCEPT_REMEDIATION_1_8_TOCHKA_HORIZON_2026-08-07.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ops/PROD_ACCEPT_REMEDIATION_1_8_TOCHKA_HORIZON_2026-08-07.md) (H2336)

## Why this matrix exists

1. **[PR #1103](https://github.com/gasyoun/Systema-Sanscriticum/pull/1103)** (remediation 1/8) enforced settlement entitlements: holds stay pending; only captured/completed settled; missing course groups fail closed.
2. **[PR #1146](https://github.com/gasyoun/Systema-Sanscriticum/pull/1146)** **re-accepted `APPROVED` as paid** — product-necessary: Tochka one-shot card / SBP / Dolyame ship `APPROVED` as money-taken, and #1103's narrower success set left real charges pending.
3. Without an explicit status → outcome table + PHPUnit rows, the soft-back can re-open the **hold-as-paid** hole on the next "cleanup".

This doc is the **ops-safe source of truth** for bank status strings. Matching fixture assertions live in `TochkaWebhookTest` (data providers + named cases below).

## Normalization

Bank `status` is compared after `mb_strtolower(trim(...))`. Case variants (`APPROVED` / `approved`) share one row.

## Status → outcome

| Bank status (raw examples) | Normalized class | Payment status | Access grant | Journal `decision` | HTTP | Notes |
|---|---|---|---|---|---|---|
| `APPROVED`, `approved` | **settled** | `paid` | **yes** (if course groups exist) | `applied` | 200 | Canonical Tochka one-shot success (#1146). Card settle / SBP / Dolyame. |
| `captured`, `completed`, `paid` | **settled** | `paid` | **yes** (if groups) | `applied` | 200 | Aliases for older/other payloads; keep for regression. |
| `authorized`, `AUTHORIZED` | **hold** | stays `pending` | **no** | `hold_not_captured` | 200 | Two-stage card hold only. Wait for later `APPROVED`/capture. Never treat as paid (#1103 / H2085). |
| `processing`, `PENDING`, other unknown non-fail | **intermediate** | stays `pending` | **no** | `applied` (no-op on status) | 200 | Not hold, not settled. No access. Decision string is historical no-op; status unchanged. |
| `rejected`, `canceled`, `cancelled`, `failed` | **failure** | `failed` | **no** | `applied` | 200 | Bank refused / cancelled the operation. |
| purpose without `Заказ №{id}` | **unmatched** | unchanged | **no** | `unmatched` | 200 | Soft 200 intentional (Tochka retries forever on non-2xx). |
| settled + course has **no** groups | **settled fail-closed** | stays `pending` | **no** | *(no row — txn rolls back)* | **500** | Operator repair groups → retry same delivery succeeds. |
| settled + guard ON + amount mismatch | **rejected** | stays `pending` | **no** | `rejected_amount_mismatch` | 200 | Only when `tochka_webhook_guard` is ON. |
| settled + guard ON + paid-then-reversed replay | **rejected** | stays non-paid | **no** | `rejected_resurrection` | 200 | Only when guard ON. |
| exact JWT already journaled + guard ON | **duplicate** | unchanged | **no** | *(no new row)* | 200 | Short-circuit before re-apply. |

### Settled vs hold (the non-negotiable split)

| | Hold (`authorized`) | Settled (`APPROVED` / `captured` / `completed` / `paid`) |
|---|---|---|
| Money taken at bank? | No (auth hold) | Yes (per Tochka product docs for one-shot) |
| `payments.status` | remains `pending` | → `paid` |
| Course groups attached? | **never** from this event | yes, via `PaymentObserver` / `grantAccess` |
| Later capture after hold | second webhook with settled status → paid + access | n/a |

**Fail =** any path where `authorized` / `AUTHORIZED` yields `paid` or attaches groups.

## Code lists (keep in sync)

From `WebhookController` (do not edit here without updating PHPUnit):

```php
$successStatuses = ['approved', 'captured', 'completed', 'paid'];
$holdStatuses    = ['authorized'];
$failureStatuses = ['rejected', 'canceled', 'cancelled', 'failed'];
```

## PHPUnit lock map

| Matrix row | Test method / provider |
|---|---|
| Hold never grants | `authorized_hold_status_does_not_grant` + `holdStatusProvider` |
| Intermediate stays pending | `non_capture_status_does_not_mark_payment_paid` + `nonCaptureStatusProvider` |
| Settled marks paid + access | `settled_bank_status_marks_payment_paid` + `settledStatusProvider` (includes **APPROVED**) |
| Capture after hold | `later_capture_after_authorized_hold_grants_access` |
| Missing groups fail-closed | `missing_groups_fail_closed_then_same_delivery_succeeds_after_repair` |
| Purpose miss | `purpose_parse_miss_is_soft_200_unmatched_no_access` |
| Failure → `failed` | `failure_bank_status_marks_payment_failed` + `failureStatusProvider` |
| Guard amount / resurrection | existing H1359 cases in the same class |

Run:

```bash
php artisan test --filter=TochkaWebhookTest
```

## Related decisions (stale-doc traps)

| Doc | Risk if left stale |
|---|---|
| [H2085 decision memo](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/H2085_MONEY_SILENT_GRANT_GAPS_DECISION_01-08-2026.md) | Pre-#1146 wording that only `captured`/`completed` settle — **superseded for success set**; hold rule unchanged. |
| [money-access-core-manual §5.1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-access-core-manual.md) | Must **not** list `authorized` / `AUTHORIZED` in success ∈. |
| H2336 prod accept §6 | Live traffic was all `APPROVED`→`applied`; hold window N/A — code path still locked by this matrix + tests. |

## Guardrails

- Do **not** put `authorized` back into `$successStatuses`.
- Do **not** remove `approved` from `$successStatuses` without a product ruling + this matrix update in the same PR.
- Do **not** enable PayPal subscriptions or partner flags in this contour.
- Synthetic JWT fixtures only — no live card charge required for the lock.

## Model

Grok 4.5 (`grok-4.5`) · money contour · `/money-pr-land` discipline (tests + docs; no new money flag).

---

_Dr. Mārcis Gasūns_
