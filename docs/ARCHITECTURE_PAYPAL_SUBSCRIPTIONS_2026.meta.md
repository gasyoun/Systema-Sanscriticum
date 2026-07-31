# Metadoc — ARCHITECTURE_PAYPAL_SUBSCRIPTIONS_2026

_Created: 31-07-2026 · Last updated: 31-07-2026_

| Field | Value |
|-------|--------|
| **Purpose** | Design of record for PayPal Subscriptions as a separate provider on the H2026 billing spine |
| **Audience** | Money-path engineers, findir, product (diaspora) |
| **Provenance** | H2027 · Grok 4.5 (`grok-4.5`) · 31-07-2026 |
| **Subject** | [ARCHITECTURE_PAYPAL_SUBSCRIPTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_PAYPAL_SUBSCRIPTIONS_2026.md) |

## Ranked improvement backlog

1. After H2026 Phase 1 webhook → Payment lands: reuse ledger/amount-guard helpers rather than forking.
2. Confirm live PayPal Business can sell Subscriptions to target countries/currencies; if not, STOP + GTD `@DO`.
3. Sandbox artisan: create Product + Plan behind flag (H2027 P1).
4. Student cancel surface + dunning copy for diaspora (P2).
5. Multi-currency Payment display (USD/EUR vs RUB teacher share) — product `@DECIDE`.

## Limitations

- Phase 0 only: no live REST calls, no Payment materialisation from webhooks.
- Shared `billing_*` tables may still be migrations-only / not yet on main when this merges — provider column is the contract; migrations live with H2026 Phase 0 when that ships.
- Russian merchant international Subscription eligibility is unverified at authoring time.

## Revision history

| Date | Change |
|------|--------|
| 31-07-2026 | Initial architecture + Phase 0 stubs (H2027 P0) |

_Dr. Mārcis Gasūns_
