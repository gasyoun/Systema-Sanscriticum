#!/usr/bin/env python3
"""Build the subhāṣita audio manifest (H4474).

Joins three offsite Yandex.Disk audio folders (126 mp3, read-only staging — the
audio itself is NOT vendored into this repo) with the Böhtlingk *Indische
Sprüche* numbering, so the Systema SRS layer can attach a recording to a saying.

Inputs (staging dir, produced by the H4474 run — see
docs/PLAN_SUBHASHITA_AUDIO_SRS_LAYER_2026.md § Reproduce):

    listing_systematic.txt   rclone lsf -R --format sp  yadisk:Subhashitas-Systematic
    listing_systematic1.txt  rclone lsf -R --format sp "yadisk:Subhashitas-Systematic (1)"
    listing_kochergina.txt   rclone lsf -R --format sp  yadisk:Kochergina-Subhashitas
    durations.tsv            ffprobe duration + bit_rate per file (relpath<TAB>sec<TAB>bps)
    recordings_text.txt      pandoc -t plain Subhashita-Recordings-Text.docx
    boethlingk95.txt         pandoc -t plain Subhashita_EnRuDe_Boethlingk-95.docx

Reference: indische_sprueche.jsonl (7537 sayings, Böhtlingk 2nd ed. 1870–73),
SanskritLexicography/IndischeSprueche/data/indische_sprueche.jsonl.
"""

import argparse
import json
import re
import sys
import unicodedata
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

DEVA_RE = re.compile(r"[ऀ-ॿ]")
STRIP_RE = re.compile(r"[\s।॥|/,.;:'​‌‍\-]")

# "Su41-Nabhisheko.mp3" / "Su7-Tatkarma.mp3"
SU_RE = re.compile(r"^Su(\d+)-(.+)\.mp3$")
# "01 Субхашита Удьямэна (Udyamena) उद्यमेन.mp3"
NUMBERED_RE = re.compile(r"^(\d+)\s+Субхашита\s+(.+?)\s+\((.+?)\)\s+([ऀ-ॿ].*)\.mp3$")
# "Субхашита Видья нама (Vidya nama) विद्या नाम.mp3"
KOCH_RE = re.compile(r"^Субхашита\s+(.+?)\s+\((.+?)\)\s+([ऀ-ॿ].*)\.mp3$")
TOC_RE = re.compile(r"^(\d+)\.\s+([ऀ-ॿ][^\d]*?)\s+(\d+)\s*$")
TEXT_HEAD_RE = re.compile(r"^(\d+)\.\s+([ऀ-ॿ].*?)\s*$")


def norm(deva: str) -> str:
    """Fold a Devanagari string to a comparison key: NFC, no spaces/punctuation."""
    return STRIP_RE.sub("", unicodedata.normalize("NFC", deva))


def read_listing(path: Path):
    """rclone `size;path` lines -> [(size:int, relpath:str)]."""
    out = []
    for line in path.read_text(encoding="utf-8").splitlines():
        if ";" not in line:
            continue
        size, rel = line.split(";", 1)
        if not size.strip().isdigit():
            continue
        out.append((int(size), rel.strip()))
    return out


def parse_anthology_toc(path: Path):
    """`N. <pratīka> <page>` -> {N: (pratika, page)} from the Böhtlingk-95 docx."""
    toc = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        m = TOC_RE.match(line.strip())
        if m:
            num, pratika, page = int(m.group(1)), m.group(2).strip(), int(m.group(3))
            toc.setdefault(num, (pratika, page))
    return toc


def parse_recordings_text(path: Path):
    """`N. <pratīka>` + following verse lines -> {N: (pratika, verse)}."""
    entries, cur, buf = {}, None, []
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        m = TEXT_HEAD_RE.match(line)
        if m and DEVA_RE.search(m.group(2)):
            if cur is not None:
                entries[cur[0]] = (cur[1], " ".join(buf).strip())
            cur, buf = (int(m.group(1)), m.group(2).strip()), []
        elif cur is not None and DEVA_RE.search(line):
            buf.append(line)
    if cur is not None:
        entries[cur[0]] = (cur[1], " ".join(buf).strip())
    return entries


