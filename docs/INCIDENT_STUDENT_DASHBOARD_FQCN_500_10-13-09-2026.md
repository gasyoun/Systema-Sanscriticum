_Created: 13-09-2026 · Last updated: 13-09-2026_

# Incident 10–13-09-2026: student cabinet `/dvaram` HTTP 500 — unqualified inline FQCN (`App\Models\Schedule`)

_Sev-1 postmortem (template H4081). Triage + fix + deploy by ox-alpha lane (opencode, `deepseek/deepseek-v4-flash`), 13-09-2026. Fix: [PR #2516](https://github.com/gasyoun/Systema-Sanscriticum/pull/2516) → merge `185bb686`, deployed `b8faa555`._

## Summary

Any student enrolled in a course with a `TextbookScale` family (grammar courses) got **HTTP 500 on the cabinet landing page `/dvaram`** — fatal `Class "App\Http\Controllers\App\Models\Schedule" not found` at [`StudentController.php:349`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/StudentController.php#L349). The public `/login` page itself returned 200; the 500 surfaced immediately after login (redirect `/login` → `/dvaram`), which is why it was reported as «`/login` 500». **9 distinct students, 43 occurrences, ~3 days** undetected.

## Timeline (server-local +0300 = MSK)

| Event | When | Evidence |
|---|---|---|
| Introduced | 2026-09-09 11:08:59 +0300 | commit [`297f8616`](https://github.com/gasyoun/Systema-Sanscriticum/commit/297f8616) (H4435 kanva v1, OxAlpha) |
| **broke_at** (first prod occurrence) | 2026-09-10 (14 occurrences) | `storage/logs/laravel-2026-09-10.log`; 09-09 and earlier = 0 |
| Exposure, undetected | 10-09 → 13-09 | 14 + 13 + 9 + 7 = **43 occurrences**; userIds 6510 (15), 6483 (7), 6379 (5), 5847 (4), 5836 (4), 6097 (3), 6767 (2), 6599 (2), 5862 (1) |
| **detected_at** | 2026-09-13 ≈09:05 +0300 | MG report «https://samskrte.ru/login 500 server error» — **no automated detection** |
| **recovered_at** | 2026-09-13 ≈09:26 +0300 | `deploy.sh` finished `b8faa555`; probe all-OK; **0 occurrences after** |
| Exposure gap (broke → detected) | **~3 days** | the headline number |

## Root cause (system gap — never an actor)

The kanva cursor block (added by H4435) references the model as an **unqualified qualified name** inside a namespaced file:

```php
// StudentController.php (namespace App\Http\Controllers), before
->whereIn('schedule_id', App\Models\Schedule::where(...)->pluck('id'))
```

PHP resolves a relative qualified name against the **current namespace**, so the class looked up was `App\Http\Controllers\App\Models\Schedule`. A `use App\Models\Schedule;` import does **not** apply — imports bind only the *unqualified* name `Schedule`. The same copy-pasted block in the Filament admin (`namespace App\Filament\Resources`) looked for `App\Filament\Resources\App\Models\Schedule` ([`UserResource.php:665`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/UserResource.php#L665) — latent, would 500 the admin attendance-canvas tab on render).

The **system gaps** that let it happen and let it live 3 days:

1. **No static rule** against unqualified inline FQCNs in namespaced PHP — Semgrep, Pint, CodeQL, PHPStan and the PHP 8.3 suite were **all green on the broken commit** (`297f8616` passed CI).
2. **No test renders the kanva branch** — the feature suite never renders `/dvaram` for a student enrolled in a family course *with attendance facts*, so the fatal line was never executed in CI.
3. **The prod probe renders the surface but not the branch** — `cabinet_probe.student_surfaces` includes `student.dashboard` as **critical** and `TEST_STUDENT_*` is configured, yet the smoke student's enrollments never enter the `TextbookScale::courseFamilyPublic()` branch, so `cabinet:probe` stayed green through all 3 days. Empirically proven: probe green ↔ 9 real students 500.

## Detection

**None automated.** Found only because a human hit the page and reported it. `/login` 200 masked the outage from public smoke; the cabinet probe rendered a user who skips the broken branch; no watcher counts 500s by route. The 500-class log line was sitting in `laravel-YYYY-MM-DD.log` for three days with nobody reading it.

## AI involvement (fact, never fault)

| Lane / agent | Model | What it changed |
|---|---|---|
| Introduction — [H4435](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H4435-OxAlpha_Systema-Sanscriticum_kanva-v1-textbook-scale_09.09.26.md) kanva v1 | OxAlpha (opencode, `zai-coding-plan/glm-5.3-flash`) | wrote the kanva cursor block with the unqualified FQCN; CI green on `297f8616` |
| Triage + fix + deploy (this session) | ox-alpha (opencode, `deepseek/deepseek-v4-flash`) | found the fatal in the log, classified, fixed both instances (`StudentController` + `UserResource`), swept H4435-touched files, PR #2516 → merge `185bb686`, `deploy.sh` → `b8faa555`, verified 0 occurrences after |
| CI lanes (all repos' checks) | — | passed on the broken commit: the gap is the **absence** of a rule, not a lane failure |

Swap test: any executor writing the same block, and any CI suite without an FQCN rule / branch-rendering test, reproduces this exactly → the phrasing points at the system.

## Action items (each carries all five columns)

| Action (system fix) | Owner | Priority | Measurable end state | Tracking row |
|---|---|---|---|---|
| Feature test: render `/dvaram` as a student enrolled in a TextbookScale-family course **with ≥1 attendance fact** (executes the kanva cursor branch) — must fail on `297f8616` | OxAlpha lane | **P0** | test red on the un-fixed line, green on `main`; `php artisan test --filter` covers it in CI | GTD `@DO` + [H4641](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4641-OxAlpha_Systema-Sanscriticum_kanva-branch-guard-gap-test-probe-semgrep_13.09.26.md) |
| Probe coverage: make the `cabinet_probe` smoke student enter the kanva family branch (family-course enrollment + one attendance fact), so a dashboard fatal flips the critical surface | OxAlpha lane | **P0** | with the bug re-injected, `cabinet:probe` turns critical on `student.dashboard` within one run | [H4641](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4641-OxAlpha_Systema-Sanscriticum_kanva-branch-guard-gap-test-probe-semgrep_13.09.26.md) |
| Static rule: Semgrep/PHPStan ban on unqualified inline `App\…` class references in namespaced PHP files + **repo-wide sweep** for existing instances (only H4435-touched files were swept in the SOS pass) | OxAlpha lane | **P1** | rule fails CI on a planted unqualified FQCN; sweep report lists 0 remaining | [H4641](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4641-OxAlpha_Systema-Sanscriticum_kanva-branch-guard-gap-test-probe-semgrep_13.09.26.md) |
| Watcher: alert on 500-class log lines by route (a 3-day 500 stream should not need a human report) | OxAlpha lane | **P1** | any route 500ing ≥N times/h pings TG | GTD `@DO` (row 13-09) |

**Residuals minted in the same pass** (not this incident's action items): `telegram-harvest:sync` root-owned-files refusal (chown applied live 13-09, verify next scheduled run) · `support:geo-update-maxmind` exit 1 (cause unlogged, needs its own look).

## Blameless-language checklist

- [x] No person or lane named as cause — cause phrases name a missing guard/rule/default
- [x] Swap test passes (any executor + any CI suite without the rule reproduces it)
- [x] Every cause answers "why did no guard catch it" — the guard gaps **are** the findings
- [x] AI involvement recorded as fact (lanes, models, what each changed)
- [x] Action items are system fixes (tests/probes/rules/watchers), never "be more careful"

_Dr. Mārcis Gasūns_
