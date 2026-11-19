#!/usr/bin/env python3
"""P4 readiness reference harness CLI (H7263 / H5446 preparation).

Report-only: manifests come from a read-only snapshot; apply goes to an isolated
SQLite file only. See scripts/p4/README.md.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from typing import Any, Dict, Optional, Sequence

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from engine import Interrupted, Refused, apply_manifest, verify  # noqa: E402
from model import build_manifest, fingerprint  # noqa: E402

# Re-exported for tests and for callers that imported the old single module.
__all__ = ["build_manifest", "apply_manifest", "verify", "fingerprint", "Refused", "Interrupted"]

def _load(path: str) -> Dict[str, Any]:
    with open(path, "r", encoding="utf-8") as fh:
        return json.load(fh)


def cmd_plan(args: argparse.Namespace) -> int:
    snapshot = _load(args.snapshot)
    p1 = _load(args.p1) if args.p1 else None
    manifest = build_manifest(snapshot, p1=p1)
    with open(args.out, "w", encoding="utf-8") as fh:
        json.dump(manifest, fh, ensure_ascii=False, indent=2, sort_keys=True)
    print(json.dumps({
        "fingerprint": manifest["snapshot"]["fingerprint"],
        "selected_movements": len(manifest["payload"]["movements"]),
        "large_anomaly_kopecks": manifest["payload"]["large_anomaly_criterion"]["kopecks"],
        "excluded": manifest["controls"]["excluded"],
        "controls": manifest["controls"]["by_recipient_period_currency"],
    }, ensure_ascii=False, indent=2))
    return 0


def cmd_apply(args: argparse.Namespace) -> int:
    manifest = _load(args.manifest)
    observed = None
    if args.observed_ids:
        observed = _load(args.observed_ids)
    try:
        result = apply_manifest(
            args.db, manifest, run_id=args.run_id, dry_run=not args.apply,
            expected_fingerprint=args.expect_fingerprint, observed_ids=observed,
            fail_after=args.fail_after,
        )
    except Refused as exc:
        print(json.dumps({"verdict": "REFUSED", "code": exc.code, "detail": exc.detail}, ensure_ascii=False, indent=2))
        return 1
    except Interrupted as exc:
        print(json.dumps({"verdict": "INTERRUPTED", "detail": str(exc)}, ensure_ascii=False, indent=2))
        return 1
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


def cmd_verify(args: argparse.Namespace) -> int:
    result = verify(args.db, _load(args.manifest))
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0 if result["verdict"] == "PASS" else 1


def main(argv: Optional[Sequence[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="P4 readiness reference harness (report-only)")
    sub = parser.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("plan", help="build a manifest from a read-only snapshot")
    p.add_argument("--snapshot", required=True)
    p.add_argument("--p1", help="optional P1 backfill report JSON (anomaly ids)")
    p.add_argument("--out", required=True)
    p.set_defaults(func=cmd_plan)

    a = sub.add_parser("apply", help="dry-run or apply to an isolated SQLite DB")
    a.add_argument("--manifest", required=True)
    a.add_argument("--db", required=True)
    a.add_argument("--run-id", default="p4-run")
    a.add_argument("--apply", action="store_true", help="write to the isolated DB (never prod)")
    a.add_argument("--expect-fingerprint")
    a.add_argument("--observed-ids", help="JSON list of source payment ids observed now")
    a.add_argument("--fail-after", type=int, help="simulate an interruption after N movements")
    a.set_defaults(func=cmd_apply)

    v = sub.add_parser("verify", help="verify controls and cutover readiness")
    v.add_argument("--manifest", required=True)
    v.add_argument("--db", required=True)
    v.set_defaults(func=cmd_verify)

    args = parser.parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    sys.exit(main())
