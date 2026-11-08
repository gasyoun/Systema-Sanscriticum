#!/usr/bin/env python3
"""vps91 capacity collector + alert evaluator (H7555, 31-10-2026).

Runs ON samskrtam50 (.91), Python 3 stdlib only, read-only against the host.
No secrets are read or printed; sizes and counters only.

Usage:
    python3 vps91_capacity_collect.py                # one JSON sample -> stdout
    python3 vps91_capacity_collect.py --samples F    # append sample to JSONL F
    python3 vps91_capacity_collect.py --growth F     # rate first->last sample
    python3 vps91_capacity_collect.py --self-test    # synthetic alert tests, no host I/O

Alert semantics mirror the live warning routes verified 31-10-2026 (see
docs/ops/VPS91_CAPACITY_REPORT_2026-10-31.md): Beszel hub fires at Disk >= 85
and auto-resolves below it; the Mac vps91-disk-watch lane used >= 85 fire /
< 80 clear hysteresis. The evaluator implements the stricter union:
fire >= 85, clear < 80, at most one alert per incident (dedup).

Ops caveat proven the same day: the Beszel hub caches alert rows in memory —
a sqlite UPDATE of a threshold is NOT picked up live. Change thresholds via
the hub UI only.
"""

import datetime
import json
import os
import subprocess
import sys

FIRE_PCT = 85
CLEAR_PCT = 80
HOT_PATHS = [
    "/srv/restic/systema",
    "/var/lib/docker",
    "/var/lib/containerd",
    "/opt/n8n/storage",
    "/opt/n8n/storage/storage/workflows",
    "/var/log/journal",
    "/var/backups/n8n",
    "/root/.cache",
]
NIGHTLY_UNITS = [
    "n8n-backup.service",
    "restic-n8n-estate.service",
    "restic-forget.service",
    "restic-fanout.service",
]
N8N_STORAGE_FILES = [
    "/opt/n8n/storage/database.sqlite",
    "/opt/n8n/storage/database.sqlite-wal",
]


def utcnow():
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def run(cmd, timeout=90):
    """Bounded subprocess; None on any failure so one broken probe never kills the sample."""
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return r.stdout if r.returncode == 0 else None
    except Exception:
        return None


def du_bytes(path):
    out = run(["du", "-sb", path], timeout=120)
    if not out:
        return None
    try:
        return int(out.split()[0])
    except (ValueError, IndexError):
        return None


def parse_df(text):
    """Parse `df -B1 /` -> dict with byte figures and used percent, or None."""
    lines = [ln for ln in text.splitlines() if ln.strip()]
    if len(lines) < 2:
        return None
    parts = lines[1].split()
    if len(parts) < 6:
        return None
    try:
        total, used, avail = int(parts[1]), int(parts[2]), int(parts[3])
        pct = int(parts[4].rstrip("%"))
    except ValueError:
        return None
    return {
        "total_bytes": total,
        "used_bytes": used,
        "avail_bytes": avail,
        "used_pct": pct,
    }


def collect():
    sample = {"ts": utcnow(), "host": "samskrtam50 (.91)"}
    df = parse_df(run(["df", "-B1", "/"]) or "")
    sample["root"] = df
    sample["inode_used_pct"] = None
    inode = run(["df", "-i", "/"])
    if inode:
        lines = [ln for ln in inode.splitlines() if ln.strip()]
        if len(lines) >= 2:
            try:
                sample["inode_used_pct"] = int(lines[1].split()[4].rstrip("%"))
            except (ValueError, IndexError):
                pass
    sample["hot_paths"] = {p: du_bytes(p) for p in HOT_PATHS}
    snaps = run(["ls", "/srv/restic/systema/snapshots"], timeout=60)
    sample["restic_snapshots"] = None if snaps is None else len([x for x in snaps.split() if x])
    sample["n8n_db"] = {}
    for f in N8N_STORAGE_FILES:
        try:
            sample["n8n_db"][os.path.basename(f)] = os.path.getsize(f)
        except OSError:
            sample["n8n_db"][os.path.basename(f)] = None
    ju = run(["journalctl", "--disk-usage"], timeout=60)
    sample["journal_usage_tail"] = ju.strip().splitlines()[-1] if ju else None
    sample["nightly_units"] = {
        u: (run(["systemctl", "show", u, "-p", "Result", "--value"], timeout=30) or "").strip() or None
        for u in NIGHTLY_UNITS
    }
    return sample


