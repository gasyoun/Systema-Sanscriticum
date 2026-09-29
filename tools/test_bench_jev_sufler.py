#!/usr/bin/env python3
"""Hermetic battery for tools/bench_jev_sufler.py (H5274).

Run: python3 tools/test_bench_jev_sufler.py
Covers: metric math (precision/recall/F1/agreement/latency), the frozen-corpus
guards (50 cases, gold inside the arm-A space, no unmasked PII), both arm
requests passing the H5275 client validator, the dry-run default sending
nothing, and the verdict rule. No network; the live key is never read.
"""
from __future__ import annotations

import io
import json
import sys
from contextlib import redirect_stdout
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import bench_jev_sufler as B  # noqa: E402

FAILURES: list[str] = []


def check(name: str, cond: bool, detail: str = "") -> None:
    if cond:
        print(f"  ok  {name}")
    else:
        FAILURES.append(name)
        print(f"FAIL  {name}  {detail}")


def metric_math() -> None:
    rows = [
        {"g": "A", "p": "A"}, {"g": "B", "p": "A"},
        {"g": "none", "p": "none"}, {"g": "C", "p": "C"},
    ]
    t = B.prf(rows, "g", "p", ["A", "B", "C", "none"])
    check("A tp=1 n_pred=2", t["A"]["tp"] == 1 and t["A"]["n_pred"] == 2)
    check("A precision=0.5", t["A"]["precision"] == 0.5)
    check("A recall=1.0", t["A"]["recall"] == 1.0)
    check("B recall=0.0", t["B"]["recall"] == 0.0)
    check("B f1=None when recall 0", t["B"]["f1"] is None)
    check("none precision=1.0 f1=1.0",
          t["none"]["precision"] == 1.0 and t["none"]["f1"] == 1.0)
    check("latency empty = {}", B.latency_stats([]) == {})
    st = B.latency_stats([10.0, 20.0, 30.0, 40.0])
    check("latency median", st["median_ms"] == 25.0)
    check("latency mean", st["mean_ms"] == 25.0)
    agg = B.agreement([{"r": "В2", "j": "В2"}, {"r": "OTHER", "j": "В2"}], "r", "j")
    check("agreement rate 0.5", agg["rate"] == 0.5 and agg["agree"] == 1)


def corpus_guards() -> None:
    cases = B.load_corpus()
    check("corpus frozen at 50 cases", len(cases) == 50, f"got {len(cases)}")
    allowed = set(B.ARM_A_OPTIONS) | {"none"}
    check("every gold inside the arm-A space",
          all((c["cat"] or "none") in allowed for c in cases))
    import re
    leaked = [c["t"] for c in cases
              if re.search(r"[\w.+-]+@[\w-]+\.[\w.]+|\+?\d[\d\s()-]{9,}", c["t"])]
    check("no unmasked email/phone in the corpus", not leaked, str(leaked[:2]))
    check("arm A = 7 options (A-F + none)", len(B.ARM_A_OPTIONS) == 7)
    check("arm B = 12 options (В1-В11 + OTHER)", len(B.ARM_B_OPTIONS) == 12)


def request_shape() -> None:
    client = B.load_client()
    for arm in ("a", "b"):
        req = B.build_arm_request(client, arm, "тест", "jev-1.13.0")
        ok, why = client.validate_request(req)
        check(f"arm {arm} request passes the H5275 validator", ok, why)
        check(f"arm {arm} uses exactly one question", list(req["questions"]) == ["category"])
    try:
        B.build_arm_request(client, "zzz", "x", "m")
        check("unknown arm raises", False, "no exception")
    except ValueError:
        check("unknown arm raises", True)


def dry_run_and_verdict() -> None:
    buf = io.StringIO()
    with redirect_stdout(buf):
        rc = B.main([])
    out = buf.getvalue()
    check("dry-run exits 0", rc == 0)
    check("dry-run sends nothing", "50 cases/arm NOT sent" in out)
    check("dry-run prints both arms", "arm A (dry-run" in out and "arm B (dry-run" in out)

    floor = {"accuracy": 0.98}
    check("verdict NO-GO below floor",
          B.decide_verdict({"accuracy": 0.86, "beats_live_floor": False}, floor).startswith("NO-GO"))
    check("verdict GO-candidate at/above floor",
          B.decide_verdict({"accuracy": 0.99, "beats_live_floor": True}, floor).startswith("GO-candidate"))
    check("verdict INCONCLUSIVE without arm A",
          B.decide_verdict(None, floor).startswith("INCONCLUSIVE"))


if __name__ == "__main__":
    print("== bench_jev_sufler fixture battery ==")
    metric_math()
    corpus_guards()
    request_shape()
    dry_run_and_verdict()
    if FAILURES:
        print(f"\n{len(FAILURES)} FAILURE(S): {FAILURES}")
        sys.exit(1)
    print("\nALL BENCH_JEV_SUFLER TESTS PASSED")
