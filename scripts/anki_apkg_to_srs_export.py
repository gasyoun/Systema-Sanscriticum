#!/usr/bin/env python3
"""Convert an Anki .apkg (shared deck export) into the Systema SRS import contract:

    out/
      manifest.json
      level_01.csv   (or multiple levels if deck has decks/tags split — default: one)
      media/         (audio + images renamed from Anki's numeric media map)

Output is the same family as Memrise's manifest.json + level CSV so
`php artisan srs:import-anki` (and, for text-only columns, srs:import-memrise)
can load it. Field split handles the common Anki "Basic" Front/Back layout
where Front is ``Devanagari<br><br>romanization[sound:x.mp3]`` and Back is
``<img …><br><br>English``.

Usage:
    python scripts/anki_apkg_to_srs_export.py \\
        --apkg path/to/deck.apkg \\
        --out database/seeders/data/anki_454628379 \\
        --course-id 454628379 \\
        --language hi \\
        --source-url https://ankiweb.net/shared/info/454628379

    python scripts/anki_apkg_to_srs_export.py --apkg … --out … --dry-run

stdlib-only (zipfile + sqlite3). No AnkiDesktop required.
"""
from __future__ import annotations

import argparse
import csv
import html
import json
import re
import shutil
import sqlite3
import sys
import tempfile
import zipfile
from datetime import date
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

TAG_RE = re.compile(r"<[^>]+>", re.I)
SOUND_RE = re.compile(r"\[sound:([^\]]+)\]", re.I)
IMG_RE = re.search  # alias for clarity at call sites
IMG_SRC_RE = re.compile(r"""<img[^>]+src=["']([^"']+)["']""", re.I)
BR_SPLIT_RE = re.compile(r"<br\s*/?>", re.I)
# Devanagari block + common Vedic extensions used in Hindi/Sanskrit cards
DEVANAGARI_RE = re.compile(r"[\u0900-\u097F\uA8E0-\uA8FF]+")
LATIN_TOKEN_RE = re.compile(r"[A-Za-z][A-Za-z0-9'.\-]*")

CSV_FIELDS = [
    "devanagari",
    "iast",
    "translation",
    "audio",
    "image",
    "note_id",
    "tags",
]


def build_arg_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        description="Export an Anki .apkg into manifest.json + level CSV for "
        "`php artisan srs:import-anki`.",
    )
    p.add_argument("--apkg", required=True, help="Path to the .apkg file")
    p.add_argument("--out", required=True, help="Destination directory")
    p.add_argument(
        "--course-id",
        default=None,
        help="Stable deck id for slugs (default: apkg stem sanitised)",
    )
    p.add_argument("--course-name", default=None, help="Display name (default: apkg stem)")
    p.add_argument(
        "--language",
        default="hi",
        choices=("hi", "sa", "en", "ru"),
        help="SrsNoteType language enum value (default: hi)",
    )
    p.add_argument(
        "--source-url",
        default=None,
        help="Provenance URL, e.g. https://ankiweb.net/shared/info/454628379",
    )
    p.add_argument(
        "--dry-run",
        action="store_true",
        help="Parse and print sample rows; write nothing",
    )
    return p


def strip_html(s: str) -> str:
    s = html.unescape(s or "")
    s = SOUND_RE.sub("", s)
    s = TAG_RE.sub(" ", s)
    s = re.sub(r"\s+", " ", s).strip()
    return s


def extract_sound(blob: str) -> str:
    m = SOUND_RE.search(blob or "")
    return m.group(1) if m else ""


def extract_image(blob: str) -> str:
    m = IMG_SRC_RE.search(blob or "")
    return m.group(1) if m else ""


