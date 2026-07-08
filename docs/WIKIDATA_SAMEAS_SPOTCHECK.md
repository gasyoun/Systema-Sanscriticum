# Wikidata `sameAs` matcher — D4 spot-check

_Created: 08-07-2026 · Last updated: 08-07-2026_

Spot-check of the automated Wikidata `sameAs` matcher built for SEO P2 Wave-1
([H210](https://github.com/gasyoun/Uprava/blob/main/handoffs/H210-Opus_Systema-Sanscriticum_seo_p2_wave1_indexation_and_wikidata_matcher_05.07.26.md),
Track B, decision D4). The matcher populates
[`dictionary_words.wikidata_qid`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DictionaryWord.php)
so the `DefinedTerm` `sameAs` triplet can emit. D4: **never emit an unverified `sameAs`.**

- **Model:** Opus 4.8 (`claude-opus-4-8`).
- **Method:** [`WikidataSameAsMatcher`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Seo/WikidataSameAsMatcher.php)
  run live against the Wikidata `wbsearchentities` + `wbgetentities` API on 12 well-known
  headwords (unsaved model instances — no DB, so it exercises the matcher, not any table).
- **Signals:** `devanagari-sa-exact` (score 1.0, auto-write eligible) = an exact
  Sanskrit-language (`sa`) Devanāgarī label/alias match whose entity survives the P31
  "instance of" denylist; `iast-en-label` (score 0.75, review-only) = IAST equals a
  Wikidata English label. Default write threshold = 1.0 (exact-only).

## Result (post-P31-filter run, 08-07-2026)

| Headword (IAST / Devanāgarī) | QID | Score | Signal | Wikidata label / description | Verdict |
|---|---|---|---|---|---|
| karma / कर्मन् | Q132196 | 1.00 | devanagari-sa-exact | कर्मन् — "जैसा कर्म वैसा फल" | ✅ correct |
| ātman / आत्मन् | Q1052811 | 1.00 | devanagari-sa-exact | आत्मन् — Hindu concept, inner self | ✅ correct |
| brahman / ब्रह्मन् | Q746990 | 1.00 | devanagari-sa-exact | ब्रह्म — metaphysical Ultimate Reality | ✅ correct |
| dharma / धर्म | Q9241518 | 1.00 | devanagari-sa-exact | धर्म — "difference between Buddhist & Jain dharma" | 🔴 wrong (concept is Q9174) |
| saṃsāra / संसार | Q7410318 | 1.00 | devanagari-sa-exact | संसार — rebirth **in Jainism** | 🟡 too narrow (general = Q11800) |
| yoga / योग | Q9350 | 0.75 | iast-en-label | yoga — physical/mental/spiritual practices | review-only (not written) |
| mokṣa / मोक्ष | Q11254469 | 0.75 | iast-en-label | Moksa — river in Norway | review-only (correctly not written) |
| guru / गुरु | Q974795 | 0.75 | iast-en-label | Guru — American rapper (1961–2010) | review-only (correctly not written) |
| veda / वेद | Q1462391 | 0.75 | iast-en-label | Veda — female given name | review-only (correctly not written) |
| mantra / मन्त्र | Q114964671 | 0.75 | iast-en-label | Mantra | review-only |
| gaja / गज | Q2546301 | 0.75 | iast-en-label | Gaja — female given name | review-only (correctly not written) |
| nirvāṇa / निर्वाण | Q11649 | 0.75 | iast-en-label | Nirvana — American rock band | review-only (P31 rejected the exact hit) |

## What the spot-check proved

1. **The P31 "instance of" denylist works and is necessary.** Before it, `निर्वाण`
   auto-wrote **Q11649 (Nirvana, the rock band)** at score 1.0 — a clear D4 violation.
   The filter (rejecting human / band / river / given-name / place / disambiguation
   entities) demoted it to review-only. Bands, rivers and given names collide freely with
   Devanāgarī labels, so this filter is load-bearing, not optional.
2. **Exact-script match alone is still not clean enough for unattended writing.** Of the 5
   surviving 1.0 candidates, 3 are correct (karma, ātman, brahman), 1 is wrong
   (`dharma` → Q9241518, a comparison item, not the concept Q9174), and 1 is too narrow
   (`saṃsāra` → the Jainism-specific item). P31 cannot catch these — both wrong targets
   are *concept-like* items, so they pass the instance-of gate. Only human eyes catch a
   conceptual-disambiguation miss.
3. **Therefore the command is safe-by-default: it writes NOTHING without `--write`.** The
   operator runs it in propose mode, spot-checks the table (paying special attention to
   the conceptual-disambiguation class above), then re-runs with `--write`. The weaker
   IAST↔English signal never writes under the default threshold.

## Operating procedure at deploy (prod DB not reachable now — FTP-only host)

```
php artisan dictionary:match-wikidata --sample=50        # propose a spot-check sample
# review the table; confirm the 1.0 rows are the right CONCEPT, not a homograph
php artisan dictionary:match-wikidata --write            # persist exact matches
```

Re-runnable + idempotent (only NULL-qid rows are processed unless `--include-set`). A row
that later proves wrong: clear its `wikidata_qid` and it drops out of `sameAs` on the next
page render (the blade emits `sameAs` only when the qid is present). Consider a follow-on
[`/review-sheet`](https://github.com/gasyoun/Uprava) pass over the 1.0 proposals if the core
set is large enough to warrant formal per-row approval.

_Dr. Mārcis Gasūns_
