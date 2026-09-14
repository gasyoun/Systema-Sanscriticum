<!-- SYNTHETIC self-run: reports/synthetic-golden-corpus.jsonl (68 rows, all
     "synthetic": true, generator tools/gen_synthetic_golden.py seed 20260913).
     NEVER counts toward the real-corpus precision gate (masked snapshots under
     corpora/ per corpora/README.md freeze protocol). Regenerate:
     python harness/precision_report.py --root . --corpus reports/synthetic-golden-corpus.jsonl --out reports/synthetic-precision-report.md -->
# Precision report

## topic

total: 68 · coverage: 89.7%

| category | n_gold | n_pred | tp | fp | fn | precision | recall | f1 |
|---|---|---|---|---|---|---|---|---|
| access_login | 5 | 5 | 5 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| access_window | 4 | 4 | 4 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| certificate | 1 | 1 | 1 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| deposit | 4 | 4 | 4 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| homework_progress | 1 | 1 | 1 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| installment | 4 | 4 | 4 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| materials_content | 3 | 3 | 3 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| membership_club | 1 | 1 | 1 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| pause | 6 | 6 | 6 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| payment_billing | 10 | 10 | 10 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| recording_access | 4 | 4 | 4 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| refund | 6 | 6 | 6 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| schedule | 5 | 5 | 5 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| tech_issue | 4 | 4 | 4 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| zoom_link | 3 | 3 | 3 | 0 | 0 | 1.000 | 1.000 | 1.000 |

## objection

total: 68 · coverage: 25.0%

| category | n_gold | n_pred | tp | fp | fn | precision | recall | f1 |
|---|---|---|---|---|---|---|---|---|
| b1_time | 1 | 1 | 1 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| b2_price | 4 | 4 | 4 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| b6_start_date | 2 | 2 | 2 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| b8_payment_mechanics | 3 | 3 | 3 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| b9_risk_trial | 7 | 7 | 7 | 0 | 0 | 1.000 | 1.000 | 1.000 |

## intent

total: 68 · coverage: 22.1%

| category | n_gold | n_pred | tp | fp | fn | precision | recall | f1 |
|---|---|---|---|---|---|---|---|---|
| complaint | 1 | 1 | 1 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| learn_start | 1 | 1 | 1 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| price_query | 6 | 6 | 6 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| schedule_query | 5 | 5 | 5 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| spam_noise | 2 | 2 | 2 | 0 | 0 | 1.000 | 1.000 | 1.000 |

## meta

total: 68 · coverage: 4.4%

| category | n_gold | n_pred | tp | fp | fn | precision | recall | f1 |
|---|---|---|---|---|---|---|---|---|
| human_trigger | 3 | 3 | 3 | 0 | 0 | 1.000 | 1.000 | 1.000 |

## funnel_stage

total: 68 · coverage: 89.7%

| category | n_gold | n_pred | tp | fp | fn | precision | recall | f1 |
|---|---|---|---|---|---|---|---|---|
| consultation | 20 | 20 | 20 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| course | 24 | 24 | 24 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| serve_only | 17 | 17 | 17 | 0 | 0 | 1.000 | 1.000 | 1.000 |

## escalation

total: 68 · coverage: 39.7%

| category | n_gold | n_pred | tp | fp | fn | precision | recall | f1 |
|---|---|---|---|---|---|---|---|---|
| calm | 12 | 12 | 12 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| churn_risk | 11 | 11 | 11 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| frustrated | 4 | 4 | 4 | 0 | 0 | 1.000 | 1.000 | 1.000 |

## resolution

total: 68 · coverage: 83.8%

| category | n_gold | n_pred | tp | fp | fn | precision | recall | f1 |
|---|---|---|---|---|---|---|---|---|
| auto_answerable | 18 | 18 | 18 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| faq_hit | 5 | 5 | 5 | 0 | 0 | 1.000 | 1.000 | 1.000 |
| needs_human | 34 | 34 | 34 | 0 | 0 | 1.000 | 1.000 | 1.000 |

## Uncategorized sample (0 of 0)

