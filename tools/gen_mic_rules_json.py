#!/usr/bin/env python3
"""Generate rules/v1/*.json siblings for the vendored message-intent-classifier.

The Systema runtime cannot parse YAML in prod (symfony/yaml is a dev-only
composer dep via laravel/sail), so the vendored snapshot ships precompiled
JSON twins of every rules/v1/*.yaml. This script regenerates them from the
vendored YAML and is the single source of the conversion contract:

    {"version": 1, "rules": [ {plane, category, priority, patterns,
                               negations, enabled, source}, ... ]}

Rule order inside a file and file sort order are both preserved (they carry
priority semantics downstream).

Usage:
    python3 tools/gen_mic_rules_json.py            # write *.json next to *.yaml
    python3 tools/gen_mic_rules_json.py --check    # exit 1 if any json stale

Requires PyYAML (preinstalled on GitHub ubuntu runners).
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

import yaml

PACKAGE_REL = Path("tools/message-intent-classifier")
RULES_REL = Path("rules/v1")
VERSION = 1


def compiled_entry(entry: dict) -> dict:
    return {
        "plane": entry["plane"],
        "category": entry["category"],
        "priority": int(entry["priority"]),
        "patterns": list(entry.get("patterns") or []),
        "negations": list(entry.get("negations") or []),
        "enabled": bool(entry.get("enabled", True)),
        "source": str(entry.get("source") or ""),
    }


def convert(yaml_path: Path) -> dict:
    doc = yaml.safe_load(yaml_path.read_text(encoding="utf-8"))
    if not isinstance(doc, dict) or not isinstance(doc.get("rules"), list):
        raise SystemExit(f"{yaml_path}: expected top-level mapping with 'rules' list")
    return {
        "version": VERSION,
        "rules": [compiled_entry(e) for e in doc["rules"]],
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="verify existing json is fresh")
    parser.add_argument(
        "--repo-root",
        default=str(Path(__file__).resolve().parents[1]),
        help="app repo root (default: script parent)",
    )
    args = parser.parse_args()

    rules_dir = Path(args.repo_root) / PACKAGE_REL / RULES_REL
    yaml_files = sorted(rules_dir.glob("*.yaml"))
    if not yaml_files:
        print(f"no rules/v1/*.yaml under {rules_dir}", file=sys.stderr)
        return 1

    stale: list[str] = []
    for yaml_path in yaml_files:
        payload = convert(yaml_path)
        json_path = yaml_path.with_suffix(".json")
        rendered = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
        if args.check:
            if not json_path.is_file() or json_path.read_text(encoding="utf-8") != rendered:
                stale.append(str(json_path))
        else:
            json_path.write_text(rendered, encoding="utf-8")
            print(f"wrote {json_path} ({len(payload['rules'])} rules)")

    if args.check:
        if stale:
            print("stale generated JSON (rerun without --check):", file=sys.stderr)
            for path in stale:
                print(f"  {path}", file=sys.stderr)
            return 1
        print(f"generated JSON fresh for {len(yaml_files)} rule files")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
