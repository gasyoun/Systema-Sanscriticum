#!/usr/bin/env python3
"""PII masking + freeze pipeline for historical ORS dialog corpora (H3527).

Input : directory of ``dialog_<id>.txt`` files (local copy, gitignored, NEVER
        committed) in the ``[YYYY-MM-DD] УЧЕНИК|КУРАТОР: text`` line format used
        by ``ors_faq/custdev_pilot.py``. Multiline messages are folded back
        into their opening line exactly like the custdev parser.
Output: JSONL rows ``{dialog_id, msg_id, direction, date, text_masked}`` —
        the ONLY shape ever allowed inside ``corpora/``.

PII policy (ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md): phones, emails,
@usernames, URLs, digit runs >= 7 and names from a supplied list are replaced
by placeholders before anything is written. Masked-only-ever-committed; any
suspected leak is a STOP condition per the autonomy contract.

Subcommands
-----------
  mask      DIR -> masked JSONL          (write a snapshot)
  validate  masked.jsonl... -> 0 hits   (zero-hit gate, exit 1 otherwise)
  sample    masked.jsonl -> checklist.md (deterministic 50-message manual
            spot-check sheet; MUST be filled and signed BEFORE the snapshot is
            committed/frozen — procedure enforced by review, see README)
  census    DIR -> counts                (dialog/message totals for wc -l)

Names list: pass ``--names FILE`` (one full name or single token per line,
UTF-8, ``#`` comments). It is gitignored by default
(``tools/pii_names*.txt`` except this file) because it is itself personal
data; supply the real list out-of-band before freezing.

Stdlib only. No network. Raw txt never leaves the local disk.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import random
import re
import sys
from datetime import date
from pathlib import Path

DIRECTION = {"УЧЕНИК": "student", "КУРАТОР": "curator"}
LINE_RE = re.compile(r"^\[(\d{4}-\d{2}-\d{2})\] (УЧЕНИК|КУРАТОР): ?(.*)$")
DIALOG_GLOB = "dialog_*.txt"

REQUIRED_KEYS = ("dialog_id", "msg_id", "direction", "date", "text_masked")

SPOTCHECK_SEED = 3527
SPOTCHECK_DEFAULT_N = 50


def build_patterns(names: list[str] | None = None):
    """Ordered (pii_class, compiled_regex, placeholder) list.

    Order matters: URLs first (they can swallow handles), then emails (they
    contain '@'), then phones, bare handles, names, and finally a catch-net
    for long digit runs (card/order numbers, phones written without spaces).
    """
    patterns = [
        ("url", re.compile(r"(?:https?://|www\.)[^\s<>\"']+",
                           re.IGNORECASE), "[URL]"),
        ("email", re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\."
                             r"[A-Za-z]{2,}"), "[EMAIL]"),
        ("phone", re.compile(
            r"(?:\+7|8)[\s\-()]*\d{3}[\s\-()]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}"
            r"|\b\d{3}[\s\-]\d{3}[\s\-]\d{4}\b"
            r"|\+\d[\d\s\-()]{8,}\d"), "[PHONE]"),
        ("handle", re.compile(
            r"(?<![\w@])@[A-Za-z][A-Za-z0-9_]{3,31}\b"), "[TG_HANDLE]"),
        ("digits", re.compile(r"\d{7,}"), "[NUMBER]"),
    ]
    for name in names or []:
        escaped = re.escape(name)
        patterns.append((
            "name",
            re.compile(
                r"(?<![\w-])" + escaped + r"(?![\w-])",
                re.IGNORECASE),
            "[NAME]",
        ))
    return patterns


def mask_text(text: str, patterns) -> tuple[str, list[str]]:
    """Return (masked_text, classes_hit)."""
    hit: list[str] = []
    for cls, rx, repl in patterns:
        def _sub(m, _cls=cls):
            if _cls not in hit:
                hit.append(_cls)
            return repl
        text = rx.sub(_sub, text)
    return text, hit


def parse_dialog(path: Path):
    """Yield {dialog_id, msg_id, direction, date, text_masked-source} rows."""
    dialog_id = path.stem.replace("dialog_", "", 1)
    msgs: list[tuple[str, str, str]] = []
    for raw in path.read_text(encoding="utf-8").splitlines():
        m = LINE_RE.match(raw)
        if m:
            msgs.append((m.group(1), m.group(2), m.group(3)))
        elif msgs and raw.strip():
            d, r, t = msgs[-1]
            msgs[-1] = (d, r, t + "\n" + raw)
    for i, (d, role, text) in enumerate(msgs, start=1):
        yield {
            "dialog_id": dialog_id,
            "msg_id": i,
            "direction": DIRECTION[role],
            "date": d,
            "text": text,
        }


def load_names(path: Path | None) -> list[str]:
    if path is None:
        return []
    names = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            names.append(line)
    return names


def iter_source_files(src: Path) -> list[Path]:
    files = sorted(src.glob(DIALOG_GLOB))
    if not files:
        raise SystemExit(f"ERROR: no {DIALOG_GLOB} under {src}")
    return files


def cmd_mask(args) -> int:
    src, out = Path(args.src), Path(args.out)
    patterns = build_patterns(load_names(Path(args.names) if args.names else None))
    rows_written = 0
    dialogs = 0
    hits_by_class: dict[str, int] = {}
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as fh:
        for path in iter_source_files(src):
            dialogs += 1
            for row in parse_dialog(path):
                masked, hit = mask_text(row["text"], patterns)
                for c in hit:
                    hits_by_class[c] = hits_by_class.get(c, 0) + 1
                fh.write(json.dumps({
                    "dialog_id": row["dialog_id"],
                    "msg_id": row["msg_id"],
                    "direction": row["direction"],
                    "date": row["date"],
                    "text_masked": masked,
                }, ensure_ascii=False) + "\n")
                rows_written += 1
    print(f"dialogs: {dialogs}")
    print(f"messages written: {rows_written} -> {out}")
    for cls in ("url", "email", "phone", "handle", "digits", "name"):
        if hits_by_class.get(cls):
            print(f"masked {cls}: {hits_by_class[cls]}")
    print("NEXT (mandatory, in order): "
          "1) python tools/mask_corpus.py validate --in <out>  "
          "2) python tools/mask_corpus.py sample --in <out>  "
          "3) human signs the 50-message checklist  "
          "4) only then commit/freeze.")
    return 0


VALIDATE_EXTRA_NET = [
    ("leftover_at_handle", re.compile(r"(?<![\w@])@[A-Za-z][A-Za-z0-9_]{3,31}\b")),
    ("leftover_url", re.compile(r"https?://|www\.", re.IGNORECASE)),
    ("leftover_digits", re.compile(r"\d{7,}")),
]


def cmd_validate(args) -> int:
    """Zero-hit gate over one or more masked JSONL files. Exit 1 = STOP."""
    patterns = build_patterns(load_names(Path(args.names) if args.names else None))
    total_rows = 0
    failures: list[str] = []
    for arg in args.files:
        p = Path(arg)
        file_rows = 0
        for lineno, line in enumerate(p.read_text(encoding="utf-8").splitlines(), 1):
            if not line.strip():
                continue
            try:
                row = json.loads(line)
            except json.JSONDecodeError as exc:
                failures.append(f"{p}:{lineno}: bad JSON ({exc})")
                continue
            file_rows += 1
            missing = [k for k in REQUIRED_KEYS if k not in row]
            if missing:
                failures.append(f"{p}:{lineno}: missing keys {missing}")
                continue
            if row["direction"] not in DIRECTION.values():
                failures.append(f"{p}:{lineno}: bad direction {row['direction']!r}")
            if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", str(row["date"])):
                failures.append(f"{p}:{lineno}: bad date {row['date']!r}")
            text = row["text_masked"]
            if not isinstance(text, str) or not text.strip():
                failures.append(f"{p}:{lineno}: empty text_masked")
                continue
            for cls, rx, _repl in patterns:
                if rx.search(text):
                    failures.append(f"{p}:{lineno}: {cls} survived masking")
            for cls, rx in VALIDATE_EXTRA_NET:
                if rx.search(text):
                    failures.append(f"{p}:{lineno}: {cls} catch-net hit")
        print(f"{p}: {file_rows} rows checked")
        total_rows += file_rows
    if failures:
        print(f"VALIDATION FAILED: {len(failures)} finding(s); "
              f"{total_rows} rows scanned. STOP — do not commit.")
        for f in failures[:20]:
            print(f"  - {f}")
        return 1
    print(f"VALIDATOR: 0 PII hits across {total_rows} rows. PASS.")
    return 0


def cmd_sample(args) -> int:
    src = Path(args.files[0])
    lines = [ln for ln in src.read_text(encoding="utf-8").splitlines()
             if ln.strip()]
    rows = [(i, json.loads(ln)) for i, ln in enumerate(lines)]
    n = min(args.n, len(rows))
    rng = random.Random(SPOTCHECK_SEED)
    picked = sorted(rng.sample(rows, n), key=lambda t: t[0])
    digest = hashlib.sha256(src.read_bytes()).hexdigest()[:12]
    out_lines = [
        f"# Spot-check checklist — {src.name}",
        "",
        f"_Created: {date.today().isoformat()} · "
        f"sample: {n} of {len(rows)} messages · seed: {SPOTCHECK_SEED} · "
        f"sha256[:12]: {digest}_",
        "",
        "⚠️ **STOP condition**: ANY suspected PII leak in the quotes below = "
        "halt the freeze, do not commit, report to the owner.",
        "",
    ]
    for idx, row in picked:
        quote = row["text_masked"].replace("\n", " ⏎ ")
        if len(quote) > 300:
            quote = quote[:300] + "…"
        out_lines.append(
            f"- [ ] #{idx + 1} (dialog {row['dialog_id']}, "
            f"{row['direction']}, {row['date']}): «{quote}»")
    out_lines += [
        "",
        "## Sign-off",
        "",
        "- Checked by (initials): ________",
        f"- Date: ________",
        "- Verdict (CLEAN / STOP): ________",
        "",
    ]
    out = Path(args.out)
    out.write_text("\n".join(out_lines), encoding="utf-8")
    print(f"checklist: {n} messages -> {out} "
          f"(human sign-off REQUIRED before commit)")
    return 0


def cmd_census(args) -> int:
    src = Path(args.src)
    files = iter_source_files(src)
    total_msgs = 0
    empty = 0
    for path in files:
        n = sum(1 for _ in parse_dialog(path))
        total_msgs += n
        if n == 0:
            empty += 1
    print(f"dir: {src}")
    print(f"dialog files: {len(files)} (empty/unparseable: {empty})")
    print(f"messages total: {total_msgs}")
    print(f"wc -l equivalence: masked JSONL should be {total_msgs} lines")
    return 0


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    sub = ap.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("mask", help="dialog_*.txt dir -> masked JSONL")
    p.add_argument("--src", required=True, help="directory with dialog_*.txt")
    p.add_argument("--out", required=True, help="output masked .jsonl")
    p.add_argument("--names", help="optional names list file (see module doc)")
    p.set_defaults(func=cmd_mask)

    p = sub.add_parser("validate", help="zero-hit PII gate over masked JSONL")
    p.add_argument("--in", dest="files", nargs="+", required=True)
    p.add_argument("--names", help="optional names list to double-check")
    p.set_defaults(func=cmd_validate)

    p = sub.add_parser("sample", help="emit 50-message manual checklist")
    p.add_argument("--in", dest="files", nargs=1, required=True)
    p.add_argument("--out", required=True, help="output checklist .md")
    p.add_argument("--n", type=int, default=SPOTCHECK_DEFAULT_N)
    p.set_defaults(func=cmd_sample)

    p = sub.add_parser("census", help="counts for wc -l comparison")
    p.add_argument("--src", required=True)
    p.set_defaults(func=cmd_census)

    args = ap.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
