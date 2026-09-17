#!/usr/bin/env python3
"""H5062 - independent review verdict schema, validator and check-run poster.

Implements the verdict half of docs/OXALPHA_STATUS_GATE_DESIGN_2026.md
section 2: a `pass` gate conclusion requires SEPARATE Standards and Spec
verdicts with evidence links; malformed or absent evidence is INCONCLUSIVE
and must become gate `fail`, never pass (PLAYBOOK_EVIDENCE_OF_DONE_2026).

Independence guard: a verdict must carry reviewer.independent == true; a
self-review by the authoring agent is rejected at validation time.

Subcommands:
  validate  --file V.json [--expect-head SHA]   -> exit 0/1 + reason lines
  post      --repo SLUG --head SHA --file V.json [--name CHECK_NAME]
            -> posts the verdict as a GitHub check run bound to the head SHA
               (run by the independent reviewer session, never by CI)

Stdlib only; hermetic tests: tools/test_oxalpha_gate_verdict.py
"""
from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path

SCHEMA = "oxalpha-review-verdict/1"
CHECK_NAME = "oxalpha-independent-verdict"
SEVERITIES = ("P0", "P1", "P2", "P3")
# Where a verdict check run is looked up by CI (design section 2: bound to head SHA).


def is_valid_location(loc: str) -> bool:
    """`path` or `path:line`, where line may be a digit, a range `31-35`, or
    comma-separated such parts (multi-path citations like
    `a.py:31-35,b.py:7` are one finding's location). Spaces are invalid -
    normalize ` ; ` joins to commas before posting."""
    import re

    if not loc or not isinstance(loc, str):
        return False
    part_re = re.compile(r"^[\w./@-]+(:\d+(-\d+)?(,\d+(-\d+)?)*)?$")
    return all(part_re.match(part) for part in loc.split(","))


def validate_verdict(data: object, expect_head: str | None = None) -> list[str]:
    """Return a list of violations; empty list == valid (evidence-complete)."""
    errs: list[str] = []
    if not isinstance(data, dict):
        return ["verdict is not a JSON object"]
    if data.get("schema") != SCHEMA:
        errs.append(f"schema tag must be {SCHEMA!r}")
    head = data.get("head_sha")
    if not (isinstance(head, str) and len(head) == 40
            and all(c in "0123456789abcdef" for c in head.lower())):
        errs.append("head_sha missing or not a 40-hex commit SHA")
    elif expect_head and head.lower() != expect_head.lower():
        errs.append(f"head_sha {head} is not the tested head {expect_head} (stale verdict)")
    reviewer = data.get("reviewer")
    if not isinstance(reviewer, dict):
        errs.append("reviewer object missing")
    else:
        if not reviewer.get("name"):
            errs.append("reviewer.name missing")
        if reviewer.get("independent") is not True:
            errs.append("reviewer.independent is not true - self-review rejected (independence rule)")
    axes = {"standards": data.get("standards"), "spec": data.get("spec")}
    for axis_name, axis in axes.items():
        if not isinstance(axis, dict):
            errs.append(f"{axis_name} verdict object missing (separate verdicts required)")
            continue
        if axis.get("verdict") not in ("pass", "fail"):
            errs.append(f"{axis_name}.verdict must be 'pass' or 'fail'")
        findings = axis.get("findings", [])
        if not isinstance(findings, list):
            errs.append(f"{axis_name}.findings must be a list")
            continue
        for i, f in enumerate(findings):
            where = f"{axis_name}.findings[{i}]"
            if not isinstance(f, dict):
                errs.append(f"{where} is not an object")
                continue
            if f.get("severity") not in SEVERITIES:
                errs.append(f"{where}.severity missing/invalid")
            if not is_valid_location(f.get("location", "")):
                errs.append(f"{where}.location missing/invalid")
            if not f.get("failure_mode"):
                errs.append(f"{where}.failure_mode missing")
            if not f.get("repro"):
                errs.append(f"{where}.repro missing")
            evidence = f.get("evidence")
            if not (isinstance(evidence, list) and evidence):
                errs.append(f"{where}.evidence missing (evidence links required)")
            if f.get("severity") in ("P0", "P1"):
                rt = f.get("regression_tests")
                if not (isinstance(rt, list) and rt and all(
                        isinstance(p, str) and (p.startswith("tests/") or "/tests/" in p
                                                or p.startswith("tools/test_"))
                        for p in rt)):
                    errs.append(f"{where} is {f.get('severity')} without regression test "
                                f"under tests/ (design section 2 fail condition)")
    spec = axes["spec"]
    if isinstance(spec, dict) and not (spec.get("evidence_links") or spec.get("findings")):
        errs.append("spec.evidence_links missing (spec verdict must cite spec surfaces)")
    return errs


