# H5094 — Mutation ledger: invariant-test hardening pilot

_Created: 18-09-2026_

Pilot over the H5049/H5066/H5085 remediation suites (PRs #2653, #2657, #2672):
three representative weak assertions replaced with observable
result + forbidden-side-effect + persisted-state assertions, each proven by a
temporary production mutation that turns the hardened test RED and is then
restored (focused suite GREEN after restoration, 19 tests / 98 assertions,
Pint green).

## Census: weak original → observable replacement

| # | Suite · test | Weak original (vacuousness) | Observable replacement |
|---|---|---|---|
| A | `CourseInterestTest::test_embed_renders_standalone_minimal_form` | `assertStringContainsString('frame-ancestors', $csp)` — passes even if the allowlist degenerates to `frame-ancestors *` (clickjacking from any origin) | Exact full-header equality pinning `frame-ancestors 'self' https://samskrtam.ru https://www.samskrtam.ru` (literal copied into the test, NOT read from the controller const); forbidden side effect: show response carries NO CSP header (embed-only scope, no site-wide weakening); persisted state: GET embed creates zero `course_interest_requests` rows |
| B | `SessionHealthProbeTest::needs_reauth_blocks_publish_fail_closed_before_any_send` | Refusal proven only by exception + `STATUS_FAILED`; a fail-closed path that forgets to journal the reason (or starts the send state machine) stays green | Forbidden side effect: `AnonsDestinationRun` count = 0 (send state machine never starts on a dead session — the stub adapter publishes successfully, so a gate moved below the send loop is caught); persisted state: publication journal must contain `needs reauthorization` + `AUTH_KEY_UNREGISTERED` (human must SEE why the story never went out) |
| C | `H5085LeadDuplicateNoTokenTest::duplicate_lead_flash_carries_no_victim_token` | Session-flash + rendered-page checks only; duplicate submission silently rotating the victim's bearer token or creating a second lead row stays green | Forbidden side effect: `magnet_deep_links` flash must be absent on the duplicate path (likely regression vector — reuse of the new-lead token-bearing flash); persisted state: `Lead` count stays 1 AND victim's `magnet_token` unchanged after the duplicate submission |

## Mutation receipts (one-to-one with the hardened assertions)

Each mutation was applied to production code, the single focused test run RED,
then the mutation reverted and the focused suite re-run GREEN. Every mutation
was chosen so the ORIGINAL test would have stayed green — i.e. each receipt
demonstrates exactly the teeth the new assertion adds.

### Mutation A — allowlist weakened

- **Temporary change:** `CourseInterestController::FRAME_ANCESTORS` → `"frame-ancestors *"`.
- **Expected failing assertion:** exact `assertSame` on the embed CSP header —
  observed: `Failed asserting that two strings are identical. -'frame-ancestors 'self' https://samskrtam.ru https://www.samskrtam.ru' +'frame-ancestors *'` → FAILURES (1).
- **Vacuousness proof:** the old `contains('frame-ancestors')` check passes on `frame-ancestors *`.
- **Restored:** `git checkout app/Http/Controllers/CourseInterestController.php`.

### Mutation B — refusal without journal

- **Temporary change:** `AnonsPublishingService::publish()` R13 reauth branch —
  journal write removed, `STATUS_FAILED` + throw kept.
- **Expected failing assertion:** `assertStringContainsString('needs reauthorization', $publication->journal)` —
  observed: `Failed asserting that '' contains "needs reauthorization"` → FAILURES (1).
- **Vacuousness proof:** the old test asserted only `STATUS_FAILED`, which the mutation preserves.
- **Restored:** `git checkout app/Services/Anons/AnonsPublishingService.php`.

### Mutation C — victim token rotation on duplicate

- **Temporary change:** `LeadController::store()` duplicate branch —
  `$existing->forceFill(['magnet_token' => 'mutated0tok'])->save();` inserted before the duplicate flash.
- **Expected failing assertion:** `assertSame(self::TOKEN, $victim->fresh()->magnet_token)` —
  observed: `victim bearer token must not be rotated by a duplicate submission / Failed asserting that two strings are identical` → FAILURES (1).
- **Vacuousness proof:** the old test checked flashes and page content only — token rotation changes neither.
- **Restored:** `git checkout app/Http/Controllers/LeadController.php`.

## Final receipt

- `phpunit tests/Feature/CourseInterestTest.php tests/Feature/Anons/SessionHealthProbeTest.php tests/Feature/Sandbox/H5085LeadDuplicateNoTokenTest.php` → OK, **19 tests / 98 assertions** (baseline before hardening: 89 assertions), all mutations restored.
- `pint <the three test files> --test` → passed.
- Scope: test files only — no production seams were needed.

_Гасунс_
