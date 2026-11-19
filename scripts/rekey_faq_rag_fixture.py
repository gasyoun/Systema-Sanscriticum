#!/usr/bin/env python3
"""H6917 — re-key the FAQ RAG eval fixture onto the current corpus.

The eval fixture (`tests/fixtures/faq_rag_eval.json`) cites chunk_ids, and a
chunk_id is the heading-path slug. ORS-FAQ re-exports `faq.md` from the wiki and
renames headings (17-10-2026: all 81 `###` headings), so the cited ids go dead
while the questions and their answers are still valid. Rewriting them by hand
is how a "re-key" turns into a guess.

This tool derives the mapping from the corpus itself, in three tiers, and REFUSES
to guess:

  1. body-exact — the new section's body is byte-identical to the old one's
     (a pure heading rename);
  2. title — the old body carries the wiki note's `title: "…"` and the new
     section's title matches it (the export switched from slug-keys to human
     titles);
  3. fuzzy title — same H2 parent and a high title similarity (printed for the
     human to see, never silently applied).

Every mapped id is then verified to exist in the parsed corpus, and the fixture
is only written when every cited id resolves. `scripts/check_faq_corpus_drift.py
--check` is the gate that proves the result.

Usage:
    python scripts/rekey_faq_rag_fixture.py --from-corpus /tmp/faq_old.md --dry-run
    python scripts/rekey_faq_rag_fixture.py --from-corpus /tmp/faq_old.md
    # or read the pre-sync corpus straight out of git:
    python scripts/rekey_faq_rag_fixture.py --from-commit 9aea235d^
"""

from __future__ import annotations

import argparse
import difflib
import json
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from check_faq_corpus_drift import (  # noqa: E402
    DEFAULT_CORPUS,
    DEFAULT_FIXTURE,
    load_corpus,
    parse_sections,
)

TITLE_RE = re.compile(r'title:\s*"(?P<title>[^"]+)"')


def norm(text: str) -> str:
    return re.sub(r"\s+", " ", text.casefold()).strip()


def old_title(body: str) -> str | None:
    match = TITLE_RE.search(body)
    return match.group("title") if match else None


