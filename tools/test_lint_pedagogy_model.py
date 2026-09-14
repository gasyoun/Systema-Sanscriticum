#!/usr/bin/env python3
"""Self-test for tools/lint_pedagogy_model.py (H3763).

Each check gets one clean case (no findings) and one violating case (>=1
finding), built from minimal synthetic models — not the committed schema, so
these keep passing even after the committed model changes.

Usage:
    python -m pytest tools/test_lint_pedagogy_model.py
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from lint_pedagogy_model import (  # noqa: E402
    check_l1,
    check_l2,
    check_l3,
    check_l4,
    check_l5,
    check_l6,
)

DELEGATION_MATRIX = {
    "constitutive": ["assist", "prohibited"],
    "enabling": ["perform", "assist", "prohibited"],
    "evidentiary": ["prepare", "assist", "prohibited"],
    "logistic": ["perform", "prepare"],
}


def test_l1_clean():
    model = {
        "delegation_matrix": DELEGATION_MATRIX,
        "operations": [
            {"id": "op.a", "class_by_rung": {"A0": "constitutive"}, "ai_mode": "assist"}
        ],
    }
    assert check_l1(model) == []


def test_l1_violation():
    model = {
        "delegation_matrix": DELEGATION_MATRIX,
        "operations": [
            {"id": "op.a", "class_by_rung": {"A0": "constitutive"}, "ai_mode": "perform"}
        ],
    }
    findings = check_l1(model)
    assert len(findings) == 1
    assert "op.a" in findings[0]


def test_l1_unknown_class():
    model = {
        "delegation_matrix": DELEGATION_MATRIX,
        "operations": [
            {"id": "op.a", "class_by_rung": {"A0": "not_a_class"}, "ai_mode": "assist"}
        ],
    }
    findings = check_l1(model)
    assert len(findings) == 1
    assert "not in delegation_matrix" in findings[0]


def test_l1_ai_mode_by_rung_overrides_scalar():
    model = {
        "delegation_matrix": DELEGATION_MATRIX,
        "operations": [
            {
                "id": "op.a",
                "class_by_rung": {"A2": "constitutive", "B2": "enabling"},
                "ai_mode": "perform",
                "ai_mode_by_rung": {"A2": "assist", "B2": "perform"},
            }
        ],
    }
    assert check_l1(model) == []


def test_l2_clean_no_ai_mode_by_rung():
    model = {"operations": [{"id": "op.a", "class_by_rung": {"A0": "enabling"}}]}
    assert check_l2(model) == []


def test_l2_clean_full_coverage():
    model = {
        "operations": [
            {
                "id": "op.a",
                "class_by_rung": {"A0": "enabling", "A1": "enabling"},
                "ai_mode_by_rung": {"A0": "perform", "A1": "perform"},
            }
        ]
    }
    assert check_l2(model) == []


def test_l2_violation_missing_rung():
    model = {
        "operations": [
            {
                "id": "op.a",
                "class_by_rung": {"A0": "enabling", "A1": "enabling"},
                "ai_mode_by_rung": {"A0": "perform"},
            }
        ]
    }
    findings = check_l2(model)
    assert len(findings) == 1
    assert "A1" in findings[0]


def test_l3_clean():
    model = {
        "operations": [{"id": "op.a"}],
        "sanskrit_layer": {"failure_modes": [{"operation": "op.a", "failure": "x"}]},
    }
    assert check_l3(model) == []


def test_l3_violation():
    model = {
        "operations": [{"id": "op.a"}],
        "sanskrit_layer": {"failure_modes": [{"operation": "op.missing", "failure": "x"}]},
    }
    findings = check_l3(model)
    assert len(findings) == 1
    assert "op.missing" in findings[0]


def test_l4_clean(tmp_path):
    (tmp_path / "RealModel.php").write_text("<?php")
    model = {"operations": [{"id": "op.a", "trace": ["RealModel"]}]}
    assert check_l4(model, tmp_path) == []


def test_l4_violation(tmp_path):
    model = {"operations": [{"id": "op.a", "trace": ["GhostModel"]}]}
    findings = check_l4(model, tmp_path)
    assert len(findings) == 1
    assert "GhostModel" in findings[0]


def test_l4_checks_assessment_functions_too(tmp_path):
    model = {"assessment_functions": [{"id": "F1", "trace": ["GhostModel"]}]}
    findings = check_l4(model, tmp_path)
    assert len(findings) == 1
    assert "GhostModel" in findings[0]


def test_l5_clean():
    model = {"pilot_norms": [{"id": "N1", "probe": "VERIFICATION P1"}]}
    verification_text = "### P1 · N1 — title\n"
    assert check_l5(model, verification_text) == []


def test_l5_violation():
    model = {"pilot_norms": [{"id": "N1", "probe": "VERIFICATION P9"}]}
    verification_text = "### P1 · N1 — title\n"
    findings = check_l5(model, verification_text)
    assert len(findings) == 1
    assert "N1" in findings[0]


def test_l6_clean_pilot_norm_exempt():
    model = {"pilot_norms": [{"id": "N1", "pilot": True}]}
    assert check_l6(model, "anything") == []


def test_l6_clean_non_pilot_with_row():
    model = {"pilot_norms": [{"id": "N1", "pilot": False}]}
    verification_text = "| ... | N1 confirmed | PASS |"
    assert check_l6(model, verification_text) == []


def test_l6_violation_non_pilot_no_row():
    model = {"pilot_norms": [{"id": "N1", "pilot": False}]}
    findings = check_l6(model, "no mention of the norm here")
    assert len(findings) == 1
    assert "N1" in findings[0]


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
