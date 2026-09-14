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
# stdlib XML parse of MG's OWN docx staging (trusted local teaching files, not
# untrusted input); defusedxml is not a repo dependency — rule consciously muted:
import xml.etree.ElementTree as ET  # nosemgrep: python.lang.security.use-defused-xml.use-defused-xml
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


def probe_blocks(blocks, probe, minlen):
    """Best anthology mūlam block for a Devanagari probe, or None."""
    if len(probe) < minlen:
        return None
    best = (0, None)
    for bi, bn in blocks:
        cp = 0
        while cp < min(len(probe), len(bn)) and probe[cp] == bn[cp]:
            cp += 1
        if cp > best[0]:
            best = (cp, bi)
    return best[1] if best[0] >= minlen else None


def probe_blocks_progressive(blocks, probe):
    """Longest-prefix mūlam-block probe: try full pratīka, then progressively
    shorter prefixes (TOC pratīkas may print a recension that diverges from the
    body print mid-word); a first-word fallback (>=6 folded chars) matches when
    unique or when several blocks share the opening but the first is the entry.
    """
    if len(probe) < 6:
        return None
    for cut in range(len(probe), 5, -1):
        sub = probe[:cut]
        cands = [bi for bi, bn in blocks if bn.startswith(sub)]
        if len(cands) == 1:
            return cands[0]
        if len(cands) > 1 and cut <= 8:
            return cands[0]
    return None


def parse_anthology_toc(paras):
    """`<pratīka><page>` lines (anthology TOC) -> {page: pratīka}."""
    toc = {}
    for p in paras[6:106]:
        m = re.match(r"^([ऀ-ॿ][ऀ-ॿ\s]*?)\s*(\d{1,3})$", p.strip())
        if m:
            toc[int(m.group(2))] = m.group(1).strip()
    return toc


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
    toc_pratika = parse_anthology_toc(bo_paras)

    cards = []
    for r in rows:
        is_num = int(r["is_num"]) if r["is_num"] else None
        su = r["su_num"]
        rec = say.get(is_num) if is_num else None
        if is_num and rec is None:
            raise SystemExit(f"manifest is_num {is_num} not in the corpus — verifier should have caught this")

        verse = clean_recorded_verse(entries[int(su)]) if su and int(su) in entries else None
        ru = None
        if is_num:
            # probe the anthology body by the IS saying's opening (first-line prefix)
            probe = norm(rec["deva"])[:20]
            best = probe_blocks(blocks, probe, 14)
            if best:
                ru = ru_for_entry(bo_paras, best)
                if verse is None:
                    verse = verse_from_entry(bo_paras, best) or None
        if is_num and ru is None and verse:
            # the anthology may print a different recension than Böhtlingk's IS
            # (Su22: tape/anthology तु vs IS च) — probe by the recorded verse itself
            probe = norm(verse)[:22]
            best = probe_blocks(blocks, probe, 14)
            if best:
                ru = ru_for_entry(bo_paras, best)
        if verse is None and r["deva_pratika"]:
            # unmatched / Su>53 rows: probe by the anthology TOC pratika (page-keyed)
            # first (it IS the anthology's own pratīka), then by the filename pratika
            prat = norm(r["deva_pratika"])
            if len(prat) >= 10:
                best = probe_blocks(blocks, prat, 10)
                if best:
                    ru = ru_for_entry(bo_paras, best)
                    if verse is None:
                        verse = verse_from_entry(bo_paras, best) or None
        if verse is None and r["anthology_page"]:
            # filename pratīka too weak (one word) — the anthology TOC pratīka
            # (page-keyed) is fuller; probe with it. The TOC may print another
            # recension than the body (Su63: सम्पत्त्या vs body संयत्त्या), so
            # probe by the longest progressively-shorter prefix that still hits.
            prat = norm(toc_pratika.get(int(r["anthology_page"]), ""))
            best = probe_blocks_progressive(blocks, prat)
            if best:
                ru = ru_for_entry(bo_paras, best)
                if verse is None:
                    verse = verse_from_entry(bo_paras, best) or None
        if verse is None and r["anthology_page"]:
            verse = toc_pratika.get(int(r["anthology_page"]), "") or None
        if verse is None and r["deva_pratika"]:
            # nothing matched: the pratīka from the filename/manifest IS the card front
            verse = r["deva_pratika"]
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
                "is_iast": rec["iast"] if rec else None,
                "is_deva": rec["deva"] if rec else None,
                "verse_deva": verse,
                "ru": ru,
                "match_method": r["match_method"],
            }
        )
    cards.sort(key=lambda c: (c["set"], c["su_num"] or 999, c["audio_id"]))

    feed = {
        "id": "subhashita-audio-srs-deck",
        "built": "2026-09-14",
        "source": "H4474 audio manifest + Subhashita-Recordings-Text.docx + Subhashita_EnRuDe_Boethlingk-95.docx + indische_sprueche.jsonl",
        "rights": "recordings are MG's own (MG ruling 14-09-2026: «все свои»); Böhtlingk text 1870–73 public domain",
        "stats": {
            "cards": len(cards),
            "with_is_num": sum(1 for c in cards if c["is_num"]),
            "without_is_num": sum(1 for c in cards if not c["is_num"]),
            "with_ru": sum(1 for c in cards if c["ru"]),
            "with_verse": sum(1 for c in cards if c["verse_deva"]),
        },
        "cards": cards,
    }
    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open("w", encoding="utf-8") as fh:
        json.dump(feed, fh, ensure_ascii=False, indent=1)
    # human-readable register: ALL recordings, Böhtlingk-marked (MG 14-09-2026:
    # «выведи мне все… не надо убивать кого-то, потому что не нашёл его в Бётлингке»)
    reg = args.out.parent / "subhashita_audio_register.md"
    write_register(reg, cards)
    # Böhtlingk-only register (MG 14-09-2026: «отдельно реестр именно тех,
    # которые есть в Бётлингке»)
    boet_reg = args.out.parent / "subhashita_audio_boetlingk_register.md"
    write_boetlingk_register(boet_reg, cards, args.sprueche)
    print(f"cards={len(cards)} with_is_num={feed['stats']['with_is_num']} "
          f"without_is_num={feed['stats']['without_is_num']} "
          f"with_ru={feed['stats']['with_ru']} with_verse={feed['stats']['with_verse']}")
    print(f"wrote {args.out}")
    print(f"wrote {reg}")
    print(f"wrote {boet_reg}")


