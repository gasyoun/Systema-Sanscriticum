# Memrise course 6522419 export

_Created: 22-07-2026 · Last updated: 05-09-2026_

Raw export of Memrise community course
[6522419 «Учебник санскрита» В.А. Кочергиной, на деванагари](https://community-courses.memrise.com/community/course/6522419/uchebnik-sanskrita-va-kocherginoi-na-devanagari/)
(own course, author samskrtamru = Mārcis Gasūns), pulled 22-07-2026 via
`scripts/memrise_export.py` (H1431) — 9 levels, 356 rows total, same 2-column
shape (`col_a`/`col_b`) as the IAST sibling course
[6502608](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/memrise_6502608/README.md).

## Comparison against 6502608 (IAST) — NOT a 1:1 script mirror

This course is **not** the full IAST course re-rendered in Devanāgarī — it is
a smaller, partial port:

- **Offset by one level**: this course has no abbreviations-glossary level
  (6502608's `level_01.csv`) — its `level_01.csv` already starts with lesson
  vocabulary and matches 6502608's `level_02.csv` (`ka`→`क`, `kha`→`ख`,
  `ga`→`°ग`, `ca`→`च`, `ja`→`°ज`…). So devanagari level *N* ≈ IAST level
  *N+1*, script substituted in `col_a`, `col_b` (Russian gloss) unchanged.
- **Levels 1–6 here = IAST levels 2–7, row-count-exact** (41/33/68/59/35/60
  rows on both sides) — full parallel coverage for that span.
- **Levels 7–9 here (14/23/23 rows) are a partial subset of IAST levels
  8–10 (34/41/38 rows)** — the Devanagari port stops mid-lesson there and
  has no further levels (IAST continues to level 41; this course tops out
  at 9).

Net: treat this course as covering roughly the first ~7 lessons' worth of
the IAST course in full, tapering into partial coverage of the next couple,
then stopping — not a complete Devanagari edition of the whole 41-level
course. Confirm scope before using it as a Devanagari-script source beyond
that range.

_Dr. Mārcis Gasūns_
