# Grammar Lab G3 learning loop (H2494)

_Created: 13-08-2026 · Last updated: 13-08-2026_

Risk-tiered drills, FSRS projection, mastery and an explainable recommender
on top of the G2 import/explorer. Learner routes stay behind
`GrammarLabAccess::canUse()`. Production flags stay OFF.

## Commands

```powershell
php artisan grammar-lab:sync
php artisan grammar-lab:review ex:grammar-lab:ablaut-grades approve
php artisan grammar-lab:review ex:grammar-lab:ablaut-grades reject --note="ambiguous"
php artisan grammar-lab:rollback ex:grammar-lab:ablaut-grades
php artisan grammar-lab:rollback ex:grammar-lab:ablaut-grades --kill
php artisan test --filter=GrammarLab
```

## Publication policy

| Class | Auto-publish | Gate |
|---|---|---|
| `deterministic` | Yes, if `GRAMMAR_LAB_AUTO_PUBLISH=true` | Validators + reproducible 20% sample + version row for rollback |
| `interpretive` | Never | `approval_record` required; otherwise `approval_required` |

Validators check answer equivalence after normalization, unique choices and
distractors, source presence, and topic-version compatibility. Failed items
become `rejected` and stay hidden.

`GRAMMAR_LAB_AUTO_PUBLISH` is an import-time kill switch. It does not flip
`GRAMMAR_LAB`. Rollback and kill change visibility only — `grammar_attempts`
rows are never deleted.

The 20% sample is `HMAC-SHA256(seed, exercise_id)` over sorted published
deterministic IDs. Seed default: `grammar-lab-g3-v1`. Manifest:
`storage/app/grammar_lab_sample.json`.

## Learner loop

- Practice: `/dvaram/grammar-lab/t/{slug}/practice`
- Add to SRS: private per-user deck `grammar-lab` via existing `ReviewService` / FSRS
- Mastery: consecutive correct answers (default 2) on `grammar_mastery` — not a second scheduler
- Next topic: prerequisite → weakness → overdue SRS card → next difficulty band, with a visible reason

## Proof

| Criterion | Test |
|---|---|
| A11 20% sample + validators | `GrammarLabLearningLoopTest::test_import_publishes_deterministic_and_blocks_interpretive` |
| A12 interpretive blocked | `test_interpretive_cannot_be_forced_visible_without_approval` |
| A13 rollback keeps attempts | `test_rollback_restores_prior_version_and_keeps_attempts` |
| A14 FSRS reused | `test_srs_projection_reuses_review_service` + `test_mastery_does_not_write_fsrs_state` |

_Dr. Mārcis Gasūns_
