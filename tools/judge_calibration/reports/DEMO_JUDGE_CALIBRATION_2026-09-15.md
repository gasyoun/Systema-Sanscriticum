# Judge-calibration report — H4589

Judge candidate: **demo-judge-v1 (synthetic fixture, NOT a real GigaChat/DeepSeek run)**

| Metric | Value | Gate | Verdict |
|---|---|---|---|
| N (aligned labels) | 20 | - | - |
| Accuracy | 0.900 | >= 0.80 | PASS |
| Cohen's kappa | 0.780 | >= 0.80 | FAIL |

**Overall: FAIL** — a FAIL here means the judge is NOT calibrated enough to gate any auto-flagging action; Суфлер keeps the human button regardless of this result (measurement only, per H4589 mission).

Skipped rows: 0 judged-but-no-human-label, 0 human-but-no-judge-label (excluded from N above).

No raw dialog text is read or written by this script — inputs are label files keyed on msg_id over an already-masked corpus (tools/message-intent-classifier/tools/mask_corpus.py contract).