def load(path: str) -> tuple[object, list[str]]:
    raw = Path(path).read_bytes()
    try:
        # utf-8-sig strips a BOM when present (house BOM rule), never silently different
        return json.loads(raw.decode("utf-8-sig")), []
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        return None, [f"verdict file unreadable/malformed JSON: {exc}"]


def verdict_conclusion(data: dict) -> str:
    """success only when BOTH axes pass; else failure (never neutral)."""
    ok = (data.get("standards", {}).get("verdict") == "pass"
          and data.get("spec", {}).get("verdict") == "pass")
    return "success" if ok else "failure"


def post_check_run(repo: str, head: str, file: str, name: str = CHECK_NAME) -> str:
    data, errs = load(file)
    if errs or not isinstance(data, dict):
        raise SystemExit(f"refusing to post invalid verdict: {errs}")
    errs = validate_verdict(data, expect_head=head)
    if errs:
        raise SystemExit(f"refusing to post invalid verdict: {errs}")
    payload = {
        "name": name,
        "head_sha": head,
        "status": "completed",
        "conclusion": verdict_conclusion(data),
        "output": {
            "title": f"Independent review: {verdict_conclusion(data)}",
            # raw JSON on purpose: CI fetches .output.summary and feeds it
            # straight back to validate - no fence-stripping round-trip.
            "summary": json.dumps(data, indent=2),
        },
    }
    out = subprocess.run(
        ["gh", "api", "--method", "POST",
         f"repos/{repo}/commits/{head}/check-runs", "--input", "-"],
        input=json.dumps(payload), capture_output=True, text=True)
    if out.returncode != 0:
        # The Checks API create endpoint is GitHub-App-only; a user token with
        # repo scope gets 404 here. Design §2 allows "commit status / check
        # run" - fall back to a commit status (140-char description limit;
        # the full verdict travels in the PR comment).
        desc = (f"standards={data['standards']['verdict']} spec={data['spec']['verdict']} "
                f"by {data['reviewer']['name']}")[:140]
        st = subprocess.run(
            ["gh", "api", "--method", "POST", f"repos/{repo}/statuses/{head}",
             "-f", f"state={verdict_conclusion(data)}",
             "-f", f"context={name}", "-f", f"description={desc}"],
            capture_output=True, text=True)
        if st.returncode != 0:
            raise SystemExit(f"check-runs failed ({out.stderr.strip()}) and commit-status "
                             f"fallback failed: {st.stderr.strip()}")
        resp = json.loads(st.stdout)
        return resp.get("target_url", "") + " (commit-status fallback; check-runs need App authority)"
    resp = json.loads(out.stdout)
    return resp.get("html_url", "")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    sub = ap.add_subparsers(dest="cmd", required=True)

    v = sub.add_parser("validate")
    v.add_argument("--file", required=True)
    v.add_argument("--expect-head", default=None)

    p = sub.add_parser("post")
    p.add_argument("--repo", required=True)
    p.add_argument("--head", required=True)
    p.add_argument("--file", required=True)
    p.add_argument("--name", default=CHECK_NAME)

    args = ap.parse_args()
    if args.cmd == "validate":
        data, errs = load(args.file)
        if data is not None:
            errs += validate_verdict(data, expect_head=args.expect_head)
        if errs:
            print("INVALID:")
            for e in errs:
                print(f"  - {e}")
            return 1
        print("VALID")
        return 0

    url = post_check_run(args.repo, args.head, args.file, args.name)
    print(url)
    return 0


if __name__ == "__main__":
    sys.exit(main())
