# Metadoc — ARCHITECTURE_TOCHKA_RECURRING_BILLING_MODES_2026

_Created: 31-07-2026 · Last updated: 31-07-2026_

| Field | Value |
|-------|--------|
| **Purpose** | Design of record for multi-mode Tochka recurring billing (A–E) |
| **Audience** | Money-path engineers, findir, product |
| **Provenance** | H2026 · Grok 4.5 (`grok-4.5`) · 31-07-2026 |
| **Subject** | [ARCHITECTURE_TOCHKA_RECURRING_BILLING_MODES_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_TOCHKA_RECURRING_BILLING_MODES_2026.md) |

## Ranked improvement backlog

1. After first Tochka sandbox call: lock fiscal multi-line vs aggregated receipt for Mode B.
2. Confirm schedule-less subscription API availability on live merchant; document fallback.
3. Wire METADOCS_INDEX / PROJECT_INTERLINKS when Phase 0 lands on main.
4. Add worked numeric examples (synthetic amounts only) per mode once pilot course chosen.

## Limitations

- No live API contract dump in this doc — field names must be re-read from Tochka OpenAPI at implement time.
- Club product table not yet chosen (Course vs club_products).
- PayPal deliberately excluded (H2027).

## Revision history

| Date | Change |
|------|--------|
| 31-07-2026 | Initial architecture + modes A–E + phased ship |

_Dr. Mārcis Gasūns_
