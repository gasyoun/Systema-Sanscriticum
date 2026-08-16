# SEO P2 wave-1 activation packet

_Created: 16-08-2026 · Last updated: 16-08-2026_

**H2893 / H2-4.** Ready packet for the first `/slovar` indexation wave. **An agent must not run the write.**

This file is the execution packet. The running log stays
[SEO_P2_INDEXATION_WAVE_LOG_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_P2_INDEXATION_WAVE_LOG_2026.md).
Rulings: [DECISIONS_seo_p2_dictionary_pages.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_seo_p2_dictionary_pages.md)
(D1 curated core · D2 monitored waves · D3 canonical collapse · D4 sameAs).

---

## Why an agent stops

D2 is promote-then-read. The write is only half. The other half — indexed count, thin-content / low-quality flags, host quality in **Yandex.Webmaster** — needs the Webmaster account. Running `dictionary:mark-core-indexable` without that reading would flip `is_indexable` on a revenue-facing surface with no back-off signal.

**An agent must not run the write.**

---

## Exact command (human, after a Webmaster reading is scheduled)

Dry-run only is allowed for an agent (already measured 16-08-2026):

```
php artisan dictionary:mark-core-indexable database/data/seo/seo_core_headwords_dcs_lexical_cores.txt --limit=435 --dry-run
```

Human write (do not run from an agent session):

```
php artisan dictionary:mark-core-indexable database/data/seo/seo_core_headwords_dcs_lexical_cores.txt --limit=435
```

Allowlist path:
[database/data/seo/seo_core_headwords_dcs_lexical_cores.txt](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/data/seo/seo_core_headwords_dcs_lexical_cores.txt)
— 7,120 unique IAST headwords, file order = promotion order. `--limit=435` is ≈ tier 2 (`pril10`), not tier 2 exactly (see the wave log correction of 16-08-2026).

Do **not** flip `DICTIONARY_SEO_INDEX_ENABLED` (already true on prod). Do **not** edit `config/dictionary_seo.php` for this wave.

---

## Measured expectation (16-08-2026 prod dry-run)

| `--limit` | Headword slugs taken | Slugs resolving | Rows promoted |
|---:|---:|---:|---:|
| **435** (wave 1) | 435 | 394 | **~805** |

Use **~805** as the expected `is_indexable` delta. A documented ± few rows is acceptable if the allowlist or dictionary rows moved; record the actual count in the wave log.

---

## Reversal

```
php artisan dictionary:mark-core-indexable database/data/seo/seo_core_headwords_dcs_lexical_cores.txt --reset --dry-run
php artisan dictionary:mark-core-indexable database/data/seo/seo_core_headwords_dcs_lexical_cores.txt --reset
```

`--reset` clears every `is_indexable` flag, then the list can be re-applied. Without a new `--limit` the full list would be marked; to undo wave 1 only, reset then re-run with `--limit=0` is **not** supported — reset returns the whole set to `noindex`. That is the intended back-off.

---

## After a human write (still human)

1. `php artisan cache:forget sitemap.xml`
2. Submit the sitemap in **Yandex.Webmaster** (agent does not have this login).
3. Wait for a Webmaster reading (indexed count, thin-content / host-quality flags).
4. Add a row to the wave log before any later `--limit`.

---

## Wave-log row template

Copy into
[SEO_P2_INDEXATION_WAVE_LOG_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_P2_INDEXATION_WAVE_LOG_2026.md)
section **Waves executed**:

| Wave | Date | `--limit` | Rows promoted | Sitemap submitted | Yandex.Webmaster reading | Verdict |
|---|---|---|---|---|---|---|
| 1 | YYYY-MM-DD | 435 | (actual) | yes/no + time | indexed=… / thin=… / host=… | proceed / back off |

---

_Dr. Mārcis Gasūns_
