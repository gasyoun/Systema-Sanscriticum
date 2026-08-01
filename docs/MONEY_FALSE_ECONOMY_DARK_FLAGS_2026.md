# Money false economy — dark flags that harm prod

_Created: 01-08-2026 · Last updated: 01-08-2026_

> **Prod arming (same day, second pass):** all MUST/SHOULD ON flags below are
> **true** on samskrte.ru `.env` + `config:cache`. Code defaults match.

**Ruling (MG, 01-08-2026):** leaving a money-path safety or cleanup feature
**dark forever** is not thrift. It is **вредительство under a thrift costume** —
students keep stuck holds, promo slots stay reserved, reverse paths stay dead,
and the next incident looks like “money is complicated” when the code already
exists and is merely switched off.

**Deploy-dark for a short review window is fine.** **Permanent dark after
merge-to-main is not** unless the feature is still product-incomplete (scaffold
only) or explicitly deferred with an owner and a date.

Companion: [money-access-core-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-access-core-manual.md) §8 ·
`config/features.php`.

---

## 1. What “false economy” means here

| Pretended thrift | Actual cost |
|---|---|
| “Don’t touch money in cron — leave reaper OFF” | Abandoned `pending` rows hold prana / referral credit / deposit credit / promo capacity for weeks |
| “Money PR must be prod-inert — never flip the flag” | Defects that already burned revenue (full price after lost promo, resurrected webhook, unlocked referral wallet) stay open on prod |
| “Default false is safer” | Every new env without an explicit key ships **unprotected**; ops forgets the key; docs say “after review” and review never happens |
| “Soft-fail schedule when flag off” (PR #1007) | Correct for noise, **wrong as a permanent substitute for enabling the reaper** |

**Not false economy (legitimately dark):** unfinished product surfaces
(`TOCHKA_RECURRING_ENABLED`, `PAYPAL_SUBSCRIPTIONS_ENABLED`) — scaffold only,
no student path, no charge loop. Keep dark until product arms them.

---

## 2. Incident that forced the rule (01-08-2026)

1. `CHECKOUT_STALE_ORDER_EXPIRY` was absent/false on prod.
2. Kernel still scheduled `payments:expire-stale-checkouts --apply` every 15 min
   (later fixed: schedule only when ON).
3. Command exited 1 → `production.ERROR` noise; dry-run showed **dozens** of
   stale pending ids still sitting.
4. MG: **enable the reaper** — “экономия денег тут чревата проблемами.”
5. First live `--apply` after enable: **18** stale pending → `failed`; reserves
   released via normal `Payment::booted()` reverse path.

Prod state after enable (01-08-2026):

```bash
# /var/www/html/.env
CHECKOUT_STALE_ORDER_EXPIRY=true
php artisan config:cache
# schedule:list shows: */15 * * * * payments:expire-stale-checkouts --apply
```

`.env` backup before flip: `/root/env-backups/.env.pre-stale-reaper-enable-*`.

---

## 3. Register — money flags and false-economy risk

**Legend**

| Class | Meaning |
|---|---|
| **MUST ON** | Shipped protection/cleanup; prod OFF = known harm |
| **SHOULD ON** | Shipped; OFF leaves a real defect class open |
| **OK DARK** | Incomplete product; do not enable for “completeness” |
| **OPS** | Depends on product/ops readiness, not thrift |

Defaults in `config/features.php` unless noted. Prod snapshot 01-08-2026 from
`.env` (absent key ⇒ code default).

| Flag / `.env` | Default | Prod 01-08 | Class | Harm while OFF |
|---|---|---|---|---|
| `checkout_stale_order_expiry` / `CHECKOUT_STALE_ORDER_EXPIRY` | **true** | **ON** | **MUST ON** | Stale `pending` holds prana/referral/deposit/promo |
| `tochka_webhook_guard` / `TOCHKA_WEBHOOK_GUARD` | **true** | **ON** | **MUST ON** | Duplicate bodies / resurrection / amount mismatch |
| `checkout_deposit_reversal` / `CHECKOUT_DEPOSIT_REVERSAL` | **true** | **ON** | **MUST ON** | paid→failed does not restore deposit credit |
| `checkout_referral_credit_lock` / `CHECKOUT_REFERRAL_CREDIT_LOCK` | **true** | **ON** | **MUST ON** | Concurrent checkouts race referral wallet |
| `checkout_inactive_tariff_guard` / `CHECKOUT_INACTIVE_TARIFF_GUARD` | **true** | **ON** | **MUST ON** | Checkout of deactivated tariff creates bank link |
| `checkout_promo_survives_session` / `CHECKOUT_PROMO_SURVIVES_SESSION` | **true** | **ON** | **MUST ON** | Session refresh drops promo → full price |
| `checkout_session_lapse_relogin` / `CHECKOUT_SESSION_LAPSE_RELOGIN` | **true** | **ON** | **MUST ON** | Lapsed session mid-checkout confuses guest form |
| `checkout_signed_return_url` / `CHECKOUT_SIGNED_RETURN_URL` | **true** | **ON** | **MUST ON** | Bank return without signed payment id |
| `checkout_promo_reservations` / `CHECKOUT_PROMO_RESERVATIONS` | false | OFF | **OPS** | Capacity promos over-sell without timed slots |
| `checkout_integrity_safe_repairs` / `CHECKOUT_INTEGRITY_SAFE_REPAIRS` | false | OFF | **OPS** | Audit `--apply-safe` blocked; not cron-critical |
| `PAYPAL_CLAIM_ENABLED` | false | **ON** | armed | — |
| `COMPANY_INVOICE_ENABLED` | false | **ON** | armed | — |
| `TOCHKA_RECURRING_ENABLED` | false | OFF | **OK DARK** | Scaffold only (H2026) |
| `PAYPAL_SUBSCRIPTIONS_ENABLED` | false | OFF | **OK DARK** | Scaffold only (H2027) |

**Do not “save money” by turning MUST/SHOULD flags off after a scare.** Fix the
bug; leave the guard on.

---

## 4. Agent / deploy rules

1. **Money PR with a dark flag is not done** until either:
   - prod `.env` has the key ON and `config:cache` ran, **or**
   - the handoff names an explicit owner + date for enable (not “after review”
     with no owner).
2. **Default `false` in `config/features.php`** is allowed only when:
   - the feature is incomplete (OK DARK), **or**
   - enable requires secrets/ops not present on every clone.
3. **If a flag is MUST ON and default is false**, treat every fresh prod env
   without the key as a **defect** until fixed (probe/soft-check is fair game).
4. **MUST ON set (after 01-08-2026 arming):** defaults **true** for reaper,
   webhook guard, deposit reversal, referral lock, inactive tariff, promo
   survives session, session-lapse re-login, signed return URL. Opt-out only
   with written reason — not “we don’t want money code to run.”

---

## 5. Verify money guards live on prod

```bash
ssh root@193.232.229.92
cd /var/www/html
for k in TOCHKA_WEBHOOK_GUARD CHECKOUT_DEPOSIT_REVERSAL CHECKOUT_REFERRAL_CREDIT_LOCK \
  CHECKOUT_INACTIVE_TARIFF_GUARD CHECKOUT_PROMO_SURVIVES_SESSION \
  CHECKOUT_SESSION_LAPSE_RELOGIN CHECKOUT_SIGNED_RETURN_URL CHECKOUT_STALE_ORDER_EXPIRY; do
  grep "^${k}=" .env
done
php artisan config:show features.tochka_webhook_guard   # true (etc.)
php artisan schedule:list | grep expire-stale-checkouts
php artisan cabinet:probe
```

---

## 6. Related

- H1358 reaper implementation · H2066 dirty-tree breaker · schedule soft-no-op #1007
- Standing thrift-is-not-a-virtue twin for **rights**: [Uprava STANDING_POLICY_RIGHTS_UNCERTAINTY_IS_NOT_A_STOP](https://github.com/gasyoun/Uprava/blob/main/docs/STANDING_POLICY_RIGHTS_UNCERTAINTY_IS_NOT_A_STOP_2026.md) — different domain, same shape: “caution” that permanently blocks shipped value.

_Dr. Mārcis Gasūns_
