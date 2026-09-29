#!/usr/bin/env python3
"""H5399 probe: how many still-gapped SRS lemmas does sa_ru_glossary.json cover?

The lemma feed mixes key schemes — hitopadesa-0 rows carry IAST in the
`lemma_slp1` column, subhāṣita rows carry real SLP1 — while the glossary is keyed
on normalised IAST. So the join needs both a raw-key attempt and an SLP1 -> IAST
transliteration, or it reports a false 0%.
"""
import json
import os
import sys
import unicodedata
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

REPO = Path(__file__).resolve().parent.parent
GLOSSARY = REPO / "resources" / "data" / "sa_ru_glossary.json"
TSV = Path(os.environ.get(
    "LEMMAS_TSV",
    r"C:\Users\user\Documents\GitHub\kosha-h5399-126640\data\cohort_start_chteniya\lemmas_for_srs.tsv",
))

SLP1_TO_IAST = [
    ("A", "ā"), ("I", "ī"), ("U", "ū"), ("f", "ṛ"), ("F", "ṝ"), ("x", "ḷ"), ("X", "ḹ"),
    ("E", "ai"), ("O", "au"),
    ("K", "kh"), ("G", "gh"), ("N", "ṅ"),
    ("C", "ch"), ("J", "jh"), ("Y", "ñ"),
    ("w", "ṭ"), ("W", "ṭh"), ("q", "ḍ"), ("Q", "ḍh"), ("R", "ṇ"),
    ("T", "th"), ("D", "dh"),
    ("P", "ph"), ("B", "bh"),
    ("S", "ś"), ("z", "ṣ"),
    ("M", "ṃ"), ("H", "ḥ"), ("~", "m"),
]


def slp1_to_iast(s: str) -> str:
    out = s
    for a, b in SLP1_TO_IAST:
        out = out.replace(a, b)
    return out


def norm(s: str) -> str:
    return unicodedata.normalize("NFC", s).strip().lower()


def main() -> int:
    entries = json.loads(GLOSSARY.read_text(encoding="utf-8"))["entries"]
    by_key = {norm(k): e for k, e in entries.items()}
    print("glossary entries %d" % len(entries))

    lines = TSV.read_text(encoding="utf-8").splitlines()
    gaps = []
    for ln in lines[1:]:
        p = ln.split("\t")
        if len(p) < 4:
            continue
        gloss = p[3].strip()
        if gloss and any("\u0400" <= ch <= "\u04ff" for ch in gloss):
            continue  # has a Cyrillic gloss already
        gaps.append((p[0], p[1], gloss))

    hits = 0
    samples = []
    misses = []
    for pack, lemma, gloss in gaps:
        for cand in (norm(lemma), norm(slp1_to_iast(lemma))):
            e = by_key.get(cand)
            if e:
                hits += 1
                if len(samples) < 12:
                    samples.append((pack, lemma, cand, e["g"][:3]))
                break
        else:
            if len(misses) < 12:
                misses.append((pack, lemma))

    print("gapped rows (empty or non-Cyrillic) %d, glossary hit %d (%.1f%%)"
          % (len(gaps), hits, 100.0 * hits / max(1, len(gaps))))
    print("\nsample fills:")
    for pack, lemma, cand, g in samples:
        print("  %-22s %-18s ~ %-18s -> %s" % (pack, lemma, cand, g))
    print("\nsample misses:")
    for pack, lemma in misses:
        print("  %-22s %s" % (pack, lemma))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
