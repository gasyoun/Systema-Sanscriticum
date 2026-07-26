# -*- coding: utf-8 -*-
"""Decode LearningApps data?jsonp=1 payloads for Systema exercise ports.

Usage:
    python scripts/decode_learningapps.py <id> [<id> ...]

See /learningapps-port skill for the tool→engine map.
"""
from __future__ import annotations

import json
import re
import sys
import urllib.parse
import urllib.request

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

TOOL_MAP = {
    "86": "sort (group assignment)",
    "71": "match (pairs)",
    "904": "match (media pairs)",
    "140": "cloze",
    "270": "table",
}


def media_text(val: str) -> str:
    if val.startswith("text|"):
        parts = val.split("|")
        return parts[1] if len(parts) > 1 else ""
    return val


def main(ids: list[str]) -> int:
    for aid in ids:
        url = f"https://learningapps.org/data?jsonp=1&id={aid}"
        raw = urllib.request.urlopen(url, timeout=45).read().decode("utf-8")  # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
        m = re.search(r"var AppClientAppData = (\{.*\});?\s*$", raw, re.S)
        if not m:
            print(aid, "PARSE FAIL", file=sys.stderr)
            continue
        data = json.loads(m.group(1))
        tool = str(data.get("tool", ""))
        flat = {
            k: (v[0] if len(v) == 1 else v)
            for k, v in urllib.parse.parse_qs(
                data.get("initparameters", ""), keep_blank_values=True
            ).items()
        }
        print("=" * 60)
        print(f"ID: {aid}")
        print(f"Title: {data.get('title')}")
        print(f"Tool: {tool} → {TOOL_MAP.get(tool, 'UNMAPPED — design new engine')}")
        print(f"Task: {data.get('tasktext')}")
        print(f"Feedback: {flat.get('feedback', '')[:120]}")
        for k in sorted(flat):
            v = flat[k]
            if not isinstance(v, str):
                continue
            if v.startswith("text|"):
                print(f"  {k}: {media_text(v)!r}")
            elif k.startswith("cloze") or k in ("clozetext", "type", "cardtype"):
                print(f"  {k}: {v[:160]!r}")
        print()
    return 0


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python scripts/decode_learningapps.py <id> [<id> ...]", file=sys.stderr)
        sys.exit(2)
    raise SystemExit(main(sys.argv[1:]))