def build_mapping(old_sections: list[dict], new_sections: list[dict],
                  used: set[str]) -> tuple[dict[str, str], list[str]]:
    mapping: dict[str, str] = {}
    problems: list[str] = []
    by_body: dict[str, list[dict]] = {}
    for section in new_sections:
        by_body.setdefault(section["body"], []).append(section)

    for old in old_sections:
        candidates = [c for c in by_body.get(old["body"], []) if c["id"] not in used]
        if len(candidates) == 1:
            mapping[old["id"]] = candidates[0]["id"]
            used.add(candidates[0]["id"])
            continue
        if len(candidates) > 1:
            problems.append(f"body-exact is ambiguous for {old['id']}: "
                            + ", ".join(c["id"] for c in candidates))
            continue

        want = old_title(old["body"])
        if want:
            titled = [s for s in new_sections
                      if s["id"] not in used and norm(s["title"]) == norm(want)]
            if len(titled) == 1:
                mapping[old["id"]] = titled[0]["id"]
                used.add(titled[0]["id"])
                continue

        # Last resort: same H2 parent, fuzzy title. Reported, never silent.
        parent = old["heading_path"][0] if old["heading_path"] else ""
        pool = [s for s in new_sections
                if s["id"] not in used and s["heading_path"]
                and s["heading_path"][0] == parent]
        scored = sorted(
            ((difflib.SequenceMatcher(None, norm(want or old["title"]),
                                      norm(s["title"])).ratio(), s) for s in pool),
            key=lambda pair: pair[0], reverse=True,
        )
        best = scored[0] if scored else (0.0, None)
        if best[0] >= 0.8 and best[1] is not None:
            mapping[old["id"]] = best[1]["id"]
            used.add(best[1]["id"])
            print(f"  fuzzy({best[0]:.2f}): {old['id']} -> {best[1]['id']}")
            continue

        problems.append(f"no mapping for {old['id']} (title={old['title']!r})")
    return mapping, problems


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="H6917: re-key the FAQ RAG eval fixture onto the current corpus.")
    parser.add_argument("--corpus", type=Path, default=DEFAULT_CORPUS)
    parser.add_argument("--fixture", type=Path, default=DEFAULT_FIXTURE)
    parser.add_argument("--from-corpus", type=Path, default=None,
                        help="pre-sync corpus markdown (its ids are the ones to map FROM)")
    parser.add_argument("--from-commit", default=None,
                        help="git rev to read the pre-sync corpus from "
                             "(e.g. 9aea235d^); ignored when --from-corpus is given")
    parser.add_argument("--dry-run", action="store_true",
                        help="print the mapping + planned fixture, write nothing")
    args = parser.parse_args(argv)

    if args.from_corpus is None and not args.from_commit:
        print("ERROR: give --from-corpus FILE or --from-commit REV", file=sys.stderr)
        return 2

    if args.from_corpus is not None:
        old_text = args.from_corpus.read_text(encoding="utf-8")
    else:
        try:
            old_text = subprocess.run(
                ["git", "show", f"{args.from_commit}:resources/knowledge/faq.md"],
                cwd=Path(__file__).resolve().parent.parent,
                capture_output=True, text=True, check=True,
            ).stdout
        except subprocess.CalledProcessError as exc:
            print(f"ERROR: git show failed: {exc.stderr.strip()}", file=sys.stderr)
            return 2

    old_sections = parse_sections(old_text)
    new_sections = load_corpus(args.corpus)
    if not old_sections or not new_sections:
        print("ERROR: one of the corpora parsed to zero chunks", file=sys.stderr)
        return 2

    new_ids = {section["id"] for section in new_sections}
    data = json.loads(args.fixture.read_text(encoding="utf-8"))
    items = data.get("items") or []
    cited = sorted({key for item in items
                    for key in (item.get("expected_chunk_ids") or [])})
    dead = [key for key in cited if key not in new_ids]
    if not dead:
        print(f"OK: no dead citation keys — all {len(cited)} cited ids exist "
              f"in {args.corpus}")
        return 0

    old_by_id = {section["id"]: section for section in old_sections}
    known_dead = [key for key in dead if key in old_by_id]
    unknown = [key for key in dead if key not in old_by_id]
    for key in unknown:
        print(f"WARNING: {key} is cited, absent from the new corpus, and unknown "
              f"to the old one — cannot derive a mapping", file=sys.stderr)

    mapping, problems = build_mapping(
        [old_by_id[key] for key in known_dead], new_sections, set()
    )

    print(f"citation keys: {len(cited)} cited, {len(dead)} dead")
    for key in known_dead:
        target = mapping.get(key)
        if target is None:
            print(f"  !! {key} -> UNMAPPED")
        else:
            print(f"  {key}\n     -> {target}")

    if problems or unknown:
        for problem in problems:
            print(f"ERROR: {problem}", file=sys.stderr)
        print("REFUSED: not every dead key has a derived mapping; fix the corpus "
              "or map by hand and re-run.", file=sys.stderr)
        return 1

    missing_targets = [t for t in mapping.values() if t not in new_ids]
    if missing_targets:
        print(f"ERROR: mapped targets missing from the corpus: {missing_targets}",
              file=sys.stderr)
        return 1

    changed = 0
    for item in items:
        ids = item.get("expected_chunk_ids") or []
        remapped = [mapping.get(key, key) for key in ids]
        if remapped != ids:
            changed += 1
            item["expected_chunk_ids"] = remapped

    data["rekeyed"] = {
        "date": "2026-10-17",
        "cause": "faq.md sync 9aea235d (ORS-FAQ re-export) renamed all 81 ### headings",
        "keys_remapped": {key: mapping[key] for key in known_dead},
        "tool": "scripts/rekey_faq_rag_fixture.py",
    }
    data["version"] = int(data.get("version", 2)) + 1

    print(f"\nitems re-keyed: {changed}/{len(items)}; fixture version -> {data['version']}")
    if args.dry_run:
        print("dry-run: fixture not written")
        return 0

    args.fixture.write_text(
        json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"written: {args.fixture}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
