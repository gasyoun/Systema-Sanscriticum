# Grammar Lab G4 — pilot readout

_Created: 14-08-2026 · Last updated: 14-08-2026_

**Status: pilot not human-authorized.**

H2495 (Grok 4.6) — Grammar Lab G4: hybrid entitlement, sandbox matrix, and learner pilot
ships consent, cohort assignment, and instrumentation. It does **not** invite students.
Until a human names a 5–10 roster and flips `GRAMMAR_LAB_PILOT`, every aggregate is a
sandbox / empty-cohort readout.

## Measures (exact denominators)

| Measure | Numerator | Denominator |
|---|---|---|
| Task completion | consented participants who opened Whitney↔Zalizniak compare | active consented `n` |
| Quiz accuracy | correct attempt events | all quiz attempt events |
| Return use | participants with events on ≥2 UTC dates | active consented `n` |
| Confusion | coded confusion events | active consented `n` |

Command: `php artisan grammar-lab:pilot-report`. The JSON has `n_consented`, `n_active`,
`n_withdrawn`, and `missing` on each rate. Identity is never in the payload.

## Empty-cohort snapshot (this merge)

| Field | Value |
|---|---|
| `pilot_authorized` | false |
| `n_consented` | 0 |
| `n_active` | 0 |
| task / quiz / return / confusion | 0 / 0 |

A 5–10-person pilot cannot support population-level learning claims. The next session
that is given a named roster reports the four rates with those denominators and stops.

## Eligibility

`php artisan grammar-lab:pilot-eligibility --json` counts current students with a real
paid key on `GRAMMAR_LAB_PILOT_COURSE_SLUGS` (or included course slugs). It does not
enroll and does not print emails unless a later ops command is written for that.

_Dr. Mārcis Gasūns_
