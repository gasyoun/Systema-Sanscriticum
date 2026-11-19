# ADR 0002: Payment fixes ship behind `PAYMENT_FIX_WAVE1`

Payment-logic fixes (wave-1 H2–H7) are feature-flagged behind `PAYMENT_FIX_WAVE1` (`config/features.php:1858`) and rollout is an ops action, not a deploy. Prod flipped to `true` after 16-09-2026 (live probe 09-11-2026); the Oct. audit recorded it as OFF.

Status: accepted 09-11-2026 (grill-with-docs).

Consequences: IAA-level money bugs can be disabled without redeploy; flag-default docs must be kept in sync or CI-green claims drift (FINDINGS §2 pattern).

_Created: 09-11-2026 · Last updated: 09-11-2026_

_Dr. Mārcis Gasūns_
