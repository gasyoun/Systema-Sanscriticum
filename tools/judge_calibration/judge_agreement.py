#!/usr/bin/env python3
"""H4589: judge-vs-human agreement calibration (Habr 949124 pattern).

Measures whether an LLM judge (GigaChat/DeepSeek candidate) agrees with a
human curator closely enough to be trusted for ANY auto-flagging action on
Суфлер free-text drafts (categories D/E/F, LLM-composed). This harness never
takes an auto-action itself — it only produces the PASS/FAIL calibration
number a human reads before turning a judge on for anything.

Input: two JSONL files, row-aligned by `msg_id`:
  --human FILE   {"msg_id": ..., "label": "grounded"|"ungrounded", ...}
  --judge FILE   {"msg_id": ..., "label": "grounded"|"ungrounded", ...}
Both must be produced from an ALREADY masked corpus (mask_corpus.py contract:
dialog_id/msg_id/direction/date/text_masked) — this script never reads raw
dialog text, only labels, and never writes text fields into its report.

The judge call itself (GigaChat/DeepSeek API, few-shot prompt) is NOT wired
here — no credentials available to this worker. --judge is a label file
produced by whatever judge run happened out of band; wiring a live API call
is future work (tracked in the closing handoff note, not silently assumed).

Gate: agreement (accuracy) and Cohen's kappa both >= --gate (default 0.8).
Below gate -> report says FAIL and the calibration must not be read as PASS
by anything downstream (acceptance rule: "judge agreement <0.8 reported as
PASS" is itself a defect).

Stdlib only. No network.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")


def read_labels(path: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    with path.open("r", encoding="utf-8") as fh:
        for line_no, line in enumerate(fh, start=1):
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            msg_id = row.get("msg_id")
            label = row.get("label")
            if msg_id is None or label is None:
                raise ValueError(f"{path}:{line_no}: row missing msg_id/label: {row}")
            out[str(msg_id)] = str(label)
    return out


def cohens_kappa(human: list[str], judge: list[str]) -> float:
    """Two-rater Cohen's kappa over the aligned label lists."""
    n = len(human)
    if n == 0:
        return 0.0

    labels = sorted(set(human) | set(judge))
    idx = {label: i for i, label in enumerate(labels)}
    k = len(labels)

    confusion = [[0] * k for _ in range(k)]
    for h, j in zip(human, judge):
        confusion[idx[h]][idx[j]] += 1

    po = sum(confusion[i][i] for i in range(k)) / n

    row_totals = [sum(confusion[i]) for i in range(k)]
    col_totals = [sum(confusion[i][j] for i in range(k)) for j in range(k)]
    pe = sum((row_totals[i] / n) * (col_totals[i] / n) for i in range(k))

    if pe == 1.0:
        return 1.0 if po == 1.0 else 0.0

    return (po - pe) / (1 - pe)


def measure(human_path: Path, judge_path: Path) -> dict:
    human = read_labels(human_path)
    judge = read_labels(judge_path)

    shared_ids = sorted(set(human) & set(judge))
    missing_human = sorted(set(judge) - set(human))
    missing_judge = sorted(set(human) - set(judge))

    if not shared_ids:
        raise ValueError("no msg_id overlap between --human and --judge label files")

    human_labels = [human[i] for i in shared_ids]
    judge_labels = [judge[i] for i in shared_ids]

    n = len(shared_ids)
    agree = sum(1 for h, j in zip(human_labels, judge_labels) if h == j)
    accuracy = agree / n
    kappa = cohens_kappa(human_labels, judge_labels)

    return {
        "n": n,
        "accuracy": accuracy,
        "kappa": kappa,
        "missing_human": len(missing_human),
        "missing_judge": len(missing_judge),
    }


def write_report(metrics: dict, gate: float, out_path: Path, judge_name: str) -> bool:
    passed = metrics["accuracy"] >= gate and metrics["kappa"] >= gate
    verdict = "PASS" if passed else "FAIL"

    lines = [
        "# Judge-calibration report — H4589",
        "",
        f"Judge candidate: **{judge_name}**",
        "",
        "| Metric | Value | Gate | Verdict |",
        "|---|---|---|---|",
        f"| N (aligned labels) | {metrics['n']} | - | - |",
        f"| Accuracy | {metrics['accuracy']:.3f} | >= {gate:.2f} | "
        f"{'PASS' if metrics['accuracy'] >= gate else 'FAIL'} |",
        f"| Cohen's kappa | {metrics['kappa']:.3f} | >= {gate:.2f} | "
        f"{'PASS' if metrics['kappa'] >= gate else 'FAIL'} |",
        "",
        f"**Overall: {verdict}** — a FAIL here means the judge is NOT calibrated "
        "enough to gate any auto-flagging action; Суфлер keeps the human button "
        "regardless of this result (measurement only, per H4589 mission).",
        "",
        f"Skipped rows: {metrics['missing_human']} judged-but-no-human-label, "
        f"{metrics['missing_judge']} human-but-no-judge-label (excluded from N above).",
        "",
        "No raw dialog text is read or written by this script — inputs are "
        "label files keyed on msg_id over an already-masked corpus "
        "(tools/message-intent-classifier/tools/mask_corpus.py contract).",
        "",
    ]

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text("\n".join(lines), encoding="utf-8")
    return passed


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--human", required=True, type=Path, help="JSONL of human labels {msg_id, label}")
    parser.add_argument("--judge", required=True, type=Path, help="JSONL of judge labels {msg_id, label}")
    parser.add_argument("--judge-name", default="unspecified", help="judge candidate name for the report header")
    parser.add_argument("--gate", type=float, default=0.8, help="minimum accuracy AND kappa to report PASS")
    parser.add_argument("--out", type=Path, default=Path("tools/judge_calibration/reports/judge_calibration_report.md"))
    args = parser.parse_args(argv)

    metrics = measure(args.human, args.judge)
    passed = write_report(metrics, args.gate, args.out, args.judge_name)

    print(f"n={metrics['n']} accuracy={metrics['accuracy']:.3f} kappa={metrics['kappa']:.3f} "
          f"gate={args.gate:.2f} verdict={'PASS' if passed else 'FAIL'}")
    print(f"report written: {args.out}")

    return 0 if passed else 1


if __name__ == "__main__":
    sys.exit(main())
