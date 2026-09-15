"""H4860 route parity check for the routes/web.php domain split.

Two independent checks, both must pass:

1. text  — inline every `require __DIR__.'/web/X.php';` of the split
           routes/web.php skeleton, drop scaffolding (<?php, top-level `use`
           lines, the H4516 per-file headers, the skeleton docblock, the
           TODO(H4860) parking note, blank lines) and diff against the
           monolith routes/web.php of --base-ref. Every route line must be
           present verbatim; the only permitted difference is the declared
           parked block moving position (--allow-moved).
2. json  — `php artisan route:list --json` captured on --base-ref (before)
           and on the branch (after) must be byte-identical.

Usage:
    python docs/evidence/h4860/route_parity.py --base-ref origin/main \
        --before before.json --after after.json
"""

import argparse
import difflib
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

ROOT = Path(__file__).resolve().parents[3]
REQUIRE = re.compile(r"^require __DIR__\.'/web/([a-z-]+)\.php';$")
SPLIT_HEADER = ("// H4516 —", "// Registration order across routes/web/*.php")


def base_monolith(ref: str) -> list[str]:
    out = subprocess.run(
        ["git", "-C", str(ROOT), "show", f"{ref}:routes/web.php"],
        capture_output=True, text=True, encoding="utf-8", check=True,
    ).stdout
    return out.splitlines()


def inline_split() -> list[str]:
    lines: list[str] = []
    in_doc = in_todo = False
    for line in (ROOT / "routes/web.php").read_text(encoding="utf-8").splitlines():
        if line.startswith("/*"):
            in_doc = True
        if in_doc:
            in_doc = not line.startswith("*/")
            continue
        if line.startswith("// TODO(H4860)"):
            in_todo = True
        if in_todo:
            in_todo = line.strip() != ""
            continue
        m = REQUIRE.match(line)
        if m:
            part = ROOT / "routes/web" / f"{m.group(1)}.php"
            lines += part.read_text(encoding="utf-8").splitlines()
        else:
            lines.append(line)
    return lines


def normalise(lines: list[str]) -> list[str]:
    return [
        l for l in lines
        if l.strip()
        and l != "<?php"
        and not l.startswith("use ")
        and not l.startswith(SPLIT_HEADER)
    ]


def text_check(ref: str, allow_moved: int) -> bool:
    before, after = normalise(base_monolith(ref)), normalise(inline_split())
    print(f"[text] {ref} monolith: {len(before)} lines · split inlined: {len(after)} lines")
    if sorted(before) != sorted(after):
        print("[text] FAIL — line multisets differ (a route line was lost, added or edited):")
        sys.stdout.writelines(difflib.unified_diff(
            sorted(before), sorted(after), "before(sorted)", "after(sorted)", lineterm="\n"))
        return False
    ops = [op for op in difflib.SequenceMatcher(a=before, b=after, autojunk=False).get_opcodes()
           if op[0] != "equal"]
    moved = [op for op in ops if op[0] in ("delete", "insert")]
    if not ops:
        print("[text] PASS — identical line sequence")
        return True
    ok = (len(ops) == 2 and len(moved) == 2
          and before[ops[0][1]:ops[0][2]] + after[ops[0][3]:ops[0][4]]
          == after[ops[1][3]:ops[1][4]] + before[ops[1][1]:ops[1][2]]
          and max(op[2] - op[1] + op[4] - op[3] for op in ops) <= allow_moved)
    for tag, i1, i2, j1, j2 in ops:
        block = before[i1:i2] if tag == "delete" else after[j1:j2]
        print(f"[text] {tag} ({len(block)} lines, before@{i1} after@{j1}):")
        for l in block:
            print(f"         {l}")
    print("[text] PASS — one declared block moved, nothing lost or edited" if ok
          else "[text] FAIL — differences beyond a single moved block")
    return ok


def json_check(before_path: Path, after_path: Path) -> bool:
    b, a = before_path.read_bytes(), after_path.read_bytes()
    hb, ha = hashlib.sha256(b).hexdigest(), hashlib.sha256(a).hexdigest()
    n = len(json.loads(b))
    print(f"[json] before sha256 {hb} ({len(b)} bytes, {n} routes)")
    print(f"[json] after  sha256 {ha} ({len(a)} bytes, {len(json.loads(a))} routes)")
    if b == a:
        print("[json] PASS — route:list --json byte-identical (name, method, uri, middleware, action)")
        return True
    print("[json] FAIL — diff:")
    sys.stdout.writelines(difflib.unified_diff(
        json.dumps(json.loads(b), indent=1, ensure_ascii=False).splitlines(True),
        json.dumps(json.loads(a), indent=1, ensure_ascii=False).splitlines(True),
        "before", "after"))
    return False


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base-ref", default="origin/main")
    ap.add_argument("--before", type=Path, required=True)
    ap.add_argument("--after", type=Path, required=True)
    ap.add_argument("--allow-moved", type=int, default=10,
                    help="max lines in the single permitted moved block")
    args = ap.parse_args()
    ok_text = text_check(args.base_ref, args.allow_moved)
    ok_json = json_check(args.before, args.after)
    print("OVERALL:", "PASS" if ok_text and ok_json else "FAIL")
    return 0 if ok_text and ok_json else 1


if __name__ == "__main__":
    sys.exit(main())
