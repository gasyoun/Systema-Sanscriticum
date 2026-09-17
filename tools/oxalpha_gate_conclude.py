#!/usr/bin/env python3
"""H5062 - gate conclusion ladder (docs/OXALPHA_STATUS_GATE_DESIGN_2026.md §2-§4).

Exactly one of three conclusions is produced for the `oxalpha-review-gate`
check; absent or malformed evidence is a FAIL, never a pass:

  kill switch (repo variable OXALPHA_REVIEW_GATE_DISABLED=true)  -> skip (neutral)
  no executable-code paths matched                               -> skip + exclusion note
  verdict file absent after the bounded wait                     -> fail (timeout)
  verdict malformed / schema-invalid / stale head                -> fail (malformed)
  sensitive paths touched, no human approval line in PR body     -> fail (approval required)
  standards pass AND spec pass AND all findings evidence-complete -> pass
  anything else                                                   -> fail

Rollback: delete the workflow file, or set the kill-switch variable - the
gate disappears without weakening any unrelated protection.

Stdlib only; hermetic tests: tools/test_oxalpha_gate_conclude.py
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from oxalpha_gate_verdict import load as load_verdict, validate_verdict  # noqa: E402

APPROVAL_LINE = re.compile(r"Gate human approval:\s*@([A-Za-z0-9-]+)", re.IGNORECASE)


def human_approved(pr_body: str, pr_author: str | None) -> tuple[bool, str]:
    """Design §3: explicit human approval recorded in the PR body.

    The approver login on the marker line must differ from the PR author
    (an author cannot approve their own sensitive-path change).
    """
    for m in APPROVAL_LINE.finditer(pr_body or ""):
        login = m.group(1)
        if pr_author and login.lower() == pr_author.lower():
            continue
        return True, login
    return False, ""


def decide(matched: dict, verdict_path: str | None, pr_body: str,
           pr_author: str | None, kill_switch: bool, tested_head: str) -> dict:
    reasons: list[str] = []

    if kill_switch:
        return {"conclusion": "skip", "mode": "neutral",
                "summary": "OxAlpha review gate DISABLED via OXALPHA_REVIEW_GATE_DISABLED "
                           "(repository kill switch). Unrelated protections untouched; "
                           "full rollback = delete the workflow file."}

    if not matched.get("has_executable"):
        excluded = ", ".join(matched.get("excluded", [])[:20]) or "-"
        other = ", ".join(matched.get("other", [])[:20]) or "-"
        return {"conclusion": "skip", "mode": "skip-note",
                "summary": "Diff matches no executable-code pattern (design §1) - not "
                           f"reviewed as executable code. Exclusion note: excluded=[{excluded}] "
                           f"other-non-executable=[{other}]"}

    if matched.get("has_sensitive"):
        sensitive = ", ".join(matched["sensitive"])
        ok, login = human_approved(pr_body, pr_author)
        if not ok:
            reasons.append(f"sensitive paths touched ({sensitive}): design §3 requires an explicit "
                           "`Gate human approval: @login` line in the PR body by a human other "
                           "than the PR author before merge")
            return {"conclusion": "fail", "mode": "human-approval-required", "summary": "; ".join(reasons)}

    if not verdict_path or not Path(verdict_path).exists():
        return {"conclusion": "fail", "mode": "timeout",
                "summary": "No independent verdict found for this head SHA within the bounded "
                           "wait - INCONCLUSIVE is never PASS (design §2)."}

    data, errs = load_verdict(verdict_path)
    if data is not None:
        errs += validate_verdict(data, expect_head=tested_head)
    if errs:
        return {"conclusion": "fail", "mode": "malformed-verdict",
                "summary": "Independent verdict invalid (treated as fail, never pass): "
                           + "; ".join(errs)}

    assert isinstance(data, dict)
    axes_ok = (data["standards"]["verdict"] == "pass" and data["spec"]["verdict"] == "pass")
    finding_summary = []
    for axis in ("standards", "spec"):
        for f in data[axis].get("findings", []):
            finding_summary.append(f"{axis}:{f['severity']} {f['location']} {f['failure_mode']}")
    if axes_ok:
        summary = "Independent review verdict: standards=pass spec=pass. "
        summary += ("Findings (evidence-complete): " + "; ".join(finding_summary)) if finding_summary \
            else "No findings."
        return {"conclusion": "pass", "mode": "verdict", "summary": summary}
    return {"conclusion": "fail", "mode": "verdict",
            "summary": "Independent review verdict negative: "
                       f"standards={data['standards']['verdict']} spec={data['spec']['verdict']}. "
                       + ("; ".join(finding_summary) if finding_summary else "")}


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--matched-json", required=True)
    ap.add_argument("--verdict-file", default=None)
    ap.add_argument("--pr-body", default="")
    ap.add_argument("--pr-author", default=None)
    ap.add_argument("--head", required=True)
    ap.add_argument("--kill-switch", default=None,
                    help="Value of OXALPHA_REVIEW_GATE_DISABLED; true/1 disables")
    ap.add_argument("--out", default="verdict.json")
    args = ap.parse_args()

    matched = json.loads(Path(args.matched_json).read_text())
    kill = (args.kill_switch or "").strip().lower() in ("1", "true", "yes")
    result = decide(matched, args.verdict_file, args.pr_body, args.pr_author, kill, args.head)
    Path(args.out).write_text(json.dumps(result, indent=2) + "\n")

    print(f"::notice::oxalpha-review-gate conclusion: {result['conclusion']} ({result['mode']})")
    with open("gate-summary.md", "w") as fh:
        fh.write(f"## OxAlpha review gate — {result['conclusion']}\n\n{result['summary']}\n")
    print(result["summary"])
    return 0 if result["conclusion"] in ("pass", "skip") else 1


if __name__ == "__main__":
    sys.exit(main())
