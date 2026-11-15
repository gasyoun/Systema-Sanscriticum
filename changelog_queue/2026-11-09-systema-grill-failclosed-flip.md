# Systema grill + fail-closed flip — CONTEXT, ADRs, flaws, ledger (2026-11-09)

_Created: 09-11-2026 · Last updated: 09-11-2026_

- grill-with-docs (MX 2026-11-09, PR #4010) — `CONTEXT.md` glossary (cabinet, lesson, payment = webhook-confirmed money movement, promise, payout, support rule), `docs/adr/0001…0003`, and `docs/systema-grill-flaws-2026-11-09.md` with receipts and live probes (.92: S3 offsite SKIP, spatie schedule dead, archive-password narrowed).
- **H7929 — fail-closed flipped ON live (PR #4012):** prod `.92` `GRANT_ACCESS_FAIL_CLOSED=true`, `PAYMENT_FIX_WAVE1=true` (already ON), `php artisan config:cache` verified via tinker `bool(true)`/`bool(true)` (@ 9d653d1b); ADR-0001 status superseded with ledger row. `config/features.php` defaults unchanged (`false`) — flip is an ops switch, revert = env line + `config:cache`.

_Dr. Mārcis Gasūns_
