#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""Per-category precision/recall + coverage report over a masked JSONL corpus.

Usage:
  python harness/precision_report.py --root . --corpus corpora/eval.jsonl --out reports/baseline.md

Corpus line format (mask_corpus.py output):
  {"id": ..., "text": ..., "gold": {"topic": "...", ...} | null, ...}
Records without "gold" count toward coverage only. The report includes the
top-50 uncategorized sample for the next rules iteration.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from engine_py.loader import PLANES, load_package  # noqa: E402
from engine_py.metrics import evaluate  # noqa: E402


def _fmt(value: float | None) -> str:
    return "-" if value is None else f"{value:.3f}"


def render(report: dict) -> str:
    lines = ["# Precision report", ""]
    for plane in PLANES:
        block = report["planes"][plane]
        lines += [
            f"## {plane}",
            "",
            f"total: {block['total']} · coverage: {block['coverage']:.1%}",
            "",
            "| category | n_gold | n_pred | tp | fp | fn | precision | recall | f1 |",
            "|---|---|---|---|---|---|---|---|---|",
        ]
        if not block["categories"]:
            lines.append("| _(no labeled data)_ | - | - | - | - | - | - | - | - |")
        for category, row in block["categories"].items():
            lines.append(
                f"| {category} | {row['n_gold']} | {row['n_pred']} | {row['tp']} "
                f"| {row['fp']} | {row['fn']} | {_fmt(row['precision'])} "
                f"| {_fmt(row['recall'])} | {_fmt(row['f1'])} |"
            )
        lines.append("")
    unc = report["uncategorized"]
    lines += [f"## Uncategorized sample ({min(len(unc), 50)} of {len(unc)})", ""]
    for record in unc[:50]:
        text = (record.get("text") or "").replace("\n", " ")[:120]
        lines.append(f"- `{record.get('id')}` {text}")
    lines.append("")
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="precision_report")
    parser.add_argument("--root", default=".", help="package root (rules/v1 + taxonomy/v1)")
    parser.add_argument("--corpus", required=True, help="masked JSONL corpus path")
    parser.add_argument("--out", help="write Markdown report here (default: stdout)")
    args = parser.parse_args(argv)

    ruleset = load_package(Path(args.root))
    with open(args.corpus, encoding="utf-8") as fh:
        records = [json.loads(line) for line in fh if line.strip()]
    report = evaluate(ruleset, records)

    rendered = render(report)
    if args.out:
        out_path = Path(args.out)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(rendered, encoding="utf-8")
        print(f"report written: {out_path}")
    else:
        print(rendered)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
