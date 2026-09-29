# Trust-Boundary Contract Matrix — anonymous input, capability tokens, destructive lifecycle

_Created: 18-09-2026 · Last updated: 18-09-2026_

_Provenance: H5093 · failure classes evidenced by H5046 security-audit run-1 and remediated in [PR #2670](https://github.com/gasyoun/Systema-Sanscriticum/pull/2670) (csv-export-formula-injection), [PR #2672](https://github.com/gasyoun/Systema-Sanscriticum/pull/2672) (lead-duplicate-flash-magnet-token-disclosure), [PR #2675](https://github.com/gasyoun/Systema-Sanscriticum/pull/2675) (payment-hard-delete-skips-access-revocation)._
_Shared assertions: [tests/Concerns/AssertsTrustBoundaries.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Concerns/AssertsTrustBoundaries.php) · representative regressions: [tests/Feature/Sandbox/H5093TrustBoundaryContractMatrixTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Sandbox/H5093TrustBoundaryContractMatrixTest.php)_

## The contract (five fields)

Every surface that moves (a) guest/user-controlled strings into spreadsheet cells, (b) capability-bearing links into guest-visible responses, or (c) destructive money/access transitions through user-reachable code must declare:

1. **Source** — who controls the input crossing the boundary (anonymous guest / self-registered user / staff / system).
2. **Sink** — where it lands (spreadsheet cell, response body, session flash, database row removal).
3. **Authority** — who may trigger the sink at all (anonymous / staff-admin / super_admin / system-only).
4. **Invariant** — the property that must hold even when Authority is abused.
5. **Negative regression** — the executable test that turns red when the invariant is violated (mutation-proven once at introduction).

> **Authority-floor note (dual column):** the Boundary A staff floor is enforced on the legacy `is_admin` column (`AdminMiddleware`), the Boundary C floor on the `role` column (`RoleGate`); the two are kept in sync by the `User::booted` hook. Writes that bypass Eloquent (direct DB writes) can desync them — any such surface must be inventoried here.

New surfaces: run the [Maintainer checklist](#maintainer-checklist-for-a-new-surface) and add a row here in the same PR.

## Boundary A — guest-controlled strings → spreadsheet export cells

Invariant (shared): **no export cell may serialize as a formula-shaped value** (`=`, `+`, `@`, tab, CR first char; `-` unless the remainder after it is fully numeric). Enforced by `App\Support\FormulaGuard::row()`/`::cell()` at every writer.

| Surface (writer) | Source | Sink | Authority | Negative regression | Status |
|---|---|---|---|---|---|
| `LeadController::export()` (`GET /admin/leads/export`, `admin` middleware) | anonymous lead form (name, contact, utm_*) | CSV download (`;`, BOM) | staff (admin) | `H5093TrustBoundaryContractMatrixTest::boundary_a_guest_formulas_never_reach_export_cells` + `H5086CsvExportFormulaNeutralizedTest` | GUARDED |
| `SurveyPageController::exportCsv()` (`GET /admin/surveys/{slug}/export`) | anonymous survey answers | CSV download (`;`, BOM) | staff | `H5086CsvExportFormulaNeutralizedTest` (writer wired via same `FormulaGuard::row`) | GUARDED |
| `Filament\Pages\AttendanceDashboard::exportCsv()` | self-chosen student names | CSV download | staff (Filament panel) | `H5086CsvExportFormulaNeutralizedTest` (writer wired via `FormulaGuard::row`) | GUARDED |
| `CourseStreamComparisonExport::studentMatrix()` | self-chosen student names | xlsx (PhpSpreadsheet) | staff | `H5086CsvExportFormulaNeutralizedTest::xlsx_stream_comparison_neutralizes_guest_student_names` | GUARDED |
| `SurveyAudienceCommand` (`survey:audience`) | lead/user names + contacts (user-controlled) | CLI-written CSV (`;`, BOM) in `storage/app/survey-audience/` | staff console | **none — GAP**: writer has no `FormulaGuard`; opened by staff in Excel ⇒ same evaluation risk as #2670, one staff step removed | **OPEN — residual @DO filed 18-09-2026; needs its own remediation unit** |
| `BuildCanonicalCsv` (`storage/app/imports/*`) | staff-imported CSV file | canonical CSV beside source | staff console (no anonymous source) | none — guard recommended when command surfaces beyond CLI | N/A (staff-only source; residual note) |
| `MoneySli\MoneySliAlerter` TSV | internal money rows (admin-set titles) | TSV alert attachment | system/alerting | none — no guest-controlled field identified | N/A (internal source) |

## Boundary B — capability tokens in guest-visible responses

Invariant (shared): **knowing a dedupe key (email/contact/phone) never returns the existing lead's bearer capability values** — no `duplicate_deep_link`, `duplicate_channel`, `status_connect_links`, `magnet_deep_links`, `marathon_telegram_link` in duplicate flashes or rendered pages; bearer `magnet_token` values never appear in a stranger's response.

| Surface | Source | Sink | Authority | Negative regression | Status |
|---|---|---|---|---|---|
| `LeadController::store()` duplicate branch (`buildDuplicateFlash()` → `thankyou.blade.php`) | anonymous submitter (dedupe key only) | session flash + rendered thank-you page | anonymous | `H5093TrustBoundaryContractMatrixTest::boundary_b_duplicate_submission_discloses_no_victim_capability_tokens` + `H5085LeadDuplicateNoTokenTest` | GUARDED |
| `LeadController::oneClick()` waitlist duplicate branch | **authenticated user whose profile contact dedupes** | `status_connect_links` built from the EXISTING lead's `magnet_token` (`statusFlash()` → `LeadFlashBuilder::statusConnectLinks()`) | authenticated profile owner | **none on main** — `H5085LeadDuplicateNoTokenTest` covers `store()` + marathon only; `WaitlistGuestSubscriptionTest` asserts the pre-fix behavior for this branch | **OPEN — `status_connect_links` still carry the victim token on the oneClick duplicate branch; remediated on PR #2672 (rotate + plain success), residual @DO filed 18-09-2026 to verify/merge** |
| Marathon register resume (`/online/konsultaciya`) | anonymous submitter (dedupe key only) | `marathon_telegram_link` flash | anonymous | `H5085LeadDuplicateNoTokenTest::marathon_duplicate_does_not_re_disclose_victim_telegram_link` | GUARDED |
| `magnet_token` lifecycle on re-submission | anonymous duplicate submission | token rotation (`rotateMagnetToken()`) invalidating minted links | anonymous | owned by PR #2672 branch tests | **OPEN — PR #2672 pending merge** |
| Magnet landing / status pages | token holder (bearer) | lead-specific content | bearer token only | bearer semantics covered by #2672 rotation above | GUARDED (bearer) |
| `LeadStepWebhookController` | Telegram webhook (signed by bot secret) | lead binding | system webhook secret | webhook signature suites | GUARDED (server-to-server) |
| Magnet callback webhook jobs `ProcessVkMagnetCallback` / `ProcessTelegramMagnetUpdate` / `ProcessMaxMagnetUpdate` | VK/Telegram/MAX webhook payloads | bearer-token lead lookup + binding | system webhook secret | webhook signature/queue suites | GUARDED (server-to-server) |
| `Filament\Pages\MarathonQuizProgress` quiz table | lead `magnet_token` rendered as a copyable staff column | Filament staff table cell | staff panel (Filament auth) | staff-only bearer display, no anonymous surface — row kept so the next audit does not re-derive it | BY DESIGN (staff-only display) |

## Boundary C — destructive money/access lifecycle transitions

Invariant (shared): **deleting a paid payment is impossible below the admin trust floor**, and deletion of money-critical rows may not strand granted benefits silently.

| Surface | Source | Sink | Authority | Negative regression | Status |
|---|---|---|---|---|---|
| `PaymentResource::canDelete()` / `canDeleteAny()` (Filament delete + bulk delete) | authenticated staff action | hard removal of a `payments` row (incl. paid) | `RoleGate::adminOnly()` (admin + super_admin) | `H5093TrustBoundaryContractMatrixTest::boundary_c_paid_payment_deletion_is_authority_gated` + `H5084PaymentDeleteAdminOnlyTest` | GUARDED |
| Paid-payment hard-delete revocation chain (revoke-before-remove: group access, prana, referral, deposit, promo, gift cert) | admin delete action | status-transition reversal chain then row removal | admin | `PaymentHardDeleteRevocationTest` (PR #2675 branch) | **OPEN — PR #2675 pending merge** |
| Silent deletes by design (`TeacherPayout` mirror `TeacherPayout.php:104` + `TeacherPayoutPoster.php:72`, `RehearseClubMembership.php:292`, `MoneySliFixture.php:88` — `withoutEvents`; conditional cleanup `Payment.php:1396` — query-builder) | system cleanup | row removal without model events | system-only (no user-reachable path) | documented in code comments; no user-reachable entrypoint | BY DESIGN (documented) |

## Maintainer checklist for a new surface

When a PR adds or touches a surface in any of the three boundaries:

1. Declare all five fields (source / sink / authority / invariant / negative regression) in a new row here — same PR, no exceptions.
2. Reuse `Tests\Concerns\AssertsTrustBoundaries` — do not hand-roll one-off assertions:
   - Boundary A → `assertCsvCellsFormulaNeutral($csv)` over the real stream (whole export, every column);
   - Boundary B → `assertSessionExposesNoCapabilityKeys()` + `assertResponseExposesNoCapabilityTokens($response, [$secret])`;
   - Boundary C → `assertTransitionAuthorityGated($probe, [...forbidden], [...allowed])`.
3. Add the negative regression that fails if the invariant is broken, and mutation-prove it ONCE at introduction (remove the guard/gate at the writer, watch it go red, revert, watch it go green).
4. Staff-only sinks with no guest source still get a row (status `N/A` + reason) so the next audit does not re-derive the census.
5. Gaps found during inventory are filed as residuals (GTD `@DO`) and remediated in their own unit — never folded silently into an unrelated PR.

_Гасунс_
