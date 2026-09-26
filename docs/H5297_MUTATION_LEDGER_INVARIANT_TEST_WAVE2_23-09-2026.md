# H5297 — Invariant mutation testing wave 2 — mutation ledger

_Created: 23-09-2026 · Last updated: 23-09-2026_

Wave 2 of the bounded mutation-testing discipline started in
[Systema PR #2683](https://github.com/gasyoun/Systema-Sanscriticum/pull/2683) (H5093,
trust-boundary contract matrix) and
[PR #2684](https://github.com/gasyoun/Systema-Sanscriticum/pull/2684) (H5094,
CSP/reauth/duplicate-token pilot). Three new seams, none reusing those PRs' examples
(CSP `frame-ancestors`, reauth journal, duplicate-token, FormulaGuard, duplicate flash,
payment-delete).

## Seam census

| # | Class | Boundary | Prior coverage |
|---|---|---|---|
| A | Authorization/destructive-lifecycle | `GatedAssetController` (H3308) — lesson transcript/material/homework files gated by `LessonGate::canWatch()`, the same chain as the video player | **None** — `grep -rl GatedAssetController tests/` found zero test files before this handoff |
| B | Capability/secret exposure | `VerifyMaxMagnetWebhook` — path-carried webhook secret (`max_webhook_secret`, `hash_equals`) gating `ProcessMaxMagnetUpdate` dispatch | `tests/Feature/Webhooks/MaxMagnetWebhookTest.php::wrong_secret_in_url_returns_403` existed but asserted **status code only** |
| C | Output-encoding/browser-policy | `partials/impersonation-banner.blade.php` — `users.name` (no HTML allowlist) spliced raw into every layout's `</body>` by `ImpersonationGuard::injectBanner()` | `StaffImpersonationTest::the_banner_is_injected_into_rendered_pages` existed but asserted `assertSee($student->name)` on a **plain** name — proves nothing about escaping |

## Invariant table

| # | Result | Forbidden side effect | Persisted state |
|---|---|---|---|
| A | Non-entitled authenticated user → 404 on `/c/{slug}/u/{id}/transcript` | Response body never carries the transcript's file content, even transiently | No `lesson_access_grants` row created by the request |
| B | Wrong path secret → HTTP 403 | `ProcessMaxMagnetUpdate` job is **never** dispatched, independent of the response status | (queue-only side effect; no DB row) |
| C | A name containing `<script>…</script>"…"` renders on the page | The raw `<script>…</script>` string never appears unescaped in the response body | (render-only; no DB row) |

## Mutation receipts (planted in a worktree, never committed — net diff vs `origin/main` is test-only)

| Seam | Planted mutation | Old/current assertion | RED receipt | Restored |
|---|---|---|---|---|
| A | `GatedAssetController::resolveAccessible()` — `abort_unless(app(LessonGate::class)->canWatch($user, $lesson), 404)` → gate called but result discarded | No boundary test existed; full suite trivially green under the mutation | New test `a_non_entitled_student_gets_404_and_never_sees_the_transcript_bytes`: `Expected response status code [404] but received 200.` | ✅ green (`git diff origin/main -- app/Http/Controllers/GatedAssetController.php` = 0 lines) |
| B | `VerifyMaxMagnetWebhook::handle()` — verify moved to run **after** `$next($request)` (job dispatches, then 403 is thrown) | `wrong_secret_in_url_returns_403` (`assertStatus(403)` only) **stays green** — status is still 403 | Hardened assertion `Bus::assertNotDispatched(ProcessMaxMagnetUpdate::class)`: `The unexpected [App\Jobs\ProcessMaxMagnetUpdate] job was dispatched. Failed asserting that false is true.` | ✅ green (`git diff origin/main -- app/Http/Middleware/VerifyMaxMagnetWebhook.php` = 0 lines) |
| C | `impersonation-banner.blade.php` — `{{ $actingAs?->name ?? '...' }}` → `{!! $actingAs?->name ?? '...' !!}` | `the_banner_is_injected_into_rendered_pages` (plain name, `assertSee($student->name)`) **stays green** — 1 failure out of 19, and it is not this test | New test `the_banner_html_escapes_a_name_with_markup_characters`: `assertDontSee('<script>alert(1)</script>', false)` fails — raw script tag present in the response body | ✅ green (`git diff origin/main -- resources/views/partials/impersonation-banner.blade.php` = 0 lines) |

No mutation exposed a real production defect — all three boundaries were already correctly gated; the gap in every case was in the **test**, not the code. No production repair was needed or made.

## Checks (local sqlite `:memory:`, PHP 8.5.9)

```
php -d memory_limit=1G vendor/bin/phpunit --filter=H5297GatedAssetTranscriptFenceTest
# 2 / 2 PASS, 5 assertions

php -d memory_limit=1G vendor/bin/phpunit --filter=MaxMagnetWebhookTest
# 5 / 5 PASS, 8 assertions

php -d memory_limit=1G vendor/bin/phpunit --filter=StaffImpersonationTest
# 19 / 19 PASS, 116 assertions

vendor/bin/pint --test tests/Feature/Sandbox/H5297GatedAssetTranscriptFenceTest.php \
  tests/Feature/Webhooks/MaxMagnetWebhookTest.php tests/Feature/StaffImpersonationTest.php
```

## Risks

New assertions are deliberately implementation-coupled at the response/state boundary
(exact status/dispatch/escaping), not at internals — same posture as H5093/H5094.
Seam B and C both harden an **existing** test in place rather than adding a parallel one,
matching the "replace vacuous assertions" method of H5094.

_Гасунс_
