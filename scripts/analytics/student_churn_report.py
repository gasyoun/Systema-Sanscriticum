#!/usr/bin/env python3
"""H6254 — reproducible runner for the behavioral student-churn SQL.

Stdlib only. No PII is read, printed or stored: the SQL returns aggregates only.

Modes (default: run):
  run     --as-of YYYY-MM-DD            run the SQL against a DB/dev dump via `mysql`
  check   --as-of ... --golden FILE     byte-compare the run against a golden file
  canary  --as-of ... --shifted ...     run two cuts; mature cohorts must be identical
  compare FILE_A FILE_B                 compare two already-captured outputs (offline)

Live mode needs DB_HOST / DB_USER / DB_PASSWORD / DB_DATABASE in the environment
(same names as the Laravel .env; source it without echoing secrets):
  set -a; . /path/to/.env; set +a
  python3 scripts/analytics/student_churn_report.py run --as-of 2026-11-24

A "dev dump" is any DB with the same users/payments/lesson_views/srs_review_logs/
course_user/courses schema and the same aggregate semantics.
"""
from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
from pathlib import Path

SQL = Path(__file__).with_name("student_churn_cohorts.sql")
DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def render(as_of: str) -> str:
    if not DATE_RE.match(as_of):
        raise SystemExit(f"bad --as-of {as_of!r}: expected YYYY-MM-DD")
    sql = SQL.read_text(encoding="utf-8")
    patched, n = re.subn(r"SET @as_of = '[^']*';", f"SET @as_of = '{as_of}';", sql, count=1)
    if n != 1:
        raise SystemExit("SQL has no single SET @as_of line — refusing to guess the cut")
    env = {k: os.environ[k] for k in ("DB_HOST", "DB_USER", "DB_PASSWORD", "DB_DATABASE") if k in os.environ}
    user = env.get("DB_USER", "")
    if "DB_PASSWORD" in env:
        pw = env["DB_PASSWORD"]
        auth = ["-u" + user, "-p" + pw]
    else:
        auth = ["-u" + user]
    cmd = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), *auth, env.get("DB_DATABASE", "laravel"), "--table"]
    proc = subprocess.run(cmd, input=patched, text=True, capture_output=True)
    if proc.returncode:
        raise SystemExit(f"mysql exited {proc.returncode}: {proc.stderr.strip()[:400]}")
    return proc.stdout


def parse_survival(text: str) -> dict[str, tuple[int, ...]]:
    """cohort_month -> (cohort_n, m1_n, m1_pct|None, m2_n, m2_pct|None, m3_n, m3_pct|None)"""
    out: dict[str, tuple[int, ...]] = {}
    for line in text.splitlines():
        if not line.startswith("| survival |"):
            continue
        cells = [c.strip() for c in line.strip("|").split("|")]
        if len(cells) < 9:
            continue
        def num(v: str) -> int | None:
            return None if v in ("NULL", "") else int(float(v))
        out[cells[1]] = tuple(num(c) for c in cells[2:9])  # type: ignore[return-value]
    return out


def ym_to_idx(ym: str) -> int:
    y, m = ym.split("-")
    return int(y) * 12 + int(m)


def canary(base_text: str, shifted_text: str) -> bool:
    """Cohorts with m1/m2/m3 fully observed in BOTH cuts must be byte-identical."""
    a, b = parse_survival(base_text), parse_survival(shifted_text)
    base_cut = max(ym_to_idx(k) for k in a)
    shift_cut = max(ym_to_idx(k) for k in b)
    latest_common = min(base_cut, shift_cut) - 3
    common = sorted(k for k in a if k in b and ym_to_idx(k) <= latest_common)
    if len(common) < 3:
        print(f"CANARY INCONCLUSIVE: only {len(common)} mature shared cohorts")
        return False
    bad = [k for k in common if a[k] != b[k]]
    print(f"CANARY: {len(common)} mature cohorts (<= {latest_common//12:04d}-{latest_common%12 or 12:02d}) "
          f"compared; mismatches={len(bad)}")
    for k in common:
        print(f"  {k}  base={a[k]}  shifted={b[k]}")
    verdict = not bad
    print("CANARY PASS" if verdict else "CANARY FAIL: " + ", ".join(bad))
    return verdict


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("mode", choices=("run", "check", "canary", "compare"))
    ap.add_argument("--as-of", default="2026-11-24")
    ap.add_argument("--shifted", default="2026-10-24", help="canary: earlier cut date")
    ap.add_argument("--golden", help="check: golden output file")
    ap.add_argument("--out", help="write stdout to this file as well")
    ap.add_argument("files", nargs="*", help="compare: two captured output files")
    args = ap.parse_args()

    if args.mode == "compare":
        if len(args.files) != 2:
            raise SystemExit("compare needs exactly two files")
        return 0 if canary(Path(args.files[0]).read_text(encoding="utf-8"),
                           Path(args.files[1]).read_text(encoding="utf-8")) else 1

    if args.mode == "canary":
        return 0 if canary(render(args.as_of), render(args.shifted)) else 1

    text = render(args.as_of)
    if args.out:
        Path(args.out).write_text(text, encoding="utf-8")
    if args.mode == "check":
        if not args.golden:
            raise SystemExit("check needs --golden FILE")
        golden = Path(args.golden).read_text(encoding="utf-8")
        ok = text == golden
        print("CHECK PASS: output byte-identical to golden" if ok else "CHECK FAIL: output differs")
        return 0 if ok else 1
    print(text, end="")
    return 0


if __name__ == "__main__":
    sys.exit(main())
