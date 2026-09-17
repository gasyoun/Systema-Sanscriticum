#!/usr/bin/env python3
"""H5062 - H3546 risk-scoped executable-code matcher (Systema-Sanscriticum).

Implements docs/OXALPHA_STATUS_GATE_DESIGN_2026.md section 1 (matching) and
section 3 (sensitive money/security/production paths) EXACTLY - no broadened
path taxonomy. A diff slice matching no executable pattern is reported as
skip-with-exclusion-note, never blocked.

Stdlib only; hermetic tests: tools/test_oxalpha_gate_match.py
"""
from __future__ import annotations

import argparse
import fnmatch
import json
import subprocess
import sys
from pathlib import Path

# --- Design section 1: executable-code include patterns ---------------------
INCLUDE_PATTERNS = [
    "app/**",
    "routes/**",
    "config/*.php",
    "database/migrations/**",
    "resources/views/**/*.blade.php",  # retained only when logic changed, see below
    "tests/**",  # reviewed as spec evidence, not churn
]

# Blade paths count only when the changed lines touch logic (design: "@if/@php/auth").
BLADE_LOGIC_MARKERS = (
    "@if", "@endif", "@else", "@elseif", "@php", "@endphp", "@auth", "@endauth",
    "@guest", "@can", "@cannot", "@foreach", "@endforeach", "@forelse", "@endforelse",
    "@while", "@endwhile", "@isset", "@empty", "@include", "@includeIf", "@includeWhen",
    "@each", "@component", "@props", "@class", "@style", "@checked", "@selected",
    "@error", "@env", "@production", "@section", "@overwrite", "@extends",
)

# --- Design section 1, decision 5: default exclusions -----------------------
EXCLUDE_PATTERNS = [
    "public/vendor/**",
    "tools/*/**",               # design "tools/*/ vendored trees": first-level
                                # subdirectory trees under tools/ are vendored;
                                # top-level tools/*.py stay first-party (not excluded)
    "**/*.min.js",
    "composer.lock",
    "package-lock.json",
    "yarn.lock",
    "pnpm-lock.yaml",
    "bun.lockb",
    "docs/**",
    "CHANGELOG*",
    "*/CHANGELOG*",
    "tests/fixtures/**",        # fixture data
    "tests/Fixtures/**",
    "*/tests/fixtures/**",
    "*/tests/Fixtures/**",
    "tests/**/fixtures/**",
    ".ai_state.md",
]

# --- Design section 3: sensitive money/security/production paths ------------
# The design writes the last glob as database/migrations/*money-or-access*;
# landed as the two literal intent glob-money/*access* (documented in the
# design-doc ARMED flip; no other widening).
SENSITIVE_PATTERNS = [
    "app/Http/Controllers/*Claim*",
    "app/Http/Controllers/Webhooks/**",
    "app/Services/Payroll/**",
    "app/Models/Payment.php",
    "app/Http/Middleware/Verify*",
    "config/services.php",
    "config/receivables.php",
    "deploy.sh",
    "database/migrations/*money*",
    "database/migrations/*access*",
]


def _glob_to_regex(pattern: str) -> str:
    """Convert a `**`-aware glob to a regex (fnmatch is not path-aware)."""
    import re

    i, out = 0, []
    while i < len(pattern):
        c = pattern[i]
        if pattern[i : i + 2] == "**":
            out.append(".*")
            i += 2
            # collapse trailing "/**" handled by the .* above
            if pattern[i : i + 1] == "/":
                i += 1
        elif c == "*":
            out.append("[^/]*")
            i += 1
        elif c == "?":
            out.append("[^/]")
            i += 1
        else:
            out.append(re.escape(c))
            i += 1
    return "^" + "".join(out) + "$"


def matches(path: str, patterns: list[str]) -> bool:
    import re

    for pat in patterns:
        if re.match(_glob_to_regex(pat), path):
            return True
    return False


def is_sensitive(path: str) -> bool:
    """Design §3 globs are intent globs: `app/Http/Controllers/*Claim*` must
    also catch `app/Http/Controllers/Api/*Claim*`, so star crosses `/` here
    (fnmatch semantics), unlike the include/exclude path-aware globs above."""
    import fnmatch

    return any(fnmatch.fnmatch(path, pat) for pat in SENSITIVE_PATTERNS)


def git_changed_paths(repo: str, base: str, head: str) -> list[str]:
    out = subprocess.run(
        ["git", "-C", repo, "diff", "--name-only", f"{base}...{head}"],
        capture_output=True, text=True, check=True,
    ).stdout
    return [line.strip() for line in out.splitlines() if line.strip()]


def blade_has_logic_change(repo: str, base: str, head: str, path: str) -> bool:
    diff = subprocess.run(
        ["git", "-C", repo, "diff", f"{base}...{head}", "--", path],
        capture_output=True, text=True, check=True,
    ).stdout
    for line in diff.splitlines():
        if (line.startswith("+") or line.startswith("-")) and not line.startswith(("+++", "---")):
            lowered = line[1:].lower()
            if any(marker in lowered for marker in BLADE_LOGIC_MARKERS):
                return True
    return False


def classify(repo: str, base: str, head: str, paths: list[str] | None = None) -> dict:
    paths = paths if paths is not None else git_changed_paths(repo, base, head)
    executable: list[str] = []
    sensitive: list[str] = []
    excluded: list[str] = []
    other: list[str] = []

    for path in paths:
        if matches(path, EXCLUDE_PATTERNS):
            excluded.append(path)
            continue
        is_exec = matches(path, INCLUDE_PATTERNS)
        if is_exec and path.endswith(".blade.php") and path.startswith("resources/views/"):
            is_exec = blade_has_logic_change(repo, base, head, path)
        if is_exec:
            executable.append(path)
            if is_sensitive(path):
                sensitive.append(path)
        else:
            other.append(path)

    return {
        "schema": "oxalpha-gate-match/1",
        "base": base,
        "head": head,
        "executable": executable,
        "sensitive": sensitive,
        "excluded": excluded,
        "other": other,
        "has_executable": bool(executable),
        "has_sensitive": bool(sensitive),
    }


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--repo", default=".")
    ap.add_argument("--base", required=True)
    ap.add_argument("--head", required=True)
    ap.add_argument("--out", default="match.json")
    ap.add_argument("--paths-file", default=None,
                    help="Optional file with one path per line (hermetic mode; skips git)")
    ap.add_argument("--gha", action="store_true", help="Emit GitHub Actions outputs")
    args = ap.parse_args()

    paths = None
    if args.paths_file:
        paths = [l.strip() for l in Path(args.paths_file).read_text().splitlines() if l.strip()]
    result = classify(args.repo, args.base, args.head, paths)
    Path(args.out).write_text(json.dumps(result, indent=2) + "\n")

    if args.gha:
        print(f"executable={'true' if result['has_executable'] else 'false'}")
        print(f"sensitive={'true' if result['has_sensitive'] else 'false'}")
    else:
        print(json.dumps({"has_executable": result["has_executable"],
                          "has_sensitive": result["has_sensitive"]}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
