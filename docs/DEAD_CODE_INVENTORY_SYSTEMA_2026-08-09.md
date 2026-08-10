# Dead-code inventory — Systema-Sanscriticum (Laravel 12 / PHP 8.3)

_Created: 09-08-2026 · Last updated: 10-08-2026_

Report-only deliverable. Nothing was removed, nothing was edited outside this file and its
sibling metadoc. Every row below is a recommendation for a human, not an applied change.

## 1. Executive summary

**41 confirmed-dead items totalling ~8,700 LOC survived adversarial verification across
2,431 scanned files in 13 subsystems** — but 6,819 of those lines (78.4%) are nine
superseded Jinja templates in one vendored directory tree, so the honest figure for
*application* dead code is **~1,881 LOC of PHP, Blade, Python and config across 37 items**. The
concentration is: [lecture-ui](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui) (5 items, 6,928 LOC — a single wholesale vendor
drop from 24-04-2026 that was cherry-picked twice and never revisited),
[app/Console/Commands](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands) (5 items, 781 LOC — two finished migration
campaigns, Curator media in March and payments/courses CSV import in May),
[app/Services](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services) (11 items, 311 LOC — all method-granularity; **zero service
classes are unreferenced**), [config](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config) (8 dead keys, 77 LOC) and
[resources/views](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views) (4 unreachable blades, 166 LOC). Three subsystems came
back completely clean: [app/Filament](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament) (270 files), [app/Models](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models)
(126 files, no dead tables, no dead accessors) and dependency manifests (46 declared
packages, all load-bearing).

The single highest-value cleanup is
**[lecture-ui/templates/Old/](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/templates/Old) plus the three superseded root
templates** — 6,819 lines, trivial risk, reachable only by a hand-typed
`build.py --template <name>` that no script, CI job or doc ever passes. Second-highest is the
two abandoned Curator media-migration commands
([MigrateMediaToCurator](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MigrateMediaToCurator.php) +
[MigrateBuilderMedia](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MigrateBuilderMedia.php), 311 LOC): re-running
either would now *corrupt* live landing-page blocks, so they are worse than inert. A
cross-cutting defect worth one fix pass independent of any deletion:
[app/Console/Commands/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/README.md) documents 7 commands under
signatures that exist in zero command files, so every documented invocation line is
copy-paste-broken.

## 2. Coverage and verdicts by subsystem

| Subsystem | Files scanned | Confirmed dead | Partial | Uncertain | False positives |
|---|---|---|---|---|---|
| [app/Services](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services) | 210 | 11 | 1 | 0 | 0 |
| [app/Filament](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament) | 270 | 0 | 0 | 0 | 0 |
| [app/Console](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console) | 132 | 5 | 0 | 2 | 3 |
| [app/Models](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models) | 126 | 0 | 1 | 0 | 0 |
| [app/Http](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http) | 102 | 1 | 0 | 0 | 0 |
| [app/Support](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support) + [app/Enums](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Enums) | 55 | 1 | 0 | 0 | 0 |
| Jobs · Mail · Listeners · Events · Observers | 87 | 4 | 0 | 0 | 3 |
| [resources/views](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views) | 329 (270 audited, 58 vendor overrides excluded) | 4 | 0 | 0 | 0 |
| Frontend ([resources/js](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/js), [lecture-ui](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui), [lecture-builder](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-builder), [mobile](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/mobile)) | 257 | 5 | 1 | 0 | 1 |
| [scripts](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts) + [tools](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tools) | 44 | 1 | 0 | 0 | 0 |
| [routes](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes) + [config](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config) | 77 | 8 | 0 | 0 | 0 |
| [database](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database) | 739 | 1 | 0 | 0 | 0 |
| Dependencies ([composer.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/composer.json), [package.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/package.json), [mobile/package.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/mobile/package.json)) | 3 manifests / 46 packages | 0 | 0 | 0 | 0 |
| **Total** | **2,431** | **41** | **3** | **2** | **7** |

Nine further unverified leads are carried in section 5.3 — they are leads, not findings.

## 3. Confirmed dead — ranked by LOC recovered × low removal risk

Trivial risk first, then moderate, then risky; within each band by LOC descending.

### 3.1 Trivial risk

