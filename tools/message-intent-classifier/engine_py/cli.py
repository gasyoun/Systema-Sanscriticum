"""CLI: classify one text or batch a JSONL corpus through the rule set.

Usage:
  python -m engine_py.cli classify --rules rules/v1 --text "Где ссылка на запись?"
  python -m engine_py.cli classify --root . --text "..."
  python -m engine_py.cli batch --rules rules/v1 --in corpus.jsonl --out results.jsonl
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

if __package__ in (None, ""):  # direct script execution: make the package importable
    sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from engine_py.classifier import classify
from engine_py.loader import load_package


def _resolve_root(args: argparse.Namespace) -> Path:
    if getattr(args, "root", None):
        return Path(args.root)
    if getattr(args, "rules", None):
        rules_dir = Path(args.rules).resolve()
        return rules_dir.parent.parent  # .../rules/v1 -> package root
    raise SystemExit("either --rules or --root is required")


def main(argv: list[str] | None = None) -> int:
    common = argparse.ArgumentParser(add_help=False)
    common.add_argument("--rules", help="path to rules dir, e.g. rules/v1")
    common.add_argument("--root", help="package root containing rules/v1 and taxonomy/v1")
    parser = argparse.ArgumentParser(prog="engine_py", parents=[common])
    subparsers = parser.add_subparsers(dest="command", required=True)

    p_classify = subparsers.add_parser("classify", help="classify a single text", parents=[common])
    p_classify.add_argument("--text", required=True)

    p_batch = subparsers.add_parser(
        "batch", help="classify a JSONL corpus (needs 'text' field)", parents=[common]
    )
    p_batch.add_argument("--in", dest="input", required=True)
    p_batch.add_argument("--out", dest="output", required=True)

    args = parser.parse_args(argv)
    ruleset = load_package(_resolve_root(args))

    if args.command == "classify":
        json.dump(classify(ruleset, args.text), sys.stdout, ensure_ascii=False, indent=2)
        sys.stdout.write("\n")
        return 0

    with open(args.input, encoding="utf-8") as fh:
        records = [json.loads(line) for line in fh if line.strip()]
    with open(args.output, "w", encoding="utf-8") as out:
        for record in records:
            row = dict(record)
            row["prediction"] = classify(ruleset, record.get("text", ""))
            out.write(json.dumps(row, ensure_ascii=False) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