def evaluate(series):
    """Alert state machine over used-percent samples.

    Returns event dicts: {"i", "pct", "event": fire|clear}. While an incident
    is open, further >= FIRE_PCT samples emit nothing (dedup); only a drop
    below CLEAR_PCT closes it (hysteresis). After a clear it re-arms.
    """
    events, active = [], False
    for i, pct in enumerate(series):
        if pct is None:  # failed df sample: never guess a threshold crossing
            continue
        if not active and pct >= FIRE_PCT:
            events.append({"i": i, "pct": pct, "event": "fire"})
            active = True
        elif active and pct < CLEAR_PCT:
            events.append({"i": i, "pct": pct, "event": "clear"})
            active = False
    return events


def self_test():
    """Synthetic threshold/hysteresis/dedup/recovery cases — no host I/O."""
    cases = [
        # (series, expected [(index, event)])
        ([75, 86, 86, 87, 86], [(1, "fire")]),            # dedup while firing
        ([86, 81, 80, 79], [(0, "fire"), (3, "clear")]),  # hysteresis band 80..84 holds
        ([79, 84, 85], [(2, "fire")]),                    # exact threshold fires, 84 does not
        ([86, 79, 85], [(0, "fire"), (1, "clear"), (2, "fire")]),  # re-arm after recovery
        ([80] * 10, []),                                  # never fires in the hysteresis band
    ]
    ok = True
    for n, (series, want) in enumerate(cases, 1):
        got = [(e["i"], e["event"]) for e in evaluate(series)]
        status = "PASS" if got == want else "FAIL"
        ok = ok and got == want
        print(f"case {n}: {status} series={series} events={got}")
    print("self-test:", "PASS" if ok else "FAIL")
    return 0 if ok else 1


def growth(path):
    with open(path, encoding="utf-8") as fh:
        rows = [json.loads(ln) for ln in fh if ln.strip()]
    if len(rows) < 2:
        print("need >= 2 samples", file=sys.stderr)
        return 2
    a, b = rows[0], rows[-1]
    t0 = datetime.datetime.strptime(a["ts"], "%Y-%m-%dT%H:%M:%SZ")
    t1 = datetime.datetime.strptime(b["ts"], "%Y-%m-%dT%H:%M:%SZ")
    hours = max((t1 - t0).total_seconds() / 3600.0, 1e-9)
    if not a["root"] or not b["root"]:
        print("samples missing df", file=sys.stderr)
        return 2
    d_bytes = b["root"]["used_bytes"] - a["root"]["used_bytes"]
    per_day = d_bytes / (hours / 24.0)
    total = b["root"]["total_bytes"]
    to_95 = max(0.95 * total - b["root"]["used_bytes"], 0)
    print(json.dumps({
        "from": a["ts"], "to": b["ts"], "interval_hours": round(hours, 2),
        "delta_bytes": d_bytes, "bytes_per_day": round(per_day),
        "mb_per_day": round(per_day / 2**20, 1),
        "used_pct_first": a["root"]["used_pct"], "used_pct_last": b["root"]["used_pct"],
        "days_to_95pct_at_rate": round(to_95 / per_day, 1) if per_day > 0 else None,
    }, indent=2))
    return 0


def main(argv):
    if "--self-test" in argv:
        return self_test()
    if "--growth" in argv:
        return growth(argv[argv.index("--growth") + 1])
    sample = collect()
    text = json.dumps(sample, indent=2)
    if "--samples" in argv:
        with open(argv[argv.index("--samples") + 1], "a", encoding="utf-8") as fh:
            fh.write(json.dumps(sample) + "\n")
    print(text)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
