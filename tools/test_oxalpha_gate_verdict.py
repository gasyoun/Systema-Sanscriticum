#!/usr/bin/env python3
"""Hermetic fixture battery for tools/oxalpha_gate_verdict.py (H5062).

Run: python3 tools/test_oxalpha_gate_verdict.py
Seeded failures: malformed JSON, missing repro, bad severity, missing spec
axis, self-review (independent=false), stale head SHA, P0/P1 without a
regression test, BOM-prefixed file. Valid fixtures must stay valid.
"""
from __future__ import annotations

import json
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from oxalpha_gate_verdict import load, validate_verdict, verdict_conclusion  # noqa: E402

HEAD = "a" * 40
FAILURES: list[str] = []


def check(name: str, cond: bool, detail: str = "") -> None:
    if cond:
        print(f"  ok  {name}")
    else:
        FAILURES.append(name)
        print(f"FAIL  {name}  {detail}")


def base_verdict() -> dict:
    return {
        "schema": "oxalpha-review-verdict/1",
        "head_sha": HEAD,
        "reviewer": {"name": "independent-oxalpha-session", "independent": True},
        "standards": {"verdict": "pass", "findings": [
            {"severity": "P3", "location": "app/Models/User.php:12",
             "failure_mode": "naming drift", "repro": "php artisan test fails",
             "evidence": ["app/Models/User.php"]}]},
        "spec": {"verdict": "pass", "findings": [], "evidence_links": ["docs/spec.md"]},
    }


def main() -> None:
    print("== oxalpha_gate_verdict fixture battery ==")
    errs = validate_verdict(base_verdict(), expect_head=HEAD)
    check("valid verdict passes", not errs, str(errs))
    check("conclusion success", verdict_conclusion(base_verdict()) == "success")

    v = base_verdict(); v["standards"]["verdict"] = "fail"
    check("negative standards -> failure", verdict_conclusion(v) == "failure")

    v = base_verdict(); del v["standards"]["findings"][0]["repro"]
    errs = validate_verdict(v)
    check("missing repro rejected", any("repro" in e for e in errs), str(errs))

    v = base_verdict(); v["standards"]["findings"][0]["severity"] = "P9"
    check("bad severity rejected", any("severity" in e for e in validate_verdict(v)))

    v = base_verdict(); del v["spec"]
    check("missing spec axis rejected (separate verdicts required)",
          any("spec" in e for e in validate_verdict(v)))

    v = base_verdict(); v["reviewer"]["independent"] = False
    errs = validate_verdict(v)
    check("self-review rejected", any("independent" in e or "self-review" in e for e in errs), str(errs))

    v = base_verdict(); v["head_sha"] = "b" * 40
    errs = validate_verdict(v, expect_head=HEAD)
    check("stale head rejected", any("stale" in e or "not the tested head" in e for e in errs), str(errs))

    for sev in ("P0", "P1"):
        v = base_verdict(); v["standards"]["findings"][0]["severity"] = sev
        errs = validate_verdict(v)
        check(f"{sev} without regression test rejected", any("regression" in e for e in errs), str(errs))
        v["standards"]["findings"][0]["regression_tests"] = ["tests/Feature/RegressTest.php"]
        check(f"{sev} with regression test accepted", not validate_verdict(v), str(validate_verdict(v)))

    v = base_verdict(); del v["spec"]["evidence_links"]; v["spec"]["findings"] = []
    check("spec without evidence_links rejected", any("evidence_links" in e for e in validate_verdict(v)))

    v = base_verdict(); v["schema"] = "something/else"
    check("wrong schema tag rejected", any("schema" in e for e in validate_verdict(v)))

    with tempfile.TemporaryDirectory() as td:
        bad = Path(td) / "broken.json"
        bad.write_text('{"schema": ')
        data, errs = load(str(bad))
        check("malformed JSON caught at load", data is None and bool(errs), str(errs))

        bom = Path(td) / "bom.json"
        bom.write_bytes(b"\xef\xbb\xbf" + json.dumps(base_verdict()).encode())
        data, errs = load(str(bom))
        check("BOM-tolerant load", data is not None and not errs, str(errs))

    if FAILURES:
        print(f"\n{len(FAILURES)} FAILURE(S): {FAILURES}")
        sys.exit(1)
    print("\nALL VERDICT TESTS PASSED")


if __name__ == "__main__":
    main()
