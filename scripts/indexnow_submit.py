#!/usr/bin/env python3
"""Submit changed URLs to IndexNow (Yandex, Bing, Seznam, Naver, Yep).

Why this exists: `SEO_MEASUREMENT_FLOOR_2026-10-19.md` §5 documents an
IndexNow step but the repo never carried the script, so the runbook pointed
at a file that did not exist. This is the repo-local, dependency-free
implementation: standard library only, no `requests`, no managed venv.

Dry-run is the default. `--apply` is required to touch the network:

    # 1. Print the payload and validate host/key/URLs offline.
    python3 scripts/indexnow_submit.py \
        --host samskrte.ru \
        --key 3d8b1b068ee599d1f5aa9ae9c63d0968 \
        --key-location https://samskrte.ru/3d8b1b068ee599d1f5aa9ae9c63d0968.txt \
        --urls-file /tmp/urls.txt

    # 2. Verify the key file is published and matches.
    python3 scripts/indexnow_submit.py ... --verify-only

    # 3. Submit (pre-flight key check is automatic).
    python3 scripts/indexnow_submit.py ... --apply

Key and key-location may come from `INDEXNOW_KEY` / `INDEXNOW_KEY_LOCATION`.
The key file body must be the key itself; it lives at
`public/<key>.txt` and is served from the site root.

IndexNow caps a batch at 10 000 URLs and requires every URL to belong to the
declared host. Google does not participate; this is a Yandex/Bing freshness
signal, not a Google indexing API.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path
from typing import Iterable, Sequence
from urllib.parse import urlparse

sys.stdout.reconfigure(encoding="utf-8")

INDEXNOW_ENDPOINT = "https://api.indexnow.org/indexnow"
MAX_URLS = 10_000
MIN_KEY_LENGTH = 8
MAX_KEY_LENGTH = 128


class IndexNowError(ValueError):
    """Validation or transport error with a human-readable message."""


def normalize_host(value: str) -> str:
    """Return the bare lower-case host from `example.com` or `https://example.com/`."""
    parsed = urlparse(value if "://" in value else f"//{value}")
    host = (parsed.hostname or value).lower().rstrip(".")
    if not host:
        raise IndexNowError(f"cannot read a host from {value!r}")
    return host


def belongs_to_host(url: str, host: str) -> bool:
    """True when `url` is on `host` or one of its subdomains."""
    parsed = urlparse(url)
    if parsed.scheme not in ("http", "https") or not parsed.hostname:
        return False
    url_host = parsed.hostname.lower().rstrip(".")
    declared = normalize_host(host)
    return url_host == declared or url_host.endswith(f".{declared}")


def load_urls(arg_urls: Sequence[str], urls_file: str | None) -> list[str]:
    """Merge `--urls` and `--urls-file`, dropping blanks, comments and duplicates."""
    items: list[str] = list(arg_urls or [])
    if urls_file:
        for line in Path(urls_file).read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#"):
                items.append(line)

    seen: set[str] = set()
    unique: list[str] = []
    for item in items:
        if item not in seen:
            seen.add(item)
            unique.append(item)
    return unique


def validate(
    *,
    host: str,
    key: str,
    key_location: str,
    urls: Iterable[str],
) -> list[str]:
    """Validate a submission and return the URL list.

    Raises `IndexNowError` on the first defect. Validation is deliberately
    strict: a silently rejected batch looks like success in the logs.
    """
    normalized = normalize_host(host)

    if not (MIN_KEY_LENGTH <= len(key) <= MAX_KEY_LENGTH):
        raise IndexNowError(
            f"key must be {MIN_KEY_LENGTH}-{MAX_KEY_LENGTH} chars, got {len(key)}"
        )

    if not belongs_to_host(key_location, normalized):
        raise IndexNowError(f"key-location {key_location!r} is not on host {normalized!r}")

    url_list = list(urls)
    if not url_list:
        raise IndexNowError("no URLs: pass --urls or --urls-file")
    if len(url_list) > MAX_URLS:
        raise IndexNowError(f"IndexNow caps a batch at {MAX_URLS} URLs, got {len(url_list)}")

    foreign = [url for url in url_list if not belongs_to_host(url, normalized)]
    if foreign:
        examples = ", ".join(foreign[:3])
        raise IndexNowError(
            f"{len(foreign)} URL(s) do not belong to {normalized!r}: {examples}"
        )

    return url_list


def build_payload(*, host: str, key: str, key_location: str, urls: Sequence[str]) -> dict:
    """The exact JSON body IndexNow expects."""
    return {
        "host": normalize_host(host),
        "key": key,
        "keyLocation": key_location,
        "urlList": list(urls),
    }


def _read_response(response) -> tuple[int, str]:
    status = getattr(response, "status", None) or response.getcode()
    body = response.read().decode("utf-8", errors="replace")
    return int(status), body


def verify_key(*, host: str, key: str, key_location: str, timeout: float = 15.0) -> None:
    """Fetch the published key file and confirm it matches.

    IndexNow treats a missing or wrong key file as a silent rejection, so the
    pre-flight is mandatory before `--apply`.
    """
    if not belongs_to_host(key_location, host):
        raise IndexNowError(f"key-location {key_location!r} is not on host {host!r}")

    try:
        with urllib.request.urlopen(key_location, timeout=timeout) as response:
            status, body = _read_response(response)
    except urllib.error.HTTPError as exc:
        raise IndexNowError(f"key-location returned HTTP {exc.code}") from exc
    except urllib.error.URLError as exc:
        raise IndexNowError(f"key-location fetch failed: {exc.reason}") from exc

    if status != 200:
        raise IndexNowError(f"key-location returned HTTP {status}")
    if body.strip() != key:
        raise IndexNowError("key-location body does not match --key")


def submit(
    payload: dict,
    *,
    endpoint: str = INDEXNOW_ENDPOINT,
    timeout: float = 30.0,
) -> tuple[int, str]:
    """POST the payload to IndexNow and return (status, body)."""
    data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    request = urllib.request.Request(
        endpoint,
        data=data,
        method="POST",
        headers={"Content-Type": "application/json; charset=utf-8"},
    )

    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return _read_response(response)
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        return exc.code, body
    except urllib.error.URLError as exc:
        raise IndexNowError(f"submit failed: {exc.reason}") from exc


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Submit URLs to IndexNow (dry-run by default).")
    parser.add_argument("--host", required=True, help="site host, e.g. samskrte.ru")
    parser.add_argument(
        "--key",
        default=os.environ.get("INDEXNOW_KEY"),
        help="IndexNow key (or set INDEXNOW_KEY)",
    )
    parser.add_argument(
        "--key-location",
        default=os.environ.get("INDEXNOW_KEY_LOCATION"),
        help="public URL of the key file (or set INDEXNOW_KEY_LOCATION)",
    )
    parser.add_argument("--urls", nargs="*", default=[], help="URLs to submit")
    parser.add_argument("--urls-file", help="file with one URL per line (# comments ignored)")
    parser.add_argument(
        "--apply",
        action="store_true",
        help="actually submit; without it the command is a dry run",
    )
    parser.add_argument(
        "--verify-only",
        action="store_true",
        help="only check that the key file is published and matches",
    )
    parser.add_argument("--json", action="store_true", help="machine-readable output")
    parser.add_argument("--timeout", type=float, default=30.0)

    args = parser.parse_args(argv)

    if not args.key or not args.key_location:
        parser.error("--key and --key-location are required (or set the env vars)")

    try:
        if args.verify_only:
            verify_key(
                host=args.host,
                key=args.key,
                key_location=args.key_location,
                timeout=args.timeout,
            )
            result = {"ok": True, "verify_only": True, "host": normalize_host(args.host)}
            if args.json:
                print(json.dumps(result, ensure_ascii=False, indent=2))
            else:
                print(f"IndexNow key: OK ({args.key_location})")
            return 0

        urls = validate(
            host=args.host,
            key=args.key,
            key_location=args.key_location,
            urls=load_urls(args.urls, args.urls_file),
        )
        payload = build_payload(
            host=args.host,
            key=args.key,
            key_location=args.key_location,
            urls=urls,
        )

        if not args.apply:
            result = {
                "ok": True,
                "dry_run": True,
                "host": payload["host"],
                "urls": len(urls),
                "endpoint": INDEXNOW_ENDPOINT,
                "payload": payload,
            }
            if args.json:
                print(json.dumps(result, ensure_ascii=False, indent=2))
            else:
                print("IndexNow: DRY RUN (pass --apply to submit)")
                print(f"  Host:     {payload['host']}")
                print(f"  URLs:     {len(urls)}")
                print(f"  Endpoint: {INDEXNOW_ENDPOINT}")
                print(json.dumps(payload, ensure_ascii=False, indent=2))
            return 0

        verify_key(
            host=args.host,
            key=args.key,
            key_location=args.key_location,
            timeout=args.timeout,
        )
        status, body = submit(payload, timeout=args.timeout)
        ok = status in (200, 202)

        result = {
            "ok": ok,
            "status_code": status,
            "submitted": len(urls) if ok else 0,
            "endpoint": INDEXNOW_ENDPOINT,
            "response_body": body[:500],
        }
        if args.json:
            print(json.dumps(result, ensure_ascii=False, indent=2))
        else:
            print(f"IndexNow: {'OK' if ok else 'FAIL'} (status={status})")
            print(f"  Submitted: {result['submitted']} URLs to {INDEXNOW_ENDPOINT}")
            if body:
                print(f"  Response:  {body[:200]}")
        return 0 if ok else 1
    except IndexNowError as exc:
        if args.json:
            print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=False, indent=2))
        else:
            print(f"IndexNow: FAIL — {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
