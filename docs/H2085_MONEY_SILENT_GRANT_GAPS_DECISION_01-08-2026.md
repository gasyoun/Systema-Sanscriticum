# H2085 — Money silent-grant gaps: locked decisions

_Created: 01-08-2026 · Last updated: 07-08-2026_

**Executor:** Grok 4.5 (`grok-4.5`) · money contour · `/money-pr-land` (no auto-merge)

Source audit: [Uprava/docs/PIPELINE_AUDIT_SYSTEMA_MONEY_01-08-2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/PIPELINE_AUDIT_SYSTEMA_MONEY_01-08-2026.md)

## Gap decisions

| # | Gap | Decision | Code |
|---|---|---|---|
| 1 | `grantAccess` empty `course_group` | **Always fail closed.** `Log::error` + throw, so paid rolls back and the webhook returns 500 for provider retry. Checkout rejects the tariff before creating a payment or bank link. | `Payment::grantAccess` + `PaymentController` |
| 2 | `authorized` / `AUTHORIZED` as success | **Hold ≠ capture.** Authorization always remains pending and journals `hold_not_captured`. **Post-#1146 settle set:** `APPROVED` / `approved` / `captured` / `completed` / `paid` mark paid + may grant. Do **not** put hold back into success. Matrix: [TOCHKA_SETTLEMENT_STATUS_MATRIX_2026-08-07.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TOCHKA_SETTLEMENT_STATUS_MATRIX_2026-08-07.md). | `WebhookController` |
| 3 | Purpose parse miss (`Заказ №{id}`) | **Soft 200 intentional** (Tochka retries forever on non-2xx; non-order purposes must not loop). Operator signal = **Log::warning** + journal `unmatched`. Do not 4xx/5xx. | `WebhookController` purpose branch |
| 4 | `TOCHKA_WEBHOOK_GUARD` default | **Already ON by default on current `main`** (MG 01-08-2026 false-economy flip: `env(..., true)`, opt-out `=false`). No further default flip in this PR. Confirm prod `.env` is not forcing `false`. | `config/features.php` |

## Production invariant

There is no compatibility switch. Every sellable course must have at least one
group before checkout; existing pending payments for a misconfigured course retry
cleanly after an operator attaches the group.

## Residual silent path (stop condition)

Handoff stop: *after 1 unpaid access path still silent*. Residual accepted as intentional:

- Purpose miss without `Заказ №{id}` → **200 + unmatched + warning log**, no access. Soft 200 is the bank-hygiene trade-off; not a free silent grant of lessons (payment stays pending).

Other silent-adjacent paths out of scope this PR: sheet-job soft fails, curator notify soft fails.

## Tests

`tests/Feature/Webhooks/TochkaWebhookTest.php` (H2085 block):

- `authorized` / `AUTHORIZED` → pending + `hold_not_captured` (never paid)
- later `APPROVED` / `captured` → paid + access (#1146 restores APPROVED as settled)
- settled aliases `paid` / `completed` / `captured` / `APPROVED` → paid + access
- empty groups → HTTP 500 + pending; exact delivery succeeds after group repair
- checkout without groups → validation error before payment/bank-link creation
- purpose miss → 200 + unmatched + no access
- Full matrix + failure rows: [TOCHKA_SETTLEMENT_STATUS_MATRIX_2026-08-07.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TOCHKA_SETTLEMENT_STATUS_MATRIX_2026-08-07.md) (H2337)

## Model

Grok 4.5 (`grok-4.5`)

---

_Dr. Mārcis Gasūns_