def split_front(front_raw: str) -> tuple[str, str]:
    """Split Anki Front into (devanagari, romanization).

    Preferred: pieces of ``…<br><br>…`` / ``…<br>…``.
    Fallback: peel leading Devanagari run from a glued string.
    """
    # Remove sound tokens before splitting so romanization isn't glued to [sound:]
    front = SOUND_RE.sub("", front_raw or "")
    parts = [p.strip() for p in BR_SPLIT_RE.split(front) if p and p.strip()]
    # Drop pure-empty / pure-tag remnants
    plain_parts = [strip_html(p) for p in parts]
    plain_parts = [p for p in plain_parts if p]

    devanagari = ""
    roman = ""

    if len(plain_parts) >= 2:
        # First part with Devanagari → headword; first Latin-ish → romanization
        for p in plain_parts:
            if not devanagari and DEVANAGARI_RE.search(p):
                # Keep only Devanagari (+ spaces) from this part
                devanagari = "".join(DEVANAGARI_RE.findall(p)) or p
                continue
            if not roman and LATIN_TOKEN_RE.search(p) and not DEVANAGARI_RE.search(p):
                roman = p
                continue
            if not roman and LATIN_TOKEN_RE.search(p):
                # mixed leftover — take Latin tokens
                roman = " ".join(LATIN_TOKEN_RE.findall(p))
        if not devanagari:
            devanagari = plain_parts[0]
        if not roman and len(plain_parts) > 1:
            roman = plain_parts[1]
    elif len(plain_parts) == 1:
        only = plain_parts[0]
        dev_chunks = DEVANAGARI_RE.findall(only)
        if dev_chunks:
            devanagari = "".join(dev_chunks)
            rest = DEVANAGARI_RE.sub("", only).strip()
            rest = re.sub(r"\s+", " ", rest).strip(" -–—")
            # Drop common Anki clutter like "(View this word list online)"
            rest = re.sub(r"\(View this word list online\)", "", rest, flags=re.I).strip()
            roman = rest
        else:
            # No Devanagari — treat whole as romanization or English source
            roman = only
    return devanagari, roman


def split_back(back_raw: str) -> str:
    """English (or target-language) gloss from Back, ignoring images."""
    back = IMG_SRC_RE.sub("", back_raw or "")
    parts = [strip_html(p) for p in BR_SPLIT_RE.split(back)]
    parts = [p for p in parts if p]
    if not parts:
        return strip_html(back_raw)
    # Prefer the last non-empty plain part (image alt junk often first)
    return parts[-1]


def open_collection(extract_dir: Path) -> sqlite3.Connection:
    for name in ("collection.anki21", "collection.anki2"):
        db = extract_dir / name
        if db.is_file():
            conn = sqlite3.connect(str(db))
            conn.row_factory = sqlite3.Row
            return conn
    raise FileNotFoundError(
        f"No collection.anki2 / collection.anki21 in {extract_dir}"
    )


def load_media_map(extract_dir: Path) -> dict[str, str]:
    """Anki media map: numeric filename -> original name."""
    media_path = extract_dir / "media"
    if not media_path.is_file():
        return {}
    raw = json.loads(media_path.read_text(encoding="utf-8"))
    # keys are strings "0","1",… values are original basenames
    return {str(k): str(v) for k, v in raw.items()}


def field_map(model: dict | None, fields: list[str]) -> dict[str, str]:
    if not model:
        return {f"f{i}": fields[i] if i < len(fields) else "" for i in range(len(fields))}
    names = [f["name"] for f in model.get("flds", [])]
    out: dict[str, str] = {}
    for i, name in enumerate(names):
        out[name] = fields[i] if i < len(fields) else ""
    return out


def pick_front_back(by_name: dict[str, str], fields: list[str]) -> tuple[str, str]:
    front_keys = ("Front", "Hindi", "Word", "Expression", "Text", "Question")
    back_keys = ("Back", "English", "Meaning", "Translation", "Answer", "Definition")
    front = ""
    back = ""
    for k in front_keys:
        if by_name.get(k):
            front = by_name[k]
            break
    for k in back_keys:
        if by_name.get(k):
            back = by_name[k]
            break
    if not front and fields:
        front = fields[0]
    if not back and len(fields) > 1:
        back = fields[1]
    return front, back


def sanitise_id(s: str) -> str:
    s = re.sub(r"[^A-Za-z0-9_-]+", "-", s).strip("-").lower()
    return s or "anki-deck"


