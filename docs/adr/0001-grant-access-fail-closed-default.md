# ADR 0001: Grant access fail-closed stays off by default

Grant-access logic defaults to `GRANT_ACCESS_FAIL_CLOSED=false` (`config/features.php:854`). The fail-closed decision (payment-audit H8) is split out as a separate ops switch rather than baked into money code; flipping it is a human ruling + `config:cache`, not a deploy.

Status: accepted 09-11-2026 (grill-with-docs; live prod `.env` has no line → default `false`, probe 09-11-2026).

Consequences: money regressions stay visible in queues/logs instead of blocking grants; any future default flip needs an incident runbook entry.

_Created: 09-11-2026 · Last updated: 09-11-2026_

_Dr. Mārcis Gasūns_
