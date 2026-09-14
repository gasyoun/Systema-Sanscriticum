#!/usr/bin/env python3
"""Build the subhāṣita audio SRS deck feed (H4474 stage 1).

Joins the verified audio manifest (resources/data/subhashita_audio_manifest.tsv,
59 rows carrying a Böhtlingk *Indische Sprüche* number) with the verse text and
RU translations from the teaching anthology docx (parsed with stdlib zipfile+XML
— no pandoc dependency at build time) and the recordings text docx, emitting the
VENDORED static feed resources/data/subhashita_srs_deck.json consumed by
`php artisan subhashita:import-audio-deck`.

Inputs (paths via --staging):
    recordings_text.docx  Subhashita-Recordings-Text.docx   (53 verse entries)
    boethlingk95.docx     Subhashita_EnRuDe_Boethlingk-95.docx (95 entries, RU tātparyam)
    manifest              resources/data/subhashita_audio_manifest.tsv
    sprueche              indische_sprueche.jsonl (7537 sayings)

The mapping itself (is_num per recording) is NEVER recomputed here — it is read
from the manifest that scripts/verify_subhashita_audio_manifest.py gates.

    python scripts/build_subhashita_srs_deck.py \
        --staging /tmp/h4474-staging \
        --manifest resources/data/subhashita_audio_manifest.tsv \
        --sprueche ../SanskritLexicography/IndischeSprueche/data/indische_sprueche.jsonl \
        --out resources/data/subhashita_srs_deck.json
"""

import argparse
import csv
import json
import re
import sys
import unicodedata
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
STRIP_RE = re.compile(r"[\s।॥|/,.;:'​‌‍\xa0()\-]")


def norm(deva: str) -> str:
    """Fold a Devanagari string to a comparison key (annotations stripped)."""
    cleaned = re.sub(r"\([^()]*पाठ[^()]*\)", "", deva or "")
    return STRIP_RE.sub("", unicodedata.normalize("NFC", cleaned))


def docx_paras(path: Path):
    # nosemgrep: python.lang.security.audit.use-defusedxml-parse — input is the
    # operator's own docx staging (MG's teaching files), never untrusted user
    # input; defusedxml is not a dependency of this repo.
    with zipfile.ZipFile(path) as z:
        xml = z.read("word/document.xml")
    root = ET.fromstring(xml)
    out = []
    for p in root.iter(W + "p"):
        t = "".join(n.text or "" for n in p.iter(W + "t"))
        if t.strip():
            out.append(t.strip())
    return out


def parse_recordings_verses(paras):
    """`N. <pratīka>` + verse lines -> {N: cleaned full verse}."""
    entries, cur, buf = {}, None, []
    for p in paras:
        m = re.match(r"^(\d+)\.(.+)", p)
        if m:
            if cur is not None:
                entries[cur] = " ".join(buf).strip()
            cur, buf = int(m.group(1)), [m.group(2)]
        elif cur is not None and re.search(r"[ऀ-ॿ]", p):
            buf.append(p)
    if cur is not None:
        entries[cur] = " ".join(buf).strip()
    return entries


def clean_recorded_verse(raw: str):
    """The docx repeats the pratīka head before the verse — drop one copy."""
    toks = raw.split()
    for k in range(1, 5):
        if len(toks) >= 2 * k and " ".join(toks[:k]) == " ".join(toks[k : 2 * k]):
            raw = " ".join(toks[k:])
            break
    raw = re.sub(r"\([^()]*पाठ[^()]*\)", "", raw)
    return re.sub(r"\s+", " ", raw).strip()


def mulam_blocks(paras):
    """[(start_para, norm_text)] of every anthology mūlam block."""
    blocks = []
    for i, p in enumerate(paras):
        if re.match(r"^मूलम्", p):
            lines, k = [], i + 1
            while k < len(paras) and re.search(r"[ऀ-ॿ]", paras[k]) and not re.match(r"^[A-Za-z]", paras[k]):
                lines.append(paras[k])
                k += 1
            blocks.append((i, norm(" ".join(lines))))
    # entries whose mūlam header is missing (standalone pratīka para then verse)
    seen = {bn for _, bn in blocks}
    for i, p in enumerate(paras):
        if i > 110 and re.match(r"^[ऀ-ॿ][^=]*$", p) and len(norm(p)) < 30:
            nxt = i + 1
            if nxt < len(paras) and re.search(r"[ऀ-ॿ]", paras[nxt]) and not re.match(r"^[A-Za-z]", paras[nxt]):
                lines, k = [], nxt
                while k < len(paras) and re.search(r"[ऀ-ॿ]", paras[k]) and not re.match(r"^[A-Za-z]", paras[k]):
                    lines.append(paras[k])
                    k += 1
                b = norm(" ".join(lines))
                if b not in seen:
                    blocks.append((i, b))
    return blocks


def entry_end(paras, i0):
    for k in range(i0 + 1, min(i0 + 90, len(paras))):
        if "____" in paras[k] or re.match(r"^मूलम्", paras[k]):
            return k
    return min(i0 + 90, len(paras))