def export_apkg(
    apkg: Path,
    out: Path,
    course_id: str | None,
    course_name: str | None,
    language: str,
    source_url: str | None,
    dry_run: bool,
) -> dict:
    if not apkg.is_file():
        raise FileNotFoundError(f"apkg not found: {apkg}")

    cid = course_id or sanitise_id(apkg.stem)
    cname = course_name or apkg.stem.replace("_", " ").replace("-", " ")

    tmp = Path(tempfile.mkdtemp(prefix="anki_apkg_"))
    try:
        with zipfile.ZipFile(apkg) as zf:
            zf.extractall(tmp)

        conn = open_collection(tmp)
        media_map = load_media_map(tmp)  # num -> basename
        num_by_name = {v: k for k, v in media_map.items()}

        col = conn.execute("SELECT models FROM col").fetchone()
        models = json.loads(col["models"])

        rows: list[dict[str, str]] = []
        for n in conn.execute("SELECT id, mid, flds, tags FROM notes ORDER BY id"):
            fields = (n["flds"] or "").split("\x1f")
            mid = str(n["mid"])
            model = models.get(mid) or models.get(n["mid"])
            by_name = field_map(model, fields)
            front, back = pick_front_back(by_name, fields)
            blob = "\x1f".join(fields)

            devanagari, roman = split_front(front)
            translation = split_back(back)
            # Drop Anki UI clutter left in romanization
            roman = re.sub(
                r"\(View this word list online\)", "", roman, flags=re.I
            ).strip()

            audio_name = extract_sound(blob)
            image_name = extract_image(blob)

            rows.append(
                {
                    "devanagari": devanagari,
                    "iast": roman,  # Latin transliteration slot (not true IAST)
                    "translation": translation,
                    "audio": audio_name,  # basename; relativised after copy
                    "image": image_name,
                    "note_id": str(n["id"]),
                    "tags": (n["tags"] or "").strip(),
                    "_audio_name": audio_name,
                    "_image_name": image_name,
                }
            )

        n_notes = conn.execute("SELECT count(*) FROM notes").fetchone()[0]
        n_cards = conn.execute("SELECT count(*) FROM cards").fetchone()[0]
        conn.close()

        sample = [
            {k: r[k] for k in CSV_FIELDS if k in r}
            for r in rows[:3]
        ]
        print(f"notes={n_notes} cards={n_cards} rows={len(rows)}")
        for s in sample:
            print(" sample", s)

        if dry_run:
            return {"notes": n_notes, "rows": len(rows), "dry_run": True}

        if out.exists():
            shutil.rmtree(out)
        out.mkdir(parents=True)
        media_out = out / "media"
        media_out.mkdir()

        audio_count = 0
        image_count = 0
        for r in rows:
            for kind, key in (("audio", "_audio_name"), ("image", "_image_name")):
                name = r.get(key) or ""
                if not name or name not in num_by_name:
                    r[kind] = ""
                    continue
                src = tmp / str(num_by_name[name])
                if not src.is_file():
                    r[kind] = ""
                    continue
                dest = media_out / name
                if not dest.exists():
                    shutil.copy2(src, dest)
                r[kind] = f"media/{name}"
                if kind == "audio":
                    audio_count += 1
                else:
                    image_count += 1
            for k in ("_audio_name", "_image_name"):
                r.pop(k, None)

        csv_path = out / "level_01.csv"
        with csv_path.open("w", encoding="utf-8", newline="") as f:
            w = csv.DictWriter(f, fieldnames=CSV_FIELDS, extrasaction="ignore")
            w.writeheader()
            w.writerows(rows)

        manifest = {
            "course_id": cid,
            "course_name": cname,
            "source_url": source_url,
            "language": language,
            "exported_at": date.today().isoformat(),
            "source_apkg": apkg.name,
            "source_format": "anki_apkg",
            "columns": {f: f for f in CSV_FIELDS},
            "levels": [
                {
                    "index": 1,
                    "name": cname,
                    "file": "level_01.csv",
                }
            ],
            "stats": {
                "notes": n_notes,
                "cards": n_cards,
                "rows_exported": len(rows),
                "audio_files": audio_count,
                "image_files": image_count,
            },
        }
        (out / "manifest.json").write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        print(f"wrote {csv_path} ({len(rows)} rows)")
        print(f"media audio={audio_count} image={image_count} dir={media_out}")
        print(f"manifest {out / 'manifest.json'}")
        return manifest
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


def main(argv: list[str] | None = None) -> int:
    args = build_arg_parser().parse_args(argv)
    try:
        export_apkg(
            apkg=Path(args.apkg),
            out=Path(args.out),
            course_id=args.course_id,
            course_name=args.course_name,
            language=args.language,
            source_url=args.source_url,
            dry_run=args.dry_run,
        )
    except Exception as e:
        print(f"error: {e}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
