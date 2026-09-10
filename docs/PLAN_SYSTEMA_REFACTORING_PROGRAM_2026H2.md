# Systema-Sanscriticum — Refactoring Program 2026H2

_Created: 10-09-2026 · Last updated: 10-09-2026_

Consolidated, measured refactoring program. Supersedes nothing — it consumes
[DEAD_CODE_INVENTORY_SYSTEMA_2026-08-09.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DEAD_CODE_INVENTORY_SYSTEMA_2026-08-09.md),
[UI_COMPONENT_LIBRARY_EXTRACTION_SYSTEMA_2026-08-10.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UI_COMPONENT_LIBRARY_EXTRACTION_SYSTEMA_2026-08-10.md)
and [OPTIMISATION_BACKLOG_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/OPTIMISATION_BACKLOG_2026H2.md),
re-validates them against the 10-09-2026 tree and re-ranks by churn × complexity × risk.

## Measured basis (10-09-2026, churn = commits since 2026-06-01)

| File | Lines | Branch-lines | Churn | Verdict |
|---|---|---|---|---|
| routes/web.php | 1 294 | — | **136** | T1 split — top merge-conflict magnet |
| config/features.php | 1 509 | — | **130** | T8 split + flags inventory (149 flags) |
| app/Console/Kernel.php | 1 050 | — | **93** | T4 schedule() 981 lines → group methods |
| app/Http/Controllers/StudentController.php | 1 511 | 79 | **58** | T3 → view services (dashboard 252, showLesson 160) |
| app/Models/Payment.php | 1 753 | 98 | **57** | T2 money state machine → services (human merge) |
| resources/views/student/dashboard.blade.php | 1 124 | — | **49** | P3 template split |
| resources/views/shop/show.blade.php | 1 079 | — | **36** | P3 |
| app/Services/TeacherSalaryService.php | 1 501 | **107** | 22 | T5 → sub-services (human merge) |
| app/Services/Support/SupportDmAutoReply.php | 1 301 | 77 | 15 | T6 → pipeline stages |
| app/Services/TelegramSupport/TelegramSupportSyncService.php | 1 320 | — | 27 | P4 shared Madeline client/normalizer with Harvest (1 009) |
| app/Filament/Resources/LandingPageResource.php | 1 625 | **0** | 25 | T7 — declarative schema: navigability split, low risk |

Key re-ranking finding: the former "three god methods" headline
(LandingPageResource::form 1 536 lines) has **zero branch keywords** — it is
declarative schema, not complexity. The real complexity hot spots are
TeacherSalaryService (107), Payment (98), StudentController (79), SupportDmAutoReply (77).

Corrections applied to the dead-code inventory (10-09 re-scan, H4515):
all sampled rows re-confirmed dead; **`DebtorsReport::preloadPromises()` verdict
flipped wire-in → delete** — the N+1 it targeted is already fixed by
`Debtors::preloadPairCaches()` (called from `DebtorsReport::totalDebtForQuery`).
Layering debt found: `preloadPairCaches()` is a static on the Filament page
`app/Filament/Pages/Debtors.php` but is consumed by `DebtorsReport` and
`DebtorsBotCommand` — move into a service during T3/T5.

## Waves

| Wave | Slice | Handoff | Effort | Gate |
|---|---|---|---|---|
| 0 | P0 dead code, batches 0–5 (delete-only) | H4515 | ~1–2 h | re-scan + `php -l` + Pint + full CI suite |
| 1 | T1 routes/web.php domain split | H4516 | ~2–3 h | `route:list --json` parity (name+action+middleware+domain), closure counter, PaidRouteFailClosedTest |
| 1 | T4 Kernel::schedule() group methods | H4517 | ~2–3 h | `schedule:list` parity + schedule-guard CI |
| 2 | T3 StudentController view services | — | ~4–6 h | full suite |
| 2 | T8 features.php split + flags inventory | — | ~2–3 h | config smoke + env-inventory CI |
| 2 | P2 PHPStan/Larastan level 5 + baseline, required CI job; Rector `--dry-run` only | — | ~2–3 h | spike Laravel 13.24 compat first |
| 2 | T6 SupportDmAutoReply pipeline stages | — | ~4–6 h | full suite + flag smoke |
| 3 | T2 Payment money state machine → services | — | ~1–2 d | human merge; money CI widened: `TochkaWebhook\|MutualSettlement\|FinanceIdempotency\|FinanceLocking\|Payment*\|Debtors*`; start only on green main after H4456 |
| 3 | T5 TeacherSalaryService sub-services | — | ~4–6 h | human merge, salary tests |
| 4 | T7 LandingPageResource per-block schemas | — | ~4–6 h | 12 landing tests + CourseLandingFilamentTest |
| 4 | P3 UI: exact-hex gray tokens (NOT `gray-*`), dark-surface tokens (`--color-surface-*`/`--color-ink`), `--color-brand-deep` (#E3122C), restore `@plugin "@tailwindcss/typography"`; then primitives Field→Button→Card→Badge→Modal→ProgressBar→Avatar, then churn-ranked template splits | — | ~1 d | Dusk + visual smoke |

Ruled 10-09-2026 (MG, interactive cards): dark palette = real theme → tokens;
#E3122C = second brand accent → token; restore typography plugin.

## Non-goals

- Batch 6 human decisions (articles:import prod check, Mail/ESP cluster, GC-B3,
  CourseLandingDemoSeeder, LectureBuilderClient::isHealthy) — not agent-deletable.
- `mobile/` and `lecture-builder/` — dependency-bump-only maintenance, do not invest.
- TrustHosts, test-only Mailables, TochkaRecurring, CascadeLemmatizer — keep per inventory §3.2–3.3.
- Migrations are history; nothing under `database/migrations` is touched.

## Verification conventions

Behavior-preserving moves only; every wave lands through PR with the full suite
(`php artisan test --parallel`, SQLite primary + MySQL finance job) plus Pint,
Semgrep and the changelog gates already required on main. P3 additionally runs
the Dusk harness; T1/T4 carry byte-parity gates named above.

_Dr. Mārcis Gasūns_
