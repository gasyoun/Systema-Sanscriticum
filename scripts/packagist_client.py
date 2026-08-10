"""Minimal read-only Packagist metadata client.

Shared by the H2529 Laravel-13 compatibility survey scripts. Exists so the
`https`-scheme guard below is written once: `urllib` honours `file://`, so
handing it a URL assembled from a variable is a real (if here theoretical)
arbitrary-file-read primitive, and Semgrep's
`dynamic-urllib-use-detected` rule blocks on it as a required check.

`fetch_package_versions` therefore builds the URL from a validated package
name and refuses anything that is not `https://repo.packagist.org/...`.
"""

import json
import re
import urllib.request

PACKAGIST_P2 = "https://repo.packagist.org/p2/"

# Packagist vendor/name: lowercase, digits, and - _ . only, exactly one slash.
_PACKAGE_RE = re.compile(r"^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$")


def package_metadata_url(package: str) -> str:
    """Return the p2 metadata URL for `package`, or raise if it looks unsafe."""
    if not _PACKAGE_RE.match(package):
        raise ValueError(f"refusing non-package-shaped name: {package!r}")
    url = f"{PACKAGIST_P2}{package}.json"
    # Belt-and-braces: the constructed URL must still be the https host we meant.
    if not url.startswith(PACKAGIST_P2):
        raise ValueError(f"constructed URL escaped the Packagist prefix: {url!r}")
    return url


def fetch_package_versions(package: str, timeout: int = 30) -> list[dict]:
    """Fetch the version list Packagist publishes for `package`.

    Read-only GET against a fixed https host; `package` is shape-validated and
    the scheme is pinned, so no caller-supplied value can redirect this at
    `file://` or another host.
    """
    url = package_metadata_url(package)
    request = urllib.request.Request(url, method="GET")  # noqa: S310 - scheme pinned above
    if request.type != "https":
        raise ValueError(f"refusing non-https scheme: {request.type!r}")
    with urllib.request.urlopen(request, timeout=timeout) as response:  # noqa: S310
        return json.load(response)["packages"][package]


def is_stable(version: str) -> bool:
    """True when `version` carries no pre-release tag."""
    lowered = version.lower()
    return not any(tag in lowered for tag in ("beta", "alpha", "rc", "dev"))
