"""H2529 — survey Laravel 13 release state + dev-dep compatibility.

Read-only. Queries Packagist p2 metadata for the packages that gate the
Laravel 12 -> 13 move in Systema-Sanscriticum.

Usage: python scripts/h2529_survey.py
"""

import json
import sys
import urllib.request

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

PACKAGES = [
    "laravel/framework",
    "laravel/tinker",
    "orchestra/testbench",
    "nunomaduro/collision",
    "filament/filament",
    "mokhosh/filament-kanban",
    "saade/filament-fullcalendar",
]

KEYS = ("illuminate/contracts", "illuminate/support", "filament/filament", "php")


def fetch(pkg):
    url = f"https://repo.packagist.org/p2/{pkg}.json"
    with urllib.request.urlopen(url, timeout=30) as resp:
        return json.load(resp)["packages"][pkg]


def is_stable(version):
    v = version.lower()
    return not any(tag in v for tag in ("beta", "alpha", "rc", "dev"))


def main():
    for pkg in PACKAGES:
        try:
            versions = fetch(pkg)
        except Exception as exc:  # noqa: BLE001
            print(f"{pkg}: ERROR {exc}")
            continue
        print(f"=== {pkg} ===")
        stable = [v for v in versions if is_stable(v["version"])]
        latest_stable = stable[0]["version"] if stable else "(none)"
        print(f"  latest stable: {latest_stable}")
        for entry in versions[:6]:
            req = entry.get("require") or {}
            bits = [f"{k}={req[k]}" for k in KEYS if k in req]
            print(
                f"  {entry['version']:<18} {entry.get('time', '')[:10]}  "
                + ("  ".join(bits) if bits else "(no metadata in this entry)")
            )
        print()


if __name__ == "__main__":
    main()
