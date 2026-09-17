#!/usr/bin/env python3
"""Hermetic fixture battery for tools/oxalpha_gate_conclude.py (H5062).

Run: python3 tools/test_oxalpha_gate_conclude.py
Branch coverage: kill switch, doc-only skip, absent verdict (timeout),
malformed verdict, stale head, sensitive-path approval missing/present,
negative verdict, pass with evidence-complete P1 finding.
"""
from __future__ import annotations

import json
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from oxalpha_gate_conclude import decide  # noqa: E402

HEAD = "a" * 40
FAILURES: list[str] = []


def check(name: str, cond: bool, detail: str = "") -> None:
    if cond:
        print(f"  ok  {name}")
    else:
        FAILURES.append(name)
        print(f"FAIL  {name}  {detail}")


def match_json(executable: bool, sensitive: bool = False, exe_paths: list[str] | None = None,
               sen_paths: list[str] | None = None) -> dict:
    exe = exe_paths if exe_paths is not None else (["app/Models/User.php"] if executable else [])
    sen = sen_paths if sen_paths is not None else (["app/Models/Payment.php"] if sensitive else [])
    return {"schema": "oxalpha-gate-match/1", "base": "0" * 40, "head": HEAD,
            "executable": exe, "sensitive": sen,
            "excluded": ["docs/x.md"] if not executable else [],
            "other": ["README.md"] if not executable else [],
            "has_executable": bool(exe), "has_sensitive": bool(sen)}


def verdict_file(tmp: str, data: dict | None, name: str = "v.json") -> str:
    p = Path(tmp) / name
    if data is None:
        p.write_text('{"schema": broken')
    else:
        p.write_text(json.dumps(data))
    return str(p)


def passing_verdict(head: str = HEAD, independent: bool = True) -> dict:
    return {"schema": "oxalpha-review-verdict/1", "head_sha": head,
            "reviewer": {"name": "independent-session", "independent": independent},
            "standards": {"verdict": "pass", "findings": []},
            "spec": {"verdict": "pass", "findings": [], "evidence_links": ["tests/Unit/DemoTest.php"]}}


def main() -> None:
    print("== oxalpha_gate_conclude fixture battery ==")

    r = decide(match_json(True), None, "", None, kill_switch=True, tested_head=HEAD)
    check("kill switch -> skip (neutral)", r["conclusion"] == "skip" and r["mode"] == "neutral", str(r))

    r = decide(match_json(False), None, "", None, kill_switch=False, tested_head=HEAD)
    check("doc-only -> skip with exclusion note",
          r["conclusion"] == "skip" and "Exclusion note" in r["summary"], str(r))

    r = decide(match_json(False, sensitive=True, sen_paths=["deploy.sh"]), None, "", "authoruser",
               kill_switch=False, tested_head=HEAD)
    check("deploy.sh-only diff -> approval-required BEFORE skip (never silent skip)",
          r["conclusion"] == "fail" and r["mode"] == "human-approval-required", str(r))

    r = decide(match_json(False, sensitive=True, sen_paths=["deploy.sh"]), None,
               "Gate human approval: @mg-reviewer", "authoruser", False, HEAD)
    check("deploy.sh-only + approval -> skip-note records approver",
          r["conclusion"] == "skip" and "@mg-reviewer" in r["summary"], str(r))

    r = decide(match_json(True), None, "", None, kill_switch=False, tested_head=HEAD)
    check("absent verdict -> fail (timeout, never pass)",
          r["conclusion"] == "fail" and r["mode"] == "timeout", str(r))

    with tempfile.TemporaryDirectory() as td:
        r = decide(match_json(True), verdict_file(td, None), "", None, False, HEAD)
        check("malformed JSON verdict -> fail", r["conclusion"] == "fail" and r["mode"] == "malformed-verdict", str(r))

        v = passing_verdict(); v["reviewer"]["independent"] = False
        r = decide(match_json(True), verdict_file(td, v), "", None, False, HEAD)
        check("self-review verdict -> fail", r["conclusion"] == "fail" and r["mode"] == "malformed-verdict", str(r))

        r = decide(match_json(True), verdict_file(td, passing_verdict(head="b" * 40)), "", None, False, HEAD)
        check("stale head verdict -> fail", r["conclusion"] == "fail", str(r))

        r = decide(match_json(True, sensitive=True), verdict_file(td, passing_verdict()),
                   "some body text", "authoruser", False, HEAD)
        check("sensitive without approval -> fail",
              r["conclusion"] == "fail" and r["mode"] == "human-approval-required", str(r))

        r = decide(match_json(True, sensitive=True, exe_paths=["app/Models/Payment.php", "tests/Feature/PaymentRegressTest.php"]),
                   verdict_file(td, passing_verdict()),
                   "Gate human approval: @mg-reviewer", "authoruser", False, HEAD)
        check("sensitive + other-human approval + regression tests + valid verdict -> pass",
              r["conclusion"] == "pass", str(r))

        r = decide(match_json(True, sensitive=True), verdict_file(td, passing_verdict()),
                   "Gate human approval: @AuthorUser", "authoruser", False, HEAD)
        check("author self-approval line rejected -> fail", r["conclusion"] == "fail", str(r))

        r = decide(match_json(True), verdict_file(td, passing_verdict()), "", None, False, HEAD)
        check("ordinary executable + valid verdict -> pass", r["conclusion"] == "pass", str(r))

        bad = match_json(True); bad["infra_failure"] = True
        r = decide(bad, None, "", None, False, HEAD)
        check("match infra failure -> fail (never benign skip)",
              r["conclusion"] == "fail" and r["mode"] == "infrastructure", str(r))

        r = decide(match_json(True, sensitive=True), verdict_file(td, passing_verdict()),
                   "Gate human approval: @mg-reviewer", "authoruser", False, HEAD)
        check("sensitive pass WITHOUT regression tests in diff -> fail (§3a)",
              r["conclusion"] == "fail" and "§3(a)" in r["summary"], str(r))

        v = passing_verdict(); v["spec"]["verdict"] = "fail"
        v["spec"]["findings"] = [{"severity": "P1", "location": "app/Models/Payment.php:40",
                                  "failure_mode": "double charge", "repro": "seeded test",
                                  "evidence": ["diff hunk"], "regression_tests": ["tests/Feature/T.php"]}]
        r = decide(match_json(True), verdict_file(td, v), "", None, False, HEAD)
        check("negative spec verdict -> fail with finding cited",
              r["conclusion"] == "fail" and "double charge" in r["summary"], str(r))

        v = passing_verdict()
        v["standards"]["findings"] = [{"severity": "P1", "location": "app/Models/User.php:9",
                                       "failure_mode": "mass assignment", "repro": "curl",
                                       "evidence": ["hunk"], "regression_tests": ["tests/Feature/Mass.php"]}]
        r = decide(match_json(True), verdict_file(td, v), "", None, False, HEAD)
        check("P1 WITH regression test still passes when axes pass",
              r["conclusion"] == "pass", str(r))

    if FAILURES:
        print(f"\n{len(FAILURES)} FAILURE(S): {FAILURES}")
        sys.exit(1)
    print("\nALL CONCLUDE TESTS PASSED")


if __name__ == "__main__":
    main()
