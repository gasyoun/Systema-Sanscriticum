# -*- coding: utf-8 -*-
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from engine_py.metrics import evaluate  # noqa: E402

REPO = Path(__file__).resolve().parents[2]


def test_evaluate_counts_and_coverage():
    from engine_py.loader import load_package

    ruleset = load_package(REPO)
    records = [
        {"id": 1, "text": "Сколько стоит курс?", "gold": {"topic": "payment_billing"}},
        {"id": 2, "text": "Сколько стоит обучение?", "gold": {"topic": "payment_billing"}},
        {"id": 3, "text": "Где ссылка на запись занятия?", "gold": {"topic": "recording_access"}},
        {"id": 4, "text": "Пришлите материалы", "gold": {"topic": "materials_content"}},
        {"id": 5, "text": "абракадабра вжух"},
    ]
    report = evaluate(ruleset, records)
    topic = report["planes"]["topic"]
    assert topic["total"] == 5
    pay = topic["categories"]["payment_billing"]
    assert (pay["tp"], pay["fp"], pay["fn"]) == (2, 0, 0)
    rec = topic["categories"]["recording_access"]
    assert (rec["tp"], rec["fn"]) == (1, 0)
    mat = topic["categories"]["materials_content"]
    assert (mat["tp"], mat["fn"]) == (1, 0)
    # coverage: 4 of 5 messages categorized in topic plane
    assert topic["coverage"] == round(4 / 5, 4)
    assert len(report["uncategorized"]) == 1


def test_precision_report_cli_smoke(tmp_path):
    corpus = tmp_path / "corpus.jsonl"
    rows = [
        {"id": 1, "text": "Сколько стоит курс?", "gold": {"topic": "payment_billing"}},
        {"id": 2, "text": "Какая цена обучения?"},
    ]
    corpus.write_text(
        "\n".join(json.dumps(r, ensure_ascii=False) for r in rows) + "\n", encoding="utf-8"
    )
    out = tmp_path / "report.md"

    sys.path.insert(0, str(REPO / "harness"))
    import precision_report

    rc = precision_report.main(["--root", str(REPO), "--corpus", str(corpus), "--out", str(out)])
    assert rc == 0
    text = out.read_text(encoding="utf-8")
    assert "# Precision report" in text and "| category |" in text
