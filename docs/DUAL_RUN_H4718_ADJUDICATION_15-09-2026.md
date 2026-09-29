_Created: 15-09-2026 · Last updated: 15-09-2026_

# Dual-run adjudication — H4718 (two OxAlpha-pool workers shipped the same unit)

H4718 was claimed at 2026-09-14T21:22Z (`.claim`, OxAlpha/opencode lane) and re-launched as a fresh worker past the stale-claim TTL. Both workers executed end-to-end before the registry row was flipped. This file is the salvage record — never a silent pick.

## The two runs

| | Worker 1 (first-landed) | Worker 2 (this session) |
|---|---|---|
| Commit | [`e1f612ed`](https://github.com/gasyoun/Systema-Sanscriticum/commit/e1f612ed) on `h4718-drain` → [PR #2569](https://github.com/gasyoun/Systema-Sanscriticum/pull/2569) | `f473ca48` (reflog-only, retired, never pushed) |
| Page | `/reading/sandhi`, flag `features.kosha_reader` (same as kosha-demo, deliberate) | `/reading/sandhi`, dedicated flag `features.sandhi_pages` |
| Baked layer | 147 rules (head through 90 % of mass), provenance pins kosha commit `3a9c35bd9` + TSV blob SHA, ~81 KB | 200 rules, sha256 TSV pin, coverage ladder baked as data |
| Builder | `scripts/vendor_corpus_sandhi.py` (reads kosha origin/main, `--check` drift gate) | `scripts/build_corpus_sandhi_page_data.py` (`--check` idempotence gate) |
| Tests | 4 tests / 602 assertions, 10-rule head-of-ranking parity vs TSV | 5 tests / 40 assertions, deterministic 10-rule spread parity vs baked feed |
| Delivery chain | edge in [interlinks_edges.tsv](https://github.com/gasyoun/Uprava/blob/main/interlinks_edges.tsv) (row 242) + kosha manifest PR [#559](https://github.com/gasyoun/kosha/pull/559) + [report](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/REPORT_CORPUS_SANDHI_READING_PAGE_H4718_2026-09-15.md) | duplicate of the same chain (own edge text + own report, discarded) |

## Verdict

**Worker 1's implementation wins** — first-landed, complete delivery chain (code + edge + kosha manifest + report), richer provenance (commit+blob pinning), feature-superset of the duplicate except the dedicated flag, which their report documents as a deliberate same-family choice.

**Worker 2's role this pass (independent verification + residuals):**

1. Re-verified their tree cold: `CorpusSandhiPageTest|ReadingPackTest` → **32 passed / 6 737 assertions OK** on PHP 8.5.9 (second-machine confirmation of the 4/602 + neighbour claim).
2. Closed their open Pint residual (their box had PHP 8.2, Pint needs ≥8.3): `pint CorpusSandhiController.php CorpusSandhiPageTest.php` → **passed**.
3. Retired the duplicate commit `f473ca48` (reflog-recoverable; nothing salvaged — no code delta worth churning the open PR).
4. Closed the registry row + this PR is left for a human merge (product repo: PR-only).

_Гасунс_
