# Company invoice path — copy + ops contract

_Created: 31-07-2026 · Last updated: 31-07-2026_

**Handoff:** H2017 · **Model:** Grok 4.5 (`grok-4.5`)

## Buyer path

1. Checkout → «Оплата по счёту для компании» (only if `COMPANY_INVOICE_ENABLED=true`).
2. Fill legal fields (name, INN, optional KPP, address) + contact.
3. System creates `Payment` `provider=invoice`, `status=pending` — **no access**.
4. Student/company sees printable invoice (HTML) with school bank details from `config/billing.legal`.
5. After bank transfer lands, admin opens filter «Счета юрлиц на проверке» → **Подтвердить счёт** → `paid` → access via existing `Payment::booted()`.

## Register

- «Счёт на оплату» / «оплата по счёту», never «безнал-кнопка Точки».
- Same fear line as PayPal: if money left the account, do not pay twice — write to us.

## Ops prerequisites before enable

1. Fill all `BILLING_*` env keys (legal name, INN, bank account, BIK, …).
2. `COMPANY_INVOICE_ENABLED=true` + `php artisan config:cache`.
3. Smoke: request one test invoice, print, confirm, revoke if needed.

_Dr. Mārcis Gasūns_
