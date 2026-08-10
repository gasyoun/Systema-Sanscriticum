"""H2529 — widen `illuminate/contracts` to admit ^13.0 in the two plugin forks.

Both blockers are Filament 3 packages whose only Laravel 13 obstacle is a
declared constraint that stops at ^12.0. This applies the identical one-line
widening upstream already proposes for fullcalendar in
saade/filament-fullcalendar#280 (base 3.x), on a branch in each gasyoun fork.

Writes via the GitHub contents API (no local clone). Idempotent: if the
constraint already admits ^13.0 the file is left untouched.

Usage: python scripts/h2529_patch_forks.py [--dry-run]
"""

import base64
import json
import subprocess
import sys

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

BRANCH = "l13-compatibility"
OLD = "^10.0|^11.0|^12.0"
NEW = "^10.0|^11.0|^12.0|^13.0"

TARGETS = [
    # (fork, base branch to cut from)
    ("gasyoun/filament-kanban", "main"),
    ("gasyoun/filament-fullcalendar", "3.x"),
]

COMMIT_MSG = (
    "fix: allow illuminate/contracts ^13.0 (Laravel 13)\n\n"
    "Filament 3 already supports illuminate ^13.0; the only obstacle was this\n"
    "declared constraint. Mirrors saade/filament-fullcalendar#280.\n\n"
    "Applied for gasyoun/Systema-Sanscriticum H2529."
)


def gh(*args, check=True):
    proc = subprocess.run(
        ["gh", *args], capture_output=True, text=True, encoding="utf-8", check=False
    )
    if check and proc.returncode != 0:
        raise RuntimeError(f"gh {' '.join(args)} failed: {proc.stderr.strip()}")
    return proc.stdout.strip()


def branch_sha(repo, ref):
    return json.loads(gh("api", f"repos/{repo}/git/ref/heads/{ref}"))["object"]["sha"]


def ensure_branch(repo, base, branch, dry_run):
    try:
        sha = branch_sha(repo, branch)
        print(f"  branch {branch} exists @ {sha[:8]}")
        return
    except RuntimeError:
        pass
    base_sha = branch_sha(repo, base)
    print(f"  creating {branch} off {base} @ {base_sha[:8]}")
    if dry_run:
        return
    gh(
        "api",
        f"repos/{repo}/git/refs",
        "-f",
        "ref=refs/heads/" + branch,
        "-f",
        f"sha={base_sha}",
    )


def patch_composer(repo, branch, dry_run):
    meta = json.loads(
        gh("api", f"repos/{repo}/contents/composer.json?ref={branch}")
    )
    content = base64.b64decode(meta["content"]).decode("utf-8")
    if NEW in content:
        print("  composer.json already admits ^13.0 — nothing to do")
        return False
    if OLD not in content:
        print(f"  !! expected constraint {OLD!r} not found — inspect manually")
        return False
    updated = content.replace(OLD, NEW, 1)
    print(f"  patching composer.json ({OLD} -> {NEW})")
    if dry_run:
        return True
    encoded = base64.b64encode(updated.encode("utf-8")).decode("ascii")
    gh(
        "api",
        "--method",
        "PUT",
        f"repos/{repo}/contents/composer.json",
        "-f",
        f"message={COMMIT_MSG}",
        "-f",
        f"content={encoded}",
        "-f",
        f"sha={meta['sha']}",
        "-f",
        f"branch={branch}",
    )
    return True


def main():
    dry_run = "--dry-run" in sys.argv
    for repo, base in TARGETS:
        print(f"=== {repo} (base {base}) ===")
        ensure_branch(repo, base, BRANCH, dry_run)
        patch_composer(repo, BRANCH, dry_run)
        if not dry_run:
            print(f"  head: {branch_sha(repo, BRANCH)[:8]}")
        print()


if __name__ == "__main__":
    main()
