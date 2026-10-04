# Business checklist — student onboarding → course access (H5635)

_Created: 02-10-2026 · Author: OxAlpha (opencode/z-ai/glm-5.3-flash) for Dr. Mārcis Gasūns_

**Process chosen:** student onboarding → course access granting.
**Justification (one line):** the access contour is the documented high-risk money/access logic (AGENTS.md: `Payment::PAID_STATUSES`, tariff keys `full`/`block_N`/`block_N_hH`, group grants, zero-lesson recording pitfall) and the test stand exercises it with zero real money — payments are plain DB rows, no webhook/provider touched.

**Test stand:** Laravel feature tests, in-memory SQLite (`phpunit.xml`), fresh `composer install` in worktree `Systema-Sanscriticum-h5635-drain`. No production contact.

**Walker:** `tests/Feature/Access/BusinessChecklistH5635Test.php` — every route walked through the REAL HTTP gate `student.recording.gate` (the gate that re-checks grant/group/payment/free/preview per play), ALLOW = redirect to embed URL, DENY = 404.

## Checklist data (routes × field values × approver/origin)

Approver/origin = who puts the enabling fact into the system: `webhook` (Tochka marks payment paid/success), `admin` (manual: group membership, per-lesson grant, access window), `system` (free/preview flags), `student` (payment promise «под обещание»).

| ID | Route (field values) | Approver/origin | Expected verdict |
|----|----------------------|-----------------|------------------|
| R01 | No payment, no grant, no group; lesson of group G | — | DENY |
| R02 | payment.status=`pending` (not in PAID_STATUSES), tariff=`full` | webhook (pending) | DENY |
| R03 | payment.status=`paid`, tariff=`full`, course-general lesson (group_id=null) | webhook | ALLOW |
| R04 | payment.status=`success`, tariff=`full`, course-general lesson | webhook | ALLOW |
| R05 | payment.status=`paid`, tariff=`block_2`; lessons of blocks 1/2/3 | webhook | block2 ALLOW, block1 DENY, block3 DENY |
| R06 | payment.status=`paid`, tariff=`block_1_h1`; lessons (b1,h1) (b1,h2) (b1,half=null) | webhook | h1 ALLOW, h2 DENY, whole-block lesson DENY |
| R07 | lesson.is_free=1, no payment, guest | system | ALLOW |
| R08 | lesson.is_preview=1, no payment, guest | system | ALLOW |
| R09 | LessonAccessGrant on lesson L1 only, no payment | admin | L1 ALLOW, sibling L2 DENY |
| R10 | user in group G1, lesson of OTHER group G2, paid `full` | admin (wrong group) | DENY |
| R11 | user in group G, lesson scoped to own group G, paid `full` | admin + webhook | ALLOW |
| R12a | payment promise LIVE (promised_at future), conditional payment (is_conditional, linked_promise_id), flag conditional_access_expiry=ON | student + admin | ALLOW |
| R12b | payment promise EXPIRED (promised_at past), conditional payment, flag conditional_access_expiry=ON | student + admin | DENY |

## Fail semantics

- Route marked PASS without a test-entity trail in the run log = invalid evidence.
- Any write to production data = handoff failure. None of the routes touches production; stand is `:memory:` SQLite.

## Evidence chain

- Run log: `docs/testing/H5635_RUN_LOG_route_verdicts.jsonl` (per-route JSON: route_id, entity ids, expected, actual, verdict).
- JUnit: emitted at run time (`--log-junit`), not committed (CI-shaped artifact).

Related: H5635 handoff · `app/Http/Controllers/Concerns/StudentCourseContentConcerns.php::resolveLessonAccessGate` · `Lesson::isUnlockedBy` · `StudentController::getUserUnlockedTariffs` · `app/Models/Payment.php::PAID_STATUSES`.
