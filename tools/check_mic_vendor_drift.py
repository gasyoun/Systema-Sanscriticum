#!/usr/bin/env python3
"""H3529 drift gate for the vendored message-intent-classifier snapshot.

Two checks, both mandatory:

1. Tree drift — the vendored tree (minus .git and declared generated files)
   must be byte-identical to upstream gasyoun/message-intent-classifier at the
   SHA recorded in tools/message-intent-classifier/PINNED_SHA.
2. Generated-JSON parity — every rules/v1/*.yaml twin must equal what
   tools/gen_mic_rules_json.py regenerates from that YAML right now.

Usage (CI):
    python tools/check_mic_vendor_drift.py --upstream _upstream-mic   # full gate
    python tools/check_mic_vendor_drift.py --self-only                # no upstream access

`--upstream` verifies tree byte-parity against a checkout of
gasyoun/message-intent-classifier at the pinned SHA. The package repo is
PRIVATE, so CI can only run the full gate once a read-only PAT is added as
the MIC_UPSTREAM_TOKEN Actions secret; until then CI runs --self-only
(generated-JSON freshness + pin sanity) and says so loudly.
"""

from __future__ import annotations

import argparse
import filecmp
import json
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]
VENDOR_DIR = REPO_ROOT / "tools" / "message-intent-classifier"

# Vendoring metadata + generated artifacts — not part of the upstream tree.
LOCAL_ONLY = {"PINNED_SHA", "VENDOR.md"}
GENERATED = "rules/v1/*.json"


def is_git_internal(rel: str) -> bool:
    parts = Path(rel).parts
    return ".git" in parts


def collect(rel_root: Path) -> dict[str, Path]:
    return {
        str(path.relative_to(rel_root)): path
        for path in sorted(rel_root.rglob("*"))
        if path.is_file() and not is_git_internal(str(path.relative_to(rel_root)))
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--upstream", help="checkout of gasyoun/message-intent-classifier at the pinned SHA")
    group.add_argument(
        "--self-only",
        action="store_true",
        help="skip upstream tree parity (private repo without MIC_UPSTREAM_TOKEN); verify JSON freshness + pin sanity only",
    )
    args = parser.parse_args()

    pin = (VENDOR_DIR / "PINNED_SHA").read_text(encoding="utf-8").strip()
    if len(pin) != 40 or not all(c in "0123456789abcdef" for c in pin.lower()):
        print(f"::error::PINNED_SHA malformed: {pin!r}")
        return 1

    failures: list[str] = []
    yaml_files = sorted((VENDOR_DIR / "rules" / "v1").glob("*.yaml"))
    if not yaml_files:
        failures.append("no rules/v1/*.yaml found in vendor dir")

    if args.upstream is not None:
        vendored = {
            rel: path
            for rel, path in collect(VENDOR_DIR).items()
            if rel not in LOCAL_ONLY and not Path(rel).match(GENERATED)
        }
        upstream = collect(Path(args.upstream))

        missing = sorted(set(upstream) - set(vendored))
        extra = sorted(set(vendored) - set(upstream))
        if missing:
            failures.append(f"files missing from vendor dir: {missing}")
        if extra:
            failures.append(f"files in vendor dir absent from pin {pin[:12]}: {extra}")

        changed = sorted(
            rel
            for rel in set(vendored) & set(upstream)
            if not filecmp.cmp(vendored[rel], upstream[rel], shallow=False)
        )
        if changed:
            failures.append(f"vendored copies drifted from pin {pin[:12]}: {changed}")
    else:
        print(
            "::warning::upstream tree parity NOT verified (private repo; set the "
            "MIC_UPSTREAM_TOKEN Actions secret to enable the full gate)"
        )

    # Generated JSON parity (always on — needs no upstream access).
    sys.path.insert(0, str(REPO_ROOT / "tools"))
    import gen_mic_rules_json  # noqa: E402  (local tool)

    for yaml_path in yaml_files:
        payload = gen_mic_rules_json.convert(yaml_path)
        json_path = yaml_path.with_suffix(".json")
        rendered = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
        if not json_path.is_file():
            failures.append(f"missing generated twin: {json_path.name}")
        elif json_path.read_text(encoding="utf-8") != rendered:
            failures.append(f"stale generated twin: {json_path.name} (rerun tools/gen_mic_rules_json.py)")

    if failures:
        for failure in failures:
            print(f"::error::{failure}")
        return 1

    print(f"vendored snapshot matches pin {pin[:12]}; generated JSON fresh ({len(yaml_files)} rule files)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