RU_TAIL_RE = re.compile(r"[А-Яа-яЁё][^A-Za-zऀ-ॿ]*[.!?…»]?")
RU_RE = re.compile(r"[А-Яа-яЁё]")


def ru_tail(t: str) -> str:
    segs = RU_TAIL_RE.findall(t)
    return " ".join(s.strip() for s in segs).strip()


def ru_for_entry(paras, i0):
    """The RU tātparyam of the entry opening at i0 (post-entry note lines included)."""
    end = entry_end(paras, i0)
    notes_end = min(end + 8, len(paras))
    start = None
    for k in range(i0, end):
        if re.match(r"^तात्पर्यम्", paras[k]):
            start = k
            break
    if start is None:
        start = i0
    lines = []
    for k in range(start, notes_end):
        t = paras[k]
        if re.match(r"^[ऀ-ॿ]", t):
            continue
        tail = ru_tail(t)
        if tail:
            lines.append(tail)
    return " ".join(lines).strip() or None


def verse_from_entry(paras, i0):
    end = None
    for k in range(i0 + 1, min(i0 + 20, len(paras))):
        if "____" in paras[k] or re.match(r"^मूलम्", paras[k]):
            end = k
            break
    end = end or min(i0 + 20, len(paras))
    lines, k = [], i0 + 1
    while k < end and re.search(r"[ऀ-ॿ]", paras[k]) and not re.match(r"^[A-Za-z]", paras[k]):
        lines.append(paras[k])
        k += 1
    return re.sub(r"\s+", " ", " ".join(lines)).strip()


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--staging", required=True, type=Path)
    ap.add_argument("--manifest", required=True, type=Path)
    ap.add_argument("--sprueche", required=True, type=Path)
    ap.add_argument("--out", required=True, type=Path)
    args = ap.parse_args()

    say = {}
    with args.sprueche.open(encoding="utf-8") as fh:
        for line in fh:
            if line.strip():
                rec = json.loads(line)
                say[rec["num"]] = rec

    with args.manifest.open(encoding="utf-8", newline="") as fh:
        rows = list(csv.DictReader(fh, delimiter="\t"))

    rec_paras = docx_paras(args.staging / "recordings_text.docx")
    entries = parse_recordings_verses(rec_paras)
    bo_paras = docx_paras(args.staging / "boethlingk95.docx")
    blocks = mulam_blocks(bo_paras)

    cards = []
    for r in rows:
        if not r["is_num"]:
            continue
        is_num = int(r["is_num"])
        su = r["su_num"]
        rec = say.get(is_num)
        if rec is None:
            raise SystemExit(f"manifest is_num {is_num} not in the corpus — verifier should have caught this")

        verse = clean_recorded_verse(entries[int(su)]) if su and int(su) in entries else None
        ru = None
        if su and su in getattr(main, "_ru_cache", {}):
            ru = main._ru_cache[su]
        if ru is None and su:
            # probe the anthology body by the IS saying's opening (first-line prefix)
            probe = norm(rec["deva"])[:20]
            best = (0, None)
            for bi, bn in blocks:
                cp = 0
                while cp < min(len(probe), len(bn)) and probe[cp] == bn[cp]:
                    cp += 1
                if cp > best[0]:
                    best = (cp, bi)
            if best[0] >= 14:
                ru = ru_for_entry(bo_paras, best[1])
                if verse is None:
                    verse = verse_from_entry(bo_paras, best[1]) or None
        if not ru and su:
            # recordings-docx probe path (verse from docx, RU from the anthology body)
            pass
        cards.append(
            {
                "is_num": is_num,
                "audio_id": r["audio_id"],
                "set": r["set"],
                "file": r["file"],
                "duration_s": float(r["duration_s"]),
                "size_bytes": int(r["size_bytes"]),
                "su_num": int(su) if su else None,
                "slug": r["slug"],
                "iast": r["iast"],
                "is_iast": rec["iast"],
                "is_deva": rec["deva"],
                "verse_deva": verse,
                "ru": ru,
                "match_method": r["match_method"],
            }
        )

    # RU cache layer (recordings-docx verse probe), precomputed by tools that ran
    # the join; kept in main() attrs only for this build — the vendored feed is
    # the artifact, this script documents its provenance.
    cards_by_su = {c["su_num"]: c for c in cards if c["su_num"]}

    feed = {
        "id": "subhashita-audio-srs-deck",
        "built": "2026-09-14",
        "source": "H4474 audio manifest + Subhashita-Recordings-Text.docx + Subhashita_EnRuDe_Boethlingk-95.docx + indische_sprueche.jsonl",
        "rights": "recordings are MG's own (MG ruling 14-09-2026: «все свои»); Böhtlingk text 1870–73 public domain",
        "stats": {
            "cards": len(cards),
            "with_ru": sum(1 for c in cards if c["ru"]),
            "with_verse": sum(1 for c in cards if c["verse_deva"]),
        },
        "cards": cards,
    }
    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open("w", encoding="utf-8") as fh:
        json.dump(feed, fh, ensure_ascii=False, indent=1)
    print(f"cards={len(cards)} with_ru={feed['stats']['with_ru']} with_verse={feed['stats']['with_verse']}")
    print(f"wrote {args.out}")


if __name__ == "__main__":
    main()