from __future__ import annotations

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from judge_agreement import cohens_kappa, measure, write_report  # noqa: E402


def _write_jsonl(path: Path, rows: list[dict]) -> None:
    path.write_text("\n".join(json.dumps(r, ensure_ascii=False) for r in rows), encoding="utf-8")


def test_cohens_kappa_perfect_agreement_is_one():
    labels = ["grounded", "ungrounded", "grounded", "grounded"]
    assert cohens_kappa(labels, labels) == 1.0


def test_cohens_kappa_chance_agreement_is_near_zero():
    # Judge alternates independently of human -> near-zero kappa expected on
    # a balanced 50/50 split with no correlation.
    human = ["grounded", "ungrounded"] * 10
    judge = ["ungrounded", "grounded"] * 10
    kappa = cohens_kappa(human, judge)
    assert kappa <= 0.0


def test_measure_computes_accuracy_and_excludes_unmatched_ids(tmp_path: Path):
    human_path = tmp_path / "human.jsonl"
    judge_path = tmp_path / "judge.jsonl"

    _write_jsonl(human_path, [
        {"msg_id": "1", "label": "grounded"},
        {"msg_id": "2", "label": "ungrounded"},
        {"msg_id": "3", "label": "grounded"},
        {"msg_id": "human_only", "label": "grounded"},
    ])
    _write_jsonl(judge_path, [
        {"msg_id": "1", "label": "grounded"},
        {"msg_id": "2", "label": "ungrounded"},
        {"msg_id": "3", "label": "ungrounded"},
        {"msg_id": "judge_only", "label": "grounded"},
    ])

    metrics = measure(human_path, judge_path)

    assert metrics["n"] == 3
    assert metrics["missing_human"] == 1
    assert metrics["missing_judge"] == 1
    assert metrics["accuracy"] == 2 / 3


def test_write_report_fails_below_gate(tmp_path: Path):
    metrics = {"n": 10, "accuracy": 0.6, "kappa": 0.5, "missing_human": 0, "missing_judge": 0}
    out = tmp_path / "report.md"

    passed = write_report(metrics, gate=0.8, out_path=out, judge_name="demo-judge")

    assert passed is False
    text = out.read_text(encoding="utf-8")
    assert "**Overall: FAIL**" in text
    assert "demo-judge" in text


def test_write_report_passes_at_or_above_gate(tmp_path: Path):
    metrics = {"n": 10, "accuracy": 0.9, "kappa": 0.85, "missing_human": 0, "missing_judge": 0}
    out = tmp_path / "report.md"

    passed = write_report(metrics, gate=0.8, out_path=out, judge_name="demo-judge")

    assert passed is True
    assert "**Overall: PASS**" in out.read_text(encoding="utf-8")
