"""H2529 — scan filament-kanban forks/alternatives for Laravel 13 + Filament 3 support.

Read-only. For each candidate package, prints its newest stable release plus the
newest release (any stability) whose `illuminate/contracts` constraint admits
^13.0, together with its `filament/filament` constraint — the pair that decides
whether it is a drop-in for Systema (Filament 3, Laravel 13).

Result when run 10-08-2026: of the 12 candidates only sheavescapital/filament-kanban
v5.2 admits ^13.0, and it requires filament/filament ^4.0|^5.0 — i.e. no drop-in
replacement exists that keeps Filament 3, which is why H2529 patched forks instead.

Usage: python scripts/h2529_fork_scan.py
"""

import sys

from packagist_client import fetch_package_versions, is_stable

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

CANDIDATES = [
    "sgvcode/filament-kanban",
    "relaticle/flowforge",
    "wezlo/filament-kanban",
    "sheavescapital/filament-kanban",
    "invaders-xx/filament-kanban-board",
    "leonardo-max/filament-kanban",
    "jodeveloper/filament-kanban",
    "jessedev/filament-kanban",
    "wildsea/filament-kanban",
    "tales-virtualy/filament-kanban-board",
    "alessandro-nuunes/filament-kanban",
    "rafazingano/filament-kanban",
]


def supports_13(constraint):
    return constraint is not None and "13" in constraint


def main():
    print(f"{'package':<42} {'newest stable':<14} {'L13-capable rel':<16} "
          f"{'illuminate':<34} filament")
    print("-" * 130)
    for pkg in CANDIDATES:
        try:
            versions = fetch_package_versions(pkg)
        except Exception as exc:  # noqa: BLE001
            print(f"{pkg:<42} ERROR {exc}")
            continue

        stable = [v for v in versions if is_stable(v["version"])]
        newest_stable = stable[0]["version"] if stable else "(none)"

        hit = None
        for entry in versions:
            req = entry.get("require") or {}
            if supports_13(req.get("illuminate/contracts")):
                hit = entry
                break

        if hit:
            req = hit["require"]
            print(f"{pkg:<42} {newest_stable:<14} {hit['version']:<16} "
                  f"{req.get('illuminate/contracts', '-'):<34} "
                  f"{req.get('filament/filament', '-')}")
        else:
            print(f"{pkg:<42} {newest_stable:<14} {'NONE':<16} "
                  f"{'(no release admits ^13.0)':<34} -")


if __name__ == "__main__":
    main()
