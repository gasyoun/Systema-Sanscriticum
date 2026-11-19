# ADR 0001: Grant access fail-closed stays off by default

Grant-access logic defaults to `GRANT_ACCESS_FAIL_CLOSED=false` (`config/features.php:854`). The fail-closed decision (payment-audit H8) is split out as a separate ops switch rather than baked into money code; flipping it is a human ruling + `config:cache`, not a deploy.

Status: **superseded by flip 09-11-2026 (H7929)**. Grill-with-docs accepted the design 09-11-2026 (prod `.env` had no line → `false`). Human ruling "flip both" 09-11-2026: prod `.env` now `GRANT_ACCESS_FAIL_CLOSED=true`, `PAYMENT_FIX_WAVE1=true` (already ON), `php artisan config:cache` verified via tinker (`bool(true)`/`bool(true)`); HEAD `9d653d1b`. PayPal amount/idempotency code path (`app/Services/Payments/PaypalClaimAmountCheck.php`) already present; H1 now rides the same ON posture.

Consequences: grants fail closed on money faults; every payment/webhook exception now blocks access until fixed — denied grants must be retried from queues, not manually re-granted. Revert is one env line + `config:cache`.

_Ledger row 09-11-2026 (no secrets): prod `.92` `9d653d1b` — `GRANT_ACCESS_FAIL_CLOSED=true`, `PAYMENT_FIX_WAVE1=true`, `config:cache OK`, tinker `grant_access_fail_closed=true`, `payment_fix_wave1=true`._

_Created: 09-11-2026 · Last updated: 09-11-2026_

_Dr. Mārcis Gasūns_
