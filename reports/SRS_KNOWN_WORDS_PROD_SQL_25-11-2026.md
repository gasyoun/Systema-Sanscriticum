# H8599 — known-words: read-only prod-SQL check (25-11-2026)

_Created: 25-11-2026 · Source: prod `.92` (`/var/www/html`, DB `laravel`), read-only `DB::select` via `php artisan tinker`. No writes, no PII in output._

## Baseline

From [`SRS_BASELINE_2_25-11-2026.md`](SRS_BASELINE_2_25-11-2026.md): 691 `srs_review_states`, 32 users, 1 490 repetitions at handoff-mint time. Live count at verification: **711 states, 32 users**.

## Aggregate probe (read-only)

`mature` = state with `due`/`last_review` set and `TIMESTAMPDIFF(DAY, last_review, due) >= 21` (R2/Q10). Shape = `srs_note_types.key` + `srs_cards.source_word_id`. A candidate is filtered when a DIFFERENT card of the same shape has a mature state for the same user (any deck).

| Metric | Value |
|---|---|
| Users with review states | 32 |
| Review states | 711 |
| States with both dates | 711 |
| Mature states (interval ≥ 21 d) | 80 |
| Max interval, days | 187 |
| Cards total | 26 619 |
| Cards with lemma | 25 849 |
| Distinct note types | 11 |
| **Due-state cards that would be filtered** | **0** |
| **Due-now cards that would be filtered** | **0** |
| **New cards that would be filtered (user × card)** | **0** |
| Users affected | 0 |

Cross-check: groups `(user, lemma, note_type)` with ≥ 2 cards and ≥ 1 mature state = **0**. So the zero is a property of the data, not a query artifact.

**Verdict:** enabling `srs.known_words.enabled` today changes no queue in the live cohort. The flag is OFF by default; the change is therefore observation-safe, but the filter itself has no live evidence yet — it is covered by the offline feature tests in `tests/Feature/Srs/KnownWordsTest.php`.

## Own-data canary (one real user, de-identified)

Pick the most recent mature state, resolve its user + deck, build `ReviewService::queueFor` with the flag OFF then ON, compare card-id sequences.

| Field | Value |
|---|---|
| Deck id | 6 |
| Queue before (flag OFF) | 32 cards |
| Queue after (flag ON) | 32 cards |
| Identical sequence | yes |
| Removed card ids | none |
| Order preserved | yes |

Only card ids and counts were returned; no user id, name, or personal data left the host.

## Re-run

The aggregate SQL is reproducible from the CTE in this report's method description; it is deliberately not committed as an executable script because prod access is SSH-gated and no credentials belong in the repo.
