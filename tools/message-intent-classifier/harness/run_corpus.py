#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""Batch offline classification of frozen masked corpora -> results/*.jsonl + baseline report.

Usage (H3528 wave-1 baseline):
  python harness/run_corpus.py --root . \
      --corpus eval=corpora/eval/2026-07-05-masked.jsonl \
      --corpus train=corpora/train/2026-08-22-masked.jsonl \
      --out-dir results --report reports/2026-08-wave1-baseline.md

Input records are mask_corpus.py output lines: {dialog_id, msg_id, direction,
date, text_masked} — a "text" field wins if present. Each corpus is classified
through the rule set and written to results/<name>.jsonl as the input row plus
"prediction". The Markdown report carries per-plane coverage%, per-category
n_pred (+ precision/recall when gold labels exist), an insufficient-evidence
mark for categories with n_gold < 30 (never auto-reply eligible), and the top-50
uncategorized sample per corpus for the next rules iteration.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from engine_py.classifier import classify  # noqa: E402
from engine_py.loader import PLANES, RuleSet, load_package  # noqa: E402
from engine_py.metrics import evaluate  # noqa: E402

MIN_GOLD_N = 30  # gate plan decision #15: precision counts only at n_gold >= 30
PRECISION_GATE = 0.93


def record_text(record: dict) -> str:
    return record.get("text") or record.get("text_masked") or ""


def record_id(record: dict, index: int) -> str:
    if record.get("id") is not None:
        return str(record["id"])
    dialog_id = record.get("dialog_id")
    msg_id = record.get("msg_id")
    if dialog_id is not None and msg_id is not None:
        return f"{dialog_id}:{msg_id}"
    return f"row-{index}"


def load_corpus(path: Path) -> list[dict]:
    records: list[dict] = []
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if line:
                records.append(json.loads(line))
    return records


def _verdict(row: dict) -> str:
    if row["n_gold"] < MIN_GOLD_N:
        return "insufficient-evidence"
    precision = row["precision"]
    if precision is None:
        return "insufficient-evidence"
    return "ok" if precision >= PRECISION_GATE else "below-gate"


def render_corpus_section(name: str, report: dict) -> list[str]:
    lines = [f"## Corpus `{name}`", ""]
    lines.append("| plane | records | coverage |")
    lines.append("|---|---|---|")
    for plane in PLANES:
        block = report["planes"][plane]
        lines.append(f"| {plane} | {block['total']} | {block['coverage']:.1%} |")
    lines.append("")
    for plane in PLANES:
        block = report["planes"][plane]
        lines += [
            f"### {plane} · coverage {block['coverage']:.1%}",
            "",
            "| category | n_pred | n_gold | precision | recall | f1 | verdict |",
            "|---|---|---|---|---|---|---|",
        ]
        if not block["categories"]:
            lines.append("| _(no matches)_ | - | - | - | - | - | - |")
        for category, row in block["categories"].items():
            def fmt(value):
                return "-" if value is None else f"{value:.3f}"

            lines.append(
                f"| {category} | {row['n_pred']} | {row['n_gold']} "
                f"| {fmt(row['precision'])} | {fmt(row['recall'])} | {fmt(row['f1'])} "
                f"| {_verdict(row)} |"
            )
        lines.append("")
    uncategorized = report["uncategorized"]
    sample = uncategorized[:50]
    lines += [
        f"### Uncategorized top-{len(sample)} of {len(uncategorized)}",
        "",
    ]
    for record in sample:
        text = (record.get("text") or "").replace("\n", " ")[:120]
        lines.append(f"- `{record.get('id')}` {text}")
    lines.append("")
    return lines


def render_baseline(corpus_reports: dict[str, dict], out_dir: Path) -> str:
    today = date.today().isoformat()
    lines = [
        "# Wave-1 baseline: offline classification of frozen corpora",
        "",
        f"_Generated: {today} · rules/v1 frozen seed · deterministic, no LLM_",
        "",
        "Gate (plan decision #15): precision >= 0.93 per category at n_gold >= 30.",
        "Categories with n_gold < 30 are marked **insufficient-evidence** and are",
        "**never auto-reply eligible**, regardless of their measured precision.",
        "",
        "| corpus | records | results |",
        "|---|---|---|",
    ]
    for name, report in corpus_reports.items():
        total = next(iter(report["planes"].values()))["total"]
        lines.append(f"| {name} | {total} | `{out_dir / (name + '.jsonl')}` |")
    lines.append("")

    failing: list[str] = []
    unlabeled = True
    for name, report in corpus_reports.items():
        lines += render_corpus_section(name, report)
        for plane in PLANES:
            for category, row in report["planes"][plane]["categories"].items():
                if row["n_gold"]:
                    unlabeled = False
                if _verdict(row) == "below-gate":
                    failing.append(f"{name}/{plane}/{category}")

    lines += ["## Gate summary", ""]
    if unlabeled:
        lines += [
            "The frozen masked snapshots carry no gold labels (mask_corpus.py emits",
            "raw dialogs only), so precision columns are empty by construction:",
            "this baseline publishes **coverage + categorized volume** only. The",
            "0.93 per-category precision gate becomes decidable once a labeled eval",
            "slice exists (ClassifierPrecisionTest-style fixture); until then every",
            "category stays **insufficient-evidence → never auto-reply eligible**.",
            "",
        ]
    if failing:
        lines += [
            "Categories below the 0.93 gate at n_gold >= 30 (stop-condition watch):",
            "",
        ]
        lines += [f"- {item}" for item in failing]
        lines.append("")
    else:
        lines += ["No category measured below-gate at n_gold >= 30.", ""]
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="run_corpus")
    parser.add_argument("--root", default=".", help="package root (rules/v1 + taxonomy/v1)")
    parser.add_argument(
        "--corpus",
        action="append",
        required=True,
        metavar="NAME=PATH",
        help="named masked JSONL corpus, repeatable (e.g. eval=corpora/eval/….jsonl)",
    )
    parser.add_argument("--out-dir", default="results", help="directory for results/<name>.jsonl")
    parser.add_argument("--report", help="write the Markdown baseline report here")
    args = parser.parse_args(argv)

    root = Path(args.root)
    ruleset = load_package(root)
    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    corpus_reports: dict[str, dict] = {}
    for spec in args.corpus:
        name, sep, path_str = spec.partition("=")
        if not sep or not name.strip() or not path_str.strip():
            raise SystemExit(f"--corpus expects NAME=PATH, got {spec!r}")
        name, path = name.strip(), Path(path_str.strip())
        records = load_corpus(path)
        results_path = out_dir / f"{name}.jsonl"
        with open(results_path, "w", encoding="utf-8") as out:
            for index, record in enumerate(records):
                prediction = classify(ruleset, record_text(record))
                row = {**record, "prediction": prediction}
                out.write(json.dumps(row, ensure_ascii=False) + "\n")
        shaped = [
            {
                "id": record_id(record, index),
                "text": record_text(record),
                **({"gold": record["gold"]} if record.get("gold") else {}),
            }
            for index, record in enumerate(records)
        ]
        corpus_reports[name] = evaluate(ruleset, shaped)

    if args.report:
        report_path = Path(args.report)
        report_path.parent.mkdir(parents=True, exist_ok=True)
        report_path.write_text(render_baseline(corpus_reports, out_dir), encoding="utf-8")
        print(f"report written: {report_path}")
    for name in corpus_reports:
        print(f"results written: {out_dir / (name + '.jsonl')}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
