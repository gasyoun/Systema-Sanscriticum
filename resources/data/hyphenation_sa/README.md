# resources/data/hyphenation_sa — Sanskrit wordform lists (SA-HK / SA-IAST / SA-devanagari)

_Created: 10-09-2026 · Last updated: 10-09-2026_

## Provenance

- **Source:** `yadisk:Sanskrityatina/20_Hyphenation/` (Yandex Disk, MG's own lexicographic
  workshop archive) — the six `SA-HK`/`SA-IAST`/`SA-devanagari` `.dic`+`.udc` files.
- **Rights:** own work — MG holds full rights, no third-party license restriction.
- **Landed by:** H4487 (OxAlpha), 10-09-2026, gzip-compressed as-is (no content edits).
- **Original encodings:** `.dic` = UTF-16LE with BOM, CRLF line endings; `.udc` = UTF-8, LF.
  Decompress with `gunzip` to recover the original bytes exactly (`gzip -d` is lossless;
  no re-encoding was applied before landing).

## What these files actually are

Despite the folder name ("20_Hyphenation") and `.dic`/`.udc` extensions, these are **flat
Sanskrit wordform lists**, not TeX-style hyphenation-pattern files (no `a1b2c`-style
positional break-point encoding, unlike the sibling `hyph-sa.tex` / `hyph_sa_1.2.oxt` in
the same source folder — those two were left un-landed, out of this handoff's scope).

Validated counts (one word per line):

| File | Encoding | Lines (words) |
|---|---|---|
| `SA-HK.dic` | UTF-16LE | 202,566 |
| `SA-HK.udc` | UTF-8 | 202,558 |
| `SA-IAST.dic` | UTF-16LE | 202,559 |
| `SA-IAST.udc` | UTF-8 | 202,558 |
| `SA-devanagari.dic` | UTF-16LE | 202,566 |
| `SA-devanagari.udc` | UTF-8 | 202,538 |

The `.dic`/`.udc` pair for each scheme is the **same ~202.5K-word list** in two encodings
(UTF-16 vs UTF-8), not two different rule sets — a sorted diff of `SA-HK.dic` vs `SA-HK.udc`
shows only 38 differing lines out of 202k (trailing-comma artifacts on a couple of entries,
and a handful of words present in one file but not the other). The three schemes (HK, IAST,
Devanagari) carry the same wordlist transliterated three ways.

## Verdict — where this plugs (or doesn't)

- **NOT a drop-in LibreOffice hyphenation dictionary.** LibreOffice hyphenation needs
  TeX-pattern-format rules (`hyph_xx.dic` built from `.tex` patterns via `patgen`); this
  is a plain wordlist, so it cannot be registered as a `HyphDictionary` service as-is.
- **NOT currently wired into Systema typography, karaoke line-break, or PWG print.**
  A repo-wide search (`grep -rniE "hyphenat|karaoke|line-break|linebreak"` over `app/`,
  `resources/`, `config/`) found zero existing hooks for hyphenation in this codebase —
  there is no consumer to plug into yet.
- **Plausible future use:** (a) raw lexicon input to a `patgen`-style hyphenation-pattern
  generator (would need Sanskrit syllabification rules layered on top); (b) a spell-check
  wordlist for editor/curator surfaces; (c) a syllable-boundary lookup table if paired with
  an existing Sanskrit syllabifier, for karaoke-style line-break at syllable edges rather
  than mid-akṣara. None of these are implemented here — this landing is the raw asset only.

## Files

```
SA-HK.dic.gz          SA-HK.udc.gz
SA-IAST.dic.gz        SA-IAST.udc.gz
SA-devanagari.dic.gz  SA-devanagari.udc.gz
```

Gzip-compressed (24 MB → 4.7 MB) to keep the landed footprint reasonable; content is
byte-identical to source after `gunzip`.

_Dr. Mārcis Gasūns_
