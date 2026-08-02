#!/usr/bin/env python3
"""H2148 C — agent-side soft-alert triage stub (dev machine, not prod daemon).

Default: SSH diagnose only (ops:soft-remediate --dry-run --json).
Optional --apply-safe: runs ops:soft-remediate --apply --apply-breaker-clear
  (still safe-only inside Laravel — never hard fuse / diverging dirty destroy).

Usage:
  python scripts/ops/soft_alert_agent_stub.py --ssh root@193.232.229.92
  python scripts/ops/soft_alert_agent_stub.py --ssh root@193.232.229.92 --apply-safe

Never: force-push, migrate --force, money artisan, blind rm of hard fuse.
Playbook: docs/SERVER_SOFT_ALERT_PLAYBOOK.md
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys


def main() -> int:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8")
    if hasattr(sys.stderr, "reconfigure"):
        sys.stderr.reconfigure(encoding="utf-8")

    p = argparse.ArgumentParser(description="Soft-alert agent stub (H2148)")
    p.add_argument("--ssh", default="root@193.232.229.92", help="SSH target")
    p.add_argument("--app-dir", default="/var/www/html", help="Remote APP_DIR")
    p.add_argument(
        "--apply-safe",
        action="store_true",
        help="Run safe apply + optional soft fuse clear (still Laravel-gated)",
    )
    p.add_argument("--timeout", type=int, default=120)
    args = p.parse_args()

    remote_cmd = (
        f"cd {args.app_dir} && "
        f"php artisan ops:soft-remediate --dry-run --json"
    )
    if args.apply_safe:
        remote_cmd = (
            f"cd {args.app_dir} && "
            f"php artisan ops:soft-remediate --apply --apply-breaker-clear --json"
        )

    ssh = [
        "ssh",
        "-o",
        "BatchMode=yes",
        "-o",
        "ConnectTimeout=10",
        args.ssh,
        remote_cmd,
    ]
    print("RUN:", " ".join(ssh), flush=True)
    try:
        proc = subprocess.run(
            ssh,
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=args.timeout,
        )
    except FileNotFoundError:
        print("ssh not found on this machine", file=sys.stderr)
        return 2
    except subprocess.TimeoutExpired:
        print("ssh timed out", file=sys.stderr)
        return 2

    out = (proc.stdout or "").strip()
    err = (proc.stderr or "").strip()
    if err:
        print("STDERR:", err, file=sys.stderr)
    print(out)
    if proc.returncode not in (0, 1):
        # Laravel: 0 ok, 1 needs_human, 2 error
        return proc.returncode

    # Best-effort pretty summary if JSON
    try:
        data = json.loads(out)
        print(
            f"\nsummary: status={data.get('status')} "
            f"exit={data.get('exit_code')} "
            f"message={data.get('message')}",
            flush=True,
        )
        if data.get("diverging"):
            print("DIVERGING (human):", ", ".join(data["diverging"]))
        if data.get("status") == "needs_human":
            print("Next: open/update GitHub issue; do NOT clear hard fuse.")
            return 1
    except json.JSONDecodeError:
        pass

    return 0 if proc.returncode == 0 else proc.returncode


if __name__ == "__main__":
    raise SystemExit(main())
