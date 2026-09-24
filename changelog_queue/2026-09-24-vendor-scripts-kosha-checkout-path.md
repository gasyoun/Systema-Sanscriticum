_Created: 24-09-2026 · Last updated: 24-09-2026_

# Vendor scripts find kosha on any box; sandhi `--check` stops false-failing (Opus 5.5 `claude-opus-5-5`, 24-09-2026)

Found by the independent verifier on the H5399 close ([PR #2827](https://github.com/gasyoun/Systema-Sanscriticum/pull/2827)): `vendor_cohort_start_chteniya_packs.py --check` died with "No such file or directory" on the Mac. The script hardcoded one Windows box's kosha path.

- **New [`scripts/_common.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/_common.py) `kosha_checkout()`**: uses `KOSHA_HOME` if set, otherwise the sibling `<repo>/../kosha` (`~/Documents/GitHub/kosha` on the Mac, `%USERPROFILE%\Documents\GitHub\kosha` on Windows). If neither exists it exits with a message listing every path it tried.
- **Three scripts drop the hardcoded `C:\Users\user\...` path.** They are [`vendor_cohort_start_chteniya_packs.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_cohort_start_chteniya_packs.py), [`vendor_nala_subhashita_packs.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_nala_subhashita_packs.py) and [`vendor_corpus_sandhi.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_corpus_sandhi.py). The sha256 + byte-count check is unchanged.
- **`vendor_corpus_sandhi.py --check` no longer reports false drift.** The rebuild stamps `source.commit` with kosha's current HEAD, which changes with every unrelated kosha commit. The check now ignores that field; `source.blob` still pins the TSV bytes. A tampered blob still exits 1.
- Proof: `--check` exits 0 for all three scripts on the Mac and on the Windows box.

_Гасунс_
