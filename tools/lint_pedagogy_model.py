#!/usr/bin/env python3
"""H3763 — semantic linter for the AI-native pedagogy model (checks L1-L6).

JSON Schema (docs/schema/ai_native_pedagogy_model.schema.json) validates the
SHAPE of docs/schema/ai_native_pedagogy_model.yaml. It cannot express the
model's semantic invariants — those are checked here, per
docs/IMPLEMENTATION_AI_NATIVE_PEDAGOGICAL_DESIGN_W1.md section 2:

  L1  ai_mode is admissible for its class, at every rung, per delegation_matrix
  L2  every rung in class_by_rung also appears in ai_mode_by_rung, if the
      latter is set on that operation
  L3  every op.* referenced in sanskrit_layer.failure_modes exists in operations
  L4  every model named in a `trace` list exists as a file in app/Models/
  L5  every pilot_norms[].probe exists as a heading in VERIFICATION
  L6  no norm is marked non-pilot without a result row in the VERIFICATION table

L6 has no bypass flag: it is the check that stops an unverified rule from
quietly becoming a fact.

Usage:
    python tools/lint_pedagogy_model.py
    python tools/lint_pedagogy_model.py --yaml <path> --verification <path> --models-dir <path>

Exit codes: 0 clean · 1 findings · 2 usage error.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

import yaml

REPO_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_YAML = REPO_ROOT / "docs" / "schema" / "ai_native_pedagogy_model.yaml"
DEFAULT_VERIFICATION = REPO_ROOT / "docs" / "VERIFICATION_AI_NATIVE_PEDAGOGICAL_DESIGN.md"
DEFAULT_MODELS_DIR = REPO_ROOT / "app" / "Models"

PROBE_HEADING_RE = re.compile(r"^#{2,4}\s+([A-Z]\d+(?:\.\d+)?)\b", re.MULTILINE)


def load_yaml(path: Path) -> dict:
    with path.open("r", encoding="utf-8") as fh:
        return yaml.safe_load(fh)


def check_l1(model: dict) -> list[str]:
    """ai_mode admissible for class, per delegation_matrix, at every rung."""
    findings = []
    delegation_matrix = model.get("delegation_matrix", {})
    for op in model.get("operations", []):
        op_id = op.get("id", "<unknown>")
        class_by_rung = op.get("class_by_rung", {})
        ai_mode = op.get("ai_mode")
        ai_mode_by_rung = op.get("ai_mode_by_rung", {})
        for rung, op_class in class_by_rung.items():
            mode = ai_mode_by_rung.get(rung, ai_mode)
            allowed = delegation_matrix.get(op_class)
            if allowed is None:
                findings.append(
                    f"L1 {op_id}@{rung}: class '{op_class}' is not in delegation_matrix"
                )
                continue
            if mode not in allowed:
                findings.append(
                    f"L1 {op_id}@{rung}: ai_mode '{mode}' not admissible for class "
                    f"'{op_class}' (allowed: {allowed})"
                )
    return findings


def check_l2(model: dict) -> list[str]:
    """Every rung in class_by_rung also appears in ai_mode_by_rung, if set."""
    findings = []
    for op in model.get("operations", []):
        op_id = op.get("id", "<unknown>")
        ai_mode_by_rung = op.get("ai_mode_by_rung")
        if not ai_mode_by_rung:
            continue
        class_by_rung = op.get("class_by_rung", {})
        for rung in class_by_rung:
            if rung not in ai_mode_by_rung:
                findings.append(
                    f"L2 {op_id}@{rung}: present in class_by_rung but missing from "
                    f"ai_mode_by_rung"
                )
    return findings


def check_l3(model: dict) -> list[str]:
    """Every op.* in sanskrit_layer.failure_modes exists in operations."""
    findings = []
    known_ops = {op.get("id") for op in model.get("operations", [])}
    sanskrit_layer = model.get("sanskrit_layer", {})
    for entry in sanskrit_layer.get("failure_modes", []):
        op_id = entry.get("operation")
        if op_id not in known_ops:
            findings.append(
                f"L3 sanskrit_layer.failure_modes: operation '{op_id}' does not exist "
                f"in operations"
            )
    return findings


def check_l4(model: dict, models_dir: Path) -> list[str]:
    """Every model named in a trace list exists as a file in app/Models/."""
    findings = []
    traced: set[str] = set()
    for func in model.get("assessment_functions", []):
        traced.update(func.get("trace", []))
    for op in model.get("operations", []):
        traced.update(op.get("trace", []))
    for name in sorted(traced):
        if not (models_dir / f"{name}.php").is_file():
            findings.append(f"L4 trace: model '{name}' has no file in {models_dir}")
    return findings


def check_l5(model: dict, verification_text: str) -> list[str]:
    """Every pilot_norms[].probe exists as a heading in VERIFICATION."""
    findings = []
    headings = {m.group(1) for m in PROBE_HEADING_RE.finditer(verification_text)}
    for norm in model.get("pilot_norms", []):
        norm_id = norm.get("id", "<unknown>")
        probe = norm.get("probe", "")
        token = probe.split()[-1] if probe else ""
        if token not in headings:
            findings.append(
                f"L5 {norm_id}: probe '{probe}' has no matching heading in VERIFICATION"
            )
    return findings


def check_l6(model: dict, verification_text: str) -> list[str]:
    """No norm is marked non-pilot without a result row in the VERIFICATION table."""
    findings = []
    for norm in model.get("pilot_norms", []):
        if norm.get("pilot") is True:
            continue
        norm_id = norm.get("id", "<unknown>")
        if norm_id not in verification_text:
            findings.append(
                f"L6 {norm_id}: marked non-pilot but has no result row in VERIFICATION"
            )
    return findings


def run_all_checks(
    model: dict, verification_text: str, models_dir: Path
) -> list[str]:
    findings: list[str] = []
    findings += check_l1(model)
    findings += check_l2(model)
    findings += check_l3(model)
    findings += check_l4(model, models_dir)
    findings += check_l5(model, verification_text)
    findings += check_l6(model, verification_text)
    return findings


def main() -> int:
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--yaml", type=Path, default=DEFAULT_YAML)
    parser.add_argument("--verification", type=Path, default=DEFAULT_VERIFICATION)
    parser.add_argument("--models-dir", type=Path, default=DEFAULT_MODELS_DIR)
    args = parser.parse_args()

    if not args.yaml.is_file():
        print(f"usage error: yaml not found: {args.yaml}", file=sys.stderr)
        return 2
    if not args.verification.is_file():
        print(f"usage error: verification doc not found: {args.verification}", file=sys.stderr)
        return 2
    if not args.models_dir.is_dir():
        print(f"usage error: models dir not found: {args.models_dir}", file=sys.stderr)
        return 2

    model = load_yaml(args.yaml)
    verification_text = args.verification.read_text(encoding="utf-8")

    findings = run_all_checks(model, verification_text, args.models_dir)

    if not findings:
        print("lint_pedagogy_model: L1-L6 clean, 0 findings")
        return 0

    for finding in findings:
        print(finding)
    print(f"lint_pedagogy_model: {len(findings)} finding(s)")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