def load_sprueche(path: Path):
    """indische_sprueche.jsonl -> [(num, deva, iast, norm_key)]."""
    rows = []
    with path.open(encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            rec = json.loads(line)
            deva = rec.get("deva") or ""
            rows.append((rec.get("num"), deva, rec.get("iast") or "", norm(deva)))
    return rows


def match_saying(verse, toc_pratika, file_pratika, sprueche):
    """Return (is_num, method) for the best Böhtlingk match, else (None, 'unmatched').

    Three evidence tiers, strongest first:
      verse_prefix   — the recorded verse's first 24 folded chars open a saying
      toc_pratika    — the anthology's pratīka (3-4 words) opens a saying
      file_pratika   — the Devanagari in the mp3 filename (often 1-2 words) opens it
    A pratīka shorter than 8 folded chars is too weak to identify one saying out of
    7537 and is never used; several hits are reported as *_ambiguous(N), first kept.
    """
    probes = (
        (verse, "verse_prefix", 24),
        (toc_pratika, "toc_pratika", 8),
        (file_pratika, "file_pratika", 8),
    )
    for probe, method, minlen in probes:
        key = norm(probe or "")
        if len(key) < minlen:
            continue
        probe_key = key[:24]
        hits = [num for num, _d, _i, sk in sprueche if sk.startswith(probe_key)]
        if len(hits) == 1:
            return hits[0], method
        if len(hits) > 1:
            return hits[0], f"{method}_ambiguous({len(hits)})"
    return None, "unmatched"


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--staging", required=True, type=Path)
    ap.add_argument("--sprueche", required=True, type=Path)
    ap.add_argument("--out", required=True, type=Path)
    args = ap.parse_args()
    st = args.staging

    durations = {}
    dur_path = st / "durations.tsv"
    if dur_path.exists():
        for line in dur_path.read_text(encoding="utf-8").splitlines():
            parts = line.split("\t")
            if len(parts) == 3 and parts[1]:
                durations[parts[0].replace("\\", "/")] = (parts[1], parts[2])

    toc = parse_anthology_toc(st / "boethlingk95.txt")
    texts = parse_recordings_text(st / "recordings_text.txt")
    sprueche = load_sprueche(args.sprueche)

    # Devanagari pratīka per Su-number, from the "(1)" mirror folder's filenames.
    deva_by_su, iast_by_su = {}, {}
    for _size, rel in read_listing(st / "listing_systematic1.txt"):
        m = NUMBERED_RE.match(Path(rel).name)
        if m:
            n = int(m.group(1))
            deva_by_su[n] = m.group(4).strip()
            iast_by_su[n] = m.group(3).strip()

    # Devanagari pratīka per Kochergina slug, from the Devanagari-named folder.
    koch_deva = {}
    for _size, rel in read_listing(st / "listing_kochergina.txt"):
        m = KOCH_RE.match(Path(rel).name)
        if m:
            koch_deva[norm(m.group(3))] = (m.group(3).strip(), m.group(2).strip(), m.group(1).strip())

    rows, seen = [], set()
    for size, rel in read_listing(st / "listing_systematic.txt"):
        name = Path(rel).name
        if not name.lower().endswith(".mp3"):
            continue
        nested = "/" in rel
        dur, bitrate = durations.get(rel.replace("\\", "/"), ("", ""))
        m = SU_RE.match(name)
        su_num = int(m.group(1)) if m else None
        slug = m.group(2) if m else name[:-4]
        deva = deva_by_su.get(su_num, "") if su_num else ""
        iast_slug = iast_by_su.get(su_num, "") if su_num else ""
        anth_pratika, anth_page = toc.get(su_num, ("", "")) if su_num else ("", "")
        verse = texts.get(su_num, ("", ""))[1] if su_num else ""
        if nested:  # Kochergina recordings mirrored inside Subhashitas-Systematic
            audio_set = "kochergina"
            audio_id = "sub-koch-" + slug.replace("Su-", "").lower()
            # These filenames carry no number; the Devanagari pratīka comes from
            # the Devanagari-named sibling folder, joined on identical byte size.
            for size2, rel2 in koch_by_size.get(size, []):
                m2 = KOCH_RE.match(Path(rel2).name)
                if m2:
                    deva, iast_slug = m2.group(3).strip(), m2.group(2).strip()
                break
        else:
            audio_set = "systematic"
            audio_id = f"sub-su{su_num:03d}-{slug.lower()}" if su_num else f"sub-{slug.lower()}"
        is_num, method = match_saying(verse, anth_pratika, deva, sprueche)
        if not deva and anth_pratika:
            deva = anth_pratika
        key = (audio_set, name)
        if key in seen:
            continue
        seen.add(key)
        rows.append({
            "audio_id": audio_id,
            "set": audio_set,
            "file": rel,
            "su_num": su_num or "",
            "slug": slug,
            "iast": iast_slug,
            "deva_pratika": deva,
            "duration_s": f"{float(dur):.2f}" if dur else "",
            "size_bytes": size,
            "bitrate_kbps": str(round(int(bitrate) / 1000)) if bitrate else "",
            "anthology_num": su_num if su_num and su_num in toc else "",
            "anthology_page": anth_page,
            "is_num": is_num or "",
            "match_method": method,
        })

    rows.sort(key=lambda r: (r["set"], int(r["su_num"]) if r["su_num"] else 999, r["file"]))
    cols = ["audio_id", "set", "file", "su_num", "slug", "iast", "deva_pratika",
            "duration_s", "size_bytes", "bitrate_kbps", "anthology_num",
            "anthology_page", "is_num", "match_method"]
    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open("w", encoding="utf-8", newline="\n") as fh:
        fh.write("\t".join(cols) + "\n")
        for r in rows:
            fh.write("\t".join(str(r[c]) for c in cols) + "\n")

    matched = sum(1 for r in rows if r["is_num"])
    total_dur = sum(float(r["duration_s"]) for r in rows if r["duration_s"])
    print(f"rows={len(rows)} matched_is={matched} unmatched={len(rows) - matched} "
          f"total_duration_min={total_dur / 60:.1f}")
    print(f"wrote {args.out}")


if __name__ == "__main__":
    main()