| Path | Symbol | Kind | LOC | Risk | Recommendation |
|---|---|---|---|---|---|
| [lecture-ui/templates/Old/](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/templates/Old) | template0/1/3/4/5/6.html.j2 (whole dir) | unreachable-file | 3,219 | trivial | Delete the directory. No loader enumerates it, no Jinja `extends`/`include` exists anywhere in [lecture-ui/templates](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/templates), and `Old/template1` and `Old/template3` are byte-identical snapshots. |
| [lecture-ui/templates/template.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/templates/template.html) | whole file | superseded-duplicate | 1,287 | trivial | Delete, or `git mv` into `Old/`. It is a 4-line CSS variant of `template2.html.j2` with the `.j2` suffix dropped; the bare name appears nowhere in the repo. |
| [lecture-ui/templates/template2.html.j2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/templates/template2.html.j2) | whole file | unreachable-file | 1,287 | trivial | `git mv` into `Old/` (repo's own convention for superseded numbered drafts). Discarded sticky-header A/B experiment; 66 lines behind the live template. |
| [lecture-ui/templates/template1.html.j2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/templates/template1.html.j2) | whole file | unreachable-file | 1,026 | trivial | Archive into `Old/` under a non-colliding name (`Old/template1.html.j2` already exists with different content). Still renders cleanly, so archive beats delete. |
| [app/Console/Commands/MigrateBuilderMedia.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MigrateBuilderMedia.php) | `app:migrate-builder` | orphaned-script | 190 | trivial | Delete with its stale [README](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/README.md) rows 36–40 (which document a signature that never existed). Do **not** archive: re-running it now writes numeric Curator IDs into fields the live editor still serves as raw-path `FileUpload`. |
| [app/Console/Commands/MigrateMediaToCurator.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MigrateMediaToCurator.php) | `app:migrate-media` | orphaned-script | 121 | trivial | Delete plus README rows 29–33. `image_id`, the column it backfills, is written by this file and read by nothing; the Curator column migration was abandoned, not completed. |
| [database/seeders/CourseLandingDemoSeeder.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/CourseLandingDemoSeeder.php) | `CourseLandingDemoSeeder` | orphaned-script | 120 | trivial | **Do not delete on grep-emptiness.** Wire it into [.claude/skills/blade-styling/SKILL.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/claude/skills/blade-styling/SKILL.md) as the named fixture for the reference landing page, or get a ruling that the demo course is unwanted. Playwright MCP (its documented consumer) is live. |
| [lecture-ui/editor_server.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/editor_server.py) | whole file (Flask editor, `apply_patch`) | superseded-duplicate | 109 | trivial | Delete plus [lecture-ui/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/README.md) rows 34 and 70. Superseded by [app/Services/Lecture/LecturePatcher.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Lecture/LecturePatcher.php), whose docblock says it deliberately never calls Python. |
| [resources/views/student/certificate_pdf.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/certificate_pdf.blade.php) | whole file | superseded-duplicate | 105 | trivial | Delete plus the stale row at [resources/views/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/README.md):22. The live sink is a hardcoded `Pdf::loadView('certificates.default')`. |
| [resources/views/partials/pagination.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/pagination.blade.php) | whole file | unreachable-file | 56 | trivial | Delete. Its only caller was removed 30-04-2026 when the shop listing became a Livewire component; no `links()` call anywhere passes a view name. |
| [tools/order_pay_conversion_baseline.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tools/order_pay_conversion_baseline.php) | whole file | superseded-duplicate | 54 | trivial | Delete ([tools](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tools) becomes empty) and rewrite the stale path in [docs/ROADMAP_Systema_NOBORING_DOZHIM_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_Systema_NOBORING_DOZHIM_2026H2.md):20 to the artisan form. 54 lines of argv pass-through to a live command. |
| [app/Services/DebtorsReport.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtorsReport.php) | `preloadPromises()` | unused-method | 30 | trivial | **Wire it in, don't delete** — add it beside the two existing preloaders at lines 746–750; it kills a live per-row N+1 at [app/Filament/Pages/Debtors.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Debtors.php):370/379/387. |
| [config/analytics.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/analytics.php) | `funnel_events` | unused-config-key | 26 | trivial | Keep the file (its `metrika.*` keys are live and feed the env inventory). Either read `analytics.funnel_events` from the funnel services, or move the block into [docs/FUNNEL_MEASUREMENT_MAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FUNNEL_MEASUREMENT_MAP_2026.md). |
| [app/Http/Middleware/TrustHosts.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/TrustHosts.php) | whole file | unused-class | 21 | trivial | Safe to delete with [app/Http/Middleware/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/README.md):25 and the commented [Kernel.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Kernel.php):58 line — or keep as stock-skeleton parity for future Laravel upgrades. Low value either way. |
| [app/Services/Payments/PaypalSubscriptionsService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Payments/PaypalSubscriptionsService.php) | `createSandboxPlanPlaceholder()` | unused-method | 12 | trivial | Delete lines 59–70. Body is an unconditional `throw`, so even manual tinker use is impossible; the Phase 1 it reserved shipped without it and P2–P3 were product-parked 02-08-2026. |
| [app/Services/PaymentImportService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/PaymentImportService.php) | `readPreview()` | unused-method | 11 | trivial | Delete lines 64–74 and, same commit, the now-false "Режим preview" bullet at [app/Services/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/README.md):35. There is no `dryRun` parameter on `import()` — preview mode has no implementation at all. |
| [app/Services/Lecture/LectureBuilderClient.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Lecture/LectureBuilderClient.php) | `isHealthy()` | unused-method | 11 | trivial | Either wire into [ProbeCabinetHealth](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ProbeCabinetHealth.php) as a soft check or delete the 11 lines and trim the `/health` mention at [app/Services/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/README.md):61. A human should decide. |
| [app/Services/Promises/PaymentPromiseSuggestionService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Promises/PaymentPromiseSuggestionService.php) | `conditionalPaymentCount()` | unused-method | 9 | trivial | Better than deleting: call it from [tests/Feature/Promises/PaymentPromiseSuggestionApprovalTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Promises/PaymentPromiseSuggestionApprovalTest.php):78 in place of the duplicated inline query, which is what its docblock always intended. |
| [app/Services/AttendanceNoticeService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/AttendanceNoticeService.php) | `forScheduleKeyedByUser()` | unused-method | 8 | trivial | Dedupe instead of deleting: point [app/Services/ClassAttendanceService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/ClassAttendanceService.php):40-43 at it — one line makes it live and removes a duplicated query. |
| [app/Services/AttendanceNoticeService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/AttendanceNoticeService.php) | `forUserSchedule()` | unused-method | 7 | trivial | Treat as refactor, not delete: the H2317 owner should decide whether this stays the single-notice read API. The feature is 3 days old and still flag-OFF. |
| [config/attendance.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/attendance.php) | `notice_labels` | dead-on-arrival-duplicate | 6 | trivial | Delete lines 28–37. Labels are hardcoded in three divergent places; note two grammatical registers are in play (first-person student picker vs third-person staff view), so *centralising* them later is not mechanical. |
| [app/Services/Cabinet/LapseDetector.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Cabinet/LapseDetector.php) | `isLapsed()` | unused-method | 5 | trivial | Leave in place with a `@internal`/TODO note pointing at the Phase 3 consumer. Two-week-old affordance of the in-flight flag-gated `cabinet_hybrid` feature; 5 lines is not worth the churn. |
| [resources/views/partials/tariff-key.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/tariff-key.blade.php) | whole file | unreachable-file | 5 | trivial | Delete. The same ternary is inlined at [resources/views/shop/show.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/show.blade.php):636 and canonically implemented in `Tariff::accessKey()`; optionally collapse the inline copy onto the model method. |
| [app/Services/Srs/ReviewService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Srs/ReviewService.php) | `dueCount()` | unused-method | 4 | trivial | Safe to delete; equally defensible to keep as a public convenience API. It is a 3-line wrapper that [app/Livewire/SrsReview.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Livewire/SrsReview.php) already inlines at lines 493/539. |
| [config/cabinet_probe.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/cabinet_probe.php) | `telegram_soft_cooldown_minutes` | superseded-duplicate | 4 | trivial | Delete lines 31–39, re-run [scripts/generate_env_inventory.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/generate_env_inventory.php), and drop the operator-facing mentions at [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md):109 and [docs/UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md):78 that still advertise it as a live tunable. |
| [config/start_chteniya.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/start_chteniya.php) | `entitlement_key`, `price_band_eur_min/max` | unused-config-key | 3 | trivial | Do not bare-delete: fold the entitlement-key name into `StartChteniyaCohort`'s docblock beside the `features.start_chteniya_cohort` read, demote the price band to a comment. Keep `course_slug` (line 17). |
| [config/attendance.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/attendance.php) | `trend_weeks` | unused-config-key | 2 | trivial | Either wire it (cap the weekly trend in `ClassAttendanceService::dashboard()`, mirroring the live `conversion.trend_weeks`) or delete line 23 and regenerate the env inventory. Reads as unfinished, not cruft. |
| [config/cabinet_probe.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/cabinet_probe.php) | `cron` | unused-config-key | 1 | trivial | Delete line 45 **and** [.env.example](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/env.example):144 in one pass, then regenerate the env inventory or the `env-inventory` CI check goes red. Its only reader was removed 30-07-2026. |
| [resources/views/layouts/partials/nav-links.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/partials/nav-links.blade.php) | whole file | unreachable-file | not measured | trivial | Delete; the then-empty [resources/views/layouts/partials](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/partials) goes with it. The string `nav-links` has never appeared in any file's content in the entire git history, and the compiled Blade cache has never rendered it while its siblings have. |

### 3.2 Moderate risk

| Path | Symbol | Kind | LOC | Risk | Recommendation |
|---|---|---|---|---|---|
| [app/Console/Commands/ImportArticlesFromHtml.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportArticlesFromHtml.php) | `articles:import` | orphaned-script | 294 | moderate | Delete with README rows 22–27 and the two consumed inputs in [public/articles](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/articles) — **but first confirm in prod that both articles exist as DB rows**, since no seeder reproduces them and the HTML would be the only copy. |
| [app/Services/Nlp/CascadeLemmatizer.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Nlp/CascadeLemmatizer.php) | `lemmatize()` | test-only | 145 | moderate | Keep. Roadmap-staged v0 whose consumer (`/lemmatize`) is planned at [docs/ROADMAP_SANSKRIT_HUB_NLP_2026_2028.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SANSKRIT_HUB_NLP_2026_2028.md):47. Any removal must also drop [resources/data/dcs_form2lemma.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/dcs_form2lemma.json), its co-dependent orphan. |
| [app/Console/Commands/PostTeacherPayouts.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/PostTeacherPayouts.php) | `salary:post-payouts` | test-only | 78 | moderate | Keep and document in [app/Console/Commands/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/README.md) as a manual idempotent backfill. Its service `TeacherPayoutPoster` is live in five Filament call sites; only the CLI wrapper is unreferenced. Do not schedule it. |
| [app/Support/TochkaRecurring.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/TochkaRecurring.php) | whole file | test-only | 56 | moderate | Keep. It is the gate H2026 Phase 1 is specified to switch on; removal would also strip [config/features.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php):704,711 and the unit test. Revisit only if Phase 1 is abandoned. |
| [app/Mail/MarathonDay3Mail.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/MarathonDay3Mail.php) | `MarathonDay3Mail` | test-only | 50 | moderate | Do not delete — deliberately parked ruled copy (H1067) behind the ESP gate (H1147). Re-audit the whole Marathon + Onboarding Mailable cluster together. |
| [app/Mail/OnboardingDay5Mail.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/OnboardingDay5Mail.php) | `OnboardingDay5Mail` | test-only | 50 | moderate | Do not delete — same parked cohort, blocked on prod-SMTP issue #504. |
| [app/Mail/MarathonDay1Mail.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/MarathonDay1Mail.php) | `MarathonDay1Mail` | test-only | 41 | moderate | Do not delete. Its view [resources/views/emails/marathon/day1.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/marathon/day1.blade.php) is referenced only from this class and is **not** independently dead. |
| [config/billing.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/billing.php) | `yoomoney.*`, `own_kkt.*` | unused-config-key | 22 | moderate | Leave as-is, or move the intent into [docs/TOCHKA_PAYMENT_METHODS_AUDIT_2026-07-31.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TOCHKA_PAYMENT_METHODS_AUDIT_2026-07-31.md) and rewrite all five citations in the same pass. These keys are the artifact the roadmap points at to stop a future session re-deciding YooMoney. |
| [config/support_tech.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/support_tech.php) | `group_peers`, `bot_username` | superseded-duplicate | 13 | moderate | Delete lines 14–21 and 23–28 only, never the file (`keywords` and `assignee_user_id` are live). Keep the [config/services.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/services.php):157-161 keys — that is what the code reads, despite the inverted "канон/зеркало" comment at services.php:152-153, which should be corrected in the same commit. Re-run the env inventory. |

### 3.3 Risky — recommended action is *not* removal

| Path | Symbol | Kind | LOC | Risk | Recommendation |
|---|---|---|---|---|---|
| [app/Console/Commands/NormalizeUserEmails.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/NormalizeUserEmails.php) | `users:normalize-emails` | test-only | 98 | risky | Do not delete. Add a README row documenting it as a manual operator tool; keep the test as the regression guard. It mutates the login identifier and refuses to auto-merge collisions, so staying out of the scheduler is correct design. |
| [app/Services/Webinar/BigBlueButtonService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Webinar/BigBlueButtonService.php) | whole file | test-only | 69 | risky | Keep. Either close GC-B3's three named gaps (a `webinar_provider_abstraction` flag, a `services.bbb` config block, a real consumer of the `WebinarProvider` binding) or delete only after a human formally cancels GC-B3. |
| [app/Mail/MarathonRecordingMail.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/MarathonRecordingMail.php) | `MarathonRecordingMail` | test-only | 45 | risky | Do not delete. Having no send site is the documented design (ESP gate H1147, ruled copy H1067); treat the five `Marathon*Mail` files as one unit. |

## 4. Partially dead

Three items are dead at one granularity and alive at another. None is a delete candidate.

**[app/Services/Webinar/WebinarProvider.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Webinar/WebinarProvider.php) —
`fetchParticipants()` (1 LOC declaration, risky).** Zero production call sites, but the two
implementations ([ZoomService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Zoom/ZoomService.php):170,
[BigBlueButtonService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Webinar/BigBlueButtonService.php):56) are alive-by-contract
and individually undeletable; only the 3-file unit is removable, and that also breaks the seam
test. The seam is half-migrated in the unusual direction: the interface exists and
`ZoomService` implements it, but the only production consumer of participant data,
[SyncZoomAttendance](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SyncZoomAttendance.php):65, calls the concrete class.
Fix by routing that line through `app(WebinarProvider::class)->fetchParticipants()` (2 lines,
pays off GC-B3) or formally park the seam with the owed flag.

**[app/Models/BillingProfile.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/BillingProfile.php) — whole file (45 LOC,
trivial).** Test-only: no production reference, no raw `table('billing_profiles')` access, no
morph map, no Filament resource. Its relation graph is outbound-only — nothing relates *into*
the profile, while its two live siblings wire only to each other. Deliberate Phase 0
scaffolding halted on an external bank confirmation; deletion would also orphan the
`BillingPreferredMode` and `BillingConsolidatedCadence` enums.

**[lecture-ui/output/](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/output) — 27 committed `.html` artifacts + `src/` (risky).**
The HTML pile is write-only from the repo's perspective, and several files are hand-versioned
duplicates (`_corrected2`/`_corrected3` byte-identical; `a.bat` is a one-line copy). But
`output/src/` is a hard-coded **input** of the live templates
([template.html.j2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/templates/template.html.j2):9 emits
`href="./src/style.css?v=1"`), so deleting the directory as one unit breaks styling and slide
images for every future render. Prune only the redundant hand-versioned copies, and only after
a human re-renders one lecture from `data/Готово/` and diffs it against the committed HTML.

## 5. Uncertain, and leads

### 5.1 Uncertain — verifier could not settle

Both are one-shot operator CLI tools from the May-2026 import campaign. For this class of
command, "no in-repo caller" is the *expected* shape, and the production crontab on the box
was not in reach of a read-only repo audit.

| Path | Symbol | LOC | Why unsettled |
|---|---|---|---|
| [app/Console/Commands/SyncCoursesFromAdmin.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SyncCoursesFromAdmin.php) | `courses:sync-from-admin` | 130 | Appears superseded — title canonicalisation now happens at import time via [app/Support/CourseNameResolver.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/CourseNameResolver.php) + [database/data/course_aliases.csv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/data/course_aliases.csv), consumed by three live importers. Needs an ops confirmation that the admin-CSV step is retired, then retire it with `BuildCanonicalCsv`. |
| [app/Console/Commands/BuildCanonicalCsv.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/BuildCanonicalCsv.php) | `import:build-canonical` | 97 | Its four campaign siblings have the identical zero-reference profile, and nobody would call the working import pipeline dead. Redundant (importers normalise inline) but not unreachable; survives as a dry-run review capability. Ask the author, then either document it in the README beside `import:academy` or retire the whole four-command cohort as one decision. |

### 5.2 Cross-cutting defect found while auditing (not a removal candidate)

[app/Console/Commands/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/README.md) documents seven commands
under signatures defined in zero command files: `archives:clean` (real `archives:cleanup`),
`academy:import` (real `import:academy`), `articles:import-html` (real `articles:import`),
`media:migrate-to-curator` (real `app:migrate-media`), `media:migrate-builder` (real
`app:migrate-builder`), `lessons:sync-materials` (real `materials:sync`),
`payments:debug-skips` (real `debug:payment-skips`). Its Schedule section is also stale — it
lists two rows against 57 real ones in [app/Console/Kernel.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php), and
gives `archives:cleanup` as hourly when the Kernel has it `dailyAt 03:00`. Worth one fix pass
regardless of any deletion, because several candidates *look* documented and are not.

Two more observations that are not dead code:
[scripts/server_guards/sbin/test_systema_auto_deploy_run.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/test_systema_auto_deploy_run.sh)
pins the H2149 auto-deploy-breaker regression but no workflow runs it, while its twin has a
dedicated one; and [scripts/mobile_viewport_audit.mjs](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/mobile_viewport_audit.mjs)
imports `playwright`, which is in neither [package.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/package.json) nor the lockfile, so it
cannot run from a clean `npm ci`.

### 5.3 Low-confidence leads — never verified

Carry as leads only. Each needs the same adversarial pass the confirmed rows received.

| Path | Symbol | Kind |
|---|---|---|
| [app/Console/Commands/DebugPaymentSkips.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/DebugPaymentSkips.php) | `debug:payment-skips` | orphaned-script |
| [app/Console/Commands/SyncLessonMaterials.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SyncLessonMaterials.php) | `materials:sync` | orphaned-script |
| [app/Console/Commands/SeedHistoricalCourses.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SeedHistoricalCourses.php) | `courses:seed-historical` | orphaned-script |
| [lecture-ui/makejson.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/makejson.py) | v1 text↔JSON converter | superseded-duplicate |
| [lecture-ui/json_to_txt.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/json_to_txt.py) | reverse JSON→text | orphaned-script |
| [lecture-ui/make_text_from_json_dg.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/make_text_from_json_dg.py) | readable-text exporter | orphaned-script |
| [lecture-ui/extract.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/extract.py) | section extractor | orphaned-script |
| [lecture-ui/pdf_to_jpg.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/pdf_to_jpg.py) | PDF→JPG (duplicated inside [lecture-builder/pipeline.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-builder/pipeline.py):29) | superseded-duplicate |
| [scripts/changelog_compare_links.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/changelog_compare_links.py) | changelog link guard with no invoker (149 version sections vs 113 link lines — the drift it prevents has resumed) | orphaned-script |

The five `lecture-ui` Python leads are graded low on purpose: that directory is a human-run
CLI toolkit whose own README documents every script as a live tool, so "no inbound code
reference" is the expected state, not evidence of death. This is the single biggest source of
false-positive risk in the whole audit.

## 6. False positives — why they looked dead

Load-bearing section. Each row names the trap that fooled a grep-based scan, so the next
session does not re-chase it.

| Item | Trap |
|---|---|
| [app/Console/Commands/ImportCourseBlocksFromCsv.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportCourseBlocksFromCsv.php) | **Operator-CLI family precedent.** Zero references is the *designed* state for hand-run import commands: all four campaign siblings score zero too, and one of them is explicitly documented as "не запускается по расписанию". Its data feeds the live [DebtorsReport](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtorsReport.php):46. |
| [app/Console/Commands/BackfillCourseEnrollments.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/BackfillCourseEnrollments.php) | **Artisan auto-registration.** [Kernel.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php):655 does `$this->load(__DIR__.'/Commands')`, so every command in the directory is invokable with no static reference — the Artisan analogue of Filament discovery. A one-shot backfill with only a test caller is correctly built, not abandoned. |
| [app/Console/Commands/PurgeBotSubscribers.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/PurgeBotSubscribers.php) | Same auto-registration trap, plus **deliberate non-scheduling**: a `--force` path that deletes users *should not* be in `schedule()`. Absence from the scheduler is the design. |
| [app/Mail/MarathonWelcomeMail.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/MarathonWelcomeMail.php) | **Pending-activation behind a named human gate.** [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md):228 records the five Marathon Mailables as merged with sending deliberately unconnected; row 37 states the reverse dependency. A merged deploy-queue row is a live owner, not decay. |
| [app/Mail/MarathonDay2Mail.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/MarathonDay2Mail.php) | **Callerless-by-acceptance-criterion.** [docs/VERIFICATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md):322 ships a grep whose *pass* condition is zero send sites outside [app/Mail](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail). Absence of callers is the contract. |
| [app/Mail/OnboardingDay1Mail.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/OnboardingDay1Mail.php) | Same gated-scaffolding trap, gate = prod-SMTP issue #504. Also **closed-allowlist dispatch**: the repo's only dynamic Mailable factory, [app/Services/Leads/LeadStepMailer.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Leads/LeadStepMailer.php):69, is an exhaustive `match` over two Lead steps with `default => null`, so no marathon/onboarding Mailable can ever be reached dynamically. |
| [lecture-ui/build2.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/build2.py) | **Dependency direction read backwards.** An empirical render proved `build.py` + the default template *crashes* (`No filter named 'seconds_to_ts'`) while `build2.py` succeeds — `build2.py` is the only functional renderer, and the committed artifacts carry its output naming and anchor count. `build.py` is the vestigial half. |

Traps that produced no findings because they were ruled out wholesale — worth reusing:

> **Filament auto-discovery.** Both panel providers call `discoverResources`/`discoverPages`/
> `discoverClusters`/`discoverWidgets`, so 270 files under [app/Filament](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament) are
> loaded by convention with zero static references. Thirteen services
> (`DisciplineScoreService`, `StuckStudentsReport`, `WorkQueueReport`, `ReactivationReport`,
> `InvestmentModelService`, `DelegationKpiService`, `GoalCheckinService`, `ProfitFundsService`,
> `MutualSettlementService`, `TeacherAccountService`, `PaymentPromiseSuggestionService`,
> `SupportDashboardPacketBuilder`, `Discipline/DisciplineScore`) look dead to a class-name grep
> and are not.
>
> **Artisan signature strings.** Grep the `$signature`, never the class name: nine services
> are kept alive only through a signature string (`MarathonDay1Sender`, `WikidataSameAsMatcher`,
> `CsrfMismatchDigestService`, `MissingMilestoneCourseDetector`, `ContentMonthSeeder`,
> `VkOrsImporter`, `SalesFunnelReportService`, `TelegramWebhookRegistry`,
> `PaymentPromiseDeferralDetector`).
>
> **Eloquent table-name convention.** No model in this codebase sets `protected $table`, so
> all 24 tables with zero string-literal hits are reached by convention alone — that single
> trap would have produced 24 false "dead table" findings.
>
> **`hasColumn`-guarded migrations.** Eleven models were flagged for `$fillable` columns with
> no `Schema::create`; every one is added by a later migration wrapped in
> `if (! Schema::hasColumn(...))`.
>
> **Wholesale config reads.** `config('x', [])` followed by `foreach` keeps entire subtrees
> alive with no per-key literal: `profit_funds.funds.*`, `marathon_landing_copy.*`,
> `storage_watch.watched.*`, `onramp.recommendations.*`.
>
> **Generated env inventory.** 327 of 518 `env()` keys are absent from
> [.env.example](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/env.example) yet all appear in
> [docs/ENVIRONMENT_VARIABLES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ENVIRONMENT_VARIABLES.md), because that file is
> generated from [config](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config) by
> [scripts/generate_env_inventory.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/generate_env_inventory.php) and enforced in CI.
> `.env.example` is a starter template, not a completeness contract — reporting those 327
> would have been a mass false positive. Corollary: deleting a config key without regenerating
> the inventory turns CI red.
>
> **Runtime-transitive dependencies.** `symfony/css-selector`, `symfony/http-client` and
> `guzzlehttp/guzzle` have zero direct imports and are load-bearing (Crawler CSS mode, the
> Mailgun/Postmark transports, the `Http` facade). Check config *driver strings*, not just
> class names.
>
> **Worktree contamination.** A repo-wide `grep -rn` walks `.claude/worktrees/`, which holds
> full sibling checkouts of the same code. Any "reference found" under that path is an
> artifact and any count taken from it is inflated. Use `git grep`. Likewise `.repowise/`
> (knowledge-graph.json, `*.pkl`, `wiki.db`) is a static-analysis index: its `contains` edges
> are declarations, not call sites.
>
> **Compiled Blade.** [storage/framework/views](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/storage/framework/views) is generated output
> and must be excluded as a liveness signal — though its *absence* of a compiled artifact is
> useful negative evidence, and was decisive for two view findings.

## 7. Non-goals and do-not-touch

- **Migrations are history and are never deleted.** All 359 under
  [database/migrations](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations) stay, including three inert no-op duplicates
  (two same-named `add_notes_to_lesson_user_table` files and one
  `add_attachments_to_lessons_table`) — they add no column, create no table, and leave no
  unused table behind. `zapisi_class_schedules` was already correctly dropped with a
  reversible `down()`; no action is owed.
- **Filament files are auto-discovered.** Nothing under [app/Filament](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament) may be
  called dead on grep-silence. The one structural lead in that tree
  ([LectureProcessingWidget](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Editor/Widgets/LectureProcessingWidget.php), in a
  directory covered by no `discoverWidgets()` call) resolved to an explicit page-level widget
  array and is live. Structural note for a human, not a finding: because
  `LectureEditorPanelProvider` has no `discoverWidgets()` call, any *future* widget added to
  that directory would be unreachable unless a page lists it explicitly.
- **Anything behind a live feature flag is dark-by-design, not dead**: `features.rq4_study`,
  `PAYPAL_SUBSCRIPTIONS_ENABLED`, `srs.enabled`, `features.games_skill_drills`,
  `ATTENDANCE_NOTICES`, `cabinet_hybrid`, `features.start_chteniya_cohort`,
  `features.tochka_recurring`. Controllers behind a dark flag self-404; they are not
  unreachable.
- **Test-only is a weaker class than unreachable.** Reported separately throughout, and in
  nearly every case the recommendation is *not* removal. Factories are excluded entirely: all
  18 are referenced only by tests, which is their purpose. Deliberate test seams are excluded
  too — `TelegramHarvestSyncService::setStoreWriter()` (a DI injection point) and
  `AcceptingPaypalWebhookSignatureVerifier` (a documented test double bound by two tests).
- **The whole Mail cluster stays**: five `Marathon*Mail` + two `Onboarding*Mail` are parked on
  the ESP gate (H1147 / issue #504) with editorially frozen copy (H1067), asserted verbatim by
  tests. Deleting them discards ~313 lines of ruled copy plus ~238 lines of Blade. The correct
  human action is to check whether the ESP decision is still open.
- **Eight further test-only service methods are observed but not reported as findings** —
  a weaker class deserving its own pass: `RecoveryState::ownedAccessKeepsWorking()`,
  `ArticleDraftGenerator::toMarkdown()`, `ForwardDraftGenerator::kinds()`,
  `GroupMembershipManager::syncForCourse()`, `MilestoneCertificateIssuer::dueMilestones()` and
  `::hasPaidMilestone()`, `OrderPaymentConversionService::unclosedCount()`,
  `TeacherSalaryService::directReceiptsTotal()`. `syncForCourse()` is the most notable, since
  group-membership sync is a live subsystem.
- **Enum cases were not swept one-by-one.** All 10 enums are live at class level (real model
  casts), but a single declared-and-never-constructed case inside a live enum would not have
  been caught. This is the most likely remaining miss in
  [app/Support](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support) + [app/Enums](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Enums).
- **[lecture-ui](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui) data is out of scope**: 200+ transcription/timecode/txt/pdf/jpg
  files are course source material, and `lecture-ui/Не_актуальны/` already self-declares as
  deprecated.

## 8. Suggested order of operations for a removal pass

531 test files exist, so batches should be small enough that a failure localises. Run the
full suite (`php artisan test --parallel`, wired in
[.github/workflows/ci.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/github/workflows/ci.yml):85) after each batch.

1. **Batch 0 — documentation only, zero code risk.** Fix the seven broken signatures and the
   stale Schedule section in [app/Console/Commands/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/README.md);
   drop the three phantom rows in [resources/views/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/README.md); add
   the missing README rows for `users:normalize-emails`, `newsletter:purge-bots`,
   `salary:post-payouts` and the four campaign import commands. Do this first: it removes the
   false "documented, therefore live" signal that several later batches depend on.
2. **Batch 1 — [lecture-ui](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui) templates (6,819 LOC, one commit).** Delete
   `templates/Old/`, `git mv` `template1.html.j2`/`template2.html.j2`/`template.html` into it.
   No PHP test touches these; the one Python test pins `template.html.j2` by name. This is
   98.6% of the recoverable volume and the cheapest batch in the whole list.
3. **Batch 2 — dead config keys (77 LOC).** All eight rows from section 3, then re-run
   `php scripts/generate_env_inventory.php` **in the same commit** and delete the orphaned
   [.env.example](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/env.example):144 line, or the `env-inventory` CI check fails on shifted line
   numbers.
4. **Batch 3 — unreachable views (166 LOC).** The four blades, each with its README row.
   Verify against the compiled-view cache rather than grep alone.
5. **Batch 4 — the two Curator media commands (311 LOC).** Delete both plus their README rows.
   Then, as a *separate* follow-up, audit the orphaned `image_id` columns from the two
   `2026_03_21` migrations — columns, not migrations.
6. **Batch 5 — service methods (≤30 LOC each, one commit per method).** Take the
   delete-only ones first (`readPreview`, `createSandboxPlanPlaceholder`, `dueCount`), then the
   three *wire-in-instead* refactors (`preloadPromises` into
   [DebtorsReport](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtorsReport.php):746, `forScheduleKeyedByUser` into
   [ClassAttendanceService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/ClassAttendanceService.php):40,
   `conditionalPaymentCount` into its test) — those three change query shape, so they are the
   batch most likely to move a test assertion.
7. **Batch 6 — human decisions, no code yet.** `articles:import` (needs a prod row check
   first), the two Uncertain import commands (needs an ops answer), the Mail cluster (needs the
   ESP-gate status), `BigBlueButtonService`/`fetchParticipants` (needs a GC-B3 ruling),
   [CourseLandingDemoSeeder](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/CourseLandingDemoSeeder.php) (wire or retire).
   Nothing here should be deleted on this audit's authority alone.
8. **Never in a batch:** anything in section 7.

**No static-analysis tool is configured in this repo.** There is no phpstan, psalm, rector,
deptrac or knip config — [.pre-commit-config.yaml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/pre-commit-config.yaml) and
[.github/workflows/ci.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/github/workflows/ci.yml) run Pint (style) and PHPUnit only.
Installing one (phpstan for method-level reachability, deptrac for layer edges, knip for the
JS side) would turn this one-off audit into a reproducible check, and would have caught the
method-granularity findings — which are the bulk of the application dead code — automatically.
Worth a decision before the next manual sweep, since the same 210-file scan will otherwise be
re-derived by hand.

## 9. Method and limitations

Produced by a fan-out scan (one agent per subsystem, 13 subsystems, `git ls-files` scoped so
`vendor/` and `node_modules/` were never in play) followed by an adversarial verification pass
whose brief was to *prove each candidate alive* and report the candidate as a false positive on
success. Repo-wide `rg` exceeded the 120 s timeout on this tree, so most subsystems built a
single-pass reference index over the ~3,200 tracked text files instead of running per-symbol
greps; every reported candidate was then hand-verified by opening its wiring file. Verification
routinely included `git log -S`/`-G` over all local and remote refs (and, for two items, all
~146 branch tips plus stashes) to distinguish *born unused* from *orphaned by refactor*, and in
one case an in-memory render to settle a supersession direction empirically. Seven candidates
were rejected as alive; two could not be settled.

Model: Opus 5 (`claude-opus-5`).

Limitations, stated plainly:

- **One LOC figure in the first draft was wrong by ~41× and was corrected before commit.**
  The synthesis agent reported [lecture-ui/templates/Old/](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-ui/templates/Old)
  as 133,091 LOC; `wc -l` over the six tracked files gives 3,219. The templates subtotal and
  the grand total were corrected with it (6,819 and ~8,700). Per-item counts for the PHP,
  Blade and config findings were spot-checked and hold, so the error was confined to that
  directory aggregate — but it is the clearest evidence in this document that **agent-reported
  magnitudes need independent arithmetic before they drive a decision**. Treat every remaining
  LOC number here as approximate and worth re-measuring before you use it to rank work.
- **A grep-based audit cannot prove the absence of dynamic references.** A variable method
  name (`$obj->$m()`), `call_user_func` with a computed string, a concatenated class name, or a
  view/command name assembled at runtime is invisible to every search run here. Each subsystem
  swept for these constructs and found none reaching its candidates, but absence of a *found*
  dynamic site is not proof of absence.
- **Static only — nothing was executed against production.** No `php artisan route:list` (the
  checkout has no `vendor/`, so artisan fatals), no `config:show`, no `model:show`, no DB
  introspection, no access logs. Column and table claims rest on migration files, and several
  migrations use `hasColumn` guards, which implies past manual schema drift. "Route exists but
  no client calls it" is not answerable from source at all; several endpoints are
  external-callback-only (n8n, Zoom, Tochka, PayPal, VK/Telegram) and have zero in-repo callers
  by design.
- **Off-repo callers are unfalsifiable here.** n8n workflow definitions live on a separate host
  and the production crontab was not read (only the tracked `@@TEMPLATE@@` forms under
  [scripts/server_guards/cron](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/cron)); `deploy.sh` lives on the box and
  is untracked. This is why every operator-CLI candidate is capped at moderate confidence, and
  why the `lecture-ui` Python leads are graded low.
- **Human invocation is invisible by construction.** For a hand-run script, "no inbound
  reference" is the expected state, not evidence of death. This distinction accounts for three
  of the seven false positives and both Uncertain rows.
- **Not audited:** enum cases individually, private/protected methods and class constants in
  [app/Support](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support), the 21 framework/package config files key-by-key (including
  [config/services.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/services.php), so unused keys inside it are unknown), the three
  [app/Filament](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament) scope hints (resources whose model no longer exists — the
  highest-value remaining sweep; pages behind a permanently-off flag; duplicated page-local
  widget logic), Filament closures that build a method name by concatenation,
  `mobile/package.json` Capacitor plugin usage (the generated native projects are untracked, so
  bridge registration is not in the repo), and lock drift between
  [composer.lock](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/composer.lock) and [composer.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/composer.json).
- **LOC figures are approximate**, derived from declaration line to next declaration; one view
  was not measured. One tooling artifact worth knowing about: `grep -rn` was parsed by ripgrep
  as `-r n` in one session, printing a phantom `database/data/n.csv` path that does not exist.
- **The audit was read-only.** No file in the scanned tree was edited, moved or deleted;
  helper scripts were written outside the repo under the OS temp dir and removed.

_Dr. Mārcis Gasūns_
