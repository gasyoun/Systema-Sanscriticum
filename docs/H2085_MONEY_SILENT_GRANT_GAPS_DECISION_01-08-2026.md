# H2085 — Money silent-grant gaps: locked decisions

_Created: 01-08-2026 · Last updated: 01-08-2026_

**Executor:** Grok 4.5 (`grok-4.5`) · money contour · `/money-pr-land` (no auto-merge)

Source audit: [Uprava/docs/PIPELINE_AUDIT_SYSTEMA_MONEY_01-08-2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/PIPELINE_AUDIT_SYSTEMA_MONEY_01-08-2026.md)

## Gap decisions

| # | Gap | Decision | Code |
|---|---|---|---|
| 1 | `grantAccess` empty `course_group` | **Always** `Log::error` (was warning). **Hard fail** (throw → paid rolls back / webhook 500) only behind dark flag. | `Payment::grantAccess` + `features.money_grant_require_groups` default **OFF** |
| 2 | `authorized` / `AUTHORIZED` as success | **Hold ≠ capture.** When flag ON, no paid + no access; journal `hold_not_captured`. Flag OFF = legacy (hold still grants) so merge is prod-inert. | `WebhookController` + `features.tochka_authorized_not_paid` default **OFF** |
| 3 | Purpose parse miss (`Заказ №{id}`) | **Soft 200 intentional** (Tochka retries forever on non-2xx; non-order purposes must not loop). Operator signal = **Log::warning** + journal `unmatched`. Do not 4xx/5xx. | `WebhookController` purpose branch |
| 4 | `TOCHKA_WEBHOOK_GUARD` default | **Already ON by default on current `main`** (MG 01-08-2026 false-economy flip: `env(..., true)`, opt-out `=false`). No further default flip in this PR. Confirm prod `.env` is not forcing `false`. | `config/features.php` |

## Prod enable (human @DO after merge)

Flags are dark until a human sets them and runs `php artisan config:cache`:

```bash
# After merge + auto-deploy of this PR — human review first
TOCHKA_AUTHORIZED_NOT_PAID=true
MONEY_GRANT_REQUIRE_GROUPS=true   # only after every paid course has ≥1 group in admin
# TOCHKA_WEBHOOK_GUARD already defaults true; set =false only to opt out
php artisan config:cache
```

**Prerequisite for `MONEY_GRANT_REQUIRE_GROUPS`:** census courses sold without groups; attach groups in Filament first, or paid webhooks 500-loop.

## Residual silent path (stop condition)

Handoff stop: *after 1 unpaid access path still silent*. Residual accepted as intentional:

- Purpose miss without `Заказ №{id}` → **200 + unmatched + warning log**, no access. Soft 200 is the bank-hygiene trade-off; not a free silent grant of lessons (payment stays pending).

Other silent-adjacent paths out of scope this PR: sheet-job soft fails, curator notify soft fails.

## Tests

`tests/Feature/Webhooks/TochkaWebhookTest.php` (H2085 block):

- flag OFF `authorized` still grants (legacy)
- flag ON `authorized` / `AUTHORIZED` → pending + `hold_not_captured`
- flag ON `paid` still grants
- empty groups → `Log::error`, no throw when flag OFF
- empty groups → HTTP 500 + pending when flag ON
- purpose miss → 200 + unmatched + no access

## Model

Grok 4.5 (`grok-4.5`)

---

_Dr. Mārcis Gasūns_
