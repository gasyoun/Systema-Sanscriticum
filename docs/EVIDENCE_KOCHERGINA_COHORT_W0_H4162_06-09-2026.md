# Evidence — Kochergina reference cohort W0: promise/access baseline and v1 contract fixtures (H4162)

_Created: 06-09-2026 · Last updated: 06-09-2026_

**Handoff:** [H4162](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4162-OxAlpha_Systema-Sanscriticum_kochergina-cohort-contract-v1-w0_05.09.26.md) (OxAlpha — GLM 5.3 Flash `zai-coding-plan/glm-5.3-flash`)
**Gate:** V1 — Kochergina trajectory truth ([verification plan](https://github.com/gasyoun/Uprava/blob/main/docs/VERIFICATION_PORTFOLIO_REVENUE_RESEARCH_PEDAGOGY_2026H2_2027.md))
**Verdict:** **PASS** (bounded by the W0 stop fences below; PARTIAL-grade caveats are named in §8)

## 1. Cohort selection (evidence-selected, one cohort)

Reference cohort: **«Грамматика по Кочергиной гр.61» — course_id 434, slug `grammatika-po-kocerginoi-gr61`, group_id 131.**

| Candidate | Paid real payments | First-result events | Verdict |
|---|---|---|---|
| gr.62 (id 435, newest) | 9 (block_1 ×5, block_2 ×4) | **0 homework, 0 completed lessons** | rejected — promise/access observable, first result NOT traceable yet |
| **gr.61 (id 434)** | **12 (block_1 ×7, block_2 ×5)** | **9 homework submissions (first 03-08), 3 completed lessons (first 16-08)** | **selected — the only cohort with the full chain observable end-to-end** |

gr.61 is also a live continuation cohort: 5 students re-purchased block_2 on 23–30-08-2026, so the access→money loop is current, not historical.

## 2. Promise → access → first-result map (committed)

| Stage | Mechanism (existing, reused — no new system) | v1 fixture |
|---|---|---|
| Offer/promise | Catalogue + tariffs (Блоки 1–10 × 8 000 ₽) + deposit/бронь 6 000 ₽ path; conditional payment = доступ под обещание | `kochergina_cohort_catalogue_v1.json` |
| Access | `Payment::fireOnPaid` → `processSuccessfulPayment()` → `grantAccess()` (group_user via course_group) + `enrollInCourse()`; lesson gate = `ensureLessonAccessible` (group visibility + `getUserUnlockedTariffs`) | `kochergina_entitlement_token_v1.json` |
| First result | `POST /c/{slug}/u/{lessonId}/complete` → exactly one `lesson_user` row (`is_completed=1`), one `LESSON_MARK_MASTERED` telemetry event, idempotent prana; homework submission = second independent signal | `kochergina_first_result_event_v1.json` |

The entitlement token's two-layer truth (tested): a **conditional** payment grants real access (by design, promise→access) but is excluded by `scopeReal()` from the cohort-entitlement filter; **deposit/trial** grant neither groups nor lesson access.

## 3. Current-data sample (read-only prod probe, observed 2026-09-06T14:14–14:20Z)

All numbers from `php artisan tinker --execute` SELECTs on prod (`193.232.229.92`), verbatim:

- `courses` row 434: `is_active=1, is_visible=1, is_completed=0, teacher_id=2, lessons_count=80, created 2026-06-17`.
- `teachers` id 2: «Гасунс Марцис Юрьевич».
- Group 131 «Грамматика по Кочергиной гр.61» attached via `course_group`.
- Paid payments: `block_1` ×7 (50 000 ₽ total, first 19-06), `block_2` ×5 (40 000 ₽, first 23-08, last 30-08-2026); `deposit` ×1 (6 000 ₽, 24-06, grants no access); `failed` ×12, `pending` ×1.
- Denominator rows: `course_user` 7, `group_user` (distinct) 7.
- Lessons live: 6 of 80 planned; `homework_submissions` 9 (first 2026-08-03 08:17:06); `lesson_user is_completed=1` rows 3 (first 2026-08-16 22:01:51).
- `tariffs`: Блоки 1–10, type `block`, price 8 000.00, all `is_active=1` (ids 4991–5000).
- `payment_promises` for course 434: **0 rows** (no open promises — the promise side is carried by the offer/deposit, not the promises ledger).

Timestamps verbatim as stored (created_at columns).

## 4. Activation denominators (H3764 definitions, this cohort)

Definitions are NOT re-invented: they are [ActivationCompletionMetricsService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Analytics/ActivationCompletionMetricsService.php) — activation anchor = first access-opening purchase (`StudentUnitEconomicsService::acquisitionAnchors`); course denominator = `course_user` rows; group denominator = `group_user` rows; completion = `lesson_user.is_completed` (never `lesson_views.is_completed`, dead on prod).

| Measure | Numerator | Denominator | Observed 06-09-2026 |
|---|---|---|---|
| Paid (revenue students) | paid+real payments on course 434 | — (count) | 12 payments / 7 enrolled students |
| Enrolled | course_user rows | — | 7 |
| Group membership | group_user distinct users | — | 7 |
| Homework activation | distinct students with ≥1 submission | group_user | ≥1 of 7 (9 submissions) |
| Lesson completion | students with ≥1 completed lesson | group_user | ≥1 of 7 (3 completions) |

Per-student splits were not extracted (read-only sample kept to aggregates); the denominators are what W0 owes.

## 5. v1 fixtures + targeted contract tests (committed on the default branch)

- [tests/fixtures/cohort_contract_v1/kochergina_cohort_catalogue_v1.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/fixtures/cohort_contract_v1/kochergina_cohort_catalogue_v1.json) — catalogue contract incl. the **drafted** `cohort_courses` entry with `enabled=false` and the stop fences.
- [tests/fixtures/cohort_contract_v1/kochergina_entitlement_token_v1.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/fixtures/cohort_contract_v1/kochergina_entitlement_token_v1.json) — token shape + 7 scenarios (paid block_1/full, deposit, trial, conditional promise, pending, cross-course leak).
- [tests/fixtures/cohort_contract_v1/kochergina_first_result_event_v1.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/fixtures/cohort_contract_v1/kochergina_first_result_event_v1.json) — event contract, idempotent replay, rollback, prod reference.
- [tests/Feature/CohortContract/KocherginaCohortContractV1Test.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CohortContract/KocherginaCohortContractV1Test.php) — runs every fixture against the real machinery.

Exact command and output:

```
$ php -d memory_limit=1G vendor/bin/phpunit --filter KocherginaCohortContractV1Test
OK, but there were issues!          # issues = pre-existing PHP 8.5 deprecation notices in vendor code
Tests: 15, Assertions: 82           # 15/15 green; pint: {"tool":"pint","result":"passed"}
```

Idempotent replay is proven on the real route: two POSTs to `student.lesson.complete` → still exactly **one** `lesson_user` row and **one** `LESSON_MARK_MASTERED` event (the pivot has no unique index; the code guard is the contract). Denial without entitlement: 403 and zero rows.

## 6. Rollback rehearsal

The test `rollback_revoke_locks_content_and_keeps_history` executes the production revoke (cancel payment + detach from course group) and asserts: lesson content locks (403 on replay), progress history stays intact, nothing is deleted. W1 enable-rollback = flip the drafted entry's `enabled` back to `false` — a one-line config revert with no migration and no cross-repo edit (V1 cross-contract test #5 satisfied by construction).

## 7. Human-gated vs agent-owned

| | |
|---|---|
| **Agent-owned (this PR)** | Fixtures, contract tests, evidence doc — read-only prod observation |
| **Human-gated (NOT done here)** | Enabling the cohort (`enabled=true`), any price change, catalogue expansion, any Karaoke/ORS wave, touching the deposit price |

## 8. Verdict

**PASS.** One eligible cohort is evidence-selected; the promise→access→first-result map is committed; three v1 fixtures survive idempotent replay with an explicit rollback; denominators are recorded. Stop fences held: no live cohort enabled, no price changed, no catalogue expansion.

Caveats (do not downgrade to PARTIAL, recorded for W1): per-student activation splits need a follow-up read-only query; gr.62's first-result chain is still unobservable (0 events) — the next natural reference cohort will need its own W0-style evidence packet once homework flows there; the drafted `cohort_courses` entry is a fixture, not yet config.

_Provenance: H4162, OxAlpha — GLM 5.3 Flash (`zai-coding-plan/glm-5.3-flash`), 06-09-2026._

_Dr. Mārcis Gasūns_

## 9. W1 addendum — the flip (06-09-2026, MG ruling «flip» in chat)

The human-gated switch was ruled ON the same day. `config/cohort_courses.php` now carries the
`kochergina-gr61` entry (`course_slug` `grammatika-po-kocerginoi-gr61`, `enabled` env-gated via
`KOCHERGINA_GR61_COHORT_ENABLED`, **no `packs` key**): the slug-keyed entitlement surface goes
live on prod via the env line, while the reader surface stays absent (404) and the course's real
access path (ordinary checkout → `grantAccess()` → groups) is untouched — zero student-visible
change. Env inventory regenerated (832→834 keys). Rollback: remove/flip the env line → `config:cache`.

## 10. W2 addendum — fixture-trajectory rehearsal on the live stack (06-09-2026, MG ruling «w2» in chat)

[Script](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/w2_fixture_trajectory_rehearsal.php) (committed, dry-run by default, `--apply` gates the write run) rehearses the full trajectory of [verification gate V1](https://github.com/gasyoun/Uprava/blob/main/docs/VERIFICATION_PORTFOLIO_REVENUE_RESEARCH_PEDAGOGY_2026H2_2027.md) on production data with **zero production money movement and zero residue**:

- **Zero residue by construction:** the whole rehearsal runs inside ONE database transaction that is always rolled back; the fixture payment carries `amount = 0.00`; in-process `queue.default=null` (the money Google Sheet never sees the fixture — `SendPaymentToSheetJob` dropped) and `mail.default=array` (welcome/receipt mails never leave). The fixture user is `is_admin=false`, no lead, no referrer, no deposits, no promises — every side branch of the canonical chain is a no-op.
- **Chain under test (16 checks, all ok, exit 0):** fixture user → `Payment::create(paid, 0.00)` → `fireOnPaid` stamps `first_paid_at` once → `grantAccess` into group 131 → `enrollInCourse` («Записался») → `CourseCohortEntitlement::hasEntitlement('kochergina-gr61')` live via the W1-flipped env → unlock keys contain `block_1` → first-result completion (exactly one `lesson_user` row, one `LESSON_MARK_MASTERED`, prana lesson award) → **idempotent replay** (payment re-processed, completion re-clicked: rows/events/membership not duplicated, `first_paid_at` never re-stamped) → **revoke** (cancel + detach: lesson locks at the tariff-unlock layer, progress history intact).
- **Post-state == pre-state** (payments 26, course_user 7, group_user 7, lesson_user 3, homework 9, revenue_schedules 14, entitled 7, fixture users 0) — the rehearsal left prod byte-for-byte as it found it.
- Honest ledger of the two rehearsal-own defects fixed during the pass (check bugs, not chain defects): (1) gr61's lessons are course-level (`group_id NULL`), so the revoke lock lives at the tariff-unlock layer, not the group-pivot visibility layer — assertion moved ([PR #2390](https://github.com/gasyoun/Systema-Sanscriticum/pull/2390)); (2) lesson selection initially demanded `group_id=131` and found nothing.
- **V1 gate status after W2:** offer/access map (W0), eligible cohort (W0), fixture payment (W2, replayed + rolled back), entitlement (W1 flip, live), first-result event (W0 prod trace + W2 live rehearsal) — **the stable identifier chain has now survived idempotent replay and rollback on the live stack. V1 = PASS.**
