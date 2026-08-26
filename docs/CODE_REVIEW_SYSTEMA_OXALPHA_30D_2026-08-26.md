# Systema-Sanscriticum OxAlpha 30-day code review report

_Created: 26-08-2026 · Last updated: 26-08-2026_

Handoff: [H3546](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3546-OxAlpha_Systema-Sanscriticum_oxalpha-30d-risk-review-gate_26.08.26.md) · Plan: [PLAN_SYSTEMA_OXALPHA_CODE_REVIEW_HARDENING_2026Q3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_OXALPHA_CODE_REVIEW_HARDENING_2026Q3.md) · Window: **26-07-2026 .. 25-08-2026** (merged-at UTC) · Executor: OxAlpha `x-preview-f-free`.

## Method

Independent passes per slice: **Standards** (named rule/smell + exact hunk, executable code only) and **Spec** (requirement quoted from the ruled source order: PR body → linked issue → handoff/plan → matching doc; `no spec available` otherwise). Generated/vendor/data-only churn excluded unless behavior changed (decision 5). Findings require severity, file/line, failure mode, and repro/test; only proven P0/P1 fixed, always with regression tests (decisions 9–10). Money/security/production fixes additionally human-gated (decision 13).

## Risk-ranked slices (10 retained, cap respected)

| Rank | PR | Merged | Base → head | Focus | Spec source (quoted requirement) | Spec | Standards |
|---|---|---|---|---|---|---|---|
| 1 | [#2088](https://github.com/gasyoun/Systema-Sanscriticum/pull/2088) SEPA bank claim | 25-08 | `c71bf08999` → `66ef93ea04` | money, public intake | Body: «платёж ложится ОБЫЧНЫМ Payment со status=pending… доступ НЕ открывается. Trusted-рулинг 22-08 зеркалится» | PASS | PASS |
| 2 | [#2009](https://github.com/gasyoun/Systema-Sanscriticum/pull/2009) gift certificates | 23-08 | `9206456d55` → `2a6ea3f594` | money | Body: «код одноразовый… доступ открывается НЕ мимо тарифной модели»; flag `GIFT_CERTIFICATES` OFF | PASS | PASS |
| 3 | [#2059](https://github.com/gasyoun/Systema-Sanscriticum/pull/2059) inbound email webhook | 24-08 | `5da00b72c6` → `ca43b6a16b` | webhook security | Body: «fail-closed 403», «Unmatched senders land in a visible queue — never dropped» | PASS | PASS |
| 4 | [#2080](https://github.com/gasyoun/Systema-Sanscriticum/pull/2080) public surveys | 25-08 | `4dd53ffff5` → `fe1ce7d8a5` | public intake, prana economy | Body: «Авто-начисление праны… идемпотентно»; throttle + honeypot + strict option validation | PASS | **FAIL → fixed** ([#2123](https://github.com/gasyoun/Systema-Sanscriticum/pull/2123)) |
| 5 | [#1986](https://github.com/gasyoun/Systema-Sanscriticum/pull/1986) upload hygiene jail | 23-08 | `6ce978b5b5` → `fd7579b2e5` | security/storage | Body: «two-sided jail… stored name always generated, client name never used for images» | PASS | PASS |
| 6 | [#2011](https://github.com/gasyoun/Systema-Sanscriticum/pull/2011) TG second session + auto-reply trial | 23-08 | `2a6ea3f594` → `b03b053db0` | scheduled state, Telegram | Body: «Drainer scoped to own account id»; all three flags default OFF | PASS | PASS |
| 7 | [#1984](https://github.com/gasyoun/Systema-Sanscriticum/pull/1984) season notify + decay flip | 23-08 | `0554883344` → `e811b0f881` | scheduled bulk send | Body: «Идемпотент: unique (season_id, user_id, channel)»; master flag default OFF | PASS | PASS |
| 8 | [#1980](https://github.com/gasyoun/Systema-Sanscriticum/pull/1980) safe withdrawal | 22-08 | `fc539ba814` → `625e266436` | finance read-only | Body: «Read-only: ни одной записи в teacher_payouts/payments» | PASS | PASS |
| 9 | [#2103](https://github.com/gasyoun/Systema-Sanscriticum/pull/2103) LYW serving | 25-08 | `5f47f269b4` → `255d848406` | student routing | Body: «flag off ⇒ 404 before any read… Quiz answer keys never leave the server» | PASS | PASS |
| 10 | [#2102](https://github.com/gasyoun/Systema-Sanscriticum/pull/2102) support rules-sync | 25-08 | `2e60e175e4` → `5f47f269b4` | support tooling | Body: «синхронизированные правила невидимы рантайм-классификатору (whereNull pattern_hash guard)» | PASS | PASS |

## Findings

### F1 — P1 — survey prana reward farm — **FIXED** [PR #2123](https://github.com/gasyoun/Systema-Sanscriticum/pull/2123)

- **Where:** [app/Http/Controllers/SurveyPageController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/SurveyPageController.php) `tryAutoReward()` (~line 244 at slice SHA `fe1ce7d8`); schema context [prana migration](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_05_02_120000_create_prana_system.php).
- **Failure mode:** `award()` was called **without morph source** → `source_type`/`source_id` NULL; MySQL unique index does not constrain NULLs → every resubmission of a survey with the same contact email re-awarded the «прана 500 ₽» reward unboundedly (throttle caps rate at 20/min, not total).
- **Repro/test:** regression tests `repeat_submission_with_same_email_awards_prana_once`, `same_email_is_rewarded_independently_per_survey` — fail-before/passes-after; SurveyPageTest 12 PASS / 66 assertions; Pint passed.
- **Fix:** per-`(survey_slug, reward_user_id)` dedupe + `source: $response` on award. No payout amounts or ruled numbers changed; different surveys stay independently rewarded. Feature remains behind `SURVEYS_ENABLED` (prod OFF).

### F2 — P1 (pre-existing main) — stale payroll test premise — **HUMAN-GATED, not auto-fixed**

- **Where:** `tests/Feature/PayrollForecastTest.php::rate_gap_falls_back_with_warning_note` (~line 123–130) vs config change in [#2110](https://github.com/gasyoun/Systema-Sanscriticum/pull/2110).
- **Failure mode:** MG's 26-08 ruling made latest percent-rate periods open-ended («rates persist until Marina announces a change»); the test asserts Leytan's September date falls back to `lms_fallback` — CI red on main tip `8b432a9c` (run of 06:30Z) and inherited by every fresh branch.
- **Why not auto-fixed:** money domain with two valid repair readings (update fixture to open-end semantics vs preserve a real-gap case for coverage) — decision 13 reserves this for the owner. Tracked: GTD Do-Today rows of 26-08; release PR [#2111](https://github.com/gasyoun/Systema-Sanscriticum/pull/2111) stays blocked until ruled.

### Observations (below fix bar, recorded only)

| ID | Sev | Slice | Observation |
|---|---|---|---|
| O1 | LOW | #2009 | `issueForPayment` existence check is check-then-act without lock; unique(`payment_id`) backstops integrity — concurrent duplicate paid-transition yields a raw QueryException instead of graceful idempotent return |
| O2 | LOW | #2059 | `firstOrCreate(message_id)` same race class; unique index backstops, retry lands on duplicate path |
| O3 | MED-LOW | #2080 | CSV export writes raw user text into Excel-facing CSV without formula-injection guard (`=`/`+`/`-`/`@` prefixes); staff-only download surface |

## Exclusions (no executable impact review)

- [#2103](https://github.com/gasyoun/Systema-Sanscriticum/pull/2103): `public/vendor/mermaid/mermaid.min.js` (+2811 lines, vendored MIT asset).
- [#2102](https://github.com/gasyoun/Systema-Sanscriticum/pull/2102): `tools/message-intent-classifier/**` vendored pinned bundle (~50 files incl. `composer.lock`, `vectors/golden.json`) — reviewed only where Systema code consumes it (`SupportRulesSync`, runtime guard, Filament panel).

## No-spec outcomes

None — all ten slices carried PR-body specs resolving the ruled order at its first step.

## Adapter bootstrap

[PR #2114](https://github.com/gasyoun/Systema-Sanscriticum/pull/2114) merged as [`72d18375`](https://github.com/gasyoun/Systema-Sanscriticum/commit/72d18375): canonical Matt Pocock GitHub-issue-tracker adapter under `docs/agents/` (intake OFF), `## Agent skills` block in CLAUDE.md, five triage labels live.

_Dr. Mārcis Gasūns_
