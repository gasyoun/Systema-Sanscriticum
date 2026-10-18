/* ============================================================
   Twins discrimination data for the ligature "близнецы" drill
   (lila v2, H6393).

   Derived deterministically from database/seeders/data/
   ligatures_frequency.tsv (same builder: build_ligatures_frequency_tsv.py):
   two top-200 DCS ligatures are twins iff their IAST readings are
   equal length, differ in exactly one consonant slot, and that slot
   differs within a visually confusable family (aspiration / n-ṇ /
   s-ṣ). Ordered by max rank of the pair (most frequent pair first).

   INTERIM set: replace with the Kiseleva gold confusion pairs when
   H6392 (ligature-confusion-gold-kiseleva) lands — the shape
   {a:{devanagari,iast,rank,pct}, b:{...}} is gold-ready.

   Source frequency: Digital Corpus of Sanskrit (Oliver Hellwig,
   CC BY 4.0) via public/lila/ligatures/data.js (H1281).
   ============================================================ */
(function (global) {
  "use strict";
  global.TWINS_PAIRS = [
    { a: { devanagari: "स्त", iast: "st", rank: 8, pct: 2.37 }, b: { devanagari: "स्थ", iast: "sth", rank: 28, pct: 1.01 } },
    { a: { devanagari: "ष्ट", iast: "ṣṭ", rank: 14, pct: 1.52 }, b: { devanagari: "ष्ठ", iast: "ṣṭh", rank: 31, pct: 0.96 } },
    { a: { devanagari: "र्थ", iast: "rth", rank: 35, pct: 0.88 }, b: { devanagari: "र्त", iast: "rt", rank: 36, pct: 0.87 } },
    { a: { devanagari: "द्य", iast: "dy", rank: 19, pct: 1.29 }, b: { devanagari: "ध्य", iast: "dhy", rank: 41, pct: 0.79 } },
    { a: { devanagari: "स्य", iast: "sy", rank: 2, pct: 3.86 }, b: { devanagari: "ष्य", iast: "ṣy", rank: 43, pct: 0.75 } },
    { a: { devanagari: "न्द", iast: "nd", rank: 44, pct: 0.71 }, b: { devanagari: "न्ध", iast: "ndh", rank: 48, pct: 0.65 } },
    { a: { devanagari: "न्य", iast: "ny", rank: 18, pct: 1.45 }, b: { devanagari: "ण्य", iast: "ṇy", rank: 61, pct: 0.42 } },
    { a: { devanagari: "र्ध", iast: "rdh", rank: 64, pct: 0.38 }, b: { devanagari: "र्द", iast: "rd", rank: 68, pct: 0.35 } },
    { a: { devanagari: "ब्र", iast: "br", rank: 30, pct: 1.00 }, b: { devanagari: "भ्र", iast: "bhr", rank: 70, pct: 0.34 } },
    { a: { devanagari: "च्छ", iast: "cch", rank: 33, pct: 0.92 }, b: { devanagari: "च्च", iast: "cc", rank: 75, pct: 0.32 } },
    { a: { devanagari: "म्ब", iast: "mb", rank: 71, pct: 0.34 }, b: { devanagari: "म्भ", iast: "mbh", rank: 79, pct: 0.31 } },
    { a: { devanagari: "द्व", iast: "dv", rank: 22, pct: 1.27 }, b: { devanagari: "ध्व", iast: "dhv", rank: 86, pct: 0.26 } },
    { a: { devanagari: "स्म", iast: "sm", rank: 17, pct: 1.45 }, b: { devanagari: "ष्म", iast: "ṣm", rank: 89, pct: 0.25 } },
    { a: { devanagari: "ख्य", iast: "khy", rank: 67, pct: 0.37 }, b: { devanagari: "क्य", iast: "ky", rank: 90, pct: 0.24 } },
    { a: { devanagari: "स्व", iast: "sv", rank: 11, pct: 1.59 }, b: { devanagari: "ष्व", iast: "ṣv", rank: 94, pct: 0.22 } },
    { a: { devanagari: "स्प", iast: "sp", rank: 80, pct: 0.30 }, b: { devanagari: "ष्प", iast: "ṣp", rank: 98, pct: 0.20 } },
    { a: { devanagari: "स्क", iast: "sk", rank: 96, pct: 0.20 }, b: { devanagari: "ष्क", iast: "ṣk", rank: 101, pct: 0.17 } },
    { a: { devanagari: "त्त", iast: "tt", rank: 10, pct: 1.71 }, b: { devanagari: "त्थ", iast: "tth", rank: 102, pct: 0.17 } },
    { a: { devanagari: "ग्न", iast: "gn", rank: 38, pct: 0.83 }, b: { devanagari: "घ्न", iast: "ghn", rank: 108, pct: 0.15 } },
    { a: { devanagari: "द्ध", iast: "ddh", rank: 21, pct: 1.27 }, b: { devanagari: "द्द", iast: "dd", rank: 115, pct: 0.14 } },
    { a: { devanagari: "द्र", iast: "dr", rank: 26, pct: 1.12 }, b: { devanagari: "ध्र", iast: "dhr", rank: 122, pct: 0.12 } },
    { a: { devanagari: "ब्द", iast: "bd", rank: 116, pct: 0.14 }, b: { devanagari: "ब्ध", iast: "bdh", rank: 124, pct: 0.11 } },
    { a: { devanagari: "ह्ण", iast: "hṇ", rank: 120, pct: 0.13 }, b: { devanagari: "ह्न", iast: "hn", rank: 125, pct: 0.11 } },
    { a: { devanagari: "त्य", iast: "ty", rank: 6, pct: 2.73 }, b: { devanagari: "थ्य", iast: "thy", rank: 129, pct: 0.11 } },
    { a: { devanagari: "ग्र", iast: "gr", rank: 40, pct: 0.79 }, b: { devanagari: "घ्र", iast: "ghr", rank: 130, pct: 0.11 } },
    { a: { devanagari: "र्ग", iast: "rg", rank: 62, pct: 0.41 }, b: { devanagari: "र्घ", iast: "rgh", rank: 140, pct: 0.09 } },
    { a: { devanagari: "र्ण", iast: "rṇ", rank: 34, pct: 0.88 }, b: { devanagari: "र्न", iast: "rn", rank: 143, pct: 0.08 } },
    { a: { devanagari: "न्त", iast: "nt", rank: 4, pct: 3.36 }, b: { devanagari: "न्थ", iast: "nth", rank: 146, pct: 0.08 } },
    { a: { devanagari: "स्प", iast: "sp", rank: 80, pct: 0.30 }, b: { devanagari: "स्फ", iast: "sph", rank: 147, pct: 0.08 } },
    { a: { devanagari: "र्भ", iast: "rbh", rank: 82, pct: 0.30 }, b: { devanagari: "र्ब", iast: "rb", rank: 152, pct: 0.07 } },
    { a: { devanagari: "ङ्क", iast: "ṅk", rank: 93, pct: 0.23 }, b: { devanagari: "ङ्ख", iast: "ṅkh", rank: 155, pct: 0.07 } },
    { a: { devanagari: "न्व", iast: "nv", rank: 63, pct: 0.40 }, b: { devanagari: "ण्व", iast: "ṇv", rank: 163, pct: 0.05 } },
    { a: { devanagari: "द्म", iast: "dm", rank: 133, pct: 0.10 }, b: { devanagari: "ध्म", iast: "dhm", rank: 165, pct: 0.05 } },
    { a: { devanagari: "क्ष", iast: "kṣ", rank: 5, pct: 3.14 }, b: { devanagari: "क्स", iast: "ks", rank: 167, pct: 0.04 } },
    { a: { devanagari: "द्भ", iast: "dbh", rank: 85, pct: 0.27 }, b: { devanagari: "द्ब", iast: "db", rank: 170, pct: 0.04 } },
    { a: { devanagari: "ण्ठ", iast: "ṇṭh", rank: 158, pct: 0.06 }, b: { devanagari: "ण्ट", iast: "ṇṭ", rank: 175, pct: 0.04 } },
    { a: { devanagari: "क्त", iast: "kt", rank: 16, pct: 1.48 }, b: { devanagari: "क्थ", iast: "kth", rank: 185, pct: 0.03 } },
    { a: { devanagari: "र्च", iast: "rc", rank: 103, pct: 0.16 }, b: { devanagari: "र्छ", iast: "rch", rank: 190, pct: 0.03 } },
    { a: { devanagari: "ङ्ग", iast: "ṅg", rank: 37, pct: 0.86 }, b: { devanagari: "ङ्घ", iast: "ṅgh", rank: 194, pct: 0.03 } },
    { a: { devanagari: "ञ्च", iast: "ñc", rank: 52, pct: 0.57 }, b: { devanagari: "ञ्छ", iast: "ñch", rank: 200, pct: 0.03 } }
  ];
  // The board shows the BOARD_GROUPS most frequent pairs so both twins
  // of every group are always in play (guaranteed discrimination).
  global.TWINS_BOARD_GROUPS = 10;
})(window);
