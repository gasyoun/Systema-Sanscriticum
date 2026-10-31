# H6851 pilot — scripted-stub

- mode: scripted-stub (deterministic, zero spend, 0.00 USD / ceiling 0.00 USD)
- target: StubFaqTarget over tests/fixtures/faq_rag_eval.json gold pairs
- live-lane pilot: **RESIDUAL — not run (see PILOT_PROTOCOL.md)**
- payload sha256: `5c436aeef2a0b728faf268c13ffa54644e88b83eecec3c7d81558ce1d164fbc2`
- 152-FZ fence hits: 0 (must be 0)

| persona | turns | verdict | goal_resolved | budget_tripped |
|---|---|---|---|---|
| PS-01 | 1/2 | PASS | True | False |
| PS-02 | 1/2 | PASS | True | False |
| PS-03 | 1/2 | PASS | True | False |
| PS-04 | 1/2 | PASS | True | False |
| PS-05 | 1/2 | PASS | True | False |
| PS-06 | 2/2 | INCONCLUSIVE | False | False |
| PS-07 | 1/1 | BUDGET_TRIPPED | False | True |

Aggregate: {"dialogs": 7, "denominator": 6, "excluded_inconclusive": 1, "passes": 5, "not_resolved": 0, "budget_tripped": 1, "pass_rate": 0.833, "rubric_mean": 0.944}

Denominator excludes INCONCLUSIVE (unreachable persona) per H6851 contract.
