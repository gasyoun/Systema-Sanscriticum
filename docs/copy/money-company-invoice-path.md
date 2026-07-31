# Company invoice path — copy + ops contract

_Created: 31-07-2026 · Last updated: 31-07-2026_

**Handoff:** [H2017](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2017-Grok_Systema-Sanscriticum_paypal-invoice-billing-paths_31.07.26.md) · **Model:** Grok 4.5 (`grok-4.5`) · **PR:** [#969](https://github.com/gasyoun/Systema-Sanscriticum/pull/969)

## Prod status (31-07-2026)

**Live on samskrte.ru.** `COMPANY_INVOICE_ENABLED=true`. CTA on checkout; form `/invoice/{tariff}`; print `/invoices/{payment}/print`.

School requisites (printed on the invoice) come from `BILLING_*` / `config('billing.legal')`:

| Key | Prod value (source) |
|---|---|
| Legal name | Индивидуальный предприниматель Гасунс Марцис (Tochka customer) |
| INN | 540861224623 |
| OGRNIP | 325400000076450 |
| Bank | АО «Точка Банк», BIK 044525104 |
| р/с | 40802810020000863757 (primary settlement account) |
| к/с | 30101810745374525104 |
| Email | rusamskrtam@yandex.ru (site footer) |
| Legal address / phone | **empty** — optional gap; print still works |

Do **not** commit real account numbers into public docs beyond this ops table if the repo visibility changes; env remains source of truth for edits.

## Buyer path

1. Checkout → «Счет для компании» (when flag on).
2. Fill legal fields (name, INN, optional KPP, address) + contact.
3. System creates `Payment` `provider=invoice`, `status=pending` — **no access**.
4. Student/company sees printable invoice (HTML) with school bank details.
5. After bank transfer lands, admin: filter **«Счета юрлиц на проверке»** → **Подтвердить счет** → `paid` → access via `Payment::booted()`.

## Register

- «Счет на оплату» / «оплата по счету», never «безнал-кнопка Точки».
- Same fear line as PayPal: if money left the account, do not pay twice — write to us.

## Admin / accountant

- Notifications: Telegram curators + `ADMIN_EMAIL` mail (`CompanyInvoiceReceivedMail`).
- Student ack: `CompanyInvoiceStudentAckMail` (queued on `mailing`).
- Stale-checkout reaper **never** cancels `provider=invoice` pending rows (`Payment::MANUAL_CLAIM_PROVIDERS`).

## Residual

- Optional: fill `BILLING_LEGAL_ADDRESS` when a formal legal address is confirmed.
- Own KKT does not affect this path (bank transfer; no card-acquiring receipt).

_Dr. Mārcis Gasūns_