def write_register(reg: Path, cards) -> None:
    """Markdown listing of every recording, Böhtlingk-flagged."""
    lines = [
        "# Subhāṣita audio register — all recordings, Böhtlingk-flagged (H4474)",
        "",
        "_Created: 14-09-2026 · Generated by `scripts/build_subhashita_srs_deck.py` — do not hand-edit._",
        "",
        "`is_num` = Böhtlingk *Indische Sprüche* number, the exact `num` field of "
        "[indische_sprueche.jsonl](https://github.com/gasyoun/SanskritLexicography/blob/master/IndischeSprueche/data/indische_sprueche.jsonl) "
        "(7537 sayings) — verified by `scripts/verify_subhashita_audio_manifest.py`. "
        "Recordings WITHOUT a Böhtlingk number are anthology sayings from other sources "
        "(Mahābhārata, Pañcatantra, Hitopadeśa, Upaniṣads, Bhartṛhari, Manu…) or one-word-pratīka "
        "Kochergina takes — they are kept, never dropped.",
        "",
        "| # | audio_id | set | Su | pratīka / verse opening | Бётлингк | RU |",
        "|---|---|---|---|---|---|---|",
    ]
    for i, c in enumerate(cards, 1):
        boet = f"IS {c['is_num']}" if c["is_num"] else "—"
        open_txt = (c["verse_deva"] or c["is_deva"] or "—")[:38]
        ru_flag = "✓" if c["ru"] else "—"
        su = c["su_num"] or "—"
        lines.append(f"| {i} | `{c['audio_id']}` | {c['set']} | {su} | {open_txt}… | {boet} | {ru_flag} |")
    reg.write_text("\n".join(lines) + "\n", encoding="utf-8")


def write_boetlingk_register(reg: Path, cards, sayings_path: Path) -> None:
    """Markdown register of the Böhtlingk-linked recordings ONLY (MG 14-09-2026:
    «отдельно реестр именно тех, которые есть в Бётлингке») — one row per
    recording carrying an is_num, with the IS text joined live from our corpus.
    """
    sayings = {}
    with sayings_path.open(encoding="utf-8") as fh:
        for line in fh:
            if line.strip():
                rec = json.loads(line)
                sayings[rec["num"]] = rec
    lines = [
        "# Subhāṣita audio — Böhtlingk-linked register (H4474)",
        "",
        "_Created: 14-09-2026 · Generated by `scripts/build_subhashita_srs_deck.py` — do not hand-edit._",
        "",
        f"The {sum(1 for c in cards if c['is_num'])} recordings whose Böhtlingk *Indische Sprüche* "
        "number is PROVEN: `is_num` is the exact `num` field of "
        "[indische_sprueche.jsonl](https://github.com/gasyoun/SanskritLexicography/blob/master/IndischeSprueche/data/indische_sprueche.jsonl) "
        "(7537 sayings, Böhtlingk 2nd ed. 1870–73) — every number below was re-read from that file by "
        "`scripts/verify_subhashita_audio_manifest.py` (exit 0). IAST is the corpus's own romanization; "
        "`match_method` says which evidence tier proved the join.",
        "",
        "| # | audio_id | Бётлингк | IAST (IS, наш корпус) | пратика плёнки | метод |",
        "|---|---|---|---|---|---|",
    ]
    n = 0
    for c in cards:
        if not c["is_num"]:
            continue
        n += 1
        rec = sayings.get(c["is_num"]) or {}
        iast = (rec.get("iast") or "")[:46]
        prat = (c["verse_deva"] or c["is_deva"] or "—")[:30]
        lines.append(
            f"| {n} | `{c['audio_id']}` | **IS {c['is_num']}** | {iast}… | {prat}… | `{c['match_method']}` |"
        )
    reg.write_text("\n".join(lines) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()