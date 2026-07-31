#!/usr/bin/env python3
"""Download a public AnkiWeb shared deck (.apkg) via Playwright.

AnkiWeb is a SPA: the bare shared-info page does not expose a static .apkg
URL, and ``/svc/shared/download-deck/{id}`` without a short-lived
``downloadKey`` returns "Your version of Anki is too old". A real browser
session (Playwright) is required.

Usage:
    python scripts/ankiweb_download_deck.py --deck-id 454628379 \\
        --out storage/app/imports/anki_454628379

    python scripts/ankiweb_download_deck.py --deck-id 454628379 --out … --headed

Requires: ``pip install playwright`` and ``playwright install chromium``.
"""
from __future__ import annotations

import argparse
import re
import sys
import time
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")


def build_arg_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(description="Download a public AnkiWeb shared deck")
    p.add_argument("--deck-id", required=True, help="AnkiWeb shared deck id")
    p.add_argument("--out", required=True, help="Directory to save the .apkg into")
    p.add_argument(
        "--headed",
        action="store_true",
        help="Show the browser (debug)",
    )
    p.add_argument(
        "--timeout-ms",
        type=int,
        default=120_000,
        help="Navigation / download timeout (default 120000)",
    )
    return p


def main(argv: list[str] | None = None) -> int:
    args = build_arg_parser().parse_args(argv)
    deck_id = str(args.deck_id).strip()
    if not re.fullmatch(r"\d+", deck_id):
        print(f"error: deck-id must be numeric, got {deck_id!r}", file=sys.stderr)
        return 1

    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print(
            "error: playwright is not installed. Run:\n"
            "  pip install playwright\n"
            "  playwright install chromium",
            file=sys.stderr,
        )
        return 1

    out_dir = Path(args.out)
    out_dir.mkdir(parents=True, exist_ok=True)
    info_url = f"https://ankiweb.net/shared/info/{deck_id}"

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=not args.headed)
        context = browser.new_context(accept_downloads=True)
        page = context.new_page()
        page.set_default_timeout(args.timeout_ms)

        print(f"open {info_url}")
        page.goto(info_url, wait_until="domcontentloaded")
        # Wait for SPA + Download button
        # Button text is usually "Download" on the shared info page
        download_btn = page.get_by_role("button", name=re.compile(r"download", re.I))
        if download_btn.count() == 0:
            download_btn = page.locator("a, button").filter(
                has_text=re.compile(r"download", re.I)
            )
        if download_btn.count() == 0:
            browser.close()
            print(
                "error: no Download control found — deck may be private or page layout changed",
                file=sys.stderr,
            )
            return 1

        print("click Download…")
        with page.expect_download(timeout=args.timeout_ms) as dl_info:
            download_btn.first.click()
        download = dl_info.value
        suggested = download.suggested_filename or f"anki_{deck_id}.apkg"
        if not suggested.lower().endswith(".apkg"):
            suggested = f"{suggested}.apkg"
        dest = out_dir / suggested
        download.save_as(str(dest))
        browser.close()

    size = dest.stat().st_size
    print(f"saved {dest} ({size} bytes)")
    if size < 1000:
        print("WARN: file is very small — may not be a real .apkg", file=sys.stderr)
    # Quick zip magic check
    with dest.open("rb") as f:
        magic = f.read(2)
    if magic != b"PK":
        print("error: file is not a zip/.apkg (missing PK header)", file=sys.stderr)
        return 1
    print("OK: valid zip/.apkg header")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
