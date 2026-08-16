# SEO P2 — dictionary indexation wave log (2026)

_Created: 15-08-2026 · Last updated: 16-08-2026_

Wave-1 **activation packet** (command, ~805-row expectation, `--reset`, Webmaster steps):
[SEO_P2_WAVE1_ACTIVATION_PACKET_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_P2_WAVE1_ACTIVATION_PACKET_2026.md).
**An agent must not run the write.**

Wave log for the `/slovar` curated-core indexation promotion
([H210](https://github.com/gasyoun/Uprava/blob/main/handoffs/H210-Opus_Systema-Sanscriticum_seo_p2_wave1_indexation_and_wikidata_matcher_05.07.26.md)
Track A, decisions D1 + D2; roadmap
[`docs/SEO_ROADMAP_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_ROADMAP_2026.md) §5).

D2 is **promote in monitored waves, not all at once**, and **back off if authority dips**.
A wave is therefore only complete once its Yandex.Webmaster reading is written down here —
that is what makes a later demotion traceable to a batch.

## The allowlist

[`database/data/seo/seo_core_headwords_dcs_lexical_cores.txt`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/data/seo/seo_core_headwords_dcs_lexical_cores.txt)
— 7,120 unique IAST headwords, consumed from V. V. Leonchenko's DCS-corpus lexical-core
appendices as extracted in SanskritLexicography
([`RussianTranslation/src/pilot/lexical_cores/`](https://github.com/gasyoun/SanskritLexicography/tree/master/RussianTranslation/src/pilot/lexical_cores)).
Not re-derived.

**File order is the promotion order** — strongest lexical core first, so a wave is simply a
prefix of the file and `--limit=N` is all a wave needs:

| Tier | Core | Headwords in file | What it is |
|---|---|---:|---|
| 2 | Приложение 10 (`pril10`) | 435 | all-periods-stable ultra-core — the safest thing to index |
| 3 | Приложение 5 (`pril5`) | 5,902 | periodized cores, ordered by period spread DESC |
| 4 | Сборное ядро (`sbornoe`) | 783 | consolidated remainder |

## Measured coverage against the prod dictionary (16-08-2026, re-measured post-deploy)

Dry runs only — `--dry-run` writes nothing. Prod dictionary = **11,892** rows. These figures
come from the **deployed** command on prod, not from a pre-deploy estimate — see the note
below, which corrects the first two rows.

| Wave | Command | Headword slugs taken | Slugs resolving | Rows promoted |
|---|---|---:|---:|---:|
| 1 | `--limit=435` (≈ tier 2) | 435 | 394 | **805** |
| 2 | `--limit=2000` | 2,000 | 1,617 | 2,884 |
| full | no `--limit` | 6,635 | 3,106 | 4,773 |

> **Correction (16-08-2026).** The first version of this table said 785 and 2,785. Those came
> from truncating the raw **file lines** and then deduplicating; the shipped `--limit`
> deduplicates to canonical headword slugs **first** and then takes N of them, so a given N
> reaches slightly further down the list. The deployed numbers above are the ones to plan
> against. One consequence for wave 1: `--limit=435` no longer means "tier 2 exactly" —
> because 7,120 IAST headwords collapse to 6,635 distinct slugs, 435 slugs reach a little
> past the 435-line tier-2 boundary. That is harmless (the next entries are the strongest of
> tier 3), but say "≈ tier 2", not "tier 2 exactly".

Only ~44 % of the list resolves to a dictionary row — the cores are corpus lemmas, the
dictionary is a curated teaching lexicon, so the intersection is the meaningful set. The
remaining 7,119 prod rows stay `noindex` under `curated_only`.

## Prod state at the time of writing (15-08-2026)

| Setting / counter | Value |
|---|---|
| `DICTIONARY_SEO_INDEX_ENABLED` | **true** (master switch already flipped by a human) |
| `dictionary_seo.gate.curated_only` | true |
| `dictionary_words` rows | 11,892 |
| `is_indexable = true` | **4** |
| `wikidata_qid` populated | 0 |

So the master switch is on but the allowlist was never fed: `/slovar` and
`/slovar/{slug}` return 200 and still emit `noindex, follow`. Nothing regressed — the gate
is default-closed exactly as designed.

## Waves executed

| Wave | Date | `--limit` | Rows promoted | Sitemap submitted | Yandex.Webmaster reading | Verdict |
|---|---|---|---|---|---|---|
| — | — | — | — | — | — | _none executed yet_ |

## ⛔ Why an agent stops here

The promotion write is one half of a **monitored** procedure. The other half — reading
indexed count, thin-content / low-quality flags and host quality in **Yandex.Webmaster**
between waves, and backing off if authority dips — needs the Webmaster account, which an
agent does not have. Running the write without the reading would execute half of D2 and
leave the back-off trigger unobservable on a revenue-facing surface.

Running wave 1 is one command once a human can watch Webmaster:

```
php artisan dictionary:mark-core-indexable database/data/seo/seo_core_headwords_dcs_lexical_cores.txt --limit=435
```

Then submit the sitemap chunk to Yandex.Webmaster, wait for a reading, and add the row to
the table above before the next wave. Reversal is symmetric — `--reset` clears every flag
and returns the whole set to `noindex`.

_Dr. Mārcis Gasūns_
