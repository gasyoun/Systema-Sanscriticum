## [Unreleased]

### Added
- **H2220: RU-инвентарь SEO-агентов (слой 1) и пакета скиллов (слой 2).** [docs/SEO_AGENTS_AND_SKILLS_INVENTORY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_AGENTS_AND_SKILLS_INVENTORY_2026.md) — 18 spawnable `seo-*` агентов, full skill list + broken-junction install state, source [claude-seo](https://github.com/gasyoun/claude-seo). Executor: Grok 4.5 (`grok-4.5`).
- F5(b) GC-C2 visibility ruled: super_admin + admin + accountant see all rows; manager sees own `created_by_user_id` only тАФ GETCOURSE_PARITY_PRODUCTION_SPEC_2026 ┬з4 / ┬з7
- **H2156: ╨┤╨╡╤В╨╡╨║╤В╨╛╤А ╨╛╤В╤Б╤А╨╛╤З╨╡╨║ ╨╛╨┐╨╗╨░╤В╤Л ╨╕╨╖ TG/╨▓╨╡╨▒-╤З╨░╤В╨░ тЖТ ╨╛╤З╨╡╤А╨╡╨┤╤М ╨║╤Г╤А╨░╤В╨╛╤А╨░.** `PaymentPromiseSuggestion` + `promises:detect-deferrals` (regex+LLM, cursor) + Filament **┬л╨Я╤А╨╡╨┤╨╗╨╛╨╢╨╡╨╜╨╕╤П ╨╛╤В╤Б╤А╨╛╤З╨╡╨║┬╗** (approve/dismiss). Approve тЖТ `PaymentPromise` active (update existing same course); **grant_access default OFF**; ЁЯЪй + grant refused. Flag `promise_suggestion_detection_enabled` default **OFF**. Docs: playbook ┬з4 live, debtors-manual FAQ. Tests: `PromiseSuggestion*` (тЙе12). Money-adjacent; agent-merge allowed under gasyoun always-merge policy (02-08-2026). Executor: Grok 4.5 (`grok-4.5`) override dual-run of Sonnet-tagged handoff.
- F5(a) GC-C2 join path ruled: manager = `payments.created_by_user_id` (who created the payment row); F5(b) visibility still open тАФ GETCOURSE_PARITY_PRODUCTION_SPEC_2026 ┬з4.1 / ┬з7
- **H2187: dry-run fixtures + operator smoke for `ops:soft-remediate`.** Committed breaker lines and expected status contracts under [`tests/fixtures/soft_remediate/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/fixtures/soft_remediate); PHPUnit [`SoftRemediateDryRunFixturesTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SoftRemediateDryRunFixturesTest.php) asserts dry-run never mutates tree/fuse (`applied === false`, porcelain + content hashes unchanged) across clean / origin-equal / diverging / hard-breaker / allowed-PDF scenarios; playbook ┬з4.0 one-liner: `php artisan test --filter=SoftRemediate && php artisan ops:soft-remediate --dry-run --json`. Residual of H2148 (#1060). Executor: Grok 4.5 (`grok-4.5`).

### Changed
- **H2188: Wave 0 orderтЖТpay re-verify after deploy.** Live `php artisan dozhim:baseline --json` on prod `193.232.229.92` (as_of 2026-08-02 19:26:05) тАФ command present; Rate A 30d **61.7%** (120) / 90d **85.0%** (567) vs H2096 freeze 65.6% / 85.7%; Rate B still sparse. Artifact [docs/ops/dozhim_baseline_prod_2026-08-02.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ops/dozhim_baseline_prod_2026-08-02.json); roadmap footnote. No money-contour code. Executor: Grok 4.5 (`grok-4.5`).

## [1.86.2] - 2026-08-02
### Added
- H2185 GC-C2 census: `assigned_to` still unused in sales reports (NOT_BUILT); prod fill Lead 0.4% / Deal 0% тАФ see ROADMAP_Systema_NOBORING_DOZHIM_2026H2 Wave 1a census

## [1.86.1] - 2026-08-02
### Added
- **H2186: Rate B instrumentation тАФ `Lead.converted_at` on ordinary course paid path (flag OFF default).** Explains H2096 Rate B ~0% as an instrumentation gap (course checkout never set `lead_id` / never called `markConverted`; deposit/trial/marathon already did). New flag `features.lead_converted_at_on_course_paid` (`LEAD_CONVERTED_AT_ON_COURSE_PAID`, default **false**); `Payment::markLinkedLeadConverted()` (lead_id or email match + FK backfill); wired into `processSuccessfulPayment` when flag ON and not conditional; checkout attaches `lead_id` only when flag ON. Spec ┬з2.4 + NOBORING roadmap Rate B residual updated. Tests: `LeadConvertedAtOnCoursePaidTest`. Fence: do not enable in prod without human review. Executor: Grok 4.5 (`grok-4.5`).

## [1.86.0] - 2026-08-02

### Added
- **H1991: `Lesson.flash_cards` migrated into lesson-tied `SrsDeck`/`SrsCard` (K2 ruling, [PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md)).** SRS becomes the single source of truth for lesson flashcards; `flash_cards` stays (dual-read, column not dropped). New [`LessonFlashCardsSync`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Srs/LessonFlashCardsSync.php) is the one idempotent sync path shared by the artisan command `srs:migrate-lesson-flash-cards {--dry-run}` and a new `Lesson::saved()` hook, so a later content edit never drifts from what was migrated тАФ cards are replaced wholesale per lesson on each sync (heterogeneous JSON shapes carry no stable natural key across runs). `Lesson::srsDeck()`/`flashcardsForDisplay()` give the dual-read presentation helper, preferring the synced deck when present. Deck `visibility=private` (lesson content sits behind paid-course access, must not surface on the public/system `/koloda` hub); `language=sa` (no language field exists on `Course`/`Lesson` yet). Tests: [`MigrateLessonFlashCardsTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Srs/MigrateLessonFlashCardsTest.php) 9 green + 116 Srs + 116 Lesson regression re-run green. [PR #1080](https://github.com/gasyoun/Systema-Sanscriticum/pull/1080). Executor: Sonnet 5 (`claude-sonnet-5`).

## [1.85.0] - 2026-08-02

### Added
- **H2110: ┬л╨б╤В╨░╤А╤В ╤З╤В╨╡╨╜╨╕╤П┬╗ reading packs inside the cabinet тАФ multi-pack, entitlement-gated, no new schema.** The reader stopped being a one-file demo: `ReadingPackController` now resolves a pack by **slug** instead of a `const FEED_PATH`, and two new cabinet routes тАФ `/dvaram/reading` (list) and `/dvaram/reading/{slug}` тАФ render it behind **two stacked gates**, `features.kosha_reader` **and** [`StartChteniyaCohort::hasEntitlement()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/StartChteniyaCohort.php), so a logged-in student who never bought the cohort gets **404, not a redirect**. Both flags default OFF, so prod is inert. Gating is the repo's existing inline `abort_unless(...)` idiom тАФ no new middleware, no new policy, no new table, no migration. `hitopadesa-0` (125 sentences / 900 tokens, 95 % RU gloss coverage) is vendored from the H2109 kosha freeze by new [`scripts/vendor_cohort_start_chteniya_packs.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_cohort_start_chteniya_packs.py), which verifies each file against the freeze MANIFEST's own **sha256 + byte count before writing** and re-verifies with `--check` тАФ the check that would have caught the two stale hashes H2129 had to correct. The demo blade's body moved to a shared partial (`reading/partials/pack.blade.php`) so the public page and the cabinet render **one** reader rather than drifting into two; the partial additionally surfaces per-token `gloss_ru` when the feed carries it (hitopadesa-0 does, nala-1 does not тАФ so `/reading/kosha-demo`'s output is unchanged, pinned by a test). **`subhashita-beginner` is deliberately NOT imported:** its shape is `sayings[]`тЖТ`lines[].chunks[]`, not `sentences[]`/`tokens[]`, and this handoff's stated failure mode was "second pack schema", so the adapter stays separate, visible work. Slug resolution is allow-list only тАФ an unoffered slug (including the legacy public `nala-1`) 404s before touching the filesystem. Tests: [`CabinetReadingPackTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/StartChteniya/CabinetReadingPackTest.php) 8 green (guestтЖТlogin, entitled reads real pinned content incl. its RU gloss, index lists packs, non-buyer 404, each flag off 404s independently, unoffered slug 404s, public demo contract unchanged); pre-existing `ReadingPackTest` + `StartChteniyaCohortEntitlementTest` re-run green (21 total). Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.84.0] - 2026-08-02

### Added
- **H1990 W1-D1: B├╝hler grammar-tables (Memrise 6517849) paradigm cells тЖТ SRS.** New `srs:import-buhler-paradigms` artisan command imports the 78-row / 5-stem-class export into a dedicated `buhler-paradigm-drills` system deck тАФ Option A per [ROADMAP_GRAMMAR_TABLES_BUHLER_MEMRISE_SRS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ROADMAP_GRAMMAR_TABLES_BUHLER_MEMRISE_SRS_2026.md) ┬зP1: one card per inflected form, tagged with `lemma` + `stem_class` metadata so a later Option B (full-paradigm grid) can reuse the same import. Cards de-dupe on `deck_id + fields->form + fields->label` rather than `source_word_id`, since a form can legitimately repeat within a level under two different case/number labels. Idempotent, dry-run supported. Tests: [`ImportBuhlerParadigmsSrsDeckTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Srs/ImportBuhlerParadigmsSrsDeckTest.php) (7 green). [PR #1073](https://github.com/gasyoun/Systema-Sanscriticum/pull/1073). Executor: Sonnet 5 (`claude-sonnet-5`).

## [1.83.1] - 2026-08-02

### Added
- **H2105: ┬л╨б╤В╨░╤А╤В ╤З╤В╨╡╨╜╨╕╤П┬╗ cohort funnel тАФ money-contour scaffold (flag OFF).** No new money/access system тАФ the cohort is an ordinary Course+Tariff sold through the existing generic checkout (ShopController/CheckoutController/PaymentController) and unlocked through the existing `PaymentObserver::grantAccess()` course_group pivot (D10). Adds [`App\Support\StartChteniyaCohort`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/StartChteniyaCohort.php) тАФ single source of truth for the `start_chteniya_cohort` entitlement key that sibling handoffs (H2106/H2110/H2111) gate reader/SRS routes on тАФ plus [`config/start_chteniya.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/start_chteniya.php) (course slug, entitlement key name, Akro тВм75тАУ129 price-band reference) and a `features.start_chteniya_cohort` deploy switch (default **OFF**). Course/Tariff/Group rows (real RUB price, cohort dates) are human ops via Filament тАФ never invented here (D6). Fences: R20 marathon cohort untouched (separate `MarathonEnrollment` path); entitlement never flips `config/srs.php` (D11). Tests: `StartChteniyaCohortEntitlementTest` (7 green) тАФ flag-off deny, unpaid deny, paid grants entitlement + course group/schedule slot, deposit/trial/conditional/pending payments never grant, R20 isolation. [PR #1064](https://github.com/gasyoun/Systema-Sanscriticum/pull/1064). Executor: Sonnet 5 (`claude-sonnet-5`).

### Fixed
- **╨Ъ╤А╨░╤Б╨╜╤Л╨╣ `main`: ╤В╨╡╤Б╤В ╤Г╨┤╨░╨╗╨╡╨╜╨╕╤П ╤А╨╡╨┐╨╗╨╕╨║╨╕ ╨╜╨╡ ╤Г╤З╨╕╤В╤Л╨▓╨░╨╗ ╨░╤Г╨┤╨╕╤В-╨╛╤В╨╝╨╡╤В╨║╤Г, ╨║╨╛╤В╨╛╤А╤Г╤О ╨╛╤Б╤В╨░╨▓╨╗╤П╨╡╤В [#1033](https://github.com/gasyoun/Systema-Sanscriticum/pull/1033).** ╨Ф╨╢╨╛╨▒ ┬лPHP 8.3 тАФ tests┬╗ ╨┐╨░╨┤╨░╨╗ ╨╜╨░ `HomeworkFlowTest::student_can_delete_own_outdated_comment_while_on_review` (┬лFailed asserting that 2 is identical to 1┬╗), ╨▓╨╛╤Б╨┐╤А╨╛╨╕╨╖╨▓╨╛╨┤╨╕╨╗╤Б╤П ╨┤╨╡╤В╨╡╤А╨╝╨╕╨╜╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛ ╨╕ **╨▓ ╨╕╨╖╨╛╨╗╤П╤Ж╨╕╨╕** тАФ ╤В╨╛ ╨╡╤Б╤В╤М ╤Н╤В╨╛ ╨╜╨╡ ╨╖╨░╨▓╨╕╤Б╨╕╨╝╨╛╤Б╤В╤М ╨╛╤В ╨┐╨╛╤А╤П╨┤╨║╨░ ╤В╨╡╤Б╤В╨╛╨▓. ╨Я╤А╨╕╤З╨╕╨╜╨░ тАФ ╤Г╤Б╤В╨░╤А╨╡╨▓╤И╨╡╨╡ ╤Г╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╕╨╡, ╨░ ╨╜╨╡ ╨┤╨╡╤Д╨╡╨║╤В ╨┐╤А╨╛╨┤╤Г╨║╤В╨░: [#1030](https://github.com/gasyoun/Systema-Sanscriticum/pull/1030) ╨┤╨╛╨▒╨░╨▓╨╕╨╗ ╨╕ ╤Г╨┤╨░╨╗╨╡╨╜╨╕╨╡, ╨╕ ╨┐╤А╨╛╨▓╨╡╤А╨║╤Г ┬л╤Б╤В╤А╨╛╨║ ╨╛╤Б╤В╨░╨╗╨╛╤Б╤М 1┬╗, ╨░ ╨┐╤А╨╕╤И╨╡╨┤╤И╨╕╨╣ ╨┐╨╛╨╖╨╢╨╡ #1033 ╨╜╨░╤Г╤З╨╕╨╗ `deleteStudentComment()` ╨╖╨▓╨░╤В╤М `recordDeletionNote()`, ╨║╨╛╤В╨╛╤А╨░╤П ╨║╨╗╨░╨┤╤С╤В ╤Б╨╗╤Г╨╢╨╡╨▒╨╜╤Г╤О ╤А╨╡╨┐╨╗╨╕╨║╤Г ╨▓ ╤В╨╛╤В ╨╢╨╡ ╤В╤А╨╡╨┤ тАФ ╤Б╤Л╤А╨╛╨╣ ╤Б╤З╤С╤В ╤Б╤В╤А╨╛╨║ ╨╛╤Б╤В╨░╤С╤В╤Б╤П 2. ╨Ю╤В╨╝╨╡╤В╨║╨░ **╨╜╨░╨╝╨╡╤А╨╡╨╜╨╜╨░╤П** (╨░╤Г╨┤╨╕╤В-╤Б╨╗╨╡╨┤; `deleteStudentComment` ╨┤╨░╨╢╨╡ `abort_if`-╨░╨╡╤В ╨╜╨░ `TYPE_MESSAGE`, ╤З╤В╨╛╨▒╤Л ╤Г╤З╨╡╨╜╨╕╨║ ╨╡╤С ╨╜╨╡ ╤Б╤В╤С╤А), ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╤З╨╕╨╜╨╕╤В╤Б╤П ╤В╨╡╤Б╤В. ╨Т╨╝╨╡╤Б╤В╨╛ ╨╛╤Б╨╗╨░╨▒╨╗╨╡╨╜╨╕╤П ╨┤╨╛ ┬л2┬╗ ╨┐╨╕╨╜╨╕╨╝ ╤А╨░╨╖╨┤╨╡╨╗╤М╨╜╨╛ ╨╕ **╨┐╨╛ ╤В╨╕╨┐╤Г**: ╨╛╤Б╤В╨░╨╗╨░╤Б╤М ╤А╨╛╨▓╨╜╨╛ ╨╛╨┤╨╜╨░ ╤А╨╡╨┐╨╗╨╕╨║╨░ ╤Г╤З╨╡╨╜╨╕╨║╨░ (`TYPE_SUBMISSION`) ╨╕ ╨╖╨░╨┐╨╕╤Б╨░╨╜╨░ ╤А╨╛╨▓╨╜╨╛ ╨╛╨┤╨╜╨░ ╤Б╨╗╤Г╨╢╨╡╨▒╨╜╨░╤П ╨╛╤В╨╝╨╡╤В╨║╨░ (`TYPE_MESSAGE`) тАФ ╤Г╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╕╨╣ 7 тЖТ 9, ╤В╨╛ ╨╡╤Б╤В╤М ╤Б╤В╤А╨╛╨╢╨╡, ╨╕ ╨┐╨╛╨▓╨╡╨┤╨╡╨╜╨╕╨╡ #1033 ╤В╨╡╨┐╨╡╤А╤М ╨╖╨░╨║╤А╤Л╤В╨╛ ╤В╨╡╤Б╤В╨╛╨╝, ╤З╨╡╨│╨╛ ╤А╨░╨╜╤М╤И╨╡ ╨╜╨╡ ╨┤╨╡╨╗╨░╨╗ ╨╜╨╕╨║╤В╨╛. [#1063](https://github.com/gasyoun/Systema-Sanscriticum/pull/1063). Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.83.0] - 2026-08-02

### Added
- **H2149 follow-up: ╨┐╤А╨░╨▓╨║╨░ managed-╤Д╨░╨╣╨╗╨░ тАФ ╨▓╤Б╨╡╨│╨┤╨░ ╨┤╨▓╤Г╤Е╤И╨░╨│╨╛╨▓╨░╤П ╨▓╤Л╨║╨╗╨░╨┤╨║╨░.** ╨Ч╨░╨┐╨╕╤Б╨░╨╜╨░ ╨▓ [server-resource-guards.md ┬з8.2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md) ╨┐╨╛╤Б╨╗╨╡ ╤В╨╛╨│╨╛, ╨║╨░╨║ ╨╜╨░ ╨╜╨╡╤С ╨╜╨░╤Б╤В╤Г╨┐╨╕╨╗╨╕ ╨┐╤А╨╕ ╨▓╤Л╨║╨░╤В╨║╨╡ ╤Б╨░╨╝╨╛╨│╨╛ H2149: `deploy.sh` ╨▓ ╨║╨╛╨╜╤Ж╨╡ ╨╖╨╛╨▓╤С╤В `guards:verify`, ╤В╨╛╤В ╨▓╨╕╨┤╨╕╤В ╤З╨╡╤Б╤В╨╜╤Л╨╣ drift (╨▓ git ╨╜╨╛╨▓╨░╤П ╨║╨╛╨┐╨╕╤П managed-╤Д╨░╨╣╨╗╨░, ╨╜╨░ ╨╝╨░╤И╨╕╨╜╨╡ ╤Б╤В╨░╤А╨░╤П), ╨┤╨╡╨┐╨╗╨╛╨╣ ╨▓╤Л╤Е╨╛╨┤╨╕╤В 1, ╨╕ ╨╛╨▒╤С╤А╤В╨║╨░ **╨╛╤В╨║╨░╤В╤Л╨▓╨░╨╡╤В** ╤А╨╛╨▓╨╜╨╛ ╤В╨╛╤В ╨║╨╛╨╝╨╝╨╕╤В, ╨╕╨╖ ╨║╨╛╤В╨╛╤А╨╛╨│╨╛ `apply.sh` ╨┤╨╛╨╗╨╢╨╡╨╜ ╨▒╤Л╨╗ ╨┐╨╛╤Б╤В╨░╨▓╨╕╤В╤М ╨╜╨╛╨▓╤Г╤О ╨▓╨╡╤А╤Б╨╕╤О. ╨Я╨╛╤А╤П╨┤╨╛╨║: `deploy.sh` тЖТ `scripts/server_guards_apply.sh` тЖТ `guards:verify`. ╨Ю╤В╨╜╨╛╤Б╨╕╤В╤Б╤П ╨║ ╨╗╤О╨▒╨╛╨╣ ╤Б╤В╤А╨╛╨║╨╡ `manifest.psv`. Executor: Opus 5 1M (`claude-opus-5[1m]`).
- **H2155: ╨┐╨╗╨░╤В╤С╨╢╨╜╨░╤П ╨┤╨╕╤Б╤Ж╨╕╨┐╨╗╨╕╨╜╨░ тАФ playbook ╨║╤Г╤А╨░╤В╨╛╤А+╤Г╤З╨╡╨╜╨╕╨║, TG 1:1, ╨┐╨╛╤Б╤В P6.** [docs/PLAYBOOK_PAYMENT_DISCIPLINE_CURATOR_STUDENT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAYBOOK_PAYMENT_DISCIPLINE_CURATOR_STUDENT_2026.md) ┬╖ [docs/copy/tg-payment-discipline-1to1-scripts.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/tg-payment-discipline-1to1-scripts.md) ┬╖ P6 ╨▓ [marketing/payment-blocks-telegram-posts.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/payment-blocks-telegram-posts.md) (╨Ы╨б тЙа ╨┤╨░╤В╨░ ╨▓ ┬л╨Ь╨╛╨╕ ╨┤╨╛╨╗╨│╨╕┬╗). FAQ/┬з17 ╨▓ debtors-manual + note ╨▓ student-manual ┬з5. ╨Ъ╨╛╨┤ auto-promise **╨╜╨╡** ╨▓ scope тАФ ╨┤╨╕╨╖╨░╨╣╨╜ twin `ReminderSuggestion` ╨▓ playbook ┬з4. Executor: Grok 4.5 (`grok-4.5`).

### Changed
- **Student-facing copy: curator magic login link.** On `/login` and `/forgot-password` (not-found + footer + mail-timeout): if you forget email/password, curator sends a personal one-time link in Telegram тАФ no new account. Onboarding FAQ + cabinet pin 0тА▓ + student-manual + bot FAQ knowledge. Executor: Grok 4.5 (`grok-4.5`).
- **Magic login link: manager (╨║╤Г╤А╨░╤В╨╛╤А) may issue.** `RoleGate::canIssueStudentLoginLink()` = admin + manager; UserResource list/view + unlock; AccessAttempt feed; Telegram `/unblock`. Runbook + H849 feed. Tests: `UnblockBotCommandTest`. Executor: Grok 4.5 (`grok-4.5`).
- **Ops: ╤А╨░╨╖╨┤╨╡╨╗╤Л ┬л╤З╤В╨╛ ╨┤╨╡╨╗╨░╤В╤М / ╨╜╨╡ ╨┤╨╡╨╗╨░╤В╤М ╤З╨╡╨╗╨╛╨▓╨╡╨║╤Г┬╗ тАФ ╨┐╨╛-╤А╤Г╤Б╤Б╨║╨╕ (H2147 follow-up).** [server-resource-guards ┬з8.1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md), [deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md), [CLAUDE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md) ┬з Ops: ╤В╨░╨▒╨╗╨╕╤Ж╤Л ╨┐╤А╨╛╤Д╨╕╨╗╨░╨║╤В╨╕╨║╨╕ ╨╕ ╨╖╨░╨┐╤А╨╡╤В╨╛╨▓ ╨╜╨░ ╤А╤Г╤Б╤Б╨║╨╛╨╝. Executor: Grok 4.5 (`grok-4.5`).

### Fixed
- **H2149: ╨╝╤П╨│╨║╨╕╨╣ ╨┐╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╤М ╨░╨▓╤В╨╛-╨┤╨╡╨┐╨╗╨╛╤П ╨┐╨╡╤А╨╡╨┐╤А╨╛╨▓╨╡╤А╤П╨╡╤В ╤Б╨╡╨▒╤П, ╨░ ╨╜╨╡ ╤Б╤В╨╛╨╕╤В ╨▓╨╡╤З╨╜╨╛.** ╨Я╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╤М тАФ ╤Д╨░╨╣╨╗, ╤Г╤Б╨╗╨╛╨▓╨╕╨╡ тАФ ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╡; ╤Г╤Б╨╗╨╛╨▓╨╕╨╡ ╨╗╨╡╤З╨╕╨╗╨╛╤Б╤М ╤Б╨░╨╝╨╛, ╤Д╨░╨╣╨╗ ╨╛╤Б╤В╨░╨▓╨░╨╗╤Б╤П, ╨╕ `[ -f "$BREAKER" ] && exit 0` ╨│╨╗╤Г╤И╨╕╨╗ ╨║╨░╨╢╨┤╤Л╨╣ ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╕╨╣ ╤В╨╕╨║ ╨╝╨╛╨╗╤З╨░ ╨╕ ╨╜╨░╨▓╤Б╨╡╨│╨┤╨░ (01-08-2026: ╨┐╤А╨╛╨┤ ╨┐╤А╨╛╤Б╤В╨╛╤П╨╗ 5 ╨║╨╛╨╝╨╝╨╕╤В╨╛╨▓ ╨┐╨╛╨╖╨░╨┤╨╕ ~40 ╨╝╨╕╨╜ ╨╜╨░ ╤Г╨╢╨╡ ╨╜╨╡╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╡╨╣ ╨┐╤А╨╕╤З╨╕╨╜╨╡ тАФ ╨│╤А╤П╨╖╨╜╤Л╨╣ `config/marathon_landing_copy.php` ╤Б╤В╨░╨╗ ╤А╨░╨▓╨╡╨╜ `origin/main`, ╨║╨░╨║ ╤В╨╛╨╗╤М╨║╨╛ ╨┤╨╛╨╡╤Е╨░╨╗ [#1045](https://github.com/gasyoun/Systema-Sanscriticum/pull/1045)). ╨в╨╡╨┐╨╡╤А╤М ╨╝╤П╨│╨║╨░╤П ╨╝╨╡╤В╨║╨░ (`[blocked-preflight]`/`[blocked-dirty]`/`[timeout-alive]`/`[rolled-back]`) + ╨╢╨╕╨▓╨╛╨╣ `health_check` + ╨┐╨░╤Г╨╖╨░ `AUTO_DEPLOY_RETRY_AFTER_MINUTES` (30) тЗТ ╨╛╨▒╤С╤А╤В╨║╨░ ╤Б╨╜╨╕╨╝╨░╨╡╤В ╨┐╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╤М ╤Б╨░╨╝╨░ ╨╕ ╨┐╤А╨╛╨▒╤Г╨╡╤В ╨┤╨╡╨┐╨╗╨╛╨╣ ╤Б╨╜╨╛╨▓╨░; ╤Б╤З╤С╤В╤З╨╕╨║ `AUTO_DEPLOY_MAX_AUTO_RETRIES` (3, ╨╛╨▒╨╜╤Г╨╗╤П╨╡╤В╤Б╤П ╤В╨╛╨╗╤М╨║╨╛ ╤Г╤Б╨┐╨╡╤И╨╜╤Л╨╝ ╨┤╨╡╨┐╨╗╨╛╨╡╨╝) ╨╜╨╡ ╨┤╨░╤С╤В ╨╝╨╕╨│╨░╤О╤Й╨╡╨╝╤Г ╤Г╤Б╨╗╨╛╨▓╨╕╤О ╨║╤А╤Г╤В╨╕╤В╤М╤Б╤П ╨▓╨╡╤З╨╜╨╛ ╨╕ ╤З╨╡╤Б╤В╨╜╨╛ ╨┤╨╛╨┐╨╕╤Б╤Л╨▓╨░╨╡╤В ┬лauto-retry ╨╕╤Б╤З╨╡╤А╨┐╨░╨╜┬╗. **╨Ц╤С╤Б╤В╨║╨╛╨╡ (╨▒╨╡╨╖ ╨╝╨╡╤В╨║╨╕) ╤Б╤А╨░╨▒╨░╤В╤Л╨▓╨░╨╜╨╕╨╡ ╨╜╨╡ ╤Б╨╜╨╕╨╝╨░╨╡╤В╤Б╤П ╨░╨▓╤В╨╛╨╝╨░╤В╨╕╤З╨╡╤Б╨║╨╕ ╨╜╨╕╨║╨╛╨│╨┤╨░**, ╨╕ ╨▓ ╨╜╨╡╨╖╨┤╨╛╤А╨╛╨▓╤Г╤О ╨╝╨░╤И╨╕╨╜╤Г ╨░╨▓╤В╨╛-╨┐╨╛╨▓╤В╨╛╤А ╨╜╨╡ ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В╤Б╤П. ╨в╨╡╨║╤Б╤В ╤Б╨╜╤П╤В╨╛╨│╨╛ ╨┐╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╤П ╤Г╨╡╨╖╨╢╨░╨╡╤В ╨▓ `storage/logs/auto_deploy_breaker_history.log`. ╨Я╨╛╨▒╨╛╤З╨╜╨╛: ╤Г╤Б╨┐╨╡╤И╨╜╨░╤П ╤Б╤В╤А╨╛╨║╨░ ╨╗╨╛╨│╨░ ╨┐╨╡╤З╨░╤В╨░╨╗╨░ `mem ?MB` (╨┐╨╡╤А╨╡╨╝╨╡╨╜╨╜╨░╤П ╨▒╤Л╨╗╨░ `local`) тАФ ╤В╨╡╨┐╨╡╤А╤М ╨╜╨░╤Б╤В╨╛╤П╤Й╨╡╨╡ ╤З╨╕╤Б╨╗╨╛. Tests: [`scripts/server_guards/sbin/test_systema_auto_deploy_run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/test_systema_auto_deploy_run.sh) тАФ 14 ╨┐╤А╨╛╨▓╨╡╤А╨╛╨║, ╨┐╨░╨┤╨░╨╡╤В 5/14 ╨┐╤А╨╛╤В╨╕╨▓ ╨┤╨╛-H2149 ╨║╨╛╨┐╨╕╨╕. Docs: [server-resource-guards.md ┬з8.2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md). ╨Я╨╛╤Б╨╗╨╡ merge: `sudo bash scripts/server_guards_apply.sh` ╨╛╨┤╨╕╨╜ ╤А╨░╨╖. Executor: Opus 5 1M (`claude-opus-5[1m]`).

### Added
- **H2148 A: soft-alert agent playbook + cause catalog.** [docs/SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) тАФ soft vs critical, breaker tags (`[blocked-preflight]` / `[timeout-alive]` / тАж), safe vs never-auto, ladders, incident log; CLAUDE.md + deploy.md + server-resource-guards ┬з8.1 pointer. Executor: Grok 4.5 (`grok-4.5`).
- **H2148 B: `php artisan ops:soft-remediate`.** Safe-only: discard tracked dirty that already matches `origin/main` (H2066 rule); optional `--apply-breaker-clear` for soft-tagged `storage/auto_deploy.disabled` only when no diverging dirty. Hard fuse / unique hotfix тЖТ exit 1, never auto-destroy. [`SoftAutoDeployRemediator`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ServerGuards/SoftAutoDeployRemediator.php) + tests. Executor: Grok 4.5 (`grok-4.5`).
- **H2148 C: soft-alert webhook skeleton (default OFF).** `SOFT_ALERT_WEBHOOK_URL` + secret тЖТ POST JSON after soft TG cooldown; n8n workflow skeleton тЖТ GitHub issue; agent stub `scripts/ops/soft_alert_agent_stub.py`. Docs: [docs/ops/SOFT_ALERT_WEBHOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ops/SOFT_ALERT_WEBHOOK.md). Executor: Grok 4.5 (`grok-4.5`).
- **Curator magic-login runbook (RU).** [docs/MANUAL_CURATOR_MAGIC_LOGIN_LINK_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_CURATOR_MAGIC_LOGIN_LINK_RU.md) тАФ one-time `/login-link/{token}` when student forgot password/email; manager + admin; scenarios AтАУE, TG `/unblock`. Cabinet TG post **0D**. Cross-link [student-unblock-access-feed.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-unblock-access-feed.md). Executor: Grok 4.5 (`grok-4.5`).
- **Homework Telegram series.** [marketing/homework-telegram-posts.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/homework-telegram-posts.md) тАФ pin 0тА▓, posts 0AтАУ0C + statuses/files/FAQ; grounded in STUDENT_HOMEWORK_GUIDE + `/faq/dz`. Study-chat calendar. Executor: Grok 4.5 (`grok-4.5`).

## [1.82.1] - 2026-08-02

### Changed
- **Ops docs: prod tracked-dirty тЖТ soft-guards class (H2147).** Always-on pointer in [CLAUDE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md) ┬з Ops; worked case 01-08-2026 (`config/marathon_landing_copy.php` тЖТ `[blocked-preflight]` fuse) in [docs/server-resource-guards.md ┬з8.1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md); dirty-gate example in [docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md). Soft TG ┬л╨Ъ╨░╨▒╨╕╨╜╨╡╤В: soft-╤Б╨▒╨╛╨╣ (guards)┬╗ тЙа cabinet outage. Cross-hub: Uprava FINDINGS ┬з280 + DANGER_FACTS. Executor: Grok 4.5 (`grok-4.5`).
- **Cabinet chat pin phrase updated.** [marketing/cabinet-telegram-posts.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/cabinet-telegram-posts.md) post 0тА▓: pre-cabinet payments count, no re-register, order-email login, curator fallback. Executor: Grok 4.5 (`grok-4.5`).
- **H2143 follow-up: cabinet Telegram posts тАФ mental model (0AтАУ0C).** [marketing/cabinet-telegram-posts.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/cabinet-telegram-posts.md): posts for ┬л╨╡╤Б╤В╤М ╨╗╨╕ ╨║╨░╨▒╨╕╨╜╨╡╤В┬╗, ┬л╨╜╨╡ ╨┐╨╛╨╝╨╜╤О ╨┐╨░╤А╨╛╨╗╤М = ╤В╨╛╤В ╨╢╨╡ ╨┐╤Г╤В╤М┬╗, ┬л╨╜╨╡╤В ╨а╨╡╨│╨╕╤Б╤В╤А╨░╤Ж╨╕╨╕ / ╤Г╤А╨╛╨║╨╕ ╤В╨╛╨╗╤М╨║╨╛ ╨╛╤В ╨╛╨┐╨╗╨░╤В╤Л┬╗, curator table, expanded FAQ post 10, study-chat calendar leading with 0AтАУ0C. Executor: Grok 4.5 (`grok-4.5`).

### Added
- **H2139: ┬л╨б╤В╨░╤А╤В ╤З╤В╨╡╨╜╨╕╤П┬╗ (Akro-style) product registered in Systema docs.** [docs/PRODUCT_START_CHTENIYA_AKRO_STYLE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PRODUCT_START_CHTENIYA_AKRO_STYLE_2026.md) тАФ product register + handoff map H2105тАУH2111; cross-links from SAMSKRTE-TIER0 plan/roadmap and Sanskrit-HUB index/progression. Docs only тАФ no SKU/code. Executor: Grok 4.5 (grok-4.5).

## [1.82.0] - 2026-08-01

### Changed
- **Homework FAQ URL canonical in docs:** onboarding + student-manual + lesson ┬л╨║╨░╨║ ╤Б╨┤╨░╨▓╨░╤В╤М┬╗ тЖТ [samskrte.ru/faq/dz](https://samskrte.ru/faq/dz) (`route('faq.dz')`). Executor: Grok 4.5 (`grok-4.5`).

### Added
- **H2143: Telegram series for student cabinet (`/dvaram`).** [marketing/cabinet-telegram-posts.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/cabinet-telegram-posts.md) тАФ 10 paste-ready posts (entry, lessons, homework, dictionary, Telegram/support, access/debts, prana, practice pointer, FAQ) + curator map + channel calendar. Grounded in onboarding-student + student-manual. Executor: Grok 4.5 (`grok-4.5`).
- **H1947: ╤А╨╡╨╢╨╕╨╝ ╨┐╤А╨╛╤Б╨╝╨╛╤В╤А╨░ ╨╖╨░ ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤П (┬л╨▓╨╛╨╣╤В╨╕ ╨║╨░╨║┬╗).** ╨б╤Г╨┐╨╡╤А-╨░╨┤╨╝╨╕╨╜ ╨╕╨╖ ┬л╨б╤В╤Г╨┤╨╡╨╜╤В╨╛╨▓┬╗ ╨╛╤В╨║╤А╤Л╨▓╨░╨╡╤В ╨║╨░╨▒╨╕╨╜╨╡╤В ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░ (`/dvaram`) ╨╕╨╗╨╕ ╨┐╨░╨╜╨╡╨╗╤М ╨║╤Г╤А╨░╤В╨╛╤А╨░ (`manager`), ╨╜╨╡ ╨╝╨╡╨╜╤П╤П `users.role` ╨╕ ╨╜╨╡ ╨╖╨╜╨░╤П ╤З╤Г╨╢╨╛╨│╨╛ ╨┐╨░╤А╨╛╨╗╤П: ╤Б╨╡╤Б╤Б╨╕╤П ╨┐╨╛╨┤╨╝╨╡╨╜╤П╨╡╤В╤Б╤П ╨╜╨░ ╤Ж╨╡╨╗╨╡╨▓╨╛╨│╨╛ ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤П, ╤Б╨▓╨╡╤А╤Е╤Г ╨▓╨╕╤Б╨╕╤В ╨╜╨╡╤Б╤К╤С╨╝╨╜╨░╤П ╨┐╨╗╨░╤И╨║╨░ ╤Б ╨▓╤Л╤Е╨╛╨┤╨╛╨╝ ╨▓ ╨╛╨┤╨╕╨╜ ╨║╨╗╨╕╨║, ╤Б╤В╨░╤А╤В ╨╕ ╨▓╤Л╤Е╨╛╨┤ ╨┐╨╕╤И╤Г╤В╤Б╤П ╨▓ `impersonation_audits` (Filament read-only ┬л╨Т╤Е╨╛╨┤╤Л ╨╖╨░ ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤П┬╗). ╨Я╨╛╨┤ ╨║╤Г╤А╨░╤В╨╛╤А╨╛╨╝ ╨┐╤А╨░╨▓╨░ ╤Б╤Г╨┐╨╡╤А-╨░╨┤╨╝╨╕╨╜╨░ **╨╜╨╡ ╨┤╨╡╨╣╤Б╤В╨▓╤Г╤О╤В** тАФ `RoleGate`/`isSuperAdmin()` ╨▓╨╕╨┤╤П╤В ╤А╨╛╨▓╨╜╨╛ ╨║╤Г╤А╨░╤В╨╛╤А╨░, ╨┐╨╛╤В╨╛╨╝╤Г ╤З╤В╨╛ ╤Б╤Г╨┐╨╡╤А-╨░╨┤╨╝╨╕╨╜╨░ ╨▓ ╤Б╨╡╤Б╤Б╨╕╨╕ ╨▓ ╤Н╤В╨╛╤В ╨╝╨╛╨╝╨╡╨╜╤В ╨╜╨╡╤В. ╨Ф╨╡╨╜╨╡╨╢╨╜╤Л╨╡ ╨Ч╨Р╨Я╨Ш╨б╨Ш ╨▓ ╤А╨╡╨╢╨╕╨╝╨╡ ╨╖╨░╨┐╤А╨╡╤Й╨╡╨╜╤Л (403 + ╨╗╨╛╨│; ╤Б╨┐╨╕╤Б╨║╨╕ тАФ [config/impersonation.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/impersonation.php)); ╤А╨╡╨╢╨╕╨╝ ╨╜╨╡ ╨╜╨░╨║╤А╤Г╤З╨╕╨▓╨░╨╡╤В ╤Б╤В╤Г╨┤╨╡╨╜╤В╤Г `login_count`/╨░╨║╤В╨╕╨▓╨╜╨╛╤Б╤В╤М ╨╕ ╨╜╨╡ ╤И╨╗╤С╤В ╨╡╨╝╤Г ╨╛╨╜╨▒╨╛╤А╨┤╨╕╨╜╨│-╨┐╨╕╨╜╨│ ┬л╨┐╨╡╤А╨▓╤Л╨╣ ╨▓╤Е╨╛╨┤┬╗; ╨▓╨╗╨╛╨╢╨╡╨╜╨╜╨╛╤Б╤В╤М ╨╕ ╨▓╤Е╨╛╨┤ ╨┐╨╛╨┤ ╨┤╤А╤Г╨│╨╕╨╝ ╤Б╤Г╨┐╨╡╤А-╨░╨┤╨╝╨╕╨╜╨╛╨╝ ╨╖╨░╨┐╤А╨╡╤Й╨╡╨╜╤Л. ╨д╨╗╨░╨│ `STAFF_IMPERSONATION` ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О **OFF** (╨╝╨░╤А╤И╤А╤Г╤В╤Л 404, ╨║╨╜╨╛╨┐╨╛╨║ ╨╜╨╡╤В, ╨╛╤В╨║╤А╤Л╤В╤Л╨╣ ╤А╨╡╨╢╨╕╨╝ ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В╤Б╤П fail-closed). Docs: [admin-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/admin-manual.md) ┬з5.4. Tests: `StaffImpersonationTest`. Executor: Opus 5 1M (`claude-opus-5[1m]`).
- **Public FAQ for homework:** `/faq/dz` (`faq.dz`) тАФ student DZ guide without login (chat-friendly). `/help/homework` тЖТ 301 to `/faq/dz`. Executor: Grok 4.5 (`grok-4.5`).
- **Cabinet URL for homework help:** `/help/homework` (`help.homework`) тАФ student-facing guide inside the cabinet layout; link ┬л╨║╨░╨║ ╤Б╨┤╨░╨▓╨░╤В╤М┬╗ on the lesson homework block. Source doc: [STUDENT_HOMEWORK_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_HOMEWORK_GUIDE_RU.md). Executor: Grok 4.5 (`grok-4.5`).
- **H2134: student homework guide (RU).** [docs/STUDENT_HOMEWORK_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_HOMEWORK_GUIDE_RU.md) тАФ where to submit, when the form appears, file limits, statuses, fix wrong file, FAQ. Linked from onboarding + student-manual. Executor: Grok 4.5 (`grok-4.5`).
- **Homework delete audit trail.** Every file/message wipe leaves a `type=message` note in the homework thread with original filename(s) and actor; timestamp is `created_at`. Bulk clear lists names, not only a count. Audit notes cannot be deleted by the student. Follow-up to H2120. Executor: Grok 4.5 (`grok-4.5`).
- **H2120: ╤И╤В╨░╤В ╤В╨╛╨╢╨╡ ╤Б╤В╨╕╤А╨░╨╡╤В ╨╖╨░╨╗╨╕╤В╤Л╨╡ ╤Д╨░╨╣╨╗╤Л ╨Ф╨Ч.** ╨Я╨╛╨▓╨╡╤А╤Е ╤Г╨╢╨╡ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╡╨│╨╛ ╤Б╤В╤Г╨┤╨╡╨╜╤З╨╡╤Б╨║╨╛╨│╨╛ delete (#1028/#1030): ╨░╨┤╨╝╨╕╨╜ / ╨┐╤А╨╡╨┐╨╛╨┤ ╨║╤Г╤А╤Б╨░ / ╨┐╤А╨╛╨▓╨╡╤А╤П╤О╤Й╨╕╨╣ ╨│╤А╤Г╨┐╨┐╤Л тАФ ╨║╨╛╤А╨╖╨╕╨╜╨░ ╤Г ╨╗╤О╨▒╨╛╨│╨╛ ╤Д╨░╨╣╨╗╨░ ╨▓ ╤В╤А╨╡╨┤╨╡ ┬л╨Ф╨╛╨╝╨░╤И╨╜╨╕╨╡ ╤А╨░╨▒╨╛╤В╤Л┬╗ + bulk **┬л╨б╤В╨╡╤А╨╡╤В╤М ╤Д╨░╨╣╨╗╤Л ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░┬╗** (wipe + ┬л╨Э╨░ ╨┤╨╛╤А╨░╨▒╨╛╤В╨║╤Г┬╗). `HomeworkService::deleteFileAsStaff` / `clearStudentFiles` / `actorIsStaffFor`. Tests: `HomeworkFileDeleteTest`. Executor: Grok 4.5 (`grok-4.5`).
- **H2027 P1: PayPal Subscriptions webhook ledger + Payment.** Signature verify (Http + test double), `PaymentWebhookEvent` idempotency, `BILLING.SUBSCRIPTION.*` тЖТ `billing_subscriptions`, `PAYMENT.SALE.COMPLETED` тЖТ `Payment` provider `paypal_subscription` paid. Flag still default OFF. Money PR no-auto-merge. Grok 4.5 (`grok-4.5`).
- **H2130: ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╨╡ ╨┤╨╛╨╗╨╢╨╜╨╕╨║╨░ ╨╕╨╖ ╤Г╤З╨╡╨▒╨╜╨╛╨│╨╛ TG-╤З╨░╤В╨░ ╤Б ┬л╨Ф╨╛╨╗╨╢╨╜╨╕╨║╨╛╨▓┬╗.** ╨Ь╨╡╨╜╤О ╤Б╤В╤А╨╛╨║╨╕ / bulk ┬л╨Ш╤Б╨║╨╗╤О╤З╨╕╤В╤М ╨╕╨╖ TG-╤З╨░╤В╨░┬╗ + ╨╛╨┐╤Ж╨╕╤П ╨┐╤А╨╕ ЁЯЪй ┬л╨в╨░╨║╨╢╨╡ ╨╕╤Б╨║╨╗╤О╤З╨╕╤В╤МтАж┬╗ ╤З╨╡╤А╨╡╨╖ `@zapisi_ORSbot` hard ban (`ZapisiChatMemberService::kickFromStudyChats`). ╨д╨╕╨╗╤М╤В╤А ╨╖╨╗╨╛╤Б╤В╨╜╤Л╤Е = **┬л╨Э╨╡╨▒╨╗╨░╨│╨╛╨╜╨░╨┤╤С╨╢╨╜╤Л╨╡┬╗ / ЁЯЪй**. LMS-╤З╨╗╨╡╨╜╤Б╤В╨▓╨╛ ╨╜╨╡ ╤В╤А╨╛╨│╨░╨╡╨╝; ╤А╨░╨╖╨▒╨░╨╜ тАФ ╨┤╨░╤И╨▒╨╛╤А╨┤ ┬л╨Ч╨░╨┐╨╕╤Б╨╕ (╨▒╨╛╤В)┬╗. Docs: [debtors-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtors-manual.md) ┬з11. Tests: `DebtorsKickFromTelegramTest`, extended `ZapisiChatMemberServiceTest`. Executor: Grok 4.5 (`grok-4.5`).
- **H2119 NOBORING Wave 1b: unpaid open-Deal WorkQueue bucket.** `WorkQueueReport::unpaidOpenDeals()` lists open Deals older than `DOZHIM_UNPAID_DEAL_HOURS` (default 24); Filament WorkQueue card ┬л╨Э╨╡╨┤╨╛╨╢╨░╤В╤Л╨╡ ╤Б╨┤╨╡╨╗╨║╨╕┬╗; flag `dozhim_queue` default **OFF**. Read-only, rank-4. Tests: `DozhimUnpaidDealQueueTest`. Residual of H2059: templates + drip still open. Executor: Grok 4.5 (`grok-4.5`).
- **H2102 open Deal on pending payable intent.** [PaymentDealBridgeObserver](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentDealBridgeObserver.php): pending course-sale Payment opens Deal on first stage (idempotent per user/lead+course+installment group); paid path still closes that open Deal to won. Flag `crm_pipeline_board` default **OFF** (prod-inert). Rank 4 only тАФ never grants access. Tests: `DealTest` pending suite + `DealFlagDefaultTest` pending silence. Roadmap Wave 1a open-on-pending checkbox ticked. Executor: Grok 4.5 (`grok-4.5`).
- **H2097 Wave 1a Deal-create audit.** Confirmed **paid bridge only**: sole `Deal::create` is [PaymentDealBridgeObserver](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentDealBridgeObserver.php) after qualifying paid Payment тЖТ **won** Deal; no open Deal on pending intent; Filament kanban has no create. Documented in [docs/ROADMAP_Systema_NOBORING_DOZHIM_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_Systema_NOBORING_DOZHIM_2026H2.md). Next residual: open-on-pending (H2058 тЖТ H2102). Executor: Grok 4.5 (`grok-4.5`).
- **H2096 NOBORING Wave 0 numbers recorded.** Prod orderтЖТpay baseline (30d Rate A **65.6%** / 90d **85.7%**; Rate B unusable тАФ `converted_at` sparse) written into [docs/ROADMAP_Systema_NOBORING_DOZHIM_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_Systema_NOBORING_DOZHIM_2026H2.md) + ORS Noboring section; Wave 0 measure checkboxes complete. Reproduce after deploy: `php artisan dozhim:baseline --json`. Executor: Grok 4.5 (`grok-4.5`).
- **H2094 NOBORING Wave 0 baseline: `php artisan dozhim:baseline`.** Dual rates for last 30/90d тАФ **A** orderтЖТpay (reuses H262 `OrderPaymentConversionService` cohort filter) and **B** LeadтЖТ`converted_at`. Wrapper [`tools/order_pay_conversion_baseline.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tools/order_pay_conversion_baseline.php). Tests: `DozhimBaselineTest`. Roadmap Wave 0 first checkbox ticked. Executor: Grok 4.5 (`grok-4.5`).
- **H1992: koloda hub language filter (`?lang=sa|hi|all`).** Public `/koloda` and cabinet `/dvaram/koloda` list hubs filter by `SrsDeck.language` (default `sa`; null language counts as sa; tabs ╨б╨░╨╜╤Б╨║╤А╨╕╤В / ╨е╨╕╨╜╨┤╨╕ / ╨Т╤Б╨╡). Deep links `/koloda/{slug}` unchanged. Tests in `SrsPublicDeckUrlsTest`. Executor: Grok 4.5 (`grok-4.5`).

### Changed
- **debtors-manual full sync after H2130.** MD + PDF Blade: ┬з4 filter ЁЯЪй = ┬л╨╖╨╗╨╛╤Б╤В╨╜╤Л╨╣┬╗, ┬з5 menu ┬л╨Ш╤Б╨║╨╗╤О╤З╨╕╤В╤М ╨╕╨╖ TG-╤З╨░╤В╨░┬╗, ┬з11 preconditions + workflow, ┬з14 bulk kick, ┬з15 no auto-kick on recount, ┬з16 FAQ (5 Q). Metadoc bumped. Executor: Grok 4.5 (`grok-4.5`).
- **Promo capacity reservations ON (`CHECKOUT_PROMO_RESERVATIONS`).** Prod `.env` + code default **true**: live pending holds promo `usage_limit` slots (Tochka link TTL 30 min + 10 min webhook buffer); pairs with stale-checkout reaper. Opt-out: `CHECKOUT_PROMO_RESERVATIONS=false`. Register: [docs/MONEY_FALSE_ECONOMY_DARK_FLAGS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONEY_FALSE_ECONOMY_DARK_FLAGS_2026.md). Executor: Grok 4.5 (`grok-4.5`).
- **Money MUST-ON guards armed (MG 01-08-2026 false-economy).** Prod `.env` + code defaults **true** for: `TOCHKA_WEBHOOK_GUARD`, `CHECKOUT_DEPOSIT_REVERSAL`, `CHECKOUT_REFERRAL_CREDIT_LOCK`, `CHECKOUT_INACTIVE_TARIFF_GUARD`, `CHECKOUT_PROMO_SURVIVES_SESSION`, `CHECKOUT_SESSION_LAPSE_RELOGIN`, `CHECKOUT_SIGNED_RETURN_URL`, `CHECKOUT_STALE_ORDER_EXPIRY`, `CHECKOUT_PROMO_RESERVATIONS`. Still dark (ops/scaffold): integrity safe-repairs, Tochka recurring, PayPal subscriptions. Register: [docs/MONEY_FALSE_ECONOMY_DARK_FLAGS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONEY_FALSE_ECONOMY_DARK_FLAGS_2026.md). Executor: Grok 4.5 (`grok-4.5`).
- **Stale-checkout reaper ON by default + false-economy policy (MG 01-08-2026).** `features.checkout_stale_order_expiry` default `true`; prod first apply released 18 stale pending. Executor: Grok 4.5 (`grok-4.5`).

### Fixed
- **H2104: auto-deploy timeout no longer false-critical ┬л╨║╨░╨▒╨╕╨╜╨╡╤В ╤Г╨┐╨░╨╗┬╗.** `deploy.sh` skips `npm ci && build` when asset paths unchanged (and `public/build/manifest.json` exists; `FORCE_NPM=1` forces rebuild) тАФ docs/PHP-only slots no longer burn the 1500s budget on vite. `systema-auto-deploy-run.sh` trips soft `[timeout-alive]` when deploy/rollback exits 124/137 (or rollback fails) but post-health smoke is green; `guards:verify` treats that tag as warning (with `[rolled-back]`/`[blocked-preflight]`). TG critical text: Artem only for host-down; auto-deploy fuse gets fuse-specific runbook. After merge: `sudo bash scripts/server_guards_apply.sh` once for `/usr/local/sbin`. Executor: Grok 4.5 (`grok-4.5`).
- **Stale-checkout reaper schedule no longer ERROR every 15 min when flag is off.** `payments:expire-stale-checkouts --apply` is only scheduled when `CHECKOUT_STALE_ORDER_EXPIRY=true`; with the flag off, `--apply` is a soft no-op (exit 0, warn, no mutation). Stops `production.ERROR: Scheduled command тАж failed` noise. Executor: Grok 4.5 (`grok-4.5`).
- **H2066: auto-deploy dirty-tree no longer false-critical ┬л╨║╨░╨▒╨╕╨╜╨╡╤В ╤Г╨┐╨░╨╗┬╗.** `deploy.sh --rollback` skips dirty-gate (`reset --hard` clears tracked dirty); forward deploy auto-discards tracked files already equal to `origin/main`; auto-deploy trips soft `[blocked-preflight]` when HEAD did not move and health is green; `guards:verify` treats `[blocked-preflight]`/`[rolled-back]` as warning and warns early on non-PDF tracked dirty. Docs: deploy.md, server-resource-guards.md. Executor: Grok 4.5 (`grok-4.5`).

### Changed
- **H1962 n8n hygiene/prune-storage тАФ binary residue 6.6G тЖТ 1.4G (тИТ5.2G).** Live host `193.232.229.91`: removed 14 older ZOOM (`1EIqqNzMl5NNIxST`) execution `binary_data` dirs + entire inactive binary trees `mkct0W3oFHftaBah` (╤В╨░╨╣╨╝╨║╨╛╨┤╤Л) and `T8scvz2KZpKNuF1B` (╤В╤А╨░╨╜╤Б╨║╤А╨╕╨▒); kept last two ZOOM successes (exec **351**, **173**). Workflow definitions / Active set (5) / count (76) untouched; healthz 200. Ops note: [docs/n8n/OPS_PRUNE_STORAGE_H1962_2026-08-01.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/OPS_PRUNE_STORAGE_H1962_2026-08-01.md). Executor: Grok 4.5 (`grok-4.5`). Tracks [#1001](https://github.com/gasyoun/Systema-Sanscriticum/issues/1001).
- **H2067 / CABINET_ADOPTION P0: password/cabinet-login mail subject.** `PasswordResetMail` subject is now **┬л╨Т╤Е╨╛╨┤ ╨▓ ╨╗╨╕╤З╨╜╤Л╨╣ ╨║╨░╨▒╨╕╨╜╨╡╤В ╨Ю╨а╨б┬╗** (not ┬л╨б╨▒╤А╨╛╤Б ╨┐╨░╤А╨╛╨╗╤П┬╗ / ┬л╨Т╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╨╡ ╨┤╨╛╤Б╤В╤Г╨┐╨░тАж┬╗); body CTA framed as cabinet entry. Prod deliverability evidence (Yandex SMTP + SPF/DKIM/DMARC on `yandex.ru` From domain, `mail:preflight` OK, Horizon `mailing`) lives in ORS-FAQ `CABINET_ADOPTION_ROADMAP.md`. Executor: Grok 4.5 (`grok-4.5`).

## [1.81.5] - 2026-08-01
### Changed
- **H1965 n8n bridge import-gaps тАФ product-gated GTD defers (no placeholder import).** Live re-probe on `193.232.229.91`: `schedule-sheet-sync` / `vk-calendar-post` still **missing** (webhook 404); monthly `eixPIvFjfPdOSrYo` present **OFF**. Disposition: explicit GTD defer for sheet + calendar until product arms them; monthly activate blocked on H1959 token hygiene. Ops note: [docs/n8n/OPS_IMPORT_GAPS_H1965_2026-08-01.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/OPS_IMPORT_GAPS_H1965_2026-08-01.md). Catalog gap table + ROADMAP Wave 3 updated. No ON workflow mutation; no Laravel env secrets written. Executor: Grok 4.5 (`grok-4.5`).

## [1.81.4] - 2026-08-01
### Security
- **H1960: Header Auth on n8n payments sheet webhook (C03).** n8n workflow `╨Р╨Ф╨Ь╨Ш╨Э╨Ъ╨Р+╨в╨Р╨С╨Ы╨Ш╨ж╨Р ╨Ю╨Я╨Ы╨Р╨в` (`/webhook/payments`) requires `X-Webhook-Secret`; Laravel [`SendPaymentToSheetJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/SendPaymentToSheetJob.php) sends `N8N_PAYMENTS_WEBHOOK_SECRET` when set. No sheet mapping or payment business-logic changes. Secret lives only on n8n host (`/root/.n8n-payments-webhook-secret`) and Laravel `.env` тАФ never in git. Executor: Grok 4.5 (`grok-4.5`).

## [1.81.3] - 2026-08-01
### Added
- **H1963 n8n hygiene/archive-tags - tag set rchive + legacy on 28 inactive workflows.** Live host 193.232.229.91: created tags rchive (f7aa560d1a125ba) + legacy (2e703e2a44a2070); applied to **20** My workflow* + **8** inactive ZOOM* (not the Active canonical 1EIqqNzMl5NNIxST). Active set (5) unchanged; workflow count still 76; **no deletes**. CATALOG 7b tag-set addendum + [docs/n8n/exports/h1963-archive-legacy-tagged-ids_2026-07-31.csv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/exports/h1963-archive-legacy-tagged-ids_2026-07-31.csv). Executor: Grok 4.5 (grok-4.5).

## [1.81.2] - 2026-08-01

### Fixed
- **H1958 n8n sec/libfl-rotate тАФ libfl password out of bookbuilder SSH node.** Live `╨б╨С╨Ю╨а╨Ъ╨Р ╨Ъ╨Э╨Ш╨У` node calls only `/opt/bookbuilder/auto_order_from_env.sh "<url>"`; secrets live in host `/root/.libfl-env` (mode 600). Live n8n sqlite has no password/login strings. Scrubbed git export [`docs/n8n/exports/book-assembly.live.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/exports/book-assembly.live.json) + inventory snapshot; credential audit C01 marked remediated. Residual: human confirms one libfl login; Jul-25 sqlite bak shred if still present. Executor: Grok 4.5 (`grok-4.5`).

### Added
- **╨н╨║╤Б╨┐╨╛╤А╤В SRS-╨║╨░╤А╤В╨╛╤З╨╡╨║ ╨▓ CSV + ╨╕╨╜╤Б╤В╤А╤Г╨║╤Ж╨╕╤П ┬лSystema, ╨╜╨╡ Memrise┬╗ ╨┤╨╗╤П ╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤П.** ╨Ъ╨╜╨╛╨┐╨║╨░ **╨н╨║╤Б╨┐╨╛╤А╤В CSV** ╨▓ Filament ┬лSRS тАФ ╨║╨░╤А╤В╨╛╤З╨║╨╕┬╗ ([`SrsCardExporter`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Exports/SrsCardExporter.php)) тАФ ╨▒╤Н╨║╨░╨┐ ╨╕ ╨╛╨┐╤Ж╨╕╨╛╨╜╨░╨╗╤М╨╜╨░╤П ╨╖╨░╨╗╨╕╨▓╨║╨░ ╨▓ Memrise. ╨Ь╨░╨╜╤Г╨░╨╗: [MANUAL_TEACHER_SRS_KOLODA_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_TEACHER_SRS_KOLODA_RU.md) тАФ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║ ╨┐╤А╨░╨▓╨┤╤Л samskrte.ru. Executor: Grok 4.5 (`grok-4.5`).
- **╨Я╨╛╨╗╨╜╨░╤П ╨│╨╡╨╣╨╝╨╕╤Д╨╕╨║╨░╤Ж╨╕╤П: ╨╝╤Г╨╗╤М╤В╨╕-╨┤╨╛╤Б╨║╨╕ ╨╗╨╕╨┤╨╡╤А╨╛╨▓ + ╨┐╨╡╤А╨╡╨╜╨╛╤Б ╨╛╤З╨║╨╛╨▓ Memrise (H2054).** ╨а╨╡╨╡╤Б╤В╤А [`config/leaderboards.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/leaderboards.php) (╨▓╤Б╨╡ ╤В╨╛╨┐╤Л ╨▓╨║╨╗╤О╤З╨╡╨╜╤Л; ╨╜╨╡╨╜╤Г╨╢╨╜╤Л╨╡ тАФ `enabled => false`): **╨Я╤А╨░╨╜╨░**, **SRS** (╨▓╤Б╨╡ / ╨▓╤Б╨╡ Memrise / ╨Я╤А╨╛╨┤╨╗╤С╨╜╨║╨░ 6679375 / 6502608 / 6508023 / 6517849 / 6522419 / Anki Hindi), **/lila** (╨▓╤Б╨╡ + sort/match/cloze/table), **Memrise import** (╤Б╨╜╨╕╨╝╨╛╨║ CSV ╨┐╨╛ ╨║╤Г╤А╤Б╨░╨╝), **╨║╨╛╨╝╨▒╨╛**. ╨Я╨╡╤А╨╕╨╛╨┤╤Л Week/Month/All. ╨Ь╨╕╨│╤А╨░╤Ж╨╕╤П: `memrise_leaderboard_imports`, `lila_score_events`, `users.memrise_username`. Artisan: `leaderboard:import-memrise`. Telemetry COMPLETE тЖТ lila (auth only; `game_events` ╨╛╤Б╤В╨░╤О╤В╤Б╤П ╨░╨╜╨╛╨╜╨╕╨╝╨╜╤Л╨╝╨╕). Service: [`LeaderboardService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Leaderboard/LeaderboardService.php). ╨Ъ╨░╨▒╨╕╨╜╨╡╤В: multi-board UI. Executor: Grok 4.5 (`grok-4.5`).
- **╨в╨░╨▒╨╗╨╕╤Ж╨░ ╨╗╨╕╨┤╨╡╤А╨╛╨▓ Week / Month / All Time (Memrise-╨░╨╜╨░╨╗╨╛╨│, H2051).** ╨Т╨║╨╗╨░╨┤╨║╨░ ┬л╨Я╤А╨░╨╜╨░┬╗ ╨▓ ╨║╨░╨▒╨╕╨╜╨╡╤В╨╡: **╨Э╨╡╨┤╨╡╨╗╤П** (╤Б ╨┐╨╜), **╨Ь╨╡╤Б╤П╤Ж** (╤Б 1-╨│╨╛), **╨Т╤Б╤С ╨▓╤А╨╡╨╝╤П** (`lifetime_prana`). ╨Я╨╡╤А╨╕╨╛╨┤╤Л ╤Б╤З╨╕╤В╨░╤О╤В ╤Б╤Г╨╝╨╝╤Г ╨╜╨░╤З╨╕╤Б╨╗╨╡╨╜╨╕╨╣ `prana_transactions` (amount > 0); ╤В╤А╨░╤В╤Л ╨╜╨╡ ╨▓╤Е╨╛╨┤╤П╤В. [`PranaLeaderboard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/PranaLeaderboard.php) + partial. Per-game / per-deck ╤В╨╛╨┐╤Л тАФ out of scope. Executor: Grok 4.5 (`grok-4.5`).
- **╨б╨╗╨╛╨▓╨░╤А╨╜╤Л╨╣ ╨╖╨░╨┐╨░╤Б (DictionaryWord) ╨┤╨╛╤Б╤В╤Г╨┐╨╡╨╜ ╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤П╨╝ (H2050).** Filament **╨Ф╨╛╨┐╨╝╨░╤В╨╡╤А╨╕╨░╨╗╤Л тЖТ ╨б╨╗╨╛╨▓╨░╤А╨╜╤Л╨╣ ╨╖╨░╨┐╨░╤Б** тАФ create/edit/delete ╨┤╨╗╤П `teacher` ╨╕ admin (╨Х╨╗╨╡╨╜╨░ ╨в╤А╨╡╤Д╨╕╨╗╨╛╨▓╨░ тАФ ╨╛╤Б╨╜╨╛╨▓╨╜╨╛╨╣ ╤А╨╡╨┤╨░╨║╤В╨╛╤А). ╨Ъ╨╛╨╜╤В╨╡╨╣╨╜╨╡╤А╤Л ┬л╨б╨╗╨╛╨▓╨░╤А╨╕┬╗ ╨╛╤Б╤В╨░╤О╤В╤Б╤П admin-only. Manual updated. Executor: Grok 4.5 (`grok-4.5`).
- **Memrise 6679375 ┬л╨Я╤А╨╛╨┤╨╗╤С╨╜╨║╨░┬╗ seed + teacher SRS authoring (H1993 / H2049).** Seed `database/seeders/data/memrise_6679375/` (10 levels, 166 cards, validate OK). Filament **SRS тАФ ╨║╨╛╨╗╨╛╨┤╤Л / ╨║╨░╤А╤В╨╛╤З╨║╨╕** open to role `teacher` (was admin-only; roadmap Wave 2 always said teacher CRUD). Prod: `php artisan srs:import-memrise database/seeders/data/memrise_6679375` + link Trefilova user `role=teacher` / `teacher_id=7`. Manual: [MANUAL_TEACHER_SRS_KOLODA_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_TEACHER_SRS_KOLODA_RU.md). Executor: Grok 4.5 (`grok-4.5`).
- **H2027 Phase 0: PayPal Subscriptions architecture + dark stubs.** Separate diaspora auto-bill lane from Tochka H2026: design of record [ARCHITECTURE_PAYPAL_SUBSCRIPTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_PAYPAL_SUBSCRIPTIONS_2026.md) maps Plan/Subscription onto shared `billing_*` with `provider=paypal`; `PAYPAL_SUBSCRIPTIONS_ENABLED` default false; REST env placeholders; empty `PaypalSubscriptionsService` + webhook route (404 when OFF); `Payment::PROVIDER_PAYPAL_SUBSCRIPTION`. Manual claim path unchanged. No prod enable. Money PR no-auto-merge. Executor: Grok 4.5 (`grok-4.5`).
- **#╨Ф╨Ч ╨╕╨╖ Telegram-╤З╨░╤В╨░ ╨│╤А╤Г╨┐╨┐╤Л тЖТ prompt ╤Г╤А╨╛╨║╨░ (╨▓╨╛╨╗╨╜╨░ 2).** ╨Я╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤М (teacher/manager/admin) ╨┐╨╕╤И╨╡╤В ╨▓ ╤З╨░╤В `#╨Ф╨Ч тАж` ╨╕╨╗╨╕ `/dz тАж` тАФ ╨┐╨╗╨░╤В╤Д╨╛╤А╨╝╨░ ╨╛╨▒╨╜╨╛╨▓╨╗╤П╨╡╤В `homework_prompt` ╨╕ ╨▓╨║╨╗╤О╤З╨░╨╡╤В ╨┐╤А╨╕╤С╨╝. ╨а╨╡╨╖╨╛╨╗╨▓╨╡╤А **CтЖТBтЖТAтЖТG**: reply ╨╜╨░ ╨┐╨╛╤Б╤В ╨▒╨╛╤В╨░ ┬л╨Ф╨Ч ╨╛╤В╨║╤А╤Л╤В╨╛┬╗ (`lesson_telegram_hooks`) тЖТ ╨╛╨┤╨╜╨╛ ╨╛╤В╨║╤А╤Л╤В╨╛╨╡ placeholder-╨Ф╨Ч тЖТ ╤Б╨▓╨╡╨╢╨░╤П ╨╖╨░╨┐╨╕╤Б╤М ╨╖╨░ `HOMEWORK_TG_TAG_LOOKBACK_HOURS` (72) тЖТ ╨╕╨╜╨░╤З╨╡ inline-╨║╨╜╨╛╨┐╨║╨╕. ╨Я╤А╨╕ generic-╨░╨▓╤В╨╛╨╛╤В╨║╤А╤Л╤В╨╕╨╕ ╨▒╨╛╤В ╤Б╨░╨╝ ╨┐╨╛╤Б╤В╨╕╤В ╤П╨║╨╛╤А╤М ╨┤╨╗╤П reply. ╨д╨╗╨░╨│ `HOMEWORK_TG_TAG_ENABLED` (╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О ON). Ops: ╨▒╨╛╤В ╨║╨░╨▒╨╕╨╜╨╡╤В╨░ ╨▓ ╤З╨░╤В╨╡ + `groups.telegram_chat_id` + privacy mode OFF ╨╕╨╗╨╕ `/dz`. Executor: Grok 4.5 (`grok-4.5`).
- **╨Р╨▓╤В╨╛╨╛╤В╨║╤А╤Л╤В╨╕╨╡ ╨Ф╨Ч ╤Б ╤Д╨╛╤А╨╝╤Г╨╗╨╕╤А╨╛╨▓╨║╨╛╨╣ ┬л╨Ф╨╛╨╝╨░╤И╨╜╨╡╨╡ ╨╖╨░╨┤╨░╨╜╨╕╨╡┬╗ тАФ generic/Hindi track.** ╨а╤П╨┤╨╛╨╝ ╤Б ╨┐╨╕╨╗╨╛╤В╨╛╨╝ ╨Ъ╨╛╤З╨╡╤А╨│╨╕╨╜╨╛╨╣ (H1764, +12╤З тЖТ 09:00, ╤В╨╡╨║╤Б╤В ╤Г╤З╨╡╨▒╨╜╨╕╨║╨░) тАФ ╨▓╤В╨╛╤А╨╛╨╣ ╨╛╤Е╨▓╨░╤В: `HOMEWORK_AUTO_OPEN_GENERIC_COURSES` (╨┐╤Г╤Б╤В╨╛ = ╤Б╨┐╨╕╤В). ╨Я╤А╨╕ **╨┐╨╡╤А╨▓╨╛╨╝** ╨┐╨╛╤П╨▓╨╗╨╡╨╜╨╕╨╕ ╨╖╨░╨┐╨╕╤Б╨╕ ╤Г╤А╨╛╨║ ╤Б╤А╨░╨╖╤Г ╨┐╨╛╨╗╤Г╤З╨░╨╡╤В `homework_enabled`, prompt ╨╕╨╖ `HOMEWORK_AUTO_OPEN_GENERIC_PROMPT` (╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О ┬л╨Ф╨╛╨╝╨░╤И╨╜╨╡╨╡ ╨╖╨░╨┤╨░╨╜╨╕╨╡┬╗) ╨╕ ╨╛╨┤╨╕╨╜ ╨┐╤Г╤И ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░╨╝; ╨╜╨░╨┐╨╕╤Б╨░╨╜╨╜╤Л╨╣ ╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╨╡╨╝ prompt ╨╜╨╡ ╨┐╨╡╤А╨╡╤В╨╕╤А╨░╨╡╤В╤Б╤П, ╤А╤Г╤З╨╜╨╛╨╡ ╨▓╨║╨╗╤О╤З╨╡╨╜╨╕╨╡ ╨Ф╨Ч ╨░╨▓╤В╨╛╨╝╨░╤В ╨╜╨╡ ╨┐╨╡╤А╨╡╤Е╨▓╨░╤В╤Л╨▓╨░╨╡╤В. ╨Ъ╨╛╤З╨╡╤А╨│╨╕╨╜╨░ ╨╕ hourly `homework:auto-open` ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В╤Л. Executor: Grok 4.5 (`grok-4.5`).
- **H2026 Phase 0: Tochka multi-mode recurring scaffold (dark flags).** Architecture modes AтАУE (docs already on main via PR #975) plus additive tables `billing_profiles` / `billing_subscriptions` / `billing_commitments`, models with enum casts, factories, and `TochkaRecurring` gate. Master `TOCHKA_RECURRING_ENABLED` default **false**; `TOCHKA_RECURRING_MODES` allow-list default `per_course,club,installment`. No live Tochka subscription API, no student UI, no access-path change while flag OFF. Docs: [ARCHITECTURE_TOCHKA_RECURRING_BILLING_MODES_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_TOCHKA_RECURRING_BILLING_MODES_2026.md). PayPal Subscriptions remains [H2027](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2027-Grok_Systema-Sanscriticum_paypal-subscriptions-api_31.07.26.md). Executor: Grok 4.5 (`grok-4.5`).

## [1.81.1] - 2026-07-31

### Fixed
- **Userbot-╤Б╨╡╤Б╤Б╨╕╤П MadelineProto: 30-07 ╨▒╨╗╨╛╨║╨╕╤А╨╛╨▓╨║╨░ ╤Б╨╜╤П╤В╨░, ╨╕╤Б╤Е╨╛╨┤╤П╤Й╨░╤П ╨┤╨╛╤Б╤В╨░╨▓╨║╨░ ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜╨░ ╨▓╨╢╨╕╨▓╤Г╤О (H594 follow-up).** ╨Ф╨╕╨░╨│╨╜╨╛╤Б╤В╨╕╨║╨░ 31-07 ╨┐╨╛╨║╨░╨╖╨░╨╗╨░ ╨╖╨┤╨╛╤А╨╛╨▓╤Г╤О ╤Б╨╡╤Б╤Б╨╕╤О (╨╛╨┤╨╕╨╜ ╨▓╨╛╤А╨║╨╡╤А ╤Б 07:32, `telegram-support:sync` ╨╖╨╡╨╗╨╡╨╜╤Л╨╣ ╨║╨░╨╢╨┤╤Г╤О ╨╝╨╕╨╜╤Г╤В╤Г); ╨╖╨░╤Б╤В╤А╤П╨▓╤И╨╕╨╣ canary-╨┤╨╢╨╛╨▒ `DeliverSupportReply` (H594, `failed_jobs` id 1445) ╨┐╨╡╤А╨╡╨╖╨░╨┐╤Г╤Й╨╡╨╜ `queue:retry` ╨╕ ╨┐╤А╨╛╤И╨╡╨╗ ╨▒╨╡╨╖ ╨┐╨╛╨▓╤В╨╛╤А╨╜╨╛╨│╨╛ ╨┐╨░╨┤╨╡╨╜╨╕╤П, ╤Б╨▓╨╡╨╢╨╕╨╡ outgoing-╤Б╤В╤А╨╛╨║╨╕ ╨╜╨╡╤Б╤Г╤В ╤А╨╡╨░╨╗╤М╨╜╤Л╨╡ `telegram_message_id`. ╨б╤В╨░╤В╤Г╤Б╨╜╨░╤П ╤Б╤В╤А╨╛╨║╨░ ╨▓ [docs/support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) ╨┐╨╡╤А╨╡╨▓╨╡╨┤╨╡╨╜╨░ ╨╕╨╖ ┬лBLOCKED┬╗ ╨▓ ┬лRECOVERED┬╗; ╤А╨╡╤В╤А╨░╨╣ W1.3 ╤А╨░╨╖╨▒╨╗╨╛╨║╨╕╤А╨╛╨▓╨░╨╜. Executor: Fable 5 (`claude-fable-5`).

### Added
- **H2017: PayPal claim fields + company invoice + Tochka/YooMoney/KKT plan ([PR #969](https://github.com/gasyoun/Systema-Sanscriticum/pull/969)).** Diaspora form requires **from-PayPal + paid date + amount** (txn/proof optional); `payments.claim_meta` JSON. Company invoice path (`provider=invoice`) with printable HTML + Filament confirm. Docs: [TOCHKA_PAYMENT_METHODS_AUDIT](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TOCHKA_PAYMENT_METHODS_AUDIT_2026-07-31.md). Executor: Grok 4.5 (`grok-4.5`).

### Changed
- **H2017 prod enable (31-07-2026):** `PAYPAL_CLAIM_ENABLED=true` (recipient `gasyoun@gmail.com`, me-link paypal.me/gasyoun); `COMPANY_INVOICE_ENABLED=true` + `BILLING_*` from Tochka customer (╨Ш╨Я ╨У╨░╤Б╤Г╨╜╤Б ╨Ь╨░╤А╤Ж╨╕╤Б / ╨Ш╨Э╨Э 540861224623 / ╤А/╤Б 40802тАж63757 @ ╨в╨╛╤З╨║╨░). Tochka retailers confirmed REG/active, modes sbp+card, cashbox `digitalKassaTochka`. Own KKT still later; YooMoney still deferred. Docs + accountant guide updated same day.

## [1.81.0] - 2026-07-31

### Added
- **Konsultaciya landing copy: real per-visitor A/B test, cutoff 01-11-2026 (H2010).** MG's 31-07-2026 ruling ("╨╜╨╡ ╤З╨╡╨╗╨╛╨▓╨╡╨║ ╤А╨╡╤И╨░╨╡╤В тАФ ╨│╨╛╨╜╤П╨╡╨╝ A/B ╨┤╨╛ 1 ╨╜╨╛╤П╨▒╤А╤П, ╨┤╨░╨╗╤М╤И╨╡ ╤А╨╡╤И╨░╤О╤В ╤Ж╨╕╤Д╤А╤Л") overrides `MarathonLandingCopy`'s earlier sequential A-then-B-via-env design. New `MarathonLandingCopySplit`: sticky cookie (`mkt_copy_variant`), true 50/50 random assignment on a visitor's first landing hit, one `impression` event per new assignment (not per reload). `Lead.landing_copy_variant` + `marathon_landing_copy_variant_events` table record the split through to conversion. `php artisan marathon:copy-variant-report` prints per-variant impressions/leads/conversion тАФ reports only, never auto-picks a winner. After `config('marathon_landing_copy.ab_test_until')` (default `2026-11-01`), the split stops assigning new visitors and everyone gets the config default variant, which a human sets after reading the report. Independent of the `MarathonVisual` skin axis (H1966/H1975) тАФ do not conflate. 8+2 new feature tests green, full marathon suite (103 tests) green, Pint clean. Executor: Sonnet 5 (`claude-sonnet-5`).

## [1.80.15] - 2026-07-31

### Added
- **╨Т╨▓╨╛╨┤ ╤А╨░╤Б╤Е╨╛╨┤╨╛╨▓ (opex) ╨╖╨░╨║╤А╨╡╨┐╨╗╨╡╨╜ ╨╖╨░ ╨▒╤Г╤Е╨│╨░╨╗╤В╨╡╤А╨╛╨╝ + ╤А╤Г╤Б╤Б╨║╨░╤П ╨╕╨╜╤Б╤В╤А╤Г╨║╤Ж╨╕╤П (H2016, issue [#953](https://github.com/gasyoun/Systema-Sanscriticum/issues/953)).** ╨а╨╡╤И╨╡╨╜╨╕╨╡ MG 31-07-2026: ╨▓╨▓╨╛╨┤ ╨╛╨┐╨╡╤А╨░╤Ж╨╕╨╛╨╜╨╜╤Л╤Е ╤А╨░╤Б╤Е╨╛╨┤╨╛╨▓ ╨▓╨╡╨┤╨╡╤В ╨Ь╨░╤А╨╕╤П (╤А╨╛╨╗╤М `accountant`) тАФ ╨┤╨╛╤Б╤В╤Г╨┐ ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜ ╨┐╨╛ ╨║╨╛╨┤╤Г (╨┐╨░╨╜╨╡╨╗╤М `canAccessPanel`, ╨┐╨╗╨░╤В╨╡╨╢╨╕ `ACCOUNTANT`-╨│╨╡╨╣╤В, ┬л╨а╨░╤Б╤Е╨╛╨┤╤Л (opex)┬╗ ╤З╨╡╤А╨╡╨╖ `FinanceAccess`/`RoleGate::finance()` тАФ ╨╜╨╕╤З╨╡╨│╨╛ ╨┤╨╛╨╛╤В╨║╤А╤Л╨▓╨░╤В╤М ╨╜╨╡ ╨┐╤А╨╕╤И╨╗╨╛╤Б╤М). ╨Э╨╛╨▓╨░╤П ╨╕╨╜╤Б╤В╤А╤Г╨║╤Ж╨╕╤П [MANUAL_EXPENSE_ENTRY_ACCOUNTANT_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_EXPENSE_ENTRY_ACCOUNTANT_RU.md) (+ ╨╝╨╡╤В╨░╨┤╨╛╨║): ╤З╤В╨╛ ╨▓╨╜╨╛╤Б╨╕╤В╤М ╨┐╨╛ ╤З╨╡╤В╤Л╤А╨╡╨╝ ╤Б╤В╨░╤В╤М╤П╨╝, ╤А╨╕╤В╨╝ ╨╕ ╨╛╨▒╤К╨╡╨╝╤Л ╨╕╨╖ ╨┐╤А╨╛╤И╨╗╨╛╨╣ ╨┐╤А╨░╨║╤В╨╕╨║╨╕ (35тАУ55 ╨╖╨░╨┐╨╕╤Б╨╡╨╣ / 0,8тАУ1,3 ╨╝╨╗╨╜ тВ╜ ╨▓ ╨╝╨╡╤Б╤П╤Ж), ╨╛╨▒╤П╨╖╨░╤В╨╡╨╗╤М╨╜╨╛╨╡ ┬л╨╜╨░╨╖╨╜╨░╤З╨╡╨╜╨╕╨╡┬╗ (╤Г ╨▓╤Б╨╡╤Е 440 legacy-╤Б╤В╤А╨╛╨║ ╨╛╨╜╨╛ ╨┐╤Г╤Б╤В╨╛), ╤А╨░╨╖╨╜╨╡╤Б╨╡╨╜╨╕╨╡ ╨╝╨╛╤Б╤В╨╛╨▓╤Л╤Е ╤Б╤В╤А╨╛╨║ ╨┐╨╛ ╤Б╤В╨░╤В╤М╤П╨╝, ╤З╨╡╨│╨╛ ╨╜╨╡ ╨┤╨╡╨╗╨░╤В╤М. ╨Ч╨░╨║╤А╨╡╨┐╨╗╨╡╨╜╨╕╨╡ ╨╛╤В╤А╨░╨╢╨╡╨╜╨╛ ╨▓ [FINANCE_REVIEW_RHYTHM.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md) (╤А╨╕╤В╨╝-╨╛╨▒╨╖╨╛╤А KPI ╨╛╤Б╤В╨░╨╡╤В╤Б╤П ╨╛╤В╨║╤А╤Л╤В╤Л╨╝ `@DECIDE`). Executor: Fable 5 (`claude-fable-5`).


## [1.80.14] - 2026-07-31

### Added
- **Konsultaciya visual direction D, stepped Alpine wizard (H1978).** `resources/views/marathon/skins/d/content.blade.php` implemented against the packet D wireframe тАФ 4-step conversion flow (intent quiz тЖТ track тЖТ contact/name тЖТ success), reusing skin B's light-island tokens (`bg-stone-50`, accent `#E85C24`) on a compact `max-w-md` card rather than B/C's `max-w-2xl`. Segmented progress bar with real `aria-current="step"` per segment plus an `aria-live` text label; both radio groups (`quiz_goal` тАФ rendered as tap-target cards matching the mockup, not B/C's `<select>` тАФ and `track`) wrapped in `fieldset`/`sr-only legend`; programmatic focus-to-heading on step change (`tabindex="-1"` + Alpine `$watch`) for screen-reader step announcements. WCAG contrast computed (not assumed) for the one new element beyond B's reused tokens тАФ the progress track тАФ and the naive "just darken it" fix was tested and rejected on the numbers (it *lowers* contrast against this accent); kept as-is since the same info is redundant via the live text label. `better-interface full` pass, 26/26 live Playwright checks green at 375/1280, plus a full real `register()` submission (DB-verified enrollment + Lead row, free and paid tracks both reach step 4 with the Telegram flash/payment card intact) and a keyboard-only path (Tab/Arrow/Enter, no mouse) with no focus trap тАФ [BETTER_INTERFACE_PASS_D_31.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/BETTER_INTERFACE_PASS_D_31.07.26.md). PHPUnit marathon suite 93/93 green (295 assertions), Pint clean. Concurrent sibling to skins A/B/C ([H1975](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1975-Sonnet_Systema-Sanscriticum_konsultaciya-visual-shell-b_30.07.26.md)/[H1976](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1976-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-a_30.07.26.md)/[H1977](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1977-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-c_30.07.26.md)), not a sole winner (H1966 multi-dir policy); default stays skin B. All four Direction A/B/C/D skins now shipped. Executor: Sonnet 5 (`claude-sonnet-5`).

### Changed
- **Optimisation kanban refresh + CRM flag coupling facts (H2014).** [`docs/OPTIMISATION_BACKLOG_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/OPTIMISATION_BACKLOG_2026H2.md) re-verified against live prod: ┬з1 deploy architecture тЖТ shipped (H1933 auto-deploy); status tokens; CRM trio (`CRM_COCKPIT` + `CRM_PIPELINE_BOARD` + `CRM_FOLLOW_UP_TASKS`) documented as ON; SESSION 1440 + SMTP preflight OK; Yandex.Disk off-site still unauthorized; H1067 residual narrowed to channel posts (landing A live). [`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) archive + residual rows updated. Windows MadelineProto polyfill silence hook: [`scripts/silence_madeline_windows_polyfill.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/silence_madeline_windows_polyfill.php) on `composer post-autoload-dump`. Executor: Grok 4.5 (`grok-4.5`).

## [1.80.13] - 2026-07-31

### Added
- **╨У╨░╤А╨┤ ╨▒╤Г╨┤╤Г╤Й╨╕╤Е ╨┤╨░╤В ╨▓ ╤А╤Г╤З╨╜╨╛╨╝ ╨▓╨▓╨╛╨┤╨╡ ╨┐╨╗╨░╤В╨╡╨╢╨╡╨╣ ╨╕ ╤А╨░╤Б╤Е╨╛╨┤╨╛╨▓ (issue [#953](https://github.com/gasyoun/Systema-Sanscriticum/issues/953), H2008).** ┬л╨Ф╨░╤В╨░ ╨┐╨╗╨░╤В╨╡╨╢╨░┬╗ ([PaymentResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/PaymentResource.php)) ╨╕ ┬л╨Ф╨░╤В╨░ ╤В╤А╨░╤В╤Л┬╗ ([ExpenseResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/ExpenseResource.php)) ╨┐╨╛╨╗╤Г╤З╨╕╨╗╨╕ `maxDate(╨║╨╛╨╜╨╡╤Ж ╤Б╨╡╨│╨╛╨┤╨╜╤П)` ╤Б ╤А╤Г╤Б╤Б╨║╨╕╨╝ ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡╨╝ ╨╛╨▒ ╨╛╤И╨╕╨▒╨║╨╡: ╨┤╨░╤В╨░ ╨▓ ╨▒╤Г╨┤╤Г╤Й╨╡╨╝ ╨┐╤А╨╕ ╤А╤Г╤З╨╜╨╛╨╝ ╨▓╨▓╨╛╨┤╨╡ тАФ ╨▓╤Б╨╡╨│╨┤╨░ ╨╛╨┐╨╡╤З╨░╤В╨║╨░ (╨┤╨▓╨╡ ╤Б╤В╤А╨╛╨║╨╕ ┬л╨а╨░╤Б╤Е╨╛╨┤┬╗ ╨┐╤П╤В╤М ╨╝╨╡╤Б╤П╤Ж╨╡╨▓ ╨▓╨╕╤Б╨╡╨╗╨╕ ╨▓ ╤Б╨╡╨╜╤В╤П╨▒╤А╨╡-2026 ╨╕╨╖-╨╖╨░ ┬л10.09┬╗ ╨▓╨╝╨╡╤Б╤В╨╛ ┬л10.02┬╗). ╨Ч╨░╨┤╨╜╨╕╨╝ ╤З╨╕╤Б╨╗╨╛╨╝ ╨▓╨╜╨╛╤Б╨╕╤В╤М ╨┐╨╛-╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г ╨╝╨╛╨╢╨╜╨╛. ╨в╨╡╤Б╤В `ManualEntryFutureDateGuardTest` (4 ╨║╨╡╨╣╤Б╨░). Executor: Fable 5 (`claude-fable-5`).

## [1.80.12] - 2026-07-31

### Fixed
- **╨Ф╨Ф╨б: ┬л╨а╨░╤Б╤Е╨╛╨┤┬╗ ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╤Б╤З╨╕╤В╨░╨╡╤В╤Б╤П ╨▓╨╛╨╖╨▓╤А╨░╤В╨╛╨╝ тАФ ╨┤╨▓╨╛╨╣╨╜╨╛╨╣ ╨╛╤В╤В╨╛╨║ ╨┐╨╛╤Б╨╗╨╡ ╨╝╨╛╤Б╤В╨░ ╤Г╤Б╤В╤А╨░╨╜╤С╨╜ (H2003, issue [#953](https://github.com/gasyoun/Systema-Sanscriticum/issues/953)).** `FinanceCockpitReport::dds` ╤Б╤З╨╕╤В╨░╨╗ ╤Б╤В╤А╨╛╨║╤Г `refundOut` ╨┐╨╛ ╤В╨░╤А╨╕╤Д╤Г ┬л╨а╨░╤Б╤Е╨╛╨┤┬╗ (`REFUND_TARIFF` ╨▒╤Л╨╗ ╨╝╨╕╤Б╨╗╨╡╨╣╨▒╨╗╨╛╨╝: ╤Н╤В╨╛ ╨╗╨╡╨│╨░╤Б╨╕-╤А╨░╤Б╤Е╨╛╨┤╤Л ╤И╨║╨╛╨╗╤Л, ╨╜╨╡ ╨▓╨╛╨╖╨▓╤А╨░╤В╤Л ╤Г╤З╨╡╨╜╨╕╨║╨░╨╝), ╨╕ ╨┐╨╛╤Б╨╗╨╡ ╨╝╨╛╤Б╤В╨░ ┬л╨а╨░╤Б╤Е╨╛╨┤┬╗тЖТExpense (1.80.11) ╤В╨╡ ╨╢╨╡ ╨┤╨╡╨╜╤М╨│╨╕ ╨▓╤Л╤З╨╕╤В╨░╨╗╨╕╤Б╤М ╨╕╨╖ net ╨┤╨▓╨░╨╢╨┤╤Л тАФ ╨║╨░╨║ refundOut ╨Ш ╨║╨░╨║ opexOut (╨╝╨░╨╣: net тИТ397 812 ╨▓╨╝╨╡╤Б╤В╨╛ тИТ132 989). ╨в╨╡╨┐╨╡╤А╤М `refundOut` ╤Б╤З╨╕╤В╨░╨╡╤В╤Б╤П ╨┐╨╛ ╨╜╨░╤Б╤В╨╛╤П╤Й╨╡╨╝╤Г ╨╝╨╡╤Е╨░╨╜╨╕╨╖╨╝╤Г ╨▓╨╛╨╖╨▓╤А╨░╤В╨╛╨▓ тАФ ╨┐╤А╨╕╨▓╤П╨╖╨║╨╡ `refund_of_payment_id` (╨║╨░╨║ ╨▓ `RevenueScheduleService::deferredRevenueAsOf`); ╨┐╤А╨╕╨▓╤П╨╖╨░╨╜╨╜╤Л╤Е ╨▓╨╛╨╖╨▓╤А╨░╤В╨╛╨▓ ╨▓ ╨┐╤А╨╛╨┤╨╡ ╨┐╨╛╨║╨░ ╨╜╨╛╨╗╤М, ╤Б╤В╤А╨╛╨║╨░ ╤З╨╡╤Б╤В╨╜╨╛ ╨╜╤Г╨╗╨╡╨▓╨░╤П. ╨а╨╡╨│╤А╨╡╤Б╤Б-╤В╨╡╤Б╤В╤Л: ╨╝╨╛╤Б╤В╨╛╨▓╨╛╨╣ ┬л╨а╨░╤Б╤Е╨╛╨┤┬╗ ╨▓ ╨Ф╨Ф╨б ╤А╨╛╨▓╨╜╨╛ ╨╛╨┤╨╕╨╜ ╤А╨░╨╖ (opexOut, ╨╜╨╡ refundOut), ╨┐╤А╨╕╨▓╤П╨╖╨░╨╜╨╜╤Л╨╣ ╨▓╨╛╨╖╨▓╤А╨░╤В ╨┐╨╛╨┐╨░╨┤╨░╨╡╤В ╨▓ refundOut. Executor: Fable 5 (`claude-fable-5`).

## [1.80.11] - 2026-07-31

### Added
- **╨Ь╨╛╤Б╤В ┬л╨а╨░╤Б╤Е╨╛╨┤┬╗ тЖТ opex-╨╗╨╡╨┤╨╢╨╡╤А + ╨┐╨╛╨╗╨╜╤Л╨╣ ╨▒╤Н╨║╤Д╨╕╨╗╨╗ (H2003, issue [#953](https://github.com/gasyoun/Systema-Sanscriticum/issues/953)).** ╨Ы╨╡╨│╨░╤Б╨╕-╤А╨░╤Б╤Е╨╛╨┤╤Л (╨╛╤В╤А╨╕╤Ж╨░╤В╨╡╨╗╤М╨╜╤Л╨╡ ╨┐╨╗╨░╤В╨╡╨╢╨╕ ╤Б ╤В╨░╤А╨╕╤Д╨╛╨╝ ┬л╨а╨░╤Б╤Е╨╛╨┤┬╗, 440 ╤Б╤В╤А╨╛╨║ ╤Б ╨╝╨░╤А╤В╨░) ╨▒╤Л╨╗╨╕ ╨╜╨╡╨▓╨╕╨┤╨╕╨╝╤Л ╨║╨╛╨║╨┐╨╕╤В╤Г: ╨╕╨╖ ╨▓╤Л╤А╤Г╤З╨║╨╕ ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╤Л `NON_REVENUE_TARIFFS`, ╨░ opex ╤З╨╕╤В╨░╨╡╤В╤Б╤П ╤В╨╛╨╗╤М╨║╨╛ ╨╕╨╖ ╨┐╤Г╤Б╤В╨╛╨│╨╛ `Expense` тАФ EBITDA ╨╖╨░╨▓╤Л╤И╨░╨╗╨░╤Б╤М ╨╜╨░ ~0.8тАУ1.3M тВ╜/╨╝╨╡╤Б ╨▓ ╨╝╨░╤А╤В╨╡тАУ╨╝╨░╨╡. ╨Э╨╛╨▓╨░╤П ╨║╨╛╨╝╨░╨╜╨┤╨░ [`expenses:bridge-raskhod`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/BridgeRaskhodExpenses.php) (dry-run ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О, `--apply` ╨┐╨╕╤И╨╡╤В) ╨║╨╛╨┐╨╕╤А╤Г╨╡╤В ╨╕╤Е ╨▓ `Expense` ╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╜╨╛ ╨┐╨╛ ╨╜╨╛╨▓╨╛╨╝╤Г ╤Г╨╜╨╕╨║╨░╨╗╤М╨╜╨╛╨╝╤Г `expenses.payment_id`; ╤Б╤В╨░╤В╤М╤П ╤Г ╨╝╨╛╤Б╤В╨╛╨▓╤Л╤Е ╤Б╤В╤А╨╛╨║ тАФ ┬л╨Э╨░╨╗╨╛╨│╨╕, ╨▒╨░╨╜╨║, ╨┐╤А╨╛╤З╨╡╨╡┬╗ (╤Б╨╕╨│╨╜╨░╨╗╨░ ╨┤╨╗╤П ╨║╨░╤В╨╡╨│╨╛╤А╨╕╨╖╨░╤Ж╨╕╨╕ ╨▓ ╨┐╨╗╨░╤В╨╡╨╢╨╡ ╨╜╨╡╤В; ╨╛╨┐╨╡╤А╨░╤В╨╛╤А ╨┐╨╡╤А╨╡-╨║╨░╤В╨╡╨│╨╛╤А╨╕╨╖╤Г╨╡╤В ╨▓ ╨░╨┤╨╝╨╕╨╜╨║╨╡). ╨Х╨╢╨╡╨┤╨╜╨╡╨▓╨╜╤Л╨╣ ╨┐╤А╨╛╨│╨╛╨╜ 04:40 ╨▓ `Kernel` ╨┤╨╡╤А╨╢╨╕╤В ╨║╨╛╨╜╨▓╨╡╨╜╤Ж╨╕╨╕ ╨▓ ╤Б╨╕╨╜╤Е╤А╨╛╨╜╨╡; ╨▒╤Г╨┤╤Г╤Й╨╡╨┤╨░╤В╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╨╡ ╨╕ ╨┐╨╛╨╗╨╛╨╢╨╕╤В╨╡╨╗╤М╨╜╤Л╨╡ ╨╕╤Б╤Е╨╛╨┤╨╜╨╕╨║╨╕ ╨║╨╛╨╝╨░╨╜╨┤╨░ ╨┐╨╛╨╝╨╡╤З╨░╨╡╤В ╨┐╤А╨╡╨┤╤Г╨┐╤А╨╡╨╢╨┤╨╡╨╜╨╕╨╡╨╝. ╨в╨╡╤Б╤В `ExpenseBridgeRaskhodTest` (5 ╨║╨╡╨╣╤Б╨╛╨▓). Executor: Fable 5 (`claude-fable-5`).

## [1.80.10] - 2026-07-31

### Added
- **Konsultaciya visual direction C, warm paper (H1977).** `resources/views/marathon/skins/c/content.blade.php` implemented against the packet C tokens (bg `#F7F1E8`, surface `#FFFCF7`, accent `#C45C26` terracotta) as an O2 light island under the dark shop header тАФ serif display (Tailwind's built-in Georgia/Cambria stack) + sans body, lesson cards with a single тЧЖ mark each, FAQ under thin rules, shared post-submit/post-Telegram-click states. WCAG contrast pairs computed (not assumed): three pairs тАФ the badge label, the contact placeholder, and both CTA buttons' white-on-accent fill тАФ came in below the 4.5:1 normal-text floor and were fixed pre-review (badge/CTA promoted to the darker in-family `#A94D1F`, already the file's own hover shade; placeholder moved to the standing `#6B5E4E` muted token) rather than accepted as a gap, since this is the skin's own new accent, not a pre-existing brand lock. `better-interface full` pass, 17/17 live Playwright checks green at 375/1280 тАФ [BETTER_INTERFACE_PASS_C_31.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/BETTER_INTERFACE_PASS_C_31.07.26.md). PHPUnit marathon suite 93/93 green (295 assertions), Pint clean. Concurrent sibling to skins A/B ([H1975](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1975-Sonnet_Systema-Sanscriticum_konsultaciya-visual-shell-b_30.07.26.md)/[H1976](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1976-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-a_30.07.26.md)), not a sole winner (H1966 multi-dir policy); default stays skin B. Executor: Sonnet 5 (`claude-sonnet-5`).

## [1.80.9] - 2026-07-31

### Fixed
- **╨д╨╕╨╜╨░╨╜╤Б╨╛╨▓╤Л╨╡ ╨╝╨╡╤Б╤П╤З╨╜╤Л╨╡ ╨╛╨║╨╜╨░ ╤В╨╡╤А╤П╨╗╨╕ ╨┐╨╛╤Б╨╗╨╡╨┤╨╜╨╕╨╣ ╨┤╨╡╨╜╤М ╨╝╨╡╤Б╤П╤Ж╨░ (issue [#935](https://github.com/gasyoun/Systema-Sanscriticum/issues/935), H1996).** `whereBetween(col, [toDateString(), toDateString()])` ╨╕╤Б╨║╨╗╤О╤З╨░╨╗ ╨╖╨░╨┐╨╕╤Б╨╕ ╨┐╨╛╤Б╨╗╨╡╨┤╨╜╨╡╨│╨╛ ╨┤╨╜╤П ╨╛╨║╨╜╨░, ╤Г ╨║╨╛╤В╨╛╤А╤Л╤Е ╨╖╨╜╨░╤З╨╡╨╜╨╕╨╡ ╤Е╤А╨░╨╜╨╕╤В╤Б╤П ╤Б╨╛ ╨▓╤А╨╡╨╝╨╡╨╜╨╡╨╝ (SQLite ╨┐╨╕╤И╨╡╤В `Y-m-d H:i:s` ╨┤╨░╨╢╨╡ ╨┐╨╛╨┤ cast `date`) тАФ `'2026-07-31 18:00:00'` ╤Б╤В╤А╨╛╨║╨╛╨▓╨╛ ╨▒╨╛╨╗╤М╤И╨╡ ╨│╤А╨░╨╜╨╕╤Ж╤Л `'2026-07-31'`. ╨Ш╨╖-╨╖╨░ ╤Н╤В╨╛╨│╨╛ ╨▓╨╡╤Б╤М `FinanceCockpitOpexTest` ╨┤╨╡╤В╨╡╤А╨╝╨╕╨╜╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛ ╨┐╨░╨┤╨░╨╗ ╨║╨░╨╢╨┤╨╛╨╡ ╨┐╨╛╤Б╨╗╨╡╨┤╨╜╨╡╨╡ ╤З╨╕╤Б╨╗╨╛ ╨╝╨╡╤Б╤П╤Ж╨░ (CI ╨╕ ╨╗╨╛╨║╨░╨╗╤М╨╜╨╛) ╨╕ ╨▒╤Л╨╗ ╨╖╨╡╨╗╤С╨╜╤Л╨╝ ╨▓ ╨╛╤Б╤В╨░╨╗╤М╨╜╤Л╨╡ ╨┤╨╜╨╕. ╨Ш╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╤Л ╨▓╤Б╨╡ ╤В╤А╨╕ ╤Д╨╕╨╜╨░╨╜╤Б╨╛╨▓╤Л╤Е ╨╛╨║╨╜╨░: [`Expense::scopeInWindow`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Expense.php), ╨Ф╨Ф╨б-╨╛╤В╤В╨╛╨║ ╨╖╨░╤А╨┐╨╗╨░╤В ╨▓ [`FinanceCockpitReport::dds`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/FinanceCockpitReport.php), ╨╛╨║╨╜╨╛ `paid_at` ╨▓ [`TeacherSalaryService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php) тАФ ╨│╤А╨░╨╜╨╕╤Ж╤Л ╤В╨╡╨┐╨╡╤А╤М ╨╜╨░╤З╨░╨╗╨╛/╨║╨╛╨╜╨╡╤Ж ╤Б╤Г╤В╨╛╨║. ╨а╨╡╨│╤А╨╡╤Б╤Б ╨╖╨░╨║╤А╨╡╨┐╨╗╤С╨╜ `FinanceMonthEndWindowTest` ╤Б ╨╖╨░╨╝╨╛╤А╨╛╨╢╨╡╨╜╨╜╤Л╨╝ ╨▓╤А╨╡╨╝╨╡╨╜╨╡╨╝ ╨╜╨░ ╨▓╨╡╤З╨╡╤А╨╡ 31-╨│╨╛ ╤З╨╕╤Б╨╗╨░, ╤З╤В╨╛╨▒╤Л ╨▓╨╛╤Б╨┐╤А╨╛╨╕╨╖╨▓╨╛╨┤╨╕╤В╤М╤Б╤П ╨▓ ╨╗╤О╨▒╨╛╨╣ ╨┤╨╡╨╜╤М ╨┐╤А╨╛╨│╨╛╨╜╨░. Executor: Fable 5 (`claude-fable-5`).

## [1.80.8] - 2026-07-31

### Added
- **Konsultaciya visual direction A, dark-native (H1976).** `resources/views/marathon/skins/a/content.blade.php` implemented against the packet A tokens (bg `#0A0D14`, surface `#111622`, accent `#E85C24`) тАФ vertical day timeline with orange nodes, accent-bar benefit cards, elevated dark form card, shared post-submit/post-Telegram-click states, dark FAQ accordion. WCAG contrast pairs computed (two `slate-500` instances bumped to `slate-400` to clear AA). `better-interface full` pass тАФ no new findings, inherits skin B's fixed `fieldset`/`legend` + FAQ `aria-expanded`/`aria-controls` patterns тАФ [BETTER_INTERFACE_PASS_A_31.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/BETTER_INTERFACE_PASS_A_31.07.26.md). Concurrent sibling to skin B ([H1975](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1975-Sonnet_Systema-Sanscriticum_konsultaciya-visual-shell-b_30.07.26.md)), not a sole winner (H1966 multi-dir policy); default stays skin B. Executor: Sonnet 5 (`claude-sonnet-5`).

## [1.80.7] - 2026-07-31

### Changed
- **╨С╨╕╨▒╨╗╨╕╨╛╤В╨╡╨║╨░ ╨╛╤В╨▓╨╡╤В╨╛╨▓ ╨┐╨╛╨┤╨┤╨╡╤А╨╢╨║╨╕ тАФ ╤А╨╡╨│╨╕╤Б╤В╤А╨╛╨▓╤Л╨╣ ╨┐╤А╨╛╤Е╨╛╨┤, ╨║╨░╤В╨╡╨│╨╛╤А╨╕╨╕ ╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А╨░ AтАУF (H1876).** ╨в╨╡╨║╤Б╤В╤Л ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║╨╛╨▓ FAQ-╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А╨░ ╨┐╤А╨╕╨▓╨╡╨┤╨╡╨╜╤Л ╨║ ╨┤╨╛╨╝╨░╤И╨╜╨╡╨╝╤Г ╤А╨╡╨│╨╕╤Б╤В╤А╤Г revenue-copy voice contract: ┬л╤Б ╤Г╤З╤С╤В╨╛╨╝┬╗ тЖТ ┬л╤Б ╤Г╤З╨╡╤В╨╛╨╝┬╗ ╨▓ ╨┐╤Г╨▒╨╗╨╕╤З╨╜╤Л╤Е ╤В╨░╤А╨╕╤Д╨░╤Е (D-╨│╨╛╤Б╤В╤М, ╨┐╤А╨░╨▓╨╕╨╗╨╛ D13), ╤А╨╡╨│╨╕╤Б╤В╤А╨╛╨▓╤Л╨╣ ╨▒╨╗╨╛╨║ ╨▓ ╤Б╨╕╤Б╤В╨╡╨╝╨╜╨╛╨╝ ╨┐╤А╨╛╨╝╨┐╤В╨╡ LLM-╤Д╨╛╤А╨╝╤Г╨╗╨╕╤А╨╛╨▓╤Й╨╕╨║╨░ D/E/F (`SupportLlmDraftComposer`), ╨║╨░╨╜╤А╨╡╨┐╨╗╨░╨╣ ┬л╨┐╤А╨╕╨╜╤П╨╗╨╕ ╨▓ ╤А╨░╨▒╨╛╤В╤Г┬╗ ╨▒╨╡╨╖ ┬л╤С┬╗/╨▓╨╛╤Б╨║╨╗╨╕╤Ж╨░╨╜╨╕╤П ╨╕ ╤Б ╨║╨╛╨╜╨║╤А╨╡╤В╨╜╨╛╨╣ ╤Б╨║╨╛╤А╨╛╤Б╤В╤М╤О ╨╛╤В╨▓╨╡╤В╨░, ╨┐╨╗╤О╤Б ╤В╤А╨╕ ╨╜╨╡╨┐╤А╨╕╨▓╤П╨╖╨░╨╜╨╜╤Л╨╡ ╨╖╨░╨│╨╛╤В╨╛╨▓╨║╨╕ D/E/F ╨▓ [`MessageTemplateSeeder`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/MessageTemplateSeeder.php) (╨┐╤А╨╕╨▓╤П╨╖╨║╨░ тАФ ╤А╨╡╤И╨╡╨╜╨╕╨╡ ╨╛╨┐╨╡╤А╨░╤В╨╛╤А╨░, S9/H1838). GтАУI ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛ ╨▓╨╜╨╡ ╨╛╤Е╨▓╨░╤В╨░ (╨╜╨╡ ╨║╨░╤В╨╡╨│╨╛╤А╨╕╨╕ ╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А╨░). ╨Ч╨░╨┐╨╕╤Б╤М: [docs/copy/support-reply-library-ru-register-a-f.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/support-reply-library-ru-register-a-f.md). Executor: Fable 5 (`claude-fable-5`).

## [1.80.6] - 2026-07-31

### Added
- **┬л╨Я╨╡╤А╨▓╤Л╨╡ ╨▓╨╛╨┐╤А╨╛╤Б╤Л┬╗ ╨╜╨░ ╨▓╨╕╤В╤А╨╕╨╜╨╡ `/online` (H1868).** ╨Ю╤А╨╕╨╡╨╜╤В╨░╤Ж╨╕╨╛╨╜╨╜╨░╤П ╨┐╨╛╨╗╨╛╤Б╨░ ╨┤╨╗╤П ╨┐╨╡╤А╨▓╨╛╨│╨╛ ╨▓╨╕╨╖╨╕╤В╨░: ╨┐╤П╤В╤М ╤А╨╡╨░╨╗╤М╨╜╤Л╤Е ╨┐╨╡╤А╨▓╤Л╤Е ╨▓╨╛╨┐╤А╨╛╤Б╨╛╨▓ ╨╕╨╖ custdev-╨║╨╛╤А╨┐╤Г╤Б╨░ (~2 600 ╤А╨░╨╖╨╝╨╡╤З╨╡╨╜╨╜╤Л╤Е ╨┤╨╕╨░╨╗╨╛╨│╨╛╨▓; ╨║╨╛╨┤╤Л ╨▓╨╛╨╖╤А╨░╨╢╨╡╨╜╨╕╨╣ ╨Т1тАУ╨Т6 ╨╕╨╖ [ORS-FAQ FAQ_FUNNEL_OBJECTION_MAP.md](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/FAQ_FUNNEL_OBJECTION_MAP.md)) ╤Б ╤З╨╡╤Б╤В╨╜╤Л╨╝╨╕ ╨╛╤В╨▓╨╡╤В╨░╨╝╨╕ ╨╕ ╤Б╤Б╤Л╨╗╨║╨░╨╝╨╕ ╤В╨╛╨╗╤М╨║╨╛ ╨╜╨░ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╡ ╨┐╨╛╨▓╨╡╤А╤Е╨╜╨╛╤Б╤В╨╕ (╨║╨▓╨╕╨╖ ┬л╨б ╤З╨╡╨│╨╛ ╨╜╨░╤З╨░╤В╤М┬╗, ╤Д╨╕╨╗╤М╤В╤А╤Л ╨║╨░╤В╨░╨╗╨╛╨│╨░, ╤П╨║╨╛╤А╤М `#ceny`, ╤Е╨░╨▒ ┬л╨Ь╨░╤В╨╡╤А╨╕╨░╨╗╤Л┬╗). Hero-╨┐╨╛╨┤╨╖╨░╨│╨╛╨╗╨╛╨▓╨╛╨║ ╨┐╨╡╤А╨╡╨┐╨╕╤Б╨░╨╜ ╨╕╨╖ ╨╗╨╛╨╖╤Г╨╜╨│╨░ ╨▓ ╨╛╤А╨╕╨╡╨╜╤В╨░╤Ж╨╕╤О; ╨▒╨╡╨╖ ╤Б╤А╨╛╤З╨╜╨╛╤Б╤В╨╕ ╨╕ ╤Б╨╛╤Ж-╨┤╨░╨▓╨╗╨╡╨╜╨╕╤П (╨░╨╜╤В╨╕-╨┐╤А╨╕╨╡╨╝╤Л Win/Loss тИТ7/тИТ6). ╨в╨╡╤Б╤В `ShopFirstQuestionsTest`. Executor: Fable 5 (`claude-fable-5`).

### Fixed
- **Main CI ╤А╨░╨╖╨▒╨╗╨╛╨║╨╕╤А╨╛╨▓╨░╨╜** ([#943](https://github.com/gasyoun/Systema-Sanscriticum/pull/943)): pint-╤Д╨╕╨║╤Б╤Л ╨▓ `SrsTestModesTest.php` (H1988) тАФ pint-╤И╨░╨│ ╨▓╨░╨╗╨╕╨╗ CI ╨╜╨░ ╨║╨░╨╢╨┤╨╛╨╝ ╨┐╤Г╤И╨╡ ╨▓ `main` ╨╜╨░╤З╨╕╨╜╨░╤П ╤Б 1.80.4, phpunit-╤И╨░╨│ ╨╜╨╡ ╨╖╨░╨┐╤Г╤Б╨║╨░╨╗╤Б╤П. Executor: Fable 5 (`claude-fable-5`).

## [1.80.5] - 2026-07-31

### Added
- **Memrise SRS P2 test modes (H1988).** Auth review UI gains mode tabs: multiple choice (`DistractorSampler`), typing (`AnswerMatcher` exact/soft), tap-the-pairs, speed (10 s timeout тЖТ Again), difficult words (`queueDifficultFor`, lapses &gt; 0). Guests stay on classic trial. Roadmap P0/P1/P2 status updated. Executor: Grok 4.5 (`grok-4.5`).

### Changed
- **Docs + marketing: product URL `srs` тЖТ `koloda` everywhere live paths appear** (manuals, roadmaps, audits, seed READMEs, README, TG drafts). Storage still `storage/.../srs/`; config file still `config/srs.php`. Executor: Grok 4.5 (`grok-4.5`).

## [1.80.4] - 2026-07-31

### Added
- **Deck owner can edit slug** (student ┬л╨Ь╨╛╨╕ ╨║╨╛╨╗╨╛╨┤╤Л┬╗ + Filament unique/required slug). Reserved: `stats`, `decks`. Executor: Grok 4.5 (`grok-4.5`).
- **SRS per-deck URLs + guest trial.** Each deck has its own path (`/koloda/{slug}` public, `/dvaram/koloda/{slug}` cabinet); hub pages list decks. Guests can try system/public decks without registration (soft wall after `SRS_GUEST_TRIAL_CARDS`, default 10; progress not saved). Tagline is language-aware (`sa` тЖТ ┬л╤Г╤З╨╕╤В╨╡ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В┬╗, `hi` тЖТ ┬л╤Г╤З╨╕╤В╨╡ ╤Е╨╕╨╜╨┤╨╕┬╗). Executor: Grok 4.5 (`grok-4.5`).
- **Konsultaciya visual skin switch + skin B light island (H1975).** `MARATHON_LANDING_VISUAL_VARIANT` (a|b|c|d, default **b**) + `?skin=` QA override, resolved by `App\Support\MarathonVisual`, orthogonal to the existing `MARATHON_LANDING_COPY_VARIANT` copy axis. `resources/views/marathon/show.blade.php` is now a thin shell that includes `marathon/skins/{a,b,c,d}/content.blade.php` (a/c/d stub тЖТ fall back to b until [H1976](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1976-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-a_30.07.26.md)/[H1977](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1977-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-c_30.07.26.md)/[H1978](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1978-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-d_30.07.26.md) ship). Skin B closes the USEIT Must-fix set (`marketing/marathon-2026-08/redesign/USEIT_NIELSEN_PASS_30.07.26.md`): O2 light-island wrap (no more dark-on-dark), explicit `text-stone-900 bg-white` form controls, numbered day cards, success pinned above the fold, inline post-Telegram-click state, ┬л╨и╨░╨│ 2 ╨╕╨╖ 2┬╗ payment step label. `better-interface full` pass closed 2 MEDIUM accessibility findings (radio group тЖТ `fieldset`/`legend`, FAQ accordion тЖТ `aria-expanded`/`aria-controls`) тАФ [BETTER_INTERFACE_PASS_B_30.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/BETTER_INTERFACE_PASS_B_30.07.26.md). Executor: Sonnet 5 (`claude-sonnet-5`).

### Changed
- **Public URL `srs` тЖТ `koloda`.** Canonical paths: `/koloda`, `/koloda/{slug}`, `/dvaram/koloda` (+ stats/decks). Legacy `/srs` and `/dvaram/srs` stay as **301** redirects. Internal route names (`student.srs`, `srs.index`) unchanged. Executor: Grok 4.5 (`grok-4.5`).
- **SRS enabled by default** (`env('SRS_ENABLED', true)`). Explicit `SRS_ENABLED=false` still darks the surface. R-6 OFF-by-default lifted after public per-deck trial URLs shipped. Executor: Grok 4.5 (`grok-4.5`).
- **H1966 konsultaciya redesign: multi-direction concurrent variants.** No single human pick among AтАУD; ship several visual directions at once (B = default only). Packet + marketing README updated. Executor: Grok 4.5 (`grok-4.5`).

## [1.80.3] - 2026-07-30

### Fixed
- **Scheduler wrapper stale-lock false-positive skip (H1973).** `systema-schedule-run.sh`'s `flock -n` overlap guard could stay falsely held for hours after a deploy-triggered restart, silently skipping every cron-scheduled command (not Telegram-specific тАФ `receivables:check`, `debts:remind`, `groups:notify-forming-shortfall`, etc.). Added a TTL-based stale-lock reclaim: a `flock` failure older than `2 ├Ч SCHEDULE_MAX_SECONDS + 60s` (env-overridable via `SYSTEMA_SCHEDULE_STALE_SECONDS`) is presumed a wedged/dead holder and reclaimed by replacing the lock file with a fresh inode. Reproduction test: [`scripts/server_guards/sbin/test_systema_schedule_run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/test_systema_schedule_run.sh). Details: [docs/server-resource-guards.md ┬з2.5](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md). Executor: Sonnet 5 (`claude-sonnet-5`).

### Added
- **H1966 konsultaciya redesign design packet (no production CSS).** Four directions AтАУD under [`marketing/marathon-2026-08/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/marketing/marathon-2026-08): token tables, post-submit/Telegram states, HTML mockups `redesign/direction-{a,b,c,d}-*.html`, full `/useit` Nielsen H1тАУH10 pass; multi-dir policy (B = default variant, not sole winner). Executor: Grok 4.5 (`grok-4.5`).
- **Anki SRS media in review UI + agent manual.** Import publishes `media/` to `storage/app/public/srs/anki_{id}/`; review loop shows audio (front) + image (reveal) via `SrsMedia`. Agent ops: [docs/MANUAL_AGENT_ANKI_SRS_IMPORT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_AGENT_ANKI_SRS_IMPORT.md). Executor: Grok 4.5 (`grok-4.5`).

## [1.80.2] - 2026-07-30
### Changed
- **Marathon: required name + admin quiz reset.** Landing form: name is required (min 2). Filament ┬л╨Ь╨░╤А╨░╤Д╨╛╨╜: ╨╛╨┐╤А╨╛╤Б╤Л┬╗ lists all enrollments with ╨б╨▒╤А╨╛╤Б ╨Ф1 / ╨Ф2 / ╨Ф1+╨Ф2 (`resetQuizEngagement` тАФ clears engaged_at + quiz_seconds only). Executor: Grok 4.5 (`grok-4.5`).

### Added
- **Floating newsletter Subscribe window (bottom-right, H1971).** Claude Marketplaces-style card on samskrte.ru public layouts (`main`, shop, articles, promo): ┬л╨Э╨╛╨▓╨╛╤Б╤В╨╕ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨░ / ╨Я╤А╨╕╤Б╨╛╨╡╨┤╨╕╨╜╤П╨╣╤В╨╡╤Б╤М ╨║ **5 000+** ╤А╤Г╤Б╤Б╨║╨╛╤П╨╖╤Л╤З╨╜╤Л╨╝ ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░╨╝тАж┬╗ + email тЖТ existing H324 `/subscribe` path (magic-link cabinet + magnets). Dismiss via `localStorage`; sits above support-chat bubble. `trust.graduates_count` defaults to **5000** (public showcase figure; `TRUST_GRADUATES_COUNT=0` hides). Partial: [`newsletter-subscribe-popup.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/newsletter-subscribe-popup.blade.php). Executor: Grok 4.5 (`grok-4.5`).
- **Student uptime pages + RU split by audience.** Public [samskrte.ru/uptime](https://samskrte.ru/uptime) (Laravel) + GitHub Pages mirror [`uptime/`](https://gasyoun.github.io/Systema-Sanscriticum/uptime/) (works when VPS is down) + WordPress snippet `uptime/samskrtam-snippet.html`. [UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md): 1 students/teachers (VPN vs site, tag `@rusamskrtam`); 2 Ivan/Marcis only (red monitors, Artem). Executor: Grok 4.5 (`grok-4.5`).
- **Anki тЖТ Systema SRS import pipeline (H1970):** public AnkiWeb shared-deck path тАФ `scripts/ankiweb_download_deck.py` (Playwright), `scripts/anki_apkg_to_srs_export.py` + `scripts/anki_export_validate.py`, `php artisan srs:import-anki`, fixture tests, pilot seed `database/seeders/data/anki_454628379/` (Hindi Core 100, AnkiWeb 454628379, ~202 cards + media). Reusable skill `/anki-srs-import`. Executor: Grok 4.5 (`grok-4.5`).

## [1.80.1] - 2026-07-30

### Changed
- **Marathon Day 1 quiz polish (copy, path, duration, no re-play).** URL `/online/konsultaciya/day/` тЖТ **`/dine/`** (Sanskrit *dine*; legacy `/day/` 301). Zero-cohort Day 1: *veda* as noun/perfect, lowercase options, *m─Бtar*/*bhr─Бtar* macrons; links to Zaliznyak ┬л╨Ю ╤П╨╖╤Л╨║╨╡ ╨┤╤А╨╡╨▓╨╜╨╡╨╣ ╨Ш╨╜╨┤╨╕╨╕┬╗ ([samskrtam.ru/mt](https://samskrtam.ru/mt/) list) and [Burlak 2018](https://samskrtam.ru/burlak-sanskrit-2018-tc). Client wall-clock `day{1,2}_quiz_seconds` on complete тАФ shown to enrollee and in Filament ┬л╨Ь╨░╤А╨░╤Д╨╛╨╜: ╨╛╨┐╤А╨╛╤Б╤Л┬╗. After `day1_engaged_at`, page shows done state (no quiz restart). Executor: Grok 4.5 (`grok-4.5`).
- **Uptime RU: ┬л╨╡╤Б╨╗╨╕ ╤Б╨░╨╣╤В ╤Г╨┐╨░╨╗┬╗ + [@rusamskrtam](https://t.me/rusamskrtam).** [UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md) leads with site-down / red-email-or-Telegram first steps; audience = letter or @rusamskrtam red alert. CLAUDE/README/EN pointers. Executor: Grok 4.5 (`grok-4.5`).
- **Marathon Day 1 immediately after Telegram `/start`.** After magnet deep-link binds `telegram_chat_id`, Day 1 drip text is sent at once (no wait for next calendar day). Cron `marathon:deliver-due` remains the catch-up path; `day1_completed_at` keeps both paths idempotent. Shared `MarathonDay1Sender`. Executor: Grok 4.5 (`grok-4.5`).

### Added
- **╨Я╤А╨╛╨┤-╨▒╨░╨╖╨╗╨░╨╣╨╜ deflection ╨┐╨╛ ╨║╨░╤В╨╡╨│╨╛╤А╨╕╤П╨╝ (H592, P0.4).** ╨а╨╡╨░╨╗╤М╨╜╤Л╨╣ ╨┐╨╛╤А╤П╨┤╨╛╨║ ╨║╨░╤В╨╡╨│╨╛╤А╨╕╨╣ ╨╕╨╖ `php artisan support:topic-ranking --months=6 --json`, ╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜ ╨▓ [`docs/DEFLECTION_BASELINE_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DEFLECTION_BASELINE_2026.md) ([PR #909](https://github.com/gasyoun/Systema-Sanscriticum/pull/909)): `schedule` (C) тАФ ╨╜╨╡ `zoom` (A) тАФ ╨╗╨╕╨┤╨╕╤А╤Г╨╡╤В ╨┐╨╛ ╨┤╨╡╤Д╨╗╨╡╨║╤Ж╨╕╨╕ (617), ╤Б ╤А╨░╤Б╤Е╨╛╨╢╨┤╨╡╨╜╨╕╨╡╨╝ ╨┐╤А╨╛╤В╨╕╨▓ roadmap ┬з4.2 A/B/C-╨┤╨╛╨┐╤Г╤Й╨╡╨╜╨╕╤П (A/B ╨╜╨╡ ╨▓╤Л╨┤╨╡╨╗╨╡╨╜╤Л ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛╨╣ ╨║╨░╤В╨╡╨│╨╛╤А╨╕╨╡╨╣ ╨▓ ╨╢╨╕╨▓╨╛╨╣ ╤В╨░╨║╤Б╨╛╨╜╨╛╨╝╨╕╨╕). Executor: Sonnet 5 (`claude-sonnet-5`).
- **Uptime: Russian human guide + dual EN/RU packaging.** [UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md) тАФ short ┬л╤З╤В╨╛ ╨║╤А╨░╤Б╨╜╨╛╨╡ / ╨║╨╛╨│╨╛ ╨╖╨▓╨░╤В╤М┬╗ for people; EN [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) marked for agents with cross-link. `CLAUDE.md` + `README.md` dual pointers. Executor: Grok 4.5 (`grok-4.5`).

## [1.80.0] - 2026-07-30

### Added
- **n8n server catalog + ops plan (context-ai.ru / samskrtam50).** Full inventory of 76 workflows (5 Active), host bookbuilder/proxy stack, Laravel `N8N_*` bridge table, credential audit (no secret values), redacted live exports, layered `/ask` PLAN/ROADMAP/ARCH/IMPL/VERIFY. Entry: [docs/n8n/CATALOG_N8N_SERVER_CONTEXT_AI_2026-07-30.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/CATALOG_N8N_SERVER_CONTEXT_AI_2026-07-30.md) ┬╖ [docs/PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md). Executor: Grok 4.5 (`grok-4.5`).
- **H1949 hardening: admin never gets remember; password change kills all devices.** After the opt-in checkbox (#900), admins who checked ┬л╨Ч╨░╨┐╨╛╨╝╨╜╨╕╤В╤М ╨╝╨╡╨╜╤П┬╗ would still have received a weeks-long recaller on the shared Filament web guard (SESSION_LIFETIME was capped at 1 day for that reason). `login` / `shopLogin` now force `remember=false` when `is_admin`. Student password change cycles `users.remember_token` so old cookies stop working. Student-manual ┬з1 documents shared-PC risk. Executor: Grok 4.5 (`grok-4.5`).
- **┬л╨Ч╨░╨┐╨╛╨╝╨╜╨╕╤В╤М ╨╝╨╡╨╜╤П┬╗ ╨╜╨░ ╨┐╨░╤А╨╛╨╗╤М╨╜╨╛╨╝ ╨▓╤Е╨╛╨┤╨╡ ╨▓ ╨║╨░╨▒╨╕╨╜╨╡╤В (H1949).** ╨г ╨▓╨╕╤В╤А╨╕╨╜╨╜╨╛╨╣ Alpine-╨╝╨╛╨┤╨░╨╗╨║╨╕ (`shop.login`) ╤З╨╡╨║╨▒╨╛╨║╤Б ╨╕ `Auth::attempt(..., remember)` ╤Г╨╢╨╡ ╨▒╤Л╨╗╨╕; ╤Д╨╛╤А╨╝╨░ `/login` (`login.post`) тАФ ╨╜╨╡╤В: ╤Б╨╡╤Б╤Б╨╕╤П ╨╢╨╕╨╗╨░ ╤В╨╛╨╗╤М╨║╨╛ `SESSION_LIFETIME` ╨╝╨╕╨╜╤Г╤В, ╨╕ ╨┐╨╛╤Б╨╗╨╡ ╨╖╨░╨║╤А╤Л╤В╨╕╤П ╨▒╤А╨░╤Г╨╖╨╡╤А╨░ / ╨┐╤А╨╛╤Б╤В╨╛╤П ╤Б╤В╤Г╨┤╨╡╨╜╤В ╤Б╨╜╨╛╨▓╨░ ╨▓╨▓╨╛╨┤╨╕╨╗ ╨┐╨░╤А╨╛╨╗╤М. ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜ opt-in ╤З╨╡╨║╨▒╨╛╨║╤Б ┬л╨Ч╨░╨┐╨╛╨╝╨╜╨╕╤В╤М ╨╝╨╡╨╜╤П┬╗ (╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О **╨▓╤Л╨║╨╗**) ╨╕ ╤В╨╛╤В ╨╢╨╡ `$request->boolean('remember')` ╨▓╨╛ `AuthController::login`, ╤З╤В╨╛ ╤Г╨╢╨╡ ╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╤В `shopLogin`. ╨б╨╛╤Ж╨╕╨░╨╗╤М╨╜╤Л╨╣ ╨╕ magic-link ╨▓╤Е╨╛╨┤ ╨┐╨╛-╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г ╨▓╤Б╨╡╨│╨┤╨░ remember. ╨Ф╨╡╨╜╨╡╨╢╨╜╤Л╨╣ ╨║╨╛╨╜╤В╤Г╤А ╨╕ ╤З╨╡╨║╨░╤Г╤В ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В╤Л. Executor: Grok 4.5 (`grok-4.5`).
- **╨Ъ╨░╨╜╨╛╨╜ uptime-inventory ╨┤╨╗╤П ╨░╨│╨╡╨╜╤В╨╛╨▓ тАФ Better Stack.** [docs/UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) (+ metadoc): HTTP + heartbeats samskrte / samskrtam / Cologne, env-╨║╨╗╤О╤З╨╕ ╨▒╨╡╨╖ TOKEN, VPS paths ╨┤╨╗╤П cologne-cdsl-heartbeat, smoke. ╨г╨║╨░╨╖╨░╤В╨╡╨╗╨╕: `server-resource-guards.md` ┬з3/┬з6, DEPLOY_QUEUE H1794, `.env.example`. healthchecks.io ╨┐╨╛╨╝╨╡╤З╨╡╨╜ obsolete. Executor: Grok 4.5 (`grok-4.5`).
- **samskrtam.ru probe-from-VPS heartbeat script** (Cologne pattern). `/usr/local/sbin/samskrtam-heartbeat.sh` on samskrte: curl home + `/parallel-corpus` + keywords тЖТ Better Stack. Cron after `SAMSKRTAM_HEARTBEAT_URL` in `/etc/default/samskrtam-heartbeat`. Doc ┬з2.2. Executor: Grok 4.5 (`grok-4.5`).

### Changed
- **Uptime doc: FAQ ┬л╤Б╨░╨╣╤В ╤Г╨┐╨░╨╗┬╗ + discoverability.** [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) ┬з5 human/agent runbook (samskrte / samskrtam / Cologne); inbound links from `CLAUDE.md`, `README.md`. Executor: Grok 4.5 (`grok-4.5`).
- **samskrtam VPS heartbeat marked wired** in [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) ┬з2.2 (cron `*/5` live). Executor: Grok 4.5 (`grok-4.5`).

## [1.79.3] - 2026-07-30

### Changed
- **S10 unattended path verified on prod (H1938).** Ten scheduled `support:rollup-web` runs at `:25` (06:25тАУ15:25 MSK, all DONE ~350 ms) after the H1914 guard-test lock cleared; flag still on; rollup totals flat at H1837 baseline because `chat_messages` did not grow (no-op re-aggregate, not a silent fail). Findings in [ROADMAP_SUPPORT_AUTOMATION_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md) ┬зS10. Open: `web_backfill_days=2` gap if scheduler is down longer than two days тАФ a human decides widen vs catch-up. Executor: Grok 4.5 (`grok-4.5`).
- **First-login Telegram ping labels synthetic / placeholder accounts (H1946).** Live pay probes (e.g. prod user **6858** / `h1939.payтАж@example.invalid` from H1939) still fire `OnboardingNotifier::firstLogin` so the probe is visible, but the message is prefixed with `ЁЯзк SYNTHETIC / TEST тАФ ╨╜╨╡ ╨╜╨░╤Б╤В╨╛╤П╤Й╨╕╨╣ ╤Б╤В╤Г╨┤╨╡╨╜╤В`. Weekly onboarding digest excludes the same placeholder suffixes (`@no-email.com`, `@example.invalid` тАФ not `@example.com`, which Faker uses in tests). Documented in [`RESULTS_LOG.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/RESULTS_LOG.md). Executor: Grok 4.5 (`grok-4.5`).

## [1.79.2] - 2026-07-30

### Changed
- **SystemInspector DI for cabinet:probe / guards:verify (H1931 item 3 / H1942).** Both commands used 
ew ShellSystemInspector inline, so no test could prove a critical guards finding actually reaches the probe verdict without a live Linux host. Bound SystemInspector тЖТ ShellSystemInspector in AppServiceProvider; commands resolve via the container. Two CabinetProbe tests swap in FakeSystemInspector (earlyoom down тЖТ ┬л╨Ъ╨░╨▒╨╕╨╜╨╡╤В ╨▒╨╛╨╗╨╡╨╜┬╗ + guards/тАжearlyoom; healthy fake тЖТ ┬л╨Я╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╨╕ ╨Ю╨б ╨╜╨░ ╨╝╨╡╤Б╤В╨╡┬╗). Prod default binding unchanged. Executor: Grok 4.5 (grok-4.5).
- **H1939 wave-1 marathon activation pass (evidence, not LIVE).** Prod smoke AтАУC green (landing 200, registerтЖТenrollment, Tochka hosted checkout 302); D/E PARK (magnet bot token placeholder; Yandex SMTP 554). LandingPage variant A applied; Schedule #1122 start set to 2026-08-28 19:00. Deploy truth = auto-deploy cron (GHA #828 still no-op without Environment secrets). Runbook: [RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md). Executor: Grok 4.5 (grok-4.5).

### Fixed
- **Soft-TG cabinet:probe ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╤Б╨┐╨░╨╝╨╕╤В ╨║╨░╨╢╨┤╤Л╨╡ 15 ╨╝╨╕╨╜.** ╨Ю╤В╨┤╨╡╨╗╤М╨╜╤Л╨╣ cooldown (CABINET_PROBE_TELEGRAM_SOFT_COOLDOWN, default = critical 60) + fingerprint ╨╜╨░╨▒╨╛╤А╨░ soft-fail: ╤В╨╛╤В ╨╢╨╡ ╨╜╨░╨▒╨╛╤А ╨│╨╗╤Г╤И╨╕╤В╤Б╤П, ╨┤╤А╤Г╨│╨╛╨╣ (╨╕╨╗╨╕ --force-alert) ╤И╨╗╤С╤В ╤Б╤А╨░╨╖╤Г. ╨Ч╨░╨│╨╛╨╗╨╛╨▓╨╛╨║ soft-╨░╨╗╨╡╤А╤В╨░ ╤В╨╡╨┐╨╡╤А╤М **╨┐╨╛ scope** (guards / hybrid / тАж), ╨░ ╨╜╨╡ ╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨╜╨░╤П ┬лhybrid / guards┬╗. Executor: Grok 4.5 (grok-4.5).

## [1.79.1] - 2026-07-30

### Fixed
- **False soft-╤Б╨▒╨╛╨╣ ┬л╨░╨▓╤В╨╛-╨┤╨╡╨┐╨╗╨╛╨╣ ╨╝╨╛╨╗╤З╨░ ╨╜╨╡ ╤А╨░╨▒╨╛╤В╨░╨╡╤В┬╗ ╨┐╤А╨╕ ╨╢╨╕╨▓╨╛╨╝ root-╨║╤А╨╛╨╜╨╡.** `cabinet:probe` (www-data, ╨║╨░╨╢╨┤╤Л╨╡ 15 ╨╝╨╕╨╜) ╨╖╨▓╨░╨╗ `ShellSystemInspector::crontabFor('root')`, ╨░ ╤В╨╛╤В ╨┐╤А╨╕ ╨╛╤В╨║╨░╨╖╨╡ `crontab -u root -l` ╨┐╨░╨┤╨░╨╗ ╨╜╨░ bare `crontab -l` тАФ ╤Н╤В╨╛ crontab **╤В╨╡╨║╤Г╤Й╨╡╨│╨╛** ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤П (www-data), ╨▒╨╡╨╖ `systema-auto-deploy-run.sh`. ╨Ш╤В╨╛╨│: soft-TG ╨║╨░╨╢╨┤╤Л╨╡ 15 ╨╝╨╕╨╜, ╤Е╨╛╤В╤П `crontab -l` ╨╛╤В root ╨╕ ╨░╨▓╤В╨╛-╨┤╨╡╨┐╨╗╨╛╨╣ ╨╢╨╕╨▓╤Л. ╨д╨╕╨║╤Б: bare `crontab -l` ╤В╨╛╨╗╤М╨║╨╛ ╨╡╤Б╨╗╨╕ `whoami === $user`; fallback ╨╜╨░ 644-╨╖╨╡╤А╨║╨░╨╗╨╛ `storage/app/server_guards/crontab-root.installed` (╨┐╨╕╤И╨╡╤В `deploy.sh` ╨╕ `server_guards_apply.sh`). ╨в╨╡╨║╤Б╤В soft-╨░╨╗╨╡╤А╤В╨░: ┬л╨╜╨╡╨║╤А╨╕╤В╨╕╤З╨╜╤Л╨╡ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕: hybrid / guards┬╗. ╨в╨╡╤Б╤В╤Л: `ShellSystemInspectorCrontabTest`. Executor: Grok 4.5 (`grok-4.5`).
- **Self-service debt pay copied the wrong block scope (solo promises expanded to tariff full 1тАУ100).** Cabinet debt pay now mirrors the curator grant-for-promise path: prefer blocks from conditional grant payments; without a grant open only the first unpaid block. [#878](https://github.com/gasyoun/Systema-Sanscriticum/pull/878). Executor: Grok 4.5 (`grok-4.5`).
- **╨Ч╨╡╤А╨║╨░╨╗╨╛ root-crontab ╨╛╨▒╨╜╨╛╨▓╨╗╤П╨╡╤В╤Б╤П ╨║╨░╨╢╨┤╤Л╨╡ 30 ╨╝╨╕╨╜, ╨┤╨░╨╢╨╡ ╨▒╨╡╨╖ ╨┤╨╡╨┐╨╗╨╛╤П (H1941 follow-up).** `systema-auto-deploy-run.sh` ╨┐╨╛╤Б╨╗╨╡ flock ╨┐╨╕╤И╨╡╤В `storage/app/server_guards/crontab-root.installed` **╨┤╨╛** early-exit `HEAD==origin/main` ╨╕ ╨┤╨╛ breaker тАФ ╨╕╨╜╨░╤З╨╡ ╤Б╨╜╨╕╨╝╨╛╨║ ╤Б╤В╨░╤А╨╡╨╗, ╨┐╨╛╨║╨░ main ╨╜╨╡ ╨┤╨▓╨╕╨│╨░╨╗╤Б╤П, ╨╕ probe ╨╝╨╛╨│ ╨╜╨╡ ╤Г╨▓╨╕╨┤╨╡╤В╤М ╤А╤Г╤З╨╜╨╛╨╡ ╤Б╨╜╤П╤В╨╕╨╡ auto-deploy. ╨Я╨╛╤Б╨╗╨╡ ╨▓╤Л╨║╨╗╨░╨┤╨║╨╕ ╤И╨░╨▒╨╗╨╛╨╜╨░: `sudo bash scripts/server_guards_apply.sh` (╨╕╨╜╨░╤З╨╡ drift managed-file). Executor: Grok 4.5 (`grok-4.5`).

### Changed
- **SAMSKRTE-TIER0 launch:** short starters `/go H1939` (no full Windows path). Executor: Grok 4.5 (`grok-4.5`).
- **Umbrella ID SAMSKRTE-TIER0:** wave-1 docs renamed to share stem *_SYSTEMA_SAMSKRTE_TIER0_*; banner on every layer + H1939. Executor: Grok 4.5 (`grok-4.5`).

## [1.79.0] - 2026-07-30

### Fixed
- **╨Я╨╡╤А╨▓╤Л╨╣ ╨╢╨╕╨▓╨╛╨╣ ╤Ж╨╕╨║╨╗ ╨░╨▓╤В╨╛-╨┤╨╡╨┐╨╗╨╛╤П ╤Г╨╝╨╡╤А ╨╜╨░ PATH ╨║╤А╨╛╨╜╨░ тАФ composer ╨╜╨╡ ╨╜╨░╨╣╨┤╨╡╨╜ (H1933, ╨║╨╛╨┤ 127).** Debian-cron ╨┤╨░╤С╤В `PATH=/usr/bin:/bin`, ╨░ composer ╨╢╨╕╨▓╤С╤В ╨▓ `/usr/local/bin`: ╤Ж╨╕╨║╨╗ 30-07-2026 10:00Z ╤Г╨┐╨░╨╗, ╨░╨▓╤В╨╛╨╛╤В╨║╨░╤В ╤З╨╡╤Б╤В╨╜╨╛ ╨▓╨╡╤А╨╜╤Г╨╗ ╨┐╤А╨╡╨╢╨╜╨╕╨╣ ╨║╨╛╨╝╨╝╨╕╤В (`git reset` ╤Г╤Б╨┐╨╡╨╗ ╨┤╨╛ composer), ╨┐╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╤М ╨▓╤Б╤В╨░╨╗, ╤Б╨░╨╣╤В ╨╜╨╡ ╨┐╨╛╤Б╤В╤А╨░╨┤╨░╨╗ (200) тАФ ╨║╨╛╨╜╤В╤Г╤А ╨╛╤В╤А╨░╨▒╨╛╤В╨░╨╗ ╤А╨╛╨▓╨╜╨╛ ╨║╨░╨║ ╨╖╨░╨┤╤Г╨╝╨░╨╜, ╨┐╤А╨╕╤З╨╕╨╜╨░ ╨▒╤Л╨╗╨░ ╨▓ ╨╛╨║╤А╤Г╨╢╨╡╨╜╨╕╨╕. ╨Я╨╛╨╗╨╜╤Л╨╣ `PATH` ╤В╨╡╨┐╨╡╤А╤М ╨▓╤Л╤Б╤В╨░╨▓╨╗╤П╤О╤В ╨Ю╨С╨Р ╤А╨╡╨╝╨╜╤П: ╤Н╨║╤Б╨┐╨╛╤А╤В ╨▓ ╨╜╨░╤З╨░╨╗╨╡ [`systema-auto-deploy-run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/systema-auto-deploy-run.sh) (╨╛╨▒╤С╤А╤В╨║╨░ ╨╛╨▒╤П╨╖╨░╨╜╨░ ╤А╨░╨▒╨╛╤В╨░╤В╤М ╨╛╨┤╨╕╨╜╨░╨║╨╛╨▓╨╛ ╨╕╨╖ cron, ╤А╤Г╨║╨░╨╝╨╕ ╨╕ ╨╕╨╖ ╤З╤Г╨╢╨╛╨│╨╛ ╨▓╤Л╨╖╨╛╨▓╨░) ╨╕ ╤Б╤В╤А╨╛╨║╨░ `PATH=` ╨▓ [`cron/root.crontab`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/cron/root.crontab). Executor: Fable 5 (`claude-fable-5`).

### Added
- **`/ask samskrte.ru` Tier-0 plan pack 2026тАУ2027 (docs-only).** Autonomy-ready index + layers: [PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md), roadmap, architecture, wave-1 implementation/verification, [RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md). Wave-1 = marathon 28-08 fully live (funnel тЖТ money activate тЖТ notify тЖТ smoke AтАУE) + DR gates; year spine = GetCourse-parity + growth. 26 interview rulings; money-code fence. Executor: Grok 4.5 (`grok-4.5`).
- **H1067 `@samskrte` channel posts (announce/start/evergreen) automated via the scheduler, with an idempotency guard (H1936, 30-07-2026).** `marathon:publish-channel-posts --live` already existed but was manual-only and had no dedup, so a bare cron entry would have risked duplicate posts to a real public channel on any scheduler overlap or redeploy. Added `marathon_channel_posts_sent` (post_number, run_key) tracking checked/recorded around every live send, then wired posts 1/2/3 into `Kernel::schedule()` (14-08 announce, 28-08 start, weekly evergreen from 04-09, `withoutOverlapping`+`onOneServer`). Posts 4/5 stay manual (date/testimonial-dependent). **Still outstanding (MG, Telegram-side only):** make the magnet bot a channel administrator with Post Messages rights on `@samskrte` тАФ no code/server action can do this. Executor: Sonnet 5 (`claude-sonnet-5`).

## [1.78.0] - 2026-07-30

### Added
- **╨Я╤А╨╛╨┤ ╨┤╨╡╨┐╨╗╨╛╨╕╤В╤Б╤П ╤Б╨░╨╝ ╨║╨░╨╢╨┤╤Л╨╡ 30 ╨╝╨╕╨╜╤Г╤В тАФ root-╨║╤А╨╛╨╜ ╤Б ╨┐╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╨╡╨╝ ╨╕ ╨┐╨╛╤Б╤В-╨┤╨╡╨┐╨╗╨╛╨╣╨╜╨╛╨╣ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╛╨╣ ╨╖╨┤╨╛╤А╨╛╨▓╤М╤П (H1933, ruling MG 30-07-2026).** ╨а╤Г╤З╨╜╨╛╨╣ ╤И╨░╨│ ┬л╨Ш╨▓╨░╨╜ ╨▓╤Л╨║╨╗╨░╨┤╤Л╨▓╨░╨╡╤В ╨┐╨╛ DEPLOY_QUEUE┬╗ ╤Б╨╜╤П╤В: [`systema-auto-deploy-run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/systema-auto-deploy-run.sh) (╤Г╨┐╤А╨░╨▓╨╗╤П╨╡╨╝╤Л╨╣ ╤Д╨░╨╣╨╗ H1914-╨║╨╛╨╜╤В╤Г╤А╨░, ╤Б╤В╨░╨▓╨╕╤В╤Б╤П [`server_guards_apply.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_apply.sh), ╨┤╤А╨╡╨╣╤Д ╨╗╨╛╨▓╨╕╤В `guards:verify`) ╨║╨░╨╢╨┤╤Л╨╡ 30 ╨╝╨╕╨╜╤Г╤В ╤Б╨▓╨╡╤А╤П╨╡╤В `HEAD` ╤Б `origin/main` ╨╕, ╨╡╤Б╨╗╨╕ ╨┐╤А╨╛╨┤ ╨╛╤В╤Б╤В╨░╨╗, ╨│╨╛╨╜╨╕╤В ╤И╤В╨░╤В╨╜╤Л╨╣ [`deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh) тАФ ╨╡╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ ╤Б╨░╨╜╨║╤Ж╨╕╨╛╨╜╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╨╣ ╨┐╤Г╤В╤М ╨▓╤Л╨║╨╗╨░╨┤╨║╨╕, ╤Б╨╛ ╨▓╤Б╨╡╨╝╨╕ ╨╡╨│╨╛ ╨│╨░╤А╨░╨╜╤В╨╕╤П╨╝╨╕ (ff-only, ╤Б╤В╨╛╨┐ ╨╜╨░ ╨│╤А╤П╨╖╨╜╨╛╨╝ ╨┤╨╡╤А╨╡╨▓╨╡, ╨╝╨╕╨│╤А╨░╤Ж╨╕╨╕, OPcache-reload, ╤А╨╡╤Б╤В╨░╤А╤В Horizon, ╤Б╨╝╨╛╤Г╨║, `guards:verify`). ╨Я╨╛╤Б╨╗╨╡ ╨┤╨╡╨┐╨╗╨╛╤П ╨╛╨▒╨╡╤А╤В╨║╨░ ╨Э╨Х╨Ч╨Р╨Т╨Ш╨б╨Ш╨Ь╨Ю ╨┐╤А╨╛╨▓╨╡╤А╤П╨╡╤В, ╤З╤В╨╛ ╤Б╨╡╤А╨▓╨╡╤А ╨╛╤Б╤В╨░╨╗╤Б╤П ╨╢╨╕╨▓: ╤Б╨╝╨╛╤Г╨║ ╨╡╤Й╨╡ ╤А╨░╨╖, `MemAvailable тЙе 1024 ╨Ь╨С` (╨║╨╗╨░╤Б╤Б ╨░╨▓╨░╤А╨╕╨╣ 23-07/28-07), `php8.3-fpm`/`mysql`/`cron` active, Horizon RUNNING. **╨Я╤А╨╛╨▓╨░╨╗ ╨┤╨╡╨┐╨╗╨╛╤П ╨╕╨╗╨╕ ╨╖╨┤╨╛╤А╨╛╨▓╤М╤П тЖТ ╨░╨▓╤В╨╛╨╛╤В╨║╨░╤В, ╨║╨╛╨│╨┤╨░ ╨╛╨╜ ╨▒╨╡╨╖╨╛╨┐╨░╤Б╨╡╨╜** (ruling MG, ╨▓╤В╨╛╤А╨░╤П ╨╕╤В╨╡╤А╨░╤Ж╨╕╤П ╤В╨╛╨│╨╛ ╨╢╨╡ ╨┤╨╜╤П): ╨╡╤Б╨╗╨╕ ╨┤╨╕╨░╨┐╨░╨╖╨╛╨╜ ╨▓╤Л╨║╨╗╨░╨┤╨║╨╕ ╨╜╨╡ ╤В╤А╨╛╨│╨░╨╗ `database/migrations/`, ╨╛╨▒╨╡╤А╤В╨║╨░ ╤Б╨░╨╝╨░ ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В ╨┐╤А╨╡╨╢╨╜╨╕╨╣ ╨║╨╛╨╝╨╝╨╕╤В ╨╜╨╛╨▓╤Л╨╝ ╤А╨╡╨╢╨╕╨╝╨╛╨╝ `deploy.sh --rollback <sha>` (╤В╨╛╤В ╨╢╨╡ ╨║╨╛╨╜╨▓╨╡╨╣╨╡╤А ╨▒╨╡╨╖ pull ╨╕ ╨▒╨╡╨╖ ╨╝╨╕╨│╤А╨░╤Ж╨╕╨╣), ╨┐╨╛╨▓╤В╨╛╤А╨╜╨╛ ╨┐╤А╨╛╨▓╨╡╤А╤П╨╡╤В ╨╖╨┤╨╛╤А╨╛╨▓╤М╨╡ тАФ ╤Б╨░╨╣╤В ╨╢╨╕╨▓╨╡╤В ╨╜╨░ ╤Б╤В╨░╤А╨╛╨╝ ╨║╨╛╨┤╨╡, ╤З╨╡╨╗╨╛╨▓╨╡╨║ ╤З╨╕╨╜╨╕╤В ╤Б╨╗╨╛╨╝╨░╨▓╤И╨╕╨╣ ╨║╨╛╨╝╨╝╨╕╤В ╨▒╨╡╨╖ ╤Б╨┐╨╡╤И╨║╨╕; ╨╝╨╕╨│╤А╨░╤Ж╨╕╨╕ ╨▓ ╨┤╨╕╨░╨┐╨░╨╖╨╛╨╜╨╡ тЖТ ╨╛╤В╨║╨░╤В╨░ ╨Э╨Х╨в (`migrate --force` ╨╜╨╡╨╛╨▒╤А╨░╤В╨╕╨╝, ╨░╨▓╤В╨╛-╤А╨╡╨▓╨╡╤А╤Б ╤Б╤Е╨╡╨╝╤Л ╨╝╨╛╨╢╨╡╤В ╤Б╤К╨╡╤Б╤В╤М ╨┤╨░╨╜╨╜╤Л╨╡). **╨Т ╨╗╤О╨▒╨╛╨╝ ╨┐╤А╨╛╨▓╨░╨╗╤М╨╜╨╛╨╝ ╨╕╤Б╤Е╨╛╨┤╨╡ ╤Б╤В╨░╨▓╨╕╤В╤Б╤П ╨┐╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╤М `storage/auto_deploy.disabled`** (╨┐╤А╨╕╤З╨╕╨╜╨░ + ╨╝╨╡╤В╨║╨░ ╨▓╤А╨╡╨╝╨╡╨╜╨╕ ╨▓╨╜╤Г╤В╤А╨╕) тАФ ╨▒╤Г╨┤╤Г╤Й╨╕╨╡ ╨░╨▓╤В╨╛-╨┤╨╡╨┐╨╗╨╛╨╕ ╨╛╤Б╤В╨░╨╜╨░╨▓╨╗╨╕╨▓╨░╤О╤В╤Б╤П ╨┤╨╛ ╤А╨░╨╖╨▒╨╛╤А╨░ ╤З╨╡╨╗╨╛╨▓╨╡╨║╨╛╨╝, ╨░ ╨╜╨╛╨▓╤Л╨╣ `auditAutoDeploy()` ╨▓ [`ServerGuardsAuditor`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ServerGuards/ServerGuardsAuditor.php) ╤А╨╡╨┐╨╛╤А╤В╨╕╤В ╨╡╨│╨╛ ╤Б severity ╨┐╨╛ ╨╕╤Б╤Е╨╛╨┤╤Г: ╨╝╨╡╤В╨║╨░ `[rolled-back]` (╤Б╨░╨╣╤В ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜) тАФ warning, ╨▒╨╡╨╖ ╨╜╨╡╨╡ тАФ **critical** тЖТ `cabinet:probe` ╤И╨╗╨╡╤В Telegram-╤В╤А╨╡╨▓╨╛╨│╤Г ╨║╨░╨╢╨┤╤Л╨╡ 15 ╨╝╨╕╨╜╤Г╤В, ╨┐╨╛╨║╨░ ╤Д╨░╨╣╨╗ ╨╜╨╡ ╤Г╨┤╨░╨╗╨╡╨╜; ╨┐╤А╨╛╨┐╨░╨╢╨░ cron-╤Б╤В╤А╨╛╨║╨╕ тАФ warning (╤В╨╕╤Е╨░╤П ╨┤╨╡╨│╤А╨░╨┤╨░╤Ж╨╕╤П). flock-╨╖╨░╨╝╨╛╨║ ╨╕╤Б╨║╨╗╤О╤З╨░╨╡╤В ╨┐╨╡╤А╨╡╤Б╨╡╤З╨╡╨╜╨╕╨╡ ╨┐╤А╨╛╨│╨╛╨╜╨╛╨▓ (npm build ╨┤╨╛╨╗╤М╤И╨╡ 30 ╨╝╨╕╨╜╤Г╤В тАФ ╨▓╤В╨╛╤А╨╛╨╣ ╤Б╨╗╨╛╤В ╨╝╨╛╨╗╤З╨░ ╨┐╤А╨╛╨┐╤Г╤Б╨║╨░╨╡╤В╤Б╤П), `git fetch`-╤Б╨▒╨╛╨╣ ╤Б╨╡╤В╨╕ тАФ ╨╜╨╡ ╤В╤А╨╡╨▓╨╛╨│╨░, ╨░ ┬л╨┐╨╛╨┐╤А╨╛╨▒╤Г╨╡╨╝ ╨▓ ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╡╨╝ ╤Б╨╗╨╛╤В╨╡┬╗. ╨з╨╕╤Б╨╗╨░ (`*/30`, ╨┐╨╛╤В╨╛╨╗╨╛╨║ 1500 ╤Б, ╨┐╨╛╤А╨╛╨│ ╨┐╨░╨╝╤П╤В╨╕, ╤Б╨╝╨╛╤Г╨║-URL) тАФ ╤В╨╛╨╗╤М╨║╨╛ ╨▓ [`server_guards.conf`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards.conf) (`AUTO_DEPLOY_*`); root-╨║╤А╨╛╨╜ тАФ ╨╜╨╛╨▓╤Л╨╣ ╤Г╨┐╤А╨░╨▓╨╗╤П╨╡╨╝╤Л╨╣ [`cron/root.crontab`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/cron/root.crontab). ╨в╤А╨╕ ╨╜╨╛╨▓╤Л╤Е ╤В╨╡╤Б╤В╨░ ╨░╤Г╨┤╨╕╤В╨╛╤А╨░ (╨┐╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╤М critical ╤Б ╨┐╤А╨╕╤З╨╕╨╜╨╛╨╣, ╨┐╤А╨╛╨┐╨░╨▓╤И╨░╤П ╤Б╤В╤А╨╛╨║╨░ warning, ╨┤╤А╨╡╨╣╤Д ╤А╨░╤Б╨┐╨╕╤Б╨░╨╜╨╕╤П warning) + ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╡ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕ ╤И╨░╨▒╨╗╨╛╨╜╨╛╨▓/╨╝╨░╨╜╨╕╤Д╨╡╤Б╤В╨░ ╨┐╨╛╨║╤А╤Л╨▓╨░╤О╤В ╨╜╨╛╨▓╤Л╨╡ ╤Д╨░╨╣╨╗╤Л; ╤А╨░╨╖╨▒╨╛╤А ╨╕ ╨║╨╛╨╝╨░╨╜╨┤╤Л ╨╛╨┐╨╡╤А╨░╨╝ тАФ [server-resource-guards.md ┬з8](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md). Executor: Fable 5 (`claude-fable-5`).

## [1.77.0] - 2026-07-30

### Added
- **`lessons:backfill-recording-stamp` тАФ ╨┐╨╛╤З╨╕╨╜╨║╨░ ╤В╤А╨╕╨│╨│╨╡╤А╨░ ╨░╨▓╤В╨╛╨╛╤В╨║╤А╤Л╤В╨╕╤П ╨Ф╨Ч, ╤Б╨╗╨╡╨┐╨╛╨│╨╛ ╨╜╨░ 99.7% ╨║╨░╤В╨░╨╗╨╛╨│╨░ (H1935, [issue #868](https://github.com/gasyoun/Systema-Sanscriticum/issues/868)).** `lessons.recording_attached_at` ╤И╤В╨░╨╝╨┐╤Г╨╡╤В╤Б╤П ╤Е╤Г╨║╨╛╨╝ `Lesson::boot()` ╤В╨╛╨╗╤М╨║╨╛ ╨┐╤А╨╕ ╨б╨Ю╨е╨а╨Р╨Э╨Х╨Э╨Ш╨Ш ╤Г╤А╨╛╨║╨░ ╤Б ╨▓╨╕╨┤╨╡╨╛, ╨░ ╨║╨╛╨╗╨╛╨╜╨║╨░ ╨┐╨╛╤П╨▓╨╕╨╗╨░╤Б╤М 27-07-2026 тАФ ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨╜╨░ ╨┐╤А╨╛╨┤╨╡ ╨╛╨╜╨░ ╨▒╤Л╨╗╨░ ╨╖╨░╨┐╨╛╨╗╨╜╨╡╨╜╨░ ╤Г **5 ╤Г╤А╨╛╨║╨╛╨▓ ╨╕╨╖ 1667, ╤Г ╨║╨╛╤В╨╛╤А╤Л╤Е ╨╡╤Б╤В╤М ╨╖╨░╨┐╨╕╤Б╤М**. ╨Ф╨╗╤П `scopeAutoOpenCandidates()`/`backfillCandidates()` ╨┐╤Г╤Б╤В╨╛╨╣ ╤И╤В╨░╨╝╨┐ ╨╜╨╡╨╛╤В╨╗╨╕╤З╨╕╨╝ ╨╛╤В ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╤П ╨╖╨░╨┐╨╕╤Б╨╕, ╤В╨░╨║ ╤З╤В╨╛ `homework:auto-open` ╨╝╨╛╨╗╤З╨░ ╤А╨░╨┐╨╛╤А╤В╨╛╨▓╨░╨╗ ┬л╨╛╤В╨║╤А╤Л╤В╨╛ 0 ╨╕╨╖ 0┬╗ тАФ ╤Г╤Б╨┐╨╡╤Е ╨┐╤А╨╕ ╨╜╨╡╨▓╤Л╨┐╨╛╨╗╨╜╨╡╨╜╨╜╨╛╨╣ ╤А╨░╨▒╨╛╤В╨╡, ╤В╨╛╤В ╨╢╨╡ ╨║╨╗╨░╤Б╤Б, ╤З╤В╨╛ [#828](https://github.com/gasyoun/Systema-Sanscriticum/issues/828). ╨Ъ╨╛╨╝╨░╨╜╨┤╨░ ╨▓╨╛╤Б╤Б╤В╨░╨╜╨░╨▓╨╗╨╕╨▓╨░╨╡╤В ╨╛╨┐╨╛╤А╨╜╤Г╤О ╤В╨╛╤З╨║╤Г ╨╕╨╖ `lesson_date` ╨╕ ╨┐╨╡╤А╨╡╤Б╤З╨╕╤В╤Л╨▓╨░╨╡╤В `homework_opens_at` ╤З╨╡╤А╨╡╨╖ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╣ `HomeworkAutoOpener::opensAtFor()` (╨╜╨╡ ╤Б╨▓╨╛╨╡╨╣ ╨║╨╛╨┐╨╕╨╡╨╣ ╤Д╨╛╤А╨╝╤Г╨╗╤Л), ╨┤╨░╤С╤В ┬л╨Ф╨Ч ╨╛╤В╨║╤А╤Л╨▓╨░╨╡╤В╤Б╤П ╨╜╨░╨╖╨░╨▓╤В╤А╨░ ╨┐╨╛╤Б╨╗╨╡ ╨╖╨░╨╜╤П╤В╨╕╤П┬╗. **╨Я╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О dry-run**; `--apply` ╨┐╨╕╤И╨╡╤В, `--course=`/`--limit=` ╤Б╤Г╨╢╨░╤О╤В ╨┐╤А╨╛╨│╨╛╨╜. ╨Ч╨╜╨░╤З╨╡╨╜╨╕╤П ╨┐╨╛╨╝╨╡╤З╨╡╨╜╤Л ╨║╨░╨║ ╨а╨Х╨Ъ╨Ю╨Э╨б╨в╨а╨г╨Ъ╨ж╨Ш╨п, ╨░ ╨╜╨╡ ╨╜╨░╤Е╨╛╨┤╨║╨░ тАФ ╤А╨╡╨░╨╗╤М╨╜╨╛╨│╨╛ ╨╝╨╛╨╝╨╡╨╜╤В╨░ ╨┐╤А╨╕╨║╤А╨╡╨┐╨╗╨╡╨╜╨╕╤П ╨▓ ╨▒╨░╨╖╨╡ ╨╜╨╡╤В. ╨Ъ╨╛╨╝╨░╨╜╨┤╨░ ╨╜╨╡ ╤В╤А╨╛╨│╨░╨╡╤В `homework_enabled`/`homework_prompt`/`homework_auto_opened_at` ╨╕ **╨╜╨╡ ╤И╨╗╤С╤В ╨╜╨╕ ╨╛╨┤╨╜╨╛╨│╨╛ ╤Г╨▓╨╡╨┤╨╛╨╝╨╗╨╡╨╜╨╕╤П** (╨╕╨╜╨░╤З╨╡ ╨┐╤А╨╛╨│╨╛╨╜ ╨┐╨╛ ╨░╤А╤Е╨╕╨▓╤Г ╤А╨░╨╖╨╛╤Б╨╗╨░╨╗ ╨▒╤Л ╨┐╤Г╤И╨╕ ╨┐╨╛ ╨▓╤Б╨╡╨╝ ╨┐╤А╨╛╤И╨╡╨┤╤И╨╕╨╝ ╤Г╤А╨╛╨║╨░╨╝) тАФ ╨╖╨░╨║╤А╤Л╤В╨╛ ╤В╨╡╤Б╤В╨╛╨╝ `backfill_never_opens_homework_and_never_notifies`. Executor: Opus 5 (`claude-opus-5[1m]`).
- **╨С╨╕╨▒╨╗╨╕╨╛╤В╨╡╨║╨░ ╤И╨░╨▒╨╗╨╛╨╜╨╛╨▓ ╨╛╤В╨║╤А╤Л╤В╨░ ╨║╤Г╤А╨░╤В╨╛╤А╤Г тАФ ╤Б append-only ╨╕╤Б╤В╨╛╤А╨╕╨╡╨╣ ╨┐╤А╨░╨▓╨╛╨║ (H1932, ruling 30-07-2026).** ┬л╨и╨░╨▒╨╗╨╛╨╜╤Л ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╣┬╗ ╨▒╤Л╨╗╨╕ ╨╖╨░╨┐╨╡╤А╤В╤Л ╨╖╨░ `AdminOnly`: ╨║╤Г╤А╨░╤В╨╛╤А (`manager`), ╤З╤М╨╕╨╝ ╤А╨░╨▒╨╛╤З╨╕╨╝ ╨╕╨╜╤Б╤В╤А╤Г╨╝╨╡╨╜╤В╨╛╨╝ ╨▒╨╕╨▒╨╗╨╕╨╛╤В╨╡╨║╨░ ╨╕ ╤П╨▓╨╗╤П╨╡╤В╤Б╤П (╨║╨░╨╜╤А╨╡╨┐╨╗╨░╨╕, ╤А╨╡╨░╨║╤В╨╕╨▓╨░╤Ж╨╕╤П, ╤В╨╡╨┐╨╡╤А╤М ╨╕ ╤И╨░╨▒╨╗╨╛╨╜╨╜╤Л╨╡ ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║╨╕ ╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А╨░ H1838), ╨╜╨╡ ╨╝╨╛╨│ ╨┤╨░╨╢╨╡ ╨╛╤В╨║╤А╤Л╤В╤М ╨╡╤С. ╨в╨╡╨┐╨╡╤А╤М ╤Б╨╝╨╛╤В╤А╨╡╤В╤М/╤Б╨╛╨╖╨┤╨░╨▓╨░╤В╤М/╨┐╤А╨░╨▓╨╕╤В╤М ╨╝╨╛╨│╤Г╤В ╨░╨┤╨╝╨╕╨╜, ╤Б╤Г╨┐╨╡╤А-╨░╨┤╨╝╨╕╨╜ ╨╕ ╨║╤Г╤А╨░╤В╨╛╤А; **╤Г╨┤╨░╨╗╨╡╨╜╨╕╨╡ ╨╛╤Б╤В╨░╨╗╨╛╤Б╤М ╨╖╨░ ╨░╨┤╨╝╨╕╨╜╨╛╨╝** тАФ ╤В╨╡╨║╤Б╤В╤Л ╨┐╨╕╤В╨░╤О╤В ╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А ╨╕ ╤А╨░╤Б╤Б╤Л╨╗╨║╨╕. ╨ж╨╡╨╜╨░ ╤А╨░╤Б╤И╨╕╤А╨╡╨╜╨╕╤П ╨┤╨╛╤Б╤В╤Г╨┐╨░ тАФ ╨┐╨╛╨┤╨╛╤В╤З╤С╤В╨╜╨╛╤Б╤В╤М: ╨╜╨╛╨▓╤Л╨╣ [`MessageTemplateAuditObserver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/MessageTemplateAuditObserver.php) ╨┐╨╕╤И╨╡╤В ╨║╨░╨╢╨┤╤Г╤О ╨┐╤А╨░╨▓╨║╤Г ╨▓ append-only `message_template_audits` (╨╖╨╡╤А╨║╨░╨╗╨╛ `lead_audits`: ╨║╤В╨╛, ╨║╨╛╨│╨┤╨░, ╨║╨░╨║╨╛╨╡ ╨┐╨╛╨╗╨╡, ╨▒╤Л╨╗╨╛ тЖТ ╤Б╤В╨░╨╗╨╛; ╨┤╨╡╨╣╤Б╤В╨▓╨╕╤П ╨╕╨╖ tinker/CLI тАФ ┬л╨б╨╕╤Б╤В╨╡╨╝╨░┬╗), ╨░ ╨▓╨║╨╗╨░╨┤╨║╨░ ┬л╨Ш╤Б╤В╨╛╤А╨╕╤П ╨╕╨╖╨╝╨╡╨╜╨╡╨╜╨╕╨╣┬╗ ([`AuditsRelationManager`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/MessageTemplateResource/RelationManagers/AuditsRelationManager.php)) ╨┐╨╛╨║╨░╨╖╤Л╨▓╨░╨╡╤В ╤В╨░╨╣╨╝╨╗╨░╨╣╨╜ ╨┐╤А╤П╨╝╨╛ ╨╜╨░ ╤Б╤В╤А╨░╨╜╨╕╤Ж╨╡ ╤И╨░╨▒╨╗╨╛╨╜╨░ тАФ ╨▓╨╕╨┤╨╜╨░ ╨╕ ╨║╤Г╤А╨░╤В╨╛╤А╤Г, ╤А╨░╨╖ ╨╛╨╜ ╨┐╤А╨░╨▓╨╕╤В. Executor: Fable 5 (`claude-fable-5`).

### Fixed
- **╨в╨░╨╣╨╝╨╗╨░╨╣╨╜ ╨░╤Г╨┤╨╕╤В╨░ ╨╗╨╕╨┤╨╛╨▓ ╨┐╨╛╨║╨░╨╖╤Л╨▓╨░╨╗ ┬лтАФ┬╗ ╨▓╨╝╨╡╤Б╤В╨╛ ╨╕╨╖╨╝╨╡╨╜╨╡╨╜╨╕╨╣ тАФ ╨╜╨░ ╨▓╤Б╨╡╤Е ╤Б╤В╤А╨╛╨║╨░╤Е, ╤З╨╕╤В╨░╨╡╨╝╤Л╤Е ╨╕╨╖ ╨С╨Ф (H1932, ╨╗╨░╤В╨╡╨╜╤В╨╜╨╛ ╤Б H221).** `LeadAudit::summary()` ╤З╨╕╤В╨░╨╗ `$this->changes`, ╨░ ╤Н╤В╨╛ ╨▓╨╜╤Г╤В╤А╨╕ Eloquent-╨╝╨╛╨┤╨╡╨╗╨╕ ╨┐╨╛╨┐╨░╨┤╨░╨╡╤В ╨▓ protected-╤Б╨▓╨╛╨╣╤Б╤В╨▓╨╛ `HasAttributes::$changes` (╨▓╨╜╤Г╤В╤А╨╡╨╜╨╜╨╕╨╣ ╤В╤А╨╡╨║╨╕╨╜╨│ dirty-╤Б╨╕╨╜╨║╨░), ╨┐╤Г╤Б╤В╨╛╨╡ ╤Г ╨┐╨╡╤А╨╡╤З╨╕╤В╨░╨╜╨╜╨╛╨╣ ╨╕╨╖ ╨С╨Ф ╤Б╤В╤А╨╛╨║╨╕ тАФ JSON-╨░╤В╤А╨╕╨▒╤Г╤В `changes` ╨┤╨░╨╢╨╡ ╨╜╨╡ ╤Б╨┐╤А╨░╤И╨╕╨▓╨░╨╗╤Б╤П. ╨Т╤Б╨┐╨╗╤Л╨╗╨╛ ╤В╨╡╤Б╤В╨╛╨╝ ╨╜╨╛╨▓╨╛╨│╨╛ ╨╖╨╡╤А╨║╨░╨╗╤М╨╜╨╛╨│╨╛ ╨░╤Г╨┤╨╕╤В╨░ ╤И╨░╨▒╨╗╨╛╨╜╨╛╨▓; ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╨╛ ╨╜╨░ `getAttribute('changes')` ╨▓ ╨╛╨▒╨╡╨╕╤Е ╨╝╨╛╨┤╨╡╨╗╤П╤Е ╨╕ ╨╖╨░╨║╤А╤Л╤В╨╛ ╤А╨╡╨│╤А╨╡╤Б╤Б╨╛╨╝ `summary_renders_changes_on_a_model_reloaded_from_db`. Executor: Fable 5 (`claude-fable-5`).

## [1.76.4] - 2026-07-30

### Fixed
- **╨и╨╡╤Б╤В╤М ╨║╨╛╨╝╨░╨╜╨┤ ╤А╨░╤Б╨┐╨╕╤Б╨░╨╜╨╕╤П ╨╕ IMAP-╤Б╨║╨░╨╜╨╡╤А ╨┐╨╕╤Б╨╡╨╝ ╨╛╨│╤А╨░╨╜╨╕╤З╨╡╨╜╤Л ╤Б╨╛╨▒╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╝ ╨┐╨╛╤В╨╛╨╗╨║╨╛╨╝, ╨╜╨╡ ╤В╨╛╨╗╤М╨║╨╛ ╨▓╨╜╨╡╤И╨╜╨╕╨╝ ╨┐╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╨╡╨╝ (H1916).** ╨Р╤Г╨┤╨╕╤В ╨┐╨╛╤Б╨╗╨╡ ╨┐╤А╨╛╤Б╤В╨╛╤П 28тАУ29.07 ([H1904](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1904-Opus_Systema-Sanscriticum_server-oom-scheduler-pileup-guards_29.07.26.md)) ╨╜╨░╤И╤С╨╗ ╤А╨╛╨▓╨╜╨╛ ╨┤╨▓╨╡ ╨┤╤Л╤А╤Л ╤В╨╛╨│╨╛ ╨╢╨╡ ╨║╨╗╨░╤Б╤Б╨░: `mail:scan-bounces` ╨╛╤В╨║╤А╤Л╨▓╨░╨╗ `imap_open()` ╨▒╨╡╨╖ `imap_timeout()` (╨╜╨╡╨┤╨╛╤Б╤В╤Г╨┐╨╜╤Л╨╣ IMAP-╤Е╨╛╤Б╤В ╨┐╨╛╨┤╨▓╨╡╤И╨╕╨▓╨░╨╗ ╨╡╨╢╨╡╤З╨░╤Б╨╜╤Л╨╣ ╨╖╨░╤Е╨╛╨┤ ╨╜╨░ ╨╜╨╡╨╛╨┐╤А╨╡╨┤╨╡╨╗╤С╨╜╨╜╤Л╨╣ ╤Б╤А╨╛╨║, ╨╖╨░╨╝╨╡╤А ╨╜╨░ ╨┐╤А╨╛╨┤╨╡ тАФ 3 ╨╝╨╕╨╜ 21 ╤Б) ╨╕ ╨▒╨╡╨╖ `->withoutOverlapping()`; ╨╡╤Й╤С ╨┐╤П╤В╤М ╨║╨╛╨╝╨░╨╜╨┤ (`archives:cleanup`, `promises:expire`, `unreliable:recount`, `promises:remind-tomorrow`, `onboarding:weekly-digest`) ╨▓╨╛╨▓╤Б╨╡ ╨╜╨╡ ╨╕╨╝╨╡╨╗╨╕ `->withoutOverlapping()`, ╨┐╤А╨╕ ╤В╨╛╨╝ ╤З╤В╨╛ ~39 ╤Б╨╛╤Б╨╡╨┤╨╜╨╕╤Е ╨║╨╛╨╝╨░╨╜╨┤ ╨╡╤С ╤Г╨╢╨╡ ╨╕╨╝╨╡╤О╤В тАФ ╤В╨╛ ╨╡╤Б╤В╤М ╤Н╤В╨╛ ╨┐╤А╨╛╨┐╤Г╤Б╨║, ╨░ ╨╜╨╡ ╤А╨╡╤И╨╡╨╜╨╕╨╡. ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜╨╛: `imap_timeout(IMAP_OPENTIMEOUT, 15)`/`imap_timeout(IMAP_READTIMEOUT, 30)` ╨┐╨╡╤А╨╡╨┤ `imap_open()` ╨▓ [`ScanBounces.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ScanBounces.php) (╨┐╨╛╤А╨╛╨│╨╕ тАФ ╨▓ `config/mail.php` `bounce_scan.open_timeout_seconds`/`read_timeout_seconds`, env-backed); `->withoutOverlapping(10)->onOneServer()->name(...)` ╨╜╨░ ╨▓╤Б╨╡ ╤И╨╡╤Б╤В╤М ╨║╨╛╨╝╨░╨╜╨┤ ╨▓ [`Kernel.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php). ╨Р╤Г╨┤╨╕╤В ╨╛╤Б╤В╨░╨╗╤М╨╜╨╛╨│╨╛ `app/` ╨╜╨░ ╨┤╤А╤Г╨│╨╕╨╡ ╨╜╨╡╨╛╨│╤А╨░╨╜╨╕╤З╨╡╨╜╨╜╤Л╨╡ ╤Б╨╡╤В╨╡╨▓╤Л╨╡ ╨▓╤Л╨╖╨╛╨▓╤Л (`Http::` ╨▒╨╡╨╖ `->timeout()`, ╨│╨╛╨╗╤Л╨╣ `curl_exec`, `fsockopen`) ╨╜╨╕╤З╨╡╨│╨╛ ╨╜╨╡ ╨╜╨░╤И╤С╨╗ тАФ ╤Д╨░╤Б╨░╨┤ `Http::` ╤Г╨╢╨╡ ╤В╨░╨╣╨╝╨░╤Г╤В╨╕╤В ╨╖╨░ 30 ╤Б ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О, ╨╡╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ `curl_exec` (`CertificateService.php`) ╤Г╨╢╨╡ ╤Б╤В╨░╨▓╨╕╤В `CURLOPT_TIMEOUT`. `php artisan test --parallel` ╨╖╨╡╨╗╨╡╨╜ (248 Unit + 2231 Feature, 0 ╨┐╨░╨┤╨╡╨╜╨╕╨╣), Pint ╤З╨╕╤Б╤В. Executor: Sonnet 5 (`claude-sonnet-5`).
- **Watchdog MTProto-╤Б╨╕╨╜╨║╨░ ╨╜╨░╨║╨╛╨╜╨╡╤Ж ╤Г╨▒╨╕╨▓╨░╨╡╤В ╨╖╨░╨▓╨╕╤Б╤И╨╕╨╣ ╨╖╨░╤Е╨╛╨┤: ╨╛╨╜ ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨▒╤А╨╛╤Б╨░╨╡╤В ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╨╡, ╨░ ╨╖╨░╨▓╨╡╤А╤И╨░╨╡╤В ╨┐╤А╨╛╤Ж╨╡╤Б╤Б ╤Б╨░╨╝ (H1915, [#840](https://github.com/gasyoun/Systema-Sanscriticum/issues/840)).** 28-07-2026 ╨╖╨░╤Е╨╛╨┤ `telegram-support:sync` ╨┐╤А╨╛╨╢╨╕╨╗ **10 470 ╤Б ╨┐╤А╨╕ ╨┐╨╛╤В╨╛╨╗╨║╨╡ 120 ╤Б** (87x) тАФ ╨╕ ╨╖╨░╨▓╨╡╤А╤И╨╕╨╗╤Б╤П **╨║╨╛╨┤╨╛╨╝ 0** (`DONE` ╨▓ `schedule.log`, ╨╜╨╡ `FAIL`); ╨╕╨╝╨╡╨╜╨╜╨╛ ╨╛╨╜ ╨╜╨░ 2 ╤З 54 ╨╝╨╕╨╜ ╨╖╨░╨║╨╗╨╕╨╜╨╕╨╗ ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║ ╨╕ ╨┤╨╛╨▓╤С╨╗ ╨║╨╛╨╜╤В╨╡╨╣╨╜╨╡╤А ╨┤╨╛ OOM. ╨Ч╨░╨╝╨╡╤А ╨╜╨░ ╨╢╨╕╨▓╨╛╨╝ ╤Е╨╛╤Б╤В╨╡ (PHP 8.3.32, `pcntl` ╨╖╨░╨│╤А╤Г╨╢╨╡╨╜, Revolt ╨╜╨░ `StreamSelectDriver`) ╨╛╨┐╤А╨╛╨▓╨╡╤А╨│ ╨▓╤Б╨╡ ╤З╨╡╤В╤Л╤А╨╡ ╤А╨░╨▒╨╛╤З╨╕╨╡ ╨│╨╕╨┐╨╛╤В╨╡╨╖╤Л: **SIGALRM ╨┤╨╛╤Б╤В╨░╨▓╨╗╤П╨╡╤В╤Б╤П ╨▒╨╡╨╖╨╛╤В╨║╨░╨╖╨╜╨╛** тАФ ╨▓ busy-╤Ж╨╕╨║╨╗╨╡, ╨▓ `sleep()`, ╨▓╨╜╤Г╤В╤А╨╕ `EventLoop::run()`, ╨┐╤А╨╕ ╨╖╨░╤А╨╡╨│╨╕╤Б╤В╤А╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛╨╝ signal-watcher'╨╡ ╨╕ ╨┤╨░╨╢╨╡ ╨▓ ╨▒╨╗╨╛╨║╨╕╤А╤Г╤О╤Й╨╡╨╝ `flock(LOCK_EX)`. ╨Э╨╡ ╨┐╨╡╤А╨╡╨╢╨╕╨▓╨░╨╗╨╛ ╨┤╨╛╤Б╤В╨░╨▓╨║╤Г **╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╨╡**: ╤Б╨╕╨│╨╜╨░╨╗ ╨┐╤А╨╕╨╗╨╡╤В╨░╨╡╤В ╨▓ ╨┐╤А╨╛╨╕╨╖╨▓╨╛╨╗╤М╨╜╨╛╨╣ ╤В╨╛╤З╨║╨╡, ╨┤╨╗╤П ╤Б╨╡╤В╨╡╨▓╨╛╨│╨╛ ╤Б╨╕╨╜╨║╨░ ╤Н╤В╨╛ ╨┐╨╛╤З╤В╨╕ ╨▓╤Б╨╡╨│╨┤╨░ callback ╤Ж╨╕╨║╨╗╨░ ╨╕╨╗╨╕ ╤Д╨░╨╣╨▒╨╡╤А, Revolt ╨╛╤В╨┤╨░╤С╤В ╤В╨░╨║╨╛╨╡ ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╨╡ ╨▓ `EventLoop::setErrorHandler()`, ╨░ MadelineProto ╤Б╤В╨░╨▓╨╕╤В ╤В╤Г╨┤╨░ ╨╛╨▒╤А╨░╨▒╨╛╤В╤З╨╕╨║ ┬л╨╖╨░╨╗╨╛╨│╨╕╤А╨╛╨▓╨░╤В╤М ╨╕ ╨┐╤А╨╛╨┤╨╛╨╗╨╢╨╕╤В╤М┬╗ (`AbstractAPI.php:41`, `API.php:429`) тАФ ╨┐╨╗╤О╤Б **76** ╨▒╨╗╨╛╨║╨╛╨▓ `catch (Throwable)` ╨▓ ╨▒╨╕╨▒╨╗╨╕╨╛╤В╨╡╨║╨╡. ╨Р `pcntl_alarm` **╨╛╨┤╨╜╨╛╤А╨░╨╖╨╛╨▓╤Л╨╣**: ╨┐╤А╨╛╨│╨╗╨╛╤З╨╡╨╜╨╜╤Л╨╣ ╤А╨░╨╖ ╨┐╨╛╤В╨╛╨╗╨╛╨║ ╨╕╤Б╤З╨╡╨╖╨░╨╡╤В ╨╜╨░╤Б╨╛╨▓╤Б╨╡╨╝, ╨╕ ╨╛╤Б╤В╨░╤В╨╛╨║ ╨╖╨░╤Е╨╛╨┤╨░ ╨╕╨┤╤С╤В ╨▒╨╡╨╖ ╨╛╨│╤А╨░╨╜╨╕╤З╨╡╨╜╨╕╤П тАФ ╨╛╤В╤Б╤О╨┤╨░ ╨┐╨░╤А╨░ ┬л10 470 ╤Б ╨Ш ╨║╨╛╨┤ 0┬╗, ╨╕╨╜╨░╤З╨╡ ╨╜╨╡ ╤Б╤Е╨╛╨┤╤П╤Й╨░╤П╤Б╤П. ╨Ъ╨╛╨╜╤В╤А╨╛╨╗╤М╨╜╤Л╨╣ ╨┐╤А╨╛╨│╨╛╨╜ ╤Б╤В╨░╤А╨╛╨╣ ╨▓╨╡╤А╤Б╨╕╨╕ ╨╜╨░ ╨▓╨╛╤Б╨┐╤А╨╛╨╕╨╖╨▓╨╡╨┤╤С╨╜╨╜╨╛╨╣ ╤Д╨╛╤А╨╝╨╡: ╨┐╨╡╤А╨╡╨╢╨╕╨╗╨░ ╨┐╨╛╤В╨╛╨╗╨╛╨║ ╨▓ 6 ╤А╨░╨╖, ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╨╡ ╨┐╤А╨╛╨│╨╗╨╛╤З╨╡╨╜╨╛, ╨╜╨░╤А╤Г╨╢╤Г ╨╜╨╕╤З╨╡╨│╨╛. ╨в╨╡╨┐╨╡╤А╤М ╨╛╨▒╤А╨░╨▒╨╛╤В╤З╨╕╨║ ╨▓╤Л╨┐╨╛╨╗╨╜╤П╨╡╤В ╤Г╨▒╨╛╤А╨║╤Г (╨╛╤В╨┐╤Г╤Б╤В╨╕╤В╤М ╨╖╨░╨╝╨╛╨║ ╤Б╨╡╤Б╤Б╨╕╨╕, ╨┐╤А╨╕╨▒╤А╨░╤В╤М ╨┤╨╡╨╝╨╛╨╜╨░, ╨┐╨╛╨╝╨╡╤В╨╕╤В╤М ╨░╨║╨║╨░╤Г╨╜╤В) ╨╕ ╨╖╨░╨▓╨╡╤А╤И╨░╨╡╤В ╨┐╤А╨╛╤Ж╨╡╤Б╤Б ╨║╨╛╨┤╨╛╨╝ `75` тАФ `exit()` ╨╕╨╖ ╨╛╨▒╤А╨░╨▒╨╛╤В╤З╨╕╨║╨░ ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜ ╨▓ callback'╨╡ ╤Ж╨╕╨║╨╗╨░, ╨╜╨░ ╨┐╤А╨╕╨╛╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╜╨╛╨╝ ╨│╨╗╨░╨▓╨╜╨╛╨╝ ╤Д╨░╨╣╨▒╨╡╤А╨╡ ╨╕ ╨▓╨╜╤Г╤В╤А╨╕ ╤В╨╡╨╗╨░ ╤Д╨░╨╣╨▒╨╡╤А╨░. ╨в╨░ ╨╢╨╡ ╨┐╤А╨░╨▓╨║╨░ ╨┐╤А╨╕╨╝╨╡╨╜╨╡╨╜╨░ ╨║ `telegram-harvest:roster-groups` (╤Б╨╡╤Б╤Б╨╕╤П ╤Г ╨╜╨╕╤Е ╨╛╨▒╤Й╨░╤П). ╨а╨╡╨│╤А╨╡╤Б╤Б╨╕╤П ╨╖╨░╨║╤А╨╡╨┐╨╗╨╡╨╜╨░ ╤В╨╡╤Б╤В╨╛╨╝, ╨▓╨╛╤Б╨┐╤А╨╛╨╕╨╖╨▓╨╛╨┤╤П╤Й╨╕╨╝ ╨╕╨╝╨╡╨╜╨╜╨╛ ╨┐╤А╨╛╨▓╨░╨╗╨╕╨▓╨░╨▓╤И╤Г╤О ╤Д╨╛╤А╨╝╤Г, ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╝ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╛╨╝. Executor: Opus 5 1M (`claude-opus-5[1m]`).
- **TTL ╨╖╨░╨╝╨║╨░ ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║╨░ ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨▓╤Л╨▓╨╛╨┤╨╕╤В╤Б╤П ╨╕╨╖ watchdog-╤В╨░╨╣╨╝╨░╤Г╤В╨░ тАФ ╤В╨╛╨╗╤М╨║╨╛ ╨╕╨╖ ╨│╨░╤А╨░╨╜╤В╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛╨│╨╛ ╨┐╨╛╤В╨╛╨╗╨║╨░ ╨╢╨╕╨╖╨╜╨╕ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨░ (H1915).** `Kernel.php` ╤Б╤В╤А╨╛╨╕╨╗ ╨╜╨░ ╨╜╤С╨╝ ╨░╤А╨│╤Г╨╝╨╡╨╜╤В ╨▒╨╡╨╖╨╛╨┐╨░╤Б╨╜╨╛╤Б╤В╨╕ (┬л╨┐╨╛╨║╨░ ╤В╨░╨╣╨╝╨░╤Г╤В < TTL, ╨╖╨░╨▓╨╕╤Б╤И╨╕╨╣ ╨╖╨░╤Е╨╛╨┤ ╤Г╨╝╨╕╤А╨░╨╡╤В ╨┐╨╡╤А╨▓╤Л╨╝┬╗); 28-07 ╨╕╨╜╨▓╨░╤А╨╕╨░╨╜╤В ╨╜╨╡ ╨▓╤Л╨┐╨╛╨╗╨╜╨╕╨╗╤Б╤П тАФ TTL ╨▒╤Л╨╗ 7 ╨╝╨╕╨╜, ╨╖╨░╤Е╨╛╨┤ ╨┐╤А╨╛╨╢╨╕╨╗ 174 ╨╝╨╕╨╜, ╨╖╨░╨╝╨╛╨║ ╨┐╤А╨╛╤В╤Г╤Е **╨┤╨▓╨░╨┤╤Ж╨░╤В╤М ╨┐╤П╤В╤М ╤А╨░╨╖**. Watchdog ╨┐╨╛╤З╨╕╨╜╨╡╨╜, ╨╜╨╛ ╨▓╤Л╨▓╨╛╨┤╨╕╤В╤М ╨│╤А╨░╨╜╨╕╤Ж╤Г ╨╕╨╖ ╨╜╨╡╨│╨╛ ╨╜╨╡╨╗╤М╨╖╤П: ╨▒╨╡╨╖ `pcntl` ╨╛╨╜ ╤З╨╡╤Б╤В╨╜╤Л╨╣ no-op. TTL ╤Б╤З╨╕╤В╨░╨╡╤В╤Б╤П ╨║╨░╨║ `ceil(max(watchdog_timeout, 2 ├Ч SCHEDULE_MAX_SECONDS) / 60) + 5` тАФ ╨╛╨▒╤С╤А╤В╨║╨░ ╤Б╨╜╨╕╨╝╨░╨╡╤В ╨╖╨░╤Е╨╛╨┤ ╨┐╨╛ `timeout`, straggler'╨░ ╨┤╨╛╨▒╨╕╨▓╨░╨╡╤В reaper ╨┐╤А╨╕ ╨▓╨╛╨╖╤А╨░╤Б╤В╨╡ > 2x ╨┐╨╛╤В╨╛╨╗╨║╨░, ╨┤╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨╢╨╕╨▓╤С╤В ╨╜╨╕╨║╤В╨╛. ╨Я╤А╨╕ 900 ╤Б ╤Н╤В╨╛ **35 ╨╝╨╕╨╜ ╨┐╤А╨╛╤В╨╕╨▓ 7**, ╨╕ ╤Н╤В╨╛ ╨▓╨╡╤А╨╜╨╛ ╨┤╨░╨╢╨╡ ╨║╨╛╨│╨┤╨░ watchdog ╨╜╨╡ ╨▓╨╖╨▓╤С╨╗╤Б╤П. ╨а╨░╨╖╨╝╨╡╨╜ ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╤Л╨╣: ╨╢╤С╤Б╤В╨║╨╛ ╤Г╨▒╨╕╤В╤Л╨╣ ╨╖╨░╤Е╨╛╨┤ ╨╖╨░╨┤╨╡╤А╨╢╨╕╨▓╨░╨╡╤В ╤Б╨╕╨╜╨║ ╨┤╨╛ ╨┐╨╛╨╗╤Г╤З╨░╤Б╨░, ╨╖╨░╤В╨╛ ╨┤╨▓╤Г╤Е ╤Н╨║╨╖╨╡╨╝╨┐╨╗╤П╤А╨╛╨▓ ╨╜╨░ ╨╛╨┤╨╜╨╛╨╣ MTProto-╤Б╨╡╤Б╤Б╨╕╨╕ (`AUTH_RESTART` ╨╜╨░ ╨╢╨╕╨▓╨╛╨╝ ╨░╨║╨║╨░╤Г╨╜╤В╨╡ ╨┐╨╛╨┤╨┤╨╡╤А╨╢╨║╨╕) ╨╜╨╡ ╨▒╤Л╨▓╨░╨╡╤В ╨╜╨╕╨║╨╛╨│╨┤╨░. ╨з╨╕╤Б╨╗╨╛ ╨╢╨╕╨▓╤С╤В ╨▓ [`scripts/server_guards.conf`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards.conf), [`config/schedule_guard.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/schedule_guard.php) тАФ ╨║╨╛╨┐╨╕╤П ╨┤╨╗╤П ╤А╨░╤Б╤З╤С╤В╨░ ╨▒╨╡╨╖ ╨┤╨╕╤Б╨║╨╛╨▓╨╛╨│╨╛ ╤А╨░╨╖╨▒╨╛╤А╨░ ╨║╨░╨╢╨┤╤Г╤О ╨╝╨╕╨╜╤Г╤В╤Г, ╤А╨░╤Б╤Е╨╛╨╢╨┤╨╡╨╜╨╕╨╡ ╨╗╨╛╨▓╨╕╤В ╤В╨╡╤Б╤В. ╨Э╨╡╨▓╨╖╨▓╨╡╨┤╤С╨╜╨╜╤Л╨╣ watchdog ╤В╨╡╨┐╨╡╤А╤М ╨╗╨╛╨│╨╕╤А╤Г╨╡╤В╤Б╤П ╨║╨░╨║ `error`, ╨░ ╨╜╨╡ ╨╝╨╛╨╗╤З╨╕╤В ╨▓╨╜╨╡ verbose. Executor: Opus 5 1M (`claude-opus-5[1m]`).

### Changed
- **Ruling: `->runInBackground()` ╨Э╨Х ╨┐╤А╨╕╨╝╨╡╨╜╤П╨╡╤В╤Б╤П ╨║ ╨║╨╛╨╝╨░╨╜╨┤╨░╨╝ ╨╛╨▒╤Й╨╡╨╣ MTProto-╤Б╨╡╤Б╤Б╨╕╨╕ (H1915).** ╨Ф╨╗╤П `telegram-support:sync` ╨╕ `telegram-harvest:roster-groups` ╤Д╨╛╨╜╨╛╨▓╤Л╨╣ ╨╖╨░╨┐╤Г╤Б╨║ ╨╛╤В╨║╨╗╨╛╨╜╤С╨╜: (1) ╨╖╨░╨╝╨╛╨║ `withoutOverlapping` ╤Б╨╜╨╕╨╝╨░╨╡╤В╤Б╤П ╤З╨╡╤А╨╡╨╖ `schedule:finish`, ╨┤╨╛ ╨║╨╛╤В╨╛╤А╨╛╨│╨╛ ╤Г╨▒╨╕╤В╤Л╨╣ ╤Д╨╛╨╜╨╛╨▓╤Л╨╣ ╨╖╨░╤Е╨╛╨┤ ╨╜╨╡ ╨┤╨╛╨╢╨╕╨▓╤С╤В тАФ ╨░ ╤Г╨▒╨╕╨╣╤Б╤В╨▓╨╛ ╤В╨╡╨┐╨╡╤А╤М ╨и╨в╨Р╨в╨Э╨л╨Щ ╨┐╤Г╤В╤М ╨╖╨░╨▓╨╡╤А╤И╨╡╨╜╨╕╤П, ╨╕ ╨╖╨░╨╝╨╛╨║ ╨┐╤А╨╛╨▓╨╕╤Б╨╡╨╗ ╨▒╤Л ╨▓╨╡╤Б╤М (╨╜╨╛╨▓╤Л╨╣, 35-╨╝╨╕╨╜╤Г╤В╨╜╤Л╨╣) TTL; (2) ╤Д╨╛╨╜╨╛╨▓╤Л╨╡ ╨┤╨╡╤В╨╕ ╨┐╨╡╤А╨╡╨╢╨╕╨▓╨░╤О╤В ╤А╨╛╨┤╨╕╤В╨╡╨╗╤П, ╨╕ ╨│╨░╤А╨░╨╜╤В╨╕╤П `flock -n` ┬л╨╜╨╡ ╨▒╨╛╨╗╨╡╨╡ ╨╛╨┤╨╜╨╛╨│╨╛ `schedule:run`┬╗ ╨┐╨╡╤А╨╡╤Б╤В╨░╤С╤В ╤З╤В╨╛-╨╗╨╕╨▒╨╛ ╨╖╨╜╨░╤З╨╕╤В╤М ╨╕╨╝╨╡╨╜╨╜╨╛ ╨┤╨╗╤П ╨┤╨╛╨╗╨│╨╕╤Е ╨║╨╛╨╝╨░╨╜╨┤; (3) ╨▓╤Л╨│╨╛╨┤╨░ ╤Г╨╢╨╡ ╨┐╨╛╨╗╤Г╤З╨╡╨╜╨░ ╨▒╨╡╨╖ ╤А╨╕╤Б╨║╨░ тАФ H1914 ╨╜╨╡ ╨┤╨░╤С╤В ╨╖╨░╤Е╨╛╨┤╤Г ╨╢╨╕╤В╤М ╨┤╨╛╨╗╤М╤И╨╡ 900 ╤Б ╨╕ ╨┐╤А╨╡╨▓╤А╨░╤Й╨░╨╡╤В ╨╝╨╡╨┤╨╗╨╡╨╜╨╜╤Л╨╣ ╨┐╤А╨╛╤Е╨╛╨┤ ╨▓ ╨┐╤А╨╛╨┐╤Г╤Б╨║ ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╡╨╣ ╨╝╨╕╨╜╤Г╤В╤Л, ╨░ ╨╜╨╡ ╨▓ ╨╜╨░╨║╨╛╨┐╨╗╨╡╨╜╨╕╨╡ ╤Ж╨╡╨┐╨╛╤З╨╡╨║. ╨Я╤А╨╛╤З╨╕╨╡ ╨┤╨╛╨╗╨│╨╕╨╡ ╨║╨╛╨╝╨░╨╜╨┤╤Л (`mail:scan-bounces`, `backup:run`, `avatars:sync`) ╨┐╨╛╨┤ ╤Н╤В╨╛╤В ruling ╨╜╨╡ ╨┐╨╛╨┤╨┐╨░╨┤╨░╤О╤В тАФ ╨╛╨╜╨╕ ╨▓ H1916; `mail:scan-bounces` ╨▓╤Б╤С ╤А╨░╨▓╨╜╨╛ ╨╗╨╡╤З╨╕╤В╤Б╤П ╤В╨░╨╣╨╝╨░╤Г╤В╨╛╨╝ ╨▓╨╜╤Г╤В╤А╨╕ ╨║╨╛╨╝╨░╨╜╨┤╤Л, ╨░ ╨╜╨╡ ╤Д╨╛╨╜╨╛╨╝. ╨а╨░╨╖╨▒╨╛╤А тАФ [`docs/server-resource-guards.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md) ┬з2.2тАУ2.4. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.76.3] - 2026-07-30

### Fixed
- **`worktree_bootstrap.ps1` ╨╜╨╡ ╨┐╨░╤А╤Б╨╕╨╗╤Б╤П ╤Ж╨╡╨╗╨╕╨║╨╛╨╝ тАФ ╨╛╨▒╤А╨░╤В╨╜╨░╤П ╨║╨░╨▓╤Л╤З╨║╨░ ╤Б╤К╨╡╨╗╨░ ╨╖╨░╨║╤А╤Л╨▓╨░╤О╤Й╤Г╤О (H1929, ╨┤╨╛╨│╨╛╨╜ 3).** ╨Т [1.76.2](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.76.2) ╨┐╤А╨╡╨┤╤Г╨┐╤А╨╡╨╢╨┤╨╡╨╜╨╕╨╡ ╨╖╨░╨║╨░╨╜╤З╨╕╨▓╨░╨╗╨╛╤Б╤М ╨╜╨░ `` `Remove-Item тАж $here` ``: ╨▓╨╜╤Г╤В╤А╨╕ ╨┤╨▓╨╛╨╣╨╜╤Л╤Е ╨║╨░╨▓╤Л╤З╨╡╨║ PowerShell ╤В╤А╨░╨║╤В╤Г╨╡╤В `` ` `` ╨║╨░╨║ escape, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨╖╨░╨▓╨╡╤А╤И╨░╤О╤Й╨░╤П ╨║╨░╨▓╤Л╤З╨║╨░ ╤Н╨║╤А╨░╨╜╨╕╤А╨╛╨▓╨░╨╗╨░ ╨╖╨░╨║╤А╤Л╨▓╨░╤О╤Й╤Г╤О `"` тАФ ╤Б╤В╤А╨╛╨║╨░ ╨╜╨╡ ╨╖╨░╨║╤А╤Л╨▓╨░╨╗╨░╤Б╤М, ╨╕ **╨▓╨╡╤Б╤М ╤Д╨░╨╣╨╗** ╨┐╨░╨┤╨░╨╗ ╤Б `ParserError` ╨╜╨░ ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╡╨╣ ╨╢╨╡ ╤Б╤В╤А╨╛╨║╨╡. ╨в╨╛ ╨╡╤Б╤В╤М ╤А╨╡╨╗╨╕╨╖ 1.76.2 ╤Б╨╛╨┤╨╡╤А╨╢╨░╨╗ ╨╜╨╡╤А╨░╨▒╨╛╤З╨╕╨╣ ╤Б╨║╤А╨╕╨┐╤В. ╨Я╨╛╨╣╨╝╨░╨╜╨╛ ╨╜╨░ ╨┐╨╡╤А╨▓╨╛╨╝ ╨╖╨░╨┐╤Г╤Б╨║╨╡ ╨┐╨╛╤Б╨╗╨╡ ╤А╨╡╨╗╨╕╨╖╨░.
- **╨Ч╨░╨▓╨╡╨┤╤С╨╜ ╨│╨╡╨╣╤В, ╨╕╨╖-╨╖╨░ ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╤П ╨║╨╛╤В╨╛╤А╨╛╨│╨╛ ╤Н╤В╨╛ ╨╕ ╤Г╨╡╤Е╨░╨╗╨╛: [`powershell-syntax.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/powershell-syntax.yml)** тАФ ╨┐╨░╤А╤Б╨╕╤В ╨║╨░╨╢╨┤╤Л╨╣ `.ps1` ╨▓ ╤А╨╡╨┐╨╛╨╖╨╕╤В╨╛╤А╨╕╨╕ (`Parser::ParseFile`, ╨▒╨╡╨╖ ╨▓╤Л╨┐╨╛╨╗╨╜╨╡╨╜╨╕╤П) ╨╜╨░ push ╨╕ PR, ╨╖╨░╤В╤А╨░╨│╨╕╨▓╨░╤О╤Й╨╕╤Е `.ps1`. ╨б╨╡╨║╤Г╨╜╨┤╤Л ╨╜╨░ ╨┐╤А╨╛╨│╨╛╨╜. PHP-╨╜╨░╨▒╨╛╤А ╨┐╤А╨╛ `.ps1` ╨╜╨╕╤З╨╡╨│╨╛ ╨╜╨╡ ╨╖╨╜╨░╨╗, ╨░ ╤Б╨░╨╝ ╤Б╨║╤А╨╕╨┐╤В ╨┤╨╛ ╨╝╨╡╤А╨╢╨░ ╨╜╨╡ ╨╖╨░╨┐╤Г╤Б╨║╨░╨╗╤Б╤П тАФ ╤Б╨╕╨╜╤В╨░╨║╤Б╨╕╤З╨╡╤Б╨║╤Г╤О ╨┐╨╛╨╗╨╛╨╝╨║╤Г ╨╗╨╛╨▓╨╕╤В╤М ╨▒╤Л╨╗╨╛ ╨╜╨╡╤З╨╡╨╝. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.76.2] - 2026-07-30

### Fixed
- **`-Teardown` ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨╛╨▒╤К╤П╨▓╨╗╤П╨╡╤В ╨╜╨╡╤Г╨┤╨░╤З╨╡╨╣ ╨г╨б╨Я╨Х╨и╨Э╨л╨Щ ╤Б╨╜╨╛╤Б worktree (H1929, ╨┤╨╛╨│╨╛╨╜ 2).** ╨Ю╨▒╨░ ╨╢╨╕╨▓╤Л╤Е ╨┐╤А╨╛╨│╨╛╨╜╨░ 30-07-2026 ╨▓╤Л╨│╨╗╤П╨┤╨╡╨╗╨╕ ╨╛╨┤╨╕╨╜╨░╨║╨╛╨▓╨╛: git ╤Б╨╜╨╕╨╝╨░╨╗ ╤А╨╡╨│╨╕╤Б╤В╤А╨░╤Ж╨╕╤О, ╤Д╨░╨╣╨╗╤Л ╤Г╨┤╨░╨╗╤П╨╗╨╕╤Б╤М, ╨░ ╤Б╨░╨╝╨░ ╨┐╨░╨┐╨║╨░ ╨┤╨╡╤А╨╢╨░╨╗╨░╤Б╤М Windows ╨╡╤Й╤С ╤Б╨╡╨║╤Г╨╜╨┤╤Г тАФ ╨╕ ╤Б╨║╤А╨╕╨┐╤В ╨┐╨╡╤З╨░╤В╨░╨╗ ┬л╨║╨░╨║╨╛╨╣-╤В╨╛ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б ╨┤╨╡╤А╨╢╨╕╤В ╤Д╨░╨╣╨╗╤Л┬╗, ╤Е╨╛╤В╤П ╤В╨╡╤А╤П╤В╤М ╨▒╤Л╨╗╨╛ ╤Г╨╢╨╡ ╨╜╨╡╤З╨╡╨│╨╛ (╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╕╨╣ ╨╢╨╡ `Remove-Item` ╨╡╤С ╤Б╨╜╨╛╤Б╨╕╨╗). ╨в╨╡╨┐╨╡╤А╤М ╨┐╨╛╨▓╤В╨╛╤А╨╛╨▓ ╨┐╤П╤В╤М ╤Б ╤А╨░╤Б╤В╤Г╤Й╨╡╨╣ ╨┐╨░╤Г╨╖╨╛╨╣ (0.3 тЖТ 4.8 ╤Б ╨▓╨╝╨╡╤Б╤В╨╛ ╤В╤А╤С╤Е ╨┐╨╛ 0.7), ╨░ ╨│╨╗╨░╨▓╨╜╨╛╨╡ тАФ **╨┐╤Г╤Б╤В╨╛╨╣ ╨╜╨╡╨╖╨░╤А╨╡╨│╨╕╤Б╤В╤А╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╨╣ ╨║╨░╤В╨░╨╗╨╛╨│ ╤Б╤З╨╕╤В╨░╨╡╤В╤Б╤П ╤Г╤Б╨┐╨╡╤Е╨╛╨╝**: ╤Б╨║╤А╨╕╨┐╤В ╨│╨╛╨▓╨╛╤А╨╕╤В, ╤З╤В╨╛ ╤А╨░╨▒╨╛╤В╤Л ╨▓ ╨╜╤С╨╝ ╨╜╨╡╤В ╨╕ git ╨╛ ╨╜╤С╨╝ ╨╜╨╡ ╨╖╨╜╨░╨╡╤В, ╨╕ ╨▓╤Л╤Е╨╛╨┤╨╕╤В ╤Б ╨╜╤Г╨╗╤С╨╝. ╨Э╨░╤Б╤В╨╛╤П╤Й╨░╤П ╨╜╨╡╤Г╨┤╨░╤З╨░ (╨▓ ╨║╨░╤В╨░╨╗╨╛╨│╨╡ ╨Ю╨б╨в╨Р╨Ы╨Ш╨б╨м ╤Д╨░╨╣╨╗╤Л) ╨┐╨╛-╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г ╨┐╨░╨┤╨░╨╡╤В ╨╕ ╨╜╨░╨╖╤Л╨▓╨░╨╡╤В ╨╕╤Е ╨║╨╛╨╗╨╕╤З╨╡╤Б╤В╨▓╨╛. ╨Ы╨╛╨╢╨╜╨░╤П ╤В╤А╨╡╨▓╨╛╨│╨░ ╨╖╨┤╨╡╤Б╤М ╨┤╨╛╤А╨╛╨╢╨╡ ╨╜╨░╤Б╤В╨╛╤П╤Й╨╡╨╣: ╨╛╨╜╨░ ╤Г╤З╨╕╤В ╨╜╨╡ ╨▓╨╡╤А╨╕╤В╤М ╨╛╤В╤З╤С╤В╤Г ╨╕╨╜╤Б╤В╤А╤Г╨╝╨╡╨╜╤В╨░. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.76.1] - 2026-07-30

### Fixed
- **`worktree_bootstrap.ps1 -Teardown` ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨▓╤А╤С╤В ╨┐╤А╨╛ ┬л╨╖╨░╨║╤А╨╛╨╣╤В╨╡ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╤Л┬╗, ╨║╨╛╨│╨┤╨░ ╨┤╨╡╤А╨╢╨╕╤В ╨║╨░╤В╨░╨╗╨╛╨│ ╤Б╨░╨╝ ╨▓╤Л╨╖╤Л╨▓╨░╤О╤Й╨╕╨╣ (H1929, ╨┤╨╛╨│╨╛╨╜).** ╨Я╨╛╨╣╨╝╨░╨╜╨╛ ╨╜╨░ ╨┐╨╡╤А╨▓╨╛╨╝ ╨╢╨╡ ╨╢╨╕╨▓╨╛╨╝ ╤Б╨╜╨╛╤Б╨╡: ╤Б╨╜╨╛╤Б ╨╖╨░╨┐╤Г╤Б╨║╨░╨╗╤Б╤П ╨╕╨╖ ╤И╨╡╨╗╨╗╨░, ╤З╨╡╨╣ ╤В╨╡╨║╤Г╤Й╨╕╨╣ ╨║╨░╤В╨░╨╗╨╛╨│ ╨╜╨░╤Е╨╛╨┤╨╕╨╗╤Б╤П ╨Т╨Э╨г╨в╨а╨Ш ╤Б╨╜╨╛╤Б╨╕╨╝╨╛╨│╨╛ worktree, тАФ Windows ╨╜╨╡ ╤Г╨┤╨░╨╗╤П╨╡╤В ╨║╨░╤В╨░╨╗╨╛╨│, ╨┐╨╛╨║╨░ ╨╛╨╜ ╤В╨╡╨║╤Г╤Й╨╕╨╣ ╨┤╨╗╤П ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨░, ╨╕ ╤Б╨║╤А╨╕╨┐╤В ╤З╨╡╤Б╤В╨╜╨╛ ╨┐╨░╨┤╨░╨╗, ╨╜╨╛ ╤Б ╨▒╨╡╤Б╨┐╨╛╨╗╨╡╨╖╨╜╤Л╨╝ ╤Б╨╛╨▓╨╡╤В╨╛╨╝. ╨в╨╡╨┐╨╡╤А╤М ╨┐╤А╨╛╨▓╨╡╤А╨║╨░ `cwd` ╨╕╨┤╤С╤В ╨┐╨╡╤А╨▓╨╛╨╣ ╨╕ ╨┐╨╡╤З╨░╤В╨░╨╡╤В ╤В╨╛╤З╨╜╤Г╤О ╨┐╤А╨╕╤З╨╕╨╜╤Г ╨▓╨╝╨╡╤Б╤В╨╡ ╤Б ╨│╨╛╤В╨╛╨▓╨╛╨╣ ╨║╨╛╨╝╨░╨╜╨┤╨╛╨╣ ╨╕╨╖ ╨│╨╗╨░╨▓╨╜╨╛╨│╨╛ ╨┤╨╡╤А╨╡╨▓╨░. ╨Ч╨░╨╛╨┤╨╜╨╛ ╤Г╨┤╨░╨╗╨╡╨╜╨╕╨╡ ╨┐╨╛╨▓╤В╨╛╤А╤П╨╡╤В╤Б╤П ╤В╤А╨╕╨╢╨┤╤Л ╤Б ╨┐╨░╤Г╨╖╨╛╨╣ (╤Е╤Н╨╜╨┤╨╗ ╨┐╨╛╨┤ worktree ╤З╨░╤Б╤В╨╛ ╨╛╤В╨┐╤Г╤Б╨║╨░╨╡╤В╤Б╤П ╤З╨╡╤А╨╡╨╖ ╨╝╨│╨╜╨╛╨▓╨╡╨╜╨╕╨╡ ╨┐╨╛╤Б╨╗╨╡ ╤В╨╛╨│╨╛, ╨║╨░╨║ git ╨╖╨░╨║╨╛╨╜╤З╨╕╨╗ тАФ ╨▓ ╤В╨╛╨╝ ╤Б╨░╨╝╨╛╨╝ ╨┐╤А╨╛╨│╨╛╨╜╨╡ ╨║╨░╤В╨░╨╗╨╛╨│ ╨╕╤Б╤З╨╡╨╖ ╤Б╨░╨╝ ╤З╨╡╤А╨╡╨╖ ╤Б╨╡╨║╤Г╨╜╨┤╤Г ╨┐╨╛╤Б╨╗╨╡ ┬л╨╜╨╡╤Г╨┤╨░╤З╨╕┬╗), ╨░ ╤Д╨╕╨╜╨░╨╗╤М╨╜╨╛╨╡ ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡ ╨╛╨▒╤К╤П╤Б╨╜╤П╨╡╤В, ╤З╤В╨╛ ╤А╨╡╨│╨╕╤Б╤В╤А╨░╤Ж╨╕╤П ╨▓ git ╨г╨Ц╨Х ╤Б╨╜╤П╤В╨░ ╨╕ ╨┤╨╛╨▒╨╕╤В╤М ╨╛╤Б╤В╨░╤С╤В╤Б╤П ╨╛╨┤╨╜╨╕╨╝ `Remove-Item`. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.76.0] - 2026-07-30

### Changed
- **╨в╤А╨░╨╜╨╖╨░╨║╤Ж╨╕╨╛╨╜╨╜╤Л╨╡ ╨┐╨╕╤Б╤М╨╝╨░ ╨┐╤А╨╕╨▓╨╡╨┤╨╡╨╜╤Л ╨║ ╨╛╨┤╨╜╨╛╨╝╤Г ╨│╨╛╨╗╨╛╤Б╤Г тАФ ╤А╨╡╨│╨╕╤Б╤В╤А╨╛╨▓╤Л╨╣ ╨┐╤А╨╛╤Е╨╛╨┤ ╨┐╨╛ ╨▓╤Б╨╡╨╝ 29 ╤И╨░╨▒╨╗╨╛╨╜╨░╨╝ (H1865).** ╨Я╨╕╤Б╤М╨╝╨░ ╨┐╨╕╤Б╨░╨╗╨╕╤Б╤М ╨╕╨╜╨║╤А╨╡╨╝╨╡╨╜╤В╨░╨╗╤М╨╜╨╛ ╨╕ ╤А╨░╨╖╨│╨╛╨▓╨░╤А╨╕╨▓╨░╨╗╨╕ ╨▓ ╨╜╨╡╤Б╨║╨╛╨╗╤М╨║╨╛ ╨│╨╛╨╗╨╛╤Б╨╛╨▓: ╤В╤А╨╕ ╤А╨░╨╖╨╜╤Л╤Е ╨┐╤А╨╕╨▓╨╡╤В╤Б╤В╨▓╨╕╤П (┬л╨Э╨░╨╝╨░╤Б╤В╨╡тАж ЁЯЩП┬╗, ┬л╨Ч╨┤╤А╨░╨▓╤Б╤В╨▓╤Г╨╣╤В╨╡┬╗, ┬л╨Э╨░╨╝╨░╤Б╤В╨╡┬╗ ╨▒╨╡╨╖ ╤Н╨╝╨╛╨┤╨╖╨╕), ╤В╤А╨╕ ╨┐╨╛╨┤╨┐╨╕╤Б╨╕ (┬л╨б ╤Г╨▓╨░╨╢╨╡╨╜╨╕╨╡╨╝, ╨Ъ╨╛╨╝╨░╨╜╨┤╨░тАж┬╗, ┬л╨б ╤Г╨▓╨░╨╢╨╡╨╜╨╕╨╡╨╝, тАж┬╗, ╨┐╤А╨╛╤Б╤В╨╛ ╨▒╤А╨╡╨╜╨┤), ╨┤╨▓╨░ ╤Д╨╛╨╗╨▒╤Н╨║╨░ ╨╕╨╝╨╡╨╜╨╕ (┬л╨б╤В╤Г╨┤╨╡╨╜╤В┬╗/┬л╨┤╤А╤Г╨│┬╗), ╤В╤А╨╕ ╨╕╨╝╨╡╨╜╨╕ ╨╛╤В╨┐╤А╨░╨▓╨╕╤В╨╡╨╗╤П ╨▓ ╨║╨╛╨┐╨╕╨╕ (┬л╨Я╨╗╨░╤В╤Д╨╛╤А╨╝╨░ ╨Ю╨▒╤Г╤З╨╡╨╜╨╕╤П┬╗, ┬лSystema Sanscriticum┬╗, ┬л╨Ю╨▒╤Й╨╡╤Б╤В╨▓╨╛ ╤А╨╡╨▓╨╜╨╕╤В╨╡╨╗╨╡╨╣ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨░┬╗). ╨н╤В╨░╨╗╨╛╨╜╨╛╨╝ ╨▓╨╖╤П╤В ╨│╨╛╨╗╨╛╤Б ╨╜╨╛╨▓╨╡╨╣╤И╨╡╨│╨╛ ╨░╨▓╤В╨╛╤А╤Б╨║╨╛╨│╨╛ ╤Б╨╗╨╛╤П (╨╝╨░╤А╨░╤Д╨╛╨╜/╨╛╨╜╨▒╨╛╤А╨┤╨╕╨╜╨│/purchase-confirmation, H1289-╤Н╨┐╨╛╤Е╨░): ╨┐╤А╨╕╨▓╨╡╤В╤Б╤В╨▓╨╕╨╡ ┬л╨Э╨░╨╝╨░╤Б╤В╨╡, {╨╕╨╝╤П ?? '╨┤╤А╤Г╨│'}!┬╗ ╨▒╨╡╨╖ ╤Н╨╝╨╛╨┤╨╖╨╕ (╨▒╨╡╨╖ ╨╕╨╝╨╡╨╜╨╕ тАФ ┬л╨Ч╨┤╤А╨░╨▓╤Б╤В╨▓╤Г╨╣╤В╨╡!┬╗), ╨┐╨╛╨┤╨┐╨╕╤Б╤М ╨┐╤А╨╛╤Б╤В╨╛ ┬л╨Ю╨▒╤Й╨╡╤Б╤В╨▓╨╛ ╤А╨╡╨▓╨╜╨╕╤В╨╡╨╗╨╡╨╣ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨░┬╗, ╨▒╤А╨╡╨╜╨┤ ╨╜╨░╨╖╨▓╨░╨╜ ╨╛╨┤╨╕╨╜╨░╨║╨╛╨▓╨╛ ╨▓╨╡╨╖╨┤╨╡, ┬л╤С┬╗ тЖТ ┬л╨╡┬╗ (╨║╤А╨╛╨╝╨╡ ╤А╨░╨╖╨╗╨╕╤З╨╡╨╜╨╕╤П ╨▓╤Б╤С/╨▓╤Б╨╡ тАФ ┬л╨Ц╨┤╤С╤В ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕┬╗ ╨╕ ╨┤╤А.). ╨Я╨╡╤А╨╡╨┐╨╕╤Б╨░╨╜ ╨┐╨░╤А╨░╨┤╨╜╤Л╨╣ ╨║╨░╨╜╤Ж╨╡╨╗╤П╤А╨╕╤В [student/welcome](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/student/welcome.blade.php) (┬л╨Т╨░╤И ╨┐╤Г╤В╤М ╨║ ╨╕╨╖╤Г╤З╨╡╨╜╨╕╤О ╤Б╨▓╤П╤Й╨╡╨╜╨╜╨╛╨│╨╛ ╤П╨╖╤Л╨║╨░ ╨╜╨░╤З╨░╨╗╤Б╤П┬╗, CTA ┬л╨Я╨╡╤А╨╡╨╣╤В╨╕ ╨║ ╨╖╨╜╨░╨╜╨╕╤П╨╝┬╗ тЖТ ┬л╨Т ╨╗╨╕╤З╨╜╤Л╨╣ ╨║╨░╨▒╨╕╨╜╨╡╤В┬╗), ╨▓ [announcement](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/announcement.blade.php) ╤Г╨▒╤А╨░╨╜ ╨╖╨░╨│╨╛╨╗╨╛╨▓╨╛╨║-╨┐╨╗╨╡╨╣╤Б╤Е╨╛╨╗╨┤╨╡╤А ┬л╨Я╨╗╨░╤В╤Д╨╛╤А╨╝╨░ ╨Ю╨▒╤Г╤З╨╡╨╜╨╕╤П┬╗, ╨▓ PayPal-╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╕╨╕ ╤Б╨▓╨╡╨┤╨╡╨╜╨░ ╨╖╨░╨┤╨▓╨╛╨╡╨╜╨╜╨░╤П ╤Б╤В╤А╨╛╨║╨░ ╨┐╨╛╨┤╨┤╨╡╤А╨╢╨║╨╕. ╨Я╨╡╤А╨╡╨╝╨╡╨╜╨╜╤Л╨╡ ╨╕ ╤Г╤Б╨╗╨╛╨▓╨╕╤П ╤И╨░╨▒╨╗╨╛╨╜╨╛╨▓ ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В╤Л тАФ ╨╝╨╡╤Е╨░╨╜╨╕╤З╨╡╤Б╨║╨╕╨╣ ╨┤╨╕╤Д╤Д echo/╨┤╨╕╤А╨╡╨║╤В╨╕╨▓ ╨┤╨╛/╨┐╨╛╤Б╨╗╨╡ ╨┐╨╛╨┤╤В╨▓╨╡╤А╨┤╨╕╨╗ ╤В╨╛╨╢╨┤╨╡╤Б╤В╨▓╨╛ (╨╝╨╡╨╜╤П╤О╤В╤Б╤П ╤В╨╛╨╗╤М╨║╨╛ ╤Б╤В╤А╨╛╨║╨╛╨▓╤Л╨╡ ╨╗╨╕╤В╨╡╤А╨░╨╗╤Л ╤Д╨╛╨╗╨▒╤Н╨║╨╛╨▓), 57 ╨┐╨╛╤З╤В╨╛╨▓╤Л╤Е ╤В╨╡╤Б╤В╨╛╨▓ ╨╖╨╡╨╗╤С╨╜╤Л╨╡, ╨▓╤Б╨╡ Blade-╤И╨░╨▒╨╗╨╛╨╜╤Л ╨║╨╛╨╝╨┐╨╕╨╗╨╕╤А╤Г╤О╤В╤Б╤П. 12 ╨╕╨╖ 29 ╤И╨░╨▒╨╗╨╛╨╜╨╛╨▓ ╤Г╨╢╨╡ ╤Б╨╛╨╛╤В╨▓╨╡╤В╤Б╤В╨▓╨╛╨▓╨░╨╗╨╕ ╤Н╤В╨░╨╗╨╛╨╜╤Г ╨╕ ╨╜╨╡ ╨╕╨╖╨╝╨╡╨╜╨╡╨╜╤Л. Executor: Fable 5 (`claude-fable-5`).

## [1.75.0] - 2026-07-30

### Added
- **FAQ-╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А ╨┤╨╛╤И╤С╨╗ ╨┤╨╛ ╤А╤Г╨║╨╛╨▓╨╛╨┤╤Б╤В╨▓╨░ ╨║╤Г╤А╨░╤В╨╛╤А╨░ тАФ ┬з1.6 ╨▓ admin-manual (H1930).** ╨д╨╕╤З╨░ ╨╢╨╕╨╗╨░ ╤В╨╛╨╗╤М╨║╨╛ ╨▓ ╤А╨╛╨░╨┤╨╝╨░╨┐╨╡ ([ROADMAP_SUPPORT_AUTOMATION_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md)) ╨╕ ╤В╨╡╤Е╨╜╨╕╤З╨╡╤Б╨║╨╛╨╣ ╨║╨░╤А╤В╨╡ ╨┐╨╛╨┤╤Б╨╕╤Б╤В╨╡╨╝╤Л тАФ ╨║╤Г╤А╨░╤В╨╛╤А-╨╛╤А╨╕╨╡╨╜╤В╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛╨│╨╛ ╤А╤Г╤Б╤Б╨║╨╛╨│╨╛ ╨╛╨┐╨╕╤Б╨░╨╜╨╕╤П ╨╜╨╡ ╨▒╤Л╨╗╨╛ ╨╜╨╕╨│╨┤╨╡. ╨Э╨╛╨▓╤Л╨╣ [┬з1.6 ┬лFAQ-╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А ╨╛╤В╨▓╨╡╤В╨╛╨▓┬╗](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/admin-manual.md) ╨╛╨▒╤К╤П╤Б╨╜╤П╨╡╤В ╤Б ╨┐╨╛╨╖╨╕╤Ж╨╕╨╕ ╨║╤Г╤А╨░╤В╨╛╤А╨░: ╨│╨┤╨╡ ╨┐╨╛╤П╨▓╨╗╤П╤О╤В╤Б╤П ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║╨╕ (╨╕╨╜╨╗╨░╨╣╨╜ ╨▓ Helpdesk, ╨║╨╜╨╛╨┐╨║╨╕ ╨Я╤А╨╕╨╜╤П╤В╤М/╨Ш╨╖╨╝╨╡╨╜╨╕╤В╤М/╨Ю╤В╨║╨╗╨╛╨╜╨╕╤В╤М), ╤В╤А╨╕ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨░ ╤В╨╡╨║╤Б╤В╨░ ╨┐╨╛ ╨║╨░╤В╨╡╨│╨╛╤А╨╕╤П╨╝ (A/B/C тАФ ╤Д╨░╨║╤В╤Л LMS ╨▒╨╡╨╖ ╨Ш╨Ш; D/E/F ╤Б ╨┐╤А╨╕╨▓╤П╨╖╨░╨╜╨╜╤Л╨╝ ╤И╨░╨▒╨╗╨╛╨╜╨╛╨╝ тАФ ┬л╨и╨░╨▒╨╗╨╛╨╜╤Л ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╣┬╗, H1838; D/E/F ╨▒╨╡╨╖ ╤И╨░╨▒╨╗╨╛╨╜╨░ тАФ ╨▓╨╜╨╡╤И╨╜╨╕╨╣ ╨Ш╨Ш ╨╖╨░ ╤Д╨╗╨░╨│╨╛╨╝ ╨╕ ╨╗╨╕╨╝╨╕╤В╨╛╨╝), ╨║╨░╨║ ╨┐╨╛╨╝╨╡╨╜╤П╤В╤М ╤В╨╡╨║╤Б╤В ╨│╨╛╤В╨╛╨▓╤Л╤Е ╨╛╤В╨▓╨╡╤В╨╛╨▓ (╨┐╤А╨░╨▓╨╕╤В╤М ╤И╨░╨▒╨╗╨╛╨╜), ╨┐╨╛╨╗╨╜╤Л╨╣ ╤Б╨┐╨╕╤Б╨╛╨║ ╤В╤Г╨╝╨▒╨╗╨╡╤А╨╛╨▓ ╨╕ **╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛╨╡ ╨┐╤А╨╛╨┤-╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╡ ╨╜╨░ 30-07-2026**: ╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А ╨╕ ╤И╨░╨▒╨╗╨╛╨╜╨╜╤Л╨╡ ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║╨╕ ╨Т╨Ъ╨Ы, ╤И╨░╨▒╨╗╨╛╨╜╤Л D/E/F #1тАУ#3 ╨┐╤А╨╕╨▓╤П╨╖╨░╨╜╤Л, ╨Ш╨Ш-╨┐╤Г╤В╤М ╨Т╨л╨Ъ╨Ы (╨╜╨╛╨╗╤М ╤А╨░╤Б╤Е╨╛╨┤╨╛╨▓ ╨╜╨░ LLM). ╨Ь╨╡╤В╨░╨┤╨╛╨║ admin-manual: ╨▒╤Н╨║╨╗╨╛╨│-╨┐╤Г╨╜╨║╤В 5 (╨┐╤А╨╛╨┤-╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╡ ╤Д╨╗╨░╨│╨╛╨▓) ╤З╨░╤Б╤В╨╕╤З╨╜╨╛ ╨╖╨░╨║╤А╤Л╤В. Executor: Fable 5 (`claude-fable-5`).
- **╨б╨▓╨╡╨╢╨╕╨╣ worktree ╨┐╨╛╨┤╨╜╨╕╨╝╨░╨╡╤В╤Б╤П ╨╖╨░ ╨╝╨╕╨╜╤Г╤В╤Г ╨▓╨╝╨╡╤Б╤В╨╛ ╨╛╨┤╨╕╨╜╨╜╨░╨┤╤Ж╨░╤В╨╕ тАФ [`scripts/worktree_bootstrap.ps1`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/worktree_bootstrap.ps1) (H1929).** ╨Ъ╨░╨╢╨┤╤Л╨╣ handoff ╨╛╨▒╤П╨╖╨░╨╜ ╨╕╨┤╤В╨╕ ╨▓ ╨╜╨╛╨▓╨╛╨╝ worktree (╤Н╤В╨╛╨│╨╛ ╤В╤А╨╡╨▒╤Г╨╡╤В ╨╖╨░╤Й╨╕╤В╨░ ╨│╨╗╨░╨▓╨╜╨╛╨│╨╛ ╨┤╨╡╤А╨╡╨▓╨░), ╨░ ╨▓ ╨╜╨╛╨▓╨╛╨╝ worktree ╨╜╨╡╤В ╨╜╨╕ `vendor/`, ╨╜╨╕ `.env` тАФ ╨╕ `composer install` ╤Б╤К╨╡╨┤╨░╨╗ **~11 ╨╝╨╕╨╜╤Г╤В** wall-clock, ╨╕╨╖╨╝╨╡╤А╨╡╨╜╨╜╤Л╤Е 30-07-2026 ╨╜╨░ ╨┐╤А╨░╨▓╨║╨╡, ╨║╨╛╤В╨╛╤А╨░╤П ╤Б╨░╨╝╨░ ╤Б╤В╨╛╨╕╨╗╨░ ╤Б╨╛╤А╨╛╨║╨░. Junction ╨╜╨░ ╤З╤Г╨╢╨╛╨╣ `vendor/` ╨┐╨╛-╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г **╨╖╨░╨┐╤А╨╡╤Й╤С╨╜ ╨╕ ╤Б╨╝╨╡╤А╤В╨╡╨╗╨╡╨╜** (PHP ╤А╨╡╨╖╨╛╨╗╨▓╨╕╤В `__DIR__` ╤З╨╡╤А╨╡╨╖ NTFS-junction ╨▓ ╤Д╨╕╨╖╨╕╤З╨╡╤Б╨║╤Г╤О ╤Ж╨╡╨╗╤М, ╨╕ worktree ╨╝╨╛╨╗╤З╨░ ╨╕╤Б╨┐╨╛╨╗╨╜╤П╨╡╤В ╨║╨╛╨┤ ╤З╤Г╨╢╨╛╨│╨╛ ╨┤╨╡╤А╨╡╨▓╨░ тАФ [#713](https://github.com/gasyoun/Systema-Sanscriticum/issues/713)), ╨╜╨╛ **╤Д╨╕╨╖╨╕╤З╨╡╤Б╨║╨░╤П ╨║╨╛╨┐╨╕╤П** ╤Н╤В╨╛╨╣ ╨▒╨╛╨╗╨╡╨╖╨╜╤М╤О ╨╜╨╡ ╤Б╤В╤А╨░╨┤╨░╨╡╤В: ╨┐╤Г╤В╨╕ ╨▓ `vendor/composer/autoload_*.php` ╤Б╤З╨╕╤В╨░╤О╤В╤Б╤П ╨╛╤В `__DIR__` ╨▓ ╨╝╨╛╨╝╨╡╨╜╤В ╨▓╤Л╨┐╨╛╨╗╨╜╨╡╨╜╨╕╤П. ╨б╨║╤А╨╕╨┐╤В ╨║╨╛╨┐╨╕╤А╤Г╨╡╤В `vendor/` ╤З╨╡╤А╨╡╨╖ robocopy (**60 ╤Б**, ╨╖╨░╨╝╨╡╤А╨╡╨╜╨╛) ╨╕ **╤В╨╛╨╗╤М╨║╨╛ ╨┐╤А╨╕ ╤Б╨╛╨▓╨┐╨░╨┤╨╡╨╜╨╕╨╕ `composer.lock`** ╤Б ╨│╨╗╨░╨▓╨╜╤Л╨╝ ╨┤╨╡╤А╨╡╨▓╨╛╨╝ тАФ ╨╕╨╜╨░╤З╨╡ ╤З╨╡╤Б╤В╨╜╨╛ ╨╖╨╛╨▓╤С╤В `composer install`, ╨┐╨╛╤В╨╛╨╝╤Г ╤З╤В╨╛ ╤А╨░╨╖╤К╨╡╤Е╨░╨▓╤И╨╕╨╣╤Б╤П ╨╜╨░╨▒╨╛╤А ╨┐╨░╨║╨╡╤В╨╛╨▓ ╤Н╤В╨╛ ╤А╨╛╨▓╨╜╨╛ ╤В╨╛╤В ╨║╨╗╨░╤Б╤Б ╤В╨╕╤Е╨╛╨╣ ╨╗╨╢╨╕, ╤А╨░╨┤╨╕ ╨║╨╛╤В╨╛╤А╨╛╨│╨╛ ╨╖╨░╨┐╤А╨╡╤В junction'╨╛╨▓ ╨╕ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╨╡╤В. ╨Ъ╨╛╨┐╨╕╤П ╨╜╨╡ ╨┐╤А╨╕╨╜╨╕╨╝╨░╨╡╤В╤Б╤П ╨╜╨░ ╨▓╨╡╤А╤Г: ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╣ ╨┐╤А╨╛╨▒╨╜╨╕╨║ ╤Б╨┐╤А╨░╤И╨╕╨▓╨░╨╡╤В ╤Г ╨░╨▓╤В╨╛╨╖╨░╨│╤А╤Г╨╖╤З╨╕╨║╨░ `realpath('app')` (╨▓ ╨╝╨░╤А╨║╨╡╤А╨░╤Е `<<<>>>`, ╨╕╨╜╨░╤З╨╡ ╨▒╨░╨╜╨╜╨╡╤А MadelineProto ╨╛ ╨┐╤А╨╛╨╕╨╖╨▓╨╛╨┤╨╕╤В╨╡╨╗╤М╨╜╨╛╤Б╤В╨╕ ╨╜╨░ Windows ╨┐╤А╨╕╨╡╨╖╨╢╨░╨╡╤В ╤Б╨║╨╗╨╡╨╡╨╜╨╜╤Л╨╝ ╤Б ╨┐╤Г╤В╤С╨╝ ╨╕ ╨┤╨░╤С╤В ╨╗╨╛╨╢╨╜╤Г╤О ╤В╤А╨╡╨▓╨╛╨│╤Г) ╨╕ ╨┐╨░╨┤╨░╨╡╤В, ╨╡╤Б╨╗╨╕ ╤В╨╛╤В ╤А╨╡╨╖╨╛╨╗╨▓╨╕╤В╤Б╤П ╨▓ ╤З╤Г╨╢╨╛╨╡ ╨┤╨╡╤А╨╡╨▓╨╛. ╨Ч╨░╨╛╨┤╨╜╨╛ ╤Б╤В╨░╨▓╨╕╤В╤Б╤П `.env` + `APP_KEY`, ╨░ `-Teardown` ╤Б╨╜╨╛╤Б╨╕╤В worktree ╨╕ **╨┤╨╛╨▓╨╛╨┤╨╕╤В ╤Г╨┤╨░╨╗╨╡╨╜╨╕╨╡ ╨┤╨╛ ╨║╨╛╨╜╤Ж╨░**, ╨║╨╛╨│╨┤╨░ Windows ╨┤╨╡╤А╨╢╨╕╤В ╤Е╤Н╨╜╨┤╨╗ ╨╕ `git worktree remove` ╨╛╤Б╤В╨░╨▓╨╗╤П╨╡╤В ╨╛╤Б╨╕╤А╨╛╤В╨╡╨▓╤И╨╕╨╣ ╨║╨░╤В╨░╨╗╨╛╨│ ╨▒╨╡╨╖ `.git`. ╨Т [`CLAUDE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md) ╨╖╨░╨║╤А╨╡╨┐╨╗╤С╨╜ ╨╕ ╤А╨╕╤В╨╝ ╤В╨╡╤Б╤В╨╛╨▓ (`--filter` ╨╜╨░ ╨╕╤В╨╡╤А╨░╤Ж╨╕╨╕, ╨┐╨╛╨╗╨╜╤Л╨╣ ╨╜╨░╨▒╨╛╤А ╨╛╨┤╨╕╨╜ ╤А╨░╨╖ ╨▓ ╨║╨╛╨╜╤Ж╨╡ ╨╕ ╨▓ ╤Д╨╛╨╜╨╡), ╨╕ ╨╗╨╛╨▓╤Г╤И╨║╨░ `preg_split('/\R/')` ╨▒╨╡╨╖ `/u`, ╤А╨╡╨╢╤Г╤Й╨░╤П ╤А╤Г╤Б╤Б╨║╨╕╨╣ ╤В╨╡╨║╤Б╤В ╨┐╨╛╤Б╤А╨╡╨┤╨╕ ╨▒╤Г╨║╨▓╤Л. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.74.0] - 2026-07-30

### Removed
- **╨Ю╨▒╨╡ ╨Р╤А╨╖╨░╨╝╨░╤Б-╨╖╨░╨╝╨╡╤В╨║╨╕ ╤Б╨╜╤П╤В╤Л ╤Б samskrte.ru тАФ ╤Б╨╡╤А╨╕╤П ╤Г╤Е╨╛╨┤╨╕╤В ╨╜╨░ ╤Б╨░╨╣╤В ╨Р╤А╨╖╨░╨╝╨░╤Б╨░ (H1928, ruling MG 30-07-2026).** ╨б ╨┐╤А╨╛╨┤╨░ ╤Г╨┤╨░╨╗╨╡╨╜╤Л ╨╛╨▒╨╡ ╤Б╤В╨░╤В╤М╨╕ (id 5 ┬л╨а╨╛╤Б╤Б╨╕╤П ╨╕ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╤Б╨║╨╕╨╣ ╤Б╨╗╨╛╨▓╨░╤А╤М┬╗ ╨╕ id 6 ┬л╨Я╨╡╤В╨╡╤А╨▒╤Г╤А╨│╤Б╨║╨╕╨╣ ╤Б╨╗╨╛╨▓╨░╤А╤М┬╗, ╨╛╨▒╨╡ ╤Б╤В╨╛╤П╨╗╨╕ ╨╛╨┐╤Г╨▒╨╗╨╕╨║╨╛╨▓╨░╨╜╨╜╤Л╨╝╨╕ ╤Б 27-07) ╨▓╨╝╨╡╤Б╤В╨╡ ╤Б ╨╛╨▒╨╗╨╛╨╢╨║╨░╨╝╨╕ тАФ URLs `/s/peterburgskiy-slovar-pwg` ╨╕ `/s/rossiya-i-sanskritskiy-slovar` ╨╛╤В╨▓╨╡╤З╨░╤О╤В 404. ╨Ш╨╖ ╤А╨╡╨┐╨╛╨╖╨╕╤В╨╛╤А╨╕╤П ╤Г╨┤╨░╨╗╨╡╨╜╤Л ╨╛╨▒╨╡ ╨╕╨╝╨┐╨╛╤А╤В-╨║╨╛╨╝╨░╨╜╨┤╤Л (`materials:import-pwg-arzamas`, `materials:import-kossovich-arzamas`) ╨╕ ╨╕╤Е ╤В╨╡╤Б╤В╤Л тАФ ╨┐╤Г╨▒╨╗╨╕╨║╨░╤Ж╨╕╨╛╨╜╨╜╤Л╨╣ ╨┐╤Г╤В╤М samskrte ╨╖╨░╨║╤А╤Л╤В, ╤З╤В╨╛╨▒╤Л ╨┤╨╡╨┐╨╗╨╛╨╣ ╨╜╨╡ ╨╝╨╛╨│ ╨▓╨╡╤А╨╜╤Г╤В╤М ╤Б╤В╨░╤В╤М╨╕ ╤Б╨╗╤Г╤З╨░╨╣╨╜╨╛. ╨Я╨░╨║╨╕ [pwg-arzamas](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/materials/pwg-arzamas) ╨╕ [kossovich-arzamas](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/materials/kossovich-arzamas) ╨╛╤Б╤В╨░╤О╤В╤Б╤П ╤А╨╡╨┤╨░╨║╤Ж╨╕╨╛╨╜╨╜╤Л╨╝ source of truth (READMEs ╨┐╨╛╨╝╨╡╤З╨╡╨╜╤Л RETARGETED; K-2 ╨╖╨░╨║╤А╤Л╤В ╨║╨░╨║ ╨▒╨╡╤Б╨┐╤А╨╡╨┤╨╝╨╡╤В╨╜╤Л╨╣); ╤Н╨║╤Б╨┐╨╛╤А╤В ╨┤╨╗╤П ╤А╨╡╨┤╨░╨║╤Ж╨╕╨╕ ╨Р╤А╨╖╨░╨╝╨░╤Б╨░ (╨╛╨▒╨░ SOURCE.md ╨╛╤В╤А╨╡╤Д╨╡╤А╨╡╨╡╨╜╨╜╤Л╤Е ╨▓╨╡╤А╤Б╨╕╨╣ + ╨▓╤Б╨╡ ╨║╨░╤А╤В╨╕╨╜╨║╨╕ + ╨╖╨░╨╝╨╡╤В╨║╨░ ╨╛╨▒ ╨░╨┤╨░╨┐╤В╨░╤Ж╨╕╨╕) ╨┐╨╡╤А╨╡╨┤╨░╨╜ MG (Desktop/Arzamas_export). Executor: Fable 5 (`claude-fable-5`).

## [1.73.0] - 2026-07-30

### Added
- **╨Я╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╨╕ ╨┐╤А╨╛╨┤╨░ ╨┐╨╡╤А╨╡╨╡╤Е╨░╨╗╨╕ ╨▓ git ╨╕ ╨╛╨▒╨╖╨░╨▓╨╡╨╗╨╕╤Б╤М ╨│╤А╨╛╨╝╨║╨╛╨╣ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╛╨╣ ╨╜╨░ ╨┐╤А╨╛╨┐╨░╨╢╤Г (H1914).** ╨Т╤Б╨╡ ╨┐╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╨╕, ╨┐╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╨╜╤Л╨╡ 29-07-2026 ([v1.65.0](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.65.0)тАУ[v1.67.0](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.67.0)), ╨╢╨╕╨╗╨╕ **╤В╨╛╨╗╤М╨║╨╛ ╨╜╨░ ╨╝╨░╤И╨╕╨╜╨╡**: ╨╛╨▒╤С╤А╤В╨║╨╕ `/usr/local/sbin/systema-{schedule,watchdog}-run.sh`, crontab `www-data`, drop-in'╤Л `cron.service.d`/`supervisor.service.d`, `memory_limit` CLI, `earlyoom`, `memwatch`, `sysstat`, `journald`, logrotate. `deploy.sh` ╨╕╤Е ╨╜╨╡ ╨║╨░╤Б╨░╨╗╤Б╤П, ╨╜╨╕ ╨╛╨┤╨╕╨╜ ╤В╨╡╤Б╤В ╨╕╨╗╨╕ ╨╝╨╛╨╜╨╕╤В╨╛╤А ╨╕╤Е ╨╜╨╡ ╨▓╨╕╨┤╨╡╨╗ тАФ ╨░ ╨┐╨╡╤А╨╡╨╡╨╖╨┤ ╨╜╨░ ╨╜╨╛╨▓╤Л╨╣ ╤Б╨╡╤А╨▓╨╡╤А ╨▓ ╤Н╤В╨╛╨╝ ╨┐╤А╨╛╨╡╨║╤В╨╡ **╤Г╨╢╨╡ ╨▒╤Л╨╗** (╨╕╤О╨╗╤М 2026): ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨░╤П ╨┐╨╡╤А╨╡╤Б╨▒╨╛╤А╨║╨░ LXC ╨╕╨╗╨╕ ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╨╡ ╨╕╨╖ ╨▒╤Н╨║╨░╨┐╨░ ╤Б╨╜╨╡╤Б╨╗╨╕ ╨▒╤Л ╨╕╤Е **╨╝╨╛╨╗╤З╨░**, ╨▓╨╡╤А╨╜╤Г╨▓ ╨░╨▓╨░╤А╨╕╤О ╨▓ ╨┐╤А╨╡╨╢╨╜╨╡╨╝ ╨▓╨╕╨┤╨╡. ╨в╨╡╨┐╨╡╤А╤М ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║ ╨┐╤А╨░╨▓╨┤╤Л ╨▓ ╤А╨╡╨┐╨╛╨╖╨╕╤В╨╛╤А╨╕╨╕: ╨╖╨╜╨░╤З╨╡╨╜╨╕╤П тАФ ╨▓ [`scripts/server_guards.conf`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards.conf) (**╨╛╨┤╨╜╨╛** ╨╝╨╡╤Б╤В╨╛, ╤З╨╕╤В╨░╤О╤В ╨╕ bash, ╨╕ PHP; ╤Д╨╛╤А╨╝╨░╤В ╨╜╨░╤А╨╛╤З╨╜╨╛ ╨▒╨╡╨┤╨╡╨╜ тАФ ╨▒╨╡╨╖ `$`-╨┐╨╛╨┤╤Б╤В╨░╨╜╨╛╨▓╨╛╨║, ╨╕╨╜╨░╤З╨╡ ╨┤╨▓╨░ ╨┐╨╛╤В╤А╨╡╨▒╨╕╤В╨╡╨╗╤П ╤А╨░╨╖╨╛╤И╨╗╨╕╤Б╤М ╨▒╤Л ╨▓ ╤В╤А╨░╨║╤В╨╛╨▓╨║╨╡ ╨╛╨┤╨╜╨╛╨│╨╛ ╤Д╨░╨╣╨╗╨░), ╤Б╨░╨╝╨╕ ╤Д╨░╨╣╨╗╤Л тАФ ╤И╨░╨▒╨╗╨╛╨╜╨░╨╝╨╕ ╤Б `@@╨Я╨Ю╨Ф╨б╨в╨Р╨Э╨Ю╨Т╨Ъ╨Р╨Ь╨Ш@@` ╨▓ [`scripts/server_guards/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/scripts/server_guards), ╨░ ╤Б╨┐╨╕╤Б╨╛╨║ ┬л╤Д╨░╨╣╨╗ тЖТ ╨║╤Г╨┤╨░ тЖТ ╨┐╤А╨░╨▓╨░ тЖТ ╨▓╨░╨╢╨╜╨╛╤Б╤В╤М┬╗ тАФ ╨▓ ╨╛╨▒╤Й╨╡╨╝ ╨┤╨╗╤П ╨┐╤А╨╕╨╝╨╡╨╜╤П╤В╨╡╨╗╤П ╨╕ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕ [`manifest.psv`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/manifest.psv) (╨┤╨▓╨╡ ╨║╨╛╨┐╨╕╨╕ ╤Б╨┐╨╕╤Б╨║╨░ ╤А╨░╨╖╨╛╤И╨╗╨╕╤Б╤М ╨▒╤Л, ╨╕ ┬л╨┐╤А╨╛╨▓╨╡╤А╨║╨░┬╗ ╨┐╨╡╤А╨╡╤Б╤В╨░╨╗╨░ ╨▒╤Л ╨┐╨╛╨║╤А╤Л╨▓╨░╤В╤М ┬л╨┐╤А╨╕╨╝╨╡╨╜╨╡╨╜╨╕╨╡┬╗). [`scripts/server_guards_apply.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_apply.sh) ╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╡╨╜ тАФ ╨▓╤В╨╛╤А╨╛╨╣ ╨┐╤А╨╛╨│╨╛╨╜ ╨┐╨╛╨┤╤А╤П╨┤ ╨╜╨╡ ╨╝╨╡╨╜╤П╨╡╤В ╨╜╨╕╤З╨╡╨│╨╛ тАФ ╨▒╤Н╨║╨░╨┐╨╕╤В ╨║╨░╨╢╨┤╤Л╨╣ ╨╕╨╖╨╝╨╡╨╜╤П╨╡╨╝╤Л╨╣ ╤Д╨░╨╣╨╗ ╨▓ `/root/preflight-backup-<stamp>/`, ╨░ **╨╗╨╛╨▓╤Г╤И╨║╤Г 29-07 ╨┐╤А╨╛╨▓╨╡╤А╤П╨╡╤В ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛ ╨╕ ╨╛╤В╨║╨░╨╖╤Л╨▓╨░╨╡╤В╤Б╤П ╤А╨░╨▒╨╛╤В╨░╤В╤М ╨┐╤А╨╕ ╨╡╤С ╨╜╨░╤А╤Г╤И╨╡╨╜╨╕╨╕**: ╤З╨╕╤Б╨╗╨░ ╨┐╨░╨╝╤П╤В╨╕ ╨┤╨╗╤П ╤О╨╜╨╕╤В╨░ ╨╛╨▒╤П╨╖╨░╨╜╤Л ╨╢╨╕╤В╤М ╤А╨╛╨▓╨╜╨╛ ╨▓ ╨╛╨┤╨╜╨╛╨╝ drop-in'╨╡, ╨╕╨╜╨░╤З╨╡ ╤Н╤Д╤Д╨╡╨║╤В╨╕╨▓╨╜╨╛╨╡ ╨╖╨╜╨░╤З╨╡╨╜╨╕╨╡ ╤А╨╡╤И╨░╨╡╤В ╤Б╨╛╤А╤В╨╕╤А╨╛╨▓╨║╨░ ╨╕╨╝╤С╨╜ ╤Д╨░╨╣╨╗╨╛╨▓, ╨░ ╨╜╨╡ ╨╖╨░╨╝╤Л╤Б╨╡╨╗. ╨Э╨╛╨▓╨░╤П `php artisan guards:verify` ([`VerifyServerGuards`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/VerifyServerGuards.php) ╨┐╨╛╨▓╨╡╤А╤Е [`ServerGuardsAuditor`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ServerGuards/ServerGuardsAuditor.php)) **╨╜╨╕╤З╨╡╨│╨╛ ╨╜╨╡ ╨┐╤А╨╕╨╝╨╡╨╜╤П╨╡╤В** тАФ ╨╛╨╜╨░ ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В non-zero ╨╕ ╨┐╨╡╤З╨░╤В╨░╨╡╤В, ╨з╨в╨Ю ╨Ш╨Ь╨Х╨Э╨Э╨Ю ╨┐╤А╨╛╨┐╨░╨╗╨╛: ╨╛╨▒╤С╤А╤В╨║╨░ ╨▓ crontab (╨╕ ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛ тАФ ╨╜╨╡ ╨▓╨╡╤А╨╜╤Г╨╗╤Б╤П ╨╗╨╕ ╨│╨╛╨╗╤Л╨╣ `artisan schedule:run`), ╨╛╨▒╨╡ ╤Б╤В╨╛╤А╨╛╨╢╨╡╨▓╤Л╨╡ ╤Б╤В╤А╨╛╨║╨╕ cron ╤Б ╨╕╤Е ╤А╨░╤Б╨┐╨╕╤Б╨░╨╜╨╕╨╡╨╝ ╨╕ ╤В╨░╨╣╨╝╨░╤Г╤В╨╛╨╝, `MemoryHigh`/`MemoryMax`/`TasksMax`/`OOMPolicy=kill` ╤Н╤Д╤Д╨╡╨║╤В╨╕╨▓╨╜╤Л╨╝╨╕ ╨╖╨╜╨░╤З╨╡╨╜╨╕╤П╨╝╨╕ ╨╕╨╖ `systemctl show` (╨░ ╨╜╨╡ ╨┐╨╛ ╨╜╨░╨╗╨╕╤З╨╕╤О ╤Д╨░╨╣╨╗╨░), `memory_limit` CLI тЙа `-1`, ╨┐╤Г╨╗ fpm, `earlyoom`/`rsyslog` active, ╤В╨░╨╣╨╝╨╡╤А╤Л `memwatch`/`sysstat`, ╨┐╨╗╤О╤Б ╤А╨░╤Б╤Е╨╛╨╢╨┤╨╡╨╜╨╕╨╡ ╤Б╨╛╨┤╨╡╤А╨╢╨╕╨╝╨╛╨│╨╛ ╨╕ ╨┐╤А╨░╨▓ ╨╗╤О╨▒╨╛╨│╨╛ ╤Г╨┐╤А╨░╨▓╨╗╤П╨╡╨╝╨╛╨│╨╛ ╤Д╨░╨╣╨╗╨░ ╤Б ╤А╨╡╨┐╨╛╨╖╨╕╤В╨╛╤А╨╕╨╡╨╝. ╨Я╤А╨╛╨▓╨╡╤А╨║╨░ **╨┐╨╛╨▓╨╡╤И╨╡╨╜╨░ ╨╜╨░ ╨╢╨╕╨▓╨╛╨╣ ╨║╨╛╨╜╤В╤Г╤А**: `cabinet:probe` (╨╛╤В╨┤╨╡╨╗╤М╨╜╨░╤П ╤Б╤В╤А╨╛╨║╨░ cron `*/15`, ╤В╨╛ ╨╡╤Б╤В╤М ╨┐╨╡╤А╨╡╨╢╨╕╨▓╨░╨╡╤В ╨╖╨░╨▓╨╕╤Б╤И╨╕╨╣ ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║) ╨┤╨╛╨▒╨░╨▓╨╗╤П╨╡╤В ╨╜╨░╤Е╨╛╨┤╨║╨╕ ╨║╨░╨║ ╤Б╨▓╨╛╤О ╨┐╨╛╨▓╨╡╤А╤Е╨╜╨╛╤Б╤В╤М тАФ `critical` ╨╕╨┤╤С╤В ╨▓ Telegram ╨║╨░╨║ ╨║╤А╨╕╤В╨╕╤З╨╜╨░╤П ╤В╤А╨╡╨▓╨╛╨│╨░, ╤А╨░╤Б╤Е╨╛╨╢╨┤╨╡╨╜╨╕╨╡ ╨║╨░╨║ `soft`, ╨╕ ╤В╨╛ ╨╕ ╨┤╤А╤Г╨│╨╛╨╡ ╨┐╨╕╤И╨╡╤В╤Б╤П ╨▓ `cabinet_probe_runs`; [`deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh) ╨╖╨╛╨▓╤С╤В **╤В╨╛╨╗╤М╨║╨╛ verify, ╨╜╨╕╨║╨╛╨│╨┤╨░ apply** (╨▓╤Л╨║╨╗╨░╨┤╨║╨░ ╨║╨╛╨┤╨░ ╨╜╨╡ ╨┤╨╛╨╗╨╢╨╜╨░ ╨╝╨╛╨╗╤З╨░ ╨╝╨╡╨╜╤П╤В╤М ╤Б╨╕╤Б╤В╨╡╨╝╨╜╤Л╨╣ ╨║╨╛╨╜╤Д╨╕╨│) ╨╕ ╨╖╨░╨║╨░╨╜╤З╨╕╨▓╨░╨╡╤В╤Б╤П ╨╜╨╡╨╜╤Г╨╗╨╡╨▓╤Л╨╝ ╨║╨╛╨┤╨╛╨╝ ╨┐╤А╨╕ ╤А╨░╤Б╤Е╨╛╨╢╨┤╨╡╨╜╨╕╨╕. ╨Ю╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╨╡ ╤Б╨▓╨╛╨┐╨░ ╤Б╨╛╨╛╨▒╤Й╨░╨╡╤В╤Б╤П ╨║╨░╨║ `info` ╨╕ ╨║╨╛╨┤ ╨▓╨╛╨╖╨▓╤А╨░╤В╨░ ╨╜╨╡ ╨┐╨╛╤А╤В╨╕╤В: ╨▓╨╜╤Г╤В╤А╨╕ LXC ╨╛╨╜ ╨╜╨╡ ╨╖╨░╨▓╨╛╨┤╨╕╤В╤Б╤П, ╤Н╤В╨╛ ╤А╤Г╤З╨║╨░ ╨╜╨░ ╤Е╨╛╤Б╤В╨╡ Proxmox. `app/Console/Kernel.php` ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В тАФ ╤Н╤В╨╛ [H1915](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1915-Opus_Systema-Sanscriticum_madeline-sync-watchdog-not-enforced_29.07.26.md)/[H1916](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1916-Sonnet_Systema-Sanscriticum_scheduled-command-timeout-overlap-sweep_29.07.26.md). Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.72.0] - 2026-07-30

### Fixed
- **╨Р╤А╨╖╨░╨╝╨░╤Б-╨╗╨╛╨╜╨│╤А╨╕╨┤ ┬л╨Я╨╡╤В╨╡╤А╨▒╤Г╤А╨│╤Б╨║╨╕╨╣ ╤Б╨╗╨╛╨▓╨░╤А╤М┬╗ ╨┐╤А╨╛╤И╤С╨╗ ╨▓╤А╨░╨╢╨┤╨╡╨▒╨╜╤Г╤О ╨┐╤А╨╡╨┤╨┐╤Г╨▒╨╗╨╕╨║╨░╤Ж╨╕╨╛╨╜╨╜╤Г╤О ╤Б╨▓╨╡╤А╨║╤Г ╤Д╨░╨║╤В╨╛╨▓ (H1862).** ╨Т╤Б╨╡ 136 ╤Д╨░╨║╤В╨╕╤З╨╡╤Б╨║╨╕╤Е ╤Г╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╕╨╣ ╤Н╤Б╤Б╨╡ ╨┐╨╡╤А╨╡╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜╤Л ╨┐╨╛ ╨┐╨╡╤А╨▓╨╛╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨░╨╝ тАФ ╨╜╨╡ ╨┐╨╛ ╤В╨░╨▒╨╗╨╕╤Ж╨╡ FACTS, ╨░ ╨┐╨╛ ╤Б╨░╨╝╨╕╨╝ ╨╜╨░╨╖╨▓╨░╨╜╨╜╤Л╨╝ ╤Д╨░╨╣╨╗╨░╨╝, ╤Б╤В╤А╨╛╨║╨░╨╝, ╤Б╨║╨░╨╜╨░╨╝ ╨╕ ╨▓╨╜╨╡╤И╨╜╨╕╨╝ ╤Б╨┐╤А╨░╨▓╨╛╤З╨╜╨╕╨║╨░╨╝ ([╨┐╨╛╨╗╨╜╤Л╨╣ ╤А╤Г╤Б╤Б╨║╨╕╨╣ ╨┐╨╡╤А╨╡╨▓╨╛╨┤ ╨┐╤А╨╡╨┤╨╕╤Б╨╗╨╛╨▓╨╕╨╣](https://github.com/sanskrit-lexicon/PWG/blob/main/prefaces/pwgpref_all.ru.md) ╨┐╤А╨╛╤З╨╕╤В╨░╨╜ ╤Ж╨╡╨╗╨╕╨║╨╛╨╝, ╨╖╨░╨┐╨╕╤Б╨╕ [pwg.txt](https://github.com/sanskrit-lexicon/csl-orig/blob/master/v02/pwg/pwg.txt) ╨┐╨╛╨┤╨╜╤П╤В╤Л ╨┐╨╛ L-╨╜╨╛╨╝╨╡╤А╨░╨╝, ╨╛╤В╤З╤С╤В╤Л csl-atlas тАФ ╨┐╨╛ ╤Ж╨╕╤В╨╕╤А╤Г╨╡╨╝╤Л╨╝ ╤Б╤В╤А╨╛╨║╨░╨╝, ╨Т╨╕╨│╨░╤Б╨╕╨╜ ╨╕ ╨Ю╨╗╤М╨┤╨╡╨╜╨▒╤Г╤А╨│ тАФ ╨┐╨╛ ╨┐╨╛╨╗╨╜╤Л╨╝ ╤В╨╡╨║╤Б╤В╨░╨╝, ╤Б╨║╨░╨╜╤Л ╤В╨╕╤В╤Г╨╗╨░ ╨╕ ╨│╤А╨╕╤Д╨░ 1855 ╨│. ╨┐╨╡╤А╨╡╤З╨╕╤В╨░╨╜╤Л ╨║╨░╨║ ╨╕╨╖╨╛╨▒╤А╨░╨╢╨╡╨╜╨╕╤П, EB1911/NIE ╨▓╤Л╨║╨░╤З╨░╨╜╤Л). ╨Ш╤В╨╛╨│: **121 sourced ┬╖ 14 corrected ┬╖ 1 struck**, ╨▓╨╡╤А╨┤╨╕╨║╤В╤Л ╨┐╨╛╤Б╤В╤А╨╛╤З╨╜╨╛ тАФ ╨▓ [FACTS_REFEREE_VERDICTS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/FACTS_REFEREE_VERDICTS_2026.md). ╨Ъ╤А╤Г╨┐╨╜╨╡╨╣╤И╨╕╨╡ ╨┐╨╛╨╣╨╝╨░╨╜╨╜╤Л╨╡ ╨┤╨╡╤Д╨╡╨║╤В╤Л: ╨╢╨░╨╗╨╛╨▒╨░ ╤Б╨╛╨░╨▓╤В╨╛╤А╨╛╨▓ ╨╛ ╤А╨░╤Б╤Б╤В╨╛╤П╨╜╨╕╨╕ ╤Б╤В╨╛╨╕╤В ╨▓ ╨┐╤А╨╡╨┤╨╕╤Б╨╗╨╛╨▓╨╕╨╕ **╤В. 1**, ╨░ ╨╜╨╡ ╤В. 2; ╨а╨╛╤В ╨╝╨╛╨╗╨╛╨╢╨╡ ╨С╤С╤В╨╗╨╕╨╜╨│╨║╨░ ╨╜╨░ **╤И╨╡╤Б╤В╤М** ╨╗╨╡╤В, ╨╜╨╡ ╨╜╨░ ╨│╨╛╨┤; ╨╗╨░╤В╤Л╨╜╤М ╨▓ 1852-╨╝ ╤В╤А╨╡╨▒╨╛╨▓╨░╨╗ ╨г╨▓╨░╤А╨╛╨▓-**╨┐╤А╨╡╨╖╨╕╨┤╨╡╨╜╤В ╨Р╨║╨░╨┤╨╡╨╝╨╕╨╕**, ╨░ ╨╜╨╡ ╨╝╨╕╨╜╨╕╤Б╤В╤А (╨┐╨╛╤Б╤В ╨╝╨╕╨╜╨╕╤Б╤В╤А╨░ ╨╛╨╜ ╤Б╨┤╨░╨╗ ╨▓ 1849-╨╝); hev─Бkin ╨▓╨╖╤П╤В ╨╜╨╡ ╨╕╨╖ ┬л╨║╨░╤И╨╝╨╕╤А╤Б╨║╨╛╨╣ ╤Е╤А╨╛╨╜╨╕╨║╨╕┬╗; ┬л╤И╨║╨╛╨╗╤М╨╜╤Л╨╣ ╤Б╨╗╨╛╨▓╨░╤А╤М ╨┐╨╛╨╗╤В╨╛╤А╤Л ╤В╤Л╤Б╤П╤З╨╕ ╨╗╨╡╤В┬╗ ╨╛╨▒ ╨Р╨╝╨░╤А╨░╨║╨╛╤И╨╡ ╨╕ ┬л╤А╨░╨▒╨╛╤З╨╕╨╣ ╤П╨╖╤Л╨║ ╨Р╨║╨░╨┤╨╡╨╝╨╕╨╕┬╗ ╨╛ ╨╜╨╡╨╝╨╡╤Ж╨║╨╛╨╝ ╨╜╨╡ ╨╕╨╝╨╡╨╗╨╕ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨╛╨▓ тАФ ╨▓╤Л╤З╨╡╤А╨║╨╜╤Г╤В╤Л, ╨╜╨╡ ╤Б╨╝╤П╨│╤З╨╡╨╜╤Л. `body.html` ╨┐╨╡╤А╨╡╤Б╨╛╨▒╤А╨░╨╜ ╨╕╨╖ ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╨╜╨╛╨│╨╛ `SOURCE.md` (20 h2 ╤Б╨╛╤Е╤А╨░╨╜╨╡╨╜╤Л). Executor: Fable 5 (`claude-fable-5`).

## [1.71.0] - 2026-07-30

### Added
- **╨и╨░╨▒╨╗╨╛╨╜ ╨▓╤Л╤В╨╡╤Б╨╜╨╕╨╗ LLM ╤В╨░╨╝, ╨│╨┤╨╡ ╨╛╤В╨▓╨╡╤В ╤Г╤Б╤В╨╛╤П╨╗╤Б╤П, тАФ ╤И╨░╨▒╨╗╨╛╨╜╨╜╤Л╨╡ ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║╨╕ FAQ-╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А╨░ (S9, H1838).** SupportAnswerSuggester v1/v2 (H247/H816) ╨┤╨╗╤П ╨║╨░╤В╨╡╨│╨╛╤А╨╕╨╣ D/E/F ╨▓╤Б╨╡╨│╨┤╨░ ╨╖╨▓╨░╨╗ ╨▓╨╜╨╡╤И╨╜╨╕╨╣ LLM, ╨┤╨░╨╢╨╡ ╨║╨╛╨│╨┤╨░ ╤Г ╤И╨║╨╛╨╗╤Л ╨┤╨░╨▓╨╜╨╛ ╨╡╤Б╤В╤М ╨▓╤Л╨▓╨╡╤А╨╡╨╜╨╜╤Л╨╣ ╨║╨░╨╜╤А╨╡╨┐╨╗╨░╨╣: ╨╗╨╕╤И╨╜╨╕╨╡ ╨▓╤Л╨╖╨╛╨▓╤Л OpenRouter ╨╕ ╨╝╨╡╨╜╨╡╨╡ ╨║╨╛╨╜╤Б╨╕╤Б╤В╨╡╨╜╤В╨╜╤Л╨╡ ╤Д╨╛╤А╨╝╤Г╨╗╨╕╤А╨╛╨▓╨║╨╕, ╤З╨╡╨╝ ╨▓ ╨▒╨╕╨▒╨╗╨╕╨╛╤В╨╡╨║╨╡ ╤И╨░╨▒╨╗╨╛╨╜╨╛╨▓. ╨в╨╡╨┐╨╡╤А╤М ╤Г [`MessageTemplate`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/MessageTemplate.php) (H221) ╨╡╤Б╤В╤М ╨┐╤А╨╕╨▓╤П╨╖╨║╨░ ┬л╨║╨░╤В╨╡╨│╨╛╤А╨╕╤П ╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А╨░ тЖТ ╤И╨░╨▒╨╗╨╛╨╜┬╗ (╨║╨╛╨╗╨╛╨╜╨║╨░ `suggester_category`, ╤Б╨╡╨╗╨╡╨║╤В╨╛╤А ╨▓ [`MessageTemplateResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/MessageTemplateResource.php)), ╨╕ ╨╜╨╛╨▓╤Л╨╣ [`SupportTemplateDraftResolver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportTemplateDraftResolver.php) ╤Б╤В╨╛╨╕╤В ╨Я╨Х╨а╨Х╨Ф [`SupportLlmDraftComposer`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportLlmDraftComposer.php): ╨┐╤А╨╕╨▓╤П╨╖╨░╨╜╨╜╨░╤П ╨║╨░╤В╨╡╨│╨╛╤А╨╕╤П ╤Б╨╛╨▒╨╕╤А╨░╨╡╤В ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║ ╨╕╨╖ ╤И╨░╨▒╨╗╨╛╨╜╨░ ╤Б ╨┐╨╛╨┤╤Б╤В╨░╨╜╨╛╨▓╨║╨╛╨╣ [`MessagePlaceholders`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/MessagePlaceholders.php) тАФ LLM ╨╜╨╡ ╨▓╤Л╨╖╤Л╨▓╨░╨╡╤В╤Б╤П ╨▓╨╛╨▓╤Б╨╡ (╨╕ ╨┤╨╜╨╡╨▓╨╜╨╛╨╣ cap ╨╜╨╡ ╤А╨░╤Б╤Е╨╛╨┤╤Г╨╡╤В╤Б╤П); ╨╜╨╡╨┐╤А╨╕╨▓╤П╨╖╨░╨╜╨╜╨░╤П ╨╕╨┤╤С╤В ╨┐╤А╨╡╨╢╨╜╨╕╨╝ LLM-╨┐╤Г╤В╤С╨╝ ╨▒╨░╨╣╤В-╨▓-╨▒╨░╨╣╤В. ╨Я╤А╨╕╨▓╤П╨╖╨║╨░ ╨┐╤А╨╡╨┤╨╗╨░╨│╨░╨╡╤В╤Б╤П ╤В╨╛╨╗╤М╨║╨╛ ╨┤╨╗╤П D/E/F: ╤Д╨░╨║╤В╨╛╨▓╤Л╨╡ A/B/C ╨╕ ╤В╨░╨║ ╤Б╤В╤А╨╛╤П╤В╤Б╤П ╨▒╨╡╨╖ LLM ╨╕╨╖ ╨╢╨╕╨▓╤Л╤Е ╨┤╨░╨╜╨╜╤Л╤Е LMS, ╨╕ ╤Н╤В╨╛ ╨╖╨░╨║╤А╤Л╤В╨╛ ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╨╡╨╣. ╨Ъ╨░╨╢╨┤╤Л╨╣ ╤И╨░╨▒╨╗╨╛╨╜╨╜╤Л╨╣ ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║ ╨┐╨╕╤И╨╡╤В ╤Б╨╛╨▒╤Л╤В╨╕╨╡ `answer_template_drafted` тАФ ╨╖╨╡╤А╨║╨░╨╗╨╛ `answer_llm_drafted` ╨▓ ╤В╨╛╨╝ ╨╢╨╡ ╨╢╤Г╤А╨╜╨░╨╗╨╡ `SupportAiReplyEvent`, ╤В╨░╨║ ╤З╤В╨╛ A/B-╤Б╤А╨░╨▓╨╜╨╡╨╜╨╕╨╡ ╨┐╤А╨╕╨╜╨╕╨╝╨░╨╡╨╝╨╛╤Б╤В╨╕ ╤И╨░╨▒╨╗╨╛╨╜╨╜╤Л╤Е ╨╕ LLM-╤З╨╡╤А╨╜╨╛╨▓╨╕╨║╨╛╨▓ (╨╝╨╡╤В╤А╨╕╨║╨░ ╤Г╤Б╨┐╨╡╤Е╨░ S9) ╤Б╤З╨╕╤В╨░╨╡╤В╤Б╤П ╨┐╨╛ `suggestion_id тЖТ facts.type` ╨▒╨╡╨╖ ╨╜╨╛╨▓╨╛╨╣ ╨╛╤В╤З╤С╤В╨╜╨╛╤Б╤В╨╕. ╨Я╤А╨╕╨╜╤П╤В╤М/╨Ш╨╖╨╝╨╡╨╜╨╕╤В╤М/╨Ю╤В╨║╨╗╨╛╨╜╨╕╤В╤М ╨▓ Helpdesk ╤А╨░╨▒╨╛╤В╨░╤О╤В ╤Б ╤И╨░╨▒╨╗╨╛╨╜╨╜╤Л╨╝ ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║╨╛╨╝ ╤А╨╛╨▓╨╜╨╛ ╨║╨░╨║ ╤Б LLM-╨╜╤Л╨╝. ╨д╨╗╨░╨│ **`support_template_drafts`, ╨Т╨л╨Ъ╨Ы ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О** (`SUPPORT_TEMPLATE_DRAFTS`): ╨┐╨╛╨║╨░ OFF, ╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А ╨▓╨╡╨┤╤С╤В ╤Б╨╡╨▒╤П ╨║╨░╨║ ╨┤╨╛ H1838, ╨┤╨░╨╢╨╡ ╨╡╤Б╨╗╨╕ ╨┐╤А╨╕╨▓╤П╨╖╨║╨╕ ╤Г╨╢╨╡ ╤А╨░╤Б╤Б╤В╨░╨▓╨╗╨╡╨╜╤Л. Executor: Fable 5 (`claude-fable-5`).

## [1.70.0] - 2026-07-30

### Added
- **╨а╨╡╨╣╤В╨╕╨╜╨│ ╤В╨╡╨╝ ╨┐╨╛╨┤╨┤╨╡╤А╨╢╨║╨╕ ╨╜╨░╤Г╤З╨╕╨╗╤Б╤П ╤З╨╕╤В╨░╤В╤М ╨║╨░╨╜╨░╨╗, ╨░ ╨╜╨╡ ╤В╨╛╨╗╤М╨║╨╛ ╤Б╤З╨╕╤В╨░╤В╤М ╨╡╨│╨╛ (H1837, ╨┤╨╛╨▒╨╛╤А).** [v1.69.0](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.69.0) ╤Б╨┤╨╡╨╗╨░╨╗╨░ `support_daily_rollups` ╨┤╨▓╤Г╤Е╨║╨░╨╜╨░╨╗╤М╨╜╨╛╨╣, ╨╕ [`support:topic-ranking`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SupportTopicRanking.php) ╤Б ╤В╨╛╨│╨╛ ╨╝╨╛╨╝╨╡╨╜╤В╨░ **╤Г╨╢╨╡** ╤Б╤З╨╕╤В╨░╨╗ ╨▓╨╡╨▒-╤Б╤В╤А╨╛╨║╨╕ тАФ ╨╛╨╜ ╨┤╨╢╨╛╨╣╨╜╨╕╤В╤Б╤П ╨╜╨░ rollup'╤Л, ╤В╨░╨║ ╤З╤В╨╛ ╨╜╨╛╨▓╤Л╨╡ ╤Б╤В╤А╨╛╨║╨╕ ╨▓╨╛╤И╨╗╨╕ ╨▓ ╨╛╤В╤З╤С╤В ╤Б╨░╨╝╨╕. ╨з╨╡╨│╨╛ ╨╛╨╜ ╨╜╨╡ ╤Г╨╝╨╡╨╗ тАФ ╤Б╨║╨░╨╖╨░╤В╤М, **╨║╤Г╨┤╨░** ╤В╨╡╨╝╨░ ╨┐╤А╨╕╤Е╨╛╨┤╨╕╤В, ╨╕ ╨┐╨╛╨║╨░╨╖╨░╤В╤М ╤Б╤А╨╡╨╖. ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜╤Л `--channel=all|web-side|telegram|web|vk|telegram_bot` ╨╕ ╨║╨╛╨╗╨╛╨╜╨║╨░ **`web %`** (╨┤╨╛╨╗╤П ╤З╨░╤В-╨┤╨╜╨╡╨╣, ╨┐╤А╨╕╤И╨╡╨┤╤И╨╕╤Е ╤З╨╡╤А╨╡╨╖ ╨▓╨╕╨┤╨╢╨╡╤В / VK / TG-student-╨▒╨╛╤В, ╨░ ╨╜╨╡ ╤З╨╡╤А╨╡╨╖ TG-support-╨░╨║╨║╨░╤Г╨╜╤В). ╨Ю╨┤╨╜╨╛ ╨╕ ╤В╨╛ ╨╢╨╡ ╤З╨╕╤Б╨╗╨╛ ╨┤╨╡╤Д╨╗╨╡╨║╤Ж╨╕╨╕ ╨╛╨╖╨╜╨░╤З╨░╨╡╤В ╨┤╨▓╨╡ ╤А╨░╨╖╨╜╤Л╨╡ ╤Б╤В╤А╨╛╨╣╨║╨╕: ╤В╨╡╨╝╨░ ╨╜╨░ 90 % ╨▓╨╡╨▒ ╨┐╤А╨╛╤Б╨╕╤В self-serve ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Г ╨╜╨░ ╤Б╨░╨╣╤В╨╡, ╤В╨░ ╨╢╨╡ ╤В╨╡╨╝╨░ ╨╜╨░ 90 % Telegram тАФ ╨╛╤В╨▓╨╡╤В ╨▓ ╨▒╨░╨╖╨╡ ╨╖╨╜╨░╨╜╨╕╨╣ ╨▒╨╛╤В╨░, ╨╕ ╤Г╤Б╤А╨╡╨┤╨╜╤С╨╜╨╜╨░╤П ╤Ж╨╕╤Д╤А╨░ ╤Н╤В╨╕ ╤Б╨╗╤Г╤З╨░╨╕ ╨╜╨╡ ╤А╨░╨╖╨╗╨╕╤З╨░╨╡╤В. ╨Ч╨╜╨░╤З╨╡╨╜╨╕╨╡ `web-side` ╨▒╨╡╤А╤С╤В╤Б╤П ╨╕╨╖ `SupportDailyRollup::WEB_SIDE_CHANNELS`, ╨░ ╨╜╨╡ ╨╕╨╖ ╤Б╨▓╨╛╨╡╨│╨╛ ╤Б╨┐╨╕╤Б╨║╨░, ╤З╤В╨╛╨▒╤Л ╨╜╨╛╨▓╤Л╨╣ ╨║╨░╨╜╨░╨╗ ╨╜╨╡ ╨┐╤А╨╕╤И╨╗╨╛╤Б╤М ╨┤╨╛╨▒╨░╨▓╨╗╤П╤В╤М ╨▓ ╨┤╨▓╤Г╤Е ╨╝╨╡╤Б╤В╨░╤Е. Executor: Opus 5 (`claude-opus-5`).

## [1.69.0] - 2026-07-30

### Added
- **╨Ш╨╖╨╝╨╡╤А╨╡╨╜╨╕╨╡ ╨┐╨╛╨┤╨┤╨╡╤А╨╢╨║╨╕ ╨┐╨╡╤А╨╡╤Б╤В╨░╨╗╨╛ ╨▒╤Л╤В╤М ╨╛╨┤╨╜╨╛╨║╨░╨╜╨░╨╗╤М╨╜╤Л╨╝ тАФ ╨▓╨╡╨▒-╤З╨░╤В ╨┐╨╛╨┐╨░╨╗ ╨▓ rollup'╤Л (S10, H1837).** [`SupportDailyRollupAggregator`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/SupportDailyRollupAggregator.php) ╤З╨╕╤В╨░╨╗ ╤А╨╛╨▓╨╜╨╛ ╨┤╨▓╨░ ╤Б╤В╨╛╤А╨░ тАФ `TelegramSupportChat`/`TelegramSupportMessage` тАФ ╨╕ **╨╜╨╕ ╤А╨░╨╖╤Г** ╨╜╨╡ ╤Г╨┐╨╛╨╝╨╕╨╜╨░╨╗ `ChatMessage`/`SupportConversation`. ╨Ч╨╜╨░╤З╨╕╤В ╨▓╤Б╤П ╨╕╨╖╨╝╨╡╤А╨╕╤В╨╡╨╗╤М╨╜╨░╤П ╨╜╨░╨┤╤Б╤В╤А╨╛╨╣╨║╨░ (deflection-╨╛╤В╤З╤С╤В╤Л S2/S7, ╨┐╨╛╨╕╤Б╨║ ╨║╨╛╨╜╤В╨╡╨╜╤В-╨┤╤Л╤А CAI3, ┬л╨Э╨░╨▒╨╗╤О╨┤╨░╨╡╨╝╨╛╤Б╤В╤М┬╗) ╨▒╤Л╨╗╨░ ╤Б╨╗╨╡╨┐╨░ ╨║ ╨▓╨╡╨▒-╨▓╨╕╨┤╨╢╨╡╤В╤Г, VK-╨▒╨╛╤В╤Г ╨╕ TG-student-╨▒╨╛╤В╤Г ╤Ж╨╡╨╗╨╕╨║╨╛╨╝: ╨░╤Б╨╕╨╝╨╝╨╡╤В╤А╨╕╤П, ╨╕╨╖╨▓╨╡╤Б╤В╨╜╨░╤П ╤Б H536 ╨╕ ╤В╨╛╨╗╤М╨║╨╛ ╨▓╤Л╤А╨╛╤Б╤И╨░╤П ╨┐╨╛╤Б╨╗╨╡ Jivo-╨┐╨░╤А╨╕╤В╨╡╤В╨░ (H1196тАУH1200). ╨в╨╡╨┐╨╡╤А╤М `support_daily_rollups` ╨┤╨▓╤Г╤Е╨║╨░╨╜╨░╨╗╤М╨╜╨░╤П тАФ ╨║╨╛╨╗╨╛╨╜╨║╨░-╨┤╨╕╤Б╨║╤А╨╕╨╝╨╕╨╜╨░╤В╨╛╤А `channel` ╨┐╨╗╤О╤Б nullable ╨║╨╗╤О╤З╨╕ ╤Б╤Г╨▒╤К╨╡╨║╤В╨░ `support_conversation_id` ╨╕ `web_user_id` ╤А╤П╨┤╨╛╨╝ ╤Б ╨┐╤А╨╡╨╢╨╜╨╕╨╝ `telegram_support_chat_id`; ╨╜╨╛╨▓╤Л╨╣ [`WebSupportRollupAggregator`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/WebSupportRollupAggregator.php) ╨┐╨╕╤И╨╡╤В ╨▓╨╡╨▒-╤Б╤В╤А╨╛╨║╨╕ ╨▓ **╤В╤Г ╨╢╨╡** ╤В╨░╨▒╨╗╨╕╤Ж╤Г. ╨Ю╨┤╨╜╨░ ╤В╨░╨▒╨╗╨╕╤Ж╨░, ╨░ ╨╜╨╡ ╨┐╨░╤А╨░╨╗╨╗╨╡╨╗╤М╨╜╨░╤П, ╨▓╤Л╨▒╤А╨░╨╜╨░ ╤А╨░╨┤╨╕ ╤Г╤Б╨╗╨╛╨▓╨╕╤П ╨┐╤А╨╕╤С╨╝╨║╨╕ ┬л╨╡╨┤╨╕╨╜╤Л╨╣ ╨╛╤В╤З╤С╤В ╨▒╨╡╨╖ ╤А╤Г╤З╨╜╨╛╨╣ ╤Б╨▓╨╡╤А╨║╨╕┬╗: ╤Б╨▓╨╛╨┤╨╜╤Л╨╡ ╤З╨╕╤Б╨╗╨░ ╨╕ ╤А╨░╨╖╨▒╨╕╨▓╨║╨░ ╨┐╨╛ ╨║╨░╨╜╨░╨╗╨░╨╝ ╤Б╤З╨╕╤В╨░╤О╤В╤Б╤П ╨╛╨┤╨╜╨╕╨╝ ╨╖╨░╨┐╤А╨╛╤Б╨╛╨╝ ╨▓ [`SupportDashboardPacketBuilder`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/SupportDashboardPacketBuilder.php) ╨╕ [`SupportObservability`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/SupportObservability.php). **╨Ч╨░╨┐╤А╨╡╤В ┬л╨╜╨╡ ╤Б╨╗╨╕╨▓╨░╨╣╤В╨╡ ╤В╨░╨▒╨╗╨╕╤Ж╤Л┬╗ ╨╕╨╖ [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) ╨╜╨╡ ╨╜╨░╤А╤Г╤И╨╡╨╜ ╨╕ ╤Г╤В╨╛╤З╨╜╤С╨╜ ╨▓ ╤Б╨░╨╝╨╛╨╝ ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨╡:** ╨╛╨╜ ╨┐╤А╨╛ ╨┤╨▓╨░ ╨е╨а╨Р╨Э╨Ш╨Ы╨Ш╨й╨Р ╨б╨Ю╨Ю╨С╨й╨Х╨Э╨Ш╨Щ, ╨║╨╛╤В╨╛╤А╤Л╨╡ ╨╛╤Б╤В╨░╤О╤В╤Б╤П ╤А╨░╨╖╨┤╨╡╨╗╤М╨╜╤Л╨╝╨╕, тАФ rollup ╨▓╤Б╨╡╨│╨┤╨░ ╨▒╤Л╨╗ ╨░╨│╤А╨╡╨│╨░╤В╨╛╨╝, ╤З╨╕╤В╨░╨╡╤В╤Б╤П ╨╛╨╜ ╤З╨╡╤А╨╡╨╖ ╨╛╨▒╤Й╨╕╨╣ `UnifiedMessage`, ╨╕ ╨╜╨╕ ╨╛╨┤╨╜╨░ ╤Б╤В╤А╨╛╨║╨░ ╨╝╨╡╨╢╨┤╤Г ╤Б╤В╨╛╤А╨░╨╝╨╕ ╨╜╨╡ ╨║╨╛╨┐╨╕╤А╤Г╨╡╤В╤Б╤П. ╨в╤А╨╡╤В╨╕╨╣ ╨║╨╗╤О╤З `web_user_id` ╨╖╨░╨▓╨╡╨┤╤С╨╜ ╨╜╨╡ ╨┤╨╗╤П ╤Б╨╕╨╝╨╝╨╡╤В╤А╨╕╨╕: VK-╨▒╨╛╤В, TG-student-╨▒╨╛╤В ╨╕ ╤А╤Г╤З╨╜╤Л╨╡ ╨╛╤В╨▓╨╡╤В╤Л ╨╕╨╖ `UserResource\Pages\Dialogs` ╨┐╨╕╤И╤Г╤В `chat_messages` **╨▒╨╡╨╖** `support_conversation_id`, ╤В╨░╨║ ╤З╤В╨╛ ╨░╨│╤А╨╡╨│╨░╤Ж╨╕╤П ╤В╨╛╨╗╤М╨║╨╛ ╨┐╨╛ ╤В╤А╨╡╨┤╤Г ╨╜╨╡ ╨┤╨░╨╗╨░ ╨▒╤Л ╨╛╨▒╨╡╤Й╨░╨╜╨╜╤Л╤Е ┬л100 % ╨▓╤Е╨╛╨┤╤П╤Й╨╕╤Е┬╗. ╨Р╤А╨╕╤Д╨╝╨╡╤В╨╕╨║╨░ ╨╝╨╡╤В╤А╨╕╨║ ╨▓╤Л╨╜╨╡╤Б╨╡╨╜╨░ ╨▓ ╨╛╨▒╤Й╨╕╨╣ [`SupportRollupMetrics`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/SupportRollupMetrics.php) тАФ ╨╛╨▒╨░ ╨░╨│╤А╨╡╨│╨░╤В╨╛╤А╨░ ╤Б╤З╨╕╤В╨░╤О╤В ╨Ю╨Ф╨Э╨Ш╨Ь ╨║╨╛╨┤╨╛╨╝, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨╜╨╛╨▓╤Л╨╣ KPI **unresolved-after-N-hours** (`support.rollup.unresolved_after_hours`, 24 ╤З) ╨╕ ╨║╨╗╨░╤Б╤Б╨╕╤Д╨╕╨║╨░╤Ж╨╕╤П ╤В╨╛╨┐╨╕╨║╨╛╨▓ ([`SupportTopicClassifier`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/SupportTopicClassifier.php) ╨▓╨╡╤В╨▓╨╕╤В╤Б╤П ╤В╨╛╨╗╤М╨║╨╛ ╨┐╨╛ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╤Г ╤В╨╡╨║╤Б╤В╨░) ╨┐╤А╨╕╨╝╨╡╨╜╤П╤О╤В╤Б╤П ╨║ ╨▓╨╡╨▒-╤Б╤В╤А╨╛╨║╨░╨╝ ╤А╨╛╨▓╨╜╨╛ ╨║╨░╨║ ╨║ ╤В╨╡╨╗╨╡╨│╤А╨░╨╝╨╜╤Л╨╝ тАФ ╨╜╨╡ ┬л╨┐╨╛╤Е╨╛╨╢╨╡┬╗, ╨░ ╤В╨╡╨╝ ╨╢╨╡ ╨▓╤Л╤А╨░╨╢╨╡╨╜╨╕╨╡╨╝. ╨Ч╨░╨┐╨╕╤Б╤М тАФ ╨┐╨╛╤З╨░╤Б╨╛╨▓╨░╤П `support:rollup-web` ╤Б ╨┐╨╡╤А╨╡╨║╤А╤Л╤В╨╕╨╡╨╝ ╨╛╨║╨╜╨░ (╨┐╨╛╤А╨╛╨│ ┬л╨▓╨╕╤Б╨╕╤В N ╤З╨░╤Б╨╛╨▓┬╗ ╤Г ╨▓╤З╨╡╤А╨░╤И╨╜╨╡╨│╨╛ ╤А╨░╨╖╨│╨╛╨▓╨╛╤А╨░ ╨┤╨╛╨╖╤А╨╡╨▓╨░╨╡╤В ╤В╨╛╨╗╤М╨║╨╛ ╤Б╨╡╨│╨╛╨┤╨╜╤П), ╨╖╨░ ╤Д╨╗╨░╨│╨╛╨╝ **`features.support_web_rollups`, ╨Т╨л╨Ъ╨Ы ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О**: ╨┐╨╛╨║╨░ ╤Д╨╗╨░╨│ OFF ╨║╨╛╨╝╨░╨╜╨┤╨░ ╤З╨╡╤Б╤В╨╜╨╛ ╨╜╨╕╤З╨╡╨│╨╛ ╨╜╨╡ ╨┐╨╕╤И╨╡╤В, ╨▓╨╡╨▒-╤Б╤В╤А╨╛╨║ ╨╜╨╡ ╨┐╨╛╤П╨▓╨╗╤П╨╡╤В╤Б╤П, ╨╕ ╨▓╤Б╨╡ ╨┤╨░╤И╨▒╨╛╤А╨┤╤Л ╨┐╨╛╨║╨░╨╖╤Л╨▓╨░╤О╤В ╤В╨╡ ╨╢╨╡ TG-╤З╨╕╤Б╨╗╨░, ╤З╤В╨╛ ╨╕ ╨┤╨╛ H1837. ╨Ф╨╡╨╜╨╡╨╢╨╜╤Л╨╣ ╨║╨╛╨╜╤В╤Г╤А ╨╕ `PaymentObserver` ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В╤Л. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.68.0] - 2026-07-29

### Fixed
- **╨Я╨╛╨┐╤А╨░╨▓╨║╨░ ╨║ ╤А╨░╨╖╨▒╨╛╤А╤Г ╨┐╤А╨╛╤Б╤В╨╛╤П: ╨▓╨╜╨╡╤И╨╜╨╕╨╣ Telegram-╨╝╨╛╨╜╨╕╤В╨╛╤А╨╕╨╜╨│ ╨а╨Р╨С╨Ю╨в╨Р╨Ы, ╨▓╤Л╨▓╨╛╨┤ ╨╛╨▒ ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╨╕ ╤Б╨╡╨║╤А╨╡╤В╨╛╨▓ ╨▒╤Л╨╗ ╨╛╤И╨╕╨▒╨╛╤З╨╜╤Л╨╝ (H1904).** ╨Т [v1.65.0](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.65.0) ╤Г╤В╨▓╨╡╤А╨╢╨┤╨░╨╗╨╛╤Б╤М, ╤З╤В╨╛ Telegram-╤И╨░╨│ ╨╝╨╛╨╜╨╕╤В╨╛╤А╨░ ╨╜╨╡ ╤Б╤А╨░╨▒╨╛╤В╨░╨╗, ╨┐╨╛╤В╨╛╨╝╤Г ╤З╤В╨╛ ╨▓ ╤А╨╡╨┐╨╛╨╖╨╕╤В╨╛╤А╨╕╨╕ ╨╜╨╡╤В Actions-╤Б╨╡╨║╤А╨╡╤В╨╛╨▓. ╨Ю╤Б╨╜╨╛╨▓╨░╨╜╨╕╨╡╨╝ ╨▒╤Л╨╗ ╨┐╤Г╤Б╤В╨╛╨╣ ╨▓╤Л╨▓╨╛╨┤ `gh secret list` тАФ ╨╜╨╛ ╤Г ╤В╨╛╨║╨╡╨╜╨░ ╨┐╤А╨╛╤Б╤В╨╛ ╨╜╨╡╤В ╨┐╤А╨░╨▓╨░ ╤З╨╕╤В╨░╤В╤М ╤Б╨╡╨║╤А╨╡╤В╤Л, ╨╕ **╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╨╡ ╨▓╤Л╨▓╨╛╨┤╨░ ╨▒╤Л╨╗╨╛ ╨┐╤А╨╕╨╜╤П╤В╨╛ ╨╖╨░ ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╨╡ ╤Б╨╡╨║╤А╨╡╤В╨╛╨▓**. ╨Я╤А╨╛╨▓╨╡╤А╨║╨░ ╨┐╨╛ ╤Б╨░╨╝╨╕╨╝ ╨┐╤А╨╛╨│╨╛╨╜╨░╨╝ (`gh run view --json jobs`) ╨╛╨┐╤А╨╛╨▓╨╡╤А╨│╨░╨╡╤В ╤Н╤В╨╛: ╤И╨░╨│ ┬л╨б╨╛╨╛╨▒╤Й╨╕╤В╤М ╨▓ Telegram ╨╛ ╨┐╤А╨╛╤Б╤В╨╛╨╡┬╗ ╨▓ ╨┐╤А╨╛╨│╨╛╨╜╨╡ `30392777459` тАФ `success`, ╤И╨░╨│ ┬л╨Ч╨░╨║╤А╤Л╤В╤М ╤В╤А╨╡╨▓╨╛╨│╤Г ╨╕ ╤Б╨╛╨╛╨▒╤Й╨╕╤В╤М ╨╛ ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╨╕┬╗ ╨▓ ╨┐╤А╨╛╨│╨╛╨╜╨╡ `30466135990` тАФ `success`. ╨Ч╨░ ╨┐╤А╨╛╤Б╤В╨╛╨╣ ╤Г╤И╨╗╨╛ ╤А╨╛╨▓╨╜╨╛ **╨┤╨▓╨░** ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╤П, ╨║╨░╨║ ╨╕ ╨╖╨░╨┤╤Г╨╝╨░╨╜╨╛ ╨░╨╜╤В╨╕-╤Б╨┐╨░╨╝-╨╗╨╛╨│╨╕╨║╨╛╨╣. ╨Э╨░╤Б╤В╨╛╤П╤Й╨░╤П ╨┐╤А╨╛╨▒╨╗╨╡╨╝╨░ ╨╜╨╡ ┬л╨╛╨┐╨╛╨▓╨╡╤Й╨╡╨╜╨╕╤П ╨╜╨╡ ╨┐╤А╨╕╤Е╨╛╨┤╤П╤В┬╗, ╨░ **┬л╨┐╤А╨╕╤Е╨╛╨┤╤П╤В ╨┐╨╛╨╖╨┤╨╜╨╛┬╗**: ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡ ╤Г╤И╨╗╨╛ ╨▓ 22:44, ╤З╨╡╤А╨╡╨╖ 1 ╤З 19 ╨╝╨╕╨╜ ╨┐╨╛╤Б╨╗╨╡ ╤В╨╛╨│╨╛, ╨║╨░╨║ ╨▓ 21:25 ╨▓╤Б╤В╨░╨╗ ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║, ╨╕ ╤В╨╛╨╗╤М╨║╨╛ ╨┐╨╛╤В╨╛╨╝╤Г, ╤З╤В╨╛ ╨║ ╤В╨╛╨╝╤Г ╨▓╤А╨╡╨╝╨╡╨╜╨╕ ╨┐╨╛╨│╨░╤Б ╤Б╨░╨╣╤В. ╨б╨╝╨╡╤А╤В╤М ╤Б╨░╨╝╨╛╨│╨╛ ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║╨░ ╨╖╨░╨╝╨╡╤В╨╕╤В╤М ╨▒╤Л╨╗╨╛ ╨╜╨╡╤З╨╡╨╝ тАФ ╨╡╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╨╛╨╡, ╤З╤В╨╛ ╨╝╨╛╨│╨╗╨╛ ╨╡╤С ╤Г╨▓╨╕╨┤╨╡╤В╤М (`cabinet:probe`), ╨╜╨░╤Е╨╛╨┤╨╕╨╗╨╛╤Б╤М ╨▓╨╜╤Г╤В╤А╨╕ ╨╜╨╡╨│╨╛. ╨н╤В╨╛ ╤А╨╛╨▓╨╜╨╛ ╤В╨╛, ╤З╤В╨╛ ╤З╨╕╨╜╨╕╤В ╨▓╤Л╨╜╨╛╤Б ╤Б╤В╨╛╤А╨╛╨╢╨╡╨╣ ╨▓ ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╡ ╤Б╤В╤А╨╛╨║╨╕ cron (v1.67.0), ╨╕ ╤В╨╛, ╤А╨░╨┤╨╕ ╤З╨╡╨│╨╛ ╨╜╤Г╨╢╨╡╨╜ ╨┐╤Г╨╗╤М╤Б ╨╜╨░ healthchecks.io. ╨г╤А╨╛╨║, ╨▓╤Л╨╜╨╡╤Б╨╡╨╜╨╜╤Л╨╣ ╨▓ ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В: **╨▓╤Л╨▓╨╛╨┤ ╨╕╨╜╤Б╤В╤А╤Г╨╝╨╡╨╜╤В╨░ ╨▒╨╡╨╖ ╨┐╤А╨░╨▓ тЙа ╤Д╨░╨║╤В ╨╛ ╨╝╨╕╤А╨╡** тАФ ╨┐╤А╨╛╨▓╨╡╤А╤П╤В╤М ╨┐╨╛ ╤Б╨╗╨╡╨┤╨░╨╝ ╤Б╨░╨╝╨╛╨│╨╛ ╨┤╨╡╨╣╤Б╤В╨▓╨╕╤П, ╨░ ╨╜╨╡ ╨┐╨╛ ╤В╨╛╨╝╤Г, ╤З╤В╨╛ ╨▓╨╕╨┤╨╜╨╛ ╨╜╨░╨▒╨╗╤О╨┤╨░╤В╨╡╨╗╤О. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.67.0] - 2026-07-29

### Fixed
- **╨б╤В╨╛╤А╨╛╨╢ ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨┤╨╡╨╗╨╕╤В ╤Б╤Г╨┤╤М╨▒╤Г ╤Б ╤В╨╡╨╝, ╤З╤В╨╛ ╤Б╤В╨╛╤А╨╛╨╢╨╕╤В (H1904, ╨┤╨╛╨┐╨╛╨╗╨╜╨╡╨╜╨╕╨╡).** ╨а╨░╨╖╨▒╨╛╤А ╨┐╤А╨╛╤Б╤В╨╛╤П 28тАУ29.07 ╨┤╨░╨╗ ╤В╨╛╤З╨╜╤Л╨╣ ╤Б╨┐╤Г╤Б╨║╨╛╨▓╨╛╨╣ ╨║╤А╤О╤З╨╛╨║: ╨╛╨┤╨╕╨╜ ╨╖╨░╤Е╨╛╨┤ `telegram-support:sync` ╨┐╤А╨╛╨╢╨╕╨╗ **10 470 ╤Б (2 ╤З 54 ╨╝╨╕╨╜ 30 ╤Б) ╨▓╨╝╨╡╤Б╤В╨╛ ╤И╤В╨░╤В╨╜╤Л╤Е 36 ╤Б** ╨╕ ╨┤╨╡╤А╨╢╨░╨╗ `schedule:run` ╨▓ foreground; `cron` ╨╖╨░ ╤Н╤В╨╛ ╨▓╤А╨╡╨╝╤П ╨╖╨░╨▓╤С╨╗ ~174 ╤Ж╨╡╨┐╨╛╤З╨║╨╕ ╨┐╨╛ ~200 ╨Ь╨С, ╨╕ ╨▓ 00:19:34 ╤П╨┤╤А╨╛ ╤Г╨▒╨╕╨╗╨╛ `cron` (╤В╤А╨╕ `Killed` ╨┐╨╛╨┤╤А╤П╨┤ ╨▓ `schedule.log`). ╨Ю╤В╤Б╤О╨┤╨░ ╨│╨╗╨░╨▓╨╜╨╛╨╡: **╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║ ╨▓╤Б╤В╨░╨╗ ╨▓ 21:25, ╨░ ╤Б╨░╨╣╤В ╨┐╨╛╨│╨░╤Б ╤В╨╛╨╗╤М╨║╨╛ ╨▓ 22:37** тАФ ╨░ `cabinet:probe`, ╨║╨╛╤В╨╛╤А╤Л╨╣ ╤И╨╗╤С╤В ╤В╤А╨╡╨▓╨╛╨│╤Г ╨▓ Telegram, ╤Б╤В╨╛╨╕╤В `*/15` **╨▓╨╜╤Г╤В╤А╨╕ ╤В╨╛╨│╨╛ ╨╢╨╡ `schedule:run`** ╨╕ ╨╖╨░ ╤Н╤В╨╕ ╤В╤А╨╕ ╤З╨░╤Б╨░ ╨╜╨╡ ╨▓╤Л╨┐╨╛╨╗╨╜╨╕╨╗╤Б╤П ╨╜╨╕ ╤А╨░╨╖╤Г. ╨Ю╨┐╨╛╨▓╨╡╤Й╨╡╨╜╨╕╨╡ ╨▒╤Л╨╗╨╛ ╨╜╨░╤Б╤В╤А╨╛╨╡╨╜╨╛ ╨╕ ╤А╨░╨▒╨╛╤В╨░╨╗╨╛ тАФ ╨╛╨╜╨╛ ╤Г╨╝╨╡╤А╨╗╨╛ ╨╜╨░ ╤З╨░╤Б ╤А╨░╨╜╤М╤И╨╡ ╤Б╨░╨╣╤В╨░. ╨Э╨╛╨▓╤Л╨╣ `/usr/local/sbin/systema-watchdog-run.sh` ╨╕ ╨┤╨▓╨╡ ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╡ ╤Б╤В╤А╨╛╨║╨╕ cron ╨┤╨░╤О╤В `cabinet:probe` (`*/15`) ╨╕ `heartbeat:ping` (`*/5`) ╤Б╨▓╨╛╨╣ ╨╖╨░╨╝╨╛╨║, ╤Б╨▓╨╛╨╣ ╨║╨╛╤А╨╛╤В╨║╨╕╨╣ ╤В╨░╨╣╨╝╨░╤Г╤В ╨╕ ╤Б╨▓╨╛╤О ╤Б╤Г╨┤╤М╨▒╤Г тАФ ╨▓╨╕╤Б╤П╤Й╨░╤П ╨║╨╛╨╝╨░╨╜╨┤╨░ ╨╕╤Е ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨│╨╗╤Г╤И╨╕╤В. ╨Ф╤Г╨▒╨╗╨╕╤А╨╛╨▓╨░╨╜╨╕╨╡ ╤Б ╨║╨╛╨┐╨╕╤П╨╝╨╕ ╨▓ `Kernel.php` ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛: ╤Б╤В╨╛╤А╨╛╨╢, ╨╛╤В╤А╨░╨▒╨╛╤В╨░╨▓╤И╨╕╨╣ ╨┤╨▓╨░╨╢╨┤╤Л, тАФ ╨╜╨╡ ╨┐╤А╨╛╨▒╨╗╨╡╨╝╨░; ╤Б╤В╨╛╤А╨╛╨╢, ╨╜╨╡ ╨╛╤В╤А╨░╨▒╨╛╤В╨░╨▓╤И╨╕╨╣ ╨╜╨╕ ╤А╨░╨╖╤Г, тАФ ╤Н╤В╨╛ ╨░╨▓╨░╤А╨╕╤П ╨▓╤Л╤И╨╡. ╨Я╨╛╨┐╤Г╤В╨╜╨╛ ╨╕╨╖╨╝╨╡╤А╨╡╨╜╨╛, ╤З╤В╨╛ watchdog ╤Б╨░╨╝╨╛╨╣ ╨║╨╛╨╝╨░╨╜╨┤╤Л ╨╜╨╡ ╤А╨░╨▒╨╛╤В╨░╨╡╤В (10 470 ╤Б ╨┐╤А╨╕ ╨┐╨╛╤В╨╛╨╗╨║╨╡ 120 ╤Б, ╨┐╤А╨╡╨▓╤Л╤И╨╡╨╜╨╕╨╡ ╨▓ 87 ╤А╨░╨╖, ╨┐╤А╨╕ ╨╖╨░╨│╤А╤Г╨╢╨╡╨╜╨╜╨╛╨╝ `pcntl`) тАФ ╨╕╨╜╨▓╨░╤А╨╕╨░╨╜╤В `Kernel.php` ┬л╨╖╨░╨▓╨╕╤Б╤И╨╕╨╣ ╨╖╨░╤Е╨╛╨┤ ╤Г╨╝╨╕╤А╨░╨╡╤В ╤А╨░╨╜╤М╤И╨╡ ╨╖╨░╨╝╨║╨░┬╗ ╨╜╨╡ ╨▓╤Л╨┐╨╛╨╗╨╜╤П╨╡╤В╤Б╤П; ╨▓╤Л╨╜╨╡╤Б╨╡╨╜╨╛ ╨▓ [#840](https://github.com/gasyoun/Systema-Sanscriticum/issues/840). Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.66.0] - 2026-07-29

### Added
- **╨Ч╨░╨┤╨░╤З╨░ ╨╝╨╡╨╜╨╡╨┤╨╢╨╡╤А╤Г ╤Б╤В╨░╨╗╨░ ╨╛╨▒╤К╨╡╨║╤В╨╛╨╝ тАФ `FollowUpTask` (GC-C3, H1836).** ┬л╨б╨╗╨╡╨┤╤Г╤О╤Й╨╕╨╣ ╨║╨╛╨╜╤В╨░╨║╤В┬╗ ╨╢╨╕╨╗ ╨┐╨░╤А╨╛╨╣ ╨┐╨╛╨╗╨╡╨╣ ╨╜╨░ ╨╗╨╕╨┤╨╡ (`leads.next_contact_at` + `leads.assigned_to`): ╨╛╨┤╨╕╨╜ ╨║╨╛╨╜╤В╨░╨║╤В ╨╜╨░ ╤З╨╡╨╗╨╛╨▓╨╡╨║╨░, ╨▒╨╡╨╖ ╤В╨╕╨┐╨░, ╨▒╨╡╨╖ ╨╛╤В╨╝╨╡╤В╨║╨╕ ┬л╤Б╨┤╨╡╨╗╨░╨╜╨╛┬╗, ╨▒╨╡╨╖ ╨╕╤Б╤В╨╛╤А╨╕╨╕. ╨в╨╡╨┐╨╡╤А╤М ╤Н╤В╨╛ ╤Б╤В╤А╨╛╨║╨░ ╨▓ ╨╜╨╛╨▓╨╛╨╣ ╤В╨░╨▒╨╗╨╕╤Ж╨╡ `follow_up_tasks` тАФ ╤В╨╕╨┐ (╨┐╨╛╨╖╨▓╨╛╨╜╨╕╤В╤М/╨╜╨░╨┐╨╕╤Б╨░╤В╤М/╨▓╤Б╤В╤А╨╡╤З╨░/╨┤╤А╤Г╨│╨╛╨╡), ╤Б╤А╨╛╨║ `due_at`, ╤Д╨░╨║╤В ╨╖╨░╨║╤А╤Л╤В╨╕╤П `done_at` тАФ ╨╕ ╨▓╨╕╤Б╨╕╤В ╨╛╨╜╨░ ╨╜╨░ **╤Б╨┤╨╡╨╗╨║╨╡** ([`Deal`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Deal.php), GC-C1/H1641), ╨░ ╨╜╨╡ ╨╜╨░ ╨╗╨╕╨┤╨╡: ╨┐╨╛╤Б╨╗╨╡ GC-C1 ╨╡╨┤╨╕╨╜╨╕╤Ж╨░ ╤А╨░╨▒╨╛╤В╤Л ╨▓╨╛╤А╨╛╨╜╨║╨╕ тАФ ╤Б╨┤╨╡╨╗╨║╨░, ╨╕ ╤Г ╨╛╨┤╨╜╨╛╨│╨╛ ╤З╨╡╨╗╨╛╨▓╨╡╨║╨░ ╨╕╤Е ╨╝╨╛╨╢╨╡╤В ╨▒╤Л╤В╤М ╨╜╨╡╤Б╨║╨╛╨╗╤М╨║╨╛. [`WorkQueueReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/WorkQueueReport.php) ╨┐╨╛╨╗╤Г╤З╨╕╨╗ **╨┐╤П╤В╤Л╨╣** ╨▒╨░╨║╨╡╤В ┬л╨╖╨░╨┤╨░╤З╨╕ ╨┐╨╛ ╤Б╨┤╨╡╨╗╨║╨░╨╝ ╨╜╨░ ╤Б╨╡╨│╨╛╨┤╨╜╤П┬╗ ╤В╨╡╨╝ ╨╢╨╡ ╨┐╨░╤В╤В╨╡╤А╨╜╨╛╨╝, ╤З╤В╨╛ ╨╕ ╤З╨╡╤В╤Л╤А╨╡ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╤Е (╤Б╤А╨░╨▓╨╜╨╡╨╜╨╕╨╡ ╨┐╨╛ ╨Ф╨Р╨в╨Х, ╨╛╨▒╤Й╨╕╨╣ `LIMIT`, ╤Б╨╛╤А╤В╨╕╤А╨╛╨▓╨║╨░ ╨┐╨╛ ╤Б╤А╨╛╨║╤Г), ╨┐╨╗╤О╤Б ╨║╨░╤А╤В╨╛╤З╨║╤Г ╤Б ╨║╨╜╨╛╨┐╨║╨╛╨╣ ┬л╨У╨╛╤В╨╛╨▓╨╛┬╗ ╨▓ ╨║╨╛╨║╨┐╨╕╤В╨╡ ┬л╨Ь╨╛╤П ╤А╨░╨▒╨╛╤В╨░ ╤Б╨╡╨│╨╛╨┤╨╜╤П┬╗. ╨д╨╗╨░╨│ **╨╜╨╛╨▓╤Л╨╣ тАФ `crm_follow_up_tasks`**, ╨░ ╨╜╨╡ ╨┐╨╡╤А╨╡╨╕╤Б╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╨╜╨╜╤Л╨╣ `crm_reminders`: ╤Б╨┐╨╡╨║╨░ [┬з7 F6](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md) ╨┐╤А╤П╨╝╨╛ ╤Б╤В╨░╨▓╨╕╤В ╤Н╤В╤Г ╤А╨░╨╖╨▓╨╕╨╗╨║╤Г, ╨╕ `crm_reminders` ╨│╨╡╨╣╤В╨╕╤В ╨║╨╛╨╝╨░╨╜╨┤╤Г `leads:remind-followup`, ╨║╨╛╤В╨╛╤А╨░╤П ╨б╨Р╨Ь╨Р ╨Я╨Ш╨и╨Х╨в ╨╗╤О╨┤╤П╨╝ ╨▓ Telegram тАФ ╤А╨░╤Б╤И╨╕╤А╤П╤В╤М ╨╛╨┤╨╕╨╜ ╤А╤Г╨▒╨╕╨╗╤М╨╜╨╕╨║ ╨╜╨░ ╨┤╨▓╨╡ ╤А╨░╨╖╨╜╤Л╨╡ ╨┐╨╛ ╤А╨╕╤Б╨║╤Г ╨┐╨╛╨▓╨╡╤А╤Е╨╜╨╛╤Б╤В╨╕ ╨╜╨╡╨╗╤М╨╖╤П. ╨Я╨╛╨▓╨╡╨┤╨╡╨╜╨╕╨╡ `leads:remind-followup` ╨╜╨╡ ╨╕╨╖╨╝╨╡╨╜╨╕╨╗╨╛╤Б╤М ╨╜╨╕ ╨▓ ╨╛╨┤╨╜╤Г ╤Б╤В╨╛╤А╨╛╨╜╤Г, ╨╕ ╤Н╤В╨╛ ╨╖╨░╨║╤А╨╡╨┐╨╗╨╡╨╜╨╛ ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╨╡╨╣ ╨▓ ╨╛╨▒╨╡ ╤Б╤В╨╛╤А╨╛╨╜╤Л (╨╖╨░╨┤╨░╤З╨╕ ╨Т╨Ъ╨Ы + ╨╜╨░╨┐╨╛╨╝╨╕╨╜╨░╨╜╨╕╤П ╨Т╨л╨Ъ╨Ы тЖТ ╨║╨╛╨╝╨░╨╜╨┤╨░ no-op; ╨╖╨░╨┤╨░╤З╨╕ ╨Т╨л╨Ъ╨Ы + ╨╜╨░╨┐╨╛╨╝╨╕╨╜╨░╨╜╨╕╤П ╨Т╨Ъ╨Ы тЖТ ╨┐╨╕╨╜╨│ ╤Г╤Е╨╛╨┤╨╕╤В ╨║╨░╨║ ╤А╨░╨╜╤М╤И╨╡). ╨Ч╨░╨║╤А╤Л╤В╨░╤П ╤Б╨┤╨╡╨╗╨║╨░ ╨╖╨░╨┤╨░╤З╤Г **╨╜╨╡** ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В тАФ ╨╡╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ ╨┐╤А╨╕╨╖╨╜╨░╨║ ┬л╤Б╨┤╨╡╨╗╨░╨╜╨╛┬╗ ╤Н╤В╨╛ `done_at`, ╨╕╨╜╨░╤З╨╡ ╤Г ┬л╨┐╨╛╤З╨╡╨╝╤Г ╨╖╨░╨┤╨░╤З╨░ ╨┐╤А╨╛╨┐╨░╨╗╨░┬╗ ╨▒╤Л╨╗╨╛ ╨▒╤Л ╨┤╨▓╨░ ╨╛╤В╨▓╨╡╤В╨░. ╨Т╨л╨Ъ╨Ы ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О (`CRM_FOLLOW_UP_TASKS`): ╨┐╨╛╨║╨░ ╤Д╨╗╨░╨│ OFF, ╨▒╨░╨║╨╡╤В ╨┐╤Г╤Б╤В, ╨║ `follow_up_tasks` ╨╜╨╡ ╤Г╤Е╨╛╨┤╨╕╤В ╨╜╨╕ ╨╛╨┤╨╜╨╛╨│╨╛ ╨╖╨░╨┐╤А╨╛╤Б╨░, ╨░ ╨┐╤П╤В╨░╤П ╨║╨░╤А╤В╨╛╤З╨║╨░ ╨╜╨╡ ╤А╨╡╨╜╨┤╨╡╤А╨╕╤В╤Б╤П тАФ ╨┤╨╡╤Д╨╛╨╗╤В ╨╖╨░╨┐╨╕╨╜╨╜╨╡╨╜ ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╝ ╤В╨╡╤Б╤В╨╛╨╝ ╨┐╨╛ ╨┐╤А╨╡╤Ж╨╡╨┤╨╡╨╜╤В╤Г `DealFlagDefaultTest`. ╨Ф╨╡╨╜╨╡╨╢╨╜╨╛╨╡ ╤П╨┤╤А╨╛ ╨╕ `crm_pipeline_board` ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В╤Л. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.65.0] - 2026-07-29

### Fixed
- **╨Я╤А╨╛╨┤ ╨┐╨╡╤А╨╡╤Б╤В╨░╤С╤В ╨╖╨░╨▓╨╕╤Б╨░╤В╤М: ╨╜╨░╨╣╨┤╨╡╨╜╨░ ╨╕ ╨╛╨▒╨╡╨╖╨▓╤А╨╡╨╢╨╡╨╜╨░ ╨┐╤А╨╕╤З╨╕╨╜╨░ ╨┐╤А╨╛╤Б╤В╨╛╨╡╨▓ 23тАУ24.07 ╨╕ 28тАУ29.07.2026 (H1904).** ╨а╨░╨╖╨▒╨╛╤А тАФ [`docs/server-resource-guards.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md). ╨Ю╨▒╨░ ╤А╨░╨╖╨░ ╨║╨╛╨╜╤В╨╡╨╣╨╜╨╡╤А **╨╜╨╡ ╨▓╤Л╨║╨╗╤О╤З╨░╨╗╤Б╤П, ╨░ ╨╖╨░╨▓╨╕╤Б╨░╨╗**: `cron` ╨╖╨░╨▓╨╛╨┤╨╕╤В `php artisan schedule:run` ╨║╨░╨╢╨┤╤Г╤О ╨╝╨╕╨╜╤Г╤В╤Г **╨▒╨╡╨╖ ╨╖╨░╨╝╨║╨░**, ╨░ `$schedule->command()` ╨╢╨┤╤С╤В ╨║╨╛╨╝╨░╨╜╨┤╤Г ╨▓ foreground тАФ ╨╖╨╜╨░╤З╨╕╤В ╨╛╨┤╨╕╨╜ ╨╝╨╡╨┤╨╗╨╡╨╜╨╜╤Л╨╣ ╨╖╨░╤Е╨╛╨┤ ╨┐╨╡╤А╨╡╨╜╨╛╤Б╨╕╤В╤Б╤П ╨╜╨░ ╤Б╨╗╨╡╨┤╤Г╤О╤Й╤Г╤О ╨╝╨╕╨╜╤Г╤В╤Г. `telegram-support:sync` (`everyMinute`) ╤А╨╡╨░╨╗╤М╨╜╨╛ ╨╕╨┤╤С╤В **36тАУ41 ╤Б**, `telegram-harvest:roster-groups` тАФ ╨┤╨╛ 600 ╤Б; ╨║╨░╨║ ╤В╨╛╨╗╤М╨║╨╛ ╨╖╨░╤Е╨╛╨┤ ╨┐╨╡╤А╨╡╨▓╨░╨╗╨╕╨╗ ╨╖╨░ 60 ╤Б, `schedule:run` ╨╜╨░╤З╨░╨╗ ╨║╨╛╨┐╨╕╤В╤М╤Б╤П ╨┐╨╛ ~100 ╨Ь╨С ╨╜╨░ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б. ╨Я╨╛╤В╨╛╨╗╨║╨╛╨▓ ╨╜╨╡ ╨▒╤Л╨╗╨╛ ╨╜╨╕ ╨╛╨┤╨╜╨╛╨│╨╛: `memory_limit = -1` ╨▓ CLI, `pids.max = max`, ╤Б╨▓╨╛╨┐╨░ ╨╜╨╡╤В, `earlyoom` ╨╜╨╡ ╤Б╤В╨╛╤П╨╗. ╨Ф╨╕╨░╨│╨╜╨╛╨╖ ╨┤╨╛╨║╨░╨╖╨░╨╜ ╨╢╤Г╤А╨╜╨░╨╗╨╛╨╝, ╨░ ╨╜╨╡ ╤А╨░╤Б╤Б╤Г╨╢╨┤╨╡╨╜╨╕╨╡╨╝: `cron.service: A process of this unit has been killed by the OOM killer` (23-07 15:38 ╨╕ 29-07 00:19:33 ╨Ь╨б╨Ъ) ╨╕ ╤Б╨╗╨╡╨┤╨╛╨╝ `Found left-over process 114146тАж114167 (php)` тАФ ╤П╨┤╤А╨╛ ╤Г╨▒╨╕╨╗╨╛ **╤Б╨░╨╝ cron**, systemd ╨┐╨╡╤А╨╡╨╖╨░╨┐╤Г╤Б╤В╨╕╨╗ ╤О╨╜╨╕╤В ╨┐╨╛╨▓╨╡╤А╤Е ~20 ╨╛╤Б╨╕╤А╨╛╤В╨╡╨▓╤И╨╕╤Е ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╛╨▓, ╨┐╨░╨╝╤П╤В╤М ╨╜╨╡ ╨╛╤Б╨▓╨╛╨▒╨╛╨┤╨╕╨╗╨░╤Б╤М, ╨╕ ╨║╨╛╨╜╤В╨╡╨╣╨╜╨╡╤А ╤Г╤И╤С╨╗ ╨▓ livelock (`load average` **370**, journald ╨╖╨░╨┐╨╕╤Б╨░╨╗ ╤Б╨▓╨╛╤О ╨╢╨╡ ╤Б╤В╤А╨╛╨║╤Г ╤Б ╨╛╨┐╨╛╨╖╨┤╨░╨╜╨╕╨╡╨╝ ╨╜╨░ 2 ╤З 47 ╨╝╨╕╨╜). `->withoutOverlapping()` ╤В╤Г╤В ╨╜╨╡ ╨┐╨╛╨╝╨╛╨│╨░╨╡╤В ╨╕ ╨╜╨╡ ╨┤╨╛╨╗╨╢╨╡╨╜: ╨╛╨╜ ╨╖╨░╤Й╨╕╤Й╨░╨╡╤В ╨║╨╛╨╝╨░╨╜╨┤╤Г ╨╛╤В ╤Б╨░╨╝╨╛╨╣ ╤Б╨╡╨▒╤П ╨╕ ╨╜╨╕╨║╨░╨║ ╨╜╨╡ ╨╛╨│╤А╨░╨╜╨╕╤З╨╕╨▓╨░╨╡╤В ╤Б╨░╨╝ `schedule:run`. ╨Я╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╨╛ ╨╜╨░ ╨┐╤А╨╛╨┤ (╤Г╤А╨╛╨▓╨╡╨╜╤М ╨Ю╨б, ╨┤╨╡╨┐╨╗╨╛╨╣ ╨╜╨╡ ╤В╤А╨╡╨▒╤Г╨╡╤В╤Б╤П): ╨╛╨▒╤С╤А╤В╨║╨░ `/usr/local/sbin/systema-schedule-run.sh` тАФ `flock -n` (╨╛╨┤╨╜╨╛╨▓╤А╨╡╨╝╨╡╨╜╨╜╨╛ ╤А╨╛╨▓╨╜╨╛ ╨╛╨┤╨╕╨╜ ╨┐╤А╨╛╨│╨╛╨╜, ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜╨╛: ╨▓╤В╨╛╤А╨╛╨╣ ╨▓╤Л╨╖╨╛╨▓ ╨┐╨╡╤З╨░╤В╨░╨╡╤В `SKIP` ╨╕ ╨▓╤Л╤Е╨╛╨┤╨╕╤В ╤Б 0), `timeout 900s`, ╨╢╨╜╨╡╤Ж ╨╛╤Б╨╕╤А╨╛╤В╨╡╨▓╤И╨╕╤Е `artisan`-╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╛╨▓ ╤Б ╤П╨▓╨╜╤Л╨╝ ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╨╡╨╝ ╨┤╨╡╨╝╨╛╨╜╨╛╨▓ (`horizon`/`reverb`/`queue:work`/MadelineProto); `TasksMax=200` + `OOMPolicy=kill` + `Restart=always` ╨╜╨░ `cron.service` (╨┐╤А╨╕ OOM ╤Б╨╜╨╛╤Б╨╕╤В╤Б╤П **╨▓╤Б╤П ╨│╤А╤Г╨┐╨┐╨░** тАФ ╤Б╨╕╤А╨╛╤В ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨╛╤Б╤В╨░╤С╤В╤Б╤П); `earlyoom` (SIGTERM <10 %, SIGKILL <5 %, `--prefer ^php[0-9.]*$`, `--avoid` ╨┤╨╗╤П mariadbd/nginx/sshd/redis/supervisord); `pm.max_children` 5 тЖТ 12 ╨╕ `pm.max_requests = 500` ╨┤╨╗╤П php-fpm (╨┐╤П╤В╤М ╨▓╨╛╤А╨║╨╡╤А╨╛╨▓ ╨╜╨░ 16 ╨У╨╕╨С тАФ ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╣ ╨╛╤В╨║╨░╨╖ ╨┐╨╛ ╨┤╨╛╤Б╤В╤Г╨┐╨╜╨╛╤Б╤В╨╕). ╨Ч╨╜╨░╤З╨╡╨╜╨╕╤П `MemoryMax` ╨╜╨░ cron/supervisor ╨╕ `memory_limit = 768M` ╨▓ CLI ╨┐╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╤Л ╨░╨┤╨╝╨╕╨╜╨╕╤Б╤В╤А╨░╤В╨╛╤А╨╛╨╝ ╨▓ ╤В╨╛╤В ╨╢╨╡ ╨┤╨╡╨╜╤М ╨╕ ╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╤Л ╨║╨░╨║ ╨╡╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║ ╨┐╤А╨░╨▓╨┤╤Л тАФ ╨┤╤Г╨▒╨╗╨╕╤А╤Г╤О╤Й╨░╤П ╨┤╨╕╤А╨╡╨║╤В╨╕╨▓╨░ ╤Б╨╜╤П╤В╨░, ╤З╤В╨╛╨▒╤Л ╤Н╤Д╤Д╨╡╨║╤В╨╕╨▓╨╜╨╛╨╡ ╨╖╨╜╨░╤З╨╡╨╜╨╕╨╡ ╨╜╨╡ ╨╖╨░╨▓╨╕╤Б╨╡╨╗╨╛ ╨╛╤В ╨┐╨╛╤А╤П╨┤╨║╨░ ╤Б╨╛╤А╤В╨╕╤А╨╛╨▓╨║╨╕ drop-in-╤Д╨░╨╣╨╗╨╛╨▓. **╨б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛ ╨╜╨╡ ╤Б╨┤╨╡╨╗╨░╨╜╨╛:** `->runInBackground()` ╨╜╨░ ╨┤╨╛╨╗╨│╨╕╤Е ╨║╨╛╨╝╨░╨╜╨┤╨░╤Е ╨▓ [`Kernel.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php) тАФ ╨┐╤А╨░╨▓╨║╨░ ╤В╤А╨╛╨│╨░╨╡╤В ╨╗╨╛╨│╨╕╨║╤Г TTL ╨╖╨░╨╝╨║╨╛╨▓ MTProto-╤Б╨╡╤Б╤Б╨╕╨╕, ╨▓╨╛╨║╤А╤Г╨│ ╨║╨╛╤В╨╛╤А╨╛╨╣ ╨▓╤Л╤Б╤В╤А╨╛╨╡╨╜╨╛ ╤А╨░╤Б╤Б╤Г╨╢╨┤╨╡╨╜╨╕╨╡ ╨┐╨╛╤Б╨╗╨╡ ╨░╨▓╨░╤А╨╕╨╕ 27-07, ╨░ ╨┤╨▓╨░ ╤Н╨║╨╖╨╡╨╝╨┐╨╗╤П╤А╨░ ╨╜╨░ ╨╛╨┤╨╜╨╛╨╣ ╤Б╨╡╤Б╤Б╨╕╨╕ MadelineProto ╨┤╨░╤О╤В `AUTH_RESTART`; ╨▓╤Л╨╜╨╡╤Б╨╡╨╜╨╛ ╨▓╨╗╨░╨┤╨╡╨╗╤М╤Ж╤Г ╨┐╨╛╨┤╤Б╨╕╤Б╤В╨╡╨╝╤Л. Executor: Opus 5 1M (`claude-opus-5[1m]`).

### Added
- **╨б╨╡╤А╨▓╨╡╤А ╨╜╨░╨║╨╛╨╜╨╡╤Ж ╨┐╨╕╤И╨╡╤В ╨╗╨╛╨│╨╕, ╨┐╨╛ ╨║╨╛╤В╨╛╤А╤Л╨╝ ╨╝╨╛╨╢╨╜╨╛ ╤А╨░╨╖╨╛╨▒╤А╨░╤В╤М ╨░╨▓╨░╤А╨╕╤О (H1904).** ╨Ф╨╛ 29-07-2026 ╤А╨░╨╖╨▒╨╕╤А╨░╤В╤М ╨╕╨╜╤Ж╨╕╨┤╨╡╨╜╤В ╨▒╤Л╨╗╨╛ ╨┐╨╛╤З╤В╨╕ ╨╜╨╡╤З╨╡╨╝: `rsyslog` **╨╜╨╡ ╨▒╤Л╨╗ ╤Г╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜ ╨▓╨╛╨╛╨▒╤Й╨╡**, `/var/log/syslog` ╨╗╨╡╨╢╨░╨╗ ╨╜╤Г╨╗╨╡╨▓╨╛╨│╨╛ ╤А╨░╨╖╨╝╨╡╤А╨░ ╤Б ╨╝╨░╤А╤В╨░, ╨░ `journald`, ╤Е╨╛╤В╤М ╨╕ ╨┐╨╡╤А╤Б╨╕╤Б╤В╨╡╨╜╤В╨╜╤Л╨╣, ╨╜╨╡ ╤Е╤А╨░╨╜╨╕╨╗ ╨╕╤Б╤В╨╛╤А╨╕╨╕ ╨┐╨╛╤В╤А╨╡╨▒╨╗╨╡╨╜╨╕╤П ╨┐╨░╨╝╤П╤В╨╕ ╨┐╨╛ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨░╨╝ тАФ ╤В╨╛ ╨╡╤Б╤В╤М ╨╜╨╕ ╨╛╨┤╨╕╨╜ ╨╕╨╖ ╨┤╨▓╤Г╤Е ╨┐╤А╨╛╤И╨╗╤Л╤Е ╨┐╤А╨╛╤Б╤В╨╛╨╡╨▓ ╨╜╨╡ ╨╛╤Б╤В╨░╨▓╨╕╨╗ ╨╛╤В╨▓╨╡╤В╨░ ╨╜╨░ ╨▓╨╛╨┐╤А╨╛╤Б ┬л╨║╤В╨╛ ╤Б╤К╨╡╨╗ RAM┬╗. ╨Я╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╨╛: `rsyslog`; ╤П╨▓╨╜╨░╤П ╨┐╨╡╤А╤Б╨╕╤Б╤В╨╡╨╜╤В╨╜╨╛╤Б╤В╤М `journald` (╨┤╨╛ 3 ╨У╨С, 180 ╨┤╨╜╨╡╨╣, rate-limit ╤Б╨╜╤П╤В тАФ ╨▓╨╛ ╨▓╤А╨╡╨╝╤П ╨░╨▓╨░╤А╨╕╨╕ ╨╕╨╜╤В╨╡╤А╨╡╤Б╨╜╤Л ╨╕╨╝╨╡╨╜╨╜╨╛ ╨┐╨╛╨▓╤В╨╛╤А╤П╤О╤Й╨╕╨╡╤Б╤П ╤Б╤В╤А╨╛╨║╨╕, ╨║╨╛╤В╨╛╤А╤Л╨╡ ╤В╤А╨╛╤В╤В╨╗╨╕╨╜╨│ ╨▓╤Л╨▒╤А╨░╤Б╤Л╨▓╨░╨╡╤В); `sysstat`/`sar` ╤Б ╤И╨░╨│╨╛╨╝ **1 ╨╝╨╕╨╜╤Г╤В╨░** ╨▓╨╝╨╡╤Б╤В╨╛ ╨┤╨╡╤Д╨╛╨╗╤В╨╜╤Л╤Е 10 ╨╕ ╨╕╤Б╤В╨╛╤А╨╕╨╡╨╣ 31 ╨┤╨╡╨╜╤М; ╤Б╨╛╨▒╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ `/usr/local/sbin/memwatch.sh` ╨┐╨╛ ╤В╨░╨╣╨╝╨╡╤А╤Г ╤А╨░╨╖ ╨▓ ╨╝╨╕╨╜╤Г╤В╤Г тАФ ╤Б╤В╤А╨╛╨║╨░ ╨▓╨╕╨┤╨░ `avail=14906MB/16384MB (90%) load=0.28 procs=63 php=22 schedule_run=0` ╨▓ `/var/log/memwatch.log`, ╨░ ╨┐╤А╨╕ ╨┐╨░╨┤╨╡╨╜╨╕╨╕ ╤Б╨▓╨╛╨▒╨╛╨┤╨╜╨╛╨╣ ╨┐╨░╨╝╤П╤В╨╕ ╨╜╨╕╨╢╨╡ 25 % ╨╡╤Й╤С ╨╕ ╨┤╨░╨╝╨┐ ╤В╨╛╨┐-25 ╨┐╨╛ RSS ╤Б ╤Б╤Г╨╝╨╝╨░╨╝╨╕ ╨┐╨╛ ╨║╨╛╨╝╨░╨╜╨┤╨░╨╝ ╨▓ `/var/log/memwatch-pressure.log`; `earlyoom` ╤А╨░╨┐╨╛╤А╤В╤Г╨╡╤В ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╡ ╨┐╨░╨╝╤П╤В╨╕ ╤А╨░╨╖ ╨▓ 60 ╤Б; logrotate ╨╜╨░ `schedule.log`/`madelineproto.log`/`horizon.log`/`reverb.log`, ╨║╨╛╤В╨╛╤А╤Л╨╡ ╨╜╨╡ ╤А╨╛╤В╨╕╤А╨╛╨▓╨░╨╗╨╕╤Б╤М ╨╜╨╕╨║╨╛╨│╨┤╨░. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.64.0] - 2026-07-29

### Added
- **Changelog duplicate-bullet guard (H1848).** [`scripts/changelog_duplicate_bullets.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/changelog_duplicate_bullets.py) fails when one top-level bullet appears in more than one place, wired into CI (`changelog-lint` job) and [`.pre-commit-config.yaml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.pre-commit-config.yaml) as a local hook. Closes the root cause behind [#793](https://github.com/gasyoun/Systema-Sanscriticum/issues/793)/[#827](https://github.com/gasyoun/Systema-Sanscriticum/pull/827): `a85acd81` anchored one edit on the repeated string `### Added` and fanned the H1620 bullet across **all 52** occurrences in a single commit. Every copy was byte-identical to the legitimate one, so no section read as wrong and review had nothing to catch тАФ the twin of the "nothing verified it" gap [`changelog_compare_links.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/changelog_compare_links.py) already closed for the compare-link table. Verified against history: it flags 52├Ч at `a85acd81`, and is green on `main` only after the fix below. Deliberately no `--fix` тАФ choosing which copy survives needs tag ancestry, and a guessing script would delete the real entry; the report prints the `git log -S` тЖТ `git tag --contains` recipe instead.
- **Cabinet probe hardening (H1794).** Public `/login`+`/online`; smoke-student (`users:ensure-test-student`); history `cabinet_probe_runs` + Filament ┬л╨Ч╨┤╨╛╤А╨╛╨▓╤М╨╡ ╨║╨░╨▒╨╕╨╜╨╡╤В╨░┬╗; critical/soft TG; ops runbook in alerts; GHA multi-URL uptime; healthchecks env docs. Non-goals: Playwright, auto-restart, public status. Executor: Grok 4.5 (`grok-4.5`).
- **H1067 publish path тАФ landing A first, then B; channel posts via magnet bot.** Public `/online/konsultaciya` now renders ruled H1067 copy from `config/marathon_landing_copy.php` (default variant **A** ┬л╤Б╤В╤А╨░╤Е╨╕ ╨╜╨╛╨▓╨╕╤З╨║╨░┬╗; switch to **B** with `MARATHON_LANDING_COPY_VARIANT=b` + `config:clear`). Artisan: `marathon:apply-landing-copy {a|b}` upserts Filament `LandingPage`; `marathon:publish-channel-posts [--post=N] [--live]` posts to @samskrte using `MarketingSetting.tg_bot_token` (dry-run by default; post 5 needs `MARATHON_TESTIMONIAL`). DEPLOY_QUEUE тДЦ27 updated. Executor: Grok 4.5 (`grok-4.5`).

### Fixed
- **Second duplicated changelog bullet removed (H1848).** The H881 optimisation-backlog bullet sat in both `[1.5.0]` and `[1.4.0]`; `27ad957a` wrote it into two sections at once (on 2026-07-13 the file was short enough that both insertions landed in one diff hunk, which is why it read as a single write). Owner established the same way as [#827](https://github.com/gasyoun/Systema-Sanscriticum/pull/827) тАФ `git tag --contains 27ad957a --sort=creatordate` yields **v1.5.0** first of 67 тАФ so the `[1.4.0]` copy is the stray. Found by the new guard, not by hand.
- **┬л419 Page Expired┬╗ ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨╖╨░╨▓╨╕╤Б╨╕╤В ╨╛╤В ╤Б╨┐╨╕╤Б╨║╨░ ╨╝╨░╤А╤И╤А╤Г╤В╨╛╨▓ (H1771).** [H1765](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Exceptions/Handler.php) ╤Г╨▒╤А╨░╨╗╨╛ ╨│╨╛╨╗╤Л╨╣ 419 **╨┐╨╛╨╕╨╝╤С╨╜╨╜╨╛**: ╤В╤А╨╕ ╨▓╨╡╤В╨▓╨╕ `routeIs()` (`payment.create`/`checkout.*`, `login.post`, `shop.login`) ╨╕ `return null` ╨┤╨╗╤П ╨▓╤Б╨╡╨│╨╛ ╨╛╤Б╤В╨░╨╗╤М╨╜╨╛╨│╨╛. ╨Т `routes/web.php` **40** POST-╨╝╨░╤А╤И╤А╤Г╤В╨╛╨▓, ╨┐╨╛╨║╤А╤Л╤В╨╛ ╨▒╤Л╨╗╨╛ ╤И╨╡╤Б╤В╤М тАФ ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╨╡ ╨┐╨░╤А╨╛╨╗╤П, ╤А╨╡╨│╨╕╤Б╤В╤А╨░╤Ж╨╕╤П, ╤Б╨┤╨░╤З╨░ ╨┤╨╛╨╝╨░╤И╨║╨╕, ╤Б╨╝╨╡╨╜╨░ ╨┐╤А╨╛╤Д╨╕╨╗╤П ╨╕ ╨┐╤А╨╛╤З╨╕╨╡ ╨┐╨╛-╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г ╤Г╨┐╨╕╤А╨░╨╗╨╕╤Б╤М ╨▓ ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Г ╨▒╨╡╨╖ ╨▓╤Л╤Е╨╛╨┤╨░, ╨░ ╤Б╨╕╨╝╨┐╤В╨╛╨╝ ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╗╤Б╤П ╨▒╤Л ╨┐╤А╨╕ ╨║╨░╨╢╨┤╨╛╨╝ ╨╜╨╛╨▓╨╛╨╝ POST-╨╝╨░╤А╤И╤А╤Г╤В╨╡, ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜╨╜╨╛╨╝ ╨▒╨╡╨╖ ╨╝╤Л╤Б╨╗╨╕ ╨╛ CSRF (╤А╨╛╨▓╨╜╨╛ ╤В╨░╨║ ╨▓╨╡╤В╨║╨░ ╤З╨╡╨║╨░╤Г╤В╨░ ╨╕ ╨┐╤А╨╛╨╗╨╡╨╢╨░╨╗╨░ ╨╝╤С╤А╤В╨▓╨╛╨╣ ╨┐╤П╤В╤М ╨╜╨╡╨┤╨╡╨╗╤М). ╨в╨╡╨┐╨╡╤А╤М graceful тАФ ╤Н╤В╨╛ **╨┤╨╡╤Д╨╛╨╗╤В**, ╨░ ╤Д╨╛╤А╨╝╨░ ╨╛╤В╨▓╨╡╤В╨░ ╨▓╤Л╨▒╨╕╤А╨░╨╡╤В╤Б╤П ╨┐╨╛ ╨▓╤Л╨╖╤Л╨▓╨░╤О╤Й╨╡╨╝╤Г, ╨╜╨╡ ╨┐╨╛ ╨╕╨╝╨╡╨╜╨╕ ╨╝╨░╤А╤И╤А╤Г╤В╨░: `expectsJson()` тЖТ 419 JSON `{success,message}` (╤В╨╛╤В ╨╢╨╡ ╨║╨╛╨╜╤В╤А╨░╨║╤В, ╤З╤В╨╛ ╤Г╨╢╨╡ ╨╛╤В╨┤╨░╤С╤В `AuthController::shopLogin()` ╨╕ ╤А╨░╨╖╨▒╨╕╤А╨░╨╡╤В Alpine-╨╝╨╛╨┤╨░╨╗╨║╨░, ╨▓╤В╨╛╤А╨╛╨│╨╛ ╨╜╨╡ ╨╖╨░╨▓╨╛╨┤╨╕╨╝), ╨╕╨╜╨░╤З╨╡ `redirect()->back()->with('error', тАж)`. ╨в╤А╨╕ ╨┐╤А╨╡╨╢╨╜╨╕╨╡ ╨▓╨╡╤В╨▓╨╕ ╨┐╨╛╨╜╨╕╨╢╨╡╨╜╤Л ╨┤╨╛ ╨┐╨╡╤А╨╡╨╛╨┐╤А╨╡╨┤╨╡╨╗╨╡╨╜╨╕╤П **╤В╨╡╨║╤Б╤В╨░**; ╤Б╤В╤А╨╛╨║╨╕ ╨▓╨╖╤П╤В╤Л ╨╕╨╖ `main` ╨┤╨╛╤Б╨╗╨╛╨▓╨╜╨╛ тАФ ╨▓╨║╨╗╤О╤З╨░╤П ╤Б╨╝╤П╨│╤З╤С╨╜╨╜╨╛╨╡ [H1774](https://github.com/gasyoun/Systema-Sanscriticum/pull/821) ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡ `shop.login` (┬л╨┐╨╛╨┐╤А╨╛╨▒╤Г╨╣╤В╨╡ ╨▓╨╛╨╣╤В╨╕ ╨╡╤Й╤С ╤А╨░╨╖┬╗ ╨▓╨╝╨╡╤Б╤В╨╛ F5, ╨┐╨╛╤В╨╛╨╝╤Г ╤З╤В╨╛ ╨╝╨╛╨┤╨░╨╗╨║╨░ ╤В╨╡╨┐╨╡╤А╤М ╨┐╨╛╨┤╤В╤П╨│╨╕╨▓╨░╨╡╤В ╤Б╨▓╨╡╨╢╨╕╨╣ ╤В╨╛╨║╨╡╨╜ ╨┐╨╡╤А╨╡╨┤ ╨║╨░╨╢╨┤╤Л╨╝ ╤Б╨░╨▒╨╝╨╕╤В╨╛╨╝), ╨╜╨░ ╨║╨╛╤В╨╛╤А╨╛╨╡ ╨▓╨╡╤В╨║╨░ ╨▒╤Л╨╗╨░ ╨┐╨╡╤А╨╡╨▒╨░╨╖╨╕╤А╨╛╨▓╨░╨╜╨░ ╤Г╨╢╨╡ ╨┐╨╛╤Б╨╗╨╡ ╨┐╨╡╤А╨▓╨╛╨│╨╛ ╨╖╨╡╨╗╤С╨╜╨╛╨│╨╛ ╨┐╤А╨╛╨│╨╛╨╜╨░, тАФ ╨╕ ╤З╨╡╤В╤Л╤А╨╡ ╤В╨╡╤Б╤В╨░ H1765 ╨┐╤А╨╛╤И╨╗╨╕ **╨╜╨╡╨╛╤В╤А╨╡╨┤╨░╨║╤В╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╨╝╨╕**. ╨Ю╨▒╤Й╨╕╨╣ JSON-╤В╨╡╨║╤Б╤В ╨┐╤А╨╕ ╤Н╤В╨╛╨╝ ╤Б╨╛╤Е╤А╨░╨╜╤П╨╡╤В ╤Б╨╛╨▓╨╡╤В ╨╛╨▒╨╜╨╛╨▓╨╕╤В╤М ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Г: ╨┐╤А╨╛╨╕╨╖╨▓╨╛╨╗╤М╨╜╤Л╨╣ fetch-╨▓╤Л╨╖╤Л╨▓╨░╤О╤Й╨╕╨╣, ╨▓ ╨╛╤В╨╗╨╕╤З╨╕╨╡ ╨╛╤В ╨╝╨╛╨┤╨░╨╗╨║╨╕, ╤В╨╛╨║╨╡╨╜ ╨┐╨╡╤А╨╡╨┤ ╨┐╨╛╨▓╤В╨╛╤А╨╛╨╝ ╨╜╨╡ ╨╛╨▒╨╜╨╛╨▓╨╗╤П╨╡╤В. ╨Х╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╨╛╨╡ ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛╨╡ ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╨╡ тАФ **Livewire, ╨╕ ╨╛╨╜╨╛ ╨▓╤Л╤П╤Б╨╜╨╡╨╜╨╛, ╨░ ╨╜╨╡ ╨┐╤А╨╡╨┤╨┐╨╛╨╗╨╛╨╢╨╡╨╜╨╛**: ╨╛╨▒╨╡ ╨┐╨░╨╜╨╡╨╗╨╕ Filament ╤Е╨╛╨┤╤П╤В ╤З╨╡╤А╨╡╨╖ ╨┤╨╡╤Д╨╛╨╗╤В╨╜╤Л╨╣ `POST /livewire/update` (`->middleware('web')`, `HandleRequests.php`), ╤В╨╛ ╨╡╤Б╤В╤М ╨┐╤А╨╛╤В╤Г╤Е╤И╨╕╨╣ ╤В╨╛╨║╨╡╨╜ ╨░╨┤╨╝╨╕╨╜╨║╨╕ ╨┐╤А╨╕╤Е╨╛╨┤╨╕╤В ╨▓ ╤В╨╛╤В ╨╢╨╡ ╤Е╨╡╨╜╨┤╨╗╨╡╤А; ╨┐╤А╨╕ ╤Н╤В╨╛╨╝ ╨║╨╗╨╕╨╡╨╜╤В Livewire ╤И╨╗╤С╤В ╤В╨╛╨╗╤М╨║╨╛ `Content-type` + `X-Livewire`, ╨▒╨╡╨╖ `Accept` ╨╕ ╨▒╨╡╨╖ `X-Requested-With`, ╨┐╨╛╤Н╤В╨╛╨╝╤Г `expectsJson()` ╨╜╨░ ╨╜╤С╨╝ **╨╗╨╛╨╢╨╡╨╜** ╨╕ ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О ╨╛╨╜ ╨┐╨╛╨╗╤Г╤З╨╕╨╗ ╨▒╤Л 302. ╨Р ╤Г Livewire ╨╡╤Б╤В╤М ╤Б╨╛╨▒╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ ╨╛╨▒╤А╨░╨▒╨╛╤В╤З╨╕╨║, ╨╖╨░╨▓╤П╨╖╨░╨╜╨╜╤Л╨╣ ╤А╨╛╨▓╨╜╨╛ ╨╜╨░ ╨│╨╛╨╗╤Л╨╣ ╤Б╤В╨░╤В╤Г╤Б (`livewire.js`: `if (!response.ok) тАж if (response.status === 419) handlePageExpiry()`); ╨┐╤А╨╛╨╣╨┤╨╡╨╜╨╜╤Л╨╣ 302 ╤Г╨▓╨╛╨┤╨╕╤В ╨▓╤Л╨┐╨╛╨╗╨╜╨╡╨╜╨╕╨╡ ╨▓ `if (response.redirected) window.location.href = тАж` тАФ ╨╝╨╛╨╗╤З╨░╨╗╨╕╨▓╤Л╨╣ ╨┐╨╛╨╗╨╜╤Л╨╣ ╨┐╨╡╤А╨╡╤Е╨╛╨┤, ╨▒╨╡╨╖ ╨┤╨╕╨░╨╗╨╛╨│╨░ ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╕╤П ╨╕ ╤Б ╨┐╨╛╤В╨╡╤А╨╡╨╣ ╨╜╨╡╤Б╨╛╤Е╤А╨░╨╜╤С╨╜╨╜╨╛╨╣ ╤Д╨╛╤А╨╝╤Л ╨░╨┤╨╝╨╕╨╜╨░. ╨в╨░╨║╨╕╨╡ ╨╖╨░╨┐╤А╨╛╤Б╤Л ╤Г╤Е╨╛╨┤╤П╤В ╨▓ ╨┤╨╡╤Д╨╛╨╗╤В Laravel ╨╜╨╡╤В╤А╨╛╨╜╤Г╤В╤Л╨╝╨╕; ╨▓╨╡╤В╨▓╤М ╨╖╨░╨║╤А╨╡╨┐╨╗╨╡╨╜╨░ ╤В╨╡╤Б╤В╨╛╨╝, ╨║╨╛╤В╨╛╤А╤Л╨╣ ╨┐╤А╨╕ ╨╡╤С ╨╛╤В╨║╨╗╤О╤З╨╡╨╜╨╕╨╕ ╨┐╨░╨┤╨░╨╡╤В (╨┐╨╛╨╗╤Г╤З╨░╨╡╤В 302), ╤В╨╛ ╨╡╤Б╤В╤М ╨╜╨╡ ╨┐╤А╨╛╤Е╨╛╨┤╨╕╤В ╨▓╤Е╨╛╨╗╨╛╤Б╤В╤Г╤О. ╨Я╤А╨╛╨▓╨╡╤А╨║╨░ ╨┐╨╡╤А╨╕╨╝╨╡╤В╤А╨░ ╨┐╨╛╨┤╤В╨▓╨╡╤А╨┤╨╕╨╗╨░ `$except` (`/api/heartbeat`, `/api/games/event`) ╨╕ ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╨╡ `VerifyCsrfToken` ╨▓ ╨│╤А╤Г╨┐╨┐╨╡ `api` тАФ ╨╜╨╛ ╤Д╨╛╤А╨╝╤Г╨╗╨╕╤А╨╛╨▓╨║╨░ H1771 ┬лCSRF-mismatch ╨╝╨╛╨╢╨╡╤В ╨┐╤А╨╕╨╣╤В╨╕ ╤В╨╛╨╗╤М╨║╨╛ ╤Б ╨╜╨░╤Б╤В╨╛╤П╤Й╨╡╨│╨╛ web-POST┬╗ ╨╛╨║╨░╨╖╨░╨╗╨░╤Б╤М **╨╜╨╡╨┐╨╛╨╗╨╜╨╛╨╣**: `/livewire/update` тАФ ╤В╨╛╨╢╨╡ ╨╜╨░╤Б╤В╨╛╤П╤Й╨╕╨╣ web-POST. ╨Я╤А╨╕╤С╨╝╨║╨░: `--filter="Login|Checkout|Payment|Auth|Csrf"` 333 passed / 1091 assertions ╨┤╨╛ ╨╕ **337 passed / 1101 assertions** ╨┐╨╛╤Б╨╗╨╡, Pint ╤З╨╕╤Б╤В. Executor: Opus 5 1M (`claude-opus-5[1m]`).
- **`php artisan test --parallel` ╤Б╨╜╨╛╨▓╨░ ╨│╨╡╨╣╤В, ╨░ ╨╜╨╡ ╨╗╨╛╤В╨╡╤А╨╡╤П ([#824](https://github.com/gasyoun/Systema-Sanscriticum/issues/824), H1810).** ╨в╨╡╤Б╤В╤Л H1773 ╤З╨╕╤В╨░╨╗╨╕ ╨╕ ╤З╨╕╤Б╤В╨╕╨╗╨╕ **╨╛╨┤╨╕╨╜ ╨╗╨╕╤В╨╡╤А╨░╨╗╤М╨╜╤Л╨╣ ╨┐╤Г╤В╤М** `storage/logs/csrf-mismatch-*.log`, ╨░ `paratest` ╨▓╤Л╨┤╨░╤С╤В ╨║╨░╨╢╨┤╨╛╨╝╤Г ╨▓╨╛╤А╨║╨╡╤А╤Г ╤Б╨▓╨╛╤О `:memory:`-╨▒╨░╨╖╤Г ╨╕ **╨╜╨╕╤З╨╡╨│╨╛** ╨┤╨╗╤П `storage/` тАФ purge ╨╛╨┤╨╜╨╛╨│╨╛ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨░ ╤Б╤В╨╕╤А╨░╨╗ ╨┐╨╛╤Б╨╡╨▓ ╨┤╤А╤Г╨│╨╛╨│╨╛ (`assertFailed()` ╨┐╨░╨┤╨░╨╗ ╨║╨░╨║ ┬л0 is not equal to 0┬╗, `assertCount(1)` тАФ ╨║╨░╨║ ┬лsize 0 matches expected size 1┬╗). ╨У╨╛╨╜╨║╨░ ╤Б╨╕╨╝╨╝╨╡╤В╤А╨╕╤З╨╜╨░, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨┤╨░╨▓╨░╨╗╨░ ╨╕ **╨╗╨╛╨╢╨╜╤Л╨╣ ╨Ч╨Х╨Ы╨Б╨Э╨л╨Щ**: ╨┐╨╛╨╗╨╜╤Л╨╣ ╨┐╤А╨╛╨│╨╛╨╜ 28-07-2026 ╨┐╨╛╨║╨░╨╖╨░╨╗ 2433/2433, ╨░ ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╕╨╣ ╨╜╨░ ╨┐╨╛╤З╤В╨╕ ╤В╨╛╨╝ ╨╢╨╡ ╨┤╨╡╤А╨╡╨▓╨╡ ╤Г╨┐╨░╨╗; ╨┐╤А╨╡╨┤╤Б╤Г╤Й╨╡╤Б╤В╨▓╨╛╨▓╨░╨╜╨╕╨╡ ╨┤╨╡╤Д╨╡╨║╤В╨░ ╨┤╨╛╨║╨░╨╖╨░╨╜╨╛ ╨║╨╛╨╜╤В╤А╨╛╨╗╨╡╨╝ тАФ `app/Exceptions/Handler.php` ╨▓╨╛╨╖╨▓╤А╨░╤Й╤С╨╜ ╨╕╨╖ `origin/main` ╨╕ ╤В╨╡╤Б╤В H1771 ╤Г╨┤╨░╨╗╤С╨╜ ╨╕╨╖ ╨┤╨╡╤А╨╡╨▓╨░, ╨┐╤А╨╛╨│╨╛╨╜ ╨┐╨░╨┤╨░╨╡╤В ╤В╨░╨║ ╨╢╨╡ (2430 tests, 1 failure). ╨Э╨╛╨▓╤Л╨╣ ╤В╤А╨╡╨╣╤В [`tests/Concerns/IsolatesCsrfMismatchLog.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Concerns/IsolatesCsrfMismatchLog.php) ╨┤╨░╤С╤В ╨║╨░╨╢╨┤╨╛╨╝╤Г (╨▓╨╛╤А╨║╨╡╤А ├Ч ╤В╨╡╤Б╤В-╨║╨╗╨░╤Б╤Б) ╤Б╨▓╨╛╨╣ ╨║╨░╤В╨░╨╗╨╛╨│ ╨╗╨╛╨│╨░, ╨║╨╗╤О╤З тАФ `UNIQUE_TEST_TOKEN`/`TEST_TOKEN` (`paratest`, `Options::ENV_KEY_UNIQUE_TOKEN`/`ENV_KEY_TOKEN`) ╤Б ╨╛╤В╨║╨░╤В╨╛╨╝ ╨╜╨░ PID ╨┤╨╗╤П ╨┐╨╛╤Б╨╗╨╡╨┤╨╛╨▓╨░╤В╨╡╨╗╤М╨╜╨╛╨│╨╛ ╨┐╤А╨╛╨│╨╛╨╜╨░; ╨║╨╗╨░╤Б╤Б ╨▓ ╨║╨╗╤О╤З╨╡ ╨╜╤Г╨╢╨╡╨╜ ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛, ╨┐╨╛╤В╨╛╨╝╤Г ╤З╤В╨╛ ╨┐╤А╨╕ `--functional` ╨┐╨╛ ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨░╨╝ ╤А╨░╤Б╨║╨╕╨┤╤Л╨▓╨░╤О╤В╤Б╤П **╨╝╨╡╤В╨╛╨┤╤Л**. **╨Я╤А╨╛╨┤╨░╨║╤И╨╡╨╜-╨║╨╛╨┤ ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В:** [`CsrfMismatchDigestService::logFiles()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/CsrfMismatchDigestService.php) ╤Г╨╢╨╡ ╨▓╤Л╨▓╨╛╨┤╨╕╨╗ ╨╝╨░╤Б╨║╤Г ╨╕╨╖ `config('logging.channels.csrf_mismatch.path')` тАФ ╤В╤А╨╡╨╣╤В ╤Н╤В╤Г ╨║╨╛╨╜╤Д╨╕╨│╤Г╤А╨░╤Ж╨╕╤О ╨┐╨╡╤А╨╡╨╛╨┐╤А╨╡╨┤╨╡╨╗╤П╨╡╤В, ╨░ ╤Б╨▓╨╛╤О ╨╝╨░╤Б╨║╤Г ╨▓╤Л╨▓╨╛╨┤╨╕╤В **╤В╨╡╨╝ ╨╢╨╡** ╤Б╨┐╨╛╤Б╨╛╨▒╨╛╨╝, ╤З╤В╨╛╨▒╤Л ╨┐╨╡╤А╨╡╨╕╨╝╨╡╨╜╨╛╨▓╨░╨╜╨╕╨╡ ╨▓ ╨┐╤А╨╛╨┤╨░╨║╤И╨╡╨╜╨╡ ╨╗╨╛╨╝╨░╨╗╨╛ ╤В╨╡╤Б╤В╤Л ╨│╤А╨╛╨╝╨║╨╛, ╨░ ╨╜╨╡ ╤А╨░╤Б╤Е╨╛╨┤╨╕╨╗╨╛╤Б╤М ╨╝╨╛╨╗╤З╨░. ╨Ю╨▒╤Е╨╛╨┤ H1771 ╤З╨╡╤А╨╡╨╖ `NullHandler` ╤Б╨╜╤П╤В: ╨▓╤Л╨╗╨╡╤З╨╡╨╜╨░ ╨┐╤А╨╕╤З╨╕╨╜╨░, ╨▓╨║╨╗╤О╤З╨░╤П ╨╛╨┤╨╜╨╛╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╜╨╛╨│╨╛ ╨┤╨▓╨╛╨╣╨╜╨╕╨║╨░ ╨╜╨░ Windows (`@unlink` ╨╜╨╡ ╤Г╨┤╨░╨╗╤П╨╡╤В ╤Д╨░╨╣╨╗ ╤Б ╨╢╨╕╨▓╤Л╨╝ ╨┤╨╡╤Б╨║╤А╨╕╨┐╤В╨╛╤А╨╛╨╝ Monolog, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨║╨╗╨░╤Б╤Б, ╨╛╤В╤А╨░╨▒╨╛╤В╨░╨▓╤И╨╕╨╣ ╤А╨░╨╜╤М╤И╨╡, ╤Г╤В╨╡╨║╨░╨╗ ╨▓ ╤З╤Г╨╢╨╛╨╣ ╤Б╤З╤С╤В). ╨Я╤А╨╕╤С╨╝╨║╨░: **4 ╨┐╨╛╨┤╤А╤П╨┤ ╨╖╨╡╨╗╤С╨╜╤Л╤Е `--parallel` ╨┐╤А╨╛╨│╨╛╨╜╨░** (3├Ч ╨┐╨╛ 6 ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╛╨▓, 1├Ч ╨┐╨╛ 10 ╨┤╨╗╤П ╨┐╨╛╨▓╤Л╤И╨╡╨╜╨╜╨╛╨╣ ╨║╨╛╨╜╨║╤Г╤А╨╡╨╜╤Ж╨╕╨╕, 2434 ╤В╨╡╤Б╤В╨░), `--functional` ╨╖╨╡╨╗╤С╨╜╤Л╨╣, 15 CSRF-╤В╨╡╤Б╤В╨╛╨▓ ╨╖╨╡╨╗╨╡╨╜╤Л ╨┐╨╛╤Б╨╗╨╡╨┤╨╛╨▓╨░╤В╨╡╨╗╤М╨╜╨╛, Pint ╤З╨╕╤Б╤В, ╨┤╨╡╤А╨╡╨▓╨╛ ╤З╨╕╤Б╤В╨╛ (`storage/logs` ╤Ж╨╡╨╗╨╕╨║╨╛╨╝ ╨▓ `.gitignore`). Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.62.2] - 2026-07-28

### Fixed
- **╨Ф╨▓╨╡ ╤Б╤В╤А╨╛╨║╨╕ ╤В╨░╨▒╨╗╨╕╤Ж╤Л ╨┐╨╛╨║╤А╤Л╤В╨╕╤П ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨░ ╨▒╤Л╨╗╨╕ ╨╖╨░╨╜╨╕╨╢╨╡╨╜╤Л (H1790).** ╨Т ╨▓╨╡╤А╤Б╨╕╨╕ `1.62.1` ╨╖╨░╨╜╤П╤В╨╕╤П 22 ╨╕ 30 ╤Б╤В╨╛╤П╨╗╨╕ ╨║╨░╨║ ┬л4 (IтАУV), ╨╜╤Г╨╝╨╡╤А╨░╤Ж╨╕╤П ╤Б ╤А╨░╨╖╤А╤Л╨▓╨╛╨╝┬╗. ╨Ю╨▒╨╡ ╤Б╤В╤А╨╛╨║╨╕ ╨╜╨╡╨▓╨╡╤А╨╜╤Л, ╨┐╨╛ ╤А╨░╨╖╨╜╤Л╨╝ ╨┐╤А╨╕╤З╨╕╨╜╨░╨╝. ╨г ╨╖╨░╨╜╤П╤В╨╕╤П 30 ╨▓ `.docx` ╨╝╨░╤А╨║╨╡╤А ╤Г╨┐╤А╨░╨╢╨╜╨╡╨╜╨╕╤П II ╨┤╨╡╨╣╤Б╤В╨▓╨╕╤В╨╡╨╗╤М╨╜╨╛ ╤Б╤В╨╛╤П╨╗ ╨│╨╛╨╗╤Л╨╝ `II` **╨▒╨╡╨╖ ╤В╨╛╤З╨║╨╕** тАФ ╨╡╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ ╤В╨░╨║╨╛╨╣ ╨╝╨░╤А╨║╨╡╤А ╨▓╨╛ ╨▓╤Б╨╡╨╣ ╨║╨╜╨╕╨│╨╡, ╨┐╤А╨╕ ╤В╨╛╨╝ ╤З╤В╨╛ ╤Б╨╛╤Б╨╡╨┤╨╕ III/IV/V ╤В╨╛╨│╨╛ ╨╢╨╡ ╨╖╨░╨╜╤П╤В╨╕╤П ╨╜╨░╨▒╤А╨░╨╜╤Л `III. тАж`; ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╨╛ ╨▓ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨╡ ╨╛╨┤╨╜╨╕╨╝ ╤Б╨╕╨╝╨▓╨╛╨╗╨╛╨╝ ([SanskritGrammar#555](https://github.com/gasyoun/SanskritGrammar/pull/555)). ╨г ╨╖╨░╨╜╤П╤В╨╕╤П 22 ╨╝╨░╤А╨║╨╡╤А IV ╨▒╤Л╨╗ ╨║╨╛╤А╤А╨╡╨║╤В╨╡╨╜ ╨╕╨╖╨╜╨░╤З╨░╨╗╤М╨╜╨╛ тАФ ╨╛╨╜ ╨┐╤А╨╛╤Б╤В╨╛ ╤Б╤В╨╛╨╕╤В **╨╛╨┤╨╕╨╜ ╨╜╨░ ╤Б╤В╤А╨╛╨║╨╡**, ╨░ ╤Б╨╛╨┤╨╡╤А╨╢╨░╨╜╨╕╨╡ ╨╕╨┤╤С╤В ╨┐╨╛╨┤╨┐╤Г╨╜╨║╤В╨░╨╝╨╕ ╨╜╨╕╨╢╨╡, ╨╕ ╨┐╤А╨╡╨╢╨╜╨╕╨╣ ╤И╨░╨▒╨╗╨╛╨╜ ╨┐╨╛╨┤╤Б╤З╤С╤В╨░ ╤В╨░╨║╤Г╤О ╤Д╨╛╤А╨╝╤Г ╨╜╨╡ ╨╗╨╛╨▓╨╕╨╗. ╨Я╨╛╤Б╨╗╨╡ ╨┐╤А╨░╨▓╨║╨╕ ╨╕ ╨┐╨╡╤А╨╡╤Б╤З╤С╤В╨░ ╨╛╨▒╨╡ ╤Б╤В╤А╨╛╨║╨╕ тАФ 5 (IтАУV), ╨╕ ╤А╨░╨╖╤А╤Л╨▓╨╛╨▓ ╨╜╤Г╨╝╨╡╤А╨░╤Ж╨╕╨╕ ╨╜╨╡ ╨╛╤Б╤В╨░╨╗╨╛╤Б╤М ╨╜╨╕ ╨▓ ╨╛╨┤╨╜╨╛╨╝ ╨╕╨╖ 35 ╨╖╨░╨╜╤П╤В╨╕╨╣ ╤Б ╨▒╨╗╨╛╨║╨╛╨╝. **╨Э╨░ ╨┐╨╛╨▓╨╡╨┤╨╡╨╜╨╕╨╡ ╨░╨▓╤В╨╛╨╛╤В╨║╤А╤Л╤В╨╕╤П ╤Н╤В╨╛ ╨╜╨╡ ╨▓╨╗╨╕╤П╨╡╤В:** `HomeworkAutoOpener` ╨▒╨╡╤А╤С╤В ╨▒╨╗╨╛╨║ ┬л╨г╨┐╤А╨░╨╢╨╜╨╡╨╜╨╕╤П┬╗ ╤Ж╨╡╨╗╨╕╨║╨╛╨╝ ╨╕ ╨╜╨░ ╨╖╨░╨┤╨░╨╜╨╕╤П ╨╡╨│╨╛ ╨╜╨╡ ╤А╨░╨╖╨▒╨╕╤А╨░╨╡╤В тАФ ╨╝╨╡╨╜╤П╨╗╨╕╤Б╤М ╤В╨╛╨╗╤М╨║╨╛ ╤З╨╕╤Б╨╗╨░ ╨▓ ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨░╤Ж╨╕╨╕. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.63.0] - 2026-07-28

### Added
- **╨У╨╛╨╗╨╛╤Б╨╛╨▓╨╛╨╣ ╨║╨╛╨╜╤В╤А╨░╨║╤В ╤Б╤В╨╡╨╜╤Л ╨Т╨Ъ ╨Ю╨а╨б + ╨┐╨╡╤А╨╡╨┐╨╕╤Б╨░╨╜╨╜╤Л╨╡ NEW-╤И╨░╨▒╨╗╨╛╨╜╤Л ╨╕ ╤А╨╡╤В╤О╨╜ ╨┐╤А╨╛╨╝╨╛-╤Д╨╕╨╗╤М╤В╤А╨░ (H1754).** Wave 4 (H1567) ╨┐╨╛╤Б╤В╨░╨▓╨╕╨╗╨░ ╨╝╨░╤И╨╕╨╜╨╡╤А╨╕╤О forward-╤З╨╡╤А╨╜╨╛╨▓╨╕╨║╨╛╨▓, ╨╜╨╛ ╨║╨╛╨┐╨╕╤П ╤З╨╡╤В╤Л╤А╤С╤Е ╤Б╨╡╨╝╨╡╨╣╤Б╤В╨▓ ╨▒╤Л╨╗╨░ generic-╤Н╨╝╨╛╨┤╨╖╨╕╨╣╨╜╨╛╨╣. ╨в╨╡╨┐╨╡╤А╤М ╤А╨╡╨┤╨░╨║╤Ж╨╕╨╛╨╜╨╜╤Л╨╣ ╨│╨╛╨╗╨╛╤Б ╨▓╤Л╨▓╨╡╨┤╨╡╨╜ ╨╕╨╖ ╨╕╨╖╨╝╨╡╤А╨╡╨╜╨╜╨╛╨╣ ╨▓╨╛╨▓╨╗╨╡╤З╤С╨╜╨╜╨╛╤Б╤В╨╕ ╤Б╨░╨╝╨╛╨╣ ╤Б╤В╨╡╨╜╤Л тАФ ╨┐╨╛╨╗╨╜╤Л╨╣ ╨║╨╛╤А╨┐╤Г╤Б `vk_ors.db` (7 608 ╨┐╨╛╤Б╤В╨╛╨▓ 2015тАУ2026, ╤Б╤А╨╡╨╖ 2022+ ╤Б тЙе100 ╨┐╤А╨╛╤Б╨╝╨╛╤В╤А╨╛╨▓, n=2 799, ╨╝╨╡╤В╤А╨╕╨║╨░ тАФ ╨╝╨╡╨┤╨╕╨░╨╜╨╜╤Л╨╣ like-rate, ╨▒╨░╨╖╨░ 2,73 %) тАФ ╨╕ ╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜ ╨║╨╛╨╜╤В╤А╨░╨║╤В╨╛╨╝ [docs/VOICE_CONTRACT_ORS_VK_WALL_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VOICE_CONTRACT_ORS_VK_WALL_RU.md) (+ ╨╝╨╡╤В╨░╨┤╨╛╨║): ╨┐╤А╨╡╨┤╨╝╨╡╤В╨╜╨░╤П ╨║╨╛╨╜╨║╤А╨╡╤В╨╕╨║╨░ ╤Б ╤Д╨░╨║╤В╨╛╨╝ ╨▓ ╨║╨░╨╢╨┤╨╛╨╝ ╨┐╨╛╤Б╤В╨╡ (╨┤╨░╤В╤Л 2,77 %, ╨╕╨╝╨╡╨╜╨░ 2,83 %, ┬л╤С╨╗╨╛╤З╨║╨╕┬╗ 2,90 %, ╨┤╨╡╨▓╨░╨╜╨░╨│╨░╤А╨╕ 2,98 % тАФ ╨▓╤Б╤С ╨▓╤Л╤И╨╡ ╨▒╨░╨╖╤Л), ╨╜╨╛╨╗╤М ╤Н╨╝╨╛╨┤╨╖╨╕ ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О (╨▒╨░╨║╨╡╤В 0 ╤Н╨╝╨╛╨┤╨╖╨╕ 2,84 % ╨┐╤А╨╛╤В╨╕╨▓ 2,36 % ╤Г 3тАУ5), ╨╖╨░╨┐╤А╨╡╤В ╨╝╨░╤А╨║╨╡╤В╨╕╨╜╨│╨╛╨▓╤Л╤Е ╤И╤В╨░╨╝╨┐╨╛╨▓ (┬л╤Г╨╜╨╕╨║╨░╨╗╤М╨╜╨░╤П ╨▓╨╛╨╖╨╝╨╛╨╢╨╜╨╛╤Б╤В╤М┬╗ 0,86 %, ┬л╨╜╨╡ ╤Г╨┐╤Г╤Б╤В╨╕╤В╨╡/╤Г╤Б╨┐╨╡╨╣╤В╨╡┬╗ 1,54 %, ┬л╨┐╤А╨╕╨│╨╗╨░╤И╨░╨╡╨╝ ╨▓╨░╤Б┬╗ 1,93 %, ╤Е╤Г╨┤╤И╨╕╨╣ ╨┐╨╛╤Б╤В ╤Б╤В╨╡╨╜╤Л тАФ ┬л╨Ю╤В╨║╤А╨╛╨╣╤В╨╡ ╨┤╨╗╤П ╤Б╨╡╨▒╤ПтАж┬╗ 0,23 % ╨┐╤А╨╕ 12 158 ╨┐╤А╨╛╤Б╨╝╨╛╤В╤А╨╛╨▓), CTA ╤В╨╛╨╗╤М╨║╨╛ ╤Д╨╛╤А╨╝╨╛╨╣ ┬л╨Я╨╛╨┤╤А╨╛╨▒╨╜╨╡╨╡: URL┬╗ (2,75 % тЙИ ╨▒╨░╨╖╨░; ┬л╨┐╨╕╤И╨╕╤В╨╡┬╗+╤Б╤Б╤Л╨╗╨║╨░ 1,89 %, ┬л╨╖╨░╨┐╨╕╤Б╨░╤В╤М╤Б╤П┬╗ 1,66 %), ╨▒╨╡╨╖ ╤Е╤Н╤И╤В╨╡╨│╨╛╨▓ (2,56 % < ╨▒╨░╨╖╤Л, 0 % ╨▓ ╤В╨╛╨┐-╨┤╨╡╤Ж╨╕╨╗╨╡), ╤Ж╨╡╨╗╨╡╨▓╨░╤П ╨┤╨╗╨╕╨╜╨░ 400тАУ1 200 ╨╖╨╜╨░╨║╨╛╨▓ (╤Е╤Г╨┤╤И╨╕╨╣ ╨▒╨░╨║╨╡╤В тАФ ╤А╨╛╨▓╨╜╨╛ ╨┐╤А╨╡╨┤╨┐╨╕╤Б╨░╨╜╨╜╤Л╨╡ ╤Б╤В╨░╤А╤Л╨╝╨╕ ╨┐╤А╨╛╨╝╨┐╤В╨░╨╝╨╕ 101тАУ300 ╨╖╨╜╨░╨║╨╛╨▓, 2,58 %). ╨Т╤Б╨╡ ╤З╨╡╤В╤Л╤А╨╡ ╤Б╨╡╨╝╨╡╨╣╤Б╤В╨▓╨░ `ForwardDraftGenerator` + ╨┐╨╛╨╗╨╕╤И-╨┐╤А╨╛╨╝╨┐╤В╤Л `CuratorAi` ╨┐╨╡╤А╨╡╨┐╨╕╤Б╨░╨╜╤Л ╨┐╨╛╨┤ ╨║╨╛╨╜╤В╤А╨░╨║╤В (╨╛╨▒╤Й╨╕╨╣ ╨▒╨╗╨╛╨║ `VOICE_PROMPT_RULES`); `EvergreenScorer::PROMO_PATTERN` ╤А╨╡╤В╤О╨╜╨╡╨╜ ╨┐╨╛ ╤А╨╡╨░╨╗╤М╨╜╤Л╨╝ ╤В╨╡╨║╤Б╤В╨░╨╝ ╨▓╨╝╨╡╤Б╤В╨╛ ╤Г╨│╨░╨┤╨░╨╜╨╜╤Л╤Е ╨║╨╗╤О╤З╨╡╨╣ (╤Б╨║╨╕╨┤╨║ ├Ч3 ╨╖╨░ 11 ╨╗╨╡╤В, ╨╝╨░╤А╨░╤Д╨╛╨╜ ├Ч4, ╨┐╤А╨╛╨╝╨╛╨║╨╛╨┤ ├Ч0 тЖТ ╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ ╨┐╤А╨╛╨╝╨╛-╤Б╨╗╨╛╨▓╨░╤А╤М: ╨╖╨░╨┐╨╕╤Б-/╤А╨╡╨│╨╕╤Б╤В╤А╨░╤Ж-/╨╛╨┐╨╗╨░╤В-/╤Б╤В╨╛╨╕╨╝╨╛╤Б╤В-/╤А╤Г╨▒╨╗╨╡╨╣): 78 тЖТ 196 ╤Б╤А╨░╨▒╨░╤В╤Л╨▓╨░╨╜╨╕╨╣ ╨╜╨░ 5 450 ╤В╨╡╨║╤Б╤В╨░╤Е, ╤Б╤В╤А╨╛╨│╨╛╨╡ ╨╜╨░╨┤╨╝╨╜╨╛╨╢╨╡╤Б╤В╨▓╨╛ (0 ╨┐╨╛╤В╨╡╤А╤П╨╜╨╜╤Л╤Е ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╨╣), ╤П╨▓╨╜╤Л╨╡ lookaround-╨│╤А╨░╨╜╨╕╤Ж╤Л ╨▓╨╝╨╡╤Б╤В╨╛ build-╨╖╨░╨▓╨╕╤Б╨╕╨╝╨╛╨│╨╛ `\b` ╨▓╨╛╨║╤А╤Г╨│ ╨║╨╕╤А╨╕╨╗╨╗╨╕╤Ж╤Л; ╨╜╨░ ╤А╨╡╨░╨╗╤М╨╜╨╛╨╝ ╨▓╤Е╨╛╨┤╨╡ ╤Б╨║╨╛╤А╨╡╤А╨░ ╨┤╨╛╨┐╨╛╨╗╨╜╨╕╤В╨╡╨╗╤М╨╜╨╛ ╨╕╤Б╨║╨╗╤О╤З╨░╨╡╤В╤Б╤П ╤А╨╛╨▓╨╜╨╛ ╨╛╨┤╨╕╨╜ ╨┐╨╛╤Б╤В тАФ 23448, ╤Б╨░╨╝╤Л╨╣ ╨┐╤А╨╛╨┤╨░╤О╤Й╨╕╨╣ ╨┐╨╛╤Б╤В ╤Б╤В╨╡╨╜╤Л. ╨а╨╛╨░╨┤╨╝╨░╨┐: ╤Б╤В╤А╨╛╨║╨░ Activation track + Wave 4 voice pass. ╨Ъ╨╛╨┐╨╕╤П ╨╕ ╤Д╨╕╨╗╤М╤В╤А тАФ ╨▒╨╡╨╖ ╤Д╨╗╨░╨│╨╛╨▓, ╨╝╨╕╨│╤А╨░╤Ж╨╕╨╣ ╨╕ ╨┐╤Г╨▒╨╗╨╕╨║╨░╤Ж╨╕╨╣ (D12 ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В). Executor: Fable 5 (`claude-fable-5`).

## [1.62.1] - 2026-07-28

### Added
- **╨в╨░╨▒╨╗╨╕╤Ж╨░ ╨┐╨╛╨║╤А╤Л╤В╨╕╤П ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨░ ╨Ъ╨╛╤З╨╡╤А╨│╨╕╨╜╨╛╨╣ ╨┐╨╛ ╨▓╤Б╨╡╨╝ 40 ╨╖╨░╨╜╤П╤В╨╕╤П╨╝ (H1789).** H1764 ╨┐╨╛╨┤╤Б╤В╨░╨▓╨╗╤П╨╡╤В ╨▓ ╤Г╤Б╨╗╨╛╨▓╨╕╨╡ ╨Ф╨Ч ╨▒╨╗╨╛╨║ ┬л╨г╨┐╤А╨░╨╢╨╜╨╡╨╜╨╕╤П┬╗ ╨╕╨╖ ╨╛╤Ж╨╕╤Д╤А╨╛╨▓╨║╨╕, ╨╜╨╛ ╨┤╨╛ ╤Б╨╕╤Е ╨┐╨╛╤А ╨╜╨╕╨║╤В╨╛ ╨╜╨╡ ╨╝╨╡╤А╨╕╨╗, ╤Г ╨║╨░╨║╨╕╤Е ╨╖╨░╨╜╤П╤В╨╕╨╣ ╤Н╤В╨╛╤В ╨▒╨╗╨╛╨║ ╨▓╨╛╨╛╨▒╤Й╨╡ ╨╡╤Б╤В╤М. ╨Я╤А╨╛╨╝╨╡╤А ╨╜╨░ ╨║╨╛╨╜╤В╤А╨░╨║╤В╨╡ `KocherginaExerciseSource::forLesson()`: ╤В╨╡╨║╤Б╤В ╨╕╨╖╨▓╨╗╨╡╨║╨░╨╡╤В╤Б╤П ╨┤╨╗╤П **35 ╨╖╨░╨╜╤П╤В╨╕╨╣ ╨╕╨╖ 40**, ╨░ ╤Г 11, 20, 27, 34 ╨╕ 40 ╤Б╤В╤А╨╛╨║╨╕ `╨г╨┐╤А╨░╨╢╨╜╨╡╨╜╨╕╤П` ╨▓ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨╡ ╨╜╨╡╤В тАФ ╤В╨░╨╝ ╨┐╨╛╨╣╨┤╤С╤В ╤И╤В╨░╤В╨╜╨░╤П ╨╛╤В╤Б╤Л╨╗╨╛╤З╨╜╨░╤П ╤Д╨╛╤А╨╝╤Г╨╗╨╕╤А╨╛╨▓╨║╨░ (A8), ╨╕ ╤Н╤В╨╛ ╨╜╨╡ ╨┤╨╡╨│╤А╨░╨┤╨░╤Ж╨╕╤П. ╨Ф╨╗╤П ╨▓╨╛╨╗╨╜╤Л 1 (╨╖╨░╨╜╤П╤В╨╕╤П 1тАУ5) ╤В╨╡╨║╤Б╤В ╨╡╤Б╤В╤М ╤Г ╨▓╤Б╨╡╤Е ╨┐╤П╤В╨╕: ╨▒╨╗╨╛╨║╨╕ 1 270тАж2 420 ╤Б╨╕╨╝╨▓╨╛╨╗╨╛╨▓, 4тАУ5 ╨╖╨░╨┤╨░╨╜╨╕╨╣ ╨╜╨░ ╨╖╨░╨╜╤П╤В╨╕╨╡. ╨а╨░╨╖╨╝╨╡╤А╤Л ╨┐╨╛ ╨▓╤Б╨╡╨╝ ╨╖╨░╨╜╤П╤В╨╕╤П╨╝ тАФ ╨╝╨╡╨┤╨╕╨░╨╜╨░ 2 411, ╤А╨░╨╖╨▒╤А╨╛╤Б 1 270тАж4 167; ╨▓╤Л╨▒╤А╨╛╤Б╨╛╨▓ ╨▒╨╛╨╗╤М╤И╨╡ 4├Ч ╨╝╨╡╨┤╨╕╨░╨╜╤Л ╨╜╨╡╤В, ╤В╨╛ ╨╡╤Б╤В╤М ╨│╤А╨░╨╜╨╕╤Ж╨░ ┬л╨┤╨╛ ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╡╨│╨╛ ╨╖╨░╨│╨╛╨╗╨╛╨▓╨║╨░ ╨╖╨░╨╜╤П╤В╨╕╤П┬╗ ╨╜╨╕╨│╨┤╨╡ ╨╜╨╡ ╤Б╤К╨╡╨╗╨░ ╨┐╨╛╤Б╤В╨╛╤А╨╛╨╜╨╜╨╕╨╣ ╤А╨░╨╖╨┤╨╡╨╗. ╨в╨░╨▒╨╗╨╕╤Ж╨░ ╨╗╨╡╨│╨╗╨░ ╨▓ [IMPLEMENTATION_SYSTEMA_HOMEWORK_AUTO_OPEN_WAVE1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_HOMEWORK_AUTO_OPEN_WAVE1.md) ╨║╨░╨║ ╤Б╨╜╨╕╨╝╨╛╨║ ╨┤╨╗╤П ╤Н╨║╤Б╨┐╨╗╤Г╨░╤В╨░╤Ж╨╕╨╕; ╨║╨░╨╜╨╛╨╜╨╕╤З╨╡╤Б╨║╨╕╨╣ ╤Н╨║╨╖╨╡╨╝╨┐╨╗╤П╤А ╨╕ ╤А╨░╨╖╨▒╨╛╤А ╨╛╤Б╨╛╨▒╨╡╨╜╨╜╨╛╤Б╤В╨╡╨╣ ╨╛╤Ж╨╕╤Д╤А╨╛╨▓╨║╨╕ (╨┤╤Г╨▒╨╗╨╕╤А╤Г╤О╤Й╨╕╨╡╤Б╤П ╨╖╨░╨│╨╛╨╗╨╛╨▓╨║╨╕ XI/XX/XXVII/XXXIV ╨▓ ╤Е╤А╨╡╤Б╤В╨╛╨╝╨░╤В╨╕╨╕, ╨┐╤П╤В╤М ╤А╨░╨╖╨╜╤Л╤Е ╤Д╨╛╤А╨╝ ╨╝╨░╤А╨║╨╡╤А╨░ ╨╖╨░╨┤╨░╨╜╨╕╤П) тАФ ╨▓ ╤А╨╡╨┐╨╛╨╖╨╕╤В╨╛╤А╨╕╨╕ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨░, [EXERCISE_BLOCK_COVERAGE_KOCHERGINA_2026.md](https://github.com/gasyoun/SanskritGrammar/blob/main/KocherginaUchebnik_1998/EXERCISE_BLOCK_COVERAGE_KOCHERGINA_2026.md) ([PR #553](https://github.com/gasyoun/SanskritGrammar/pull/553)). ╨Ъ╨╛╨┤╨░ ╨╜╨╡ ╨╝╨╡╨╜╤П╨╡╤В тАФ ╤В╨╛╨╗╤М╨║╨╛ ╨╕╨╖╨╝╨╡╤А╨╡╨╜╨╕╨╡ ╨╕ ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨░╤Ж╨╕╤П; ╤В╨╡╨║╤Б╤В ╤Г╤З╨╡╨▒╨╜╨╕╨║╨░ (D14) ╨╜╨╡ ╨┐╨╡╤А╨╡╨╜╨╛╤Б╨╕╤В╤Б╤П. Executor: Opus 5 1M (`claude-opus-5[1m]`).

### Changed
- **╨в╨╡╨║╤Б╤В╤Л TG-╨░╨╗╨╡╤А╤В╨╛╨▓ cabinet:probe тАФ ╨┐╨╛-╤А╤Г╤Б╤Б╨║╨╕ + ╨┐╨╛╨╗╨╜╤Л╨╡ URL.** ╨б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╤П ┬л╨▒╨╛╨╗╨╡╨╜ / ╤Б╨╜╨╛╨▓╨░ ╨╢╨╕╨▓┬╗ ╤Б https://samskrte.ru/ , https://samskrte.ru/dvaram , https://samskrte.ru/login , https://samskrte.ru/admin . Executor: Grok 4.5 (`grok-4.5`).

## [1.62.0] - 2026-07-28

### Added
- **╨Р╨▓╤В╨╛╨╛╤В╨║╤А╤Л╤В╨╕╨╡ ╨┐╤А╨╕╤С╨╝╨░ ╨Ф╨Ч ╨┐╨╛╤Б╨╗╨╡ ╨┐╤А╨╛╨▓╨╡╨┤╤С╨╜╨╜╨╛╨│╨╛ ╤Г╤А╨╛╨║╨░ тАФ ╨▓╨╛╨╗╨╜╨░ 1 (H1764).** ╨Я╤А╨╕╤С╨╝ ╨┤╨╛╨╝╨░╤И╨╜╨╕╤Е ╨╖╨░╨┤╨░╨╜╨╕╨╣ ╨┐╨╛ ╨╖╨░╨╜╤П╤В╨╕╤П╨╝ 1тАУ5 ╨│╤А╨░╨╝╨╝╨░╤В╨╕╨║╨╕ ╨Ъ╨╛╤З╨╡╤А╨│╨╕╨╜╨╛╨╣ ╨╛╤В╨║╤А╤Л╨▓╨░╨╡╤В╤Б╤П ╤Б╨░╨╝, ╨╜╨░ ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╕╨╣ ╨┤╨╡╨╜╤М ╨┐╨╛╤Б╨╗╨╡ ╨┐╤А╨╛╨▓╨╡╨┤╤С╨╜╨╜╨╛╨│╨╛ ╤Г╤А╨╛╨║╨░, ╨▓ 09:00 ╨Ь╨б╨Ъ, ╤Б ╤Г╤Б╨╗╨╛╨▓╨╕╨╡╨╝ ┬л╨▓╤Б╨╡ ╤Г╨┐╤А╨░╨╢╨╜╨╡╨╜╨╕╤П ╨║ ╨Ч╨░╨╜╤П╤В╨╕╤О N┬╗. ╨в╨╛╤З╨║╨░ ╨╛╤В╤Б╤З╤С╤В╨░ тАФ `recording_attached_at`, ╤И╤В╨░╨╝╨┐╤Г╨╡╨╝╤Л╨╣ **╨╛╨┤╨╕╨╜ ╤А╨░╨╖** ╨┐╤А╨╕ ╨┐╨╡╤А╨▓╨╛╨╝ ╤Б╨╛╤Е╤А╨░╨╜╨╡╨╜╨╕╨╕ ╤Г╤А╨╛╨║╨░ ╤Б ╨▓╨╕╨┤╨╡╨╛: ╨┐╨╛╨▓╤В╨╛╤А╨╜╨░╤П ╨╖╨░╨╝╨╡╨╜╨░ ╨╖╨░╨┐╨╕╤Б╨╕ ╨╝╨╛╨╝╨╡╨╜╤В ╨╛╤В╨║╤А╤Л╤В╨╕╤П ╨╜╨╡ ╨┤╨▓╨╕╨│╨░╨╡╤В, ╨╕╨╜╨░╤З╨╡ ╨┐╨╡╤А╨╡╨╝╨╛╨╜╤В╨░╨╢ ╨╜╨░ ╨╜╨╡╨┤╨╡╨╗╨╡ ╤Б╨┤╨▓╨╕╨│╨░╨╗ ╨▒╤Л ╨┤╨╡╨┤╨╗╨░╨╣╨╜ ╤Г╨╢╨╡ ╤Г╨▓╨╡╨┤╨╛╨╝╨╗╤С╨╜╨╜╤Л╨╝ ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░╨╝. ╨Ь╨╛╨╝╨╡╨╜╤В ╨▓╤Л╤А╨░╨▓╨╜╨╕╨▓╨░╨╡╤В╤Б╤П ╨╜╨░ ╨▒╨╗╨╕╨╢╨░╨╣╤И╨╕╨╡ 09:00 (╨╖╨░╨┐╨╕╤Б╤М ╨▓ 06:00 ╨╕ ╨▓ 20:00 тЖТ ╨╖╨░╨▓╤В╤А╨░, ╨▓ 21:30 тЖТ ╨┐╨╛╤Б╨╗╨╡╨╖╨░╨▓╤В╤А╨░) тАФ ╨┐╨╛╨╖╨┤╨╜╤П╤П ╨╖╨░╨╗╨╕╨▓╨║╨░ ╤Б╤В╨╛╨╕╤В ╤Б╤В╤Г╨┤╨╡╨╜╤В╤Г ╤Б╤Г╤В╨╛╨║, ╨╕ ╤Н╤В╨╛ ╨┐╤А╨╕╨╜╤П╤В╨╛ ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛, `homework_opens_at` ╨▓╨╕╨┤╨╡╨╜ ╨▓ ╨░╨┤╨╝╨╕╨╜╨║╨╡. ╨з╨░╤Б╨╛╨▓╨╛╨╣ ╤Б╨╗╨╛╤В ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║╨░ (`homework:auto-open`) ╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╡╨╜ ╨┐╨╛ `homework_auto_opened_at`: ╨▓╤В╨╛╤А╨╛╨╣ ╨┐╤А╨╛╨│╨╛╨╜ ╨▓ ╤В╨╛╤В ╨╢╨╡ ╤З╨░╤Б ╨╜╨╡ ╤И╨╗╤С╤В ╨▓╤В╨╛╤А╨╛╨│╨╛ ╨┐╤Г╤И╨░. ╨в╨╡╨║╤Б╤В ╤Г╤Б╨╗╨╛╨▓╨╕╤П ╨┐╨╛╨┤╤Б╤В╨░╨▓╨╗╤П╨╡╤В╤Б╤П ╨╕╨╖ ╤Г╤З╨╡╨▒╨╜╨╕╨║╨░, ╨╜╨╛ **╨╜╨░╨┐╨╕╤Б╨░╨╜╨╜╤Л╨╣ ╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╨╡╨╝ `homework_prompt` ╨░╨▓╤В╨╛╨╝╨░╤В ╨╜╨╡ ╤В╤А╨╛╨│╨░╨╡╤В**, ╨░ ╨╜╨╡╨┤╨╛╤Б╤В╤Г╨┐╨╜╤Л╨╣ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║ ╨╛╤В╨║╤А╤Л╤В╨╕╨╡ ╨╜╨╡ ╨▒╨╗╨╛╨║╨╕╤А╤Г╨╡╤В тАФ ╤Г╤А╨╛╨║ ╨╛╤В╨║╤А╨╛╨╡╤В╤Б╤П ╤Б ╨╛╤В╤Б╤Л╨╗╨╛╤З╨╜╨╛╨╣ ╤Д╨╛╤А╨╝╤Г╨╗╨╕╤А╨╛╨▓╨║╨╛╨╣. ╨Ю╨│╤А╨░╨╜╨╕╤З╨╡╨╜╨╕╨╡ D14: ╤В╨╡╨║╤Б╤В ╨Ъ╨╛╤З╨╡╤А╨│╨╕╨╜╨╛╨╣ ╨▓ ╤Н╤В╨╛╤В ╨┐╤Г╨▒╨╗╨╕╤З╨╜╤Л╨╣ ╤А╨╡╨┐╨╛╨╖╨╕╤В╨╛╤А╨╕╨╣ ╨╜╨╡ ╨║╨╛╨╝╨╝╨╕╤В╨╕╤В╤Б╤П (╤З╨╕╤В╨░╨╡╤В╤Б╤П ╨╕╨╖ ╤Б╨╛╤Б╨╡╨┤╨╜╨╡╨│╨╛ ╨║╨╗╨╛╨╜╨░ ╨┐╨╛ ╨┐╤Г╤В╨╕ ╨╕╨╖ ╨║╨╛╨╜╤Д╨╕╨│╨░) ╨╕ ╨▓ `is_free`/`is_preview` ╤Г╤А╨╛╨║╨╕ ╨╜╨╡ ╨┐╨╕╤И╨╡╤В╤Б╤П тАФ ╤В╨░╨╝ ╨╛╤В╤Б╤Л╨╗╨║╨░ ╨╕ warning ╨▓ ╨╗╨╛╨│; ╨▓ ╤Д╨╕╨║╤Б╤В╤Г╤А╨░╤Е ╤В╨╡╤Б╤В╨╛╨▓ ╤Б╨╕╨╜╤В╨╡╤В╨╕╤З╨╡╤Б╨║╨╕╨╣ mdx-╤Д╤А╨░╨│╨╝╨╡╨╜╤В, ╨╜╨╡ ╨║╤Г╤Б╨╛╨║ ╨║╨╜╨╕╨│╨╕. ╨Я╨╕╨╗╨╛╤В ╨▓╨║╨╗╤О╤З╨░╨╡╤В╤Б╤П ╨║╨╛╨╜╤Д╨╕╨│╨╛╨╝: `course_slugs` ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О ╨┐╤Г╤Б╤В, ╤В╨░╨║ ╤З╤В╨╛ ╨╜╨░ ╨╝╨╡╤А╨╢╨╡ ╨░╨▓╤В╨╛╨╝╨░╤В ╨┐╨╛ ╨┐╨╛╤Б╤В╤А╨╛╨╡╨╜╨╕╤О ╨╜╨╡ ╨┤╨╡╨╗╨░╨╡╤В ╨╜╨╕╤З╨╡╨│╨╛ тАФ ╨╜╤Г╨╢╨╡╨╜ ╨╡╤Й╤С ╤И╨░╨│ ╤З╨╡╨╗╨╛╨▓╨╡╨║╨░ (╨┐╤А╨╛╤Б╤В╨░╨▓╨╕╤В╤М `textbook_lesson` ╨╜╨░ ╨┐╤П╤В╨╕ ╤Г╤А╨╛╨║╨░╤Е ╨╕ ╨▓╨╜╨╡╤Б╤В╨╕ ╨║╤Г╤А╤Б ╨▓ ╨╛╤Е╨▓╨░╤В). ╨Ч╨░╤А╨┐╨╗╨░╤В╨╜╤Л╨╣ ╨╕ ╨┐╨╗╨░╤В╤С╨╢╨╜╤Л╨╣ ╨║╨╛╨╜╤В╤Г╤А╤Л ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В╤Л: ╨┐╤А╨╛╨│╨╛╨╜ ╨┤╨╛/╨┐╨╛╤Б╨╗╨╡ ╨╜╨░ ╨╛╨┤╨╜╨╛╨╣ ╨▒╨░╨╖╨╡ ╨┤╨░╨╗ ╤В╨╡ ╨╢╨╡ ╤З╨╕╤Б╨╗╨░ тАФ `TeacherSalary` 19 passed / 50 assertions, `Payment` 177 passed / 581 assertions. 13 ╤В╨╡╤Б╤В╨╛╨▓ ╨┐╤А╨╕╤С╨╝╨║╨╕ A1тАУA13 / 49 assertions, Pint ╤З╨╕╤Б╤В. ╨Т╨╛╨╗╨╜╤Л 2 ╨╕ 3 ╨╜╨╡ ╨▓╤Е╨╛╨┤╤П╤В: `close_previous` ╨▓╤Л╨║╨╗╤О╤З╨╡╨╜, ╤В╨░╨▒╨╗╨╕╤Ж╨░ ╨│╤А╨░╨╜╤В╨╛╨▓ ╨╜╨╡ ╨╖╨░╨▓╨╛╨┤╨╕╤В╤Б╤П. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.61.0] - 2026-07-28

### Added
- **TG-╨░╨╗╨╡╤А╤В ╨┐╤Г╨╗╤М╤Б╨░ ╨║╨░╨▒╨╕╨╜╨╡╤В╨░ (H1777 follow-up).** `cabinet:probe` ╨┐╤А╨╕ ╨┐╨░╨┤╨╡╨╜╨╕╨╕ ╤И╨╗╤С╤В HTML-╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡ ╨╛╤Б╨╜╨╛╨▓╨╜╤Л╨╝ ╨▒╨╛╤В╨╛╨╝ (`TELEGRAM_BOT_TOKEN`) ╨▓ `CABINET_PROBE_TELEGRAM_CHAT_ID` (╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О `ADMIN_TELEGRAM_ID`); cooldown 60 ╨╝╨╕╨╜, ┬л╤Б╨╜╨╛╨▓╨░ ╨╢╨╕╨▓┬╗ ╨╛╨┤╨╕╨╜ ╤А╨░╨╖ ╨┐╨╛╤Б╨╗╨╡ ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╤П; sync-HTTP ╨▒╨╡╨╖ ╨╛╤З╨╡╤А╨╡╨┤╨╕ (╨╜╨╡ ╨╖╨░╨▓╨╕╤Б╨╕╤В ╨╛╤В Horizon); `--force-alert` ╨╕╨│╨╜╨╛╤А╨╕╤А╤Г╨╡╤В cooldown. healthchecks.io ╨┐╨╛-╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г ╨╛╨┐╤Ж╨╕╨╛╨╜╨░╨╗╨╡╨╜. Executor: Grok 4.5 (`grok-4.5`).

### Fixed
- **`payments.first_paid_at` + backfill тАФ closes the resurrection-guard's audit blind spot (H1645, H1405 C3).**
 `Payment::hasPriorPaidTransition()` (the H1359 resurrection guard, `WebhookController` guard b) read only `payment_audits`, which exists only since 08-06-2026, and inspected only the *new* value of each status diff тАФ so a payment paid-and-reversed before the observer existed, or created already-paid via `withoutEvents` (silent `PromiseFulfillment::fulfil()`, `BlockAccessMaterializer`, `ConditionalAccessGranter`), left the guard blind even with `TOCHKA_WEBHOOK_GUARD=true`. Added a nullable `payments.first_paid_at` timestamp, stamped once on the first transition into `PAID_STATUSES` (`fireOnPaid`, via a `withoutEvents` direct-column update so no observer recurses and other `updated` listeners' `isDirty('status')` stays intact) and written directly into all three `withoutEvents` create-as-paid payloads; the audit walk itself is hardened to check **both** sides of each status diff. `hasPriorPaidTransition()` checks `first_paid_at !== null` first, falling back to the (now-hardened) audit walk while the column is unbackfilled тАФ a strict superset of prior detection, so no new flag was needed. One-shot idempotent, dry-run-by-default `php artisan payments:backfill-first-paid-at [--apply]` backfills existing rows from the earliest audit evidence or `created_at`; the genuinely unrecoverable residue (paid-and-reversed entirely before 08-06-2026, zero audit trace) stays `null` and its count is printed, not silently dropped. `FirstPaidAtTest` (11 tests) + existing `TochkaWebhookTest` (13) stay green. Migration/backfill are **not** run against prod by an agent тАФ DEPLOY_QUEUE row 62 (Ivan). Executor: Sonnet 5 (`claude-sonnet-5`).

### Added
- **╨а╤Г╤Б╤Б╨║╨╕╨╣ ╨╝╨╕╨║╤А╨╛╨║╨╛╨┐╨╕╤А╨░╨╣╤В ╨║╨░╨▒╨╕╨╜╨╡╤В╨░ тАФ ╨┤╨╛╨╗╨│╨╕, ╤Б╤В╨░╤В╤Г╤Б╤Л ╨Ф╨Ч, ╤Б╨╗╨╛╨▓╨░╤А╤М, ╨┐╤А╨░╨╜╨░, ╨┐╤Г╤Б╤В╤Л╨╡ ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╤П (H1756).** ╨в╨╕╨║╨╡╤В╤Л 2тАУ6 ╨░╤Г╨┤╨╕╤В╨░ [MANUALS_TO_UI_CONTENT_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUALS_TO_UI_CONTENT_AUDIT_2026.md) ╨┤╨╛╨▓╨╡╨┤╨╡╨╜╤Л ╨╛╤В ┬лdocument-only┬╗ ╨┤╨╛ ╤А╨╡╨░╨╗╤М╨╜╤Л╤Е ╨┐╤А╨╡╨┤╨╗╨╛╨╢╨╡╨╜╨╕╨╣ ╨▓╨╛ ╨▓╤М╤О╤Е╨░╤Е: ╨▒╨░╨╜╨╜╨╡╤А ╨▓╨║╨╗╨░╨┤╨║╨╕ ┬л╨Ь╨╛╨╕ ╨┤╨╛╨╗╨│╨╕┬╗ ╤В╨╡╨┐╨╡╤А╤М ╤А╨░╨╖╨╗╨╕╤З╨░╨╡╤В ┬л╨╜╨╡ ╨┐╤А╨╛╨┤╨╗╨╕╨╗┬╗ (╨╛╨┐╨╗╨░╤В╨╕╤В╤М ╨▒╨╗╨╛╨║ тАФ ╨┤╨╛╤Б╤В╤Г╨┐ ╨╛╤В╨║╤А╨╛╨╡╤В╤Б╤П ╨░╨▓╤В╨╛╨╝╨░╤В╨╕╤З╨╡╤Б╨║╨╕) ╨╕ ╤Б╨╛╨│╨╗╨░╤Б╨╛╨▓╨░╨╜╨╜╤Г╤О ╤А╨░╤Б╤Б╤А╨╛╤З╨║╤Г (╨┐╨╛ ╨│╤А╨░╤Д╨╕╨║╤Г ╨╕╨╗╨╕ ╤Б╤А╨░╨╖╤Г ╨▓╤Б╤С; ╤В╤А╨╕ ╨▓╨░╤А╨╕╨░╨╜╤В╨░ ╨┐╨╛ `promise_active`/`promise_overdue`, ╨▒╨╡╨╖ ╤Б╤В╤Л╨┤╤П╤Й╨╡╨│╨╛ ╤А╨╡╨│╨╕╤Б╤В╤А╨░); ╤Г ╨▒╨╡╨╣╨┤╨╢╨░ ╤Б╤В╨░╤В╤Г╤Б╨░ ╨Ф╨Ч ╤З╨╡╤В╤Л╤А╨╡ tooltip-╨┐╨╛╨┤╤Б╨║╨░╨╖╨║╨╕ ╨╕╨╖ ╤В╨░╨▒╨╗╨╕╤Ж╤Л `onboarding-student.md` (╤З╨╡╤А╨╜╨╛╨▓╨╕╨║ / ╨╜╨░ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╡ / ╨╜╨░ ╨┤╨╛╤А╨░╨▒╨╛╤В╨║╤Г / ╨┐╤А╨╕╨╜╤П╤В╨╛); placeholder ╨┐╨╛╨╕╤Б╨║╨░ ╨▓ ╤Б╨╗╨╛╨▓╨░╤А╨╡ ╨╜╨░╨╖╤Л╨▓╨░╨╡╤В ╨▓╤Б╨╡ ╤З╨╡╤В╤Л╤А╨╡ ╤А╨╡╨╢╨╕╨╝╨░ (┬л╨Ш╤Й╨╕╤В╨╡ ╨┤╨╡╨▓╨░╨╜╨░╨│╨░╤А╨╕, IAST, ╨║╨╕╤А╨╕╨╗╨╗╨╕╤Ж╨╡╨╣ ╨╕╨╗╨╕ ╨┐╨╡╤А╨╡╨▓╨╛╨┤╨╛╨╝тАж┬╗); ╨╜╨╛╨▓╨░╤П help-╤Б╤В╤А╨░╨╜╨╕╤Ж╨░ `/help/prana-balance` ┬л╨Я╨╛╤З╨╡╨╝╤Г ╨▒╨░╨╗╨░╨╜╤Б ╨┐╤А╨░╨╜╤Л ╤Г╨╝╨╡╨╜╤М╤И╨╕╨╗╤Б╤П?┬╗ (╨┐╨╛╨║╤Г╨┐╨║╨░ ╨┐╨╡╤А╨║╨░, ╤Б╨║╨╕╨┤╨║╨░ ╨┐╤А╨╕ ╨╛╨┐╨╗╨░╤В╨╡, P2P-╨┐╨╡╤А╨╡╨▓╨╛╨┤, decay-╨┐╤Г╨╜╨║╤В ╤Г╤Б╨╗╨╛╨▓╨╡╨╜ ╨╜╨░ `config('prana.decay.enabled')`; ╤А╨░╨╜╨│ ╨╕ ╨▒╨╡╨╣╨┤╨╢╨╕ ╨╜╨╡ ╤Б╨│╨╛╤А╨░╤О╤В) ╤Б╨╛ ╤Б╤Б╤Л╨╗╨║╨╛╨╣ ╨╕╨╖ ╨║╨░╤А╤В╨╛╤З╨║╨╕ ╨▒╨░╨╗╨░╨╜╤Б╨░ ╨┐╤А╨░╨╜╤Л; ╨╛╨┤╨╜╨╛-╤Б╤В╤А╨╛╤З╨╜╤Л╨╡ ╨┐╤Г╤Б╤В╤Л╨╡ ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╤П ╨▓╨╝╨╡╤Б╤В╨╛ ╨┐╤Г╤Б╤В╨╛╤В╤Л: ╨╕╤Б╤В╨╛╤А╨╕╤П ╨╛╨┐╨╗╨░╤В, ╨┤╨╛╤Б╤В╨╕╨╢╨╡╨╜╨╕╤П, ┬л╨Ь╨╛╨╕ ╨┐╨╛╨║╤Г╨┐╨║╨╕┬╗ ╨╝╨░╨│╨░╨╖╨╕╨╜╨░ ╨┐╤А╨░╨╜╤Л. ╨в╨╕╨║╨╡╤В 1 (┬л╨Я╨╛╤З╨╡╨╝╤Г ╨╖╨░╨║╤А╤Л╤В╨╛?┬╗) ╨╕╤Б╨║╨╗╤О╤З╤С╨╜ тАФ `AccessDiagnosticsService` ╨╡╤Й╤С ╨╜╨╡ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╨╡╤В. Executor: Fable 5 (`claude-fable-5`).

## [1.60.0] - 2026-07-28

### Changed
- **╨б╤А╨╛╨║ ╨╢╨╕╨╖╨╜╨╕ ╤Б╨╡╤Б╤Б╨╕╨╕ ╨┐╨╛╨┤╨╜╤П╤В ╤Б╨╛ 120 ╨╝╨╕╨╜╤Г╤В ╨┤╨╛ 1440 (╤Б╤Г╤В╨║╨╕) тАФ H1765/H1774.** Ruling MG 28-07-2026 ╨┐╨╛ ╤Б╨╗╨╡╨┤╨░╨╝ ╤А╨░╨╖╨▒╨╛╤А╨░ ┬л419 Page Expired┬╗ ╨╜╨░ ╨▓╤Е╨╛╨┤╨╡. ╨Ф╨▓╤Г╤Е╤З╨░╤Б╨╛╨▓╨░╤П ╤Б╨╡╤Б╤Б╨╕╤П ╨╛╨╖╨╜╨░╤З╨░╨╗╨░, ╤З╤В╨╛ ╨▓╨║╨╗╨░╨┤╨║╨░, ╨┐╤А╨╛╤Б╤В╨╛╤П╨▓╤И╨░╤П ╨╛╨▒╨╡╨┤, ╨│╨░╤А╨░╨╜╤В╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛ ╨╗╨╛╨▓╨╕╤В ╨┐╤А╨╛╤В╤Г╤Е╤И╨╕╨╣ CSRF-╤В╨╛╨║╨╡╨╜ ╨╜╨░ ╤Б╨░╨▒╨╝╨╕╤В╨╡: H1765 ╤Б╨┤╨╡╨╗╨░╨╗ ╤Н╤В╨╛╤В ╤Б╨╗╤Г╤З╨░╨╣ ╨┐╤А╨╛╤Е╨╛╨┤╨╕╨╝╤Л╨╝ (╨▓╨╜╤П╤В╨╜╨╛╨╡ ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡ ╨▓╨╝╨╡╤Б╤В╨╛ ╤В╤Г╨┐╨╕╨║╨░), ╨╜╨╛ ╨╜╨╡ ╤А╨╡╨┤╨║╨╕╨╝. ╨б╤Г╤В╨║╨╕ ╤Г╨▒╨╕╤А╨░╤О╤В ╨╝╨░╤Б╤Б╨╛╨▓╤Л╨╣ ╤Б╤Ж╨╡╨╜╨░╤А╨╕╨╣ ┬л╨▓╨║╨╗╨░╨┤╨║╨░ ╨┐╤А╨╛╤Б╤В╨╛╤П╨╗╨░ ╨╜╨╛╤З╤М/╨╛╨▒╨╡╨┤┬╗, ╨░ ╨╜╨╡ ╤Б╤Г╤В╨║╨╕-╨┐╨╗╤О╤Б тАФ ╨┐╨╛╤В╨╛╨╝╤Г ╤З╤В╨╛ ╨╜╨░ ╤В╨╛╨╣ ╨╢╨╡ ╤Б╨╡╤Б╤Б╨╕╨╕ ╤Б╨╕╨┤╤П╤В ╨╛╨▒╨╡ ╨░╨┤╨╝╨╕╨╜╨║╨╕ Filament, ╨╕ ╨┤╨╡╤А╨╢╨░╤В╤М ╨╡╤С ╨╢╨╕╨▓╨╛╨╣ ╨╜╨╡╨┤╨╡╨╗╤П╨╝╨╕ ╨╜╨░ ╨╛╨▒╤Й╨╡╨╝ ╨║╨╛╨╝╨┐╤М╤О╤В╨╡╤А╨╡ ╨┤╨╛╤А╨╛╨╢╨╡, ╤З╨╡╨╝ ╨▓╤Л╨╕╨│╤А╤Л╤И ╨▓ ╤Г╨┤╨╛╨▒╤Б╤В╨▓╨╡. ╨Ч╨┤╨╡╤Б╤М ╨╝╨╡╨╜╤П╨╡╤В╤Б╤П **╤В╨╛╨╗╤М╨║╨╛ `.env.example`** (╨┤╨╡╤Д╨╛╨╗╤В ╨┤╨╗╤П ╨╜╨╛╨▓╤Л╤Е ╨╛╨║╤А╤Г╨╢╨╡╨╜╨╕╨╣); ╨╜╨░ ╨┐╤А╨╛╨┤╨╡ ╤Н╤В╨╛ ╨┐╤А╨░╨▓╨║╨░ `.env` + `php artisan optimize:clear && php artisan optimize`, ╤И╨░╨│ ╨╛╨┐╨╡╤А╨░╤В╨╛╤А╨░. ╨Ю╤Б╤В╨░╤В╨╛╤З╨╜╤Л╨╡ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨╕ ╨┐╤А╨╛╨╝╨░╤Е╨╛╨▓ тАФ bfcache ╨╕ ╨┐╨╡╤А╨╡╤Е╨╛╨┤ ╨╕╨╖ ╨▓╤Б╤В╤А╨╛╨╡╨╜╨╜╨╛╨│╨╛ ╨▒╤А╨░╤Г╨╖╨╡╤А╨░ ╨╝╨╡╤Б╤Б╨╡╨╜╨┤╨╢╨╡╤А╨░ ╨▓ ╨╛╨▒╤Л╤З╨╜╤Л╨╣ тАФ ╤Б╤А╨╛╨║╨╛╨╝ ╤Б╨╡╤Б╤Б╨╕╨╕ ╨╜╨╡ ╨╗╨╡╤З╨░╤В╤Б╤П ╨╕ ╨╖╨░╨║╤А╤Л╨▓╨░╤О╤В╤Б╤П ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛ ╨▓ [H1774](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1774-Sonnet_Systema-Sanscriticum_csrf-token-refresh-login-and-shop-modal_28.07.26.md) ╨┐╨╡╤А╨╡╨╕╤Б╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╨╜╨╕╨╡╨╝ `GET /csrf-token`. Executor: Opus 5 1M (`claude-opus-5[1m]`).

### Added
- **╨Я╤Г╨╗╤М╤Б ╨║╨░╨▒╨╕╨╜╨╡╤В╨░ тАФ `cabinet:probe` (H1777).** Homepage-uptime ╨╕ `heartbeat:ping` ╨╜╨╡ ╨▓╨╕╨┤╤П╤В ┬л/ ╨╛╤В╨┤╨░╤С╤В 200, ╨░ ╨║╨░╨▒╨╕╨╜╨╡╤В/Auth/Filament ╨╗╨╡╨╢╨░╤В┬╗. ╨а╨░╨╖ ╨▓ 15 ╨╝╨╕╨╜ (`CABINET_PROBE_CRON`, default `*/15`) ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║ ╨╗╨╛╨│╨╕╨╜╨╕╤В smoke-╨╝╨╡╨╜╨╡╨┤╨╢╨╡╤А╨░ (`TEST_MANAGER_*`) in-process ╨╕ GET-╨╕╤В `/dvaram`, `/messages`, `/calendar`, `/open-lessons`, `/admin` (+ `/library|/progress|/access` ╤В╨╛╨╗╤М╨║╨╛ ╨┐╤А╨╕ `features.cabinet_hybrid`); ╨╗╨╛╨▓╨╕╤В 4xx/5xx ╨╕ ╨╝╨░╤А╨║╨╡╤А╤Л Whoops/SQLSTATE. Fail-open: ╨▒╨╡╨╖ ╨┐╨░╤А╨╛╨╗╤П тАФ no-op; ╨║╨╛╨╝╨░╨╜╨┤╨░ ╨▓╤Б╨╡╨│╨┤╨░ SUCCESS (╨╜╨╡ ╨▓╨░╨╗╨╕╤В ╤Б╨╗╨╛╤В schedule), ╤В╤А╨╡╨▓╨╛╨│╨░ тАФ `CABINET_PROBE_PING_URL` healthchecks.io + `/fail` + `Log::error`. 10 ╤В╨╡╤Б╤В╨╛╨▓. Executor: Grok 4.5 (`grok-4.5`).

- **Smoke-╨╝╨╡╨╜╨╡╨┤╨╢╨╡╤А ╨╕╨╖ `.env` тАФ `users:ensure-test-manager`.** ╨г╨╖╨║╨╕╨╣ `role=manager` ╨┤╨╗╤П ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕ ╨▓╤Е╨╛╨┤╨░ ╨╕ CRM ╨▒╨╡╨╖ ╨▓╤Л╨┤╨░╤З╨╕ super_admin (╤В╨╡ ╨╛╤Б╤В╨░╤О╤В╤Б╤П ╤Г ╨У╨░╤Б╤Г╨╜╤Б╨░ ╨╕ ╨Ш╨▓╨░╨╜╨░). `TEST_MANAGER_EMAIL` / `TEST_MANAGER_PASSWORD` / `TEST_MANAGER_NAME` ╨▓ `.env` (╤И╨░╨▒╨╗╨╛╨╜ ╨▓ `.env.example`); ╨┐╨░╤А╨╛╨╗╤М ╤В╨╛╨╗╤М╨║╨╛ ╨╜╨░ ╤Б╨╡╤А╨▓╨╡╤А╨╡, ╨▓ git ╨╜╨╡ ╨┐╨╛╨┐╨░╨┤╨░╨╡╤В. ╨Ъ╨╛╨╝╨░╨╜╨┤╨░ ╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╜╨░: ╤Б╨╛╨╖╨┤╨░╤С╤В ╨╕╨╗╨╕ ╨┐╨╡╤А╨╡-╤Б╨╕╨╜╤Е╤А╨╛╨╜╨╕╨╖╨╕╤А╤Г╨╡╤В ╨┐╨░╤А╨╛╨╗╤М; no-op ╨┐╤А╨╕ ╨┐╤Г╤Б╤В╨╛╨╝ ╨┐╨░╤А╨╛╨╗╨╡; ╨╛╤В╨║╨░╨╖╤Л╨▓╨░╨╡╤В╤Б╤П ╨┐╨╡╤А╨╡╨╖╨░╨┐╨╕╤Б╤Л╨▓╨░╤В╤М super_admin/admin/accountant/teacher ╨╕ ╤З╤Г╨╢╨╛╨╣ student-email. 6 ╤В╨╡╤Б╤В╨╛╨▓. Executor: Grok 4.5 (`grok-4.5`).


## [1.59.1] - 2026-07-28

### Fixed
- **┬л419 Page Expired┬╗ ╨╜╨░ ╨▓╤Е╨╛╨┤╨╡ тАФ ╨╕ ╨┐╨╛╨╗╤В╨╛╤А╨░ ╨╝╨╡╤Б╤П╤Ж╨░ ╨╝╤С╤А╤В╨▓╨╛╨╣ ╨▓╨╡╤В╨║╨╕ ╨╜╨░ ╨╛╨┐╨╗╨░╤В╨╡ (H1765).** ╨б╤В╤Г╨┤╨╡╨╜╤В╤Л 27-07-2026 ╤Г╨┐╨╕╤А╨░╨╗╨╕╤Б╤М ╨╜╨░ `POST /login` ╨╕ `/shop/login` ╨▓ ╨│╨╛╨╗╤Г╤О ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Г 419 ╨▒╨╡╨╖ ╨▓╤Л╤Е╨╛╨┤╨░ тАФ ╤Н╤В╨╛ ╨╕ ╤З╨╕╤В╨░╨╗╨╛╤Б╤М ╨║╨░╨║ ┬л╤Б╨░╨╣╤В ╨╜╨╡ ╨┐╤Г╤Б╨║╨░╨╡╤В┬╗. ╨в╨╛╨║╨╡╨╜ ╨╜╨░ ╨▓╤Е╨╛╨┤╨╡ ╨┐╤А╨╛╤В╤Г╤Е╨░╨╡╤В ╤И╤В╨░╤В╨╜╨╛: ╨▓╨║╨╗╨░╨┤╨║╨░ ╨┐╤А╨╛╤Б╤В╨╛╤П╨╗╨░ ╨┤╨╛╨╗╤М╤И╨╡ ╤Б╨╡╤Б╤Б╨╕╨╕, ╨┐╨╛╨▓╤В╨╛╤А╨╜╤Л╨╣ ╤Б╨░╨▒╨╝╨╕╤В ╨┐╨╛ ╤Г╨╢╨╡ ╨╛╤В╨║╤А╤Л╤В╨╛╨╣ ╤Б╤В╤А╨░╨╜╨╕╤Ж╨╡, ╨┐╨╡╤А╨╡╤Е╨╛╨┤ ╨╕╨╖ ╨▓╤Б╤В╤А╨╛╨╡╨╜╨╜╨╛╨│╨╛ ╨▒╤А╨░╤Г╨╖╨╡╤А╨░ ╨╝╨╡╤Б╤Б╨╡╨╜╨┤╨╢╨╡╤А╨░ ╨▓ ╨╛╨▒╤Л╤З╨╜╤Л╨╣. ╨Э╨╛ ╨│╨╗╨░╨▓╨╜╨╛╨╡ ╨╜╨░╤И╨╗╨╛╤Б╤М ╨┐╤А╨╕ ╨┐╨╡╤А╨▓╨╛╨╝ ╨╢╨╡ ╨┐╤А╨╛╨│╨╛╨╜╨╡ ╤В╨╡╤Б╤В╨░: ╨║╨╛╨╗╨▒╤Н╨║ `renderable(function (TokenMismatchException $e, ...))` ╨▓ [`app/Exceptions/Handler.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Exceptions/Handler.php) **╨╜╨╡ ╤Б╤А╨░╨▒╨░╤В╤Л╨▓╨░╨╡╤В ╨╜╨╕╨║╨╛╨│╨┤╨░** тАФ `Handler::render()` ╤Б╨╜╨░╤З╨░╨╗╨░ ╨╖╨╛╨▓╤С╤В `prepareException()`, ╨║╨╛╤В╨╛╤А╤Л╨╣ ╨╝╨╡╨╜╤П╨╡╤В `TokenMismatchException` ╨╜╨░ `HttpException(419, ..., previous: $e)`, ╨╕ ╤В╨╛╨╗╤М╨║╨╛ ╨┐╨╛╤В╨╛╨╝ ╨╛╨▒╤Е╨╛╨┤╨╕╤В renderable-╨║╨╛╨╗╨▒╤Н╨║╨╕ (Laravel 12, `Foundation/Exceptions/Handler.php`, ╤Б╤В╤А╨╛╨║╨╕ 616тАУ620). ╨Ч╨╜╨░╤З╨╕╤В, ╨╕ ╨▓╨╡╤В╨║╨░ ╤З╨╡╨║╨░╤Г╤В╨░ ╨╕╨╖ [`295ea8b8`](https://github.com/gasyoun/Systema-Sanscriticum/commit/295ea8b8) (┬л╤Г╤Б╤В╤А╨░╨╜╨╡╨╜╨╕╨╡ 419 ╨┐╤А╨╕ ╤Б╨░╨▒╨╝╨╕╤В╨╡ ╨╛╨┐╨╗╨░╤В╤Л┬╗, 25-06-2026, **╨▒╨╡╨╖ ╤В╨╡╤Б╤В╨░**) ╨▒╤Л╨╗╨░ ╨╝╨╡╤А╤В╨▓╨░ ╤Б ╤А╨╛╨╢╨┤╨╡╨╜╨╕╤П: ╨╛╨▒╨╡╤Й╨░╨╜╨╜╨╛╨│╨╛ ╨╝╤П╨│╨║╨╛╨│╨╛ ╤А╨╡╤В╤А╨░╤П ╨╜╨░ ╨╛╨┐╨╗╨░╤В╨╡ ╨╜╨╡ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╨╛╨▓╨░╨╗╨╛, ╤Б╨╕╨╝╨┐╤В╨╛╨╝ ╨╗╨╕╤И╤М ╤З╨░╤Б╤В╨╕╤З╨╜╨╛ ╨╝╨░╤Б╨║╨╕╤А╨╛╨▓╨░╨╗╨╛ JS-╨┐╨╛╨┤╤В╤П╨│╨╕╨▓╨░╨╜╨╕╨╡ ╤Б╨▓╨╡╨╢╨╡╨│╨╛ ╤В╨╛╨║╨╡╨╜╨░ ╨╕╨╖ ╤В╨╛╨│╨╛ ╨╢╨╡ ╨║╨╛╨╝╨╝╨╕╤В╨░. ╨в╨╡╨┐╨╡╤А╤М ╨║╨╛╨╗╨▒╤Н╨║ ╤В╨╕╨┐╨╕╨╖╨╕╤А╨╛╨▓╨░╨╜ ╨╛╨▒╤С╤А╤В╨║╨╛╨╣ ╨╕ ╤Б╨░╨╝ ╨┐╤А╨╛╨▓╨╡╤А╤П╨╡╤В, ╤З╤В╨╛ ╤Н╤В╨╛ CSRF (╤Б╤В╨░╤В╤Г╤Б 419 **╨╕** `previous instanceof TokenMismatchException`), ╨╕╨╜╨░╤З╨╡ ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В `null`. ╨Я╨╛╨▓╨╡╨┤╨╡╨╜╨╕╨╡ ╨┐╨╛ ╨┐╨╛╨▓╨╡╤А╤Е╨╜╨╛╤Б╤В╤П╨╝ ╤А╨░╨╖╨╜╨╛╨╡ ╨╕ ╨╛╤Б╨╛╨╖╨╜╨░╨╜╨╜╨╛: `/login` ╨╕ ╤З╨╡╨║╨░╤Г╤В ╨┐╨╛╨╗╤Г╤З╨░╤О╤В `redirect()->back()` ╤Б ╤Д╨╗╨╡╤И-╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡╨╝ (╤Б╨▓╨╡╨╢╨╕╨╣ CSRF ╨╡╨┤╨╡╤В ╨╜╨░ ╤В╨╛╨╣ ╨╢╨╡ ╤Б╤В╤А╨░╨╜╨╕╤Ж╨╡, ╨┐╨╛╨▓╤В╨╛╤А╨╜╤Л╨╣ ╤Б╨░╨▒╨╝╨╕╤В ╨┐╤А╨╛╤Е╨╛╨┤╨╕╤В; ╨▓ `auth/login.blade.php` ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ ╨▒╨╗╨╛╨║ `session('error')` тАФ ╤А╨░╨╜╤М╤И╨╡ ╤И╨░╨▒╨╗╨╛╨╜ ╤А╨╕╤Б╨╛╨▓╨░╨╗ ╤В╨╛╨╗╤М╨║╨╛ `session('status')`, ╨╕ ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡ ╤Г╤И╨╗╨╛ ╨▒╤Л ╨▓ ╨╜╨╕╨║╤Г╨┤╨░), ╨░ `/shop/login` тАФ JSON 419 `{success:false,message}` ╤Б ╨┐╤А╨╛╤Б╤М╨▒╨╛╨╣ ╨╛╨▒╨╜╨╛╨▓╨╕╤В╤М ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Г: ╨╝╨╛╨┤╨░╨╗╨║╨░ ╨╜╨░ Alpine ╤З╨╕╤В╨░╨╡╤В ╨╕╨╝╨╡╨╜╨╜╨╛ ╤Н╤В╤Г ╤Д╨╛╤А╨╝╤Г ╨╛╤В╨▓╨╡╤В╨░, ╨░ `<meta name="csrf-token">` ╨▒╨╡╨╖ ╨┐╨╡╤А╨╡╨╖╨░╨│╤А╤Г╨╖╨║╨╕ ╨╛╤Б╤В╨░╨╜╨╡╤В╤Б╤П ╤Б╤В╨░╤А╤Л╨╝, ╤В╨░╨║ ╤З╤В╨╛ ┬л╨┐╨╛╨┐╤А╨╛╨▒╤Г╨╣╤В╨╡ ╨╡╤Й╤С ╤А╨░╨╖┬╗ ╨┐╤А╨╛╨▓╨░╨╗╨╕╨╗╨╛╤Б╤М ╨▒╤Л ╤В╨╡╨╝ ╨╢╨╡ 419. ╨в╨╕╨┐ `HttpException` тАФ ╤Б╨╡╤В╤М ╨╖╨░╨╝╨╡╤В╨╜╨╛ ╤И╨╕╤А╨╡ ╨┐╤А╨╡╨╢╨╜╨╡╨╣, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨┐╨╕╨╜╨░╨╡╤В╤Б╤П ╤В╨╡╤Б╤В╨╛╨╝, ╤З╤В╨╛ `418` ╨╕ ┬л╨│╨╛╨╗╤Л╨╣┬╗ `abort(419)` ╨▒╨╡╨╖ CSRF-╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╤П ╤Г╤Е╨╛╨┤╤П╤В ╨┤╨╡╤Д╨╛╨╗╤В╨╜╨╛╨╝╤Г ╨╛╨▒╤А╨░╨▒╨╛╤В╤З╨╕╨║╤Г ╨╜╨╡╤В╤А╨╛╨╜╤Г╤В╤Л╨╝╨╕; ╨▓╨╛╤Б╨║╤А╨╡╤И╤С╨╜╨╜╨░╤П ╨▓╨╡╤В╨║╨░ ╤З╨╡╨║╨░╤Г╤В╨░ ╨┐╨╛╨╗╤Г╤З╨╕╨╗╨░ ╤Б╨╛╨▒╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╨╛╨╜╨╜╤Л╨╣ ╤В╨╡╤Б╤В тАФ ╨╛╨╜╨░ ╤Г╨╢╨╡ ╨╛╨┤╨╕╨╜ ╤А╨░╨╖ ╤Г╨╝╨╡╤А╨╗╨░ ╨╝╨╛╨╗╤З╨░. 4 ╤В╨╡╤Б╤В╨░ / 11 assertions; ╨┐╤А╨╛╨│╨╛╨╜ login/checkout/payment/auth тАФ 318 passed (1046 assertions), Pint ╤З╨╕╤Б╤В. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.59.0] - 2026-07-28

### Added
- **╨Я╨╗╨░╨╜ ╨░╨▓╤В╨╛╨╛╤В╨║╤А╤Л╤В╨╕╤П ╨┐╤А╨╕╤С╨╝╨░ ╨┤╨╛╨╝╨░╤И╨╜╨╕╤Е ╨╖╨░╨┤╨░╨╜╨╕╨╣ (H1764).** ╨Я╤А╨╕╤С╨╝ ╨Ф╨Ч ╨┐╨╛ ╨┐╨╡╤А╨▓╤Л╨╝ ╨┐╤П╤В╨╕ ╨╖╨░╨╜╤П╤В╨╕╤П╨╝ ╨│╤А╨░╨╝╨╝╨░╤В╨╕╨║╨╕ ╨Ъ╨╛╤З╨╡╤А╨│╨╕╨╜╨╛╨╣ ╨┤╨╛╨╗╨╢╨╡╨╜ ╨╛╤В╨║╤А╤Л╨▓╨░╤В╤М╤Б╤П ╤Б╨░╨╝ ╨╜╨░ ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╕╨╣ ╨┤╨╡╨╜╤М ╨┐╨╛╤Б╨╗╨╡ ╨┐╤А╨╛╨▓╨╡╨┤╤С╨╜╨╜╨╛╨│╨╛ ╤Г╤А╨╛╨║╨░ тАФ ╤Б╨╡╨│╨╛╨┤╨╜╤П ╤Н╤В╨╛ ╨╛╨┤╨╕╨╜ ╨▒╤Г╨╗╨╡╨▓ `lessons.homework_enabled`, ╨║╨╛╤В╨╛╤А╤Л╨╣ ╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤М ╤Й╤С╨╗╨║╨░╨╡╤В ╤А╤Г╨║╨░╨╝╨╕, ╨╕ ╨╜╨╕╨║╨░╨║╨╛╨│╨╛ ╨╛╤В╨║╤А╤Л╤В╨╕╤П ╨┐╨╛ ╨▓╤А╨╡╨╝╨╡╨╜╨╕ ╨▓ ╤Б╨╕╤Б╤В╨╡╨╝╨╡ ╨╜╨╡╤В ╨▓╨╛╨╛╨▒╤Й╨╡. ╨б╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╨░╨╜ ╨┐╨╛╨╗╨╜╤Л╨╣ ╨║╨╛╨╜╤В╤Г╤А: ╤В╨╛╤З╨║╨░ ╨╛╤В╤Б╤З╤С╤В╨░ `recording_attached_at` (╨┐╨╛╤П╨▓╨╗╨╡╨╜╨╕╨╡ ╨╖╨░╨┐╨╕╤Б╨╕), ╨╛╤В╨║╤А╤Л╤В╨╕╨╡ ╨▓ ╨▒╨╗╨╕╨╢╨░╨╣╤И╨╡╨╡ 09:00 ╨Ь╨б╨Ъ ╨┐╨╛╤Б╨╗╨╡ +12 ╤З, ╨░╨▓╤В╨╛╨┐╨╛╨┤╤Б╤В╨░╨╜╨╛╨▓╨║╨░ ╤Г╤Б╨╗╨╛╨▓╨╕╤П ╨╕╨╖ ╨╛╤Ж╨╕╤Д╤А╨╛╨▓╨║╨╕ ╤Г╤З╨╡╨▒╨╜╨╕╨║╨░, ╨┐╤Г╤И ╨▓ Telegram/VK, ╨╖╨░╨║╤А╤Л╤В╨╕╨╡ ╨┐╤А╨╕ ╨╛╤В╨║╤А╤Л╤В╨╕╨╕ ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╡╨│╨╛ ╤Г╤А╨╛╨║╨░ ╨╕ ╤В╨╛╤З╨╡╤З╨╜╤Л╨╣ ╨│╤А╨░╨╜╤В ╨┤╨╗╤П ╨┤╨╛╨│╨╛╨╜╤П╤О╤Й╨╕╤Е. ╨Ф╨▓╨░ ╤Д╨░╨║╤В╨░, ╤Г╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╜╤Л╤Е ╨░╤Г╨┤╨╕╤В╨╛╨╝ ╨╕ ╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╤Е ╤З╤В╨╛╨▒╤Л ╨╕╤Е ╨╜╨╡ ╨╕╤Б╨║╨░╨╗╨╕ ╤Б╨╜╨╛╨▓╨░: ╤Г `schedules` **╨╜╨╡╤В `lesson_id`** (╨╖╨░╨╜╤П╤В╨╕╨╡ ╨╕ ╤Г╤А╨╛╨║ ╤Б╨▓╤П╨╖╨░╨╜╤Л ╤В╨╛╨╗╤М╨║╨╛ ╤Б╨╛╨│╨╗╨░╤И╨╡╨╜╨╕╨╡╨╝ ╨┐╨╛ ╨║╨╗╤О╤З╤Г `(course_id, group_id, lesson_date)`, ╤В╨╡╨╝ ╨╢╨╡, ╤З╤В╨╛ ╤Г n8n `storeFromZoom`), ╨░ ╨╝╨╡╤В╨╛╨┤╨╕╤З╨║╨░-╨║╨╛╨╝╨┐╨░╨╜╤М╨╛╨╜ ╨┐╨╛╨║╤А╤Л╨▓╨░╨╡╤В ╨╖╨░╨╜╤П╤В╨╕╤П VIтАУXXXIX ╨╕ ╨╖╨░╨╜╤П╤В╨╕╨╣ 1тАУ5 ╨▓ ╨╜╨╡╨╣ ╨╜╨╡╤В тАФ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║ ╤В╨╛╨╗╤М╨║╨╛ ╤Б╨░╨╝ ╤Г╤З╨╡╨▒╨╜╨╕╨║. `hub_grep` ╨┐╨╛ ╨▓╤Б╨╡╨╝ ╤Е╨░╨▒╨░╨╝ ╨╛╤А╨│╨░╨╜╨╕╨╖╨░╤Ж╨╕╨╕: ╨┐╤А╨╡╤Ж╨╡╨┤╨╡╨╜╤В╨╛╨▓ ╨░╨▓╤В╨╛╨╛╤В╨║╤А╤Л╤В╨╕╤П ╨┤╨╛╨╝╨░╤И╨╡╨║ ╨╜╨╛╨╗╤М, ╨╝╨╡╤Е╨░╨╜╨╕╨╖╨╝ ╨┐╨╕╤И╨╡╤В╤Б╤П ╨▓╨┐╨╡╤А╨▓╤Л╨╡. 16 ╤А╤Г╨╗╨╕╨╜╨│╨╛╨▓ ╨╕╨╜╤В╨╡╤А╨▓╤М╤О ╨╖╨░╨║╤А╤Л╨▓╨░╤О╤В ╨▓╤Б╨╡ ╤А╨░╨╖╨▓╨╕╨╗╨║╨╕ ╨▓╨╛╨╗╨╜╤Л 1; ╨║╨╛╨┤╨░ ╨▓ ╤Н╤В╨╛╨╣ ╨┐╨╛╤Б╤В╨░╨▓╨║╨╡ ╨╜╨╡╤В тАФ ╤В╨╛╨╗╤М╨║╨╛ ╨┐╨╗╨░╨╜ ╨╕╨╖ ╨┐╤П╤В╨╕ ╤Б╨╗╨╛╤С╨▓. Executor: Opus 5 1M (`claude-opus-5[1m]`).
- **╨Я╤Г╨╗╤М╤Б ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║╨░ ╨╜╨░ healthchecks.io тАФ `heartbeat:ping` (H1713).** ╨Т╤В╨╛╤А╨░╤П ╨┐╨╛╨╗╨╛╨▓╨╕╨╜╨░ ╨╖╨░╨║╤А╤Л╤В╨╕╤П ╨┐╤А╨╛╤Б╤В╨╛╤П 24тАУ26.07.2026 ([#730](https://github.com/gasyoun/Systema-Sanscriticum/issues/730)): ╨▓╨╜╨╡╤И╨╜╨╕╨╣ ╨╝╨╛╨╜╨╕╤В╨╛╤А ╨┤╨╛╤Б╤В╤Г╨┐╨╜╨╛╤Б╤В╨╕ ╨╛╤В╨▓╨╡╤З╨░╨╡╤В ╨╜╨░ ╨▓╨╛╨┐╤А╨╛╤Б ┬л╨╛╤В╨┤╨░╤С╤В ╨╗╨╕ ╤Б╨░╨╣╤В ╨┐╤А╨░╨▓╨╕╨╗╤М╨╜╤Г╤О ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Г┬╗, ╨╜╨╛ ╤Б╨╗╨╡╨┐ ╨║ ╤Б╤Ж╨╡╨╜╨░╤А╨╕╤О ┬л╤Б╨░╨╣╤В ╨╢╨╕╨▓, ╨░ ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║ ╨╕╨╗╨╕ Horizon ╤Г╨╝╨╡╤А╨╗╨╕┬╗ тАФ ╤Б╨╜╨░╤А╤Г╨╢╨╕ ╨╛╨╜ ╨╜╨╡╨╛╤В╨╗╨╕╤З╨╕╨╝ ╨╛╤В ╨╜╨╛╤А╨╝╤Л, ╨░ ╨▓╨╜╤Г╤В╤А╨╕ ╤Б╤В╨╛╤П╤В ╤А╨░╤Б╤Б╤Л╨╗╨║╨╕, ╨╜╨░╨┐╨╛╨╝╨╕╨╜╨░╨╜╨╕╤П, ╤Б╨┐╨╕╤Б╨░╨╜╨╕╤П ╨╕ ╨▓╤Л╨║╨╗╨░╨┤╨║╨░. ╨Э╨╛╨▓╨░╤П ╨║╨╛╨╝╨░╨╜╨┤╨░ ╤А╨░╨╖ ╨▓ 5 ╨╝╨╕╨╜╤Г╤В (`HEARTBEAT_CRON`) ╨┤╤С╤А╨│╨░╨╡╤В ╤Г╨╜╨╕╨║╨░╨╗╤М╨╜╤Л╨╣ URL ╨╜╨░ [healthchecks.io](https://healthchecks.io); ╤В╤А╨╡╨▓╨╛╨│╤Г ╨┐╨╛╨┤╨╜╨╕╨╝╨░╨╡╤В **╨╝╨╛╨╗╤З╨░╨╜╨╕╨╡**, ╨░ ╨╜╨╡ ╨╛╤И╨╕╨▒╨║╨░, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╤Б╤В╨╛╤А╨╛╨╢ ╨┐╨╡╤А╨╡╨╢╨╕╨▓╨░╨╡╤В ╤Б╨╝╨╡╤А╤В╤М ╨▓╤Б╨╡╨│╨╛ ╤Б╨╡╤А╨▓╨╡╤А╨░ тАФ ╨▓ ╨╛╤В╨╗╨╕╤З╨╕╨╡ ╨╛╤В ╨╗╤О╨▒╨╛╨╣ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕, ╨╢╨╕╨▓╤Г╤Й╨╡╨╣ ╨╜╨░ ╤Б╨░╨╝╨╛╨╣ ╨╝╨░╤И╨╕╨╜╨╡. ╨Ч╨░╨╛╨┤╨╜╨╛ ╨┐╤А╨╛╨▓╨╡╤А╤П╨╡╤В╤Б╤П Horizon (`MasterSupervisorRepository`): ╨╝╤С╤А╤В╨▓╤Л╨╣ ╨╕╨╗╨╕ ╨┐╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╨╜╤Л╨╣ ╨╜╨░ ╨┐╨░╤Г╨╖╤Г ╨╝╨░╤Б╤В╨╡╤А-╨┐╤А╨╛╤Ж╨╡╤Б╤Б ╤И╨╗╤С╤В `<url>/fail`, ╤В╨╛ ╨╡╤Б╤В╤М ╤В╤А╨╡╨▓╨╛╨│╨░ ╨┐╨╛╨┤╨╜╨╕╨╝╨░╨╡╤В╤Б╤П ╤Б╤А╨░╨╖╤Г, ╨╜╨╡ ╨┤╨╛╨╢╨╕╨┤╨░╤П╤Б╤М ╨╕╤Б╤В╨╡╤З╨╡╨╜╨╕╤П ╨┐╨╡╤А╨╕╨╛╨┤╨░. ╨Т╤Б╤С fail-open: ╨┐╤Г╤Б╤В╨╛╨╣ `HEARTBEAT_PING_URL` тЖТ ╨║╨╛╨╝╨░╨╜╨┤╨░ ╨╝╨╛╨╗╤З╨╕╤В ╨╕ ╨╖╨░╨▓╨╡╤А╤И╨░╨╡╤В╤Б╤П ╤Г╤Б╨┐╨╡╤Е╨╛╨╝, ╨╜╨╡╨┤╨╛╤Б╤В╤Г╨┐╨╜╨░╤П ╤Б╨╡╤В╤М, 500 ╨╛╤В ╤Б╨╡╤А╨▓╨╕╤Б╨░ ╨╕ ╤Г╨┐╨░╨▓╤И╨╕╨╣ Redis ╤В╨╛╨╢╨╡ ╨╜╨╡ ╤А╨╛╨╜╤П╤О╤В ╨┐╤А╨╛╨│╨╛╨╜ тАФ ╤Б╤В╨╛╤А╨╛╨╢, ╨╛╨▒╤А╤Г╤И╨╕╨▓╨░╤О╤Й╨╕╨╣ ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║, ╨▓╤А╨╡╨┤╨╜╨╡╨╡ ╨▒╨╛╨╗╨╡╨╖╨╜╨╕ (╤Г╨┐╨░╨▓╤И╨░╤П ╨║╨╛╨╝╨░╨╜╨┤╨░ ╤В╤П╨╜╨╡╤В ╨╖╨░ ╤Б╨╛╨▒╨╛╨╣ ╤Б╨╛╤Б╨╡╨┤╨╡╨╣ ╨┐╨╛ ╤Б╨╗╨╛╤В╤Г). `evenInMaintenanceMode()`, ╤З╤В╨╛╨▒╤Л ╨▓╤Л╨║╨╗╨░╨┤╨║╨░ ╨╜╨╡ ╨┐╤А╨╡╨▓╤А╨░╤Й╨░╨╗╨░╤Б╤М ╨▓ ╨╗╨╛╨╢╨╜╤Г╤О ╤В╤А╨╡╨▓╨╛╨│╤Г; `onOneServer()` ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛ ╨Э╨Х ╤Б╤В╨░╨▓╨╕╤В╤Б╤П тАФ ╨┐╤Г╨╗╤М╤Б ╨╛╨▒╤П╨╖╨░╨╜ ╨╕╨┤╤В╨╕ ╨╕ ╨┐╤А╨╕ ╨╜╨╡╨┤╨╛╤Б╤В╤Г╨┐╨╜╨╛╨╝ Redis-╨╗╨╛╨║╨╡, ╨░ ╨╗╨╡╨╢╨░╤Й╨╕╨╣ Redis ╨║╨░╨║ ╤А╨░╨╖ ╨▓╤Е╨╛╨┤╨╕╤В ╨▓ ╨╛╤В╤Б╨╗╨╡╨╢╨╕╨▓╨░╨╡╨╝╤Л╨╡ ╨╛╤В╨║╨░╨╖╤Л. 9 ╤В╨╡╤Б╤В╨╛╨▓ (`SchedulerHeartbeatTest`) ╨╖╨░╨║╤А╤Л╨▓╨░╤О╤В ╨╛╨▒╨╡ ╨▓╨╡╤В╨║╨╕ ╨╕ ╨▓╤Б╨╡ ╤З╨╡╤В╤Л╤А╨╡ ╤Б╤Ж╨╡╨╜╨░╤А╨╕╤П fail-open. Executor: Opus 5 1M (`claude-opus-5[1m]`).

### Changed
- **╨з╨╡╤Б╤В╨╜╨░╤П ╤З╨░╤Б╤В╨╛╤В╨░ ╨▓╨╜╨╡╤И╨╜╨╡╨│╨╛ ╨╝╨╛╨╜╨╕╤В╨╛╤А╨░ ╨▓╨╝╨╡╤Б╤В╨╛ ╨╛╨▒╨╡╤Й╨░╨╜╨╜╨╛╨╣ (H1713).** ╨Ъ╨╛╨╝╨╝╨╡╨╜╤В╨░╤А╨╕╨╣ ╨▓ [`uptime-samskrte.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/uptime-samskrte.yml) ╤Г╤В╨▓╨╡╤А╨╢╨┤╨░╨╗, ╤З╤В╨╛ ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║ GitHub ┬л╨╛╨┐╨░╨╖╨┤╤Л╨▓╨░╨╡╤В ╨╜╨░ 5тАУ15 ╨╝╨╕╨╜╤Г╤В┬╗. ╨Ч╨░╨╝╨╡╤А ╨╖╨░ ╨┐╨╡╤А╨▓╤Л╨╡ ╤Б╤Г╤В╨║╨╕ ╤А╨░╨▒╨╛╤В╤Л ╨╝╨╛╨╜╨╕╤В╨╛╤А╨░ (27-07-2026) ╤Н╤В╨╛ ╨╛╨┐╤А╨╛╨▓╨╡╤А╨│: ╨┤╨╛╨╡╤Е╨░╨╗╨╛ **10 ╨┐╨╗╨░╨╜╨╛╨▓╤Л╤Е ╨┐╤А╨╛╨│╨╛╨╜╨╛╨▓ ╨╕╨╖ 123 (8.1 %)**, ╨╝╨╡╨┤╨╕╨░╨╜╨╜╤Л╨╣ ╤А╨░╨╖╤А╤Л╨▓ ╨╝╨╡╨╢╨┤╤Г ╨┐╤А╨╛╨│╨╛╨╜╨░╨╝╨╕ 122 ╨╝╨╕╨╜, ╨╝╨░╨║╤Б╨╕╨╝╨░╨╗╤М╨╜╤Л╨╣ **230 ╨╝╨╕╨╜**. ╨а╨╡╨░╨╗╤М╨╜╨╛╨╡ ╨▓╤А╨╡╨╝╤П ╨╛╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜╨╕╤П ╨┐╤А╨╛╤Б╤В╨╛╤П тАФ ╤З╨░╤Б╤Л, ╨░ ╨╜╨╡ ╨╝╨╕╨╜╤Г╤В╤Л; ╨┐╤А╨╛╤В╨╕╨▓ ╨┤╨▓╤Г╤Е╤Б╤Г╤В╨╛╤З╨╜╨╛╨│╨╛ ╨╝╨╛╨╗╤З╨░╨╜╨╕╤П ╤Н╤В╨╛ ╨┐╨╛-╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г ╨╛╨│╤А╨╛╨╝╨╜╤Л╨╣ ╨▓╤Л╨╕╨│╤А╤Л╤И, ╨╜╨╛ ╨╛╨▒╨╡╤Й╨░╤В╤М ╨┤╨╡╤Б╤П╤В╤М ╨╝╨╕╨╜╤Г╤В ╨╜╨╡╨╗╤М╨╖╤П. ╨Ы╨╛╨╢╨╜╨░╤П ╤Г╨▓╨╡╤А╨╡╨╜╨╜╨╛╤Б╤В╤М ╨▓ ╤З╨░╤Б╤В╨╛╤В╨╡ тАФ ╤В╨░ ╨╢╨╡ ╨▒╨╛╨╗╨╡╨╖╨╜╤М, ╤З╤В╨╛ ╨╕ ╨╜╨╡╨╜╨░╤Б╤В╤А╨╛╨╡╨╜╨╜╨╛╨╡ ╨╛╨┐╨╛╨▓╨╡╤Й╨╡╨╜╨╕╨╡, ╤А╨░╨┤╨╕ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕ ╨║╨╛╤В╨╛╤А╨╛╨│╨╛ ╨╖╨░╨▓╨╡╨┤╤С╨╜ `force_alert`. ╨Ъ╨╛╨╝╨╝╨╡╨╜╤В╨░╤А╨╕╨╣ ╨╖╨░╨╝╨╡╨╜╤С╨╜ ╨╜╨░ ╨╕╨╖╨╝╨╡╤А╨╡╨╜╨╜╤Л╨╡ ╤З╨╕╤Б╨╗╨░ ╤Б ╤Г╨║╨░╨╖╨░╨╜╨╕╨╡╨╝, ╤З╤В╨╛ ╨╝╨╕╨╜╤Г╤В╨╜╤Г╤О ╤В╨╛╤З╨╜╨╛╤Б╤В╤М ╨┤╨░╤С╤В ╨┐╤Г╨╗╤М╤Б ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║╨░, ╨░ ╨╜╨╡ GitHub-╨║╤А╨╛╨╜. Executor: Opus 5 1M (`claude-opus-5[1m]`).

## [1.58.0] - 2026-07-27

### Added
- **╨Я╤А╨╛╨▓╨╡╤А╤П╤О╤Й╨╕╨╡ ╨┤╨╛╨╝╨░╤И╨╡╨║ ╨┐╨╛ ╨│╤А╤Г╨┐╨┐╨░╨╝ тАФ ╨▓╨╛╨╗╨╜╨░ A ╨┐╨╗╨░╨╜╨░ ╨▓╨╖╨░╨╕╨╝╨╛╨╖╨░╤З╤С╤В╨░ (H1729).** ╨Э╨╛╨▓╤Л╨╣ pivot `group_reviewer` (`groups` ├Ч **`users`**) ╨┤╨░╤С╤В ╤З╨╡╨╗╨╛╨▓╨╡╨║╤Г ╨┤╨╛╤Б╤В╤Г╨┐ ╨║ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╡ ╨┤╨╛╨╝╨░╤И╨╡╨║ ╨║╨╛╨╜╨║╤А╨╡╤В╨╜╤Л╤Е ╨│╤А╤Г╨┐╨┐, ╨╜╨╡ ╨┤╨╡╨╗╨░╤П ╨╡╨│╨╛ ╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╨╡╨╝ ╨║╤Г╤А╤Б╨░: ╨Ю╨╗╤М╨│╨░ ╨Ы╨╕╤В╨▓╨╕╨╜╨╡╨╜╨║╨╛ ╨┐╤А╨╛╨▓╨╡╤А╤П╨╡╤В ╨│╤А╤Г╨┐╨┐╤Л 60/61 ╨У╨░╤Б╤Г╨╜╤Б╨░. ╨Я╤А╨╕╨▓╤П╨╖╨║╨░ ╨║ `users`, ╨░ ╨╜╨╡ ╨║ `teachers`, тАФ ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨░╤П: ╤Б╨╛-╨┐╤А╨╡╨┐╨╛╨┤ ╨▓ `course_teacher` ╨┐╨╛╨┐╨░╨┤╨░╨╡╤В ╨▓ `Course::salaryTermsFor()` (╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В ╤Г╤Б╨╗╨╛╨▓╨╕╤П ╨Ч╨Я ╨╕╨╖ pivot, **╨╜╨╡** `null`), ╨╕ `TeacherSalaryService` ╨╜╨░╤З╨╕╤Б╨╗╨╕╨╗ ╨▒╤Л ╨┐╤А╨╛╨▓╨╡╤А╤П╤О╤Й╨╡╨╝╤Г ╨╖╨░╤А╨┐╨╗╨░╤В╤Г ╤Б ╨▓╤Л╤А╤Г╤З╨║╨╕ ╤З╤Г╨╢╨╕╤Е ╨║╤Г╤А╤Б╨╛╨▓; ╨╖╨░╨║╤А╤Л╤В╨╛ ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╨╛╨╜╨╜╤Л╨╝ ╤В╨╡╤Б╤В╨╛╨╝ `grant_does_not_accrue_salary_for_the_reviewer`. ╨Я╤А╨░╨▓╨╕╨╗╨╛ ╨▓╨╕╨┤╨╕╨╝╨╛╤Б╤В╨╕ тАФ ╨╡╨┤╨╕╨╜╤Л╨╣ `HomeworkSubmission::scopeInReviewableGroups()`: ╤Г ╤Г╤А╨╛╨║╨░ ╨┐╤А╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╨░ `group_id` тАФ ╤Б╨▓╨╡╤А╤П╨╡╨╝ ╨┐╨╛ ╨╜╨╡╨╣, ╤Г╤А╨╛╨║ ╨╛╨▒╤Й╨╕╨╣ тАФ ╤В╤А╨╡╨▒╤Г╨╡╨╝ ╤Б╨╛╨▓╨┐╨░╨┤╨╡╨╜╨╕╤П **╨╕** ╤З╨╗╨╡╨╜╤Б╤В╨▓╨░ ╤Г╤З╨╡╨╜╨╕╨║╨░ ╨▓ ╨┐╨╛╨┤╤И╨╡╤Д╨╜╨╛╨╣ ╨│╤А╤Г╨┐╨┐╨╡, **╨╕** ╨┐╤А╨╕╨▓╤П╨╖╨║╨╕ ╨║╤Г╤А╤Б╨░ ╨║ ╨╜╨╡╨╣ (╨▒╨╡╨╖ ╨▓╤В╨╛╤А╨╛╨│╨╛ ╤Г╤Б╨╗╨╛╨▓╨╕╤П ╨┐╤А╨╛╨▓╨╡╤А╤П╤О╤Й╨╕╨╣ ╨│╤А╤Г╨┐╨┐╤Л 60 ╨▓╨╕╨┤╨╡╨╗ ╨▒╤Л ╤А╨░╨▒╨╛╤В╤Л ╤В╨╛╨│╨╛ ╨╢╨╡ ╤Г╤З╨╡╨╜╨╕╨║╨░ ╨┐╨╛ ╤З╤Г╨╢╨╛╨╝╤Г ╨║╤Г╤А╤Б╤Г). ╨б╨▓╨╛╤П ╤А╨░╨▒╨╛╤В╨░ ╨▓ ╤Б╨▓╨╛╤О ╨╛╤З╨╡╤А╨╡╨┤╤М ╨╜╨╡ ╨┐╨╛╨┐╨░╨┤╨░╨╡╤В ╨╜╨╕╨║╨╛╨│╨┤╨░. ╨У╤А╨░╨╜╤В ╤А╨░╤Б╤И╨╕╤А╤П╨╡╤В ╨╛╤З╨╡╤А╨╡╨┤╤М ╨┤╨╛╨╝╨░╤И╨╡╨║ (╨▓╨╡╤В╨║╨░ `orWhere` ╤А╤П╨┤╨╛╨╝ ╤Б╨╛ ┬л╤Б╨▓╨╛╨╕╨╝╨╕ ╨║╤Г╤А╤Б╨░╨╝╨╕┬╗, ╨╜╨╡ ╨▓╨╝╨╡╤Б╤В╨╛) ╨╕ ╤Б╨╛╤Б╤В╨░╨▓ ╨│╤А╤Г╨┐╨┐╤Л (`StudentGroupResource`); ╨║╤Г╤А╤Б╤Л/╤Г╤А╨╛╨║╨╕/╤А╨░╤Б╨┐╨╕╤Б╨░╨╜╨╕╨╡/╤Б╨╡╤А╤В╨╕╤Д╨╕╨║╨░╤В╤Л ╨╜╨╡ ╨╛╤В╨║╤А╤Л╨▓╨░╨╡╤В тАФ ╨╖╨░╨║╤А╤Л╤В╨╛ ╨╜╨╡╨│╨░╤В╨╕╨▓╨╜╤Л╨╝╨╕ ╤В╨╡╤Б╤В╨░╨╝╨╕. ╨г╨▓╨╡╨┤╨╛╨╝╨╗╨╡╨╜╨╕╤П тАФ ╨╜╨╛╨▓╤Л╨╣ `HomeworkNotifier`: ╨┐╤А╨╛╨▓╨╡╤А╤П╤О╤Й╨╡╨╝╤Г ╨║╨╛╨╗╨╛╨║╨╛╨╗╤М╤З╨╕╨║ ╨▓ ╨░╨┤╨╝╨╕╨╜╨║╨╡ + ╨┐╨╕╤Б╤М╨╝╨╛ + Telegram (╨║╨░╨╜╨░╨╗╤Л ╨▓ `config/homework.php`), ╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤О ╨║╤Г╤А╤Б╨░ ╨┐╨╡╤А╤Б╨╛╨╜╨░╨╗╤М╨╜╤Л╨╡ ╨┐╨╕╤Б╤М╨╝╨░ ╨╖╨░╨╝╨╡╨╜╤П╤О╤В╤Б╤П ╨╜╨╡╨┤╨╡╨╗╤М╨╜╨╛╨╣ ╤Б╨▓╨╛╨┤╨║╨╛╨╣ `homework:reviewer-digest` (╨┐╨╛╨╜╨╡╨┤╨╡╨╗╤М╨╜╨╕╨║ 09:00) тАФ ╨╜╨╛ **╤В╨╛╨╗╤М╨║╨╛** ╨┤╨╗╤П ╨│╤А╤Г╨┐╨┐ ╤Б ╨░╨║╤В╨╕╨▓╨╜╤Л╨╝ ╨┐╤А╨╛╨▓╨╡╤А╤П╤О╤Й╨╕╨╝, ╨║╤Г╤А╤Б╤Л ╨▒╨╡╨╖ ╨╜╨╕╤Е ╨▓╨╡╨┤╤Г╤В ╤Б╨╡╨▒╤П ╨║╨░╨║ ╤А╨░╨╜╤М╤И╨╡. ╨У╤А╨░╨╜╤В╤Л ╤А╨░╨╖╨┤╨░╤С╤В ╤В╨╛╨╗╤М╨║╨╛ ╨░╨┤╨╝╨╕╨╜ (╨┤╨╡╨╣╤Б╤В╨▓╨╕╨╡ ┬л╨Я╤А╨╛╨▓╨╡╤А╤П╤О╤Й╨╕╨╡┬╗ ╨▓ `GroupResource`). 20 ╨╜╨╛╨▓╤Л╤Е ╤В╨╡╤Б╤В╨╛╨▓. Executor: Opus 5 1M (`claude-opus-5[1m]`).

- **╨Т╨╖╨░╨╕╨╝╨╛╨╖╨░╤З╤С╤В ┬л╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤М-╤Г╤З╨╡╨╜╨╕╨║┬╗: ╨░╨║╤В, ╤Б╨▓╨╡╤А╨║╨░ ╨┤╨▓╤Г╤Е ╤Ж╨╕╤Д╤А, ╨╖╨░╤З╤С╤В ╨┤╨╛ ╨▓╤Л╨┐╨╗╨░╤В╤Л (H1730, ╨▓╨╛╨╗╨╜╨░ B).** ╨з╨╡╨╗╨╛╨▓╨╡╨║, ╨║╨╛╤В╨╛╤А╤Л╨╣ ╨╛╨┤╨╜╨╛╨▓╤А╨╡╨╝╨╡╨╜╨╜╨╛ ╨┐╨╗╨░╤В╨╕╤В ╤И╨║╨╛╨╗╨╡ ╨║╨░╨║ ╤Г╤З╨╡╨╜╨╕╨║ ╨╕ ╨┐╨╛╨╗╤Г╤З╨░╨╡╤В ╨╛╤В ╨╜╨╡╤С ╨│╨╛╨╜╨╛╤А╨░╤А ╨║╨░╨║ ╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤М, ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨│╨╛╨╜╤П╨╡╤В ╨┤╨╡╨╜╤М╨│╨╕ ╤В╤Г╨┤╨░-╨╛╨▒╤А╨░╤В╨╜╨╛, ╤В╨╡╤А╤П╤П 6 % ╨Э╨Я╨Ф ╨╜╨░ ╨║╨░╨╢╨┤╨╛╨╝ ╨┐╤А╨╛╤Е╨╛╨┤╨╡ тАФ ╨╖╨░╤З╤С╤В ╤Б╤З╨╕╤В╨░╨╡╤В╤Б╤П ╨╕ ╨▓╤Л╤З╨╕╤В╨░╨╡╤В╤Б╤П **╨┤╨╛** ╤Б╨╛╨╖╨┤╨░╨╜╨╕╤П ╨▓╤Л╨┐╨╗╨░╤В╤Л (ruling MG 27-07-2026). `MutualSettlementService` ╤Б╤З╨╕╤В╨░╨╡╤В ╨╛╨▒╨╡ ╤Ж╨╕╤Д╤А╤Л ╤Б ╤А╨░╤Б╤И╨╕╤Д╤А╨╛╨▓╨║╨╛╨╣: ╨╛╨┐╨╗╨░╤В╤Л ╨║╨░╨║ ╤Г╤З╨╡╨╜╨╕╨║╨░ (╤В╨╡ ╨╢╨╡ ╨╕╤Б╨║╨╗╤О╤З╨░╨╡╨╝╤Л╨╡ ╤В╨░╤А╨╕╤Д╤Л `╨а╨░╤Б╤Е╨╛╨┤`/`salary_payout`, ╨▓╨╖╤П╤В╤Л╨╡ ╤Г `TeacherSalaryService` **╨║╨╛╨╜╤Б╤В╨░╨╜╤В╨╛╨╣, ╨░ ╨╜╨╡ ╨║╨╛╨┐╨╕╨╡╨╣ ╤Б╨┐╨╕╤Б╨║╨░**) ╨╕ accrual-╨╜╨░╤З╨╕╤Б╨╗╨╡╨╜╨╕╨╡ ╨║╨░╨║ ╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤О, ╨┤╨╡╨╗╨╡╨│╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛╨╡ `TeacherSalaryService::totalForTeacher()` тАФ ╨▓╤В╨╛╤А╨╛╨│╨╛ ╤А╨░╤Б╤З╤С╤В╨░ ╨Ч╨Я ╨╜╨╡ ╨╖╨░╨▓╨╡╨┤╨╡╨╜╨╛, ╤Ж╨╕╤Д╤А╨░ ╤Б╤Е╨╛╨┤╨╕╤В╤Б╤П ╤Б╨╛ ╤Б╤В╤А╨░╨╜╨╕╤Ж╨╡╨╣ ┬л╨Ч╨░╤А╨┐╨╗╨░╤В╤Л┬╗. ╨Ь╨╛╨┤╨╡╨╗╤М `mutual_settlements` ╤Д╨╕╨║╤Б╨╕╤А╤Г╨╡╤В ╤Б╨╜╨╕╨╝╨╛╨║ ╨╜╨░ ╨▒╨╗╨╛╨║/╨┐╨╛╤В╨╛╨║: **╤Д╨╕╨║╤Б╨░╤Ж╨╕╤П ╨╖╨░╨╝╨╛╤А╨░╨╢╨╕╨▓╨░╨╡╤В ╤З╨╕╤Б╨╗╨░, ╨░ ╨╜╨╡ ╨┐╨╡╤А╨╡╤Б╤З╨╕╤В╤Л╨▓╨░╨╡╤В** (╨┐╨╛╨╖╨┤╨╜╤П╤П ╨╛╨┐╨╗╨░╤В╨░ ╨╖╨░╨┤╨╜╨╕╨╝ ╤З╨╕╤Б╨╗╨╛╨╝ ╨╜╨╡ ╨┤╨▓╨╕╨│╨░╨╡╤В ╨┐╨╛╨┤╨┐╨╕╤Б╨░╨╜╨╜╤Г╤О ╤Ж╨╕╤Д╤А╤Г), ╨┐╨╡╤А╨╡╤Б╨╝╨╛╤В╤А ╤Б╨╛╨╖╨┤╨░╤С╤В ╨╜╨╛╨▓╤Г╤О ╤Б╤В╤А╨╛╨║╤Г ╨╕ ╨┐╨╡╤А╨╡╨▓╨╛╨┤╨╕╤В ╨┐╤А╨╡╨╢╨╜╤О╤О ╨▓ `superseded`. ╨б╤В╤А╨░╨╜╨╕╤Ж╨░ ┬л╨Т╨╖╨░╨╕╨╝╨╛╨╖╨░╤З╤С╤В┬╗ (`/admin/mutual-settlements`, ╨│╤А╤Г╨┐╨┐╨░ ┬л╨д╨╕╨╜╨░╨╜╤Б╤Л┬╗) ╨╖╨░╨║╤А╤Л╤В╨░ **╤В╨╡╨╝ ╨╢╨╡ ╨│╨╡╨╣╤В╨╛╨╝ `RoleGate::accounting()`, ╤З╤В╨╛ ╨╕ ╤Б╤В╤А╨░╨╜╨╕╤Ж╨░ ╨╖╨░╤А╨┐╨╗╨░╤В** тАФ ╨┐╤А╨╛╤З╨╕╤В╨░╨╜ ╤Д╨░╨║╤В╨╕╤З╨╡╤Б╨║╨╕╨╣ ╨│╨╡╨╣╤В, ╨░ ╨╜╨╡ ╨▓╤Л╨▒╤А╨░╨╜ ╨┐╨╛ ╨┤╨╛╨│╨░╨┤╨║╨╡. ╨Ъ╨╛╨╝╨░╨╜╨┤╨░ `settlement:preview` тАФ ╤А╨░╨╖╨╛╨▓╨░╤П ╤Б╨▓╨╡╤А╨║╨░ ╨╜╨░ ╨┐╤А╨╛╨┤╨╡, ╤В╨╛╨╗╤М╨║╨╛ ╤З╤В╨╡╨╜╨╕╨╡. ╨Ц╤С╨╗╤В╤Л╨╣ ╨▒╨╡╨╣╨┤╨╢ ╨╖╨░╨│╨╛╤А╨░╨╡╤В╤Б╤П, ╨║╨╛╨│╨┤╨░ ╨╢╨╕╨▓╨╛╨╡ ╨╜╨░╤З╨╕╤Б╨╗╨╡╨╜╨╕╨╡ ╨┐╨╡╤А╨╡╨▓╨╡╤Б╨╕╨╗╨╛ ╨╛╨┐╨╗╨░╤В╤Л. ╨Т╤Л╤А╤Г╤З╨║╨░ ╤И╨║╨╛╨╗╤Л ╨╜╨╡ ╤В╤А╨╛╨│╨░╨╡╤В╤Б╤П: ╨┐╨╗╨░╤В╨╡╨╢╨╕ ╨╛╤Б╤В╨░╤О╤В╤Б╤П ╨┤╨╛╤Е╨╛╨┤╨╛╨╝ ╨▓ ╨┐╨╛╨╗╨╜╨╛╨╝ ╨╛╨▒╤К╤С╨╝╨╡, ╨╖╨░╤З╤С╤В ╨╢╨╕╨▓╤С╤В ╤В╨╛╨╗╤М╨║╨╛ ╨╜╨░ ╨▓╤Л╨┐╨╗╨░╤В╨╜╨╛╨╣ ╤Б╤В╨╛╤А╨╛╨╜╨╡; ╨┐╤Г╤В╤М ┬л100 % ╤Б╨║╨╕╨┤╨║╨░ ╤Г╤З╨╡╨╜╨╕╨║╤Г┬╗ ╨╕ ╨┐╨╡╤А╨╡╨╕╤Б╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╨╜╨╕╨╡ `TeacherPayout.type=advance` ╨╛╤В╨▓╨╡╤А╨│╨╜╤Г╤В╤Л. ╨Ю╨┤╨╜╨╛╨║╤А╨░╤В╨╜╨╛╤Б╤В╤М ╨░╨║╤В╨░ тАФ ╤Г╤Б╨╗╨╛╨▓╨╜╤Л╨╝ `update` ╨╜╨░ ╤Г╤А╨╛╨▓╨╜╨╡ ╨┤╨░╨╜╨╜╤Л╤Е (`payout_id`), ╨┤╨▓╨╛╨╣╨╜╨╛╨╣ ╨╖╨░╤З╤С╤В ╨╜╨╡╨▓╨╛╨╖╨╝╨╛╨╢╨╡╨╜. **╨Я╤А╨░╨▓╨║╨░ ╨╖╨░╤А╨┐╨╗╨░╤В╨╜╨╛╨│╨╛ ╨║╨╛╨╜╤В╤Г╤А╨░ ╨░╨┤╨┤╨╕╤В╨╕╨▓╨╜╨░:** `blockPayoutTotal()` ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В, ╨▓╤Л╤З╨╡╤В ╤Б╨┤╨╡╨╗╨░╨╜ ╨┐╨╛ ╨╛╨▒╤А╨░╨╖╤Ж╤Г ╨░╨▓╨░╨╜╤Б╨░, ╨╕ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╣ ╨╜╨░╨▒╨╛╤А ╨╖╨░╤А╨┐╨╗╨░╤В╨╜╤Л╤Е ╤В╨╡╤Б╤В╨╛╨▓ ╨┤╨░╤С╤В **78 passed / 265 assertions ╨┤╨╛ ╨╕ ╨┐╨╛╤Б╨╗╨╡** тАФ ╨╕╨┤╨╡╨╜╤В╨╕╤З╨╜╨╛. 22 ╨╜╨╛╨▓╤Л╤Е ╤В╨╡╤Б╤В╨░ (`MutualSettlementTest`) ╨╖╨░╨║╤А╤Л╨▓╨░╤О╤В ╤В╨░╨▒╨╗╨╕╤Ж╤Г ╨┐╤А╨╕╤С╨╝╨║╨╕ ╨▓╨╛╨╗╨╜╤Л B. ╨Я╨╡╤А╨▓╨░╤П **╤А╨╡╨░╨╗╤М╨╜╨░╤П** ╤Б╨▓╨╡╤А╨║╨░ ╨╢╨┤╤С╤В read-only ╨┤╨╛╤Б╤В╤Г╨┐╨░ ╨║ ╨┐╤А╨╛╨┤-╨С╨Ф (╤В╨╛╤В ╨╢╨╡ ╨▒╨╗╨╛╨║╨╡╤А, ╤З╤В╨╛ ╨┤╨╡╤А╨╢╨╕╤В H165). Executor: Opus 5 1M (`claude-opus-5[1m]`).

- **Online Sanskrit games, Wave 2 тАФ registerтЖТSRS onboarding deck + cabinet skill-drill strip (H1680).** New `item_seen` telemetry event (`game_events.payload` JSON тАФ additive column, still no `user_id`/PII, see `the_table_stores_no_ip_or_user_agent_by_design`): a pack may opt in via `window.SGX_SEEN_ITEMS = [{iast, ru}, ...]` before `telemetry.js`'s deferred script runs (wired for `roots/top-25` as the reference pack); absent on every other pack тЖТ no new request. `SrsOnboardingFromGames` service matches purely on the existing `anon_id` (never a guest_id/user_id column) and, on first authenticated dashboard load per browser (`POST /api/games/srs-onboarding-import`, localStorage-deduped), imports up to 20 distinct lemmas that guest saw into the shared system `SrsDeck` (slug `onboarding-from-games`), idempotent `firstOrCreate` throughout тАФ a second call creates no duplicate cards, only (re)syncs the subscription. Cabinet dashboard shows "╨Ф╨╛╨▒╨░╨▓╨╕╨╗╨╕ N ╤Б╨╗╨╛╨▓ ╨▓ ╨┐╨╛╨▓╤В╨╛╤А╨╡╨╜╨╕╤П" via a dispatched Alpine event. Behind the existing `SRS_ENABLED` (still OFF by default, R-6) тАФ the import is a no-op while OFF. New independent cabinet page `/dvaram/skill-drills` links to short `/lila` drills, DISTINCT from the FSRS review loop at `/dvaram/koloda` тАФ gated by its own flag `features.games_skill_drills` (`GAMES_SKILL_DRILLS` env), OFF by default per Architecture ┬з5. Non-goals held: Wave 3 needs-engine games, audio, multiplayer, csl-guides, money contour. Executor: Sonnet 5 (`claude-sonnet-5`).

- **╨Я╨╗╨░╨╜: ╨▓╨╖╨░╨╕╨╝╨╛╨╖╨░╤З╤С╤В ┬л╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤М-╤Г╤З╨╡╨╜╨╕╨║┬╗ + ╨│╤А╤Г╨┐╨┐╨╛╨▓╤Л╨╡ ╨┐╤А╨╛╨▓╨╡╤А╤П╤О╤Й╨╕╨╡ ╨┤╨╛╨╝╨░╤И╨╡╨║ (H1729/H1730).** ╨Ъ╨╛╨╝╨┐╨╗╨╡╨║╤В ╨╕╨╖ ╨┐╤П╤В╨╕ ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨╛╨▓ ╨┐╨╛ ╨╕╤В╨╛╨│╨░╨╝ `/ask` 27-07-2026 (4 ╤А╨░╤Г╨╜╨┤╨░ ╨╕╨╜╤В╨╡╤А╨▓╤М╤О, 15 ╤А╨╡╤И╨╡╨╜╨╕╨╣ MG): [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS_2026H2.md) + ROADMAP + ARCHITECTURE + IMPLEMENTATION + VERIFICATION + ╨╝╨╡╤В╨░╨┤╨╛╨║. ╨Т╨╛╨╗╨╜╨░ A тАФ ╨┐╤А╨╕╨▓╤П╨╖╨║╨░ ┬л╨┐╤А╨╛╨▓╨╡╤А╤П╤О╤Й╨╕╨╣ тЖФ ╨│╤А╤Г╨┐╨┐╨░┬╗ (`group_reviewer`), ╤З╤В╨╛╨▒╤Л ╨Ю╨╗╤М╨│╨░ ╨Ы╨╕╤В╨▓╨╕╨╜╨╡╨╜╨║╨╛ ╨┐╤А╨╛╨▓╨╡╤А╤П╨╗╨░ ╨┤╨╛╨╝╨░╤И╨║╨╕ ╨│╤А╤Г╨┐╨┐ 60/61 ╨У╨░╤Б╤Г╨╜╤Б╨░ ╤Б ╨╛╨┐╨╛╨▓╨╡╤Й╨╡╨╜╨╕╨╡╨╝ ╨▓ ╨░╨┤╨╝╨╕╨╜╨║╨╡, **╨▒╨╡╨╖** ╨┐╤Г╤В╨╕ ┬л╤Б╨╛-╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤М┬╗, ╨║╨╛╤В╨╛╤А╤Л╨╣ ╤З╨╡╤А╨╡╨╖ `Course::salaryTermsFor()` ╨╜╨░╤З╨╕╤Б╨╗╨╕╨╗ ╨▒╤Л ╨╡╨╣ ╨Ч╨Я ╤Б ╤З╤Г╨╢╨╛╨╣ ╨▓╤Л╤А╤Г╤З╨║╨╕. ╨Т╨╛╨╗╨╜╨░ B тАФ ╨╝╨╛╨┤╨╡╨╗╤М `mutual_settlements`: ╨╛╨▒╨╡ ╤Ж╨╕╤Д╤А╤Л (╨╡╤С ╨╛╨┐╨╗╨░╤В╤Л ╨║╨░╨║ ╤Г╤З╨╡╨╜╨╕╨║╨░ ╨╕ accrual-╨╜╨░╤З╨╕╤Б╨╗╨╡╨╜╨╕╨╡ ╨║╨░╨║ ╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤О) ╤Д╨╕╨║╤Б╨╕╤А╤Г╤О╤В╤Б╤П ╨╜╨░ ╨▒╨╗╨╛╨║/╨┐╨╛╤В╨╛╨║, ╨╖╨░╤З╤С╤В ╨▓╤Л╤З╨╕╤В╨░╨╡╤В╤Б╤П ╨╕╨╖ ╨▓╤Л╨┐╨╗╨░╤В╤Л **╨┤╨╛** ╨╡╤С ╤Б╨╛╨╖╨┤╨░╨╜╨╕╤П, ╨▒╨╡╨╣╨┤╨╢ ╨╖╨░╨│╨╛╤А╨░╨╡╤В╤Б╤П ╨║╨╛╨│╨┤╨░ ╨╜╨░╤З╨╕╤Б╨╗╨╡╨╜╨╕╨╡ ╨┐╨╡╤А╨╡╨▓╨╡╤И╨╕╨▓╨░╨╡╤В ╨╛╨┐╨╗╨░╤В╤Л. ╨Я╤А╨░╨▓╨╛╨▓╨░╤П ╤А╨░╨╝╨║╨░ тАФ ╨Э╨Я╨Ф; ╨╖╨░╤З╤С╤В ╨┤╨╛ ╨▓╤Л╨┐╨╗╨░╤В╤Л тАФ ruling MG 27-07-2026. Planner: Opus 5 (`claude-opus-5[1m]`).

- **HTML-╨╝╨░╨╜╤Г╨░╨╗ ╨┐╨╛ ╨╕╨│╤А╨░╨╝ ╨Ы╨╕╨╗╨░ (docs/lila-games-manual.html).** ╨Я╨╛╨╗╨╜╨╛╨╡ ╤А╤Г╨║╨╛╨▓╨╛╨┤╤Б╤В╨▓╨╛: ╨┤╨╛╤Б╤В╤Г╨┐ ╨│╨╛╤Б╤В╤М/╤Б╤В╤Г╨┤╨╡╨╜╤В, ╤И╨╡╤Б╤В╤М ╤Б╨╡╨╝╨╡╨╣╤Б╤В╨▓, ╨║╨░╤В╨░╨╗╨╛╨│ ╤Б╤Б╤Л╨╗╨╛╨║, ╨╢╨╡╤Б╤В╤Л, FAQ, ╨▒╨╗╨╛╨║ ╨┤╨╗╤П ╨║╤Г╤А╨░╤В╨╛╤А╨░; ╨╝╨╡╤В╨░╨┤╨╛╨║ lila-games-manual.meta.md; ╤Б╤Б╤Л╨╗╨║╨░ ╨╕╨╖ student-manual ┬з12. Executor: Grok 4.5 (grok-4.5).

- **Online Sanskrit games, Wave 1 тАФ 5-play gate + CTAтЖТregister KPI + three P0 packs (H1678).** `/lila` gate widened from one global free play to **5 free plays per drill family** (`gate.js`, `sgx_plays_v2` localStorage key, family = first path segment under `/lila/`); authenticated users stay ungated. `GameEvent::ctaRegistrationRate()` computes the playтЖТregister KPI (locked D6: тЙе15% of CTA clickers merge into an authenticated user within 7 days) from the **existing** `anon_id`/`authenticated` columns тАФ deliberately without adding a `user_id` column, since `game_events` is intentionally kept outside the 152-╨д╨Ч perimeter (see `the_table_stores_no_ip_or_user_agent_by_design`); surfaced in `games:funnel` and the Filament ┬л╨Т╨╛╤А╨╛╨╜╨║╨░ ╤В╤А╨╡╨╜╨░╨╢╤С╤А╨╛╨▓┬╗ page. New shared `public/lila/locale.js` (RU/EN string picker + toggle, default RU, falls back to RU when EN copy is missing). Three P0 packs: **G-C01** `sort/vowel-length` (24 curated CV syllables, short vs long vowel, no Devan─Бgar─л), **G-C02** `match/iast-cyrillic` (40 pairs reused from the genders/verb-roots packs + a documented pedagogical Cyrillic transliteration), **G-C03** `match/kochergina-l1` (20 words from the H1431 Kochergina lesson-1 export, `database/seeders/data/memrise_6502608/level_02.csv`). Catalogue cards + free-banner copy updated. Non-goals held: P1 packs (H1679), SRS onboarding (H1680), csl-guides, new engines, audio, multiplayer тАФ `SRS_ENABLED` untouched. Executor: Sonnet 5 (`claude-sonnet-5`).

- **Online Sanskrit games, Wave 1 тАФ three P1 engine-fill packs (H1679).** **G-C04** `roots/ru-faces` (top-25 verbal roots, RU gloss тЖФ IAST root match, no Devan─Бgar─л on either face тАФ for learners who don't read it yet); **G-C05** `ligatures/top-10` (existing pack extended with a hand-curated Cyrillic pronunciation hint alongside the IAST answer); **G-C06** `cloze/root-rank` (guess each of the top-25 roots' frequency-rank band, 5-wide bands computed at runtime from `ROOT_BANDS.top25` тАФ no hand-authored blanks). All three reuse the existing `roots/data.js` / `ligatures/data.js` fixtures and the H1678 gate/telemetry/locale shell (`data-drill`/`data-band` ids, RU/EN toggle). Catalogue cards added to `roots/index.html` and `cloze/index.html`. New `tests/Feature/Exercises/P1PackPagesTest.php` (6 tests) covers file presence, gate/telemetry wiring, and the no-Devanagari/no-hand-authored-bands invariants. Non-goals held: no new `engine.js` family, no SRS import (H1680). Executor: Sonnet 5 (`claude-sonnet-5`).

- **Rename free drills path public/exercises/ тЖТ public/lila/ (URL /lila/).** Nav, gate/telemetry scripts, tests, manuals, Telegram drafts; nginx 301 /exercises/ тЖТ /lila/. Executor: Grok 4.5 (grok-4.5).
- **Nginx: index index.php index.html for /lila/ directory URLs (H1710 follow-up).** Bare paths like /lila/table/ no longer 403; docs/links drop forced index.html. Executor: Grok 4.5 (grok-4.5).

- **H1710 docs: student-manual ┬з12 + README exercises table + Telegram drafts.** ╨Ъ╤Г╤А╨░╤В╨╛╤А╤Б╨║╨░╤П ╨║╨░╤А╤В╨░ ╨╕╨│╤А-╤Г╨┐╤А╨░╨╢╨╜╨╡╨╜╨╕╨╣ (docs/student-manual.md ┬з12), ╤А╨░╤Б╤И╨╕╤А╨╡╨╜ ╨▒╨╗╨╛╨║ /lila/ ╨▓ README, ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║╨╕ ╨┐╨╛╤Б╤В╨╛╨▓ ╨║╨░╨╜╨░╨╗╨░ ╨▓ marketing/lila-telegram-posts.md. Executor: Grok 4.5 (grok-4.5).

## [1.57.0] - 2026-07-26

### Added
- **Kossovich-╨╖╨░╨╝╨╡╤В╨║╨░: ╨│╨░╨╗╨╡╤А╨╡╤П 16 ╤Б╨░╨╝╨░╤Б ╨▓ ╨│╨╗. 10 (M12).** ╨Я╨╛ ╤Г╨║╨░╨╖╨░╨╜╨╕╤О MG (┬л╨╖╨░╨┐╨╕╤И╨╕ ╨▓╤Б╨╡, ╨╗╨╕╤И╨╜╨╕╨╡ ╨┐╨╛╤В╨╛╨╝ ╨┐╨╡╤А╨╡╨╜╨╡╤Б╤Г ╨▓ ╨╖╨░╨┐╨░╤Б┬╗) ╨▓ ╨│╨╗. 10 ╨▓╨╗╨╕╤В ╨┐╨╛╨╗╨╜╤Л╨╣ ╤И╨╛╤А╤В-╨╗╨╕╤Б╤В FOLLOWUPS K-6 тАФ 16 ╤Б╨░╨╝╨░╤Б ╤Б ╨╜╨╡╨╛╤З╨╡╨▓╨╕╨┤╨╜╤Л╨╝╨╕ ╤А╤Г╤Б╤Б╨║╨╕╨╝╨╕ ╨┐╨╡╤А╨╡╨▓╨╛╨┤╨░╨╝╨╕ ╨Ъ╨╛╤Б╤Б╨╛╨▓╨╕╤З╨░, ╨║╨░╨╢╨┤╨░╤П ╨│╨╗╨╛╤Б╤Б╨░ ╨┤╨╛╤Б╨╗╨╛╨▓╨╜╨╛ ╨╕╨╖ [kow.jsonl](https://github.com/gasyoun/SanskritLexicography/blob/master/RussianTranslation/src/kow.jsonl) (╨║╨╗╤О╤З╨╕ ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜╤Л): gav─Бkс╣гa ┬л╨▒╤Л╤З╨╕╨╣ ╨│╨╗╨░╨╖┬╗=╨╛╨║╨╜╨╛, anekaja ┬л╤А╨╛╨╢╨┤╨░╤О╤Й╨╕╨╣╤Б╤П ╨╜╨╡ ╨╖╨░ ╨╛╨┤╨╕╨╜ ╤А╨░╨╖┬╗=╨┐╤В╨╕╤Ж╨░, amс╣Ыtasodara ┬л╨▒╤А╨░╤В ╨░╨╝╨▒╤А╨╛╨╖╨╕╨╕┬╗=╨║╨╛╨╜╤М, budhav─Бra ┬л╨┤╨╡╨╜╤М ╨Ь╨╡╤А╨║╤Г╤А╨╕╤П┬╗=╤Б╨╡╤А╨╡╨┤╨░, vitс╣Ыс╣гс╣Зat─Б ┬л╨╜╨╡╤З╤Г╨▓╤Б╤В╨▓╨╛╨▓╨░╨╜╨╕╨╡ ╨╢╨░╨╢╨┤╤Л┬╗=╨┤╨╛╨▓╨╛╨╗╤М╤Б╤В╨▓╨╛ ╨╕ ╨┤╤А.; ╤А╨░╨╝╨╛╤З╨╜╤Л╨╣ ╨┐╤А╨╕╨╝╨╡╤А kс╣Ыtaj├▒a ╨┐╨╛╨╝╨╡╤З╨╡╨╜ ╨▓ FACTS ╨║╨░╨║ ╨╛╨▒╤Й╨╡╤Б╨░╨╜╤Б╨║╤А╨╕╤В╤Б╨║╨╕╨╣ (╨▒╤Г╨║╨▓╤Л ┬л╨║┬╗ ╨▓ ╤Б╨╗╨╛╨▓╨░╤А╨╡ ╨╜╨╡╤В). FACTS +2 ╤Б╤В╤А╨╛╨║╨╕ (K10-6/K10-7), FOLLOWUPS K-6 тЖТ done, DECISIONS M12; ~4 000 ╤Б╨╗╨╛╨▓, ~20 ╨╝╨╕╨╜ ╤З╤В╨╡╨╜╨╕╤П. Executor: Fable 5 (`claude-fable-5`).

### Added

- **LearningApps to Systema: 7 drills + table engine + decode helper (H1710).** New family public/lila/table/ (TableExercise.mount, LA tool 270) with verb-conjugation grid + masc. -i nominative; five thin drills (sort/verb-person-number, cloze/interrogative-accusative, cloze/demonstrative-pronouns, match/ru-sa-sentences, match/ru-sa-pairs-short). Helper scripts/decode_learningapps.py. Skill /learningapps-port. Executor: Grok 4.5 (grok-4.5).

## [1.56.0] - 2026-07-26

### Added
- **╨Т╨╜╨╡╤И╨╜╨╕╨╣ ╨╝╨╛╨╜╨╕╤В╨╛╤А ╨┤╨╛╤Б╤В╤Г╨┐╨╜╨╛╤Б╤В╨╕ ╨┐╤А╨╛╨┤╨░ тАФ [`.github/workflows/uptime-samskrte.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/uptime-samskrte.yml).** ╨Ч╨░╨▓╨╡╨┤╤С╨╜ ╨┐╨╛╤Б╨╗╨╡ ╨┐╤А╨╛╤Б╤В╨╛╤П 24тАУ26.07.2026 ([#730](https://github.com/gasyoun/Systema-Sanscriticum/issues/730)): `samskrte.ru` ╨╗╨╡╨╢╨░╨╗ ╨┤╨▓╨╛╨╡ ╤Б╤Г╤В╨╛╨║, ╨╕ ╨╛╨▒ ╤Н╤В╨╛╨╝ ╨╜╨╕╨║╤В╨╛ ╨╜╨╡ ╤Г╨╖╨╜╨░╨╗. ╨Я╤А╨╛╨▒╨░ ╤А╨░╨╖ ╨▓ 10 ╨╝╨╕╨╜╤Г╤В ╨▓╤Л╨┐╨╛╨╗╨╜╤П╨╡╤В╤Б╤П ╨╜╨░ ╤А╨░╨╜╨╜╨╡╤А╨╡ GitHub, ╤В╨╛ ╨╡╤Б╤В╤М ╨▓╨╜╨╡ ╤Б╨░╨╝╨╛╨│╨╛ ╤Б╨╡╤А╨▓╨╡╤А╨░ тАФ ╨╝╨╛╨╜╨╕╤В╨╛╤А ╨▓╨╜╤Г╤В╤А╨╕ ╨┐╨░╨┤╨░╤О╤Й╨╡╨╣ ╨╝╨░╤И╨╕╨╜╤Л ╨╛ ╨╡╤С ╤Б╨╝╨╡╤А╤В╨╕ ╤Б╨╛╨╛╨▒╤Й╨╕╤В╤М ╨╜╨╡ ╨╝╨╛╨╢╨╡╤В. ╨Я╤А╨╛╨▓╨╡╤А╤П╨╡╤В╤Б╤П ╨╜╨╡ ╤В╨╛╨╗╤М╨║╨╛ ╨║╨╛╨┤ ╨╛╤В╨▓╨╡╤В╨░, ╨╜╨╛ ╨╕ ╨╜╨░╨╗╨╕╤З╨╕╨╡ ╨╢╨╕╨▓╨╛╨│╨╛ ╤В╨╡╨║╤Б╤В╨░ ╨╜╨░ ╤Б╤В╤А╨░╨╜╨╕╤Ж╨╡ (`╨Ю╨▒╤Й╨╡╤Б╤В╨▓╨╛ ╤А╨╡╨▓╨╜╨╕╤В╨╡╨╗╨╡╨╣ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨░`), ╤З╤В╨╛ ╨╗╨╛╨▓╨╕╤В ╤Б╨╗╤Г╤З╨░╨╣ ┬л╨╛╤В╨▓╨╡╤З╨░╨╡╤В 200, ╨░ ╨╛╤В╨┤╨░╤С╤В ╨╖╨░╨│╨╗╤Г╤И╨║╤Г┬╗; ╤В╤А╨╕ ╨┐╨╛╨┐╤Л╤В╨║╨╕ ╤Б ╨╕╨╜╤В╨╡╤А╨▓╨░╨╗╨╛╨╝ 20 ╤Б ╨│╨░╤Б╤П╤В ╨╛╨┤╨╕╨╜╨╛╤З╨╜╤Л╨╣ ╤Б╨╡╤В╨╡╨▓╨╛╨╣ ╤Б╨▒╨╛╨╣ ╤А╨░╨╜╨╜╨╡╤А╨░. ╨б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╡ ╤Е╤А╨░╨╜╨╕╤В╤Б╤П ╨▓ issue ╤Б ╨╝╨╡╤В╨║╨╛╨╣ `uptime-alert`: ╤Г╨┐╨░╨╗ тАФ ╨╖╨░╨▓╨╛╨┤╨╕╤В╤Б╤П issue ╨╕ ╤Г╤Е╨╛╨┤╨╕╤В ╨╛╨┤╨╜╨╛ ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡ ╨▓ Telegram, ╨╗╨╡╨╢╨╕╤В тАФ ╨╝╨╛╨╜╨╕╤В╨╛╤А ╨╝╨╛╨╗╤З╨╕╤В, ╨▓╤Б╤В╨░╨╗ тАФ issue ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В╤Б╤П ╤Б ╨┤╨╗╨╕╤В╨╡╨╗╤М╨╜╨╛╤Б╤В╤М╤О ╨┐╤А╨╛╤Б╤В╨╛╤П. ╨Ч╨░ ╨┐╤П╤В╨╕╤З╨░╤Б╨╛╨▓╨╛╨╣ ╨┐╤А╨╛╤Б╤В╨╛╨╣ ╨┐╤А╨╕╤Е╨╛╨┤╨╕╤В ╨┤╨▓╨░ ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╤П, ╨░ ╨╜╨╡ ╤В╤А╨╕╨┤╤Ж╨░╤В╤М. ╨Ф╨╛╨┐╨╛╨╗╨╜╨╕╤В╨╡╨╗╤М╨╜╨╛: ╨┐╤А╨╡╨┤╤Г╨┐╤А╨╡╨╢╨┤╨╡╨╜╨╕╨╡ ╨╖╨░ 14 ╨┤╨╜╨╡╨╣ ╨┤╨╛ ╨╕╤Б╤В╨╡╤З╨╡╨╜╨╕╤П TLS-╤Б╨╡╤А╤В╨╕╤Д╨╕╨║╨░╤В╨░ (╤Б╤А. [#658](https://github.com/gasyoun/Systema-Sanscriticum/issues/658)) ╨╕ ╤А╤Г╤З╨╜╨╛╨╣ ╨┐╤А╨╛╨│╨╛╨╜ `force_alert` ╨┤╨╗╤П ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕ ╤В╨╛╨│╨╛, ╤З╤В╨╛ ╨╛╨┐╨╛╨▓╨╡╤Й╨╡╨╜╨╕╨╡ ╤А╨╡╨░╨╗╤М╨╜╨╛ ╨┤╨╛╤Е╨╛╨┤╨╕╤В. ╨б╨╡╨║╤А╨╡╤В╤Л `TELEGRAM_BOT_TOKEN` ╨╕ `TELEGRAM_CHAT_ID` ╨╖╨░╨┤╨░╤О╤В╤Б╤П ╨╛╨┤╨╕╨╜ ╤А╨░╨╖ ╨▓ ╨╜╨░╤Б╤В╤А╨╛╨╣╨║╨░╤Е ╤А╨╡╨┐╨╛╨╖╨╕╤В╨╛╤А╨╕╤П; ╨▒╨╡╨╖ ╨╜╨╕╤Е ╨╝╨╛╨╜╨╕╤В╨╛╤А ╨┐╤А╨╛╨┤╨╛╨╗╨╢╨░╨╡╤В ╨╖╨░╨▓╨╛╨┤╨╕╤В╤М issue. ╨Ф╨╕╨░╨│╨╜╨╛╤Б╤В╨╕╨║╨░ ╨┐╤А╨╛╤Б╤В╨╛╤П ╨╕ ╨▓╤Л╨▒╨╛╤А ╨║╨╛╨╜╤Б╤В╤А╤Г╨║╤Ж╨╕╨╕: Opus 5 (`claude-opus-5[1m]`).

## [1.55.0] - 2026-07-26

### Added
- **Kossovich-╨╖╨░╨╝╨╡╤В╨║╨░: mining-pass M11 тАФ 16 ╨│╨╗╨░╨▓, ╨╛╨▒╤А╨░╨╖╤Ж╤Л ╤Б╤В╨░╤В╨╡╨╣ ╤Б╨╗╨╛╨▓╨░╤А╤П, ╨┐╨╡╤А╨╡╨╕╨╖╨┤╨░╨╜╨╕╤П (H1696 follow-up).** ╨Ш╨╖ escrow-╨▓╨╡╤В╨║╨╕ `h1696-kossovich-arzamas-2099` (╨┐╨░╤А╨░╨╗╨╗╨╡╨╗╤М╨╜╨░╤П ╨┐╨╛╨┐╤Л╤В╨║╨░ H1696) ╨▓ ╨║╨░╨╜╨╛╨╜╨╕╤З╨╡╤Б╨║╨╕╨╣ ╤В╨╡╨║╤Б╤В ╨▓╨╗╨╕╤В╤Л ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜╨╜╤Л╨╡ ╨┐╨╛ ╨┐╨╡╤А╨▓╨╛╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨░╨╝ ╨┐╨░╤Б╤Б╨░╨╢╨╕: ╨▒╨╕╨╛╨│╤А╨░╤Д╨╕╤П ╨Ъ╨╛╤Б╤Б╨╛╨▓╨╕╤З╨░ ╨┐╨╛ ╤Б╤В╨░╤В╤М╨╡ ╨Ю╨╗╤М╨┤╨╡╨╜╨▒╤Г╤А╨│╨░ (┬л╨н╤В╤О╨┤╤Л┬╗ ~1705тАУ1730 тАФ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║, ╨╜╨╡ ╨╜╨░╨╣╨┤╨╡╨╜╨╜╤Л╨╣ ╨▓ ╨┐╨╡╤А╨▓╨╛╨╝ ╨┐╤А╨╛╤Е╨╛╨┤╨╡: ╨Я╨╛╨╗╨╛╤Ж╨║, ┬л╨╖╨░╨╝╨╡╤З╨░╤В╨╡╨╗╤М╨╜╤Л╨╣ ╨┐╤А╨╕╨╝╨╡╤А ╨░╨▓╤В╨╛╨┤╨╕╨┤╨░╨║╤В╨░┬╗, ╨╝╨╡╤В╨╛╨┤ ┬л╤Б╨║╨╛╨╗╤М╨╖╨╕╨╗ ╨┐╨╛ ╨│╤А╨░╨╝╨╝╨░╤В╨╕╨║╨╡┬╗), ┬л╤А╨╡╤И╤С╤В╨║╨░ ╨║╨╕╤А╨╕╨╗╨╗╨╕╤Ж╤Л┬╗, ╨╗╨░╤В╨╕╨╜╤Б╨║╨╕╨╣ ┬л╤Д╨╕╨│╨╛╨▓╤Л╨╣ ╨╗╨╕╤Б╤В╨╛╨║┬╗ (╨╝╨╛╤Б╤В ╨║ ╨│╨╗. 8 ╨┐╨╡╤А╨▓╨╛╨╣ ╨╖╨░╨╝╨╡╤В╨║╨╕), ╤З╨╡╤В╤Л╤А╤С╤Е╨╗╨╡╤В╨╜╤П╤П ╨┐╤А╨╛╨│╤А╨░╨╝╨╝╨░ ╤Б ╤Е╤А╨░╨╝╨░╨╝╨╕ ╨Ю╤А╨╕╤Б╤Б╤Л, ╨║╨╛╨╜╤В╤А╨░╤Б╤В ╨┐╨╛╨│╨╗╨╛╤Й╨╡╨╜╨╕╤П 70,9 %/23,6 % (A43), ╤Д╨╕╨╜╨░╨╗╤М╨╜╤Л╨╡ ╤Д╨╛╤А╨╝╤Г╨╗╤Л. ╨Э╨Ю╨Т╨Р╨п ╨│╨╗. 10 ┬л╨Т╨╜╤Г╤В╤А╨╕ ╤Б╨╗╨╛╨▓╨░╤А╤П: тАЮ╤П╨▒╨╗╨╛╨║╨╛" ╨╕╨╖ ╨░╨╝╨░╨╗╨░╨║╨╕┬╗ тАФ ╨╛╨▒╤А╨░╨╖╤Ж╤Л ╤Б╤В╨░╤В╨╡╨╣ ╨╕╨╖ ╨╛╤Ж╨╕╤Д╤А╨╛╨▓╨║╨╕ kow.jsonl (att─Б ~ ┬л╨╛╤В╨╡╤Ж┬╗/┬л╤В╨╡╤В╤П┬╗ ╤Б ╨┐╨╛╨╝╨╡╤В╨╛╨╣ ┬л╨У╨╕╨╗╤М╤Д.┬╗ ╨▓ ╤Б╨░╨╝╨╛╨╣ ╤Б╤В╨░╤В╤М╨╡, ─Бmalaka ~ ┬л╤П╨▒╨╗╨╛╨║╨╛┬╗, aruс╣Зa ~ ┬л╤А╤Г╨╝╤П╨╜╤Л╨╣┬╗, anu┼Ы─Бsitar ┬л╨╜╨░-╨║╨░╨╖-╨░╤В╨╡╨╗╤М┬╗ тАФ ╨┐╤А╨╛╤В╨╕╨▓ ╨╜╨░╤Б╤В╨╛╤П╤Й╨╕╤Е ╨║╨╛╨│╨╜╨░╤В╨╛╨▓ aham~╨░╨╖╤К, agra~╨╛╤Б╤В╤А╤Л╨╣, asthi~╨╛╤Б╤В╤М; ╨▓╤В╨╛╤А╨░╤П ╨╖╨░╨┐╨╕╤Б╤М ╨Ъ╨╛╤Б╤Б╨╛╨▓╨╕╤З╨░ ╨╕ ╨┐╨╡╤А╨▓╨░╤П ╨╖╨░╨┐╨╕╤Б╤М PWG тАФ ╨╛╨┤╨╜╨╛ ╨╕ ╤В╨╛ ╨╢╨╡ ┬л╨▓╨╛╤Б╨║╨╗╨╕╤Ж╨░╨╜╤Ц╨╡ ╤Б╨╛╤Б╤В╤А╨░╨┤╨░╨╜╤Ц╤П┬╗). ╨Я╨╛ ╤Г╨║╨░╨╖╨░╨╜╨╕╤О MG ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜╤Л [╨▓╤Б╤В╤Г╨┐╨╕╤В╨╡╨╗╤М╨╜╨░╤П ╨╗╨╡╨║╤Ж╨╕╤П 1859 ╨│.](https://samskrtam.ru/kossovich-vstupitelnaya-lekciya-1859) ╨╕ ╨║╤А╨░╤Г╨┤╤Д╨░╨╜╨┤╨╕╨╜╨│╨╛╨▓╤Л╨╡ ╨┐╨╡╤А╨╡╨╕╨╖╨┤╨░╨╜╨╕╤П ╤Б╨╗╨╛╨▓╨░╤А╤П ([planeta.ru/sanskrit](https://planeta.ru/campaigns/sanskrit), 3-╨╡ ╨╕╨╖╨┤. 2017) ╨╕ ┬л╨Ы╨╡╨│╨╡╨╜╨┤╤Л ╨╛╨▒ ╨╛╤Е╨╛╤В╨╜╨╕╨║╨╡┬╗ ([planeta.ru/ohotnik](https://planeta.ru/campaigns/ohotnik), 2018). FACTS +17 ╤Б╤В╤А╨╛╨║ (K3-9тАжK16-5, ╨▓╨║╨╗╤О╤З╨░╤П kow-╨║╨╗╤О╤З╨╕, ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜╨╜╤Л╨╡ ╨┐╨╛ ╤Д╨░╨╣╨╗╤Г), DECISIONS M11, MAJORS-╨╗╨╡╨┤╨╢╨╡╤А ╨┤╨╛╨┐╨╛╨╗╨╜╨╡╨╜ ╨┐╨╛╤Б╤В-╤Б╨╛╨▓╨╡╤В╨╜╨╛╨╣ ╤Б╨╡╨║╤Ж╨╕╨╡╨╣; expected_min_h2 15тЖТ16. ╨в╨╡╤Б╤В╤Л: 11 passed (49 assertions). Executor: Fable 5 (`claude-fable-5`).

### Added


## [1.54.0] - 2026-07-26

### Added
- **╨Т╨в╨Ю╨а╨Р╨п Arzamas-╨╖╨░╨╝╨╡╤В╨║╨░ ┬л╨а╨╛╤Б╤Б╨╕╤П ╨╕ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╤Б╨║╨╕╨╣ ╤Б╨╗╨╛╨▓╨░╤А╤М: ╨Ъ╨╛╤Б╤Б╨╛╨▓╨╕╤З ╨┐╤А╨╛╤В╨╕╨▓ ╨С╤С╤В╨╗╨╕╨╜╨│╨║╨░┬╗ (H1696).** ╨Я╨╛╨╗╨╜╤Л╨╣ ╨╝╨░╤В╨╡╤А╨╕╨░╨╗-╨┐╨░╨║ [docs/materials/kossovich-arzamas/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/materials/kossovich-arzamas) тАФ ╤Б╤О╨╢╨╡╤В, ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛ ╨▓╤Л╤А╨╡╨╖╨░╨╜╨╜╤Л╨╣ ╨╕╨╖ ╨┐╨╡╤А╨▓╨╛╨╣ ╨╖╨░╨╝╨╡╤В╨║╨╕ (DECISIONS L9 / FOLLOWUPS W2-5, ┬л╨╕╤Е ╨╝╨╛╨╢╨╡╤В ╨▒╤Л╤В╤М ╨╕ ╨┤╨▓╨╡┬╗ тАФ MG 26-07-2026): ╤А╤Г╤Б╤Б╨║╨╕╨╣ ╨║╨╛╨╜╤В╨╡╨║╤Б╤В PWG тАФ ╨╕╨╝╨┐╨╡╤А╨╕╤П ╨┐╨╗╨░╤В╨╕╤В ╨╖╨░ ╨╜╨╡╨╝╨╡╤Ж╨║╨╕╨╣ ╤Б╨╡╨╝╨╕╤В╨╛╨╝╨╜╨╕╨║, ╤В╤А╨╡╨▒╨╛╨▓╨░╨╜╨╕╨╡ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨╛-╨а╨г╨б╨б╨Ъ╨Ю╨У╨Ю ╤Б╨╗╨╛╨▓╨░╤А╤П ╨╛╤В II ╨Ю╤В╨┤╨╡╨╗╨╡╨╜╨╕╤П, ╨╗╨░╤В╤Л╨╜╤М ╨г╨▓╨░╤А╨╛╨▓╨░ ┬л╨║╨░╨║ ╨▓ ╨┤╨╛╨▒╤А╤Л╨╡ ╤Б╤В╨░╤А╤Л╨╡ ╨▓╤А╨╡╨╝╨╡╨╜╨░┬╗, ╤Б╨╗╨╛╨▓╨░╤А╤М ╨Ъ╨╛╤Б╤Б╨╛╨▓╨╕╤З╨░ 1854 ╨│. ╨╕ ╤А╨░╨╖╨│╤А╨╛╨╝╨╜╤Л╨╣ ╨╛╤В╨╖╤Л╨▓ ╨С╤С╤В╨╗╨╕╨╜╨│╨║╨░ (┬л╨╜╨╡ ╨┤╨╡╨╗╨░╨╡╤В ╨╜╨╕╨║╨░╨║╨╛╨│╨╛ ╤Б╨░╨╝╨╛╤Б╤В╨╛╤П╤В╨╡╨╗╤М╨╜╨╛╨│╨╛ ╨▓╨║╨╗╨░╨┤╨░тАж ╨╖╨░ ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╨╡╨╝ ╨╛╤И╨╕╨▒╨╛╨║┬╗), ╤Б╨╗╨░╨▓╤П╨╜╨╛╤Д╨╕╨╗╤М╤Б╨║╨░╤П ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨╛╨╝╨░╨╜╨╕╤П (┬л╤И╤Г╨▒╨░┬╗ ╨╕╨╖ ┬л╤И╤Г╨▒╤Е┬╗, ╨╗╨╡╨║╤Б╨╕╨║╨╛╨╜╤З╨╕╨║ ╨е╨╛╨╝╤П╨║╨╛╨▓╨░), ╤Д╨╡╨╗╤М╨╡╤В╨╛╨╜ ╨Ы╨░╨╝╨░╨╜╤Б╨║╨╛╨│╨╛ 1879 ╨│. ╨╛ ┬л╤Б╤В╨░ ╤В╤Л╤Б╤П╤З╨░╤Е ╤А╤Г╨▒╨╗╨╡╨╣┬╗, ╨╖╨░╤Й╨╕╤В╨░ ╨С╤Г╨╗╨╕╤З╨░ ╨╕ ╨а╨╛╤В╨░, ╤Б╤Г╨┤╤М╨▒╨░ ╤В╨╛╤А╤Б╨░ ╨┐╨╛ A43 (9 592 ╤Г╨╜╨╕╨║╨░╨╗╤М╨╜╤Л╤Е ╤Б╨╗╨╛╨▓╨░ = 73 % ╤Б╨╗╨╛╨▓╨╜╨╕╨║╨░, ╨║╤А╤Г╨┐╨╜╨╡╨╣╤И╨╕╨╣ ╤Г╨╜╨╕╨║╨░╨╗╤М╨╜╤Л╨╣ ╨▓╨║╨╗╨░╨┤╤З╨╕╨║ ╤А╤Г╤Б╤Б╨║╨╛╨╣ ╤Б╨╡╨╝╤М╨╕) ╨╕ ╤Д╨╕╨╜╨░╨╗ ╨Ю╨╗╤М╨┤╨╡╨╜╨▒╤Г╤А╨│╨░ ┬л╨┤╨╛ ╤Б╨╗╨╛╨▓╨░╤А╤П ╨╕ ╨┐╨╛╤Б╨╗╨╡ ╤Б╨╗╨╛╨▓╨░╤А╤П┬╗. SOURCE.md 15 ╨│╨╗╨░╨▓ ~3 100 ╤Б╨╗╨╛╨▓; FACTS.md ~80 ╤Б╤В╤А╨╛╨║ K-*/KA-* (╨▓╤Б╨╡ `verified`/`hedged`; ╨┐╤А╨░╨▓╨╕╨╗╨╛ ┬л╨░╤А╤Е╨╕╨▓ тЙа ╨┐╨╡╤А╨▓╨╛╨╕╤Б╤В╨╛╤З╨╜╨╕╨║┬╗ тАФ ╨▓╤Б╨╡ ╨░╤А╤Е╨╕╨▓╨╜╤Л╨╡ ╤Ж╨╕╤В╨░╤В╤Л ╨░╤В╤А╨╕╨▒╤Г╤В╨╕╤А╨╛╨▓╨░╨╜╤Л ╨Т╨╕╨│╨░╤Б╨╕╨╜╤Г ╨┐╤А╤П╨╝╨╛ ╨▓ ╤В╨╡╨║╤Б╤В╨╡); ASSETS.md тАФ 3 ╨╜╨╛╨▓╤Л╤Е PD-╨┐╨╛╤А╤В╤А╨╡╤В╨░ (╨Ъ╨╛╤Б╤Б╨╛╨▓╨╕╤З, ╨г╨▓╨░╤А╨╛╨▓/╨У╨╛╨╗╨╕╨║╨╡-1833 ╨║╨░╨║ ╨╛╨▒╨╗╨╛╨╢╨║╨░, ╨░╨▓╤В╨╛╨┐╨╛╤А╤В╤А╨╡╤В ╨е╨╛╨╝╤П╨║╨╛╨▓╨░-1842) + ╨┐╨╡╤А╨╡╨╕╤Б╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╨╜╨╜╤Л╨╣ ╨┐╨╛╤А╤В╤А╨╡╤В ╨С╤С╤В╨╗╨╕╨╜╨│╨║╨░ ╨╕╨╖ ╨┐╨░╨║╨░ ╨┐╨╡╤А╨▓╨╛╨╣ ╨╖╨░╨╝╨╡╤В╨║╨╕; `build_body.py` (floor тЙе12 h2 ╨┐╨╛ goal H1696). ╨Т╨╖╨░╨╕╨╝╨╜╤Л╨╡ ╤Б╤Б╤Л╨╗╨║╨╕: ╨│╨╗. 16 ╨┐╨╡╤А╨▓╨╛╨╣ ╨╖╨░╨╝╨╡╤В╨║╨╕ ╤В╨╡╨┐╨╡╤А╤М ╨▓╨╡╨┤╤С╤В ╨╜╨░ ╨▓╤В╨╛╤А╤Г╤О (re-import ╨┐╨╡╤А╨▓╨╛╨╣ ╨╜╨░ ╨┐╤А╨╛╨┤╨╡ тАФ ╨┐╨╛╤Б╨╗╨╡ ╨┐╤Г╨▒╨╗╨╕╨║╨░╤Ж╨╕╨╕ ╨▓╤В╨╛╤А╨╛╨╣, FOLLOWUPS K-2). ╨Ш╨╝╨┐╨╛╤А╤В: artisan `materials:import-kossovich-arzamas {--publish}` (╤В╨╛╨╜╨║╨░╤П ╨║╨╛╨╝╨░╨╜╨┤╨░-╤Б╨╕╨▒╨╗╨╕╨╜╨│, ╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╜╤Л╨╣ upsert ╨┐╨╛ slug `rossiya-i-sanskritskiy-slovar`). ╨в╨╡╤Б╤В╤Л: `KossovichArzamasMaterialTest` (6, ╨▓╨║╨╗╤О╤З╨░╤П ╤В╨╡╤Б╤В ╤Б╨╛╤Б╤Г╤Й╨╡╤Б╤В╨▓╨╛╨▓╨░╨╜╨╕╤П ╨╕ ╨▓╨╖╨░╨╕╨╝╨╜╤Л╤Е ╤Б╤Б╤Л╨╗╨╛╨║ ╨╛╨▒╨╡╨╕╤Е ╨╖╨░╨╝╨╡╤В╨╛╨║). RWS-╤Б╨╛╨▓╨╡╤В╤Л `sanskrit`+`general` (DeepSeek `deepseek-v4-pro`), Majors ╤А╨░╨╖╨╛╨▒╤А╨░╨╜╤Л ╨▓ [rws/MAJORS_RESOLUTION.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/rws/MAJORS_RESOLUTION.md). ╨Я╤Г╨▒╨╗╨╕╨║╨░╤Ж╨╕╤П ╨▓ ╨┐╤А╨╛╨┤ тАФ ╨┐╨╛ README-runbook ╨┐╨░╨║╨░ (prod-CLI ╨▓ ╤Б╨╡╤Б╤Б╨╕╨╕ ╨╜╨╡╤В). Executor: Fable 5 (`claude-fable-5`).

## [1.53.0] - 2026-07-26

### Added
- **PWG Arzamas-longread ┬л╨Я╨╡╤В╨╡╤А╨▒╤Г╤А╨│╤Б╨║╨╕╨╣ ╤Б╨╗╨╛╨▓╨░╤А╤М┬╗ тАФ wave-1 build (H1620).** ╨Я╨╛╨╗╨╜╤Л╨╣ ╨╝╨░╤В╨╡╤А╨╕╨░╨╗-╨┐╨░╨║ [docs/materials/pwg-arzamas/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/materials/pwg-arzamas): SOURCE.md (20 ╨│╨╗╨░╨▓, ~4 500 ╤Б╨╗╨╛╨▓, Arzamas-╤А╨╡╨│╨╕╤Б╤В╤А), FACTS.md (138 ╤Б╤В╤А╨╛╨║ claimтЖТsource, ╨▓╤Б╨╡ `verified`/`hedged`), ASSETS.md (rights-╤В╨░╨▒╨╗╨╕╤Ж╨░: 9 PD-╨╕╨╖╨╛╨▒╤А╨░╨╢╨╡╨╜╨╕╨╣ + 2 ╨░╨▓╤В╨╛╤А╤Б╨║╨╕╤Е SVG ╨▓ `public/images/materials/pwg/`), ╨┤╨╡╤В╨╡╤А╨╝╨╕╨╜╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╨╣ ╤А╨╡╨╜╨┤╨╡╤А╨╡╤А `build_body.py` (MarkdownтЖТ`body.html`, тЙе15 h2 gate), DECISIONS_LOG/FOLLOWUPS/bibliography. ╨Ф╨░╨╜╨╜╤Л╨╡ ╨╛╨▒╨╛╨│╨░╤Й╨╡╨╜╤Л ╨┐╨╛ live-╤А╨╡╨▓╤М╤О MG: ╤Б╤В╨░╤В╨╕╤Б╤В╨╕╨║╨░ csl-atlas (801 790 `<ls>`-╤Ж╨╕╤В╨░╤В, ╨┐╤А╨╕╤Б╤В╨░╨▓╨╛╤З╨╜╤Л╨╡ ╤Б╨╡╨╝╤М╨╕ vi-/─Б-/sam-, ┬л╤Е╤Г╨┤╨╡╤О╤Й╨╕╨╡┬╗ ╤Б╤В╨░╤В╤М╨╕ тИТ14,3 %/╨┤╨╡╨║╨░╨┤╤Г, ╨║╨╛╨╜╤В╤А╨░╤Б╤В ╤Б ╨┐╤Г╨╜╤Б╨║╨╕╨╝ PD-╤Б╨╗╨╛╨▓╨░╤А╤С╨╝ тЙИ2280 ╨│.), ╨░╤А╤Е╨╕╨▓╨╜╨░╤П ╨│╨╗╨░╨▓╨░ ╨Т╨╕╨│╨░╤Б╨╕╨╜╨░ (┬л╨Ф╨╡╨╗╨╛ ╨╛ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╤Б╨║╨╛╨╝ ╤Б╨╗╨╛╨▓╨░╤А╨╡┬╗: ╨г╨▓╨░╤А╨╛╨▓ ╨╕ ╨╗╨░╤В╤Л╨╜╤М, ╨│╨╛╨╜╨╛╤А╨░╤А╤Л, ╤В╨╕╤А╨░╨╢), ╨╜╨╡╨║╤А╨╛╨╗╨╛╨│ ╨Ю╨╗╤М╨┤╨╡╨╜╨▒╤Г╤А╨│╨░, ╤Б╤В╨░╤В╤М╨╕ A33/A40/A50/Stache-Weiske; ╨┐╨╛╤А╤В╤А╨╡╤В ╨Ф╨░╨╗╤П ╤Б╨╜╤П╤В (╨╖╨░╨╝╨╡╨╜╤П╤В ╨│╨╡╨╜╨╡╤А╨╕╤А╤Г╨╡╨╝╤Л╨╡ ╨╕╨╜╤Д╨╛╨│╤А╨░╤Д╨╕╨║╨╕, FOLLOWUPS W2-4); ╤Б╤О╨╢╨╡╤В ┬л╨Ъ╨╛╤Б╤Б╨╛╨▓╨╕╤З ╨┐╤А╨╛╤В╨╕╨▓ ╨С╤С╤В╨╗╨╕╨╜╨│╨║╨░┬╗ ╨▓╤Л╨╜╨╡╤Б╨╡╨╜ ╨▓ ╨┐╨╗╨░╨╜ ╨▓╤В╨╛╤А╨╛╨╣ ╨╖╨░╨╝╨╡╤В╨║╨╕ (W2-5). ╨Ш╨╝╨┐╨╛╤А╤В: artisan `materials:import-pwg-arzamas {--publish}` (╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╜╤Л╨╣ upsert ╨┐╨╛ slug `peterburgskiy-slovar-pwg`, staging ╨╛╨▒╨╗╨╛╨╢╨║╨╕ ╨╜╨░ public-╨┤╨╕╤Б╨║, reading_time). ╨в╨╡╤Б╤В╤Л: `PwgArzamasMaterialTest` (5, 22 assertions, ╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╜╨╛╤Б╤В╤М + 404 ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║╨░ + тЙе15 h2 + ╨║╨░╤А╤В╨╛╤З╨║╨░ ╤Е╨░╨▒╨░). RWS-╤Б╨╛╨▓╨╡╤В╤Л `sanskrit`+`indology` (DeepSeek `deepseek-v4-pro`; ╨░╨╗╨╕╨░╤Б `deepseek-chat` ╤Г╨╝╨╡╤А тАФ ╨┐╨░╨┐╨╕╤А╨║╨░╤В╤Л ╨┐╨╛╨┤╨░╨╜╤Л), Majors ╨╖╨░╨║╤А╤Л╤В╤Л ([rws/MAJORS_RESOLUTION.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/rws/MAJORS_RESOLUTION.md)). ╨Я╤Г╨▒╨╗╨╕╨║╨░╤Ж╨╕╤П ╨▓ ╨┐╤А╨╛╨┤ тАФ ╨┐╨╛ Step 10 (README runbook ╨┐╤А╨╕ ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╨╕ prod-CLI). Executor: Fable 5 (`claude-fable-5`).

- **Online Sanskrit games multi-wave plan (`/ask`, 26-07-2026).** Layered PLAN + ROADMAP + ARCHITECTURE + IMPLEMENTATION (Wave 1) + VERIFICATION for games built on existing Systema assets (`/lila` engines, frequency roots, Kochergina/SRS fixtures, lead-magnet funnel, Sanskrit-HUB ladder). Invent catalogue **28** game IDs in three sections (asset-pedagogy ┬╖ viral LM ┬╖ engine-fill). Wave-1 fence: extend engines only, no audio/multiplayer. Deferred handoffs H1678тАУH1680 (platform+P0, P1 packs, SRS onboarding). Index: [docs/PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md). Executor: Grok 4.5 (`grok-4.5`).

### Fixed
- **CI ╨╜╨░ `main` ╨┐╨╛╤З╨╕╨╜╨╡╨╜: `DeliverSupportReplyTest` ╨┐╨╛╨╗╤Г╤З╨░╨╡╤В ╤З╨╡╤В╨▓╤С╤А╤В╤Г╤О ╨╖╨░╨▓╨╕╤Б╨╕╨╝╨╛╤Б╤В╤М `TelegramSupportSyncService` ([PR #726](https://github.com/gasyoun/Systema-Sanscriticum/pull/726), [issue #725](https://github.com/gasyoun/Systema-Sanscriticum/issues/725)).** ╨Ъ╨╛╨╝╨╝╨╕╤В [`95a61d4`](https://github.com/gasyoun/Systema-Sanscriticum/commit/95a61d4) ╨┤╨╛╨▒╨░╨▓╨╕╨╗ ╤Б╨╡╤А╨▓╨╕╤Б╤Г ╤З╨╡╤В╨▓╤С╤А╤В╤Л╨╣ ╨┐╨░╤А╨░╨╝╨╡╤В╤А ╨║╨╛╨╜╤Б╤В╤А╤Г╨║╤В╨╛╤А╨░ (`TechnicalIssueRouter`), ╨╜╨╛ ╨░╨╜╨╛╨╜╨╕╨╝╨╜╤Л╨╣ ╨╜╨░╤Б╨╗╨╡╨┤╨╜╨╕╨║ ╨▓ ╤В╨╡╤Б╤В╨╡ ╨┐╤А╨╛╨┤╨╛╨╗╨╢╨░╨╗ ╤Б╤В╤А╨╛╨╕╤В╤М ╨╡╨│╨╛ ╤Б ╤В╤А╨╡╨╝╤П тАФ ╤В╤А╨╕ ╨║╨╡╨╣╤Б╨░ ╨┐╨░╨┤╨░╨╗╨╕ ╤Б `ArgumentCountError`, ╨╕ job ┬лPHP 8.3 тАФ tests┬╗ ╨▒╤Л╨╗ ╨║╤А╨░╤Б╨╜╤Л╨╝ ╨╜╨░ `main` ╤Б ╤Н╤В╨╛╨│╨╛ ╨║╨╛╨╝╨╝╨╕╤В╨░ (╨╖╨╡╨╗╤С╨╜╤Л╨╣ ╨╜╨░ [`7b18267`](https://github.com/gasyoun/Systema-Sanscriticum/commit/7b18267)). ╨Ъ╨╛╨╝╨╝╨╕╤В ╤Г╤И╤С╨╗ ╨▓ `main` ╨╜╨░╨┐╤А╤П╨╝╤Г╤О, ╨▒╨╡╨╖ PR, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨╡╨│╨╛ ╨╜╨╕╤З╤В╨╛ ╨╜╨╡ ╤Б╨│╨╡╨╣╤В╨╕╨╗╨╛. ╨Т╤Б╨╡ ╤З╨╡╤В╤Л╤А╨╡ ╨╖╨░╨▓╨╕╤Б╨╕╨╝╨╛╤Б╤В╨╕ ╤А╨╡╨╖╨╛╨╗╨▓╤П╤В╤Б╤П ╨║╨╛╨╜╤В╨╡╨╣╨╜╨╡╤А╨╛╨╝ тАФ ╨┐╤А╨░╨▓╨║╨░ ╨▓ ╨┤╨▓╨╡ ╤Б╤В╤А╨╛╨║╨╕, ╨┐╤А╨╛╨┤╨░╨║╤И╨╡╨╜-╨║╨╛╨┤ ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В. ╨Ю╨▒╨╜╨░╤А╤Г╨╢╨╡╨╜╨╛ ╨┐╤А╨╕ ╨┐╤А╨╛╨│╨╛╨╜╨╡ ╨┐╨╛╨╗╨╜╨╛╨│╨╛ Feature-╨╜╨░╨▒╨╛╤А╨░ ╨┤╨╗╤П #724. Executor: Opus 5 (`claude-opus-5[1m]`).
- **╨Ь╨╛╤Б╤В ╨╛╨┐╨╗╨░╤В╨░тЖТ╤Б╨┤╨╡╨╗╨║╨░: ╨║╤Г╤А╤Б ╤Б╤В╨░╨╗ ╤А╨░╨╖╨╗╨╕╤З╨░╤О╤Й╨╕╨╝ ╨┐╤А╨╕╨╖╨╜╨░╨║╨╛╨╝ ╨╕ ╨╜╨░ ╨▓╨╡╤В╨║╨╡ ╨│╤А╤Г╨┐╨┐╤Л ╤А╨░╤Б╤Б╤А╨╛╤З╨║╨╕ (H1690, ╨╛╤Б╤В╨░╤В╨║╨╕ ╤А╨╡╨▓╤М╤О H1659).** ╨Ю╨▒╨╡ ╨╜╨░╤Е╨╛╨┤╨║╨╕ adversarial-╤А╨╡╨▓╤М╤О H1659, ╨╛╤Ж╨╡╨╜╤С╨╜╨╜╤Л╨╡ ╨║╨░╨║ PLAUSIBLE (╨░ ╨╜╨╡ CONFIRMED, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨▓ [PR #714](https://github.com/gasyoun/Systema-Sanscriticum/pull/714) ╨╕╤Е ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛ ╨╜╨╡ ╤З╨╕╨╜╨╕╨╗╨╕), ╨╖╨░╨║╤А╤Л╤В╤Л ╨▓╨╝╨╡╤Б╤В╨╡ ╤Б ╨╛╨┤╨╜╨╕╨╝ ╨╝╨╕╨╜╨╛╤А╨╜╤Л╨╝ ╨┐╤Г╨╜╨║╤В╨╛╨╝. **(1)** [`PaymentDealBridgeObserver::closeOrRecordDeal()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentDealBridgeObserver.php) ╨╕╤Б╨║╨░╨╗ ╤Б╨┤╨╡╨╗╨║╤Г ╨┐╨╗╨░╨╜╨░ ╨Ю╨Ф╨Э╨Ш╨Ь ╤Г╤Б╨╗╨╛╨▓╨╕╨╡╨╝ `where('installment_group_id', $group)` тАФ ╨▒╨╡╨╖ ╨║╤Г╤А╤Б╨░, ╤З╨╡╨╗╨╛╨▓╨╡╨║╨░ ╨╕ ╨╗╨╕╨┤╨░, ╤В╨╛╨│╨┤╨░ ╨║╨░╨║ ╤Б╨╛╤Б╨╡╨┤╨╜╤П╤П `findOpenDealFor()` ╨╜╨╡╤Б╤С╤В ╤А╨╛╨▓╨╜╨╛ ╨┐╤А╨╛ ╤Н╤В╨╛ ╨│╤А╨╛╨╝╨║╨╕╨╣ ╨┤╨╛╨║╨▒╨╗╨╛╨║ (╨┤╨╡╤Д╨╡╨║╤В 1 ╤А╨╡╨▓╤М╤О H1641: ┬л╨╛╨┐╨╗╨░╤В╨░ ╨║╤Г╤А╤Б╨░ B ╨╖╨░╨║╤А╤Л╨▓╨░╨╗╨░ ╤Б╨┤╨╡╨╗╨║╤Г ╨┐╨╛ ╨║╤Г╤А╤Б╤Г A┬╗). ╨а╨╡╨▓╤М╤О ╨┐╨╛╨║╨░╨╖╨░╨╗╨╛ ╨▓ ╤А╨░╨╜╤В╨░╨╣╨╝╨╡: ╤Б╨┤╨╡╨╗╨║╤Г ╨┐╨╗╨░╨╜╨░ G ╨╜╨░ ╨║╤Г╤А╤Б A ╤А╨╡╨▓╨╡╤А╤Б╤П╤В, ╤З╨╡╨╗╨╛╨▓╨╡╨║ ╤А╤Г╨║╨░╨╝╨╕ ╨┐╨╡╤А╨╡╨▓╨╛╨┤╨╕╤В ╨╡╤С ╨╜╨░ ╨║╤Г╤А╤Б B тАФ ╨╕ ╨▓╤В╨╛╤А╨╛╨╣ ╨▓╨╖╨╜╨╛╤Б ╨┐╨╗╨░╨╜╨░ G ╨╡╤С ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В. **╨а╨░╨╖╨▓╨╕╨╗╨║╨░ ╤А╨╡╤И╨╡╨╜╨░ ╨▓ ╨┐╨╛╨╗╤М╨╖╤Г ╨┐╤А╨╛╤З╤В╨╡╨╜╨╕╤П (╨▒) ┬л╨║╤Г╤А╤Б тАФ ╤А╨░╨╖╨╗╨╕╤З╨░╤О╤Й╨╕╨╣ ╨┐╤А╨╕╨╖╨╜╨░╨║ ╨Т╨Х╨Ч╨Ф╨Х┬╗**, ╨░ ╨╜╨╡ (╨░) ┬л╤В╨╛╨╢╨┤╨╡╤Б╤В╨▓╨╛ ╨┐╨╗╨░╨╜╨░ ╤Б╨╕╨╗╤М╨╜╨╡╨╡┬╗: ╤Ж╨╡╨╜╨░ ╨╛╤И╨╕╨▒╨║╨╕ ╨╜╨╡╤Б╨╕╨╝╨╝╨╡╤В╤А╨╕╤З╨╜╨░ тАФ ╨┐╨╛ (╨░) ╨▓╤Л╨╕╨│╤А╨░╨╜╨╜╨░╤П ╤Б╨┤╨╡╨╗╨║╨░ ╨┐╨╕╤И╨╡╤В╤Б╤П ╨╜╨░ ╨║╤Г╤А╤Б, ╨╖╨░ ╨║╨╛╤В╨╛╤А╤Л╨╣ ╨▓ ╤Н╤В╨╛╨╣ ╤В╤А╨░╨╜╨╖╨░╨║╤Ж╨╕╨╕ ╨╜╨╕╨║╤В╨╛ ╨╜╨╡ ╨┐╨╗╨░╤В╨╕╨╗, ╨╕ ╨╛╨┤╨╜╨╛╨▓╤А╨╡╨╝╨╡╨╜╨╜╨╛ ╨╜╨░╤Б╤В╨╛╤П╤Й╨░╤П ╨┐╤А╨╛╨┤╨░╨╢╨░ ╨║╤Г╤А╤Б╨░ A ╨╕╤Б╤З╨╡╨╖╨░╨╡╤В ╨╕╨╖ ╨▓╨╛╤А╨╛╨╜╨║╨╕ (╨┤╨▓╨╡ ╨╝╨╛╨╗╤З╨░╨╗╨╕╨▓╤Л╤Е ╨┐╨╛╤А╤З╨╕ ╨╛╤В╤З╤С╤В╨╜╨╛╤Б╤В╨╕), ╨┐╨╛ (╨▒) ╤Е╤Г╨┤╤И╨╕╨╣ ╨╕╤Б╤Е╨╛╨┤ тАФ ╨▓╤В╨╛╤А╨░╤П, ╨▓╨╕╨┤╨╕╨╝╨░╤П ╤Б╨┤╨╡╨╗╨║╨░ ╨╜╨░ ╤В╤Г ╨╢╨╡ ╨│╤А╤Г╨┐╨┐╤Г, ╨╖╨░╤В╨╛ ╨┤╨╡╨╜╤М╨│╨╕ ╨╗╨╡╨│╨╗╨╕ ╨╜╨░ ╤Б╨▓╨╛╨╣ ╨║╤Г╤А╤Б. ╨Т╨╡╤В╨║╨░ ╨▓╤Л╨╜╨╡╤Б╨╡╨╜╨░ ╨▓ `dealOfPlan()` ╤Б ╤В╨╛╨╣ ╨╢╨╡ ╤В╨╡╤А╨┐╨╕╨╝╨╛╤Б╤В╤М╤О ╨║ null, ╤З╤В╨╛ ╨╕ ╤Г `findOpenDealFor()` (╤Б╨┤╨╡╨╗╨║╨░ ╨┐╨╗╨░╨╜╨░ ╤Б ╨╡╤Й╤С ╨╜╨╡ ╨┐╤А╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╨╜╤Л╨╝ ╨║╤Г╤А╤Б╨╛╨╝ ╨╝╨╛╨╢╨╡╤В ╨▒╤Л╤В╤М ┬л╤В╨╛╨╣ ╤Б╨░╨╝╨╛╨╣┬╗; ╨┐╨╗╨░╤В╤С╨╢ ╨▒╨╡╨╖ ╨║╤Г╤А╤Б╨░ ╨┤╨╛╨▓╨╡╤А╤П╨╡╤В ╨│╤А╤Г╨┐╨┐╨╡ ╤Ж╨╡╨╗╨╕╨║╨╛╨╝), ╤З╤В╨╛╨▒╤Л ╨╜╨╛╨▓╤Л╨╣ ╨│╨░╤А╨┤ ╨╜╨╡ ╨╜╨░╤З╨░╨╗ ╨┐╨╗╨╛╨┤╨╕╤В╤М ╨┤╤Г╨▒╨╗╨╕. Ruling ╨┐╤А╨╛╨│╨╛╨▓╨╛╤А╤С╨╜ ╨▓ ╨┤╨╛╨║╨▒╨╗╨╛╨║╨╡ тАФ ╨┤╨╛ H1690 ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╨╡ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕ ╨║╤Г╤А╤Б╨░ ╤З╨╕╤В╨░╨╗╨╛╤Б╤М ╨║╨░╨║ ╨╜╨╡╨┤╨╛╤Б╨╝╨╛╤В╤А. **╨Ю╨▒╤П╨╖╨░╤В╨╡╨╗╤М╨╜╨╛╨╡ adversarial-╤А╨╡╨▓╤М╤О ╨┐╨╛╨╣╨╝╨░╨╗╨╛ ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╤О ╨▓ ╤Б╨░╨╝╨╛╨╣ ╤Н╤В╨╛╨╣ ╨┐╤А╨░╨▓╨║╨╡ тАФ ╨╕, ╨▓ ╨╛╤В╨╗╨╕╤З╨╕╨╡ ╨╛╤В H1659, ╨Ф╨Ю ╨╝╨╡╤А╨┤╨╢╨░:** `dealOfPlan()` ╨┐╤А╨╛╤Б╨╝╨░╤В╤А╨╕╨▓╨░╨╡╤В ╨Т╨б╨о ╨│╤А╤Г╨┐╨┐╤Г, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨╡╨│╨╛ ╨╛╤В╨║╨░╨╖ ╨╛╨╖╨╜╨░╤З╨░╨╗, ╤З╤В╨╛ ╨╜╨╕ ╨╛╨┤╨╜╨░ ╤Б╨┤╨╡╨╗╨║╨░ ╨┐╨╗╨░╨╜╨░ ╨┐╨╛ ╨║╤Г╤А╤Б╤Г ╨╜╨╡ ╨┐╨╛╨┤╨╛╤И╨╗╨░, ╨░ OR-╤Г╤Б╨╗╨╛╨▓╨╕╨╡ `findOpenDealFor()` (┬л╨│╤А╤Г╨┐╨┐╨░ ╨┐╤Г╤Б╤В╨░ ╨Ш╨Ы╨Ш ╤А╨░╨▓╨╜╨░ G┬╗) ╨┐╨╛╤Б╨╗╨╡ ╤Н╤В╨╛╨│╨╛ ╤Б╨┐╨╛╤Б╨╛╨▒╨╜╨╛ ╨╜╨░╨╣╤В╨╕ ╨в╨Ю╨Ы╨м╨Ъ╨Ю ╤Б╨┤╨╡╨╗╨║╤Г ╨С╨Х╨Ч ╨│╤А╤Г╨┐╨┐╤Л тАФ ╤В╨╛ ╨╡╤Б╤В╤М ╨║╨░╨╢╨┤╤Л╨╣ ╤А╨░╨╖, ╨║╨╛╨│╨┤╨░ ╤Б╤А╨░╨▒╨░╤В╤Л╨▓╨░╨╗ ╨╜╨╛╨▓╤Л╨╣ ╨│╨░╤А╨┤, ╨┐╤А╨╛╨▓╨░╨╗ ╤Г╨┐╤А╨░╨▓╨╗╨╡╨╜╨╕╤П ╨▓╤С╨╗ ╨▓ ╨╖╨░╨▓╨╡╨┤╨╛╨╝╨╛ ╤З╤Г╨╢╤Г╤О ╤Б╨┤╨╡╨╗╨║╤Г, ╨╕ `closeDealWith()` ╨╜╨░╨▓╤Б╨╡╨│╨┤╨░ ╨║╨╗╨╡╨╣╨╝╨╕╨╗ ╨╡╤С ╤И╤В╨░╨╝╨┐╨╛╨╝ ╤З╤Г╨╢╨╛╨│╨╛ ╨┐╨╗╨░╨╜╨░. ╨б╤В╤А╨╛╨│╨╛ ╤Е╤Г╨╢╨╡, ╤З╨╡╨╝ ╨┤╨╛ H1690 (╤В╨░╨╝ ╤Н╤В╨╛╤В ╤Б╨╗╤Г╤З╨░╨╣ ╨▒╤Л╨╗ ╨╜╨╡╨╝╤Л╨╝), ╨╕ ╨┤╨╛╤Б╤В╨╕╨╢╨╕╨╝╨╛ ╨▒╨╡╨╖ ╨╡╨┤╨╕╨╜╨╛╨│╨╛ ╤Б╨╕╨╜╤В╨╡╤В╨╕╤З╨╡╤Б╨║╨╛╨│╨╛ ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╤П: ╨║╤Г╤А╨░╤В╨╛╤А ╨┐╤А╨░╨▓╨╕╤В ╨║╤Г╤А╤Б ╨╛╨┤╨╜╨╛╨│╨╛ ╨▓╨╖╨╜╨╛╤Б╨░ ╨╢╨╕╨▓╤Л╨╝ `EditAction` ╨╛╨▒╨╡╤Й╨░╨╜╨╕╤П. ╨Ч╨░╨║╤А╤Л╤В╨╛ ╨│╨░╤А╨┤╨╛╨╝ `$planOwnsADeal` тАФ ╨╡╤Б╨╗╨╕ ╨┐╨╗╨░╨╜ ╤Г╨╢╨╡ ╨▓╨╗╨░╨┤╨╡╨╡╤В ╤Б╨┤╨╡╨╗╨║╨╛╨╣, ╨░ ╨┐╨╗╨░╤В╤С╨╢ ╨┐╨╛ ╨║╤Г╤А╤Б╤Г ╤Б ╨╜╨╡╨╣ ╤А╨░╨╖╨╛╤И╤С╨╗╤Б╤П, `findOpenDealFor()` ╨┐╤А╨╛╨┐╤Г╤Б╨║╨░╨╡╤В╤Б╤П ╨╕ ╨╖╨░╨▓╨╛╨┤╨╕╤В╤Б╤П ╤Б╨▓╨╛╤П ╤Б╨┤╨╡╨╗╨║╨░: ╤А╨╛╨▓╨╜╨╛ ╤В╨░ ┬л╨▓╤В╨╛╤А╨░╤П ╨Т╨Ш╨Ф╨Ш╨Ь╨Р╨п ╤Б╨┤╨╡╨╗╨║╨░ ╨╜╨░ ╤В╤Г ╨╢╨╡ ╨│╤А╤Г╨┐╨┐╤Г┬╗, ╨║╨╛╤В╨╛╤А╤Г╤О ╨╛╨▒╨╡╤Й╨░╨╗ ruling ╨╕ ╨║╨╛╤В╨╛╤А╨╛╨╣ ╨▓ ╨║╨╛╨┤╨╡ ╨┤╨╛ ╤А╨╡╨▓╤М╤О ╨╜╨╡ ╨▒╤Л╨╗╨╛. ╨Я╨╡╤А╨▓╤Л╨╣ ╨▓╨╖╨╜╨╛╤Б ╨┐╨╗╨░╨╜╨░ (╨│╤А╤Г╨┐╨┐╤Л ╨▓ `deals` ╨╡╤Й╤С ╨╜╨╡╤В) ╨┐╨╛-╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г ╤И╤В╨░╤В╨╜╨╛ ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В ╨╝╨╡╨╜╨╡╨┤╨╢╨╡╤А╤Б╨║╤Г╤О ╨╛╤В╨║╤А╤Л╤В╤Г╤О ╤Б╨┤╨╡╨╗╨║╤Г. **(2)** ╨Т╤В╨╛╤А╨╛╨╣ ╨┐╨╡╤А╨╡╤Е╨╛╨┤ ╤Ж╨╡╨┐╨╛╤З╨║╨╕ (`payment_promises.fulfilled_payment_id`) ╤А╨╡╨▓╤М╤О ╤Б╨╛╤З╨╗╨╛ ╨┐╨╛╤З╤В╨╕ ╨╝╤С╤А╤В╨▓╤Л╨╝ ╨║╨╛╨┤╨╛╨╝: `PromiseAutoFulfiller::handlePaidPayment()` ╨▓╤Л╤Е╨╛╨┤╨╕╤В ╨╜╨░ ╨┐╤Г╤Б╤В╨╛╨╝ `linked_promise_id`, ╨╖╨╜╨░╤З╨╕╤В ╤В╨░╨╝, ╨│╨┤╨╡ ╨╛╨▒╤А╨░╤В╨╜╤Г╤О ╤Б╨▓╤П╨╖╤М ╤Б╤В╨░╨▓╨╕╤В ╨╛╨╜, ╨┐╨╡╤А╨▓╤Л╨╣ ╨┐╨╡╤А╨╡╤Е╨╛╨┤ ╤Г╨╢╨╡ ╨▓╨╡╤А╨╜╤Г╨╗ ╤В╤Г ╨╢╨╡ ╨│╤А╤Г╨┐╨┐╤Г. **╨Я╨╡╤А╨╡╤Е╨╛╨┤ ╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜ ╨╛╤Б╨╛╨╖╨╜╨░╨╜╨╜╨╛** тАФ ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜╨╛, ╤З╤В╨╛ ╨╢╨╕╨▓╨╛╨╣ ╤Б╨╗╤Г╤З╨░╨╣ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╨╡╤В ╨╕ ╨╛╨╜ ╨╡╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣: ╨║╤Г╤А╨░╤В╨╛╤А╤Б╨║╨╕╨╣ ╨┐╨╗╨░╤В╤С╨╢ `PromiseFulfillment::fulfil()` (╤Б╨▓╤П╨╖╤Л╨▓╨░╨╡╤В ╨╛╨▒╨╡╤Й╨░╨╜╨╕╨╡ ╨Я╨Ю╨б╨Ы╨Х ╤Б╨╛╨╖╨┤╨░╨╜╨╕╤П ╨┐╨╗╨░╤В╨╡╨╢╨░), ╨║╨╛╤В╨╛╤А╤Л╨╣ ╨╛╤В╨║╨░╤В╨╕╨╗╨╕ ╨╕ ╨┐╤А╨╛╨▓╨╡╨╗╨╕ ╨╖╨░╨╜╨╛╨▓╨╛, тАФ ╨┐╤А╤П╨╝╨╛╨╣ ╤Б╨▓╤П╨╖╨╕ ╤Г ╨╜╨╡╨│╨╛ ╨╜╨╡╤В ╨╜╨╕╨║╨╛╨│╨┤╨░, ╨░ ╤В╤А╨╡╤В╨╕╨╣ ╨┐╨╡╤А╨╡╤Е╨╛╨┤ ╨║ ╤Н╤В╨╛╨╝╤Г ╨╝╨╛╨╝╨╡╨╜╤В╤Г ╨╝╨╛╨╗╤З╨╕╤В, ╨┐╨╛╤В╨╛╨╝╤Г ╤З╤В╨╛ ╨╛╨▒╨╡╤Й╨░╨╜╨╕╨╡ ╤Г╨╢╨╡ `FULFILLED` ╨╕ ╤А╨╡╨▓╨╡╤А╤Б ╨╡╨│╨╛ ╨▓ `active` ╨╜╨╡ ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В. ╨Я╤А╨╕╤З╨╕╨╜╨░ ╨╖╨░╨┐╨╕╤Б╨░╨╜╨░ ╨▓ ╨┤╨╛╨║╨▒╨╗╨╛╨║╨╡, ╨░ ╤В╨╡╤Б╤В `instalment_is_recognised_through_the_reverse_promise_link` ╨┐╨╡╤А╨╡╨┐╨╕╤Б╨░╨╜ ╤Б ╤Б╨╕╨╜╤В╨╡╤В╨╕╤З╨╡╤Б╨║╨╛╨╣ ╤Б╨▓╤П╨╖╨╕ ╤З╨╡╤А╨╡╨╖ `withoutEvents` + ╨┐╤А╤П╨╝╨╛╨╣ ╨▓╤Л╨╖╨╛╨▓ ╨╛╨▒╤Б╨╡╤А╨▓╨╡╤А╨░ ╨╜╨░ ╨а╨Х╨Р╨Ы╨м╨Э╨л╨Щ ╨┐╤Г╤В╤М ┬л╨║╤Г╤А╨░╤В╨╛╤А╤Б╨║╨╕╨╣ ╨▓╨╖╨╜╨╛╤Б тЖТ ╤А╨╡╨▓╨╡╤А╤Б тЖТ ╨┐╨╛╨▓╤В╨╛╤А╨╜╨░╤П ╨╛╨┐╨╗╨░╤В╨░┬╗ (╨┐╨╗╨░╨╜ ╨╕╨╖ ╨╛╨┤╨╜╨╛╨│╨╛ ╨▓╨╖╨╜╨╛╤Б╨░, ╨╕╨╜╨░╤З╨╡ ╨│╤А╤Г╨┐╨┐╤Г ╨▓╨╡╤А╨╜╤Г╨╗ ╨▒╤Л `unmetPlanFor` ╨╕ ╤В╨╡╤Б╤В ╨┐╤А╨╛╨▓╨╡╤А╤П╨╗ ╨▒╤Л ╨╜╨╡ ╤В╨╛╤В ╨┐╨╡╤А╨╡╤Е╨╛╨┤). **(3)** ╨Ь╨╕╨╜╨╛╤А: ╨┐╨╛╨╕╤Б╨║ ╤Б╨┤╨╡╨╗╨║╨╕ ╨┐╨╗╨░╨╜╨░ ╤Г╨┐╨╛╤А╤П╨┤╨╛╤З╨╡╨╜ ╨┐╨╛ `id`, ╨░ ╨╜╨╡ `oldest()`/`created_at` тАФ ╤Б╨╡╨║╤Г╨╜╨┤╨╜╨░╤П ╤В╨╛╤З╨╜╨╛╤Б╤В╤М ╨╜╨░ MySQL ╨╕ SQLite ╨┤╨░╨▓╨░╨╗╨░ ╨╜╨╡╤Г╤Б╤В╨╛╨╣╤З╨╕╨▓╤Л╨╣ ╤В╨░╨╣-╨▒╤А╨╡╨╣╨║ ╨┐╤А╨╕ ╨║╨╛╨╜╨║╤Г╤А╨╡╨╜╤В╨╜╨╛╨╣ ╨┤╨╛╤Б╤В╨░╨▓╨║╨╡ ╨┤╨▓╤Г╤Е ╨▓╨╖╨╜╨╛╤Б╨╛╨▓. ╨Т╤Б╨╡ ╤В╤А╨╕ ╨┐╤Г╨╜╨║╤В╨░ ╨Ш╨Ч╨Ь╨Х╨а╨Х╨Э╨л ╨╝╤Г╤В╨░╤Ж╨╕╨╛╨╜╨╜╨╛╨╣ ╨┐╤А╨╛╨▓╨╡╤А╨║╨╛╨╣, ╨░ ╨╜╨╡ ╨╖╨░╤П╨▓╨╗╨╡╨╜╤Л: `instalment_for_a_repointed_course_never_hijacks_an_unrelated_deal` ╨┐╨░╨┤╨░╨╡╤В ╨╜╨░ ╨║╨╛╨┤╨╡ ╨┤╨╛ ╨│╨░╤А╨┤╨░ `$planOwnsADeal`, `instalment_is_recognised_through_the_reverse_promise_link` тАФ ╤Б ╨▓╤Л╤А╨╡╨╖╨░╨╜╨╜╤Л╨╝ ╨▓╤В╨╛╤А╤Л╨╝ ╨┐╨╡╤А╨╡╤Е╨╛╨┤╨╛╨╝, `plan_deal_lookup_breaks_ties_by_id_not_by_timestamp` тАФ ╨┐╤А╨╕ ╨╛╤В╨║╨░╤В╨╡ ╤Б╨╛╤А╤В╨╕╤А╨╛╨▓╨║╨╕ ╨╜╨░ `oldest()` (╨┐╨╡╤А╨▓╨░╤П ╨▓╨╡╤А╤Б╨╕╤П ╤Н╤В╨╛╨│╨╛ ╤В╨╡╤Б╤В╨░ ╨┤╨░╨▓╨░╨╗╨░ ╨╛╨▒╨╡╨╕╨╝ ╤Б╨┤╨╡╨╗╨║╨░╨╝ ╨╛╨┤╨╕╨╜╨░╨║╨╛╨▓╤Л╨╣ `created_at` ╨╕ ╨┐╨╛╤В╨╛╨╝╤Г ╨┐╤А╨╛╤Е╨╛╨┤╨╕╨╗╨░ ╨┐╤А╨╕ ╨╗╤О╨▒╨╛╨╣ ╤Б╨╛╤А╤В╨╕╤А╨╛╨▓╨║╨╡ тАФ SQLite ╨┐╤А╨╕ ╤А╨░╨▓╨╡╨╜╤Б╤В╨▓╨╡ ╨║╨╗╤О╤З╨░ ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В ╤Б╤В╤А╨╛╨║╨╕ ╨▓ ╨┐╨╛╤А╤П╨┤╨║╨╡ rowid; ╨┐╨╡╤А╨╡╤Б╨╛╨▒╤А╨░╨╜ ╤В╨░╨║, ╤З╤В╨╛╨▒╤Л ╨┐╨╛╤А╤П╨┤╨╛╨║ ╨┐╨╛ `created_at` ╨▒╤Л╨╗ ╨╛╨▒╤А╨░╤В╨╡╨╜ ╨┐╨╛╤А╤П╨┤╨║╤Г ╨┐╨╛ `id`). ╨в╨╡╤Б╤В ╨│╤А╨░╨╜╨╕╤Ж╤Л ╤А╨░╨╜╨│╨╛╨▓ 1тАУ5 ╨┐╨╛-╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г ╨┐╤А╨╛╤Е╨╛╨┤╨╕╤В ╨┐╨╛ ╨│╤А╤Г╨┐╨┐╨╛╨▓╨╛╨╣ ╨▓╨╡╤В╨║╨╡. ╨Ф╨╡╨╜╨╡╨╢╨╜╤Л╨╣ ╨┐╤Г╤В╤М ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В (`InstallmentPlanCreator`, `PromiseAutoFulfiller`, `PromiseFulfillment`, `DebtPaymentController` тАФ ╤В╨╛╨╗╤М╨║╨╛ ╤З╤В╨╡╨╜╨╕╨╡), ╨╜╨╛╨▓╤Л╤Е ╨╝╨╕╨│╤А╨░╤Ж╨╕╨╣ ╨╕ ╤Д╨╗╨░╨│╨╛╨▓ ╨╜╨╡╤В, ╨▓╤Б╤С ╨┐╨╛-╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г ╨╖╨░ `crm_pipeline_board` (default OFF). Executor: Opus 5 (`claude-opus-5[1m]`).

## [1.52.0] - 2026-07-26

### Added
- **GetCourse-parity F9 тАФ ╤Б╨▓╨╛╨┤╨╜╨░╤П ╨┤╨╛╤Б╨║╨░ ╨┐╤А╨╛╨┤╨░╨╢ ╨║╨░╨║ ╨Р╨Ы╨м╨в╨Х╨а╨Э╨Р╨в╨Ш╨Т╨Э╨л╨Щ ╤В╤А╨╡╤В╨╕╨╣ UI (H1658).** ╨а╨░╨╖╨▓╨╕╨╗╨║╨░ F9 ╤Б╨┐╨╡╨║╨╕ ┬з7 (┬л╤З╤В╨╛ ╨┤╨╡╨╗╨░╤В╤М ╤Б ╨┤╨╛╤Б╨║╨╛╨╣ ╨╖╨░╤П╨▓╨╛╨║ ╤В╨╡╨┐╨╡╤А╤М, ╨║╨╛╨│╨┤╨░ ╨╡╤Б╤В╤М ╨┤╨╛╤Б╨║╨░ ╤Б╨┤╨╡╨╗╨╛╨║┬╗) **╨╖╨░╨║╤А╤Л╤В╨░ MG 26-07-2026** ╨▓╨░╤А╨╕╨░╨╜╤В╨╛╨╝ (╨░)+(╨▓) ╨░╨┤╨┤╨╕╤В╨╕╨▓╨╜╨╛: ╨╛╨▒╨╡ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╡ ╨┤╨╛╤Б╨║╨╕ тАФ ┬л╨Ч╨░╤П╨▓╨║╨╕ тАФ ╨┤╨╛╤Б╨║╨░┬╗ ([`LeadKanbanBoard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/LeadKanbanBoard.php), H451) ╨╕ ┬л╨б╨┤╨╡╨╗╨║╨╕ тАФ ╨┤╨╛╤Б╨║╨░┬╗ ([`DealKanbanBoard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/DealKanbanBoard.php), H1641) тАФ ╨╛╤Б╤В╨░╤О╤В╤Б╤П ╨╜╨╡╤В╤А╨╛╨╜╤Г╤В╤Л╨╝╨╕, ╨░ ╨╛╨▒╤Й╨╕╨╣ ╤Б╨╗╨╛╨╣ ╤Б╤В╨░╨┤╨╕╨╣ ╤Б╤В╤А╨╛╨╕╤В╤Б╤П ╨в╨а╨Х╨в╨м╨Ш╨Ь ╨┐╤А╨╡╨┤╤Б╤В╨░╨▓╨╗╨╡╨╜╨╕╨╡╨╝ ╤А╤П╨┤╨╛╨╝. ╨н╤В╨╛ ╨Э╨Х ╨▓╨░╤А╨╕╨░╨╜╤В (╨▒) (╤Г╨▒╤А╨░╤В╤М ╨┤╨╛╤Б╨║╤Г ╨╖╨░╤П╨▓╨╛╨║) ╨╕ ╨Э╨Х ╤А╨░╨╖╤А╤Г╤И╨╕╤В╨╡╨╗╤М╨╜╨░╤П ╤Д╨╛╤А╨╝╨░ ╨▓╨░╤А╨╕╨░╨╜╤В╨░ (╨▓): ╤Д╨╕╨╖╨╕╤З╨╡╤Б╨║╨╛╨│╨╛ ╤Б╨▓╨╡╨┤╨╡╨╜╨╕╤П `lead_stages` ╨╕ `deal_stages` ╨▓ ╨╛╨┤╨╜╤Г ╤В╨░╨▒╨╗╨╕╤Ж╤Г ╨╜╨╡╤В тАФ ╤Н╤В╨╛ ╤А╨░╨╖╨▓╨╕╨╗╨║╨░ **F3**, ╤Г╨╢╨╡ ╤А╨╡╤И╤С╨╜╨╜╨░╤П ╨▓ ╨┐╨╛╨╗╤М╨╖╤Г ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╤Е ╤В╨░╨▒╨╗╨╕╤Ж (╤Б╤В╤А╨╛╨║╨╛╨▓╤Л╨╣ `key` тЖФ ╤З╨╕╤Б╨╗╨╛╨▓╨╛╨╣ `id`, ╨╝╨╕╨│╤А╨░╤Ж╨╕╤П ╤В╤А╨╛╨│╨░╨╗╨░ ╨▒╤Л ╨╢╨╕╨▓╤Л╨╡ `leads`). **╨Э╨╕ ╨╛╨┤╨╜╨╛╨╣ ╨╝╨╕╨│╤А╨░╤Ж╨╕╨╕**; `leads.status`, `lead_stages`, `LeadResource`, `Lead::statuses()`, `RemindLeadsForFollowup` ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В╤Л. ╨Э╨╛╨▓╨░╤П ╤Б╤В╤А╨░╨╜╨╕╤Ж╨░ [`UnifiedSalesBoard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/UnifiedSalesBoard.php) (slug `sales-board`, ╨│╤А╤Г╨┐╨┐╨░ ┬л╨Я╤А╨╛╨┤╨░╨╢╨╕┬╗, sort 70 тАФ ╨╜╨░╨┤ ╨╛╨▒╨╡╨╕╨╝╨╕ ╨┤╨╛╤Б╨║╨░╨╝╨╕) ╨┐╨╛╨▓╨╡╤А╤Е [`UnifiedSalesStage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/UnifiedSalesStage.php) тАФ ╤Б╨╗╨╛╨▓╨░╤А╤П ╨╕╨╖ ╤З╨╡╤В╤Л╤А╤С╤Е ╨╛╨▒╤Й╨╕╤Е ╨║╨╛╨╗╨╛╨╜╨╛╨║ (╨Э╨╛╨▓╤Л╨╡ ┬╖ ╨Т ╤А╨░╨▒╨╛╤В╨╡ ┬╖ ╨Т╤Л╨╕╨│╤А╨░╨╜╨╛ ┬╖ ╨Я╤А╨╛╨╕╨│╤А╨░╨╜╨╛), ╨║╨╛╤В╨╛╤А╤Л╨╣ ╨╢╨╕╨▓╤С╤В ╨в╨Ю╨Ы╨м╨Ъ╨Ю ╨▓ ╤Б╨╗╨╛╨╡ ╨┐╤А╨╡╨┤╤Б╤В╨░╨▓╨╗╨╡╨╜╨╕╤П ╨╕ ╨╜╨╕ ╨▓╨╛ ╤З╤В╨╛ ╨╜╨╡ ╨╖╨░╨┐╨╕╤Б╤Л╨▓╨░╨╡╤В╤Б╤П (╨╖╨░╨║╤А╨╡╨┐╨╗╨╡╨╜╨╛ ╤В╨╡╤Б╤В╨╛╨╝ `the_common_vocabulary_is_never_persisted_into_either_stage_table`). Value-╨║╨╗╨░╤Б╤Б, ╨░ ╨╜╨╡ ╨╝╨░╤Б╤Б╨╕╨▓ ╨▓ `config/`: ╤Г ╤Б╨╗╨╛╨▓╨░╤А╤П ╨╡╤Б╤В╤М ╨┐╨╛╨▓╨╡╨┤╨╡╨╜╨╕╨╡ (╤Б╨╛╨┐╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╨╕╨╡ ╨▓ ╨╛╨▒╨╡ ╤Б╤В╨╛╤А╨╛╨╜╤Л + ╨┐╨╛╨┤╨▒╨╛╤А ╤Ж╨╡╨╗╨╡╨▓╨╛╨╣ ╤Б╤В╨░╨┤╨╕╨╕), ╨░ ╤З╨░╤Б╤В╤М ╨╡╨│╨╛ ╨Т╨л╨Т╨Ю╨Ф╨Ш╨в╨б╨п ╨╕╨╖ ╨╢╨╕╨▓╤Л╤Е ╤Б╤В╤А╨╛╨║ ╨╕ ╨╜╨╡ ╨┐╨╡╤А╨╡╨╢╨╕╨╗╨░ ╨▒╤Л `config:cache`. ╨Р╤Б╨╕╨╝╨╝╨╡╤В╤А╨╕╤П ╤Б╤В╨╛╤А╨╛╨╜ ╨╜╨░╨╝╨╡╤А╨╡╨╜╨╜╨░╤П: **╤Б╨┤╨╡╨╗╨║╨╕** ╤А╨░╤Б╨║╨╗╨░╨┤╤Л╨▓╨░╤О╤В╤Б╤П ╤Б╤В╤А╤Г╨║╤В╤Г╤А╨╜╨╛, ╨╕╨╖ ╨┤╨░╨╜╨╜╤Л╤Е (`is_won` тЖТ ┬л╨Т╤Л╨╕╨│╤А╨░╨╜╨╛┬╗, `is_lost` тЖТ ┬л╨Я╤А╨╛╨╕╨│╤А╨░╨╜╨╛┬╗, ╨┐╨╡╤А╨▓╨░╤П ╨┐╨╛ `position` тЖТ ┬л╨Э╨╛╨▓╤Л╨╡┬╗, ╨╛╤Б╤В╨░╨╗╤М╨╜╤Л╨╡ тЖТ ┬л╨Т ╤А╨░╨▒╨╛╤В╨╡┬╗), ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╤Б╨▓╨╛╤П ╤Б╤В╨░╨┤╨╕╤П ╨░╨┤╨╝╨╕╨╜╨░ ╨╜╨╡ ╤В╤А╨╡╨▒╤Г╨╡╤В ╨┐╤А╨░╨▓╨║╨╕ ╨║╨╛╨┤╨░; **╨╖╨░╤П╨▓╨║╨╕** ╤В╨░╨║ ╨▓╤Л╨▓╨╡╤Б╤В╨╕ ╨╜╨╡╨╗╤М╨╖╤П тАФ `lead_stages` ╨╜╨╡╤Б╤С╤В ╤В╨╛╨╗╤М╨║╨╛ `is_final` ╨╕ ╨╜╨╡ ╨╛╤В╨╗╨╕╤З╨░╨╡╤В ┬л╨Ъ╨╛╨╜╨▓╨╡╤А╤Б╨╕╤О┬╗ ╨╛╤В ┬л╨Ю╤В╨║╨░╨╖╨░┬╗, ╨┤╨╗╤П ╨╜╨╕╤Е ╤П╨▓╨╜╨░╤П ╤В╨░╨▒╨╗╨╕╤Ж╨░ ╨║╨╗╤О╤З╨╡╨╣. ╨Э╨╡╨╖╨╜╨░╨║╨╛╨╝╨░╤П ╤Б╤В╨░╨┤╨╕╤П ╤Б ╨╗╤О╨▒╨╛╨╣ ╤Б╤В╨╛╤А╨╛╨╜╤Л ╨┐╨░╨┤╨░╨╡╤В ╨▓ ┬л╨Т ╤А╨░╨▒╨╛╤В╨╡┬╗, ╨░ ╨╜╨╡ ╨╕╤Б╤З╨╡╨╖╨░╨╡╤В ╤Б ╨┤╨╛╤Б╨║╨╕. ╨Ъ╨░╤А╤В╨╛╤З╨║╨╕ ╤А╨░╨╖╨╗╨╕╤З╨░╤О╤В╤Б╤П ╨▒╨╡╨╣╨┤╨╢╨╡╨╝ ┬л╨Ч╨░╤П╨▓╨║╨░┬╗/┬л╨б╨┤╨╡╨╗╨║╨░┬╗ ╨╕ ╤Б╨╛╤Б╤В╨░╨▓╨╜╤Л╨╝ DOM-id (`lead-12` / `deal-12`) тАФ ╤З╨╕╤Б╨╗╨╛╨▓╤Л╨╡ ╨║╨╗╤О╤З╨╕ ╨┤╨▓╤Г╤Е ╤Б╤Г╤Й╨╜╨╛╤Б╤В╨╡╨╣ ╨┐╨╡╤А╨╡╤Б╨╡╨║╨░╤О╤В╤Б╤П, ╨╕ ╨▒╨╡╨╖ ╤Н╤В╨╛╨│╨╛ drag-drop ╨┐╨╡╤А╨╡╨╜╨╛╤Б╨╕╨╗ ╨▒╤Л ╨╜╨╡ ╤В╤Г ╨╖╨░╨┐╨╕╤Б╤М. ╨Я╨╡╤А╨╡╨╜╨╛╤Б ╨┐╨╕╤И╨╡╤В ╨▓ ╨а╨Ю╨Ф╨Э╨г╨о ╤Б╤Г╤Й╨╜╨╛╤Б╤В╤М: ╨╖╨░╤П╨▓╨║╨░ тАФ `leads.status` ╨╜╨░╨┐╤А╤П╨╝╤Г╤О (╨╕ ╨╡╤С `lead_audits`, ╨║╨░╨║ ╨╜╨░ ╨╛╨┤╨╕╨╜╨╛╤З╨╜╨╛╨╣ ╨┤╨╛╤Б╨║╨╡), ╤Б╨┤╨╡╨╗╨║╨░ тАФ ╤З╨╡╤А╨╡╨╖ `Deal::moveToStage()`, ╤З╤В╨╛╨▒╤Л ╨╢╤Г╤А╨╜╨░╨╗ `deal_transitions` ╨┐╤А╨╛╨┤╨╛╨╗╨╢╨░╨╗ ╨╜╨░╨┐╨╛╨╗╨╜╤П╤В╤М╤Б╤П ╨╕ ╨┐╨╛╨┤╨┐╨╕╤Б╤Л╨▓╨░╤В╤М╤Б╤П ╨╝╨╡╨╜╨╡╨┤╨╢╨╡╤А╨╛╨╝. ╨Ю╨▒╨░ ╨│╨░╤А╨┤╨░ `blocksRollbackToFirstStage` ╨╛╤В╨║╨╗╨╛╨╜╤П╤О╤В ╨╛╤В╨║╨░╤В ╤А╨╛╨▓╨╜╨╛ ╨║╨░╨║ ╨╜╨░ ╨╛╨┤╨╕╨╜╨╛╤З╨╜╤Л╤Е ╨┤╨╛╤Б╨║╨░╤Е. ╨Я╨╡╤А╨╡╨╜╨╛╤Б ╨Т╨Э╨г╨в╨а╨Ш ╨╛╨┤╨╜╨╛╨╣ ╨║╨╛╨╗╨╛╨╜╨║╨╕ тАФ ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╤Л╨╣ no-op: ╨╛╨▒╤К╨╡╨┤╨╕╨╜╤С╨╜╨╜╨░╤П ╨║╨╛╨╗╨╛╨╜╨║╨░ ╨╜╨╡ ╨╕╨╝╨╡╨╡╤В ╨┐╤А╨░╨▓╨░ ╨╝╨╛╨╗╤З╨░ ╨┐╨╛╨╜╨╕╨╖╨╕╤В╤М ┬л╨Ъ╨▓╨░╨╗╨╕╤Д╨╕╤Ж╨╕╤А╨╛╨▓╨░╨╜┬╗ ╨┤╨╛ ┬л╨Т ╤А╨░╨▒╨╛╤В╨╡┬╗ ╨╕╨╗╨╕ ╨╖╨░╨┐╨╕╤Б╨░╤В╤М ╨╗╨╕╤И╨╜╤О╤О ╤Б╤В╤А╨╛╨║╤Г ╨┐╨╡╤А╨╡╤Е╨╛╨┤╨░. ╨Ч╨░ ╤В╨╡╨╝ ╨╢╨╡ ╤Д╨╗╨░╨│╨╛╨╝ `crm_pipeline_board` (default `false`) ╨┐╨╗╤О╤Б ╤В╨╛╤В ╨╢╨╡ `RoleGate::any(ADMIN, MANAGER)` тАФ **╤Б╨▓╨╛╨╡╨│╨╛ ╤Д╨╗╨░╨│╨░ ╨╜╨╡ ╨╖╨░╨▓╨╛╨┤╨╕╨╗╨╕**, ╤Н╤В╨╛ ╤В╨░ ╨╢╨╡ ╨┐╨╛╨▓╨╡╤А╤Е╨╜╨╛╤Б╤В╤М GC-C1. ╨в╨╡╤Б╤В╤Л: `UnifiedSalesBoardTest` (15). ╨б╨┐╨╡╨║╨░ ┬з7 F9 ╨┐╨╡╤А╨╡╨┐╨╕╤Б╨░╨╜╨░ ╨║╨░╨║ ╤А╨╡╤И╨╡╨╜╨╕╨╡ (╨╕╤Б╤Е╨╛╨┤╨╜╨░╤П ╤Д╨╛╤А╨╝╤Г╨╗╨╕╤А╨╛╨▓╨║╨░ ╤Б╨╛╤Е╤А╨░╨╜╨╡╨╜╨░ ╨╜╨╕╨╢╨╡), ╤Б╤В╤А╨╛╨║╨░ GC-C1 ╨▓ ┬з1 ╨╕ ╨╝╨╡╤В╨░╨┤╨╛╨║ ╨╛╨▒╨╜╨╛╨▓╨╗╨╡╨╜╤Л. Executor: Opus 5 (`claude-opus-5[1m]`) тАФ ╨╗╨╛╨║ ╤Е╨╡╨╜╨┤╨╛╤Д╤Д╨░ ╨╜╨░ Sonnet 5 ╨╜╨╡ ╤Б╨╛╨▒╨╗╤О╨┤╤С╨╜, ╨╖╨░╨┐╤Г╤Б╨║ ╤З╨╡╨╗╨╛╨▓╨╡╨║╨╛╨╝ ╨╜╨░╨┐╤А╤П╨╝╤Г╤О.
- **VK/ORS content calendar тАФ Wave 5: auto-pilot (H1568, PLAN closer).** `content:publish-due` (hourly ticker, `app/Console/Kernel.php`) posts every `scheduled` `ContentCalendarSlot` whose `publish_at` is due to a new n8n webhook (`CalendarPublishService`) тАФ same webhook-forward shape as `PublishSocialPostJob`/`PostMonthlySchedule`, VK-only text `wall.post` per D10 (no TG mirror). n8n workflow JSON: [`docs/n8n/vk-calendar-post.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/vk-calendar-post.workflow.json). Success marks the slot `published` and mirrors any linked `ContentCandidate`; a non-2xx response leaves the slot `scheduled` for retry on the next hourly tick тАФ no silent drop. Cancel-window enforcement (D15, `ContentCalendarSlot::canCancel()`) was already wired from W1 into the Filament bulk action; this wave adds the first direct unit coverage of its 24h boundary. Flag-gated behind `content_calendar_autopilot` (`CONTENT_CALENDAR_AUTOPILOT`, default OFF) тАФ command no-ops while off; `N8N_CALENDAR_POST_WEBHOOK`/`_SECRET` unset тЖТ warn + no-op. No new migration. Tests: `PublishDueContentCommandTest` (5: E1 flag-off no-op, webhook-unset no-op, due-only publish + candidate mirror, failed-response keeps scheduled, never touches `api.vk.com`) + `ContentCalendarSlotCancelTest` (7, `canCancel()` boundary). DEPLOY_QUEUE тДЦ60. Executor: Sonnet 5 (`claude-sonnet-5`).
- **H1644 pedagogy hop smoke (Grok 4.5 grok-4.5, 25-07-2026).** Artisan pedagogy:sync-sg-export copies SanskritGrammar data/pedagogy_export (schema major >=1, sha256-checked) into
esources/data/rq4_item_bank.json. Does **not** flip 
eatures.rq4_study. Smoke: schema_version=1.0.0, items=24, first_item=yat, flag OFF. Tests: SyncPedagogyExportFromSgTest (2) + php artisan test --filter=Rq4 (11) green.
- **GetCourse-parity GC-B1 rescope тАФ ╨╛╨┤╨╜╨░ recurring Zoom-╨▓╤Б╤В╤А╨╡╤З╨░ ╨Э╨Р ╨Ъ╨г╨а╨б (H1642).** ╨а╨░╨╖╨▓╨╕╨╗╨║╨░ F1 (per-schedule ╨░╨▓╤В╨╛-╤Б╨╛╨╖╨┤╨░╨╜╨╕╨╡ vs. ╨╡╨┤╨╕╨╜╨░╤П-╤Б╤Б╤Л╨╗╨║╨░ ╨╝╨╛╨┤╨╡╨╗╤М) ╨▒╤Л╨╗╨░ **╨╖╨░╨║╤А╤Л╤В╨░ MG 19-07-2026** ╨╜╨░ ╨╜╨╡╨┤╨╡╨╗╤М╨╜╨╛╨╝ `@DECIDE`-╨╗╨╕╤Б╤В╨╡ тЖТ ╨╛╨┐╤Ж╨╕╤П (b): ╤А╨╡╤Б╨║╨╛╤Г╨┐ ╨╜╨░ ┬л╨░╨▓╤В╨╛-╤Б╨╛╨╖╨┤╨░╨╜╨╕╨╡ ╨Ю╨Ф╨Э╨Ю╨Щ recurring-╨▓╤Б╤В╤А╨╡╤З╨╕ ╨╜╨░ ╨║╤Г╤А╤Б┬╗, ╨╡╨┤╨╕╨╜╨░╤П-╤Б╤Б╤Л╨╗╨║╨░ ╨╝╨╛╨┤╨╡╨╗╤М (`eda8059`, 27-06-2026) ╤Б╤В╨╛╨╕╤В ╨╜╨╡╤В╤А╨╛╨╜╤Г╤В╨╛╨╣, per-schedule ╨░╨▓╤В╨╛-╤Б╨╛╨╖╨┤╨░╨╜╨╕╨╡ ╨Э╨Х ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В╤Б╤П. ╨в╤А╨╕╨│╨│╨╡╤А тАФ ╨┐╨╡╤А╨▓╨░╤П ╨│╨╡╨╜╨╡╤А╨░╤Ж╨╕╤П ╨┐╨╛╤В╨╛╨║╨░ ╨╖╨░╨╜╤П╤В╨╕╨╣ ╨║╤Г╤А╤Б╨░ (`ScheduleGenerator::generate()`): ╨╡╤Б╨╗╨╕ ╤Г ╨║╤Г╤А╤Б╨░ ╨╡╤Й╤С ╨╜╨╡╤В `zoom_meeting_id` ╨╕ ╤П╨▓╨╜╨╛╨╣ ╤Б╤Б╤Л╨╗╨║╨╕ ╨▓ ╤Д╨╛╤А╨╝╨╡ ╨╜╨╡ ╨╖╨░╨┤╨░╨╜╨╛, ╨▓╤Л╨╖╤Л╨▓╨░╨╡╤В╤Б╤П `ZoomService::createMeeting()` (╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ ╨▓╤Л╨╖╨╛╨▓ Zoom API, `type=8` тАФ recurring ╨▒╨╡╨╖ ╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛╨│╨╛ ╨▓╤А╨╡╨╝╨╡╨╜╨╕) ╨╕ ╤А╨╡╨╖╤Г╨╗╤М╤В╨░╤В ╤Б╨╛╤Е╤А╨░╨╜╤П╨╡╤В╤Б╤П ╤З╨╡╤А╨╡╨╖ ╤Г╨╢╨╡ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╣ `Course::setZoomLinkAttribute()` (╨┐╨░╤А╤Б╨╡╤А meeting_id ╨╕╨╖ `join_url` ╨┐╨╡╤А╨╡╨╕╤Б╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╨╜, ╨╜╨╡ ╨╖╨░╨┤╤Г╨▒╨╗╨╕╤А╨╛╨▓╨░╨╜). ╨Ш╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╜╨╛ тАФ ╨╝╨░╤А╨║╨╡╤А ╤Г╨╢╨╡-╤Б╨╛╨╖╨┤╨░╨╜╨╜╨╛╨╣ ╨▓╤Б╤В╤А╨╡╤З╨╕ тАФ `courses.zoom_meeting_id`; ╨┐╨╛╨▓╤В╨╛╤А╨╜╨░╤П ╨│╨╡╨╜╨╡╤А╨░╤Ж╨╕╤П ╨┐╨╛╤В╨╛╨║╨░ ╨┤╨╗╤П ╤В╨╛╨│╨╛ ╨╢╨╡ ╨║╤Г╤А╤Б╨░ Zoom API ╨╜╨╡ ╨┤╤С╤А╨│╨░╨╡╤В. `WebinarProvider`-╤И╨╛╨▓ (GC-B3) ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В тАФ `ZoomService::createMeeting()` ╨╛╤Б╤В╨░╤С╤В╤Б╤П ╨╡╨│╨╛ ╨╡╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╨╛╨╣ ╨╢╨╕╨▓╨╛╨╣ ╤А╨╡╨░╨╗╨╕╨╖╨░╤Ж╨╕╨╡╨╣. ╨Т╤Б╤С ╨╖╨░ ╤Д╨╗╨░╨│╨╛╨╝ `zoom_auto_create` (default `false`) тАФ ╨┐╨╛╨║╨░ OFF, `ScheduleGenerator` ╨▓╨╡╨┤╤С╤В ╤Б╨╡╨▒╤П ╨▒╨░╨╣╤В-╨▓-╨▒╨░╨╣╤В ╨║╨░╨║ ╤А╨░╨╜╤М╤И╨╡. **╨а╨╡╤Б╨║╨╛╤Г╨┐-╨┐╤А╨╡╨╡╨╝╨╜╨╕╨║ ╤В╨╡╤Б╤В╨░-╨╖╨░╨╝╨║╨░:** `WebinarProviderSeamTest::test_zoom_create_meeting_stays_removed_per_gc_b1` ╨╖╨░╨╝╨╡╨╜╤С╨╜ ╨╜╨░ `test_create_meeting_requires_configured_credentials` (╤В╨╛╤В ╨╢╨╡ ╨│╨▓╨░╤А╨┤ ╨║╤А╨╡╨┤╨╛╨▓, ╨▒╨╡╨╖ ╤Б╨╡╤В╨╕ тАФ ╤А╨╡╨░╨╗╤М╨╜╤Л╨╣ ╨▓╤Л╨╖╨╛╨▓ Zoom API ╤В╨╡╨┐╨╡╤А╤М ╤В╨╡╤Б╤В╨╕╤А╤Г╨╡╤В╤Б╤П ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛, `Http::fake` ╤В╤А╨╡╨▒╤Г╨╡╤В ╨║╨╛╨╜╤В╨╡╨╣╨╜╨╡╤А Laravel). ╨в╨╡╤Б╤В╤Л: `ZoomAutoCreateTest` (5 тАФ ╤Д╨╗╨░╨│ ╨▓╤Л╨║╨╗/╨▓╨║╨╗/╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╜╨╛╤Б╤В╤М/╤А╤Г╤З╨╜╨╛╨╣ ╨┐╤Г╤В╤М Filament ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В/per-schedule-guard тАФ ╨┐╤А╤П╨╝╨╛╨╡ ╤Б╨╛╨╖╨┤╨░╨╜╨╕╨╡ `Schedule` ╨▓ ╨╛╨▒╤Е╨╛╨┤ ╨│╨╡╨╜╨╡╤А╨░╤В╨╛╤А╨░ Zoom API ╨╜╨╡ ╨┤╤С╤А╨│╨░╨╡╤В). ╨Я╨╛╨╗╨╜╤Л╨╣ Feature-╨╜╨░╨▒╨╛╤А ╨╖╨╡╨╗╤С╨╜╤Л╨╣, Pint ╤З╨╕╤Б╤В. Executor: Sonnet 5 (`claude-sonnet-5`).
- **GetCourse-parity GC-C1 тАФ ╤Б╨┤╨╡╨╗╨║╨╕ (`Deal`) + ╨║╨░╨╜╨▒╨░╨╜ + ╨╝╨╛╤Б╤В ╨╛╤В ╨╛╨┐╨╗╨░╤В╤Л (H1641, Wave 2 head).** ╨а╨░╨╖╨▓╨╕╨╗╨║╨░ F2 (╨┤╨▓╨╡ ╨┐╤А╨╛╤В╨╕╨▓╨╛╤А╨╡╤З╨░╤Й╨╕╤Е ╨╖╨░╨┐╨╕╤Б╨╕ ╤А╨╡╤И╨╡╨╜╨╕╨╣: ┬л╤А╨░╤Б╤И╨╕╤А╨╕╤В╤М `Lead`┬╗ vs ┬л╨╛╤В╨┤╨╡╨╗╤М╨╜╨░╤П ╤Б╤Г╤Й╨╜╨╛╤Б╤В╤М `Deal`┬╗) ╨▒╤Л╨╗╨░ **╨╖╨░╨║╤А╤Л╤В╨░ MG ╨╡╤Й╤С 21-07-2026** ╨╜╨░ ╨╜╨╡╨┤╨╡╨╗╤М╨╜╨╛╨╝ `@DECIDE`-╨╗╨╕╤Б╤В╨╡ ╨▓ ╨┐╨╛╨╗╤М╨╖╤Г ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛╨╣ ╤Б╤Г╤Й╨╜╨╛╤Б╤В╨╕, ╨╜╨╛ ╨╜╨╡╨┤╨╡╨╗╤О ╨╜╨╡ ╨┤╨╛╨╡╨╖╨╢╨░╨╗╨░ ╨┤╨╛ ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╨╛╨▓ тАФ `DECISIONS_roadmap_forks_2026H2.md` ┬зR2 ╤В╨╡╨┐╨╡╤А╤М ╨┐╨╛╨╝╨╡╤З╨╡╨╜ superseded. ╨Р╨┤╨┤╨╕╤В╨╕╨▓╨╜╨╛: ╨╝╨╕╨│╤А╨░╤Ж╨╕╨╕ `deal_stages` (5 ╨╖╨░╤Б╨╡╤П╨╜╨╜╤Л╤Е ╤Б╤В╨░╨┤╨╕╨╣, ╤А╨╛╨▓╨╜╨╛ ╨╛╨┤╨╜╨░ `is_won`) / `deals` / `deal_transitions` (append-only, ╨б╨Ю╨Ч╨Э╨Р╨в╨Х╨Ы╨м╨Э╨Ю ╨▒╨╡╨╖ FK тАФ ╨┐╨╡╤А╨╡╨╢╨╕╨▓╨░╨╡╤В ╤Г╨┤╨░╨╗╨╡╨╜╨╕╨╡ ╤Б╨┤╨╡╨╗╨║╨╕, ╨┐╤А╨╕╤С╨╝ `lead_audits`), ╨╝╨╛╨┤╨╡╨╗╨╕ `Deal`/`DealStage`/`DealTransition` ╤Б ╨│╨░╤А╨┤╨╛╨╝ ╨╛╤В╨║╨░╤В╨░ ╤Д╨╕╨╜╨░╨╗╤М╨╜╨╛╨╣ ╤Б╤В╨░╨┤╨╕╨╕ (╨╖╨╡╤А╨║╨░╨╗╨╛ `Lead::blocksRollbackToFirstStage`), ╨┤╨╛╤Б╨║╨░ `DealKanbanBoard` (╤Д╨╛╤А╨╝╨░ ╤Б╨║╨╛╨┐╨╕╤А╨╛╨▓╨░╨╜╨░ ╤Б `LeadKanbanBoard`, `$statusEnum` ╨╜╨░╨╝╨╡╤А╨╡╨╜╨╜╨╛ ╨╛╨┐╤Г╤Й╨╡╨╜ тАФ `stage_id` ╨╜╨╡ enum-╨║╨░╤Б╤В), ╨╕ `PaymentDealBridgeObserver` тАФ ╨Ю╨в╨Ф╨Х╨Ы╨м╨Э╨л╨Щ ╨╛╨▒╤Б╨╡╤А╨▓╨╡╤А ╨┐╨╛ ╨┐╤А╨╡╤Ж╨╡╨┤╨╡╨╜╤В╤Г `PaymentAuditObserver`/`PaymentTelemetryObserver`, ╨┐╤А╨╡╨┤╨╕╨║╨░╤В `wasChanged('status')` (╤А╨░╨╖╨▓╨╕╨╗╨║╨░ F4). ╨Ь╨╛╤Б╤В ╨┐╨╛╨▓╤В╨╛╤А╤П╨╡╤В ╨╜╨░╨▒╨╛╤А ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╨╣ `Payment::fireOnPaid` (╤А╨░╤Б╤Е╨╛╨┤/╨Ч╨Я/╨┤╨╡╨┐╨╛╨╖╨╕╤В/╨┐╤А╨╛╨▒╨╜╨╛╨╡/╨╝╨░╤А╨░╤Д╨╛╨╜/`is_conditional`), ╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╡╨╜ ╨┐╨╛ `source_payment_id`, ╨░ ╨╜╨░ ╤А╨╡╨▓╨╡╤А╤Б╨╡ ╨┐╨╗╨░╤В╨╡╨╢╨░ ╤Б╨╜╨╛╨▓╨░ ╨Ю╨в╨Ъ╨а╨л╨Т╨Р╨Х╨в ╤Б╨┤╨╡╨╗╨║╤Г (╤А╨░╨╜╨│ 1 ╨┐╤А╨░╨▓, ╤Б╨┤╨╡╨╗╨║╨░ ╨▒╤Л╨╗╨░ ╤Г╤Б╤В╨░╤А╨╡╨▓╤И╨╡╨╣). ╨Т╤Б╤С ╨╖╨░ ╤Д╨╗╨░╨│╨╛╨╝ `crm_pipeline_board` (default `false`) тАФ ╨┐╨╛╨║╨░ OFF, ╨┤╨╛╤Б╨║╨░ ╨╜╨╡╨┤╨╛╤Б╤В╤Г╨┐╨╜╨░ ╨╕ ╨▓ `deals` ╨╜╨╡ ╨┐╨╕╤И╨╡╤В╤Б╤П ╨╜╨╕ ╤Б╤В╤А╨╛╨║╨╕. **`LeadKanbanBoard`/`LeadStage` (H451) ╨Э╨Х ╤В╤А╨╛╨╜╤Г╤В╤Л** тАФ ╨╕╤Е ╤Б╤Г╨┤╤М╨▒╨░ ╨▓╤Л╨╜╨╡╤Б╨╡╨╜╨░ ╨╜╨╛╨▓╨╛╨╣ ╤А╨░╨╖╨▓╨╕╨╗╨║╨╛╨╣ F9 ╤Б╨┐╨╡╨║╨╕. ╨в╨╡╤Б╤В╤Л: `DealTest` (25) + `DealFlagDefaultTest` (3, ╨┐╨╕╨╜╨╜╨╕╤В ╨┤╨╡╤Д╨╛╨╗╤В ╤Д╨╗╨░╨│╨░ тАФ ╨┤╤Л╤А╨░ ┬з6 ╨╖╨░╨║╤А╤Л╤В╨░), ╨▓╨║╨╗╤О╤З╨░╤П guard ╨┤╨╡╨╜╨╡╨╢╨╜╨╛╨╣ ╨│╤А╨░╨╜╨╕╤Ж╤Л: ╨╝╨╛╤Б╤В ╨╜╨╡ ╨┐╨╕╤И╨╡╤В ╨Э╨Ш ╨Т ╨Ю╨Ф╨Э╨г ╤В╨░╨▒╨╗╨╕╤Ж╤Г ╨║╤А╨╛╨╝╨╡ `deals`/`deal_transitions` ╨╕ ╨╜╨╡ ╨║╨╛╨╜╨▓╨╡╤А╤В╨╕╤А╤Г╨╡╤В ╨╗╨╕╨┤ ╨╛╨▒╤Л╤З╨╜╨╛╨╣ ╨┐╨╛╨║╤Г╨┐╨║╨╕ (┬з2.4). **╨Я╨╛ ╨╕╤В╨╛╨│╨░╨╝ ╨╛╨▒╤П╨╖╨░╤В╨╡╨╗╤М╨╜╨╛╨│╨╛ adversarial-╤А╨╡╨▓╤М╤О (╤Б╨▓╨╡╨╢╨╕╨╣ ╨║╨╛╨╜╤В╨╡╨║╤Б╤В, Opus 5 `claude-opus-5`) ╨╕╤Б╨┐╤А╨░╨▓╨╗╨╡╨╜╨╛ ╨Ф╨Ю ╨╝╨╡╤А╨┤╨╢╨░, ╨║╨░╨╢╨┤╨░╤П ╨┐╤А╨░╨▓╨║╨░ ╨╖╨░╨║╤А╤Л╤В╨░ ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╨╛╨╜╨╜╤Л╨╝ ╤В╨╡╤Б╤В╨╛╨╝:** (1) ╨▓╨╡╤В╨║╨░ ╤Б╨╛╨┐╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╨╕╤П ╨┐╨╛ ╨╗╨╕╨┤╤Г ╨╕╨│╨╜╨╛╤А╨╕╤А╨╛╨▓╨░╨╗╨░ ╨║╤Г╤А╤Б тАФ ╨╛╨┐╨╗╨░╤В╨░ ╨║╤Г╤А╤Б╨░ B ╨╖╨░╨║╤А╤Л╨▓╨░╨╗╨░ ╤Б╨┤╨╡╨╗╨║╤Г ╨┐╨╛ ╨║╤Г╤А╤Б╤Г A, ╤В.╨╡. ╨╖╨░╨┐╨╕╤Б╤М ╨╜╨╡ ╨▓ ╤В╤Г ╤Б╤В╤А╨╛╨║╤Г; ╨║╤Г╤А╤Б ╤В╨╡╨┐╨╡╤А╤М ╤А╨░╨╖╨╗╨╕╤З╨░╤О╤Й╨╕╨╣ ╨┐╤А╨╕╨╖╨╜╨░╨║ ╨╕ ╨┐╨╛ ╨╗╨╕╨┤╤Г ╤В╨╛╨╢╨╡; (2) ╤А╨░╤Б╤Б╤А╨╛╤З╨║╨░ ╨╖╨░╨▓╨╛╨┤╨╕╨╗╨░ ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Г╤О ╨▓╤Л╨╕╨│╤А╨░╨╜╨╜╤Г╤О ╤Б╨┤╨╡╨╗╨║╤Г ╨╜╨░ ╨║╨░╨╢╨┤╤Л╨╣ ╨▓╨╖╨╜╨╛╤Б ╨╕ ╤А╨░╨╖╨┤╤Г╨▓╨░╨╗╨░ ╨▓╨╛╤А╨╛╨╜╨║╤Г тАФ ╨▓╤В╨╛╤А╨╛╨╣ ╨┐╨╗╨░╤В╤С╨╢ ╨┐╨╛ ╤В╨╛╨╝╤Г ╨╢╨╡ ╤З╨╡╨╗╨╛╨▓╨╡╨║╤Г ╨╕ ╨║╤Г╤А╤Б╤Г ╤Б╨┤╨╡╨╗╨║╤Г ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨┐╨╗╨╛╨┤╨╕╤В (╤Ж╨╡╨╜╨░: ╨┐╨╛╨▓╤В╨╛╤А╨╜╨░╤П ╨┐╨╛╨║╤Г╨┐╨║╨░ ╤В╨╛╨│╨╛ ╨╢╨╡ ╨║╤Г╤А╤Б╨░ ╤В╨╛╨╢╨╡ ╨╜╨╡ ╨╖╨░╨▓╨╡╨┤╤С╤В ╨▓╤В╨╛╤А╤Г╤О тАФ ╤А╨░╨╖╨╝╨╡╨╜ ╨▓╤Л╨╜╨╡╤Б╨╡╨╜ ╤З╨╡╨╗╨╛╨▓╨╡╨║╤Г); (3) ╨╜╨╡╨╛╨▒╤А╨░╨▒╨╛╤В╨░╨╜╨╜╨╛╨╡ ╨╕╤Б╨║╨╗╤О╤З╨╡╨╜╨╕╨╡ ╨╝╨╛╤Б╤В╨░ ╨▓╨╜╤Г╤В╤А╨╕ ╤В╤А╨░╨╜╨╖╨░╨║╤Ж╨╕╨╕ ╨▓╨╡╨▒╤Е╤Г╨║╨░ ╨в╨╛╤З╨║╨╕ ╨╛╤В╨║╨░╤В╨╕╨╗╨╛ ╨▒╤Л ╨Я╨Ю╨Ф╨в╨Т╨Х╨а╨Ц╨Ф╨Б╨Э╨Э╨л╨Щ ╨С╨Р╨Э╨Ъ╨Ю╨Ь ╨┐╨╗╨░╤В╤С╨╢ (╤А╨░╨╜╨│ 4 ╨╜╨╡ ╨╕╨╝╨╡╨╡╤В ╨┐╤А╨░╨▓╨░ ╨▓╨╡╤В╨╛ ╨╜╨░╨┤ ╤А╨░╨╜╨│╨╛╨╝ 1) тАФ `sync()` ╤Ж╨╡╨╗╨╕╨║╨╛╨╝ ╨▓ try/catch ╤Б ╨╗╨╛╨│╨╛╨╝; (4) ╤А╨╡╨▓╨╡╤А╤Б ╨┐╨╡╤А╨╡╤В╨╕╤А╨░╨╗ ╤А╨╡╤И╨╡╨╜╨╕╨╡ ╤З╨╡╨╗╨╛╨▓╨╡╨║╨░, ╨▓╨╛╤Б╨║╤А╨╡╤И╨░╤П ╤Б╨┤╨╡╨╗╨║╤Г, ╤Г╨▓╨╡╨┤╤С╨╜╨╜╤Г╤О ╤А╤Г╨║╨░╨╝╨╕ ╨▓ ┬л╨Я╤А╨╛╨╕╨│╤А╨░╨╜╨░┬╗; (5) `UNIQUE` ╨╜╨░ `source_payment_id` ╨┐╤А╨╛╤В╨╕╨▓ ╨│╨╛╨╜╨║╨╕ check-then-insert ╨▓╨╜╨╡ ╨▓╨╡╨▒╤Е╤Г╨║╨░; (6) ╨┤╨▓╤Г╤Е╤И╨░╨│╨╛╨▓╨╛╨╡ ╨╖╨░╨║╤А╤Л╤В╨╕╨╡ ╨╛╨▒╤С╤А╨╜╤Г╤В╨╛ ╨▓ ╤В╤А╨░╨╜╨╖╨░╨║╤Ж╨╕╤О; (7) `DealStage::first()` тЖТ `firstStage()` тАФ ╨┐╨╡╤А╨╡╨║╤А╤Л╨▓╨░╨╗ ╤Б╤В╨░╤В╨╕╤З╨╡╤Б╨║╨╕╨╣ ╤Д╨╛╤А╨▓╨░╤А╨┤╨╕╨╜╨│ Eloquent. ╨Я╨╛╨╗╨╜╤Л╨╣ Feature-╨╜╨░╨▒╨╛╤А **1910 ╨╖╨╡╨╗╤С╨╜╤Л╨╣**.
- **Upgrade-credit refund-link attribution тАФ flag-gated, default OFF (H1405 C2, PR #695).** `Tariff::upgradeRefundsForUser` block branch additionally nets ┬л╨а╨░╤Б╤Е╨╛╨┤┬╗ rows linked via `refund_of_payment_id` to a paid half of the purchased block when `features.upgrade_credit_refund_link` is ON (`UPGRADE_CREDIT_REFUND_LINK`, default OFF тАФ flag-OFF parity test pins today's behavior). Closes the over-credit where a form-created refund (start/end auto-nulled by PaymentResource) stayed invisible to the netting. Tests: `UpgradeCreditRefundLinkTest` (6). Executor: Fable 5 (`claude-fable-5`).
- **VK/ORS content calendar тАФ Wave 4: forward drafts (H1567).** `ForwardDraftGenerator` fills empty `forward`-type `ContentCalendarSlot` rows with NEW copy, rotating four template kinds тАФ reading-group tease, dictionary tip (grounded in a real `DictionaryWord`, falls back to a generic template when none exists), event promo (grounded in the next upcoming `Schedule`+`Course`, falls back generically), FAQ-style micro-answer (grounded in the "╨Э╨╛╨▓╨╕╤З╨║╨░╨╝" section of `resources/knowledge/faq.md` тАФ deliberately outside the money/policy FAQ sections). CuratorAi polishes the deterministic base when an OpenRouter key is set (`Http::fake` in tests); the base itself covers the no-key/test path. Per the skip-review default (D12: forward is NEW copy), a filled slot only ever moves `empty` тЖТ `draft`, never `scheduled` тАФ only a human monthly Keep in Filament schedules it. Cost cap: `content.forward_draft_max_per_run` (default 10, `CONTENT_FORWARD_DRAFT_MAX_PER_RUN`), mirrors `ArticleDraftGenerator::WEEKLY_LESSON_LIMIT`. Artisan `content:fill-forward {YYYY-MM} [--limit=] [--force-flag]`. No new migration; flag-gated behind existing `content_calendar` (OFF); no live VK. Tests: `ForwardDraftFillTest` (11 cases). DEPLOY_QUEUE тДЦ57. Executor: Sonnet 5 (`claude-sonnet-5`).
- **VK/ORS content calendar тАФ Wave 3: Systema bridge (H1566).** `SystemaCalendarBridge::bridgeClips()` fills empty `clip_tease`-type `ContentCalendarSlot` rows for `YYYY-MM` with free (`is_free=true`) `LectureClip`s already mirrored into an accepted `ContentCandidate` (H1547 sync) тАФ one clip per slot, deduped against clips already used in another slot, VK permalink in `meta.link` when `vk_owner_id`/`vk_video_id` are already set by n8n. `bridgeScheduleDigest()` creates one `event`-type digest slot for the **current** month (idempotent by `source_kind=schedule_digest`+`source_ref=YYYY-MM`; no-ops for a non-current target month) тАФ **prior-art reuse**: sources courses/schedule/teacher/URL straight from the existing `MonthlyScheduleDigest` service (the live `schedule:post-monthly` poster) instead of re-querying `Schedule`, so the calendar digest can never disagree with the live one about which courses are running (same `is_active`/`is_visible`/course-block-intersects-month filter тАФ a from-scratch `Schedule`-only query was drafted first, then replaced after finding `MonthlyScheduleDigest` mid-build; it would have leaked hidden/inactive courses that still had stray `Schedule` rows). Both bridges go straight to `scheduled` (skip-review default D12: Systema-sourced content is not NEW copy), same pattern as W2's evergreen fill. Only calendar rows pointing at existing artifacts тАФ **no ffmpeg/VK upload from the bridge** (C2; that stays n8n per H1452). Artisan `content:bridge-systema {YYYY-MM}`. Default log (D22): the roadmap's per-class `schedule_note` (change-tracking) and `faq_tease` (FAQ Accept) sources are deferred тАФ neither is required by W3's DoD (C1/C2); `Schedule` carries no diff primitive yet. Tests: `SystemaBridgeTest` (13 cases: clip fill + link, non-free/already-used dedupe, digest reuse/hidden-course-exclusion/idempotency/non-current-month no-op, `Http::assertNothingSent()`, flag-off no-op). DEPLOY_QUEUE тДЦ58. Executor: Sonnet 5 (`claude-sonnet-5`).
- **GetCourse-parity GC-A1 segment engine (H1637, Wave 4 head).** `segments` migration (name/description/typed `criteria` JSON/`is_builtin`/`created_by`) + `Segment` model + Filament `SegmentResource`, all behind `marketing_segments` (default `false`). Three built-in segments (`SegmentSeeder`) wrap `ReactivationReport`/`DebtorsReport`/`StuckStudentsReport` query-for-query, no reinterpretation. Custom segments store an AND-combined `criteria` array (group membership, last-activity, completed-lesson, tariff-ownership, attendance, lead-status-by-email, debtor, UTM), all read-only against ranks 1-5 (money/access/lead/stage) per docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md ┬з2.2. Tests: `SegmentTest` (14, 33 assertions), including a boundary-rule guard modeled on `test_zoom_create_meeting_stays_removed_per_gc_b1` that fails if evaluation ever issues a non-SELECT statement or changes a rank 1-5 row count.
- **Money/access-core deep systems manual (H1405, Wave 2 of the org deep-manuals programme).** [docs/money-access-core-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-access-core-manual.md) + metadoc with staleness block: access-key algebra (accessKey/unlockingKeys/isUnlockedBy, verified live), containment upgrade-credit, payment lifecycle, H1359 webhook ledger + three guards, discount/prana/deposit/loyalty stacking order, receivables-vs-conversion loops, money config-gate map, [RU] findir mechanism chapter + [RU] failure/recovery runbook. Claim-verify: C1 amount-field documented-limitation (@DECIDE spike), C2 refund-netting defect CONFIRMED (fix ships separately, flag-gated), C3 audit-trail blind spot documented (schema fix queued per D16). All spot-run commands recorded in the metadoc. Executor: Fable 5 (claude-fable-5).
- **VK/ORS content calendar тАФ Wave 2: evergreen recycle (H1565).** `EvergreenScorer` ranks `top_posts_by_likes.csv` rows (likes DESC) filtered by age тЙе12 months, topic тИИ {╨║╨╜╨╕╨│╨░, ╤Б╨╗╨╛╨▓╨░╤А╤М, pdf, ╤В╨╡╨║╤Б╤В} (keyword patterns ported from `IndologyScholars/vk-ors/vk_ors_archive/insights.py` TOPICS), promo exclusion, and de-dupe against any `source_ref` already used within ┬▒6 months of the target month. Artisan `content:fill-evergreen {YYYY-MM}` fills draft `evergreen`-type `ContentCalendarSlot` rows with the verbatim excerpt (D17), sets `source_kind`/`source_ref`/permalink+likes+topic meta, and тАФ per the skip-review default (D12: evergreen is not NEW copy) тАФ calls `markKept()` to go straight to `scheduled` with `publish_at` = the slot's existing seeded date at noon Europe/Moscow. Mirrors the fill onto the slot's linked `ContentCandidate` (type `vk_post`, status `scheduled`). No new migration (reuses W1's `content_calendar_slots` schema). Flag-gated behind the existing `content_calendar` (still OFF); no live VK. Tests: `EvergreenFillTest` (score filters, de-dupe across months, idempotency, flag-off no-op). Executor: Sonnet 5 (`claude-sonnet-5`).
- **n8n lecture content engine тАФ Wave 5 student study artifacts (H1551).** StudyArtifactGenerator builds type=study_artifact drafts (kinds: summary / card_seeds / homework) from transcript spans; body hard-capped (MAX_BODY_CHARS + ratio vs source); QuotePolicy on summary quote; channel staff_study; **never** selected by PublishSocialPostJob / SendContentOneShotMailJob (type guards + observer no-op on Accept). Artisan content:compose-study-artifacts; LessonObserver drafts when CONTENT_FROM_LECTURES=true. Filament ┬л╨Ъ╨╛╨╜╤В╨╡╨╜╤В-╨║╨░╨╜╨┤╨╕╨┤╨░╤В╤Л┬╗ staff review (filter already had study type). DEPLOY_QUEUE **тДЦ55**. Tests: StudyArtifactGeneratorTest (E1/E2). Executor: Grok 4.5 (grok-4.5) Sonnet-lock override.
- **n8n lecture content engine тАФ Wave 4 long-form articles (H1550).** `ArticleDraftGenerator` builds type=`article` drafts (per-lesson outline + optional `--weekly` pack) from transcript spans via ClipSpanPlanner; body hard-capped (`MAX_BODY_CHARS` + ratio vs source); `QuotePolicy` on quote; never a full transcript dump. Artisan `content:compose-article-drafts`; LessonObserver drafts when `CONTENT_FROM_LECTURES=true`. Draft-only Filament (no auto-publish). DEPLOY_QUEUE **тДЦ54**. Tests: `ArticleDraftGeneratorTest` (D1/D2). Executor: Grok 4.5 (`grok-4.5`) Sonnet-lock override.
- **n8n lecture content engine тАФ Wave 3 FAQ from lectures (H1549).** `FaqDraftGenerator` mines transcript interrogatives (fallback: ClipSpanPlanner span titles) into `ContentCandidate` type=`faq_draft` (no SupportTopic/CAI3 input, D3). Filament Accept тЖТ `KnowledgeFaqPublisher` appends to `resources/knowledge/faq_from_lectures.md` (sibling of ORS-export `faq.md`) and marks published; `BotKnowledgeBase` loads both. Artisan `content:compose-faq-drafts`; LessonObserver drafts when `CONTENT_FROM_LECTURES=true`. DEPLOY_QUEUE **тДЦ53**. Tests: `FaqDraftGeneratorTest`, `KnowledgeFaqPublisherTest`, chain C2. Executor: Grok 4.5 (`grok-4.5`) Sonnet-lock override.
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15тАУ20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **C3 mobile viewport audit + PWA shell (H1488).** Dated inventory of every student-cabinet route at 320тАУ390 px; fixes header density on <375 px, support-chat short-viewport height, main `overflow-x-hidden`; ships `public/manifest.webmanifest` + `public/sw.js` + `public/offline.html` linked from student layout; Feature smoke `PwaShellAssetsTest`; optional Playwright script `scripts/mobile_viewport_audit.mjs`. Report: [docs/MOBILE_VIEWPORT_CABINET_AUDIT_2026-07-24.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MOBILE_VIEWPORT_CABINET_AUDIT_2026-07-24.md). Executor: Grok 4.5 (`grok-4.5`) override of Sonnet lock.
- **Cabinet hybrid Phase 4 тАФ R20 flag-flip release pack (H1582).** Does **not** enable
  the hybrid in prod. Adds [docs/CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md)
  (GO/NO-GO gates, walkthrough ┬з3, activate/revert, KPI readout),
  `php artisan cabinet:hybrid-readiness`, DEPLOY_QUEUE **тДЦ52**, `.env.example`
  `CABINET_HYBRID=false`. Baseline clock from тДЦ25 (21-07-2026) тЖТ earliest mechanical
  GO ~04-08-2026; human C+D still required. Executor: Grok 4.5 (`grok-4.5`) via xAI.
- **Cabinet hybrid Phase 3 тАФ ╨Я╤А╨╛╨│╤А╨╡╤Б╤Б ladder + lighting + course vehi (H1573, R29.6тАУR29.8).**
  `config/grammar_ladder.php` + `GrammarLadder`: station map on hybrid ┬л╨Я╤А╨╛╨│╤А╨╡╤Б╤Б┬╗
  (╨┐╨╕╤Б╤М╨╝╨╛ тЖТ ╨│╤А╨░╨╝╨╝╨░╤В╨╕╨║╨░ I/II тЖТ ╤В╨╡╨║╤Б╤В╤Л), completion lighting, ladder offer only after
  station complete (┬л╤Б╤В╨░╨╜╤Ж╨╕╤П ╨┐╨╛╨┤╨╛╨╢╨┤╤С╤В┬╗, no timers), suppressed in recovery; course-home
  landmarks from `CourseBlock` dates (orientation only). Telemetry
  `path.station.view` / `path.station.lit.impression`. Tests: `HybridPhase3Test`.
  Executor: Grok 4.5 (`grok-4.5`) via xAI.
- **VK/ORS content calendar Wave 1 (H1564).** ContentCalendarSlot + import/seed artisan commands + Filament ┬л╨Ъ╨░╨╗╨╡╨╜╨┤╨░╤А╤М ╨║╨╛╨╜╤В╨╡╨╜╤В╨░┬╗ behind CONTENT_CALENDAR_ENABLED (OFF). Reuses H1547 ContentCandidate (calendar_slot_id). Fixtures + tests; no live VK. Grok 4.5 (grok-4.5) override of Sonnet lock.
- **SRS Wave 2 authoring UI (H1487).** Filament `SrsDeckResource` +
  `SrsCardResource` (teacher CRUD, course/lesson attach, paste bulk-add,
  "seed from Dictionary" action, CSV import via `SrsCardImporter`) plus
  student private-deck Livewire `SrsDeckEditor` at `/dvaram/koloda/decks`.
  Behind existing `srs.enabled` / `SRS_ENABLED` flag (default off). Feature
  tests in `tests/Feature/Srs/SrsAuthoringTest.php`. Grok 4.5 (`grok-4.5`).
- **Cabinet hybrid Phase 2 тАФ ╨Ч╨░╨┐╨╕╤Б╨╕ shelves + lapse + rail + ownership offer (H1572, R29.3тАУR29.5).**
  Behind `cabinet_hybrid`: `LapseDetector` (debt gap тЖТ first-class lapsed state),
  `RecordingsCatalog` shelves (watching / owned / lapsed / completed), progress rail
  for recording courses without homework, R29.5 ownership offer (suppressed in recovery),
  membership ┬л╤Б╨║╨╛╤А╨╛┬╗ slot on ╨Ч╨░╨┐╨╕╤Б╨╕. Telemetry `library.shelf.view` + `library.rail.jump`.
  Tests: `HybridPhase2Test` (7). Executor: Grok 4.5 (`grok-4.5`) via xAI.
- **Cabinet hybrid Phase 1 chassis + recovery-mode resolver (H1481, R29.0тАУR29.2).**
  Flag cabinet_hybrid / CABINET_HYBRID (OFF by default, R20 deploy gate).
  Job-named student nav (╨б╨╡╨│╨╛╨┤╨╜╤П / ╨Ъ╨░╨╗╨╡╨╜╨┤╨░╤А╤М / ╨Ч╨░╨┐╨╕╤Б╨╕ / ╨Я╤А╨╛╨│╤А╨╡╤Б╤Б / ╨Ю╨┐╨╗╨░╤В╨░ ╨╕ ╨┤╨╛╤Б╤В╤Г╨┐ /
  ╨Я╨╛╨╝╨╛╤Й╤М); hybrid home with ┬л╨б╨╡╨│╨╛╨┤╨╜╤П┬╗ band (continue + nearest live + homework-rework
  only when returned); course workspace hash-addressable tabs; routes /library,
  /progress, /access (404 while flag off). Server-side
  App\Services\Cabinet\RecoveryStateResolver: declined/canceled payment or expired
  promise тЖТ recovery banner, unconditional offer suppression, owned/live access kept;
  bare pending is not recovery (webhook-delay trap). Telemetry cabinet.home.view
  now carries mode: normal|recovery + reason. Feature tests:
  tests/Feature/Cabinet/HybridPhase1Test.php (11 cases). Spec:
  [docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md)
  ┬з6 step 2. **Money-adjacent (offer suppression)** тАФ flag default OFF; enable only
  after review + config:cache. Executor: Grok 4.5 (grok-4.5) via xAI (Opus-lock override).
- **n8n lecture content engine тАФ Wave 2 of 5 (H1548).** `SocialDraftGenerator`
  drafts a `social_post` ContentCandidate for every free clip тАФ quote grounded
  in the actual transcript sentence inside the clip's own span (never
  invented, never the full lecture body), CuratorAi text when an OpenRouter
  key is configured, deterministic template fallback otherwise.
  `PublishSocialPostJob` posts VK wall (with the free clip's video attached)
  + a Telegram mirror in one call, via a new n8n webhook (same
  webhook-forward shape as `clip_extract`/`monthly_schedule`) тАФ QuotePolicy
  hard-fails a too-long quote before it ever posts. `ContentCandidateObserver`
  chains the flow off the existing "accepted" transition: marking a clip
  free auto-drafts the social post; accepting the draft in Filament dispatches
  the publish job (self-gated by `content_auto_publish_pilot`, OFF).
  `EmailBlastComposer` (+ `content:compose-weekly-digest` command) composes a
  weekly `email_blast` digest from clips accepted in the last 7 days;
  `SendContentOneShotMailJob` sends it to the existing `newsletter_subscribed_at`
  segment (H324) once accepted, gated by `content_email_oneshot` (August
  activation, depends on live SMTP per H1449/#504) тАФ no new campaign domain
  model beyond the candidate itself. DEPLOY_QUEUE тДЦ51. Waves 3тАУ5 remain
  queued as Uprava H1549тАУH1551. **Executor:** Sonnet 5 (`claude-sonnet-5`).
- **n8n lecture content engine тАФ Wave 1 of 5 (H1547).** `ContentCandidate`
  model/migration тАФ the unified review/publish unit for everything the
  content engine will generate from lectures (clip now, social/faq/article/
  study in waves 2тАУ5). `SpanRanker` (heuristic top-N, default 5) narrows
  `ClipSpanPlanner`'s output before it reaches n8n. `QuotePolicy` guards
  against a full-transcript leak through any future "public quote" field
  (тЙд2 sentences, hard-fail). `LessonObserver` dispatches
  `DispatchLectureClipExtractionJob` on the publish transition, idempotent
  (skips if `LectureClip` rows already exist), gated by both
  `content_from_lectures` (new) and `clip_marketing` (H1452, unchanged).
  `LectureClipObserver` + `ContentCandidateSync` mirror every `LectureClip`
  into a `ContentCandidate` row regardless of flags (cheap idempotent
  upsert, not a publish action) тАФ staff marking a clip free flips it to
  `accepted`. Thin `ContentCandidateResource` (Filament, admin-only, gated
  by `content_from_lectures`) for review. DEPLOY_QUEUE тДЦ49. Waves 2тАУ5
  tracked as Uprava H1548тАУH1551. **Executor:** Sonnet 5 (`claude-sonnet-5`).
- **n8n lecture content engine plan (H2 2026).** Layered `/ask` plan for turning weekly
  lecture video + transcript + AI timecodes into five sequenced products (clips тЖТ social
  text тЖТ FAQ тЖТ long-form тЖТ student materials) under one `ContentCandidate` backbone,
  reusing H1452 clip plumbing and CuratorAi. Docs:
  [`docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md)
  + ROADMAP / ARCHITECTURE / IMPLEMENTATION / VERIFICATION + metadoc. Cross-linked from
  Content-AI and Anton ops-gaps plans. Execution handoffs H1547тАУH1551 (Uprava).
  Grok 4.5 (`grok-4.5`).
- **Sanskrit-HUB L5 Workstream-A v0 тАФ `/transliterate` + cascade lemmatizer (H1463).**
  Flag `hub_transliterate` / `HUB_TRANSLITERATE` (OFF by default):
  `GET /transliterate` playground (IAST тЖТ Devan─Бgar─л + SLP1 via vendored
  `resources/js/vendor/sanskrit-util.js`, Vite entry `transliterate.js`).
  Internal `App\Services\Nlp\CascadeLemmatizer` (DCS тЖТ vidyut тЖТ Heritage; stage 1
  reads `resources/data/dcs_form2lemma.json` тАФ 341 DCS-attested forms from the
  Nala-1 reading pack slice; stages 2/3 interface-stubbed). No HTTP route for
  the lemmatizer. Tests: `TransliteratePlaygroundTest`, `CascadeLemmatizerTest`.
  **Executor:** Grok 4.5 (`grok-4.5`) via xAI (user-authorized override of the
  Opus 4.8 handoff lock). **Claude/Opus: verify after** тАФ cascade order, key
  normalizer parity with `SanskritGlossary::normalizeKey`, slice provenance,
  and that the playground never uses `iast_to_devanagari`.
- **Lecture clip marketing pipeline, Wave 4 (H1452).** Flag-gated
  (`clip_marketing` / `CLIP_MARKETING_ENABLED`, OFF by default) n8n orchestration:
  `ClipSpanPlanner` reuses existing AI transcript timecodes (no recompute) тЖТ
  `DispatchLectureClipExtractionJob` POSTs spans to n8n тЖТ secret-guarded
  `POST /api/webhooks/lecture-clip-callback` writes `LectureClip` rows (idempotent
  on lesson+span) тЖТ Filament `LectureClipResource` for staff `is_free` (~3 free
  per lecture) + header ┬л╨Э╨░╤А╨╡╨╖╨░╤В╤М ╨╗╨╡╨║╤Ж╨╕╤О┬╗. Importable workflow
  `docs/n8n/lecture-clip-extract.workflow.json` (ffmpeg/VK nodes are operator
  placeholders тАФ no live VK tokens in repo). IMPLEMENTATION:
  `docs/IMPLEMENTATION_SYSTEMA_ANTON_OPS_GAPS_WAVE4.md`. DEPLOY_QUEUE тДЦ47.
  Tests: `tests/Feature/LectureClips/*`, `tests/Unit/Lecture/ClipSpanPlannerTest`.
  No money-code; no real VK posts in CI (`Http::fake`).
- **In-video resume тАФ ┬л╨┐╤А╨╛╨┤╨╛╨╗╨╢╨╕╤В╤М ╤Б HH:MM┬╗ (H1450, Anton ops-gaps W2).** ╨в╤А╨╕
  ╨░╨┤╨┤╨╕╤В╨╕╨▓╨╜╤Л╨╡ ╨║╨╛╨╗╨╛╨╜╨║╨╕ ╨╜╨░ `lesson_views` (`last_position_seconds`,
  `max_position_seconds` тАФ ╨╝╨╛╨╜╨╛╤В╨╛╨╜╨╜╤Л╨╣ ╨┐╤А╨╛╨│╤А╨╡╤Б╤Б-╤Б╨╕╨│╨╜╨░╨╗, ╨╜╨╕╨║╨╛╨│╨┤╨░ ╨╜╨╡ ╤Г╨▒╤Л╨▓╨░╨╡╤В ╨┤╨░╨╢╨╡
  ╨┐╤А╨╕ ╨┐╨╡╤А╨╡╨╝╨╛╤В╨║╨╡ ╨╜╨░╨╖╨░╨┤, `video_duration_seconds`) ╨┐╨╕╤И╤Г╤В╤Б╤П ╤З╨╡╤А╨╡╨╖ ╤Г╨╢╨╡ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╣
  `POST /api/heartbeat` тАФ ╨╜╨╛╨▓╤Л╨╣ ╤Н╨╜╨┤╨┐╨╛╨╕╨╜╤В ╨╜╨╡ ╨┐╨╛╨╜╨░╨┤╨╛╨▒╨╕╨╗╤Б╤П, ╤В╨╛╨╗╤М╨║╨╛ ╨┤╨▓╨░
  ╨╜╨╡╨╛╨▒╤П╨╖╨░╤В╨╡╨╗╤М╨╜╤Л╤Е ╨┐╨╛╨╗╤П ╨▓ ╤В╨╡╨╗╨╡ ╨╖╨░╨┐╤А╨╛╤Б╨░. Host-agnostic JS-╤Б╨╗╨╛╨╣
  (`public/js/video-resume.js`) ╨┤╨░╤С╤В ╨┐╨╛ ╨╛╨┤╨╜╨╛╨╝╤Г ╨░╨┤╨░╨┐╤В╨╡╤А╤Г ╨╜╨░ YouTube/RuTube/VK/
  Kinescope/Vimeo (D8) тАФ ╤Б╨╡╨│╨╛╨┤╨╜╤П ╨▓ ╨┐╨╗╨╡╨╡╤А╨╡ ╤Г╤А╨╛╨║╨░ ╤А╨╡╨░╨╗╤М╨╜╨╛ ╤А╨╡╨╜╨┤╨╡╤А╤П╤В╤Б╤П ╤В╨╛╨╗╤М╨║╨╛
  YouTube ╨╕ RuTube, ╨╛╤Б╤В╨░╨╗╤М╨╜╤Л╨╡ ╤В╤А╨╕ ╨┤╨╡╨│╤А╨░╨┤╨╕╤А╤Г╤О╤В ╨▓ no-op ╨┤╨╛ ╨┐╨╛╤П╨▓╨╗╨╡╨╜╨╕╤П
  ╤Б╨╛╨╛╤В╨▓╨╡╤В╤Б╤В╨▓╤Г╤О╤Й╨╕╤Е ╨┐╨╗╨╡╨╡╤А╨╛╨▓ (╨╖╨░╨│╨╛╤В╨╛╨▓╨║╨░ ╨┤╨╗╤П W3 Kinescope-╨┐╨╕╨╗╨╛╤В╨░, ╨║╨╛╤В╨╛╤А╤Л╨╣ ╤П╨▓╨╜╨╛
  ╨┐╨╡╤А╨╡╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╤В ╤Н╤В╨╛╤В ╨╢╨╡ Kinescope-╨░╨┤╨░╨┐╤В╨╡╤А). ╨д╨╗╨░╨│ `video_resume` (config/
  features.php) ╨Т╨л╨Ъ╨Ы╨о╨з╨Х╨Э ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О тАФ ╨┐╨╛╨║╨░ OFF, ╨┐╨╗╨╡╨╡╤А ╨╕ heartbeat ╨▓╨╡╨┤╤Г╤В ╤Б╨╡╨▒╤П
  ╨▒╨░╨╣╤В-╨▓-╨▒╨░╨╣╤В ╨║╨░╨║ ╤А╨░╨╜╤М╤И╨╡.
- **Transactional email revival + homegrown campaign engine, Wave 1 (H1449).**
  Closes the first of three genuine Anton-parity gaps (email/resume/clips тАФ
  `docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md`). Part A (reuse, don't rebuild):
  a global `App\Listeners\Email\EnforceMailSendingGuards` on every outgoing
  Mailable тАФ a `SuppressedEmail` list (hard-bounce/unsubscribe) that's checked
  before every send, and a `config('mail.throttle_per_minute')` per-minute
  send throttle (mailbox providers rate-limit/suspend on bulk send, unlike a
  dedicated ESP). `mail:scan-bounces` (D11: scheduled IMAP scan, hourly,
  no-op until `mail.bounce_scan.enabled`/host/creds are set) suppresses hard
  bounces it finds. `docs/mail-esp.md` updated with the D6 ruling: mail.ru/
  Yandex 360 mailbox SMTP is the default transport (already covered by the
  existing generic-SMTP `Option A`), transport-agnostic so it can later be
  overridden to Postmark/Mailgun-as-relay (R1) with zero campaign-engine
  changes. Part B, entirely behind the new `email_campaigns` flag (OFF by
  default тАФ `CampaignResource` hidden, `/e/o`/`/e/c` tracking routes 404,
  `CampaignSender`/`SendCampaignRecipient` early-return): `Campaign` +
  `CampaignRecipient` models (additive migrations, indexed
  `(campaign_id, opened_at)`), `CampaignSegmentResolver` (all-subscribers /
  a course's students / a lead-stage, fail-safe empty on an unrecognised
  filter тАФ never all-users), token-scoped open-pixel + click-redirect
  tracking endpoints (no PII in the URL; the click target is an
  app-encrypted opaque token, not attacker-controlled input тАФ no open
  redirect possible), `CampaignHtmlRenderer` (link/pixel rewriter),
  `CampaignSender::send()`/`resend()` (Anton's "╨┤╨╛╨│╨╛╨╜" тАФ resend to
  `opened_at IS NULL` recipients, linked via `resend_of_id`), and a Filament
  `CampaignResource` modeled on `AnnouncementResource` (compose, pick
  segment, send, open/click stats, "╨Ф╨╛╨│╨╜╨░╤В╤М ╨╜╨╡╨╛╤В╨║╤А╤Л╨▓╤И╨╕╤Е" action). All new
  mail uses `Mail::fake()`-safe/`array`-transport tests тАФ no live sends.
  `changelog.md`/`DEPLOY_QUEUE.md` carry the activation prerequisites
  (mailbox creds, SPF/DKIM/DMARC, migrations, flag flip).

### Changed
- **GC-C1 тАФ ╨╛╨┤╨╜╨░ ╨┐╤А╨╛╨┤╨░╨╢╨░ ╨╛╨┐╤А╨╡╨┤╨╡╨╗╤П╨╡╤В╤Б╤П ╨│╤А╤Г╨┐╨┐╨╛╨╣ ╤А╨░╤Б╤Б╤А╨╛╤З╨║╨╕, ╨░ ╨╜╨╡ ╨┐╨░╤А╨╛╨╣ ┬л╤З╨╡╨╗╨╛╨▓╨╡╨║ + ╨║╤Г╤А╤Б┬╗ (H1659).** ╨а╨░╨╖╨▓╨╕╨╗╨║╨░ F9 (╨▓╤В╨╛╤А╨╛╨╣ ╨▓╨╛╨┐╤А╨╛╤Б) ╨╖╨░╨║╤А╤Л╤В╨░ MG 26-07-2026 ╨▓ ╨┐╨╛╨╗╤М╨╖╤Г ╨╛╨┐╤Ж╨╕╨╕ **(╨▓) тАФ ╤П╨▓╨╜╤Л╨╣ ╨╝╨░╤А╨║╨╡╤А**. ╨н╨▓╤А╨╕╤Б╤В╨╕╨║╨░ H1641 ┬л╨▓╤Л╨╕╨│╤А╨░╨╜╨╜╨░╤П ╤Б╨┤╨╡╨╗╨║╨░ ╨┐╨╛ ╤Н╤В╨╛╨╝╤Г ╤З╨╡╨╗╨╛╨▓╨╡╨║╤Г ╨╕ ╨║╤Г╤А╤Б╤Г ╤Г╨╢╨╡ ╨╡╤Б╤В╤М тЖТ ╨▓╤В╨╛╤А╨╛╨╣ ╨┐╨╗╨░╤В╤С╨╢ ╨╝╨╛╨╗╤З╨╕╤В┬╗ ╨│╨░╤Б╨╕╨╗╨░ ╨╕╨╜╤Д╨╗╤П╤Ж╨╕╤О ╨▓╨╛╤А╨╛╨╜╨║╨╕ ╨╜╨░ ╨▓╨╖╨╜╨╛╤Б╨░╤Е, ╨╜╨╛ ╤В╨╛╨╣ ╨╢╨╡ ╤Ж╨╡╨╜╨╛╨╣ ╨┐╤А╤П╤В╨░╨╗╨░ ╨Э╨Р╨б╨в╨Ю╨п╨й╨г╨о ╨┐╨╛╨▓╤В╨╛╤А╨╜╤Г╤О ╨┐╨╛╨║╤Г╨┐╨║╤Г ╤В╨╛╨│╨╛ ╨╢╨╡ ╨║╤Г╤А╤Б╨░. ╨Ч╨░╨╝╨╡╨╜╨╡╨╜╨░ ╤П╨▓╨╜╨╛╨╣ ╤Ж╨╡╨┐╨╛╤З╨║╨╛╨╣ `Payment` тЖТ `linked_promise_id` тЖТ `PaymentPromise` тЖТ `installment_group_id` (╨┐╨╗╤О╤Б ╨╛╨▒╤А╨░╤В╨╜╨░╤П ╨▓╨╡╤В╨▓╤М `payment_promises.fulfilled_payment_id`, ╨║╨╛╤В╨╛╤А╤Г╤О `PromiseAutoFulfiller` ╨┐╤А╨╛╤Б╤В╨░╨▓╨╗╤П╨╡╤В ╨▓╨╜╤Г╤В╤А╨╕ `fireOnPaid`, ╤В╨╛ ╨╡╤Б╤В╤М ╨Ф╨Ю ╨╛╨▒╤Б╨╡╤А╨▓╨╡╤А╨╛╨▓). ╨Ф╨▓╨░ ╨┐╨╗╨░╤В╨╡╨╢╨░ тАФ ╨╛╨┤╨╜╨░ ╨┐╤А╨╛╨┤╨░╨╢╨░ ╤В╨╛╨│╨┤╨░ ╨╕ ╤В╨╛╨╗╤М╨║╨╛ ╤В╨╛╨│╨┤╨░, ╨║╨╛╨│╨┤╨░ ╨╕╤Е ╨╛╨▒╨╡╤Й╨░╨╜╨╕╤П ╨┤╨╡╨╗╤П╤В ╨╜╨╡╨┐╤Г╤Б╤В╤Г╤О ╨│╤А╤Г╨┐╨┐╤Г; ╨┐╨╗╨░╤В╤С╨╢ ╨▒╨╡╨╖ ╨│╤А╤Г╨┐╨┐╤Л ╤Б╨╜╨╛╨▓╨░ ╨╖╨░╨▓╨╛╨┤╨╕╤В ╤Б╨╛╨▒╤Б╤В╨▓╨╡╨╜╨╜╤Г╤О ╤Б╨┤╨╡╨╗╨║╤Г. ╨Р╨┤╨┤╨╕╤В╨╕╨▓╨╜╨░╤П ╨╝╨╕╨│╤А╨░╤Ж╨╕╤П `deals.installment_group_id` (nullable uuid + index, ╨▒╨╡╨╖ FK тАФ ╤Н╤В╨╛ uuid-╨╝╨╡╤В╨║╨░ ╨╜╨░ `payment_promises`, ╨░ ╨╜╨╡ ╨┐╨╡╤А╨▓╨╕╤З╨╜╤Л╨╣ ╨║╨╗╤О╤З ╤В╨░╨▒╨╗╨╕╤Ж╤Л): ╨│╤А╤Г╨┐╨┐╨░ ╨╝╨░╤В╨╡╤А╨╕╨░╨╗╨╕╨╖╤Г╨╡╤В╤Б╤П ╨╜╨░ ╤Б╨┤╨╡╨╗╨║╨╡ ╨┐╤А╨╕ ╨╡╤С ╤Б╨╛╨╖╨┤╨░╨╜╨╕╨╕/╨╖╨░╨║╤А╤Л╤В╨╕╨╕, ╤З╤В╨╛╨▒╤Л ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╕╨╣ ╨▓╨╖╨╜╨╛╤Б ╨╜╨░╤Е╨╛╨┤╨╕╨╗ ╤Б╨▓╨╛╤О ╨┐╤А╨╛╨┤╨░╨╢╤Г ╨Ю╨Ф╨Э╨Ш╨Ь ╨╕╨╜╨┤╨╡╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╨╝ ╨╖╨░╨┐╤А╨╛╤Б╨╛╨╝, ╨░ ╨╜╨╡ ╨┐╨╡╤А╨╡╨┐╤А╨╛╤Е╨╛╨╢╨┤╨╡╨╜╨╕╨╡╨╝ ╤Ж╨╡╨┐╨╛╤З╨║╨╕ ╨╜╨░ ╨║╨░╨╢╨┤╨╛╨╝ ╨┐╨╗╨░╤В╨╡╨╢╨╡. **╨Я╤А╨╛╨▓╨╡╤А╨║╨░ ╨│╤А╤Г╨┐╨┐╤Л ╨┐╨╛╨┤╨╜╤П╤В╨░ ╨Т╨л╨и╨Х ╨┐╨╛╨╕╤Б╨║╨░ ╨╛╤В╨║╤А╤Л╤В╨╛╨╣ ╤Б╨┤╨╡╨╗╨║╨╕** тАФ ╨▓╨╖╨╜╨╛╤Б ╨┐╨╛ ╤Г╨╢╨╡ ╤Г╤З╤В╤С╨╜╨╜╨╛╨╝╤Г ╨┐╨╗╨░╨╜╤Г ╨╛╨▒╤П╨╖╨░╨╜ ╨▒╤Л╤В╤М ╨╜╨╡╨╝╤Л╨╝ ╤Ж╨╡╨╗╨╕╨║╨╛╨╝, ╨╕╨╜╨░╤З╨╡ ╨╛╨╜ ╨╖╨░╨║╤А╤Л╨╗ ╨▒╤Л ╤Б╨╛╨▒╨╛╨╣ ╤З╤Г╨╢╤Г╤О ╨╛╤В╨║╤А╤Л╤В╤Г╤О ╤Б╨┤╨╡╨╗╨║╤Г ╨┐╨╛ ╤В╨╛╨╝╤Г ╨╢╨╡ ╨║╤Г╤А╤Б╤Г (╤В╨╛╤В ╨╢╨╡ ╨║╨╗╨░╤Б╤Б ┬л╨╖╨░╨┐╨╕╤Б╨╕ ╨╜╨╡ ╨▓ ╤В╤Г ╤Б╤В╤А╨╛╨║╤Г┬╗, ╤З╤В╨╛ ╤А╨╡╨▓╤М╤О H1641 ╤Г╨╢╨╡ ╨╗╨╛╨▓╨╕╨╗╨╛ ╨▓ `findOpenDealFor()`); ╤Б╨▓╨╛╤О ╨╢╨╡ ╤Б╨┤╨╡╨╗╨║╤Г ╨│╤А╤Г╨┐╨┐╨░ ╨╜╨░╤Е╨╛╨┤╨╕╤В ╨╜╨░╨┐╤А╤П╨╝╤Г╤О ╨┐╨╛ `deals.installment_group_id`, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨▓╨╖╨╜╨╛╤Б ╨┐╨╛╤Б╨╗╨╡ ╤А╨╡╨▓╨╡╤А╤Б╨░ ╨┐╨╡╤А╨▓╨╛╨│╨╛ ╨┐╨╗╨░╤В╨╡╨╢╨░ ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В ╨╕╨╝╨╡╨╜╨╜╨╛ ╨┐╨╡╤А╨╡╨╛╤В╨║╤А╤Л╤В╤Г╤О ╤Б╨┤╨╡╨╗╨║╤Г ╨┐╨╗╨░╨╜╨░, ╨░ ╨╜╨╡ ╨╖╨░╨▓╨╛╨┤╨╕╤В ╨▓╤В╨╛╤А╤Г╤О. ╨Ш╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╜╨╛╤Б╤В╤М ╨┐╨╛ `source_payment_id` ╨╜╨╡ ╨╛╤Б╨╗╨░╨▒╨╗╨╡╨╜╨░ тАФ ╤Н╤В╨╛ ╨│╨░╤А╨░╨╜╤В╨╕╤П ╨┐╤А╨╛ ╨Ю╨Ф╨Ш╨Э ╨┐╨╗╨░╤В╤С╨╢, ╨╛╤А╤В╨╛╨│╨╛╨╜╨░╨╗╤М╨╜╨░╤П ╨│╤А╤Г╨┐╨┐╨╕╤А╨╛╨▓╨║╨╡. ╨Ь╨╛╤Б╤В ╨╛╤Б╤В╨░╨╗╤Б╤П ╤З╨╕╤В╨░╤В╨╡╨╗╨╡╨╝ `payments`/`payment_promises`: ╤Б╨┐╨╕╤Б╨╛╨║ ╤В╨░╨▒╨╗╨╕╤Ж ╨▓ ╤В╨╡╤Б╤В╨╡ ╨┤╨╡╨╜╨╡╨╢╨╜╨╛╨╣ ╨│╤А╨░╨╜╨╕╤Ж╤Л ╤А╨░╤Б╤И╨╕╤А╨╡╨╜ `payment_promises`. _╨Я╨╛╨┐╤А╨░╨▓╨║╨░ ╨┐╨╛ ╨╕╤В╨╛╨│╨░╨╝ ╤А╨╡╨▓╤М╤О: ╨▓ ╤В╨╛╨╣ ╤Д╨╕╨║╤Б╤В╤Г╤А╨╡ ╨╛╨▒╨╡╤Й╨░╨╜╨╕╨╣ ╨╜╨╡╤В, ╤В╨░╨║ ╤З╤В╨╛ ╨│╤А╤Г╨┐╨┐╨╛╨▓╤Г╤О ╨▓╨╡╤В╨║╤Г ╤В╨╛╤В ╤В╨╡╤Б╤В ╨╜╨╡ ╨┐╤А╨╛╤Е╨╛╨┤╨╕╨╗ тАФ ╤А╨╡╨░╨╗╤М╨╜╨╛╨╡ ╨┐╨╛╨║╤А╤Л╤В╨╕╨╡ ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜╨╛ ╨╖╨░╨┐╨╕╤Б╤М╤О ╨▓ ┬лFixed┬╗ ╨▓╤Л╤И╨╡._ **╨Ш╨╖╨▓╨╡╤Б╤В╨╜╨╛╨╡ ╨╛╨│╤А╨░╨╜╨╕╤З╨╡╨╜╨╕╨╡, ╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╨╛ ╨╛╤Б╨╛╨╖╨╜╨░╨╜╨╜╨╛:** ╨╝╨╡╨╜╨╡╨┤╨╢╨╡╤А╤Б╨║╨╛╨╡ ┬л╨Я╨╛╨┤╤В╨▓╨╡╤А╨┤╨╕╤В╤М ╨╛╨┐╨╗╨░╤В╤Г┬╗ ([`PromiseFulfillment::fulfil`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/PromiseFulfillment.php)) ╤Б╨╛╨╖╨┤╨░╤С╤В ╨┐╨╗╨░╤В╤С╨╢ ╨╕ ╨╗╨╕╤И╤М ╨Я╨Ю╨в╨Ю╨Ь ╤Б╨▓╤П╨╖╤Л╨▓╨░╨╡╤В ╨╛╨▒╨╡╤Й╨░╨╜╨╕╨╡, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨╜╨░ ╨╝╨╛╨╝╨╡╨╜╤В ╨╛╨▒╤Б╨╡╤А╨▓╨╡╤А╨░ ╨╜╨╕ ╨╛╨┤╨╜╨╛╨╣ ╨╕╨╖ ╨┤╨▓╤Г╤Е ╤Б╨▓╤П╨╖╨╡╨╣ ╨╡╤Й╤С ╨╜╨╡╤В ╨╕ ╤В╨░╨║╨╛╨╣ ╨▓╨╖╨╜╨╛╤Б ╨▓╤Л╨│╨╗╤П╨┤╨╕╤В ╤Б╨░╨╝╨╛╤Б╤В╨╛╤П╤В╨╡╨╗╤М╨╜╨╛╨╣ ╨┐╤А╨╛╨┤╨░╨╢╨╡╨╣. _╨Я╨╛╨┐╤А╨░╨▓╨║╨░ ╨┐╨╛ ╨╕╤В╨╛╨│╨░╨╝ ╤А╨╡╨▓╤М╤О: ╨╛╨│╤А╨░╨╜╨╕╤З╨╡╨╜╨╕╨╡ ╨╛╨║╨░╨╖╨░╨╗╨╛╤Б╤М ╨╜╨╡ ╨║╤А╨░╨╡╨╝, ╨░ ╨╛╤Б╨╜╨╛╨▓╨╜╤Л╨╝ ╤А╤Г╤З╨╜╤Л╨╝ ╤Б╤Ж╨╡╨╜╨░╤А╨╕╨╡╨╝ (3 ╤Б╨┤╨╡╨╗╨║╨╕ ╨▓╨╝╨╡╤Б╤В╨╛ 1) ╨╕ ╨╖╨░╨║╤А╤Л╤В╨╛ ╤В╤А╨╡╤В╤М╨╕╨╝ ╨┐╨╡╤А╨╡╤Е╨╛╨┤╨╛╨╝ ╤Ж╨╡╨┐╨╛╤З╨║╨╕ тАФ ╤Б╨╝. ┬лFixed┬╗ ╨▓╤Л╤И╨╡._ ╨в╨╡╤Б╤В╤Л: `DealTest` 31 ╨╝╨╡╤В╨╛╨┤ / 30 ╨║╨╡╨╣╤Б╨╛╨▓ (6 ╨╜╨╛╨▓╤Л╤Е; ╤В╤А╨╕ ╨╕╨╖ ╨╜╨╕╤Е ╨┐╨░╨┤╨░╤О╤В ╨╜╨░ `origin/main` тАФ ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜╨╛ ╨╛╤В╨║╨░╤В╨╛╨╝ ╨╛╨▒╤Б╨╡╤А╨▓╨╡╤А╨░: ╨▓╨╖╨╜╨╛╤Б╤Л ╨╛╨┤╨╜╨╛╨│╨╛ ╨┐╨╗╨░╨╜╨░ тЖТ ╨╛╨┤╨╜╨░ ╤Б╨┤╨╡╨╗╨║╨░, ╨┐╨╛╨▓╤В╨╛╤А╨╜╨░╤П ╨┐╨╛╨║╤Г╨┐╨║╨░ ╨▒╨╡╨╖ ╤А╨░╤Б╤Б╤А╨╛╤З╨║╨╕ тЖТ ╨Ф╨Т╨Х, ╨▓╨╖╨╜╨╛╤Б ╨╜╨╡ ╨╖╨░╨║╤А╤Л╨▓╨░╨╡╤В ╤З╤Г╨╢╤Г╤О ╨╛╤В╨║╤А╤Л╤В╤Г╤О ╤Б╨┤╨╡╨╗╨║╤Г; ╨┐╨╗╤О╤Б ╨╛╨▒╤А╨░╤В╨╜╨░╤П ╨▓╨╡╤В╨▓╤М ╤Б╨▓╤П╨╖╨╕, ╨╛╨▒╨╡╤Й╨░╨╜╨╕╨╡ ╨▒╨╡╨╖ ╨│╤А╤Г╨┐╨┐╤Л, ╨▓╨╛╨╖╨▓╤А╨░╤В-╤Б-╨┐╨╛╨▓╤В╨╛╤А╨╜╨╛╨╣-╨╛╨┐╨╗╨░╤В╨╛╨╣). Executor: Opus 5 (`claude-opus-5`).
- **H1623 docs-freshness (Grok 4.5 grok-4.5, 25-07-2026):** metadoc freshness sync for docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.meta.md, docs/deploy.meta.md, docs/support-subsystem-map.meta.md.
- **H1451 true redo (24-07-2026).** Hardened Kinescope pilot after first merge:
  multi-field URL resolve (`video_url` тЖТ `youtube_url` тЖТ `rutube_url`), reserved
  path-segment reject in `VideoEmbed::kinescopeId`, `.env.example` activation
  knobs, extra tests. Executor: Grok 4.5 (`grok-4.5`) via xAI.
- **VK/ORS content calendar plan (H2).** Layered /ask from
- **╨Ю╨┐╨╡╤А╨░╤В╨╛╤А╤Б╨║╨╕╨╣ ╨╝╨░╨╜╤Г╨░╨╗ RU: ╨║╨╗╨╕╨┐╤Л ╨╗╨╡╨║╤Ж╨╕╨╣ + n8n.** [docs/MANUAL_N8N_LECTURE_CLIPS_OPERATOR_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_N8N_LECTURE_CLIPS_OPERATOR_RU.md) тАФ ╨╡╨╢╨╡╨╜╨╡╨┤╨╡╨╗╤М╨╜╨░╤П ╤Н╨║╤Б╨┐╨╗╤Г╨░╤В╨░╤Ж╨╕╤П; ╤Г╤Б╤В╨░╨╜╨╛╨▓╨║╨░: [issue #666](https://github.com/gasyoun/Systema-Sanscriticum/issues/666). Grok 4.5 (grok-4.5).
- **Kinescope pilot on one flagship course тАФ Anton ops-gaps Wave 3 (H1451).**
  Flag `kinescope_pilot` / `KINESCOPE_PILOT` (OFF by default) +
  `config/video.php` `kinescope_pilot_course_id`. `VideoEmbed` recognises
  kinescope.io URLs; lesson player renders a Kinescope iframe + Player SDK
  only when the flag is on, the course matches, and `lesson.video_url` is
  Kinescope тАФ reuses W2 `video-resume.js` kinescope adapter. Comparison memo
  `docs/KINESCOPE_PILOT_COMPARISON_2026.md`; DEPLOY_QUEUE тДЦ48.
  **Executor:** Grok 4.5 (`grok-4.5`) via xAI (Sonnet-lock override).

### Fixed
- **GC-C1 тАФ ╨┤╨▓╨╡ ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╨╕ H1659, ╨╜╨░╨╣╨┤╨╡╨╜╨╜╤Л╨╡ ╨╛╨▒╤П╨╖╨░╤В╨╡╨╗╤М╨╜╤Л╨╝ adversarial-╤А╨╡╨▓╤М╤О (Opus 5 `claude-opus-5`) ╤Г╨╢╨╡ ╨Я╨Ю╨б╨Ы╨Х ╨╝╨╡╤А╨┤╨╢╨░ [PR #705](https://github.com/gasyoun/Systema-Sanscriticum/pull/705).** ╨а╨╡╨▓╤М╤О ╨▓╨╡╤А╨╜╤Г╨╗╨╛╤Б╤М ╨┐╨╛╤Б╨╗╨╡ ╨╝╨╡╤А╨┤╨╢╨░; ╨╛╨▒╨░ ╨┤╨╡╤Д╨╡╨║╤В╨░ ╨╕╨╖╨╝╨╡╤А╨╡╨╜╤Л ╨┐╤А╨╛╨│╨╛╨╜╨╛╨╝, ╨╛╨▒╨░ ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╤А╨╛╨▓╨░╨╗╨╕ ╨┐╤А╨╛╤В╨╕╨▓ ╨┤╨╛-H1659 ╨┐╨╛╨▓╨╡╨┤╨╡╨╜╨╕╤П. **(1) ╨Ъ╤Г╤А╨░╤В╨╛╤А╤Б╨║╨╛╨╡ ┬л╨Я╨╛╨┤╤В╨▓╨╡╤А╨┤╨╕╤В╤М ╨╛╨┐╨╗╨░╤В╤Г┬╗ ╨╖╨░╨▓╨╛╨┤╨╕╨╗╨╛ ╨┐╨╛ ╤Б╨┤╨╡╨╗╨║╨╡ ╨Э╨Р ╨Ъ╨Р╨Ц╨Ф╨л╨Щ ╨▓╨╖╨╜╨╛╤Б** тАФ ╨╕╨╖╨╝╨╡╤А╨╡╨╜╨╛ 3 ╤Б╨┤╨╡╨╗╨║╨╕ ╨┐╤А╨╛╤В╨╕╨▓ 1: [`PromiseFulfillment::fulfil`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/PromiseFulfillment.php) ╤Б╨╛╨╖╨┤╨░╤С╤В ╨┐╨╗╨░╤В╤С╨╢ ╨╕ ╨╗╨╕╤И╤М ╨Я╨Ю╨в╨Ю╨Ь ╤Б╨▓╤П╨╖╤Л╨▓╨░╨╡╤В ╨╛╨▒╨╡╤Й╨░╨╜╨╕╨╡, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨╜╨░ ╨╝╨╛╨╝╨╡╨╜╤В ╨╛╨▒╤Б╨╡╤А╨▓╨╡╤А╨░ ╨╜╨╡╤В ╨╜╨╕ ╨┐╤А╤П╨╝╨╛╨╣, ╨╜╨╕ ╨╛╨▒╤А╨░╤В╨╜╨╛╨╣ ╤Б╨▓╤П╨╖╨╕, ╨░ ╤Б╨╜╤П╤В╨░╤П ╤Н╨▓╤А╨╕╤Б╤В╨╕╨║╨░ ┬л╤З╨╡╨╗╨╛╨▓╨╡╨║ + ╨║╤Г╤А╤Б┬╗ ╨▒╤Л╨╗╨░ ╨╡╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╝, ╤З╤В╨╛ ╨┐╤А╨╕╨║╤А╤Л╨▓╨░╨╗╨╛ ╤Н╤В╨╛╤В ╨┐╤Г╤В╤М; ╨╕ ╤Н╤В╨╛ ╨╜╨╡ ╨║╤А╨░╨╣, ╨░ ╨╛╤Б╨╜╨╛╨▓╨╜╨╛╨╣ ╤А╤Г╤З╨╜╨╛╨╣ ╤Б╤Ж╨╡╨╜╨░╤А╨╕╨╣ ╨╖╨░╨║╤А╤Л╤В╨╕╤П ╤А╨░╤Б╤Б╤А╨╛╤З╨║╨╕ ([`Debtors.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Debtors.php), [`PaymentPromisesRelationManager.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/UserResource/RelationManagers/PaymentPromisesRelationManager.php)). ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜ **╤В╤А╨╡╤В╨╕╨╣ ╨┐╨╡╤А╨╡╤Е╨╛╨┤** ╤Ж╨╡╨┐╨╛╤З╨║╨╕ тАФ ╨┐╨╗╨░╨╜ ╤Б ╨Э╨Х╨Я╨Ю╨У╨Р╨и╨Х╨Э╨Э╨л╨Ь╨Ш (`active`/`expired`) ╨╛╨▒╨╡╤Й╨░╨╜╨╕╤П╨╝╨╕ ╨┐╨╛ ╤Н╤В╨╛╨╝╤Г ╨╢╨╡ ╤З╨╡╨╗╨╛╨▓╨╡╨║╤Г ╨╕ ╨║╤Г╤А╤Б╤Г. ╨н╤В╨╛ ╨Э╨Х ╨▓╨╛╨╖╨▓╤А╨░╤В ╤Н╨▓╤А╨╕╤Б╤В╨╕╨║╨╕: ╨┐╨╗╨░╨╜ ╨┤╨╛╨╗╨╢╨╡╨╜ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╨╛╨▓╨░╤В╤М ╨╕ ╨▒╤Л╤В╤М ╨╡╤Й╤С ╨╜╨╡ ╨╖╨░╨║╤А╤Л╤В, ╨┐╨╛╤Н╤В╨╛╨╝╤Г ╨┐╨╛╨╗╨╜╨╛╤Б╤В╤М╤О ╨╛╨┐╨╗╨░╤З╨╡╨╜╨╜╤Л╨╣ ╨┐╨╗╨░╨╜ ╨┐╤А╨╛╤И╨╗╨╛╨│╨╛ ╨│╨╛╨┤╨░ ╨┐╨╛╨▓╤В╨╛╤А╨╜╤Г╤О ╨┐╨╛╨║╤Г╨┐╨║╤Г ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨│╨╗╤Г╤И╨╕╤В (╨╖╨░╨║╤А╨╡╨┐╨╗╨╡╨╜╨╛ ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╝ ╤В╨╡╤Б╤В╨╛╨╝ `a_fully_paid_plan_no_longer_suppresses_a_later_repurchase`); ╨╛╤Б╤В╨░╤В╨╛╤З╨╜╨░╤П ╤Ж╨╡╨╜╨░ тАФ ╨┐╨╛╨║╤Г╨┐╨║╨░ ╨║╤Г╤А╤Б╨░, ╨┐╨╛ ╨║╨╛╤В╨╛╤А╨╛╨╝╤Г ╨┐╤А╤П╨╝╨╛ ╤Б╨╡╨╣╤З╨░╤Б ╨▓╨╕╤Б╨╕╤В ╨╜╨╡╨╛╨┐╨╗╨░╤З╨╡╨╜╨╜╤Л╨╣ ╨┐╨╗╨░╨╜, ╨╛╤В╨╜╨╛╤Б╨╕╤В╤Б╤П ╨║ ╤Н╤В╨╛╨╝╤Г ╨┐╨╗╨░╨╜╤Г. **(2) ╨Я╨╗╨░╤В╤С╨╢ ╨╝╨╛╨│ ┬л╤Г╨│╨╜╨░╤В╤М┬╗ ╨╛╤В╨║╤А╤Л╤В╤Г╤О ╤Б╨┤╨╡╨╗╨║╤Г, ╨┐╨╛╨╝╨╡╤З╨╡╨╜╨╜╤Г╤О ╨з╨г╨Ц╨Ш╨Ь ╨┐╨╗╨░╨╜╨╛╨╝** тАФ ╨╕╨╖╨╝╨╡╤А╨╡╨╜╨╛ 2 ╤Б╨┤╨╡╨╗╨║╨╕ ╨┐╤А╨╛╤В╨╕╨▓ 1: ╨▓╨╖╨╜╨╛╤Б ╨┐╨╗╨░╨╜╨░ G2 ╨╖╨░╨║╤А╤Л╨▓╨░╨╗ ╤Б╨┤╨╡╨╗╨║╤Г ╨┐╨╗╨░╨╜╨░ G1 ╤З╤Г╨╢╨╕╨╝╨╕ ╨┤╨╡╨╜╤М╨│╨░╨╝╨╕, ╤И╤В╨░╨╝╨┐╨░ G2 ╨╜╨╡ ╨┐╨╛╨╗╤Г╤З╨░╨╗, ╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╕╨╣ ╨▓╨╖╨╜╨╛╤Б G2 ╨╖╨░╨▓╨╛╨┤╨╕╨╗ ╨▓╤В╨╛╤А╤Г╤О ╤Б╨┤╨╡╨╗╨║╤Г, ╨░ ╨▒╤Г╨┤╤Г╤Й╨╕╨╣ ╨▓╨╖╨╜╨╛╤Б G1 ╤Г╨┐╤С╤А╤Б╤П ╨▒╤Л ╨▓ ┬л╤Г╨╢╨╡ ╨╖╨░╨║╤А╤Л╤В╨░┬╗ ╨╕ ╨▒╤Л╨╗ ╨▒╤Л ╨╝╨╛╨╗╤З╨░ ╤Б╤К╨╡╨┤╨╡╨╜. `findOpenDealFor()` ╤В╨╡╨┐╨╡╤А╤М ╨┐╨╛╨╗╤Г╤З╨░╨╡╤В ╨│╤А╤Г╨┐╨┐╤Г ╨┐╨╗╨░╤В╨╡╨╢╨░ ╨╕ **╨╜╨╕╨║╨╛╨│╨┤╨░ ╨╜╨╡ ╨┐╨╡╤А╨╡╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╤В ╤Б╨┤╨╡╨╗╨║╤Г ╤Б ╨з╨г╨Ц╨Ш╨Ь ╨╜╨╡╨┐╤Г╤Б╤В╤Л╨╝ ╤И╤В╨░╨╝╨┐╨╛╨╝ ╨┐╨╗╨░╨╜╨░**; ╨┐╨╗╨░╤В╤С╨╢ ╨▒╨╡╨╖ ╨│╤А╤Г╨┐╨┐╤Л ╤Б╨┤╨╡╨╗╨║╨╕ ╨┐╨╗╨░╨╜╨╛╨▓ ╨╜╨╡ ╤В╤А╨╛╨│╨░╨╡╤В ╨▓╨╛╨▓╤Б╨╡. **(3) ╨в╨╡╤Б╤В ╨┤╨╡╨╜╨╡╨╢╨╜╨╛╨╣ ╨│╤А╨░╨╜╨╕╤Ж╤Л ╨╜╨╡ ╨┐╨╛╨║╤А╤Л╨▓╨░╨╗ ╨│╤А╤Г╨┐╨┐╨╛╨▓╤Г╤О ╨▓╨╡╤В╨║╤Г** (╤Д╨╕╨║╤Б╤В╤Г╤А╨░ ╤Б╤В╤А╨╛╨╕╨╗╨░╤Б╤М ╨▒╨╡╨╖ ╨╛╨▒╨╡╤Й╨░╨╜╨╕╨╣, `payment_promises` ╨┐╤А╨╛╨▓╨╡╤А╤П╨╗╨░╤Б╤М ╨╜╨░ ╨┐╤Г╤Б╤В╨╛╨╣ ╤В╨░╨▒╨╗╨╕╤Ж╨╡) тАФ ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ `bridge_stays_read_only_while_walking_the_instalment_group`, ╤А╨╡╨░╨╗╤М╨╜╨╛ ╨┐╤А╨╛╤Е╨╛╨┤╤П╤Й╨╕╨╣ ╨┐╨╛ ╨║╨╛╨┤╤Г H1659. ╨Я╨╛╨╗╨╜╤Л╨╣ ╨▓╨╡╤А╨┤╨╕╨║╤В ╤А╨╡╨▓╤М╤О (7 REFUTED, ╨▓╨║╨╗╤О╤З╨░╤П ╤А╨░╨╜╤В╨░╨╣╨╝-╨┤╨╛╨║╨░╨╖╨░╤В╨╡╨╗╤М╤Б╤В╨▓╨╛ ╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╤П ╨╖╨░╨┐╨╕╤Б╨╡╨╣ ╨▓╨╜╨╡ `deals`/`deal_transitions` ╨╕ fault-injection ╨╜╨░ ┬л╤А╨░╨╜╨│ 4 ╨╜╨╡ ╨▓╨╡╤В╨╕╤А╤Г╨╡╤В ╤А╨░╨╜╨│ 1┬╗) тАФ [╨║╨╛╨╝╨╝╨╡╨╜╤В╨░╤А╨╕╨╡╨╝ ╨║ PR #705](https://github.com/gasyoun/Systema-Sanscriticum/pull/705#issuecomment-5082410482). ╨в╨╡╤Б╤В╤Л: `DealTest` 34 ╨║╨╡╨╣╤Б╨░, ╨╕╨╖ ╨╜╨╕╤Е 4 ╨╜╨╛╨▓╤Л╤Е; ╨┤╨▓╨░ (╨║╤Г╤А╨░╤В╨╛╤А╤Б╨║╨╛╨╡ ╨╖╨░╨║╤А╤Л╤В╨╕╨╡ ╨▓╨╖╨╜╨╛╤Б╨╛╨▓, ╤Г╨│╨╛╨╜ ╤З╤Г╨╢╨╛╨╣ ╤Б╨┤╨╡╨╗╨║╨╕) ╨╕╨╖╨╝╨╡╤А╨╡╨╜╨╜╨╛ ╨┐╨░╨┤╨░╤О╤В ╨╜╨░ `main` ╨┤╨╛ ╤Н╤В╨╛╨╣ ╨┐╤А╨░╨▓╨║╨╕ тАФ ╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜╨╛ ╨╛╤В╨║╨░╤В╨╛╨╝ ╨╛╨▒╤Б╨╡╤А╨▓╨╡╤А╨░ ╨╜╨░ `origin/main` ╨╕ ╨┐╤А╨╛╨│╨╛╨╜╨╛╨╝. ╨Я╨╛╨╗╨╜╤Л╨╣ Feature-╨╜╨░╨▒╨╛╤А **1932** ╨╖╨╡╨╗╤С╨╜╤Л╨╣, Pint ╤З╨╕╤Б╤В. Executor: Opus 5 (`claude-opus-5`).

## [1.51.0] - 2026-07-22

### Added
- **Kochergina lesson 1 тЖТ dedicated SRS deck (H1431).** New
  `srs:import-kochergina-lesson1` artisan command maps the already-sourced
  `database/seeders/data/memrise_6502608/level_02.csv` (╨Ч╨░╨╜╤П╤В╨╕╨╡ I vocabulary,
  cross-checked against the digitized textbook) onto a dedicated system
  `SrsDeck` (`kochergina-lesson-1`, note type `kochergina_l1`, fields
  `devanagari`/`iast`/`translation_ru`/`translation_en`/`notes` per
  `ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md` P1), separate from the generic
  `srs:import-memrise` per-level decks for the same course. Grammar-class tags
  (`(m)`/`(n)`/`(m,n)`) in the Russian gloss are extracted into `notes` without
  dropping them from `translation_ru`. Idempotent; 7 feature tests, full Srs
  suite green. Behind the existing `SRS_ENABLED=false` gate; the import itself
  is a one-time manual deploy step, not auto-seeded.
- **╨У╨╡╨╣╤В ╤Б╨╛╨│╨╗╨░╤Б╨╕╤П ╨╜╨░ ╤А╨╡╨║╨╗╨░╨╝╨╜╤Г╤О ╤А╨░╤Б╤Б╤Л╨╗╨║╤Г ╨▓ ╨╝╨╡╤Б╤Б╨╡╨╜╨┤╨╢╨╡╤А╤Л (152-╨д╨Ч, H1430).** ╨Я╤Г╤В╤М
  ╤А╨░╤Б╤Б╤Л╨╗╨║╨╕ ╨░╨╜╨╛╨╜╤Б╨╛╨▓ `AnnouncementDispatcher` ╨│╨╡╨╣╤В╨╕╨╗ ╤В╨╛╨╗╤М╨║╨╛ email
  (`wants_email_announcements`), ╨░ Telegram/VK тАФ ╨╜╨╕╤З╨╡╨╝, ╨╕ ╤Г `User` ╨╜╨╡ ╨▒╤Л╨╗╨╛ ╤Д╨╗╨░╨│╨░
  ╤Б╨╛╨│╨╗╨░╤Б╨╕╤П ╨╜╨░ ╨╝╨╡╤Б╤Б╨╡╨╜╨┤╨╢╨╡╤А╤Л. ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜ `users.wants_messenger_announcements` (boolean),
  ╨║╨╛╤В╨╛╤А╤Л╨╝ ╤В╨╡╨┐╨╡╤А╤М ╨│╨╡╨╣╤В╨╕╤В╤Б╤П ╨▓╨╡╤В╨║╨░ `SendMessengerAlerts` ╨▓ ╨┤╨╕╤Б╨┐╨╡╤В╤З╨╡╤А╨╡ ╨░╨╜╨╛╨╜╤Б╨╛╨▓;
  **╤В╤А╨░╨╜╨╖╨░╨║╤Ж╨╕╨╛╨╜╨╜╤Л╨╡** ╤Г╨▓╨╡╨┤╨╛╨╝╨╗╨╡╨╜╨╕╤П (╨║╨╛╨╜╤В╨╡╨╜╤В ╨╝╨░╤А╨░╤Д╨╛╨╜╨░, ╨╜╨░╨┐╨╛╨╝╨╕╨╜╨░╨╜╨╕╤П ╨╛ ╨┤╨╛╨╗╨│╨╡, ╨╜╨░╨▒╨╛╤А
  ╨│╤А╤Г╨┐╨┐) ╨╕╨┤╤Г╤В ╨╝╨╕╨╝╨╛ ╨┤╨╕╤Б╨┐╨╡╤В╤З╨╡╤А╨░ ╨╕ ╤Д╨╗╨░╨│╨╛╨╝ ╨Э╨Х ╨╛╨│╤А╨░╨╜╨╕╤З╨╡╨╜╤Л. ╨Я╨╛╨╗╨╕╤В╨╕╨║╨░ (╤А╨╡╤И╨╡╨╜╨╕╨╡ MG
  21-07-2026): ╨╜╨╛╨▓╤Л╨╡ ╨░╨║╨║╨░╤Г╨╜╤В╤Л тАФ opt-in ╨╕╨╖ ╨│╨░╨╗╨╛╤З╨║╨╕ ╤Б╨╛╨│╨╗╨░╤Б╨╕╤П (`TrialController`),
  ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╡ тАФ ╨│╤А╨░╨╜╨┤╤Д╨░╨╖╨╡╤А ╨▓ `true` ╤А╨░╨╖╨╛╨▓╤Л╨╝ `UPDATE` ╨▓ ╨╝╨╕╨│╤А╨░╤Ж╨╕╨╕ (opt-out, ╤З╤В╨╛╨▒╤Л
  ╨╛╤Е╨▓╨░╤В ╨╜╨╡ ╨╛╤В╨▓╨░╨╗╨╕╨╗╤Б╤П). ╨Я╤А╨╡╨╖╤Г╨╝╨┐╤Ж╨╕╤П ╤Б╨╛╨│╨╗╨░╤Б╨╕╤П ╨┤╨╗╤П ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╤Е ╤Б╨┤╨╡╨╗╨░╨╗╨░ ╨╛╨▒╤П╨╖╨░╤В╨╡╨╗╤М╨╜╨╛╨╣
  **╨╛╤В╨┐╨╕╤Б╨║╤Г**: ╨║╨╛╨╝╨░╨╜╨┤╤Л ╨▒╨╛╤В╨░ `/stop`/`╨╛╤В╨┐╨╕╤Б╨░╤В╤М╤Б╤П`/`╤Б╤В╨╛╨┐ ╤А╨░╤Б╤Б╤Л╨╗╨║╨░` ╨▓
  `TelegramWebhookController` ╤Б╨╜╨╕╨╝╨░╤О╤В ╤В╨╛╨╗╤М╨║╨╛ ╤А╨╡╨║╨╗╨░╨╝╨╜╨╛╨╡ ╤Б╨╛╨│╨╗╨░╤Б╨╕╨╡, ╤В╤А╨░╨╜╨╖╨░╨║╤Ж╨╕╨╛╨╜╨╜╤Л╨╡
  ╤Г╨▓╨╡╨┤╨╛╨╝╨╗╨╡╨╜╨╕╤П ╨╛╤Б╤В╨░╤О╤В╤Б╤П. ╨Р╨┤╨╝╨╕╨╜-╨║╨╛╨╗╨╛╨╜╨║╨░ + ╤Д╨╕╨╗╤М╤В╤А ┬л╨Р╨╜╨╛╨╜╤Б╤Л ╨▓ ╨╝╨╡╤Б╤Б╨╡╨╜╨┤╨╢╨╡╤А╤Л┬╗ ╨▓
  `UserResource`. ╨в╨╡╤Б╤В╤Л: ╨│╨╡╨╣╤В ╨┤╨╕╤Б╨┐╨╡╤В╤З╨╡╤А╨░ (╤И╨╗╤С╤В ╤В╨╛╨╗╤М╨║╨╛ ╤Б╨╛╨│╨╗╨░╤Б╨╕╨▓╤И╨╕╨╝╤Б╤П) + ╨╛╤В╨┐╨╕╤Б╨║╨░ ╨▒╨╛╤В╨╛╨╝.
  **Follow-up (╨╜╨╡ ╨▓ ╤Н╤В╨╛╨╝ PR):** ╨╖╨░╤Е╨▓╨░╤В ╤Б╨╛╨│╨╗╨░╤Б╨╕╤П ╨╜╨░ ╨┐╤А╨╛╤З╨╕╤Е ╨┐╤Г╤В╤П╤Е ╤Б╨╛╨╖╨┤╨░╨╜╨╕╤П User
  (newsletter/╤Б╨╛╤Ж-╨▓╤Е╨╛╨┤/`LeadтЖТUser` ╨┐╤А╨╕ ╨╛╨┐╨╗╨░╤В╨╡), VK-╤Н╨║╨▓╨╕╨▓╨░╨╗╨╡╨╜╤В `/stop`, ╤В╤Г╨╝╨▒╨╗╨╡╤А ╨▓
  ╨║╨░╨▒╨╕╨╜╨╡╤В╨╡ ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░.
- **╨Я╤А╨╛╨╝╨╛-╤Б╨╛╨│╨╗╨░╤Б╨╕╨╡ (152-╨д╨Ч) ╨╜╨░ ╨╛╨▒╨╡╨╕╤Е ╤В╤А╨╕╨░╨╗-╤Д╨╛╤А╨╝╨░╤Е ╨╖╨░╤Е╨▓╨░╤В╨░ ╨╗╨╕╨┤╨░ (H1429).** ╨д╨╛╤А╨╝╨░
  `promo/blocks/trial_block` ╨╕ ╤Г╨╜╨╕╨▓╨╡╤А╤Б╨░╨╗╤М╨╜╨░╤П ╨╝╨╛╨┤╨░╨╗╨║╨░ `components/trial-modal`
  ╨┐╨╛╤Б╤В╨╕╨╗╨╕ ╨▓ `leads.store` ╤В╨╛╨╗╤М╨║╨╛ ╤Б ╨╛╨▒╤П╨╖╨░╤В╨╡╨╗╤М╨╜╨╛╨╣ ╨Я╨Ф╨╜-╨│╨░╨╗╨╛╤З╨║╨╛╨╣ тАФ ╤А╨╡╨║╨╗╨░╨╝╨╜╨╛╨╣
  (`is_promo_agreed`) ╨╜╨╡ ╨▒╤Л╨╗╨╛, ╤В╨░╨║ ╤З╤В╨╛ ╨╕╤Е ╨╗╨╕╨┤╤Л ╨▓╤Б╨╡╨│╨┤╨░ ╤Б╨╛╤Е╤А╨░╨╜╤П╨╗╨╕╤Б╤М ╤Б
  `is_promo_agreed=false` ╨╕ ╨╜╨╡ ╨╝╨╛╨│╨╗╨╕ ╨▒╤Л╤В╤М ╨╖╨░╨║╨╛╨╜╨╜╨╛ ╨▓╨║╨╗╤О╤З╨╡╨╜╤Л ╨▓ ╨╛╤В╨╗╨╛╨╢╨╡╨╜╨╜╤Г╤О ╤А╨░╤Б╤Б╤Л╨╗╨║╤Г
  (╨╜╨░╨┐╤А. ╤Б╨╡╨╜╤В╤П╨▒╤А╤М╤Б╨║╨╛╨╡ ╨╜╨░╨┐╨╛╨╝╨╕╨╜╨░╨╜╨╕╨╡). ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜╨░ ╨▓╤В╨╛╤А╨░╤П, ╨╜╨╡╨╛╨▒╤П╨╖╨░╤В╨╡╨╗╤М╨╜╨░╤П ╨│╨░╨╗╨╛╤З╨║╨░
  ╤Б╨╛╨│╨╗╨░╤Б╨╕╤П ╨╜╨░ ╤А╨░╤Б╤Б╤Л╨╗╨║╤Г ╨┐╨╛ ╤Н╤В╨░╨╗╨╛╨╜╤Г `promo/blocks/form_block`. **╨Ш╨╖╨▓╨╡╤Б╤В╨╜╤Л╨╣
  companion-╤А╨░╨╖╤А╤Л╨▓ (╨╜╨╡ ╨▓ ╤Н╤В╨╛╨╝ PR):** ╨┐╤Г╤В╤М ╤А╨░╤Б╤Б╤Л╨╗╨║╨╕ ╨▓ ╨╝╨╡╤Б╤Б╨╡╨╜╨┤╨╢╨╡╤А╤Л
  (`AnnouncementDispatcher` тЖТ `SendMessengerAlerts`) ╨╜╨╡ ╨│╨╡╨╣╤В╨╕╤В Telegram/VK ╨┐╨╛
  ╤Б╨╛╨│╨╗╨░╤Б╨╕╤О, ╨░ ╤Г `User` ╨╜╨╡╤В ╤Д╨╗╨░╨│╨░ ╤Б╨╛╨│╨╗╨░╤Б╨╕╤П ╨╜╨░ ╨╝╨╡╤Б╤Б╨╡╨╜╨┤╨╢╨╡╤А╤Л тАФ ╤Б╨╝. `@DECIDE` ╨▓ GTD.
- **Roadmap: teacher-load report + public schedule widget.** Layered `/ask` plan
  ([PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md) +
  ROADMAP/ARCHITECTURE/IMPLEMENTATION/VERIFICATION siblings) for an admin
  ┬л╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤М ├Ч ╨│╤А╤Г╨┐╨┐╤Л ├Ч ╨╜╨░╨┐╤А╨░╨▓╨╗╨╡╨╜╨╕╨╡┬╗ analytics page and a reusable public
  iframe-embeddable schedule widget, aimed at replacing the hand-typed
  `samskrtam.ru/raspisanie/` page. No code yet тАФ plan only; wave-1 handoffs
  minted for execution.
- **Public schedule feed + embeddable widget (wave 1b, H1427).** New unauthenticated
  read-only feed `GET /api/public/schedule`
  ([`PublicScheduleController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Api/PublicScheduleController.php))
  behind a strict field-allowlist Resource
  ([`PublicScheduleResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Resources/PublicScheduleResource.php)
  тАФ never emits `link`, `zoom_join_url`/`zoom_start_url`, or numeric ids), throttled 30/min and
  cached 5 min; plus a bare, iframe-embeddable widget page `GET /widgets/schedule`
  ([`PublicWidgetController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicWidgetController.php)
  + [Blade](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/widgets/schedule.blade.php)
  + [vanilla JS](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/widgets/schedule.js))
  that renders a direction/teacher-filterable, weekday-grouped schedule and posts its height to the
  parent for iframe auto-resize, with `Content-Security-Policy: frame-ancestors` scoped to
  `samskrtam.ru` on that one response. Copy-paste embed artifact:
  [`docs/copy/public-schedule-widget-embed.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/public-schedule-widget-embed.md).
  Additive, inert-until-visited; pasting onto the live `samskrtam.ru/raspisanie/` page stays a
  separate explicit human go-ahead.

## [1.50.1] - 2026-07-21

### Changed
- **╨У╨╛╨┤╨╛╨▓╨╛╨╣ ╤А╨╛╨░╨┤╨╝╨░╨┐ ╤Б╨▓╨╡╤А╨╡╨╜ ╤Б ╤Д╨░╨║╤В╨╛╨╝ (H1417).** [`docs/ROADMAP_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md)
  ╨╛╤В╤Б╤В╨░╨▓╨░╨╗ ╨╜╨░ 300+ ╨║╨╛╨╝╨╝╨╕╤В╨╛╨▓ ╨╛╤В ╤Б╨╛╨┤╨╡╤А╨╢╨░╤В╨╡╨╗╤М╨╜╨╛╨╣ ╤А╨╡╨┤╨░╨║╤Ж╨╕╨╕ 07-07-2026. ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜ ┬з1a-╤Б╨╜╨╕╨╝╨╛╨║
  ╤Б╨▓╨╡╤А╨║╨╕ (╨┐╤А╨╛╨│╤А╨╡╤Б╤Б ╤Б╤В╨░╨▓╨╛╨║ ╨│╨╛╨┤╨░, ╨┐╤А╨╕╨╡╤Е╨░╨▓╤И╨╕╨╡ ╨▓╨╜╨╡ ╤В╨╕╨║╨╡╤В╨╛╨▓ ╨┐╤А╨╛╨│╤А╨░╨╝╨╝╤Л, ╨╛╤Б╤В╨░╤В╨╛╨║ ╨┤╨╛╨╗╨│╨╛╨▓), ╤Б╤В╨░╤В╤Г╤Б╤Л
  ╤В╨╕╨║╨╡╤В╨╛╨▓ ┬з5тАУ┬з6 ╨┐╤А╨╛╤Б╤В╨░╨▓╨╗╨╡╨╜╤Л ╨┐╨╛ ╤А╨╡╨░╨╗╤М╨╜╤Л╨╝ PR/handoff-╨░╤А╤Е╨╕╨▓╤Г (R1 SRS тЬЕ ╨▓ ╨┐╤А╨╛╨┤╨╡, M1/M2/O3/S5/S6/X1/X4
  ╨╕ ╨┤╤А.), ╤В╤А╨╕ ╤А╨╡╤И╨╡╨╜╨╕╤П ┬з2 ╨┐╨╛╨╝╨╡╤З╨╡╨╜╤Л **тЯ│ ╨Я╨Х╨а╨Х╨б╨Ь╨Ю╨в╨а╨Х╨Э╨Ю** (CD-╨┐╨░╨╣╨┐╨╗╨░╨╣╨╜ H1046 ╨┐╨╛╤Б╤В╤А╨╛╨╡╨╜ ╨▓╨╛╨┐╤А╨╡╨║╨╕ ┬л╨╜╨╡ ╤Б╤В╤А╨╛╨╕╨╝┬╗;
  Laravel 10тЖТ**12** ╨▓╨╝╨╡╤Б╤В╨╛ 11 H862; X0 ╨╕╨╖ ┬л╨╜╨╡╤А╨░╨╖╨▒╨╗╨╛╨║╨╕╤А╤Г╨╡╨╝╨╛╨│╨╛ ╨│╨╡╨╣╤В╨░┬╗ тЖТ ╨║╨░╤В╤П╤Й╨╕╨╣╤Б╤П ╨┐╤А╨╛╨┤-╤И╨░╨│ ╨Ш╨▓╨░╨╜╨░).
  ╨Ь╨╡╤В╨░╨┤╨╛╨║ ╨╛╨▒╨╜╨╛╨▓╨╗╤С╨╜ (backlog #1 ╨╖╨░╨║╤А╤Л╤В). `Last updated` тЖТ 21-07-2026.

## [1.50.0] - 2026-07-21

### Added
- **H1396 ┬з┬з2тАУ4: ╤З╨╡╨║╨░╤Г╤В ╨┐╨╡╤А╨╡╨╢╨╕╨▓╨░╨╡╤В ╤Б╨▓╨╛╤О ╤Б╨╡╤Б╤Б╨╕╤О (╨╜╨╡-╨┤╨╡╨╜╨╡╨╢╨╜╨░╤П ╨┐╨╛╨╗╨╛╨▓╨╕╨╜╨░).** ╨в╤А╨╕
  ╨┤╨╡╤Д╨╡╨║╤В╨░, ╨╛╨▒╤Й╨╕╨╣ ╨║╨╛╤А╨╡╨╜╤М ╤Б ┬з1 тАФ ┬л╤Б╤В╤А╨░╨╜╨╕╤Ж╨░ ╤З╨╡╨║╨░╤Г╤В╨░ ╨╢╨╕╨▓╤С╤В ╨┤╨╛╨╗╤М╤И╨╡ ╤Б╨╛╨▒╤Б╤В╨▓╨╡╨╜╨╜╨╛╨╣ ╤Б╨╡╤Б╤Б╨╕╨╕┬╗,
  ╨║╨░╨╢╨┤╤Л╨╣ ╨╖╨░ ╤Д╨╗╨░╨│╨╛╨╝ (╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О OFF), ╨║╤А╨╛╨╝╨╡ ┬з4-╤В╤А╨╛╤В╤В╨╗╨░ (╨╛╨▒╤Л╤З╨╜╨╛╨╡ ╤Г╤Б╨╕╨╗╨╡╨╜╨╕╨╡, ╨║╨╛╤В╨╛╤А╨╛╨╡
  ╤Г╨╢╨╡ ╨╡╤Б╤В╤М ╤Г ╨▓╤Б╨╡╤Е ╤Б╨╛╤Б╨╡╨┤╨╜╨╕╤Е ╤А╨╛╤Г╤В╨╛╨▓).
  - **┬з2 тАФ ╤В╤Г╨┐╨╕╨║ ╨┐╤А╨╕ ╨╕╤Б╤В╤С╨║╤И╨╡╨╣ ╤Б╨╡╤Б╤Б╨╕╨╕ ╨▒╨╡╨╖ remember-me.** ╨Ч╨░╨╗╨╛╨│╨╕╨╜╨╡╨╜╨╜╤Л╨╣ ╤Б╤В╤Г╨┤╨╡╨╜╤В, ╤З╤М╤П
    ╤Б╨╡╤Б╤Б╨╕╤П ╨┐╤А╨╛╤В╤Г╤Е╨╗╨░ ╨╝╨╡╨╢╨┤╤Г ╨┐╨╛╨║╨░╨╖╨╛╨╝ ╨╕ ╤Б╨░╨▒╨╝╨╕╤В╨╛╨╝, ╨┐╤А╨╕╤Е╨╛╨┤╨╕╨╗ ╨▓
    [`PaymentController::createPayment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaymentController.php)
    ╤Г╨╢╨╡ ╨│╨╛╤Б╤В╨╡╨╝; ╤Д╨╛╤А╨╝╤Г ╨╡╨╝╤Г ╨┐╨╛╨║╨░╨╖╤Л╨▓╨░╨╗╨╕ ╨С╨Х╨Ч ╨│╨╛╤Б╤В╨╡╨▓╤Л╤Е ╨┐╨╛╨╗╨╡╨╣ (╨╛╨╜╨╕ ╨▓ `@guest`), ╨┐╨╛╤Н╤В╨╛╨╝╤Г
    guest-required ╨▓╨░╨╗╨╕╨┤╨░╤Ж╨╕╤П ╤Б╤Л╨┐╨░╨╗╨░ ╤З╨╡╤В╤Л╤А╨╡ ╨╛╤И╨╕╨▒╨║╨╕ ╨╜╨░ ╨╜╨╡╨▓╨╕╨┤╨░╨╜╨╜╤Л╤Е ╨┐╨╛╨╗╤П╤Е, ╨░ ╤Б╨▓╨╛╨╣ ╨╢╨╡ email
    ╨╗╨╛╨▓╨╕╨╗ ╨╢╤С╤Б╤В╨║╨╕╨╣ ╨╛╤В╨║╨░╨╖. ╨в╨╡╨┐╨╡╤А╤М ╤Б╨║╤А╤Л╤В╨░╤П ╨╝╨╡╤В╨║╨░ `checkout_authed=1` ╨╕╨╖ `@auth`-╤Д╨╛╤А╨╝╤Л
    ╤А╨░╤Б╨┐╨╛╨╖╨╜╨░╤С╤В ╤Н╤В╨╛ ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╡ ╨╕ ╤Г╨▓╨╛╨┤╨╕╤В ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░ ╨╜╨░ `/login` ╤Б intended-╨▓╨╛╨╖╨▓╤А╨░╤В╨╛╨╝ ╨║
    ╨╛╨┐╨╗╨░╤В╨╡ ╤В╨╛╨│╨╛ ╨╢╨╡ ╤В╨░╤А╨╕╤Д╨░. ╨Ч╨░ ╤Д╨╗╨░╨│╨╛╨╝ `checkout_session_lapse_relogin`.
  - **┬з3 тАФ ╨╕╨┤╨╡╨╜╤В╨╕╤Д╨╕╨║╨░╤Ж╨╕╤П ╨▓╨╛╨╖╨▓╤А╨░╤В╨░ ╨╕╨╖ ╨▒╨░╨╜╨║╨░ ╨┐╨╛ ╨┐╨╛╨┤╨┐╨╕╤Б╨░╨╜╨╜╨╛╨╝╤Г id ╨╖╨░╨║╨░╨╖╨░.**
    [`TochkaPaymentService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Payments/TochkaPaymentService.php)
    ╤Б╨╗╨░╨╗ ╨╜╨╡╨┐╨╛╨┤╨┐╨╕╤Б╨░╨╜╨╜╤Л╨╡ `redirectUrl`/`failRedirectUrl`, ╨░ `success()` ╨╛╨┐╨╛╨╖╨╜╨░╨▓╨░╨╗ ╨╖╨░╨║╨░╨╖
    ╨┐╨╛ `auth()->id()` + `latest('id')` тАФ ╤З╤В╨╛ ╨╗╨╛╨╝╨░╨╗╨╛╤Б╤М ╨▓ in-app WebView (Telegram),
    ╨│╨┤╨╡ ╤А╨╡╨┤╨╕╤А╨╡╨║╤В ╨╕╨╖ ╨▒╨░╨╜╨║╨░ ╤Г╤Е╨╛╨┤╨╕╤В ╨▓ ╨┤╤А╤Г╨│╤Г╤О cookie jar: ╤А╨╡╨░╨╗╤М╨╜╨╛ ╨╛╨┐╨╗╨░╤В╨╕╨▓╤И╨╕╨╣ ╤Б╤В╤Г╨┤╨╡╨╜╤В
    ╨┐╨╛╨┐╨░╨┤╨░╨╗ ╨╜╨░ ╨│╨╛╤Б╤В╨╡╨▓╨╛╨╣ ╤Н╨║╤А╨░╨╜ ┬л╨Т╨╛╨╣╨┤╨╕╤В╨╡ ╨▓ ╨░╨║╨║╨░╤Г╨╜╤В┬╗, ╨░ ╨┐╤А╨╕ ╨┤╨▓╤Г╤Е pending ╨▓╤Л╨▒╨╕╤А╨░╨╗╤Б╤П ╨Э╨Х ╤В╨╛╤В
    ╨╖╨░╨║╨░╨╖. ╨в╨╡╨┐╨╡╤А╤М ╨▓╨╛╨╖╨▓╤А╨░╤В ╨╜╨╡╤Б╤С╤В `URL::signedRoute` ╤Б payment id, ╨╕ `success`/`fail`
    ╨╛╨┐╨╛╨╖╨╜╨░╤О╤В ╤В╨╛╤З╨╜╤Л╨╣ ╨╖╨░╨║╨░╨╖ ╨┐╨╛ ╨▓╨░╨╗╨╕╨┤╨╜╨╛╨╣ ╨┐╨╛╨┤╨┐╨╕╤Б╨╕, ╨┐╨╡╤А╨╡╨╢╨╕╨▓╨░╤П ╨┐╨╛╤В╨╡╤А╤О cookie. ╨Ч╨░ ╤Д╨╗╨░╨│╨╛╨╝
    `checkout_signed_return_url`.
  - **┬з4 тАФ ╤В╤А╨╛╤В╤В╨╗ `/csrf-token`.** ╨Х╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ ╤А╨╛╤Г╤В web-╨│╤А╤Г╨┐╨┐╤Л ╨▒╨╡╨╖ ╤В╤А╨╛╤В╤В╨╗╨░; ╤Б
    `SESSION_DRIVER=file` ╨║╨░╨╢╨┤╤Л╨╣ ╨▒╨╡╨╖╨║╤Г╨║╨╕╤Б╨╜╤Л╨╣ ╤Е╨╕╤В ╨┐╨╕╤Б╨░╨╗ ╨╜╨╛╨▓╤Л╨╣ session-╤Д╨░╨╣╨╗. ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜
    `throttle:30,1` тАФ ╨║╨░╨║ ╤Г ╤Б╨╛╤Б╨╡╨┤╨╜╨╕╤Е ╤З╨╡╨║╨░╤Г╤В-╤А╨╛╤Г╤В╨╛╨▓
    ([`routes/web.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php)).

  8 ╤В╨╡╤Б╤В╨╛╨▓ (`CheckoutSessionRenewalHardeningTest`), `--filter="Checkout|Promo|Payment"`
  (241) + Pint ╨╖╨╡╨╗╤С╨╜╤Л╨╡. ╨д╨╗╨░╨│╨╕: `CHECKOUT_SESSION_LAPSE_RELOGIN` /
  `CHECKOUT_SIGNED_RETURN_URL` = true + `config:cache` ╨┐╨╛╤Б╨╗╨╡ ╤А╨╡╨▓╤М╤О.
  ([H1396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1396-Opus_Systema-Sanscriticum_promo-lost-on-session-renewal-full-price-charge_20.07.26.md))

## [1.49.1] - 2026-07-21

### Fixed
- **H1396 ┬з1: ╨┐╤А╨╛╨╝╨╛╨║╨╛╨┤ ╨┐╨╡╤А╨╡╨╢╨╕╨▓╨░╨╡╤В ╨╛╨▒╨╜╨╛╨▓╨╗╨╡╨╜╨╕╨╡ ╤Б╨╡╤Б╤Б╨╕╨╕ ╨▓ ╤З╨╡╨║╨░╤Г╤В╨╡ (╨┤╨╡╨╜╨╡╨╢╨╜╤Л╨╣ ╨▒╨░╨│).**
  ╨Я╤А╨╕╨╝╨╡╨╜╤С╨╜╨╜╤Л╨╣ ╨┐╤А╨╛╨╝╨╛╨║╨╛╨┤ ╨╢╨╕╨╗ ╨в╨Ю╨Ы╨м╨Ъ╨Ю ╨▓ `session('promo_code')`; ╨░╨╜╤В╨╕-419 ╨╛╨▒╨╜╨╛╨▓╨╗╨╡╨╜╨╕╨╡
  CSRF-╤В╨╛╨║╨╡╨╜╨░ ╨╝╨╛╨│╨╗╨╛ ╨▓╤Л╨┤╨░╤В╤М ╨╜╨╛╨▓╤Г╤О ╨┐╤Г╤Б╤В╤Г╤О ╤Б╨╡╤Б╤Б╨╕╤О, ╨░ remember-me ╨┐╨╡╤А╨╡-╨░╤Г╤В╨╡╨╜╤В╨╕╤Д╨╕╤Ж╨╕╤А╨╛╨▓╨░╨╗
  ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤П тАФ ╨╛╨╜ ╨┐╤А╨╛╤Б╨║╨░╨║╨╕╨▓╨░╨╗ `auth()->check()` ╨▓
  [`PaymentController::createPayment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaymentController.php)
  ╤Б ╨┐╨╛╤В╨╡╤А╤П╨╜╨╜╤Л╨╝ ╨┐╤А╨╛╨╝╨╛╨║╨╛╨┤╨╛╨╝ ╨╕ ╤Г╤Е╨╛╨┤╨╕╨╗ ╨▓ ╨▒╨░╨╜╨║ ╨╜╨░ ╨Я╨Ю╨Ы╨Э╨г╨о ╤Б╤Г╨╝╨╝╤Г, ╤Е╨╛╤В╤П ╨║╨╜╨╛╨┐╨║╨░ ╨┐╨╛╨║╨░╨╖╤Л╨▓╨░╨╗╨░
  ╤Б╨║╨╕╨┤╨║╤Г (╨┐╨╛╨▓╤В╨╛╤А H071 #13 ╤З╨╡╤А╨╡╨╖ ╨┤╤А╤Г╨│╤Г╤О ╨┤╨▓╨╡╤А╤М). ╨в╨╡╨┐╨╡╤А╤М ╨║╨╛╨┤ ╨╜╨╡╤Б╤С╤В╤Б╤П ╨▓ ╤Б╨║╤А╤Л╤В╨╛╨╝ ╨┐╨╛╨╗╨╡
  ╤Д╨╛╤А╨╝╤Л ╨╕ ╨┐╨╡╤А╨╡-╤А╨╡╨╖╨╛╨╗╨▓╨╕╤В╤Б╤П ╨Р╨Т╨в╨Ю╨а╨Ш╨в╨Х╨в╨Э╨Ю ╨┐╤А╨╕ ╤Б╨░╨▒╨╝╨╕╤В╨╡ (╨║╨╗╨╕╨╡╨╜╤В╤Б╨║╨╛╨╡ ╨╖╨╜╨░╤З╨╡╨╜╨╕╨╡ forgeable тЖТ
  ╤В╨╡ ╨╢╨╡ ╨┐╤А╨░╨▓╨╕╨╗╨░ `isCurrentlyActive`/`appliesToCourse`/`redeemedByUser`/`hasCapacity`):
  ╨▓╨░╨╗╨╕╨┤╨╡╨╜ тЖТ ╤Б╨║╨╕╨┤╨║╨░ ╤Б╨┐╨╕╤Б╤Л╨▓╨░╨╡╤В╤Б╤П ╨╖╨░╨╜╨╛╨▓╨╛; ╨┐╤А╨╛╤В╤Г╤Е тЖТ ╨Э╨Х ╤Г╤Е╨╛╨┤╨╕╨╝ ╨╝╨╛╨╗╤З╨░ ╨▓ ╨▒╨░╨╜╨║ ╨╜╨░ ╨┐╨╛╨╗╨╜╤Г╤О ╨╕
  ╨Э╨Х ╨╛╤В╨║╨░╨╖╤Л╨▓╨░╨╡╨╝, ╨░ ╨┐╨╛╨║╨░╨╖╤Л╨▓╨░╨╡╨╝ ╤П╨▓╨╜╤Л╨╣ ╤Н╨║╤А╨░╨╜
  [`confirm-price`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/checkout/confirm-price.blade.php)
  ╤Б ╨╜╨╛╨▓╨╛╨╣ ╤Ж╨╡╨╜╨╛╨╣, ╨╕ ╨╖╨░╨║╨░╨╖ ╤Б╨╛╨╖╨┤╨░╤С╤В╤Б╤П ╤В╨╛╨╗╤М╨║╨╛ ╨┐╨╛╤Б╨╗╨╡ ╤П╨▓╨╜╨╛╨│╨╛ ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╕╤П ╤А╨╛╨▓╨╜╨╛ ╨╜╨░
  ╨┐╨╛╨║╨░╨╖╨░╨╜╨╜╤Г╤О ╤Б╤Г╨╝╨╝╤Г (RULED 20-07-2026 MG). ╨Ч╨░ ╤Д╨╗╨░╨│╨╛╨╝ `checkout_promo_survives_session`
  (╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О OFF) тАФ ╤Б ╤Д╨╗╨░╨│╨╛╨╝ OFF `createPayment` ╨▒╨░╨╣╤В-╨▓-╨▒╨░╨╣╤В ╨║╨░╨║ ╤А╨░╨╜╤М╤И╨╡, ╤З╤В╨╛
  ╨╖╨░╨║╤А╨╡╨┐╨╗╨╡╨╜╨╛ parity-╤В╨╡╤Б╤В╨╛╨╝; ╨┤╨╡╨╜╨╡╨╢╨╜╤Л╨╣ PR ╨┐╤А╨╛╨┤-╨╕╨╜╨╡╤А╤В╨╡╨╜ ╨┤╨╛
  `CHECKOUT_PROMO_SURVIVES_SESSION=true` + `config:cache` ╨┐╨╛╤Б╨╗╨╡ ╤А╨╡╨▓╤М╤О. ╨б╨▓╨╡╤А╨║╨░
  ╨┤╨▓╨╛╨╣╨╜╨╛╨│╨╛ ╨┐╤А╨╛╨│╨╛╨╜╨░ (dual-run): ╨┤╨▓╨╡ ╨╜╨╡╨╖╨░╨▓╨╕╤Б╨╕╨╝╤Л╨╡ ╤Б╨╡╤Б╤Б╨╕╨╕ Opus 4.8 ╤А╨╡╨░╨╗╨╕╨╖╨╛╨▓╨░╨╗╨╕ ┬з1
  ╨╛╨┤╨╕╨╜╨░╨║╨╛╨▓╨╛ ╨┐╨╛╨▒╨░╨╣╤В╨╜╨╛; ╨▓╤Л╨╕╨│╤А╨░╨▓╤И╨╕╨╣ ╨╗╨╡╨╣╨╜ ╨┤╨╛╨▒╨░╨▓╨╕╨╗ ╤Д╨╗╨░╨│-╨│╨╡╨╣╤В ╨╕ parity-╤В╨╡╤Б╤В.
  4 ╤В╨╡╤Б╤В╨░ (`CheckoutPromoRenewalTest`), `--filter="Checkout|Promo|Payment"` + Pint
  ╨╖╨╡╨╗╤С╨╜╤Л╨╡ ╨▓ CI. ┬з2/┬з3/┬з4 тАФ ╨▓ ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛╨╝ follow-up.
  ([PR #631](https://github.com/gasyoun/Systema-Sanscriticum/pull/631),
  [H1396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1396-Opus_Systema-Sanscriticum_promo-lost-on-session-renewal-full-price-charge_20.07.26.md))

## [1.49.0] - 2026-07-20

### Added
- **H1291: ╨║╨╛╤А╨┐╤Г╤Б ╨▓╨╛╨╖╤А╨░╨╢╨╡╨╜╨╕╨╣ тАФ ╨╝╨╕╨║╤А╨╛╨║╨╛╨┐╨╕╤П ╨▓ ╤В╨╛╤З╨║╨╡ ╨┐╤А╨╛╨┤╨░╨╢╨╕ (╨Т╨а╨Х╨Ь╨п + ╨ж╨Х╨Э╨Р).**
  ╨Я╨╛╤Б╨╗╨╡╨┤╨╜╨╕╨╣ ╨╗╨╡╨╣╨╜ ╨▓╨╛╨╗╨╜╤Л revenue-copy
  ([╨┐╨╗╨░╨╜](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)):
  ╨│╨╛╤В╨╛╨▓╤Л╨╡ ╤Д╨╛╤А╨╝╤Г╨╗╨╕╤А╨╛╨▓╨║╨╕ ╨┐╨╗╨╡╨╣╨▒╤Г╨║╨░ ╨▓╨╛╨╖╤А╨░╨╢╨╡╨╜╨╕╨╣ H474/H482 (537 ╨╕╨╖ 679 ╨▓╨╛╨╖╤А╨░╨╢╨╡╨╜╨╕╨╣ тАФ
  ╨Т╨а╨Х╨Ь╨п ╨╕ ╨ж╨Х╨Э╨Р) ╨┐╨╡╤А╨╡╨╜╨╡╤Б╨╡╨╜╤Л ╨╜╨░ ╨┐╨╛╨▓╨╡╤А╤Е╨╜╨╛╤Б╤В╨╕, ╨│╨┤╨╡ ╨▓╨╛╨╖╤А╨░╨╢╨╡╨╜╨╕╨╡ ╨▓╨╛╨╖╨╜╨╕╨║╨░╨╡╤В. ╨Ъ╨░╤А╤В╨╛╤З╨║╨░
  ╨║╨░╤В╨░╨╗╨╛╨│╨░ ╨╛╨▒╤К╤П╤Б╨╜╤П╨╡╤В ╨╡╨┤╨╕╨╜╨╕╤Ж╤Г ╤Ж╨╡╨╜╤Л (┬л╨С╨╗╨╛╨║ тАФ ╨╛╨▒╤Л╤З╨╜╨╛ 4 ╨╖╨░╨╜╤П╤В╨╕╤П┬╗, ╤В╨╛╨╗╤М╨║╨╛ ╨║╨╛╨│╨┤╨░
  ╨┐╨╛╨║╨░╨╖╨░╨╜╨╜╨░╤П ╤Ж╨╡╨╜╨░ тАФ ╨╖╨░ ╤Ж╨╡╨╗╤Л╨╣ ╨▒╨╗╨╛╨║); ╤Б╨╡╨║╤Ж╨╕╤П ┬л╨а╨░╤Б╨┐╨╕╤Б╨░╨╜╨╕╨╡┬╗ ╤Б╨╜╨╕╨╝╨░╨╡╤В ╤Б╤В╤А╨░╤Е ╨┐╤А╨╛╨┐╤Г╤Б╨║╨░
  (┬л╨Ч╨░╨╜╤П╤В╨╕╨╡ ╨╛╤Б╤В╨░╨╜╨╡╤В╤Б╤П ╨▓ ╨╖╨░╨┐╨╕╤Б╨╕тАж ╨Я╤А╨╛╨┐╤Г╤Б╨║ ╨╜╨╡ ╨▓╤Л╨▒╨╕╨▓╨░╨╡╤В ╨╕╨╖ ╨║╤Г╤А╤Б╨░┬╗); ╤Г ╤В╨░╤А╨╕╤Д╨╜╨╛╨╣
  ╤Б╨╡╤В╨║╨╕ тАФ ╨┐╤А╨╕╨╜╤Ж╨╕╨┐ ╨┐╨╛╨▒╨╗╨╛╤З╨╜╨╛╨╣ ╨╛╨┐╨╗╨░╤В╤Л (╤В╨╛╨╗╤М╨║╨╛ ╨║╤Г╤А╤Б╨░╨╝ ╤Б ╤Ж╨╡╨╗╨╛╨▒╨╗╨╛╤З╨╜╤Л╨╝╨╕ ╤В╨░╤А╨╕╤Д╨░╨╝╨╕ ╨╕ ╨╜╨╡
  ╨▓ ╤А╨╡╨╢╨╕╨╝╨╡ ╨┐╤А╨╛╨┤╨░╨╢╨╕ ╨╖╨░╨┐╨╕╤Б╨╡╨╣), ╤Г╨║╨░╨╖╨░╤В╨╡╨╗╤М ╨╜╨░ ╨╖╨░╨┐╤А╨╛╤Б ┬л╨╛╨┐╨╗╨░╤В╨░ ╨┐╨╛ ╤З╨░╤Б╤В╤П╨╝┬╗ (╤В╨░ ╨╢╨╡
  ╨║╨░╨╗╨╕╤В╨║╨░ ╨║╤Г╤А╨░╤В╨╛╤А╤Б╨║╨╛╨│╨╛ ╤З╨░╤В╨░, ╤З╤В╨╛ ╤Г H1290), ╨╗╤М╨│╨╛╤В╨╜╨░╤П ╤Б╤В╤А╨╛╨║╨░ ╤Б ╤З╨╡╤Б╤В╨╜╨╛╨╣ ╨╛╨│╨╛╨▓╨╛╤А╨║╨╛╨╣
  ┬л╨╜╨░ ╨╝╨╜╨╛╨│╨╕╤Е ╨║╤Г╤А╤Б╨░╤Е┬╗; ┬л╨Т╨╛╨╖╨▓╤А╨░╤В: ╨┤╨╛ ╨╜╨░╤З╨░╨╗╨░ тАФ 100%┬╗ ╨┐╤А╨╛╤Ж╨╕╤В╨╕╤А╨╛╨▓╨░╨╜ ╤Б╤Б╤Л╨╗╨║╨╛╨╣ ╨╜╨░
  `/vozvrat` (shared string 4). ╨Ъ╤Г╨┐╨╕╨▓╤И╨╡╨╝╤Г ╨▓╨╡╤Б╤М ╨║╤Г╤А╤Б ╨╝╨╕╨║╤А╨╛╨║╨╛╨┐╨╕╤П ╨╜╨╡ ╨┐╨╛╨║╨░╨╖╤Л╨▓╨░╨╡╤В╤Б╤П.
  ╨з╨╡╨║╨░╤Г╤В ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛ ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В тАФ ╤В╨░╨╝ ╨╛╨▒╨░ ╨▓╨╛╨╖╤А╨░╨╢╨╡╨╜╨╕╤П ╤Г╨╢╨╡ ╨╛╤В╨▓╨╡╤З╨╡╨╜╤Л. ╨Ъ╨░╨╢╨┤╨░╤П ╤Б╤В╤А╨╛╨║╨░
  ╤Б╨▓╨╡╤А╨╡╨╜╨░ ╤Б ╨┐╤А╨╛╨┤╤Г╨║╤В╨╛╨╝; ┬л╨▓╨▓╨╛╨┤╨╜╤Л╨╣ ╤А╨░╨╖╨▒╨╛╤А ╨У╨╕╤В╤Л ╨╖╨░ 2 000 тВ╜┬╗ ╨╕╨╖ ╨┐╨╗╨╡╨╣╨▒╤Г╨║╨░ ╨╜╨░ ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Л
  ╨Э╨Х ╨┐╨╛╨┐╨░╨╗ тАФ ╤В╨░╨║╨╛╨│╨╛ ╨┐╤А╨╛╨┤╤Г╨║╤В╨░ ╨▓ ╨║╨╛╨┤╨╛╨▓╨╛╨╣ ╨▒╨░╨╖╨╡ ╨╜╨╡╤В. ╨б╤В╤А╨╛╨║╨╕ ╨╕ ╤А╨╡╤И╨╡╨╜╨╕╤П:
  [`docs/copy/money-objection-corpus-pos-microcopy.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-objection-corpus-pos-microcopy.md);
  13 feature-╤В╨╡╤Б╤В╨╛╨▓
  ([`tests/Feature/Shop/ObjectionPosMicrocopyTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Shop/ObjectionPosMicrocopyTest.php)).

## [1.48.0] - 2026-07-20

### Added
- **H1357: ╨┤╨╡╤В╨╡╤А╨╝╨╕╨╜╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╨╣ `/help` ╨╕ ┬л╨╝╨╛╨╕ ╨╖╨░╨┤╨░╨╜╨╕╤П┬╗ ╨▓ ╨▒╨╛╤В╨╡ тАФ ╨┤╨╛ ╨┐╨╡╤А╨╡╨┤╨░╤З╨╕ `CuratorAi`.**
  `StudentSelfService` ╨┐╨╛╨╗╤Г╤З╨╕╨╗ `matchesHelpIntent`/`helpMenu()` ╨╕
  `matchesHomeworkIntent`/`homeworkSummary()` ╨┐╨╛ ╨╛╨▒╤А╨░╨╖╤Ж╤Г ╤Г╨╢╨╡ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╡╨│╨╛
  `matchesGroupsIntent`/`groupsSummary` тАФ ╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨╜╤Л╨╣ ╤В╨╡╨║╤Б╤В ╨╕ ╨┤╨░╨╜╨╜╤Л╨╡ ╨╕╨╖ ╨С╨Ф,
  ╨▒╨╡╨╖ ╨╛╨▒╤А╨░╤Й╨╡╨╜╨╕╤П ╨║ LLM, ╤В╨░╨║ ╤З╤В╨╛ ╨║╤Г╤А╨░╤В╨╛╤А-╨Ш╨Ш ╨╜╨╕╨║╨╛╨│╨┤╨░ ╨╜╨╡ ╨┐╤А╨╕╨┤╤Г╨╝╤Л╨▓╨░╨╡╤В ╤Б╤В╨░╤В╤Г╤Б
  ╨┤╨╛╨╝╨░╤И╨╜╨╡╨│╨╛ ╨╖╨░╨┤╨░╨╜╨╕╤П. ╨Я╨╛╨┤╨║╨╗╤О╤З╨╡╨╜╨╛ ╨▓╨╛ ╨▓╤Б╨╡╤Е ╤В╤А╤С╤Е ╨║╨░╨╜╨░╨╗╨░╤Е, ╨│╨┤╨╡ ╤Г╨╢╨╡ ╤Б╤В╨╛╨╕╤В ╤Н╤В╨░
  ╤А╨░╨╖╨▓╨╕╨╗╨║╨░: `TelegramWebhookController::processStudentQuestion`,
  `ProcessVkBotMessage::handle` (VK) ╨╕ `StudentChatService::respond`
  (╨▓╨╡╨▒-╤З╨░╤В). `/help` ╨╜╨░╨╝╨╡╤А╨╡╨╜╨╜╨╛ ╨╜╨╡ ╨▓╨║╨╗╤О╤З╨░╨╡╤В ┬л╨┐╨╛╨╝╨╛╤Й╤М┬╗ тАФ ╤Н╤В╨╛ ╤Б╨╗╨╛╨▓╨╛ ╤Г╨╢╨╡ ╨╖╨░╨╜╤П╤В╨╛
  ╤В╤А╨╕╨│╨│╨╡╤А╨╛╨╝ ╨┐╨╡╤А╨╡╨┤╨░╤З╨╕ ╨╢╨╕╨▓╨╛╨╝╤Г ╨║╤Г╤А╨░╤В╨╛╤А╤Г (`HUMAN_TRIGGERS`) ╨▓╨╛ ╨▓╤Б╨╡╤Е ╤В╤А╤С╤Е
  ╨║╨╛╨╜╤В╤А╨╛╨╗╨╗╨╡╤А╨░╤Е. 29+112 ╤В╨╡╤Б╤В╨╛╨▓ (`StudentSelfServiceIntentTest`,
  `StudentSelfServiceHomeworkTest`, ╨┐╨╛╨╗╨╜╤Л╨╣ ╨╜╨░╨▒╨╛╤А `--filter=Bot`) ╨╖╨╡╨╗╤С╨╜╤Л╨╡,
  Pint ╤З╨╕╤Б╤В.
  ([PR #610](https://github.com/gasyoun/Systema-Sanscriticum/pull/610),
  [H1357](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1357-Sonnet_Systema-Sanscriticum_bot-deterministic-help-and-homework-status_20.07.26.md)).
- **H1294: ╤А╨╡╨║╨╛╨╝╨╡╨╜╨┤╨░╤Ж╨╕╤П ╤И╨║╨╛╨╗╤Л тАФ ╨┐╤А╨╛╤Б╤М╨▒╨░ ┬л╨┐╤А╨╕╨│╨╗╨░╤Б╨╕╤В╨╡ ╨┤╤А╤Г╨│╨░┬╗ ╨▒╨╡╨╖ ╨▒╨╛╨╜╤Г╤Б╨╜╨╛╨╣ ╤А╨░╨╝╨║╨╕.**
  ╨Ы╨╡╨╣╨╜ ╨▓╨╛╨╗╨╜╤Л revenue-copy
  ([╨┐╨╗╨░╨╜](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  ╨б╤Г╤Й╨╡╤Б╤В╨▓╨╛╨▓╨░╨▓╤И╨╕╨╣ ╨▒╨╗╨╛╨║ ┬л╨Я╤А╨╕╨│╨╗╨░╤И╨░╨╣╤В╨╡ ╨┤╤А╤Г╨╖╨╡╨╣┬╗ (╨╕╨║╨╛╨╜╨║╨░ ╨┐╨╛╨┤╨░╤А╨║╨░, ┬л╨▓╨░╨╝ ╨╜╨░╤З╨╕╤Б╨╗╤П╤В
  500 тВ╜┬╗ ╨┐╨╡╤А╨▓╤Л╨╝ ╨┐╤А╨╡╨┤╨╗╨╛╨╢╨╡╨╜╨╕╨╡╨╝, ╨▓╨╜╤Г╤В╤А╨╕ ╨▓╨║╨╗╨░╨┤╨║╨╕ ┬л╨Я╤А╨░╨╜╨░┬╗) ╨┐╨╡╤А╨╡╨┐╨╕╤Б╨░╨╜ ╨▓ ╤А╨░╨╝╨║╤Г
  ╤А╨╡╨║╨╛╨╝╨╡╨╜╨┤╨░╤Ж╨╕╨╕ ╤Г╤З╨╕╤В╨╡╨╗╤П: ╨║╤А╨╡╨┤╨╕╤В тАФ ┬л╨▓ ╨╖╨╜╨░╨║ ╨▒╨╗╨░╨│╨╛╨┤╨░╤А╨╜╨╛╤Б╤В╨╕┬╗, ╨╝╨╡╤Е╨░╨╜╨╕╨║╨░ ╨╛╨▒╤К╤П╤Б╨╜╨╡╨╜╨░
  ╨┤╨╛ ╨║╨╛╨╜╤Ж╨░, ╨╜╤Г╨╗╨╡╨▓╤Л╨╡ ╤Б╤З╨╡╤В╤З╨╕╨║╨╕ ╤Б╨║╤А╤Л╤В╤Л; ╨▒╨╗╨╛╨║ ╨┐╨╡╤А╨╡╨╜╨╡╤Б╨╡╨╜ ╨▓ ╨╛╤Б╨╜╨╛╨▓╨╜╤Г╤О ╨▓╨║╨╗╨░╨┤╨║╤Г
  ╨║╨░╨▒╨╕╨╜╨╡╤В╨░ (╤А╨░╨╜╤М╤И╨╡ ╨╕╤Б╤З╨╡╨╖╨░╨╗ ╨┐╤А╨╕ ╨▓╤Л╨║╨╗╤О╤З╨╡╨╜╨╜╨╛╨╣ ╨┐╤А╨░╨╜╨╡). ╨Ф╨╛╤Б╤В╤А╨╛╨╡╨╜╤Л ╨╜╨╡╨┤╨╛╤Б╤В╨░╤О╤Й╨╕╨╡
  ╨┐╨╛╨▓╨╡╤А╤Е╨╜╨╛╤Б╤В╨╕: ╤В╨╕╤Е╨░╤П ╨┐╤А╨╛╤Б╤М╨▒╨░ ╨╜╨░ ╤Б╤В╤А╨░╨╜╨╕╤Ж╨╡ ╤Г╤Б╨┐╨╡╤И╨╜╨╛╨╣ ╨╛╨┐╨╗╨░╤В╤Л (╤В╨╛╨╗╤М╨║╨╛
  ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╜╨╛╨╡ ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╡), ╨│╨╛╤В╨╛╨▓╨╛╨╡ ╨╗╨╕╤З╨╜╨╛╨╡ ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡ ╨┤╨╗╤П ╨╛╤В╨┐╤А╨░╨▓╨║╨╕ ╨╖╨╜╨░╨║╨╛╨╝╨╛╨╝╤Г
  ╨╕ ╨╕╨╝╨╡╨╜╨╜╨░╤П ╨▓╤Б╤В╤А╨╡╤З╨░ ╨┐╤А╨╕╨│╨╗╨░╤И╨╡╨╜╨╜╨╛╨│╨╛ ╨╜╨░ ╨│╨╗╨░╨▓╨╜╨╛╨╣ (┬л{╨Ш╨╝╤П} ╤А╨╡╨║╨╛╨╝╨╡╨╜╨┤╤Г╨╡╤В ╨▓╨░╨╝ ╨╜╨░╤И╤Г
  ╤И╨║╨╛╨╗╤Г┬╗) ╨┐╨╛ ╨▓╨░╨╗╨╕╨┤╨╜╨╛╨╝╤Г `?ref`-╨║╨╛╨┤╤Г. `config/referral.php` ╨╕ ╨╗╨╛╨│╨╕╨║╨░ ╨╜╨░╤З╨╕╤Б╨╗╨╡╨╜╨╕╤П
  ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В╤Л. ╨б╤В╤А╨╛╨║╨╕ ╨╕ ╤А╨╡╤И╨╡╨╜╨╕╤П:
  [`docs/copy/money-referral-invite-ask.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-referral-invite-ask.md);
  6 ╨╜╨╛╨▓╤Л╤Е feature-╤В╨╡╤Б╤В╨╛╨▓
  ([`tests/Feature/ReferralAskSurfacesTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/ReferralAskSurfacesTest.php)).

## [1.47.0] - 2026-07-20

### Security
- **H1359: Tochka payment-webhook now has an idempotency ledger + resurrection/amount-mismatch grant guards** ([H1359](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1359-Opus_Systema-Sanscriticum_tochka-webhook-delivery-ledger-and-grant-guards_20.07.26.md)). `WebhookController::handleTochkaWebhook` тАФ the single automatic ┬л╨╛╨┐╨╗╨░╤З╨╡╨╜╨╛ тЖТ ╨┤╨╛╤Б╤В╤Г╨┐┬╗ trigger in prod тАФ granted on the bank's word alone: a re-delivered or replayed success JWT re-ran the entire paid path (`Payment::fireOnPaid` тЖТ access re-grant, deposit re-consumption, promo-slot double-burn via `PromoCode::markRedeemed`, referral re-reward) because `!== 'paid'` treated a **reversed** payment like a fresh pending one; the bank-reported amount was never compared to `payments.amount`; and no ledger existed for money webhooks.
  - **New append-only ledger** `payment_webhook_events` (one row per unique signature-valid delivery, keyed by `event_hash` = sha256 of the raw JWT body; `decision` тИИ applied / duplicate / rejected_resurrection / rejected_amount_mismatch / unmatched). Recorded on **every** signature-valid delivery, additively тАФ no behaviour change with the flag off.
  - **Three refusals gated behind `features.tochka_webhook_guard`** (env `TOCHKA_WEBHOOK_GUARD`, default **false** тАФ the money PR stays prod-inert until deliberately enabled): (a) a duplicate `event_hash` short-circuits to a 200 no-op; (b) a success for a payment that was paid and then reversed (detected via the `PaymentAudit` trail, `Payment::hasPriorPaidTransition()`) is refused тАФ no resurrection; (c) a bank amount differing from `payments.amount` beyond `config('checkout.webhook_amount_tolerance')` (default 1.00 тВ╜) is refused. The amount is extracted defensively (unconfirmed payload key, absent тЗТ null тЗТ no check), keeping the change free of any live-bank dependency.
  - Operators see refusals via a new **┬лRejected webhook deliveries┬╗** block in `payments:audit-checkout-integrity` (read-only). **13 webhook tests** (7 existing + 6 new: flag-OFF `paidтЖТfailedтЖТreplay` parity pinning today's behaviour, flag-ON resurrection refusal, duplicate no-op, amount-mismatch no-grant, matched-amount success, additive ledger) + an audit-command case. Note a subtle bug fixed in passing: the `payment_audits.changes` column collides with Eloquent's protected `Model::$changes`, so the resurrection detector reads it via `getAttribute('changes')` (sibling-class protected access would otherwise return the empty dirty-tracking array).

## [1.46.0] - 2026-07-20

### Added
- **Free-drill funnel is now measured тАФ an anonymous `game_events` telemetry rail for `/lila`** ([H1360](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1360-Opus_Systema-Sanscriticum_free-drill-funnel-instrumentation-game-events_20.07.26.md), [PR #622](https://github.com/gasyoun/Systema-Sanscriticum/pull/622)). The whole drill family (ligatures/roots/sort/match/cloze) previously stored **not one row** тАФ `gate.js` kept its one-free-play state in `localStorage` only, and `GET /api/games/auth` was the sole server touchpoint тАФ so the Tier-0 funnel question (how many visitors play тЖТ finish тЖТ hit the register wall тЖТ click ┬л╨Э╨░╤З╨░╤В╤М ╨▒╨╡╤Б╨┐╨╗╨░╤В╨╜╨╛┬╗) was unanswerable. Added a first-party `POST /api/games/event` ingest (public web-guard, throttled, CSRF-exempt), a new `game_events` table, a `public/lila/telemetry.js` sender (`navigator.sendBeacon`), a `games:funnel --days=N` report command, and a Filament **┬л╨Т╨╛╤А╨╛╨╜╨║╨░ ╤В╤А╨╡╨╜╨░╨╢╤С╤А╨╛╨▓┬╗** page (manager/admin).
  - **Anonymous by construction (privacy fence):** the table stores no student id, no IP, and no user-agent тАФ only a short client-minted `anon_id` stripped server-side to `[A-Za-z0-9]{0,32}`. The `authenticated` flag is stamped from the web session on the server, never trusted from the client. This keeps the table out of 152-╨д╨Ч personal-data scope.
  - **`gate.js` untouched:** `telemetry.js` is a passive DOM observer of the wall and completion signals gate.js already produces, so the gate's own gating behaviour stays byte-for-byte unchanged (asserted by test). It uses a distinct `localStorage` key (`sgx_anon_v1`) and never reads or writes the gate's `sgx_played_v1`.

## [1.45.0] - 2026-07-20

### Fixed
- **Level-quiz answer positions тАФ the `deva` cohort's quiz graded "always tap the top option" as 6/6** ([H1387](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1387-Fable_csl-guides_quiz-answer-position-fixed-index-defect_20.07.26.md), [PR #614](https://github.com/gasyoun/Systema-Sanscriticum/pull/614)). All six items in `config('marathon.cohorts.deva.level_quiz')` carried `correct => 0`, inherited verbatim from csl-guides, where every answer was authored first and nothing shuffled. `quiz_level` therefore measured nothing about the student тАФ on the cohort whose first intake is **28-08-2026**. Option order re-ported from the fixed upstream bank ([csl-guides PR #119](https://github.com/sanskrit-lexicon/csl-guides/pull/119)) and verified in sync item by item, so the "ported verbatim" relationship the config comment promises still holds.
  - **The tests encoded the defect as a fixture:** three hardcoded `picks = [0,0,0,0,0,0]` as the perfect run and the class docblock stated it as intended behaviour. They now derive picks from config, so a future re-port cannot silently invalidate them.
  - Two guards added тАФ answers must not all share one option position (**confirmed to fail** against a temporarily restored all-zero config before passing on the fix), and every `correct` index must exist in its own `opts`.
- **Salary period close: percent-scheme accrual ignored a closed month for
  payments created before the closure timestamp, leaving CI red.**
  `TeacherSalaryService::rollForwardEventMonth()` only rolled a `percent`/
  `percent_per_block` accrual event forward out of a closed month when the
  triggering payment's `created_at` was *after* the period's `closed_at`.
  Closing a month is meant to mean "nothing more accrues here," regardless of
  when the underlying payment was recorded тАФ the non-percent schemes already
  enforce that unconditionally via `remapMonths()`/`rollForwardMonth()`. The
  event-level `created_at` comparison had no test coverage of its own and
  directly contradicted `tests/Feature/SalaryPeriodCloseTest.php`'s three
  scenarios (late payment roll-forward, per-teacher isolation, reopening),
  which were failing on `origin/main` at `c81a35d` independent of any other
  in-flight PR. Removed the `created_at` special-case; percent-type events now
  roll forward through `rollForwardMonth()` exactly like the fixed schemes.
- **H1391: checkout on phones (iPhone + Android) тАФ four measured defects.**
  Reported only as "checkout issue on iPhone", with no symptom named, so the
  page was served locally and its geometry measured in a real browser at
  360/375/430/1280 px rather than guessed at.
  (1) **The pay button rendered as a 3-line stack.** Its flex row was
  `nowrap`, so the line could not break *between* items and squeezed the label
  instead тАФ ┬л╨Ъ ╨▒╨╡╨╖╨╛╨┐╨░╤Б╨╜╨╛╨╣ ╨╛╨┐╨╗╨░╤В╨╡┬╗ wrapped *inside words* to three lines and
  the primary CTA stood **116 px** tall against an intended ~60. Reproduced on
  **both** 360 px Android and 375 px iPhone; now one line and 88 px at 360 px
  (56 px at 430 px), with desktop untouched.
  (2) **The cookie bar sat on top of the bottom of every page.** It is
  `fixed bottom-0 z-[200]` and stacks to a column on mobile тАФ **164 px, 24.6 %
  of a 375├Ч667 viewport** тАФ while `body` had no compensating padding. It now
  reserves its own height while open and releases it on dismiss.
  (3) **The pay button could hang forever.** The pre-submit CSRF `fetch` had
  no timeout and the button is disabled *before* the await, so a single
  stalled mobile connection left it permanently dead. Now bounded by a 4 s
  `AbortController`; submission proceeds either way.
  (4) **The prana slider was unusable by touch.** The `input[type=range]` box
  *was* its 8 px visual track тАФ about 18 % of the 44 px iOS minimum тАФ with no
  `touch-action`, so an imprecise drag resolved as a page scroll. Now a 44 px
  hit area with the track still drawn at 8 px.
  Also: the trust row was `hidden sm:flex`, hiding the ╨Ь╨Ш╨а card list **and the
  page's only link to the refund policy** on every phone тАФ it now wraps
  instead of hiding, and the slider thumb's `:hover` scale moved behind
  `@media (hover: hover)` so it stops sticking after a touch drag.
  No server-side or payment logic changed; the checkout POST path was
  re-verified end to end (guest user + `Payment` row created).

## [1.44.0] - 2026-07-20

### Added
- **H1358: `payments:expire-stale-checkouts` тАФ abandoned-checkout reaper.**
  Pending `Payment` rows created at checkout provisionally hold resources
  (prana spend, applied referral credit, consumed deposit credit, promo-code
  usage slots) with nothing ever releasing them if the buyer just abandons the
  bank tab тАФ worse, `PaymentController` then hard-blocks a second checkout
  attempt for the same course while the abandoned row sits there. The
  reversal logic already existed (`Payment::booted()`'s `updated` listener
  refunds prana/referral credit and restores deposits/promo slots on any
  `pending тЖТ failed/canceled` transition) тАФ it just had no trigger for
  silently-abandoned orders. The new command finds stale `pending` payments
  (timed promo reservations use `PromoCode::WEBHOOK_BUFFER_MINUTES` as
  authoritative; everything else uses the new
  `config('checkout.legacy_pending_days')`, default 30 days тАФ deliberately
  wide, mirroring `AuditCheckoutIntegrity`'s own caution that these rows want
  manual-review-grade care before cancellation) and flips them to `failed`
  under a per-row `lockForUpdate()` transaction тАФ the same idempotency
  pattern `WebhookController` uses тАФ so a bank webhook landing in the same
  instant always wins the race instead of getting reaped. Deposit/trial,
  PayPal-pending, and conditional rows are hard-excluded (not abandoned
  checkouts in the same sense). Scheduled every 15 minutes with `--apply`
  (`Kernel::schedule`); without `--apply` the command only reports what it
  would fail, independent of the gate. Live runs require the new
  deploy-╤А╤Г╨▒╨╕╨╗╤М╨╜╨╕╨║ `features.checkout_stale_order_expiry` (off by default,
  `CHECKOUT_STALE_ORDER_EXPIRY=true` + `config:cache` to enable).

### Fixed
- **H1355: CI green + enforcing тАФ flaky VK deep-link assertion, secretless
  Deploy-production job, 110 hidden Pint violations.** Three CI gaps: (1)
  `VkAuthTokenLinkingTest`'s substring-based `ref` assertion could collide by
  chance against a random 32-char token тАФ now an exact query-param compare;
  (2) `.github/workflows/deploy.yml`'s "Deploy production" job painted `main`
  red on every push because prod SSH secrets aren't set yet (H478 gate) тАФ now
  skips cleanly (success, not failure) until a human wires them; (3)
  `.github/workflows/ci.yml`'s Pint step had `continue-on-error: true` hiding
  96 files of violations тАФ fixed and made enforcing. Pint's own auto-fix
  introduced a real bug (moved `use` imports below their first `::class`
  usage in `routes/api.php`/`scripts/export-analytics-tables.php`, silently
  resolving to the wrong namespace) тАФ caught by the full suite and fixed by
  hand.

## [1.43.0] - 2026-07-20

### Added
- **H1356: frequency-ranked root drills (top-25/50/100) in `public/lila/roots/`.**
  New match-family exercise pairing each Sanskrit verbal root (deva + IAST hint)
  with its most frequent attested form (RU gloss as hint), banded by DCS corpus
  frequency (top-25 flat, top-50/100 random-10-per-round, mirroring the
  `ligatures/` D6 pattern). Data is generated from the already-committed
  570-root RU fixture (`database/seeders/data/roots_frequency_ru.tsv`, H1280) by
  a newly **committed** generator (`scripts/build_root_drill_data.py`, with a
  `--check` drift mode) тАФ closing the gap the ligatures family left open (its
  equivalent exporter was never committed). Registered as a new family card on
  `public/lila/index.html`; anti-drift coverage in
  `tests/Feature/Exercises/RootDrillPagesTest.php`.

## [1.42.0] - 2026-07-20

### Added
- **H1290: installments тАФ the no-shame ┬л╤А╨░╨╖╨▒╨╕╤В╤М ╨╜╨░ ╤З╨░╤Б╤В╨╕┬╗ checkout ask.**
  ╨Ы╨╡╨╣╨╜ ╨▓╨╛╨╗╨╜╤Л revenue-copy
  ([╨┐╨╗╨░╨╜](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  ╨Ь╨╡╤Е╨░╨╜╨╕╨║╨░ ╤А╨░╤Б╤Б╤А╨╛╤З╨║╨╕ (`InstallmentPlanCreator` + `PaymentPromise`) ╨▒╤Л╨╗╨░
  ╨┐╨╛╨╗╨╜╨╛╤Б╤В╤М╤О ╨╜╨╡╨▓╨╕╨┤╨╕╨╝╨░ ╤Б╤В╤Г╨┤╨╡╨╜╤В╤Г тАФ ╨┐╤А╤П╨╝╨╛╨╣ ╨╛╤В╨▓╨╡╤В ╨╜╨░ ╨▓╨╛╨╖╤А╨░╨╢╨╡╨╜╨╕╨╡ ╨ж╨Х╨Э╨Р ╤Б╤Г╤Й╨╡╤Б╤В╨▓╨╛╨▓╨░╨╗
  ╤В╨╛╨╗╤М╨║╨╛ ╨╜╨░ ╤Б╤В╨╛╤А╨╛╨╜╨╡ ╨║╤Г╤А╨░╤В╨╛╤А╨░. ╨в╨╡╨┐╨╡╤А╤М ╨╜╨░ ╤З╨╡╨║╨░╤Г╤В╨╡ ╨┐╨╛╨┤ ╨║╨╜╨╛╨┐╨║╨╛╨╣ ╨╛╨┐╨╗╨░╤В╤Л тАФ ╤В╨╕╤Е╨░╤П
  ╤В╨╛╤З╨║╨░ ╨▓╤Е╨╛╨┤╨░ ┬л╨Э╤Г╨╢╨╜╨╛ ╤А╨░╨╖╨▒╨╕╤В╤М ╨╛╨┐╨╗╨░╤В╤Г ╨╜╨░ ╤З╨░╤Б╤В╨╕?┬╗
  ([`partials/installments-cta`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/installments-cta.blade.php)):
  ╤Д╨╛╤А╨╝╨░ ╨╖╨░╨┐╤А╨╛╤Б╨░ ╨╖╨╛╨▓╤С╤В ╨║╤Г╤А╨░╤В╨╛╤А╨░ ╤З╨╡╤А╨╡╨╖ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╣ `CuratorNotifier`
  (Telegram-╤З╨░╤В) ╨╕ ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨░╨╡╤В ╤Б╤В╤Г╨┤╨╡╨╜╤В╤Г ╨╜╨░ ╨╝╨╡╤Б╤В╨╡ (┬л╨┐╨╗╨░╤В╨╕╤В╤М ╤Б╨╡╨╣╤З╨░╤Б ╨╜╨╕╤З╨╡╨│╨╛ ╨╜╨╡
  ╨╜╤Г╨╢╨╜╨╛┬╗). **╨Ч╨░╨┐╤А╨╛╤Б ╨╜╨╡ ╤Б╨╛╨╖╨┤╨░╤С╤В ╨╜╨╕ `PaymentPromise`, ╨╜╨╕ ╤А╨░╤Б╤Б╤А╨╛╤З╨║╤Г, ╨╜╨╕
  ╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╤В╨╡╨╗╤П** (ruling D6 тАФ ╤Г╤Б╨╗╨╛╨▓╨╕╤П ╤Б╨╛╨│╨╗╨░╤Б╤Г╨╡╤В ╨║╤Г╤А╨░╤В╨╛╤А ╨▓ ╤А╨░╨╝╨║╨░╤Е ╨╗╨╕╨╝╨╕╤В╨╛╨▓
  ╤Д╨╕╨╜╨┤╨╕╤А╨░); ╨▒╨╗╨╛╨║ ╤Б╨║╤А╤Л╤В, ╨╡╤Б╨╗╨╕ ╨║╤Г╤А╨░╤В╨╛╤А╤Б╨║╨╕╨╣ ╤З╨░╤В ╨╜╨╡ ╨╜╨░╤Б╤В╤А╨╛╨╡╨╜. ╨а╨╡╨│╨╕╤Б╤В╤А: ┬л╨╛╨┐╨╗╨░╤В╨░ ╨┐╨╛
  ╤З╨░╤Б╤В╤П╨╝┬╗ ╨▓╨╝╨╡╤Б╤В╨╛ ╨║╤А╨╡╨┤╨╕╤В╨╜╨╛-╨║╨╛╨╜╨╜╨╛╤В╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛╨╣ ┬л╤А╨░╤Б╤Б╤А╨╛╤З╨║╨╕┬╗, ╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╨░╨╜╨╕╨╡ ╨▓╨╝╨╡╤Б╤В╨╛
  ╤Г╤Б╤В╤Г╨┐╨║╨╕, ╨╜╨╕╨║╨░╨║╨╕╤Е ╨▓╤Л╨┤╤Г╨╝╨░╨╜╨╜╤Л╤Е ╤Г╤Б╨╗╨╛╨▓╨╕╨╣. ╨б╤В╤А╨╛╨║╨╕ ╨╕ 9 unattended-╤А╨╡╤И╨╡╨╜╨╕╨╣:
  [`docs/copy/money-installments-no-shame-checkout-ask.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-installments-no-shame-checkout-ask.md);
  7 ╨╜╨╛╨▓╤Л╤Е feature-╤В╨╡╤Б╤В╨╛╨▓
  ([`tests/Feature/InstallmentRequestTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/InstallmentRequestTest.php)).

## [1.41.0] - 2026-07-20

### Added
- **H1293: ╨╗╨╡╤Б╨╡╨╜╨║╨░ ╤Ж╨╡╨╜ ╤Б ╨┐╨╛╨╖╨╕╤Ж╨╕╨╛╨╜╨╕╤А╨╛╨▓╨░╨╜╨╕╨╡╨╝ ╨╜╨░ ╨▓╨╕╤В╤А╨╕╨╜╨╡ `/online`.** ╨Ы╨╡╨╣╨╜ ╨▓╨╛╨╗╨╜╤Л
  revenue-copy
  ([╨┐╨╗╨░╨╜](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  ╨Т╨╕╤В╤А╨╕╨╜╨░ ╨╜╨╡ ╨┐╨╛╨║╨░╨╖╤Л╨▓╨░╨╗╨░ ╨╜╨╕ ╨╛╨┤╨╜╨╛╨╣ ╤Ж╨╡╨╜╤Л, ╤Б╤А╨░╨▓╨╜╨╕╤В╤М ╨▒╨╗╨╛╨║ ╤Б ╤Ж╨╡╨╗╤Л╨╝ ╨║╤Г╤А╤Б╨╛╨╝ ╨▒╤Л╨╗╨╛
  ╨╜╨╡╨│╨┤╨╡. ╨в╨╡╨┐╨╡╤А╤М ╤Б╨╡╨║╤Ж╨╕╤П ┬л╨б╨║╨╛╨╗╤М╨║╨╛ ╤Б╤В╨╛╨╕╤В ╨╛╨▒╤Г╤З╨╡╨╜╨╕╨╡┬╗ (`#ceny`) + ╨║╨╜╨╛╨┐╨║╨░-╤П╨║╨╛╤А╤М
  ┬л╨Ъ╨░╨║ ╤Г╤Б╤В╤А╨╛╨╡╨╜╤Л ╤Ж╨╡╨╜╤Л┬╗ ╨╜╨░ ╨┐╨╡╤А╨▓╨╛╨╝ ╤Н╨║╤А╨░╨╜╨╡: ╤В╤А╨╕ ╤Д╨╛╤А╨╝╨░╤В╨░ ╤Б ╨┐╨╛╨╖╨╕╤Ж╨╕╨╛╨╜╨╕╤А╨╛╨▓╨░╨╜╨╕╨╡╨╝ тАФ
  ╨║╨╛╨╝╤Г ╨┐╨╛╨┤╤Е╨╛╨┤╨╕╤В, ╤З╤В╨╛ ╨╝╨╡╨╜╤П╨╡╤В╤Б╤П ╨╝╨╡╨╢╨┤╤Г ╤Б╤В╤Г╨┐╨╡╨╜╤П╨╝╨╕, ╤З╤В╨╛ ╨▓╤Л╨▒╤А╨░╤В╤М, ╨╡╤Б╨╗╨╕ ╨╜╨╡ ╤Г╨▓╨╡╤А╨╡╨╜╤Л;
  ╤З╨╡╤Б╤В╨╜╤Л╨╡ ┬л╨╛╤В N тВ╜┬╗ ╤В╨╛╨╗╤М╨║╨╛ ╨╕╨╖ `ProductLadderAnchors` (╤Е╨╡╨╗╨┐╨╡╤А ╤А╨░╤Б╤И╨╕╤А╨╡╨╜ read-only
  ╤П╨║╨╛╤А╨╡╨╝ `minLiveFullPrice` тАФ ╨╢╨╕╨▓╨╛╨╣ ╨║╤Г╤А╤Б ╤Ж╨╡╨╗╨╕╨║╨╛╨╝), ╤Б╤А╨░╨▓╨╜╨╡╨╜╨╕╨╡ ┬л╨▒╨╗╨╛╨║ ╨┐╤А╨╛╤В╨╕╨▓
  ╤Ж╨╡╨╗╨╛╨│╨╛ ╨║╤Г╤А╤Б╨░┬╗, JSON-LD `OfferCatalog` ╨╕╨╖ `AggregateOffer`. ╨Э╨╕ ╨╛╨┤╨╜╨╛╨╣
  ╨╖╨░╤Е╨░╤А╨┤╨║╨╛╨╢╨╡╨╜╨╜╨╛╨╣ ╤Ж╨╡╨╜╤Л (grep-floor ╨╗╨╡╨╣╨╜╨░); ╨╜╨╡╤В ╤В╨░╤А╨╕╤Д╨╛╨▓ тАФ ╤З╨╕╤Б╨╗╨░ ╨╕ schema-╨╜╨╛╨┤╨░
  ╨╜╨╡ ╤А╨╡╨╜╨┤╨╡╤А╤П╤В╤Б╤П, ╤Б╨╡╨║╤Ж╨╕╤П ╨╛╤Б╤В╨░╨╡╤В╤Б╤П. ╨Ъ╨╛╨┐╨╕-╨┤╨╛╨║:
  [docs/copy/money-price-ladder-narrative-page.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-price-ladder-narrative-page.md).

## [1.40.0] - 2026-07-20

### Added
- **H1292: ╨┤╨╕╨░╤Б╨┐╨╛╤А╨╜╤Л╨╣ ╨┐╤Г╤В╤М ╨╛╨┐╨╗╨░╤В╤Л тАФ PayPal-╨┐╤Г╤В╤М ╨┐╨╛╨╗╤Г╤З╨╕╨╗ ╤В╨░╨╣╨╝╨╕╨╜╨│╨╕ ╨╕ ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╕╨╡ ╤Б╤В╤Г╨┤╨╡╨╜╤В╤Г.**
  ╨Ы╨╡╨╣╨╜ ╨▓╨╛╨╗╨╜╤Л revenue-copy
  ([╨┐╨╗╨░╨╜](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  ╨Ф╨╗╤П ╨┤╨╕╨░╤Б╨┐╨╛╤А╨╜╨╛╨│╨╛ ╨┐╨╛╨║╤Г╨┐╨░╤В╨╡╨╗╤П PayPal-╨╖╨░╤П╨▓╨║╨░ тАФ ╨╡╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ ╤А╨░╨▒╨╛╤З╨╕╨╣ ╨┐╤Г╤В╤М, ╨╜╨╛ CTA
  ╨╛╨▒╨╡╤Й╨░╨╗ ╤В╨╛╨╗╤М╨║╨╛ ┬л╨┤╨╛╤Б╤В╤Г╨┐ ╨╛╤В╨║╤А╨╛╨╡╨╝ ╨┐╨╛╤Б╨╗╨╡ ╤Б╨▓╨╡╤А╨║╨╕ ╨┐╨╗╨░╤В╨╡╨╢╨░┬╗ (╨▒╨╡╨╖ ╤Б╤А╨╛╨║╨░ ╨╕ ╨┐╤А╨╕╤З╨╕╨╜╤Л), ╨░
  ╨┐╨╕╤Б╤М╨╝╨╛ ╨╛ ╨╖╨░╤П╨▓╨║╨╡ ╤Г╤Е╨╛╨┤╨╕╨╗╨╛ ╤В╨╛╨╗╤М╨║╨╛ ╨░╨┤╨╝╨╕╨╜╤Г тАФ ╤Б╤В╤Г╨┤╨╡╨╜╤В ╨╜╨╡ ╨┐╨╛╨╗╤Г╤З╨░╨╗ ╨╜╨╕╤З╨╡╨│╨╛ (finding F3
  ╨░╤Г╨┤╨╕╤В╨░ [CHECKOUT_PURCHASE_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md)).
  ╨в╨╡╨┐╨╡╤А╤М CTA ╨╕ ╤Д╨╛╤А╨╝╨░-╨╖╨░╤П╨▓╨║╨░ ╨╜╨░╨╖╤Л╨▓╨░╤О╤В ╤Б╤А╨╛╨║ (┬л╨╛╨▒╤Л╤З╨╜╨╛ ╨▓ ╤В╨╡╤З╨╡╨╜╨╕╨╡ ╨╛╨┤╨╜╨╛╨│╨╛ ╤А╨░╨▒╨╛╤З╨╡╨│╨╛
  ╨┤╨╜╤П┬╗) ╨╕ ╨┐╤А╨╕╤З╨╕╨╜╤Г ╤А╤Г╤З╨╜╨╛╨╣ ╤Б╨▓╨╡╤А╨║╨╕; ╤Б╤В╤А╨░╨╜╨╕╤Ж╨░ ╨╖╨░╤П╨▓╨║╨╕ ╨┐╨╛╨╗╤Г╤З╨╕╨╗╨░ ╨▒╨╗╨╛╨║ ┬л╨з╤В╨╛ ╨▒╤Г╨┤╨╡╤В
  ╨┤╨░╨╗╤М╤И╨╡┬╗ ╨╕ ╤Б╨╜╤П╤В╨╕╨╡ ╤Б╤В╤А╨░╤Е╨░ ╨┤╨▓╨╛╨╣╨╜╨╛╨│╨╛ ╤Б╨┐╨╕╤Б╨░╨╜╨╕╤П (╨╛╨▒╤Й╨░╤П ╤Б╤В╤А╨╛╨║╨░ 2) ╨╜╨░╨▓╨╡╤А╤Е╤Г; ╤Б╤В╤Г╨┤╨╡╨╜╤В
  ╨▓╨┐╨╡╤А╨▓╤Л╨╡ ╨┐╨╛╨╗╤Г╤З╨░╨╡╤В ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╕╨╡ тАФ ╨╜╨╛╨▓╤Л╨╣
  [`PaypalClaimStudentAckMail`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/PaypalClaimStudentAckMail.php)
  (╨░╨┤╨╝╨╕╨╜╤Б╨║╨╕╨╣ `PaypalClaimReceivedMail` ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В). ╨д╨╕╤З╨░ ╨╛╤Б╤В╨░╨╡╤В╤Б╤П ╨╖╨░ ╨▓╤Л╨║╨╗╤О╤З╨╡╨╜╨╜╤Л╨╝
  `PAYPAL_CLAIM_ENABLED` (╨┐╤А╨╛╨┤), ╨┐╤А╨╛╨┤-SMTP ╤Б╨╗╨╛╨╝╨░╨╜ (#504) тАФ ╨▓╤Б╨╡ ╨╕╨╜╨╡╤А╤В╨╜╨╛ ╨┤╨╛
  ╨▓╨║╨╗╤О╤З╨╡╨╜╨╕╤П. ╨б╤В╤А╨╛╨║╨╕ ╨╕ 6 ╨╜╨╡╨┐╨╡╤А╨╡╨┤╨░╨╜╨╜╤Л╤Е ╤А╨╡╤И╨╡╨╜╨╕╨╣:
  [`docs/copy/money-diaspora-paypal-buyer-path.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-diaspora-paypal-buyer-path.md);
  5 ╨╜╨╛╨▓╤Л╤Е feature-╤В╨╡╤Б╤В╨╛╨▓ ╨▓
  [`tests/Feature/PaypalClaimTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/PaypalClaimTest.php).

## [1.39.0] - 2026-07-20

### Added
- **H1289: dunning тАФ ╨╛╨┤╨╜╨╛ ╨╜╨░╨┐╨╛╨╝╨╕╨╜╨░╨╜╨╕╨╡ ╨┤╨╛╨╗╨╢╨╜╨╕╨║╤Г ╤Б╤В╨░╨╗╨╛ ╨╗╨╡╤Б╤В╨╜╨╕╤Ж╨╡╨╣ ╨╕╨╖ ╤З╨╡╤В╤Л╤А╤С╤Е ╤Б╤В╨░╨┤╨╕╨╣.**
  ╨Ы╨╡╨╣╨╜ ╨▓╨╛╨╗╨╜╤Л revenue-copy
  ([╨┐╨╗╨░╨╜](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  `debts:remind` ╤Б╨╗╨░╨╗ ╨╛╨┤╨╕╨╜ ╨╕ ╤В╨╛╤В ╨╢╨╡ ╤И╨░╨▒╨╗╨╛╨╜ ╨┐╨╛╨▓╤В╨╛╤А╨╜╨╛, ╨▒╨╡╨╖ ╤Н╤Б╨║╨░╨╗╨░╤Ж╨╕╨╕ тАФ ╨╛╨┤╨╜╨░
  ╤В╨╡╨╝╨┐╨╡╤А╨░╤В╤Г╤А╨░ ╨╗╨╕╨▒╨╛ ╨┐╨╕╨╗╨╕╤В ╤А╨░╨╜╨╛, ╨╗╨╕╨▒╨╛ ╤И╨╛╨║╨╕╤А╤Г╨╡╤В ╨┐╨╛╨╖╨┤╨╜╨╛. ╨в╨╡╨┐╨╡╤А╤М ╤Б╤В╨░╨┤╨╕╤П ╨▓╤Л╨▒╨╕╤А╨░╨╡╤В╤Б╤П ╨┐╨╛
  ╨┤╨╡╨┤╨╗╨░╨╣╨╜╤Г ╨╛╨┐╨╗╨░╤В╤Л (00:00 ╨Ь╨б╨Ъ ╨┤╨╜╤П ╤Б╤В╨░╤А╤В╨░ ╨▒╨╗╨╛╨║╨░): ╨╝╤П╨│╨║╨╛╨╡ ╨╜╨░╨┐╨╛╨╝╨╕╨╜╨░╨╜╨╕╨╡ тЖТ ┬л╨┤╨╡╨┤╨╗╨░╨╣╨╜
  ╨▒╨╗╨╕╨╖╨║╨╛┬╗ (╨╖╨░ 3 ╨┤╨╜╤П) тЖТ ┬л╨┤╨╛╤Б╤В╤Г╨┐ ╨┐╨╛╨┤ ╤Г╨│╤А╨╛╨╖╨╛╨╣┬╗ (╨┐╨╛╤Б╨╗╨╡ ╨┤╨╡╨┤╨╗╨░╨╣╨╜╨░) тЖТ ┬л╨┤╨╛╤Б╤В╤Г╨┐ ╨╖╨░╨║╤А╤Л╤В┬╗
  (╤Б 14-╨│╨╛ ╨┤╨╜╤П ╨┐╤А╨╛╤Б╤А╨╛╤З╨║╨╕); ╨┐╨╛╤А╨╛╨│╨╕ тАФ
  [`config/dunning.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/dunning.php),
  ╤В╨╡╨║╤Б╤В╤Л тАФ [`app/Support/DunningStage.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/DunningStage.php)
  ╤Б ╨┐╨╡╤А╨╡╨╛╨┐╤А╨╡╨┤╨╡╨╗╨╡╨╜╨╕╨╡╨╝ ╨╝╨╡╨╜╨╡╨┤╨╢╨╡╤А╨╛╨╝ ╨▓ Marketing Settings (╨░╨┤╨┤╨╕╤В╨╕╨▓╨╜╨░╤П ╨╝╨╕╨│╤А╨░╤Ж╨╕╤П, 6
  nullable-╨║╨╛╨╗╨╛╨╜╨╛╨║). ╨б╤В╤А╨╛╨║╨░ ┬л╨Х╤Б╨╗╨╕ ╨╛╨┐╨╗╨░╤В╨░ ╤Г╨╢╨╡ ╨▓╨╜╨╡╤Б╨╡╨╜╨░ тАФ ╨┐╤А╨╛╤Б╤В╨╛ ╨┐╤А╨╛╨╕╨│╨╜╨╛╤А╨╕╤А╤Г╨╣╤В╨╡тАж┬╗
  ╤Б╨╛╤Е╤А╨░╨╜╨╡╨╜╨░ ╨▓╨╛ ╨▓╤Б╨╡╤Е ╤З╨╡╤В╤Л╤А╤С╤Е ╤Б╤В╨░╨┤╨╕╤П╤Е; ╤Д╤А╨░╨│╨╝╨╡╨╜╤В `{deadline}` ╤Б╤В╨░╨╗ ╤З╨╡╤Б╤В╨╜╤Л╨╝ ╨┐╨╛
  ╨▓╤А╨╡╨╝╨╡╨╜╨╕ (┬л╤Б╤А╨╛╨║ ╨╛╨┐╨╗╨░╤В╤Л ╨╕╤Б╤В╨╡╨║тАж┬╗ ╨▓╨╝╨╡╤Б╤В╨╛ ┬л╨╜╤Г╨╢╨╜╨╛ ╨┤╨╛ <╨┐╤А╨╛╤И╨╡╨┤╤И╨╡╨╣ ╨┤╨░╤В╤Л>┬╗); ╤Б╤В╨░╨┤╨╕╤П 4
  ╤З╨╡╤Б╤В╨╜╨░ ╨┐╨╛ ╨╝╨╡╤Е╨░╨╜╨╕╨║╨╡ (╨╝╨░╤В╨╡╤А╨╕╨░╨╗╤Л ╨▒╨╗╨╛╨║╨░ ╨╖╨░╨║╤А╤Л╤В╤Л ╨░╨▓╤В╨╛╨╝╨░╤В╨╕╤З╨╡╤Б╨║╨╕, ┬л╤Н╤В╨╛ ╨╜╨╡
  ╨╛╤В╤З╨╕╤Б╨╗╨╡╨╜╨╕╨╡┬╗ ╨▓╨╡╤А╨╜╨╛ ╨┐╨╛ ╨┐╨╛╤Б╤В╤А╨╛╨╡╨╜╨╕╤О ╨▓╤Л╨▒╨╛╤А╨║╨╕ ╨┤╨╛╨╗╨╢╨╜╨╕╨║╨╛╨▓). Win-back ╨┐╨╛╤Б╨╗╨╡ ╨╛╤В╤З╨╕╤Б╨╗╨╡╨╜╨╕╤П
  ╨╜╨╡ ╤В╤А╨╛╨╜╤Г╤В (╤В╨╡╤А╤А╨╕╤В╨╛╤А╨╕╤П H219). ╨б╤В╤А╨╛╨║╨╕ ╨╕ ╤А╨╡╤И╨╡╨╜╨╕╤П:
  [`docs/copy/money-dunning-escalation-ladder.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-dunning-escalation-ladder.md);
  7 ╨╜╨╛╨▓╤Л╤Е feature-╤В╨╡╤Б╤В╨╛╨▓
  ([`tests/Feature/DunningEscalationLadderTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/DunningEscalationLadderTest.php)).

## [1.38.0] - 2026-07-20

### Added
- **H1286: ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╕╨╡ ╨┐╨╛╨║╤Г╨┐╨║╨╕ + ╨╛╨╜╨▒╨╛╤А╨┤╨╕╨╜╨│ ╨┐╨╡╤А╨▓╨╛╨╣ ╨╜╨╡╨┤╨╡╨╗╨╕.** ╨Ы╨╡╨╣╨╜ ╨▓╨╛╨╗╨╜╤Л
  revenue-copy
  ([╨┐╨╗╨░╨╜](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  ╨б╤А╨╡╨┤╨╕ 24 mailable ╨╜╨╕ ╨╛╨┤╨╕╨╜ ╨╜╨╡ ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨░╨╗ ╨┐╨╛╨║╤Г╨┐╨║╤Г тАФ ╨╝╨╛╨╝╨╡╨╜╤В ┬л╨▓╤Л ╨▓ ╨┤╨╡╨╗╨╡┬╗ ╨╕╤Б╤З╨╡╤А╨┐╤Л╨▓╨░╨╗╤Б╤П
  flash-╤Б╤В╤А╨╛╨║╨╛╨╣. ╨в╨╡╨┐╨╡╤А╤М: `PurchaseConfirmationMail` тАФ ╤З╨╡╨║-╨┐╤А╨╕╨▓╨╡╤В╤Б╤В╨▓╨╕╨╡ ╨╜╨░ ╨║╨░╨╢╨┤╤Г╤О ╤А╨╡╨░╨╗╤М╨╜╤Г╤О
  ╨╛╨┐╨╗╨░╤В╤Г (╤З╤В╨╛ ╨║╤Г╨┐╨╗╨╡╨╜╨╛, ╤В╨░╤А╨╕╤Д, ╤Б╤Г╨╝╨╝╨░, ┬л╨┤╨╛╤Б╤В╤Г╨┐ ╨╛╤В╨║╤А╨╛╨╡╤В╤Б╤П ╨▓ ╤В╨╡╤З╨╡╨╜╨╕╨╡ ╨┐╨░╤А╤Л ╨╝╨╕╨╜╤Г╤В┬╗ тАФ ╨╛╨▒╤Й╨░╤П
  ╤Б╤В╤А╨╛╨║╨░ 1 ╨▓╨╛╨╗╨╜╤Л, ╤Б╤Б╤Л╨╗╨║╨░ ╨╜╨░ ╨║╨░╤Б╤Б╨╛╨▓╤Л╨╣ ╤З╨╡╨║ ╨┐╨╗╨░╤В╨╡╨╢╨╜╨╛╨╣ ╤Б╨╕╤Б╤В╨╡╨╝╤Л); `OnboardingDay1Mail` /
  `OnboardingDay5Mail` тАФ ┬л╤Б ╤З╨╡╨│╨╛ ╨╜╨░╤З╨░╤В╤М┬╗ ╨╕ ╨╝╤П╨│╨║╨╕╨╣ ╤З╨╡╨║-╨╕╨╜ ╨▒╨╡╨╖ ╨▓╨╕╨╜╤Л. Email-╨║╨░╨╜╨░╨╗ ╨╕╨╜╨╡╤А╤В╨╡╨╜
  ╨┤╨╛ ╨┐╨╛╤З╨╕╨╜╨║╨╕ ╨┐╤А╨╛╨┤-SMTP ([#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504),
  ESP-╨│╨╡╨╣╤В H1147) тАФ send-╤Б╨░╨╣╤В╨░ ╤Г ╨┤╨╜╨╡╨╣ 1/5 ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛ ╨╜╨╡╤В (╨┐╤А╨╡╤Ж╨╡╨┤╨╡╨╜╤В ╨╝╨░╤А╨░╤Д╨╛╨╜╤Б╨║╨╕╤Е ╨┐╨╕╤Б╨╡╨╝);
  ╤А╨░╨▒╨╛╤З╨░╤П ╨┤╨╛╤Б╤В╨░╨▓╨║╨░ ╨┤╨╜╨╡╨╣ 1/5 ╤Г╨╢╨╡ ╤Б╨╡╨╣╤З╨░╤Б тАФ Telegram/VK ╤З╨╡╤А╨╡╨╖ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╣ `ScheduledReminder`
  (╨┐╨╡╤А╨▓╨░╤П ╨╛╨┐╨╗╨░╤В╨░ ╨║╨╛╨╜╨║╤А╨╡╤В╨╜╨╛╨│╨╛ ╨║╤Г╤А╤Б╨░, ╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╜╨╛). `grantAccess()` ╨╕ ╨┐╤Г╤В╤М ╨┐╤А╨╛╨▓╨░╨╣╨┤╨╡╤А╨░ ╨╜╨╡
  ╤В╤А╨╛╨╜╤Г╤В╤Л. ╨б╤В╤А╨╛╨║╨╕ ╨╕ 8 ╤А╨╡╤И╨╡╨╜╨╕╨╣:
  [`docs/copy/money-purchase-confirmation-onboarding-seq.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-purchase-confirmation-onboarding-seq.md);
  10 ╨╜╨╛╨▓╤Л╤Е ╤В╨╡╤Б╤В╨╛╨▓ (╨┤╨╕╤Б╨┐╨░╤В╤З ╨┐╨╛╨┤ `Mail::fake()`, ╤А╨╡╨╜╨┤╨╡╤А ╤Б ╤А╨╡╨░╨╗╤М╨╜╤Л╨╝╨╕ ╨╖╨╜╨░╤З╨╡╨╜╨╕╤П╨╝╨╕, ╨║╨╛╨╜╤В╤А╨░╨║╤В
  ╨│╨╛╨╗╨╛╤Б╨░: ╤Н╨╝╨╛╨┤╨╖╨╕/╤Б╤А╨╛╤З╨╜╨╛╤Б╤В╤М/╤С).

## [1.37.0] - 2026-07-20

### Added
- **H1288: ╨▓╨╛╨╖╨▓╤А╨░╤В тАФ ╤Б╤В╤А╨░╨╜╨╕╤Ж╨░ `/vozvrat` ╨┐╨╛╨▓╨╡╤А╤Е ╨╛╤Д╨╡╤А╤В╤Л.** ╨Ы╨╡╨╣╨╜ ╨▓╨╛╨╗╨╜╤Л revenue-copy
  ([╨┐╨╗╨░╨╜](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  Trust row ╤З╨╡╨║╨░╤Г╤В╨░ ╨╛╨▒╨╡╤Й╨░╨╗ ┬л╨Т╨╛╨╖╨▓╤А╨░╤В ╨┐╨╛ ╨╛╤Д╨╡╤А╤В╨╡┬╗ ╨╕ ╨╜╨╡ ╨▓╨╡╨╗ ╨╜╨╕╨║╤Г╨┤╨░; ╨┐╨╛╤А╤П╨┤╨╛╨║ ╨▓╨╛╨╖╨▓╤А╨░╤В╨░
  ╤Б╤Г╤Й╨╡╤Б╤В╨▓╨╛╨▓╨░╨╗ ╤В╨╛╨╗╤М╨║╨╛ ╨▓╨╜╤Г╤В╤А╨╕ 8-╤Б╤В╤А╨░╨╜╨╕╤З╨╜╨╛╨│╨╛ PDF. ╨Э╨╛╨▓╨░╤П ╤Б╤В╤А╨░╨╜╨╕╤Ж╨░
  ([`resources/views/docs/vozvrat.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/docs/vozvrat.blade.php))
  ╨╕╨╖╨╗╨░╨│╨░╨╡╤В ╨┐╤А╨╕╨╗╨╛╨╢╨╡╨╜╨╕╨╡ тДЦ1 ╨╛╤Д╨╡╤А╤В╤Л ╨▓ ╤В╨╛╤З╨╜╤Л╤Е ╤В╨╡╤А╨╝╨╕╨╜╨░╤Е тАФ 100%-╤Б╨╗╤Г╤З╨░╨╕, ╤Д╨╛╤А╨╝╤Г╨╗╨░
  ╤З╨░╤Б╤В╨╕╤З╨╜╨╛╨│╨╛ ╨▓╨╛╨╖╨▓╤А╨░╤В╨░, ╨┐╨╛╨╗╤П ╨╖╨░╤П╨▓╨╗╨╡╨╜╨╕╤П, 10 (╨┤╨╡╤Б╤П╤В╨╕) ╤А╨░╨▒╨╛╤З╨╕╤Е ╨┤╨╜╨╡╨╣ тАФ ╤Б mailto-╨║╨╜╨╛╨┐╨║╨╛╨╣
  ╨╖╨░╤П╨▓╨╗╨╡╨╜╨╕╤П (╤В╨╡╨╝╨░ ╨╕ ╤В╨╡╨╗╨╛ ╨┐╤А╨╡╨┤╨╖╨░╨┐╨╛╨╗╨╜╨╡╨╜╤Л ╨┐╨╛╨╗╤П╨╝╨╕ ┬з4.2) ╨╕ ╤Б╤Б╤Л╨╗╨║╨░╨╝╨╕ ╨╜╨░ ╨┐╨╛╨╗╨╜╤Л╨╣ PDF.
  Trust row ╤В╨╡╨┐╨╡╤А╤М ╤Б╤Б╤Л╨╗╨░╨╡╤В╤Б╤П ╨╜╨░ ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Г ╤Б╨╛ ╤Б╤В╤А╨╛╨║╨╛╨╣ ┬л╨Т╨╛╨╖╨▓╤А╨░╤В: ╨┤╨╛ ╨╜╨░╤З╨░╨╗╨░ тАФ 100%┬╗
  (╨╛╨▒╤Й╨░╤П ╤Б╤В╤А╨╛╨║╨░ 4 ╨▓╨╛╨╗╨╜╤Л, ╨╛╨┐╤А╨╡╨┤╨╡╨╗╨╡╨╜╨░ ╨▓
  [`docs/copy/_shared_strings.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/_shared_strings.md));
  ╨┐╨╛╨┤╨▓╨░╨╗ ╨▓╤Б╨╡╤Е ╨┐╤П╤В╨╕ layout'╨╛╨▓ ╨┐╨╛╨╗╤Г╤З╨╕╨╗ ╤Б╤Б╤Л╨╗╨║╤Г ┬л╨г╤Б╨╗╨╛╨▓╨╕╤П ╨▓╨╛╨╖╨▓╤А╨░╤В╨░┬╗ ╤З╨╡╤А╨╡╨╖ ╨┐╨░╤А╤В╨╕╨░╨╗
  `footer-docs`. Term-by-term diff ╨┐╤А╨╛╤В╨╕╨▓ ╨┤╨╛╤Б╨╗╨╛╨▓╨╜╨╛╨╣ ╨▓╤Л╨┤╨╡╤А╨╢╨║╨╕ ╨╕╨╖ ╨╛╤Д╨╡╤А╤В╤Л тАФ ╨┐╤А╨╕╨╡╨╝╨╛╤З╨╜╤Л╨╣
  ╤В╨╡╤Б╤В ╨╗╨╡╨╣╨╜╨░ тАФ ╨▓
  [`docs/copy/money-refund-policy-student-surface.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-refund-policy-student-surface.md);
  4 ╨╜╨╛╨▓╤Л╤Е feature-╤В╨╡╤Б╤В╨░ (╤В╨╛╤З╨╜╤Л╨╡ ╤Б╤А╨╛╨║╨╕, ╤Б╤Б╤Л╨╗╨║╨░ ╨╕╨╖ trust row, ╤Б╤Б╤Л╨╗╨║╨░ ╨╜╨░ ╨╛╤Д╨╡╤А╤В╤Г).

## [1.36.0] - 2026-07-19

### Changed
- **H1287: ╤З╨╡╤Б╤В╨╜╤Л╨╣ ╨┤╨╡╤Д╨╕╤Ж╨╕╤В тАФ ╤Д╨░╨╗╤М╤И╨╕╨▓╤Л╨╡ ╤В╨░╨╣╨╝╨╡╤А╤Л ╨╕ ┬л16/20 ╨╝╨╡╤Б╤В┬╗ ╤Г╨▒╨╕╤В╤Л.** ╨Т╤В╨╛╤А╨╛╨╣
  Fable-╨╗╨╡╨╣╨╜ ╨▓╨╛╨╗╨╜╤Л revenue-copy
  ([╨┐╨╗╨░╨╜](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md),
  ╨┐╨╛╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╨╡ D4). `price_block` ╨╕ `course_streams_block` ╨▒╨╡╨╖ ╨╜╨░╤Б╤В╤А╨╛╨╣╨║╨╕
  ╤А╨╡╨╜╨┤╨╡╤А╨╕╨╗╨╕ ╨╛╨▒╤А╨░╤В╨╜╤Л╨╣ ╨╛╤В╤Б╤З╨╡╤В ┬л╨Я╨╛╨▓╤Л╤И╨╡╨╜╨╕╨╡ ╤Ж╨╡╨╜╤Л ╤З╨╡╤А╨╡╨╖:┬╗, ╤Б╨▒╤А╨░╤Б╤Л╨▓╨░╨▓╤И╨╕╨╣╤Б╤П ╨╜╨░ +24 ╤З╨░╤Б╨░
  ╨┐╤А╨╕ ╨║╨░╨╢╨┤╨╛╨╣ ╨╖╨░╨│╤А╤Г╨╖╨║╨╡, ╨╖╨░╤И╨╕╤В╤Л╨╡ ┬л16/20 ╨╝╨╡╤Б╤В┬╗, ╨╝╨╕╨│╨░╤О╤Й╨╕╨╣ ╨▒╨╡╨╣╨┤╨╢ ┬л╨Р╨║╤Ж╨╕╤П┬╗ ╨╕ ╤П╤А╨╗╤Л╨║
  ┬л╨Ю╤Б╤В╨░╨╗╨╛╤Б╤М ╨╝╨░╨╗╨╛!┬╗. ╨в╨╡╨┐╨╡╤А╤М ╨┤╨╡╤Д╨╕╤Ж╨╕╤В ╤А╨╡╨╜╨┤╨╡╤А╨╕╤В╤Б╤П ╤В╨╛╨╗╤М╨║╨╛ ╤Б ╤А╨╡╨░╨╗╤М╨╜╤Л╨╝╨╕ ╨┤╨░╨╜╨╜╤Л╨╝╨╕:
  ╨╜╨░╤Б╤В╤А╨╛╨╡╨╜╨╜╤Л╨╣ ╨┤╨╡╨┤╨╗╨░╨╣╨╜ тАФ ╨┤╨░╤В╨╛╨╣ ╤Б╨╗╨╛╨▓╨░╨╝╨╕ (┬л╨в╨╡╨║╤Г╤Й╨░╤П ╤Ж╨╡╨╜╨░ ╨┤╨╡╨╣╤Б╤В╨▓╤Г╨╡╤В ╨┤╨╛ 26 ╨╕╤О╨╗╤П,
  19:00┬╗), ╨░ ╨╜╨╡ ╤В╨╕╨║╨░╤О╤Й╨╕╨╝╨╕ ╤Ж╨╕╤Д╤А╨░╨╝╨╕; ╨╝╨╡╤Б╤В╨░ тАФ ╤В╨╛╨╗╤М╨║╨╛ ╨┐╤А╨╕ ╤П╨▓╨╜╨╛ ╨╖╨░╨┐╨╛╨╗╨╜╨╡╨╜╨╜╤Л╤Е ╤З╨╕╤Б╨╗╨░╤Е,
  ╨▒╨╡╨╖ ╨┤╨░╨▓╤П╤Й╨╕╤Е ╤П╤А╨╗╤Л╨║╨╛╨▓; ╨┐╤Г╤Б╤В╨░╤П ╨║╨╛╨╜╤Д╨╕╨│╤Г╤А╨░╤Ж╨╕╤П ╨┤╨╡╨│╤А╨░╨┤╨╕╤А╤Г╨╡╤В ╨┤╨╛ ╤З╨╡╤Б╤В╨╜╨╛╨│╨╛ ╨┐╤А╨░╨╣╤Б╨░.
  ╨д╨╛╨╗╨▒╤Н╨║ ╨╜╨░ ╨┤╨░╤В╤Г ╨▓╨╡╨▒╨╕╨╜╨░╤А╨░ ╨╕ ╨┐╤А╨╡╨┤╨╖╨░╨┐╨╛╨╗╨╜╨╡╨╜╨╜╤Л╨╡ 16/20 ╨▓ Filament-╤Б╤Е╨╡╨╝╨╡ ╤Г╨┤╨░╨╗╨╡╨╜╤Л,
  ╨╕╤Б╤В╨╡╨║╤И╨╕╨╣ ╨┤╨╡╨┤╨╗╨░╨╣╨╜ ╤Б╨║╤А╤Л╨▓╨░╨╡╤В╤Б╤П. ╨б╤В╤А╨╛╨║╨╕ ╨╕ ╤А╨╡╤И╨╡╨╜╨╕╤П:
  [`docs/copy/money-honest-scarcity-urgency-rewrite.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-honest-scarcity-urgency-rewrite.md);
  machine-checkable floor ╨╖╨░╨║╤А╨╡╨┐╨╗╨╡╨╜ ╨╜╨╛╨▓╤Л╨╝
  [`tests/Feature/PriceBlockTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/PriceBlockTest.php)
  (6 ╤В╨╡╤Б╤В╨╛╨▓) + ╨┐╨╡╤А╨╡╨┐╨╕╤Б╨░╨╜╨╜╤Л╨╝╨╕ ╤Б╤Ж╨╡╨╜╨░╤А╨╕╤П╨╝╨╕
  [`tests/Feature/CourseStreamsBlockTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CourseStreamsBlockTest.php).

## [1.35.0] - 2026-07-19

### Added
- **H1285: ╨╝╨╛╨╝╨╡╨╜╤В ╨┐╨╛╤Б╨╗╨╡ ╨╛╨┐╨╗╨░╤В╤Л тАФ ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Л success/fail ╨▓╨╝╨╡╤Б╤В╨╛ ╤А╨╡╨┤╨╕╤А╨╡╨║╤В╨╛╨▓.** ╨Я╨╡╤А╨▓╤Л╨╣
  Fable-╨╗╨╡╨╣╨╜ ╨▓╨╛╨╗╨╜╤Л revenue-copy
  ([╨┐╨╗╨░╨╜](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  `/payment/success` ╨╕ `/payment/fail` тАФ ╨┤╨▓╨╡ ╤Б╨░╨╝╤Л╨╡ ╤Н╨╝╨╛╤Ж╨╕╨╛╨╜╨░╨╗╤М╨╜╤Л╨╡ ╤В╨╛╤З╨║╨╕ ╨▓╨╛╤А╨╛╨╜╨║╨╕ тАФ ╨╜╨╡
  ╤А╨╡╨╜╨┤╨╡╤А╨╕╨╗╨╕ ╨╜╨╕╤З╨╡╨│╨╛: ╤Г╤Б╨┐╨╡╤Е ╤Г╨▓╨╛╨┤╨╕╨╗ ╤Д╨╗╨╡╤И╨╡╨╝ ╨▓ ╨║╨░╨▒╨╕╨╜╨╡╤В, ╨╜╨╡╤Г╨┤╨░╤З╨░ ╨▓╤Л╨▒╤А╨░╤Б╤Л╨▓╨░╨╗╨░ ╨╜╨░ ╨│╨╗╨░╨▓╨╜╤Г╤О,
  ╤В╨╡╤А╤П╤П ╨║╤Г╤А╤Б ╨╕ ╨▓╨╛╨╖╨╝╨╛╨╢╨╜╨╛╤Б╤В╤М ╨┐╨╛╨▓╤В╨╛╤А╨░. ╨в╨╡╨┐╨╡╤А╤М ╨╛╨▒╨░ ╨╝╨░╤А╤И╤А╤Г╤В╨░ ╤А╨╡╨╜╨┤╨╡╤А╤П╤В ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Л
  ([`resources/views/payment/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/resources/views/payment)):
  ╤Г╤Б╨┐╨╡╤Е тАФ ╤В╤А╨╕ ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╤П (╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╛ ╨▓╨╡╨▒╤Е╤Г╨║╨╛╨╝ / ╨▒╨░╨╜╨║ ╨┐╤А╨╕╨╜╤П╨╗, ╨╢╨┤╨╡╨╝ ╨┐╨╛╨┤╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨╕╤П,
  ╤Б ╨╛╨│╤А╨░╨╜╨╕╤З╨╡╨╜╨╜╤Л╨╝ ╨╛╨╢╨╕╨┤╨░╨╜╨╕╨╡╨╝ ┬л╨╡╤Б╨╗╨╕ ╤З╨╡╤А╨╡╨╖ 10 ╨╝╨╕╨╜╤Г╤В ╨┤╨╛╤Б╤В╤Г╨┐╨░ ╨▓╤Б╤С ╨╡╤Й╨╡ ╨╜╨╡╤В тАФ ╨╜╨░╨┐╨╕╤И╨╕╤В╨╡тАж┬╗ /
  ╨│╨╛╤Б╤В╤М ╤Б ╨║╨╜╨╛╨┐╨║╨╛╨╣ ╨▓╤Е╨╛╨┤╨░), ╨╜╨╡╤Г╨┤╨░╤З╨░ тАФ ╨▒╨╗╨╛╨║ ┬л╨Х╤Б╨╗╨╕ ╨┤╨╡╨╜╤М╨│╨╕ ╤Б╨┐╨╕╤Б╨░╨╗╨╕╤Б╤М тАФ ╨╜╨╡ ╨┐╨╗╨░╤В╨╕╤В╨╡
  ╨┐╨╛╨▓╤В╨╛╤А╨╜╨╛тАж┬╗ ╨┐╨╡╤А╨▓╤Л╨╝, ╨▓╤Л╤И╨╡ ╤Д╨╛╨╗╨┤╨░, ╨╖╨░╤В╨╡╨╝ ╨┐╨╛╨▓╤В╨╛╤А ╨╛╨┐╨╗╨░╤В╤Л ╤Б╨╛ ╤Б╤Б╤Л╨╗╨║╨╛╨╣ ╨╜╨░ ╨║╤Г╤А╤Б ╨╕╨╖
  ╨┐╨╛╤Б╨╗╨╡╨┤╨╜╨╡╨│╨╛ ╨╜╨╡╨╛╨┐╨╗╨░╤З╨╡╨╜╨╜╨╛╨│╨╛ ╨┐╨╗╨░╤В╨╡╨╢╨░ (╤В╨╛╨╗╤М╨║╨╛ ╤З╤В╨╡╨╜╨╕╨╡ тАФ ╤Б╤В╨░╤В╤Г╤Б ╨┐╨╗╨░╤В╨╡╨╢╨░ ╨┐╨╛-╨┐╤А╨╡╨╢╨╜╨╡╨╝╤Г
  ╨╝╨╡╨╜╤П╨╡╤В ╨╕╤Б╨║╨╗╤О╤З╨╕╤В╨╡╨╗╤М╨╜╨╛ ╨▓╨╡╨▒╤Е╤Г╨║ ╨в╨╛╤З╨║╨╕). ╨б╤В╤А╨╛╨║╨╕ ╨╕ ╤А╨╡╤И╨╡╨╜╨╕╤П:
  [`docs/copy/money-post-payment-moment-copy.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-post-payment-moment-copy.md);
  ╨╛╨▒╤Й╨╕╨╡ ╤Б╤В╤А╨╛╨║╨╕ ╨▓╨╛╨╗╨╜╤Л 1тАУ3 (╤В╨░╨╣╨╝╨╕╨╜╨│ ╨┤╨╛╤Б╤В╤Г╨┐╨░, ╨┤╨▓╨╛╨╣╨╜╨╛╨╡ ╤Б╨┐╨╕╤Б╨░╨╜╨╕╨╡, ╨║╨░╨╜╨░╨╗ ╨┐╨╛╨┤╨┤╨╡╤А╨╢╨║╨╕)
  ╨╛╨┐╤А╨╡╨┤╨╡╨╗╨╡╨╜╤Л ╨▓
  [`docs/copy/_shared_strings.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/_shared_strings.md).
  7 ╨╜╨╛╨▓╤Л╤Е feature-╤В╨╡╤Б╤В╨╛╨▓: ╤А╨╡╨╜╨┤╨╡╤А ╨▓╤Б╨╡╤Е ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╣, ╨┐╨╛╨▓╤В╨╛╤А ╨║ ╤Б╨║╤А╤Л╤В╨╛╨╝╤Г ╨║╤Г╤А╤Б╤Г ╤Г╤Е╨╛╨┤╨╕╤В ╨▓
  ╨║╨░╤В╨░╨╗╨╛╨│, ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Л ╨╜╨╡ ╨╝╤Г╤В╨╕╤А╤Г╤О╤В ╤Б╤В╨░╤В╤Г╤Б ╨┐╨╗╨░╤В╨╡╨╢╨░.

## [1.34.0] - 2026-07-19

### Added
- **H1345: ╤Б╨╗╨╡╨╢╨║╨░ ╨╖╨░ ╤А╨╛╤Б╤В╨╛╨╝ ╤Д╨░╨╣╨╗╨╛╨▓╨╛╨│╨╛ ╤Е╤А╨░╨╜╨╕╨╗╨╕╤Й╨░.** ╨Ч╨░╨┐╤А╨╛╤Б MG: ┬л╨╝╨╛╨╢╨╜╨╛ ╨╗╨╕ ╨┐╨╛╤Б╤В╨░╨▓╨╕╤В╤М ╤Б╨╗╨╡╨╢╨║╤Г
  ╨╖╨░ ╤Н╤В╨╕╨╝, ╤З╤В╨╛╨▒╤Л ╤Б╨╡╤А╨▓╨╡╤А ╨╜╨╡ ╨╗╨╡╨│ ╨╛╤В ╤Д╨░╨╣╨╗╨╛╨▓┬╗. ╨Ф╨╛ ╤Н╤В╨╛╨│╨╛ ╤А╨╛╤Б╤В ╨╝╨╡╨┤╨╕╨░ ╨╜╨╡ ╨╕╨╖╨╝╨╡╤А╤П╨╗╤Б╤П **╨╜╨╕╤З╨╡╨╝** тАФ
  `disk_free_space`/`du` ╨╜╨╡ ╨▓╤Б╤В╤А╨╡╤З╨░╨╗╨╕╤Б╤М ╨▓ ╨║╨╛╨┤╨╡ ╨╜╨╕ ╤А╨░╨╖╤Г, ╤В╨╛ ╨╡╤Б╤В╤М ╤Г╨╖╨╜╨░╤В╤М ╨╛ ╨┐╤А╨╛╨▒╨╗╨╡╨╝╨╡ ╨╝╨╛╨╢╨╜╨╛ ╨▒╤Л╨╗╨╛
  ╤В╨╛╨╗╤М╨║╨╛ ╨┐╨╛ ╤Д╨░╨║╤В╤Г ╨┐╨░╨┤╨╡╨╜╨╕╤П. ╨Э╨╛╨▓╨░╤П ╨╡╨╢╨╡╨┤╨╜╨╡╨▓╨╜╨░╤П ╨║╨╛╨╝╨░╨╜╨┤╨░ `storage:check` (04:20, ╨┐╨╛╤Б╨╗╨╡
  `archives:cleanup` ╨╕ `backup:clean`, ╤З╤В╨╛╨▒╤Л ╨╝╨╡╤А╨╕╤В╤М ╨╛╤Б╨▓╨╛╨▒╨╛╨╢╨┤╤С╨╜╨╜╨╛╨╡ ╨╝╨╡╤Б╤В╨╛, ╨░ ╨╜╨╡ ╨▓╤А╨╡╨╝╨╡╨╜╨╜╤Л╨╣ ╨┐╨╕╨║)
  ╨╕╨╖╨╝╨╡╤А╤П╨╡╤В ╨▓╨╡╤Б ╨║╨░╤В╨░╨╗╨╛╨│╨╛╨▓ ╨╖╨░╨│╤А╤Г╨╖╨╛╨║ ╨╕ ╤Б╨▓╨╛╨▒╨╛╨┤╨╜╨╛╨╡ ╨╝╨╡╤Б╤В╨╛ ╨╜╨░ ╨┤╨╕╤Б╨║╨╡, ╤Б╨▓╨╡╤В╨╛╤Д╨╛╤А 0.8/1.0 ╨║╨░╨║ ╤Г
  ╨┤╨╡╨▒╨╕╤В╨╛╤А╨║╨╕, ╨░╨╗╨╡╤А╤В ╨░╨┤╨╝╨╕╨╜╨░╨╝ ╤З╨╡╤А╨╡╨╖ Filament-╤Г╨▓╨╡╨┤╨╛╨╝╨╗╨╡╨╜╨╕╨╡. ╨Я╨╛╤А╨╛╨│╨╕ тАФ
  [`config/storage_watch.php`](config/storage_watch.php) (env-backed), ╨╕╨╖╨╝╨╡╤А╨╡╨╜╨╕╨╡ тАФ
  [`StorageUsageService`](app/Services/StorageUsageService.php). ╨Ъ╨╛╨╝╨░╨╜╨┤╨░ **╨╜╨╕╤З╨╡╨│╨╛ ╨╜╨╡ ╤Г╨┤╨░╨╗╤П╨╡╤В**
  (╨░╨▓╤В╨╛╨╝╨░╤В╨╕╤З╨╡╤Б╨║╨╕ ╤Б╤В╨╕╤А╨░╤В╤М ╤А╨░╨▒╨╛╤В╤Л ╤Б╤В╤Г╨┤╨╡╨╜╤В╨╛╨▓ ╨╜╨╡╨┤╨╛╨┐╤Г╤Б╤В╨╕╨╝╨╛) ╨╕ **╨│╤А╨╛╨╝╨║╨╛ ╤Б╨╛╨╛╨▒╤Й╨░╨╡╤В ╨╛ ╤Б╨╛╨▒╤Б╤В╨▓╨╡╨╜╨╜╨╛╨╣
  ╤Б╨╗╨╡╨┐╨╛╤В╨╡**: ╨╡╤Б╨╗╨╕ ╨╛╨▒╤Е╨╛╨┤ ╤Г╨┐╤С╤А╤Б╤П ╨▓ ╨┐╤А╨╡╨┤╨╛╤Е╤А╨░╨╜╨╕╤В╨╡╨╗╤М ╨┐╨╛ ╤З╨╕╤Б╨╗╤Г ╤Д╨░╨╣╨╗╨╛╨▓, ╤Н╤В╨╛ ╨╛╤В╨┤╨╡╨╗╤М╨╜╨░╤П ╤Б╤В╤А╨╛╨║╨░ ╨░╨╗╨╡╤А╤В╨░,
  ╨░ ╨╜╨╡ ╨╝╨╛╨╗╤З╨░ ╨╖╨░╨╜╨╕╨╢╨╡╨╜╨╜╨░╤П ╤Ж╨╕╤Д╤А╨░. ╨У╨╛╨┤╨╕╤В╤Б╤П ╨╕ ╨║╨░╨║ ╤А╤Г╤З╨╜╨╛╨╣ ╨╕╨╜╤Б╤В╤А╤Г╨╝╨╡╨╜╤В тАФ `php artisan storage:check --dry`
  ╨┐╨╡╤З╨░╤В╨░╨╡╤В ╤В╨░╨▒╨╗╨╕╤Ж╤Г ┬л╨╖╨░╨╜╤П╤В╨╛/╨┐╨╛╤В╨╛╨╗╨╛╨║/╨┤╨╛╨╗╤П┬╗.
- **Roadmap ╤Е╤А╨░╨╜╨╡╨╜╨╕╤П ╨╝╨╡╨┤╨╕╨░** тАФ [`docs/ROADMAP_MEDIA_STORAGE_2026_2028.md`](docs/ROADMAP_MEDIA_STORAGE_2026_2028.md)
  (+ ╨╝╨╡╤В╨░╨┤╨╛╨║). ╨н╤В╨░╨┐╤Л ╨┐╤А╨╕╨▓╤П╨╖╨░╨╜╤Л ╨║ **╤В╤А╨╕╨│╨│╨╡╤А╨░╨╝, ╨░ ╨╜╨╡ ╨║ ╨┤╨░╤В╨░╨╝**: ╨╜╨░ 19-07-2026 ╨▓╤Б╤С `storage/app`
  ╨╖╨░╨╜╨╕╨╝╨░╨╡╤В ~20 ╨Ь╨С, ╨┐╨╡╤А╨╡╨╜╨╛╤Б╨╕╤В╤М ╨╜╨╡╤З╨╡╨│╨╛, ╨╕ ╨║╨░╨╗╨╡╨╜╨┤╨░╤А╨╜╤Л╨╣ ╨┐╨╗╨░╨╜ ╨▒╤Л╨╗ ╨▒╤Л ╨▓╤Л╨┤╤Г╨╝╨║╨╛╨╣. ╨а╨░╨╖╨╛╨▒╤А╨░╨╜╨╛, ╨┐╨╛╤З╨╡╨╝╤Г
  VK ╨║╨░╨║ ╤Б╨╛╤Ж╤Б╨╡╤В╤М ╨╜╨╡ ╨┐╨╛╨┤╤Е╨╛╨┤╨╕╤В ╨┤╨╗╤П ╤А╨░╨▒╨╛╤В ╤Б╤В╤Г╨┤╨╡╨╜╤В╨╛╨▓ (152-╨д╨Ч; ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В VK ╨┤╨╛╤Б╤В╤Г╨┐╨╡╨╜ ╨┐╨╛ ╤Б╤Б╤Л╨╗╨║╨╡ ╨▒╨╡╨╖
  ╨┐╤А╨╛╨▓╨╡╤А╨║╨╕ ╨┐╤А╨░╨▓, ╤В╨╛╨│╨┤╨░ ╨║╨░╨║ ╤Б╨╡╨╣╤З╨░╤Б ╤Б╨║╨░╤З╨╕╨▓╨░╨╜╨╕╨╡ ╨┐╤А╨╛╨▓╨╡╤А╤П╨╡╤В ┬л╨▓╨╗╨░╨┤╨╡╨╗╨╡╤Ж/╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤М/╨░╨┤╨╝╨╕╨╜┬╗;
  ╨░╤Г╨┤╨╕╨╛-API ╨╖╨░╨║╤А╤Л╤В ╤Б 2016), ╨▓╨║╨╗╤О╤З╨░╤П ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛ ╤А╨░╤Б╤Б╨╝╨╛╤В╤А╨╡╨╜╨╜╤Л╨╣ ╨▓╨░╤А╨╕╨░╨╜╤В ┬лVK-╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В╤Л ╨┤╨╗╤П ╤Д╨╛╤В╨╛┬╗ тАФ
  ╤Д╨╛╤В╨╛╨│╤А╨░╤Д╨╕╤П ╨┤╨╛╨╝╨░╤И╨╜╨╡╨╣ ╤А╨░╨▒╨╛╤В╤Л ╨╛╤Б╤В╨░╤С╤В╤Б╤П ╤А╨░╨▒╨╛╤В╨╛╨╣ ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░, ╨┐╨╛╤Б╨╗╨░╨▒╨╗╨╡╨╜╨╕╨╡ ╨┐╨╛ ╤В╨╕╨┐╤Г ╤Д╨░╨╣╨╗╨░ ╨╜╨╡ ╨┐╨╛╨╝╨╛╨│╨░╨╡╤В.
- **╨Ю╨┐╤Ж╨╕╨╕ S3 ╨┤╨╗╤П VK Cloud ╨╕ Yandex Object Storage** тАФ ╨┐╤А╨░╨▓╨╕╨╗╤М╨╜╨░╤П ╤Д╨╛╤А╨╝╨░ ╨╕╨┤╨╡╨╕ ┬л╤Е╤А╨░╨╜╨╕╤В╤М ╨╜╨░
  ╤Б╤В╨╛╤А╨╛╨╜╨╡ VK┬╗: ╨╖╨░╨║╤А╤Л╤В╤Л╨╣ bucket + ╨┐╨╛╨┤╨┐╨╕╤Б╨░╨╜╨╜╤Л╨╡ ╤Б╤Б╤Л╨╗╨║╨╕, ╨║╨╛╨╜╤В╤А╨╛╨╗╤М ╨┤╨╛╤Б╤В╤Г╨┐╨░ ╨╛╤Б╤В╨░╤С╤В╤Б╤П ╤Г ╨╜╨░╤Б. ╨Ф╨╕╤Б╨║
  ╨╛╨┤╨╕╨╜, ╨┐╤А╨╛╨▓╨░╨╣╨┤╨╡╤А ╨▓╤Л╨▒╨╕╤А╨░╨╡╤В╤Б╤П ╨┐╨░╤А╨╛╨╣ `AWS_ENDPOINT`+`AWS_DEFAULT_REGION`; ╨│╨╛╤В╨╛╨▓╤Л╨╡ ╨╜╨░╨▒╨╛╤А╤Л ╨▓
  [`.env.example`](.env.example) ╨╕ `config/filesystems.php`. ╨Я╨╛╨║╨░ ╨╜╨╡ ╨▓╨║╨╗╤О╤З╨╡╨╜╨╛ тАФ ╨▓╨║╨╗╤О╤З╨░╤В╤М ╨┐╨╛
  ╤В╤А╨╕╨│╨│╨╡╤А╤Г ╤Н╤В╨░╨┐╨░ 2.

### Changed
- **╨Ч╨░╨║╤А╤Л╤В╤Л ╨╜╨╡╨╖╨░╨║╤А╤Л╤В╤Л╨╡ ╨╗╨╕╨╝╨╕╤В╤Л ╨╖╨░╨│╤А╤Г╨╖╨╛╨║** (╨╛╨┤╨╛╨▒╤А╨╡╨╜╨╛ MG). ╨Ы╨╕╨┤-╨╝╨░╨│╨╜╨╕╤В╤Л ╨┐╨╛╨┤╨┐╨╕╤Б╤З╨╕╨║╨╛╨▓ ╨┐╤А╨╕╨╜╨╕╨╝╨░╨╗╨╕
  ╤Д╨░╨╣╨╗ **╨╗╤О╨▒╨╛╨│╨╛ ╤А╨░╨╖╨╝╨╡╤А╨░ ╨╕ ╨╗╤О╨▒╨╛╨│╨╛ ╤В╨╕╨┐╨░** (╨▓╨║╨╗╤О╤З╨░╤П ╨╕╤Б╨┐╨╛╨╗╨╜╤П╨╡╨╝╤Л╨╣) ╨▓ ╨┐╤Г╨▒╨╗╨╕╤З╨╜╨╛ ╨╛╤В╨┤╨░╨▓╨░╨╡╨╝╤Л╨╣ ╨║╨░╤В╨░╨╗╨╛╨│ тАФ
  ╤В╨╡╨┐╨╡╤А╤М 20 ╨Ь╨С ╨╕ ╤В╨╛╨╗╤М╨║╨╛ PDF/DOC/DOCX/EPUB. ╨Ь╨░╤В╨╡╤А╨╕╨░╨╗╤Л ╨╖╨░╨╜╤П╤В╨╕╨╣: 100 ╨Ь╨С ╨╜╨░ ╤Д╨░╨╣╨╗ ╨┐╤А╨╕
  `appendFiles()` ╨╕ **╨▒╨╡╨╖ ╨┐╨╛╤В╨╛╨╗╨║╨░ ╨┐╨╛ ╨║╨╛╨╗╨╕╤З╨╡╤Б╤В╨▓╤Г** тАФ ╨╛╨┤╨╕╨╜ ╤Г╤А╨╛╨║ ╨╝╨╛╨│ ╨║╨╛╨┐╨╕╤В╤М ╨▓╨╕╨┤╨╡╨╛ ╨▒╨╡╨╖ ╨┐╤А╨╡╨┤╨╡╨╗╨░;
  ╤В╨╡╨┐╨╡╤А╤М ╨┤╨╛ 20 ╤Д╨░╨╣╨╗╨╛╨▓. ╨б╨┐╤А╨░╨▓╨╛╤З╨╜╤Л╨╡ ╤Д╨░╨╣╨╗╤Л ╨╖╨░╨┤╨░╨╜╨╕╤П тАФ ╨┤╨╛ 10.

### Fixed
- **╨Ь╤С╤А╤В╨▓╨░╤П ╤В╤А╨╡╨▓╨╛╨│╨░ ╨▓ ╨╝╨╛╨╜╨╕╤В╨╛╤А╨╕╨╜╨│╨╡ ╨▒╤Н╨║╨░╨┐╨╛╨▓.** Health-check `MaximumStorageInMegabytes` ╤Б╤В╨╛╤П╨╗ ╨╜╨░
  5000 ╨Ь╨С, ╤В╨╛╨│╨┤╨░ ╨║╨░╨║ ╤Б╤В╤А╨░╤В╨╡╨│╨╕╤П ╤Г╨▒╨╛╤А╨║╨╕ ╤А╨╡╨╢╨╡╤В ╤Б╤В╨░╤А╤Л╨╡ ╨▒╤Н╨║╨░╨┐╤Л ╤Г╨╢╨╡ ╨╜╨░ 1000 ╨Ь╨С тАФ ╤В╨╛ ╨╡╤Б╤В╤М ╤В╤А╨╡╨▓╨╛╨│╨░ ╨╜╨╡
  ╨╝╨╛╨│╨╗╨░ ╤Б╤А╨░╨▒╨╛╤В╨░╤В╤М ╨╜╨╕╨║╨╛╨│╨┤╨░. ╨Ю╨▒╨░ ╤З╨╕╤Б╨╗╨░ ╨▓╤Л╨▓╨╡╨┤╨╡╨╜╤Л ╨▓ env, ╨┐╨╛╤А╨╛╨│ ╤В╤А╨╡╨▓╨╛╨│╨╕ ╨╛╨┐╤Г╤Й╨╡╨╜ ╨┤╨╛ 1200 ╨Ь╨С, ╨│╨┤╨╡ ╨╛╨╜
  ╨╛╨╖╨╜╨░╤З╨░╨╡╤В ╨╛╤Б╨╝╤Л╤Б╨╗╨╡╨╜╨╜╨╛╨╡: ┬л╤Г╨▒╨╛╤А╨║╨░ ╨╜╨╡ ╤Б╨┐╤А╨░╨▓╨╗╤П╨╡╤В╤Б╤П┬╗. ╨Т╨░╨╢╨╜╨╛, ╨┐╨╛╤Б╨║╨╛╨╗╤М╨║╤Г `storage/app` ╤Ж╨╡╨╗╨╕╨║╨╛╨╝ ╨▓╤Е╨╛╨┤╨╕╤В
  ╨▓ ╨╡╨╢╨╡╨╜╨╡╨┤╨╡╨╗╤М╨╜╤Л╨╣ ╨▒╤Н╨║╨░╨┐, ╨╕ ╤А╨╛╤Б╤В ╨╝╨╡╨┤╨╕╨░ ╤А╨░╨╖╨┤╤Г╨▓╨░╨╡╤В ╨╡╨│╨╛ ╨╗╨╕╨╜╨╡╨╣╨╜╨╛.

## [1.33.0] - 2026-07-19

### Added
- **H1343: ╨┐╤А╨╕╤С╨╝ ╨▓╨╕╨┤╨╡╨╛ ╨▓ ╨┤╨╛╨╝╨░╤И╨╜╨╕╤Е ╨╖╨░╨┤╨░╨╜╨╕╤П╤Е.** MG ╨┐╨╛╤Б╤В╨░╨╜╨╛╨▓╨╕╨╗ ┬л╨▓╨╕╨┤╨╡╨╛ ╨┐╤А╨╡╨╢╨┤╨╡ ╨╜╨╡ ╨▒╤Л╨╗╨╛, ╨╜╨░╨┤╨╛
  ╤Б╨┤╨╡╨╗╨░╤В╤М┬╗ тАФ ╨┤╨╛ ╤Н╤В╨╛╨│╨╛ ╨╜╨╕ `accept` ╨╕╨╜╨┐╤Г╤В╨░, ╨╜╨╕ ╤Б╨╡╤А╨▓╨╡╤А╨╜╨╛╨╡ ╨┐╤А╨░╨▓╨╕╨╗╨╛ `mimes:` ╨╜╨╡ ╤Б╨╛╨┤╨╡╤А╨╢╨░╨╗╨╕ ╨╜╨╕
  ╨╛╨┤╨╜╨╛╨│╨╛ ╨▓╨╕╨┤╨╡╨╛╤Д╨╛╤А╨╝╨░╤В╨░, ╤В╨░╨║ ╤З╤В╨╛ ╨┐╨╛╨┐╤Л╤В╨║╨░ ╨┐╤А╨╕╨╗╨╛╨╢╨╕╤В╤М ╨▓╨╕╨┤╨╡╨╛ ╨┐╨░╨┤╨░╨╗╨░ ╨╜╨░ ╨▓╨░╨╗╨╕╨┤╨░╤Ж╨╕╨╕, ╨░ ╨┐╨╛╨┤╨┐╨╕╤Б╤М ╤Д╨╛╤А╨╝╤Л
  ╨▓╨╕╨┤╨╡╨╛ ╨╕ ╨╜╨╡ ╨╛╨▒╨╡╤Й╨░╨╗╨░. ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜╤Л `mp4`, `mov`, `webm`. **╨Я╨╛╤В╨╛╨╗╨╛╨║ ╨╜╨░ ╤Д╨░╨╣╨╗ ╨Э╨Х ╨┐╨╛╨┤╨╜╨╕╨╝╨░╨╗╤Б╤П**:
  30 ╨Ь╨С тАФ ╤Н╤В╨╛ ~2тАУ3 ╨╝╨╕╨╜╤Г╤В╤Л ╨▓╨╕╨┤╨╡╨╛ ╤Б ╤В╨╡╨╗╨╡╤Д╨╛╨╜╨░ ╨▓ 720p, ╤З╨╡╨│╨╛ ╨┤╨╗╤П ╨╛╤В╨▓╨╡╤В╨░ ╨┐╨╛ ╨╖╨░╨┤╨░╨╜╨╕╤О ╨┤╨╛╤Б╤В╨░╤В╨╛╤З╨╜╨╛, ╨╕
  ╨▒╨╗╨░╨│╨╛╨┤╨░╤А╤П ╤Н╤В╨╛╨╝╤Г ╤Б╨╡╤А╨▓╨╡╤А╨╜╤Л╨╡ ╨╗╨╕╨╝╨╕╤В╤Л (`upload_max_filesize`/`post_max_size` ╨▓ php-fpm,
  `client_max_body_size` ╨▓ nginx) ╨╝╨╡╨╜╤П╤В╤М ╨╜╨╡ ╨┐╨╛╤В╤А╨╡╨▒╨╛╨▓╨░╨╗╨╛╤Б╤М тАФ ╤З╤В╨╛ ╨▓╨░╨╢╨╜╨╛, ╨┐╨╛╤Б╨║╨╛╨╗╤М╨║╤Г ╨╕╤Е ╤А╨╡╨░╨╗╤М╨╜╤Л╨╡
  ╨┐╤А╨╛╨┤-╨╖╨╜╨░╤З╨╡╨╜╨╕╤П ╨╜╨╕╨│╨┤╨╡ ╨▓ ╤А╨╡╨┐╨╛╨╖╨╕╤В╨╛╤А╨╕╨╕ ╨╜╨╡ ╨╖╨░╨┐╨╕╤Б╨░╨╜╤Л.
- **╨Э╨╛╨▓╤Л╨╣ [`config/homework.php`](config/homework.php)** (env-backed, ╨┐╨╛ ╨┤╨╛╨╝╨╛╨▓╨╛╨╝╤Г ╨┐╤А╨░╨▓╨╕╨╗╤Г
  ┬л╨┐╨╛╤А╨╛╨│╨╕ ╨╜╨╡ ╤Е╨░╤А╨┤╨║╨╛╨┤╤П╤В╤Б╤П ╨▓ ╨║╨╛╨╜╤В╤А╨╛╨╗╨╗╨╡╤А╨╡/╤Б╤В╤А╨░╨╜╨╕╤Ж╨╡┬╗): `max_files`, `max_file_kb`,
  `total_max_kb`, `allowed_extensions`. ╨Я╨╛╨┤╨┐╨╕╤Б╤М ╤Д╨╛╤А╨╝╤Л, ╤Д╨╕╨╗╤М╤В╤А ╨▓╤Л╨▒╨╛╤А╨░ ╤Д╨░╨╣╨╗╨╛╨▓ ╨╕ ╤Б╨╡╤А╨▓╨╡╤А╨╜╨░╤П
  ╨▓╨░╨╗╨╕╨┤╨░╤Ж╨╕╤П ╤В╨╡╨┐╨╡╤А╤М ╤З╨╕╤В╨░╤О╤В ╨Ю╨Ф╨Ш╨Э ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║ ╨╕ ╨╜╨╡ ╨╝╨╛╨│╤Г╤В ╤А╨░╨╖╤К╨╡╤Е╨░╤В╤М╤Б╤П тАФ ╤А╨░╨╜╤М╤И╨╡ ╤В╤А╨╕ ╨╝╨╡╤Б╤В╨░ ╨┐╤А╨░╨▓╨╕╨╗╨╕╤Б╤М
  ╨▓╤А╤Г╤З╨╜╤Г╤О ╨╕ ╨┐╨╛ ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛╤Б╤В╨╕.

### Fixed
- **╨Я╤А╨╡╨▓╤Л╤И╨╡╨╜╨╕╨╡ ╤А╨░╨╖╨╝╨╡╤А╨░ ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨┤╨░╤С╤В ╨┐╤Г╤Б╤В╤Г╤О ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Г 413.** ╨Т╨░╨╗╨╕╨┤╨░╤Ж╨╕╤П ╨┤╨╛╨┐╤Г╤Б╨║╨░╨╗╨░ 10 ╤Д╨░╨╣╨╗╨╛╨▓ ├Ч
  30 ╨Ь╨С = 300 ╨Ь╨С ╨╜╨░ ╨╛╤В╨┐╤А╨░╨▓╨║╤Г ╨┐╤А╨╛╤В╨╕╨▓ ╨╖╨░╤П╨▓╨╗╨╡╨╜╨╜╨╛╨│╨╛ `post_max_size=100M`, ╤В╨╛ ╨╡╤Б╤В╤М ╤Б╤В╤Г╨┤╨╡╨╜╤В ╤Б
  ╤З╨╡╤В╤Л╤А╤М╨╝╤П ╤В╤П╨╢╤С╨╗╤Л╨╝╨╕ ╤Д╨░╨╣╨╗╨░╨╝╨╕ ╤Г╨╢╨╡ ╤Б╨╡╨│╨╛╨┤╨╜╤П ╨┐╨╛╨┐╨░╨┤╨░╨╗ ╨▓ ╨╝╨╛╨╗╤З╨░╨╗╨╕╨▓╤Л╨╣ ╨╛╤В╨║╨░╨╖: PHP ╨╛╤В╨▒╤А╨░╤Б╤Л╨▓╨░╨╡╤В ╤В╨╡╨╗╨╛
  ╨╖╨░╨┐╤А╨╛╤Б╨░, Laravel ╨┤╨╛ ╨▓╨░╨╗╨╕╨┤╨░╤Ж╨╕╨╕ ╨╜╨╡ ╨┤╨╛╤Е╨╛╨┤╨╕╤В, ╨╜╨░╨▒╤А╨░╨╜╨╜╤Л╨╣ ╤В╨╡╨║╤Б╤В ╨╛╤В╨▓╨╡╤В╨░ ╤В╨╡╤А╤П╨╡╤В╤Б╤П. ╨Ф╨╛╨▒╨░╨▓╨╗╨╡╨╜╤Л ╨┤╨▓╨╡
  ╨╖╨░╤Й╨╕╤В╤Л: ╨┐╤А╨╛╨▓╨╡╤А╨║╨░ ╤Б╤Г╨╝╨╝╨░╤А╨╜╨╛╨│╨╛ ╨▓╨╡╤Б╨░ ╨╛╤В╨┐╤А╨░╨▓╨║╨╕ (`total_max_kb`, ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О 90 ╨Ь╨С тАФ ╤Б ╨╖╨░╨┐╨░╤Б╨╛╨╝
  ╨╜╨╕╨╢╨╡ `post_max_size`), ╨┤╨░╤О╤Й╨░╤П ╨▓╨╡╨╢╨╗╨╕╨▓╤Г╤О ╨╛╤И╨╕╨▒╨║╤Г ╨┐╤А╤П╨╝╨╛ ╨▓ ╤Д╨╛╤А╨╝╨╡, ╨╕ ╨╛╨▒╤А╨░╨▒╨╛╤В╤З╨╕╨║
  `PostTooLargeException` ╨▓ [`app/Exceptions/Handler.php`](app/Exceptions/Handler.php),
  ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╤О╤Й╨╕╨╣ ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░ ╨▓ ╤Д╨╛╤А╨╝╤Г ╤Б ╨╛╨▒╤К╤П╤Б╨╜╨╡╨╜╨╕╨╡╨╝ ╨▓╨╝╨╡╤Б╤В╨╛ ╨│╨╛╨╗╨╛╨│╨╛ 413. ╨б ╨╛╤В╨║╤А╤Л╤В╨╕╨╡╨╝ ╨▓╨╕╨┤╨╡╨╛ ╤Н╤В╨░
  ╨┤╨╛╤А╨╛╨╢╨║╨░ ╤Б╤В╨░╨╗╨░ ╨▒╤Л ╨│╨╛╤А╤П╤З╨╡╨╣.

## [1.32.0] - 2026-07-19

### Fixed
- **╨Я╤А╨╕╨║╤А╨╡╨┐╨╗╨╡╨╜╨╕╨╡ ╨Ф╨Ч: ╤Д╨░╨╣╨╗╤Л ╤А╨░╨╖╨╜╤Л╤Е ╤Д╨╛╤А╨╝╨░╤В╨╛╨▓ ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╨▓╤Л╤В╨╡╤Б╨╜╤П╤О╤В ╨┤╤А╤Г╨│ ╨┤╤А╤Г╨│╨░.** ╨б╤В╤Г╨┤╨╡╨╜╤В╨║╨░
  ╤Б╨╛╨╛╨▒╤Й╨╕╨╗╨░, ╤З╤В╨╛ ╨┐╤А╨╕ ╨╛╤В╨┐╤А╨░╨▓╨║╨╡ ╨┤╨╛╨╝╨░╤И╨╜╨╡╨│╨╛ ╨╖╨░╨┤╨░╨╜╨╕╤П ╨╜╨╡╨╗╤М╨╖╤П ╨┐╤А╨╕╨╗╨╛╨╢╨╕╤В╤М ╤Д╨╛╤В╨╛ ╨╕ ╨░╤Г╨┤╨╕╨╛ ╨╛╨┤╨╜╨╛╨▓╤А╨╡╨╝╨╡╨╜╨╜╨╛:
  ╨▓╤Л╨▒╤А╨░╨╗╨░ jpg, ╨╖╨░╤В╨╡╨╝ ╨┤╨╛╨▒╨░╨▓╨╕╨╗╨░ ╨░╤Г╨┤╨╕╨╛ тАФ ╤Д╨╛╤В╨╛╨│╤А╨░╤Д╨╕╨╕ ╨╕╤Б╤З╨╡╨╖╨░╨╗╨╕, ╨╕ ╨╜╨░╨╛╨▒╨╛╤А╨╛╤В. ╨Я╤А╨╕╤З╨╕╨╜╨░ тАФ
  `<input type="file" multiple>` ╨┐╤А╨╕ ╨║╨░╨╢╨┤╨╛╨╝ ╨╜╨╛╨▓╨╛╨╝ ╨▓╤Л╨▒╨╛╤А╨╡ **╨╖╨░╨╝╨╡╨╜╤П╨╡╤В** ╤Б╨▓╨╛╨╣ `FileList`
  ╤Ж╨╡╨╗╨╕╨║╨╛╨╝, ╨░ ╤Д╨╛╤А╨╝╨░ ╨╛╤В╨┐╤А╨░╨▓╨╗╤П╨╗╨░ ╨╡╨│╨╛ ╨╜╨░╨┐╤А╤П╨╝╤Г╤О; ╨╜╨╕ ╨▓ JS, ╨╜╨╕ ╨▓ Alpine ╨╜╨╡ ╨▒╤Л╨╗╨╛ ╨╜╨░╨║╨╛╨┐╨╕╤В╨╡╨╗╤П, ╤В╨░╨║
  ╤З╤В╨╛ ╨╜╨░ ╤Б╨╡╤А╨▓╨╡╤А ╤Г╤Е╨╛╨┤╨╕╨╗╨░ ╤В╨╛╨╗╤М╨║╨╛ ╨┐╨╛╤Б╨╗╨╡╨┤╨╜╤П╤П ╨┐╨░╤З╨║╨░ (╨┐╨╛╤В╨╡╤А╤П ╨▒╤Л╨╗╨░ ╤З╨╕╤Б╤В╨╛ ╨║╨╗╨╕╨╡╨╜╤В╤Б╨║╨╛╨╣ тАФ
  `HomeworkService::recordSubmission` ╤Г╨╢╨╡ ╨║╨╛╤А╤А╨╡╨║╤В╨╜╨╛ ╨║╨╛╨┐╨╕╤В ╤Д╨░╨╣╨╗╤Л ╨╝╨╡╨╢╨┤╤Г ╨╛╤В╨┐╤А╨░╨▓╨║╨░╨╝╨╕).
  [`resources/views/student/partials/homework.blade.php`](resources/views/student/partials/homework.blade.php)
  ╤В╨╡╨┐╨╡╤А╤М ╨┤╨╡╤А╨╢╨╕╤В ╤Б╨╛╨▒╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ ╨╝╨░╤Б╤Б╨╕╨▓ `File`-╨╛╨▒╤К╨╡╨║╤В╨╛╨▓, ╨┐╤А╨╕ ╨║╨░╨╢╨┤╨╛╨╝ ╨▓╤Л╨▒╨╛╤А╨╡ ╨┤╨╛╨┐╨╛╨╗╨╜╤П╨╡╤В ╨╡╨│╨╛,
  ╨┤╨╡╨┤╤Г╨┐╨╗╨╕╤Ж╨╕╤А╤Г╨╡╤В ╨┐╨╛ ╨╕╨╝╨╡╨╜╨╕+╤А╨░╨╖╨╝╨╡╤А╤Г ╨╕ ╨┐╨╡╤А╨╡╨┐╨╕╤Б╤Л╨▓╨░╨╡╤В `input.files` ╤З╨╡╤А╨╡╨╖ `DataTransfer` тАФ
  ╤Д╨╛╤А╨╝╨░╤В ╨▓╤Л╨▒╨╛╤А╨░ ╨╖╨╜╨░╤З╨╡╨╜╨╕╤П ╨╜╨╡ ╨╕╨╝╨╡╨╡╤В. ╨Я╨╗╤О╤Б: ╨║╤А╨╡╤Б╤В╨╕╨║ ╨┤╨╗╤П ╤Г╨┤╨░╨╗╨╡╨╜╨╕╤П ╨╛╤В╨┤╨╡╨╗╤М╨╜╨╛╨│╨╛ ╤Д╨░╨╣╨╗╨░,
  ╨┐╤А╨╡╨┤╤Г╨┐╤А╨╡╨╢╨┤╨╡╨╜╨╕╨╡ ╨┐╤А╨╕ ╨┐╤А╨╡╨▓╤Л╤И╨╡╨╜╨╕╨╕ ╨╗╨╕╨╝╨╕╤В╨░ ╨▓ 10 ╤Д╨░╨╣╨╗╨╛╨▓ (╤А╨░╨╜╤М╤И╨╡ ╨╗╨╕╤И╨╜╨╕╨╡ ╨╝╨╛╨╗╤З╨░ ╤Г╤Е╨╛╨┤╨╕╨╗╨╕ ╨▓
  ╤Б╨╡╤А╨▓╨╡╤А╨╜╤Г╤О ╨▓╨░╨╗╨╕╨┤╨░╤Ж╨╕╤О), ╨╕ `:key` ╨▓ `x-for` ╨▒╨╛╨╗╤М╤И╨╡ ╨╜╨╡ ╤Б╤Е╨╗╨╛╨┐╤Л╨▓╨░╨╡╤В ╨╛╨┤╨╜╨╛╨╕╨╝╤С╨╜╨╜╤Л╨╡ ╤Д╨░╨╣╨╗╤Л.
  ╨Я╨╛╨▓╨╡╨┤╨╡╨╜╨╕╨╡ ╤З╨╕╤Б╤В╨╛ ╨║╨╗╨╕╨╡╨╜╤В╤Б╨║╨╛╨╡, PHPUnit ╨╡╨│╨╛ ╨╜╨╡ ╨┐╨╛╨║╤А╤Л╨▓╨░╨╡╤В тАФ ╨┐╤А╨╛╨▓╨╡╤А╤П╤В╤М ╨▓╤А╤Г╤З╨╜╤Г╤О ╨▓ ╨║╨░╨▒╨╕╨╜╨╡╤В╨╡.

### Changed
- **H1295: ╤С-orthography normalisation sweep.** Mechanical prerequisite for the Systema
  revenue-copy wave (ruling D13/D14) тАФ normalises existing user-facing Russian copy to the
  house no-╤С rule (╨╡ instead of ╤С, except where dropping it would collide with a different
  word, e.g. ┬л╨▓╤Б╤С┬╗/┬л╨▓╤Б╨╡┬╗). A one-off census script scoped to `resources/views/**`,
  `app/Mail/**`, and Russian label values in `config/*.php` classified 297 occurrences
  (MUST_KEEP / REVIEW / SAFE) and applied 275 ╤СтЖТ╨╡ replacements across 77 files; full
  rationale, review list, and decisions-taken-unattended in
  [`docs/copy/yo-orthography-normalisation-sweep.md`](docs/copy/yo-orthography-normalisation-sweep.md).
  22 intentional exceptions remain (the ┬л╨▓╨╡╤Б╤М┬╗/┬л╨╛╨╜┬╗/┬л╤З╤В╨╛┬╗ pronoun-case minimal pairs, plus
  two individually-reviewed aspectual-verb risks left ╤С per the ambiguity default).

## [1.31.0] - 2026-07-19

### Added
- **H164: Telegram Track C тАФ @zapisi_ORSbot (class-booking bot) integration.**
  Executes the locked D7тАУD11 rulings
  ([DECISIONS_telegram_harvester.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_telegram_harvester.md#track-c--second-bot-account-zapisi_orsbot)):
  D8 go-forward webhook capture (`POST /api/webhooks/telegram-zapisi`,
  `verify.tg.zapisi` fail-closed secret middleware, `ProcessTelegramZapisiUpdate`
  normalizing into the same corpus schema as Track B, tagged `account_type=bot`)
  with D11 media download (`DownloadTelegramZapisiMedia`, Bot API `getFile` +
  raw download тАФ an override of Track B's D4 metadata-only default, scoped to
  this chat only); D9 full-member-roster snapshot
  (`telegram-harvest:roster {peer}`, `TelegramHarvestSyncService::fetchRoster`,
  `RosterStoreWriter`) and a D11 MadelineProto-backfill media-download path
  gated by the new `services.telegram_harvest.media_download_peers` config
  (peer-scoped, D4 stays metadata-only for every other Track B peer); D10 a new
  independent `zapisi_class_schedules` table + `zapisi:send-reminders`
  (`SendZapisiBotMessageJob`, idempotent via `sent_at`, scheduled every minute,
  gated by the new `features.telegram_zapisi_bot` deploy flag); a new
  admin-only Filament cluster (`ZapisiClassScheduleResource` CRUD +
  `ZapisiBotDashboard` read view over the out-of-git roster/message store) and
  new encrypted `zapisi_bot_token`/`zapisi_webhook_secret`/`zapisi_chat_id`
  fields on `MarketingSetting`. D7 (add the chat as a Track B peer) needs no
  new code тАФ Track B's existing `TELEGRAM_HARVEST_PEERS` mechanism already
  handles it once the chat's numeric id is discovered on a live host via
  `telegram-harvest:peers`; D8b (disable bot privacy mode via @BotFather) is a
  human action, filed as a GTD `@DO`. 17 new feature tests (webhook secret
  verification, normalization/store, media download, roster fetch/command,
  reminder scheduler, D11 peer-scoped backfill download). Sonnet 5
  (`claude-sonnet-5`). [PR #593](https://github.com/gasyoun/Systema-Sanscriticum/pull/593).
  [H164](https://github.com/gasyoun/Uprava/blob/main/handoffs/H164-Sonnet_DO_telegram-sanskrit-corpus_zapisi_orsbot_integration_04.07.26.md).

## [1.30.0] - 2026-07-19

### Added
- **H1281 (D6): ┬л╨Ы╨╕╨│╨░╤В╤Г╤А╤Л ╨┐╨╛ ╤З╨░╤Б╤В╨╛╤В╨╜╨╛╤Б╤В╨╕┬╗ тАФ ╨┤╨╡╨▓╨░╨╜╨░╨│╨░╤А╨╕-╤В╤А╨╡╨╜╨░╨╢╤С╤А ╨║╨╛╨╜╤К╤О╨╜╨║╤В╨╛╨▓.** ╨Э╨╛╨▓╨╛╨╡
  ╤Б╤В╨░╤В╨╕╤З╨╜╨╛╨╡ ╤Б╨╡╨╝╨╡╨╣╤Б╤В╨▓╨╛ `public/lila/ligatures/` ╨▓ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╡╨╣ `public/lila/`
  ╨╕╨│╤А╨╛╤В╨╡╨║╨╡ (╨╜╨╡ ╨╜╨╛╨▓╤Л╨╣ ╨┤╨▓╨╕╨╢╨╛╨║ тАФ reuse `match/engine.js`+`match/engine.css` as-is, per the
  plan's non-goal). ╨Ф╨░╨╜╨╜╤Л╨╡ тАФ ╤В╨╛╨┐-200 ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╤Б╨║╨╕╤Е ╨╗╨╕╨│╨░╤В╤Г╤А (saс╣Гyoga) ╨┐╨╛
  ╨║╨╛╤А╨┐╤Г╤Б╨╜╨╛╨╣ ╤З╨░╤Б╤В╨╛╤В╨╜╨╛╤Б╤В╨╕ ╨╕╨╖ VisualDCS
  [`derived-data/Fonetika/regen-2026/ligature_freq.csv`](https://github.com/gasyoun/VisualDCS/blob/main/derived-data/Fonetika/regen-2026/ligature_freq.csv)
  (Digital Corpus of Sanskrit тАФ Oliver Hellwig, CC BY 4.0; kosha manifest id
  `dcs-grapheme-frequency`), committed as
  [`data.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/ligatures/data.js)
  with the regen command in its header. Three cumulative frequency levels тАФ
  `top-10/` (all 10 shown), `top-50/` and `top-200/` (`perRound: 10`, a fresh random
  ten each "╨Ч╨░╨╜╨╛╨▓╨╛") тАФ each a `MatchExercise.mount()` pairing the Devan─Бgar─л glyph to
  its IAST romanization, hint = corpus rank + % of all ligature tokens. Linked from the
  main `/lila/` catalogue as a fourth family; prior-art fence links out to
  [csl-guides](https://sanskrit-lexicon.github.io/csl-guides/) for the full script
  course rather than duplicating it. Static-only тАФ no migration, no flag, no backend;
  ships with the normal deploy тАФ see
  [DEPLOY_QUEUE тДЦ40](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md).
  Sonnet 5 (`claude-sonnet-5`).
  [H1281](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1281-Sonnet_Systema-Sanscriticum_marathon-conjunct-frequency-order_19.07.26.md).

## [1.29.0] - 2026-07-19

### Added
- **H1280 (D4): SRS-╨║╨╛╨╗╨╛╨┤╨░ ┬л╨Ъ╨╛╤А╨╜╨╕ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨░ ╨┐╨╛ ╤З╨░╤Б╤В╨╛╤В╨╜╨╛╤Б╤В╨╕┬╗.** ╨Э╨╛╨▓╨░╤П ╤Б╨╕╤Б╤В╨╡╨╝╨╜╨░╤П ╨║╨╛╨╗╨╛╨┤╨░
  `sanskrit-roots-frequency` ╨▓ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╡╨╝ FSRS-╤В╤А╨╡╨╜╨░╨╢╤С╤А╨╡ (H211): 570 ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╤Б╨║╨╕╤Е
  ╨║╨╛╤А╨╜╨╡╨╣ ╨▓ ╨┐╨╛╤А╤П╨┤╨║╨╡ ╨║╨╛╤А╨┐╤Г╤Б╨╜╨╛╨╣ ╤З╨░╤Б╤В╨╛╤В╨╜╨╛╤Б╤В╨╕ (kosha
  [`roots_frequency.tsv`](https://github.com/gasyoun/kosha/blob/main/data/roots/roots_frequency.tsv),
  H950, Digital Corpus of Sanskrit тАФ Oliver Hellwig, CC BY 4.0), ╤Б ╤А╤Г╤Б╤Б╨║╨╕╨╝╨╕ ╨│╨╗╨╛╤Б╤Б╨░╨╝╨╕
  ╨╕╨╖ WhitneyRoots'
  [`crosswalk/ru_root_glosses.tsv`](https://github.com/gasyoun/WhitneyRoots/blob/main/crosswalk/ru_root_glosses.tsv)
  (H347). Join committed ╨▓
  [`database/seeders/data/build_roots_frequency_ru.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/build_roots_frequency_ru.py)
  (readable via `indic_transliteration` for the Devan─Бgar─л root form тАФ `sanskrit-util`'s
  `iast_to_devanagari` is display-only and mangles consonant-final roots, so it was not
  used here); 59 ╨╕╨╖ 629 DCS-ranked corpus roots have no RU gloss match тАФ logged to
  `database/seeders/data/roots_frequency_ru_unmatched.tsv`, not silently dropped. New
  `SrsRootFrequencyDeckSeeder` (idempotent, keyed by `fields->dcs_lemma`) inserts cards
  in rank order so `ReviewService::queueFor()`'s `orderBy('id')` new-card query serves
  the highest-yield roots first with no new sort column needed. Feature test seeds a
  10-root fixture and reviews one card end-to-end. No engine change. Deploy: one-time
  `php artisan db:seed --class=SrsRootFrequencyDeckSeeder` тАФ see
  [DEPLOY_QUEUE тДЦ39](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md);
  deck stays invisible to students until the existing `SRS_ENABLED` flag is on (R-6
  baseline protection, default OFF). Sonnet 5 (`claude-sonnet-5`).
  [H1280](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1280-Sonnet_Systema-Sanscriticum_srs-root-frequency-ru-deck_19.07.26.md).

## [1.28.0] - 2026-07-19

### Added
- **Corpus-frequency learner surfaces staged (queued, docs-only):** new plan
  [`docs/PLAN_SYSTEMA_CORPUS_FREQUENCY_LEARNER_SURFACES_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_CORPUS_FREQUENCY_LEARNER_SURFACES_2026H2.md)
  (+ metadoc) staging two Tier-0 integrations via
  [`/ask-batch`](https://github.com/gasyoun/claude-config/blob/main/commands/ask-batch.md):
  a frequency-ranked RU root SRS deck
  ([H1280](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1280-Sonnet_Systema-Sanscriticum_srs-root-frequency-ru-deck_19.07.26.md),
  kosha `roots_frequency.tsv` ├Ч WhitneyRoots RU glosses тЖТ existing FSRS stack) and a
  conjunct-frequency Devan─Бgar─л drill
  ([H1281](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1281-Sonnet_Systema-Sanscriticum_marathon-conjunct-frequency-order_19.07.26.md),
  `dcs-grapheme-frequency` тЖТ `public/lila/` family);
  [`docs/SRS_ROADMAP_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SRS_ROADMAP_2026.md)
  gains the content-deck row. Fable 5 (`claude-fable-5`).

## [1.27.0] - 2026-07-18

### Added
- **H1147: ESP transactional-email transport + `mail:preflight` guard тАФ fixes issue #504's repo-side root cause.** `.env.example` no longer ships `MAIL_HOST=mailpit` as if it were a production value тАФ local dev keeps mailpit, with a commented production shape adjacent pointing at the new [`docs/mail-esp.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mail-esp.md) setup contract (`.env` keys per driver class, SPF+DKIM+DMARC requirement, `mailing`-queue worker requirement). New `php artisan mail:preflight` command ([`app/Console/Commands/MailPreflight.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MailPreflight.php)) rejects a dev mail-catcher host or placeholder sender outside `APP_ENV=local` (non-zero exit, names the reason), warns (non-fatal) when `QUEUE_CONNECTION` isn't `sync`, and supports an opt-in `--send=<addr>` real test send; proven by `tests/Feature/Mail/MailPreflightTest.php` (7/7 green, no network by default). Added `symfony/mailgun-mailer` + `symfony/postmark-mailer` (+ `symfony/http-client`) so the existing `mailgun`/`postmark` blocks in `config/mail.php` are actually usable, alongside the already-generic `smtp` mailer тАФ vendor choice stays a human `@DECIDE` (R-3), no vendor hardcoded. [DEPLOY_QUEUE тДЦ37](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) has the exact deploy sequence. **Does not claim mail is delivered** тАФ issue #504 stays open until a human picks an ESP, creates the account, and installs the prod secret. Sonnet 5 (`claude-sonnet-5`). [H1147](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1147-Sonnet_Systema-Sanscriticum_esp-transactional-mail-transport-preflight_17.07.26.md).

## [1.26.0] - 2026-07-18

### Added
- **H1144 (W1-D1): ╨┐╤А╨╛╨╕╨╖╨▓╨╛╨┤╤Б╤В╨▓╨╡╨╜╨╜╨░╤П ╤Б╨┐╨╡╤Ж╨╕╤Д╨╕╨║╨░╤Ж╨╕╤П getcourse-╨┐╨░╤А╨╕╤В╨╡╤В╨░ тАФ R29-╤Н╨║╨▓╨╕╨▓╨░╨╗╨╡╨╜╤В, ╨║╨╛╤В╨╛╤А╨╛╨│╨╛ ╤В╤А╨╡╨▒╤Г╨╡╤В R-1.** [docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md) + [╨╝╨╡╤В╨░╨┤╨╛╨║](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.meta.md). 9 ╤А╨░╨╖╨┤╨╡╨╗╨╛╨▓: ╨║╨╛╨╝╨┐╨╛╨╖╨╕╤Ж╨╕╤П ╨▓╤Б╨╡╤Е 14 ╤В╨╕╨║╨╡╤В╨╛╨▓ GC-* ╤Б ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╨╡╨╝, **╤Б╨▓╨╡╤А╨╡╨╜╨╜╤Л╨╝ ╤Б ╨┤╨╡╤А╨╡╨▓╨╛╨╝** (`9b63861`) тАФ ╨┐╨╛ ╨╛╨┤╨╜╨╛╨╝╤Г read-only ╨░╨│╨╡╨╜╤В╤Г ╨╜╨░ ╤В╨╕╨║╨╡╤В, ╨║╨░╨╢╨┤╤Л╨╣ ╨▓╨╡╤А╨┤╨╕╨║╤В ╨║╤А╨╛╨╝╨╡ high-confidence `NOT_BUILT` ╨┐╨╡╤А╨╡╨┐╤А╨╛╨▓╨╡╤А╨╡╨╜ ╨▓╤В╨╛╤А╤Л╨╝ ╨░╨│╨╡╨╜╤В╨╛╨╝ ╤Б ╨╖╨░╨┤╨░╨╜╨╕╨╡╨╝ **╨╛╨┐╤А╨╛╨▓╨╡╤А╨│╨╜╤Г╤В╤М** ╨╡╨│╨╛ (25 ╨░╨│╨╡╨╜╤В╨╛╨▓); **╨╗╨╡╤Б╤В╨╜╨╕╤Ж╨░ ╨┐╤А╨╕╨╛╤А╨╕╤В╨╡╤В╨╛╨▓ ╨╖╨░╨┐╨╕╤Б╨╕** (┬з2) тАФ ╨╛╨▒╨╛╨▒╤Й╨╡╨╜╨╕╨╡ ╨┐╤А╨░╨▓╨╕╨╗╨░ ╨│╤А╨░╨╜╨╕╤Ж╤Л ╨┤╨╡╨╜╨╡╨╢╨╜╨╛╨│╨╛ ╤П╨┤╤А╨░ (┬л╤Б╨╗╨╛╨╣ `Deal` ╨╜╨░╨▒╨╗╤О╨┤╨░╨╡╤В ╨┤╨╡╨╜╨╡╨╢╨╜╨╛╨╡ ╤П╨┤╤А╨╛ ╨╕ ╨╜╨╕╨║╨╛╨│╨┤╨░ ╨╡╨│╨╛ ╨╜╨╡ ╨░╨▓╤В╨╛╤А╨╕╨╖╤Г╨╡╤В┬╗) ╨╜╨░ ╨▓╤Б╨╡ 14 ╤В╨╕╨║╨╡╤В╨╛╨▓, ╤В╨╛ ╤Б╨░╨╝╨╛╨╡ ╨┐╤А╨░╨▓╨╕╨╗╨╛, ╨║╨╛╤В╨╛╤А╨╛╨│╨╛ ╨╜╨╡╤В ╤Г ╤А╨╛╨░╨┤╨╝╨░╨┐╨░ ╨╕ ╨║╨╛╤В╨╛╤А╨╛╨╡ ╨▓╤Б╨╡╨│╨┤╨░ ╨╜╤Г╨╢╨╜╨╛ ╤Б╨▒╨╛╤А╤Й╨╕╨║╤Г; ╨┐╤А╨╛╨╕╨╖╨▓╨╛╨┤╤Б╤В╨▓╨╡╨╜╨╜╨░╤П ╨│╨╗╤Г╨▒╨╕╨╜╨░ ╨┐╨╛ GC-C1 (`Deal`+╨║╨░╨╜╨▒╨░╨╜, ╤В╨╛╤З╨║╨░ ╨┐╨╛╨┤╨║╨╗╤О╤З╨╡╨╜╨╕╤П ╨╝╨╛╤Б╤В╨░ тАФ `PaymentObserver.php:63`) ╨╕ GC-C2 (╨░╤В╤А╨╕╨▒╤Г╤Ж╨╕╤П ╨┐╨╛ ╨╝╨╡╨╜╨╡╨┤╨╢╨╡╤А╨░╨╝); ╨┤╨░╤В╨░-╨▒╨╕╨╗╨╗, ╨┐╨╗╨░╨╜ ╤Д╨╗╨░╨│╨╛╨▓, 8 ╨╜╨░╨╖╨▓╨░╨╜╨╜╤Л╤Е ╤А╨░╨╖╨▓╨╕╨╗╨╛╨║ (╨╜╨╕ ╨╛╨┤╨╜╨░ ╨╜╨╡ ╤А╨░╨╖╤А╨╡╤И╨╡╨╜╨░ тАФ ╤Н╤В╨╛ ╤А╨░╨▒╨╛╤В╨░ ╤З╨╡╨╗╨╛╨▓╨╡╨║╨░) ╨╕ ╨┐╨╛╤Б╨╗╨╡╨┤╨╛╨▓╨░╤В╨╡╨╗╤М╨╜╨╛╤Б╤В╤М ┬л╨╛╨┤╨╕╨╜ ╤И╨░╨│ = ╨╛╨┤╨╕╨╜ ╤Е╤Н╨╜╨┤╨╛╤Д╤Д┬╗.
  ╨в╤А╨╕ ╤Б╨╛╤Б╤В╨╛╤П╨╜╨╕╤П ╤А╨░╤Б╤Е╨╛╨┤╤П╤В╤Б╤П ╤Б ╤А╨╛╨░╨┤╨╝╨░╨┐╨╛╨╝ H438: **GC-B3 ╤З╨░╤Б╤В╨╕╤З╨╜╨╛ ╤Б╨┤╨░╨╜** ([PR #549](https://github.com/gasyoun/Systema-Sanscriticum/pull/549)), ╤Е╨╛╤В╤П ╤А╨╛╨░╨┤╨╝╨░╨┐ ╤З╨╕╤Б╨╗╨╕╤В ╨╡╨│╨╛ ╨▓ ┬лLater┬╗ тАФ ╨┐╤А╨╕ ╤Н╤В╨╛╨╝ ╨┐╤А╨╕╨▓╤П╨╖╨║╨░ ╨║╨╛╨╜╤В╨╡╨╣╨╜╨╡╤А╨░ **╨╜╨╡ ╨╕╨╝╨╡╨╡╤В ╨╜╨╕ ╨╛╨┤╨╜╨╛╨│╨╛ ╨┐╨╛╤В╤А╨╡╨▒╨╕╤В╨╡╨╗╤П** (╨▓╨╡╨▒╤Е╤Г╨║ ╤А╨╡╨╖╨╛╨╗╨▓╨╕╤В ╨║╨╛╨╜╨║╤А╨╡╤В╨╜╤Л╨╣ `ZoomService`), ╨░ ╨▒╨╗╨╛╨║╨░ `services.bbb` ╨╜╨╡╤В, ╤В╨░╨║ ╤З╤В╨╛ ╨░╨▒╤Б╤В╤А╨░╨║╤Ж╨╕╤П ╨╕╨╜╨╡╤А╤В╨╜╨░; **GC-A3** ╨┐╨╛╨╜╨╕╨╢╨╡╨╜ PARTIAL тЖТ NOT_BUILT (╨╖╨░ ┬л╤З╨░╤Б╤В╨╕╤З╨╜╤Г╤О ╤Б╨┤╨░╤З╤Г┬╗ ╨┐╤А╨╕╨╜╨╕╨╝╨░╨╗╨╕ ╨╛╨▒╤К╤П╨▓╨╗╨╡╨╜╨╜╤Г╤О ╤Б╨░╨╝╨╕╨╝ ╤В╨╕╨║╨╡╤В╨╛╨╝ ╨▒╨░╨╖╤Г ╨┐╨╡╤А╨╡╨╕╤Б╨┐╨╛╨╗╤М╨╖╨╛╨▓╨░╨╜╨╕╤П); **GC-C1** ╤З╨░╤Б╤В╨╕╤З╨╜╨╛ ╤Б╨┤╨░╨╜, ╨╜╨╛ ╨▓ ╤Д╨╛╤А╨╝╨╡ **╨╛╤В╨▓╨╡╤А╨│╨╜╤Г╤В╨╛╨╣** ╨░╤А╤Е╨╕╤В╨╡╨║╤В╤Г╤А╤Л.
  ╨У╨╗╨░╨▓╨╜╨░╤П ╨╜╨░╤Е╨╛╨┤╨║╨░ тАФ ╤А╨░╨╖╨▓╨╕╨╗╨║╨░ F2: **╨┤╨▓╨░ ╨╢╨╕╨▓╤Л╤Е ╤Г╨┐╤А╨░╨▓╨╗╤П╤О╤Й╨╕╤Е ╤А╨╡╤И╨╡╨╜╨╕╤П ╨┐╤А╨╛╤В╨╕╨▓╨╛╤А╨╡╤З╨░╤В ╨┤╤А╤Г╨│ ╨┤╤А╤Г╨│╤Г.** [Uprava DECISIONS_roadmap_forks_2026H2.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_roadmap_forks_2026H2.md) ┬зR2 (10-07) ╤А╤Г╨╗╨╕╤В ┬л╤А╨░╤Б╤И╨╕╤А╤П╤В╤М `Lead`┬╗, ROADMAP ┬з5 (11-07 00:01) ╤А╤Г╨╗╨╕╤В ┬л╨╛╤В╨┤╨╡╨╗╤М╨╜╨░╤П ╤Б╤Г╤Й╨╜╨╛╤Б╤В╤М `Deal`┬╗; H451 ╤Б╨┤╨░╨╗ `LeadStage`+`LeadKanbanBoard` 10-07 11:06 тАФ **╨╝╨╡╨╢╨┤╤Г** ╨╜╨╕╨╝╨╕, ╨║╨╛╤А╤А╨╡╨║╤В╨╜╨╛ ╨╕╤Б╨┐╨╛╨╗╨╜╨╕╨▓ ╨┤╨╡╨╣╤Б╤В╨▓╨╛╨▓╨░╨▓╤И╨╕╨╣ ╤В╨╛╨│╨┤╨░ ╤А╤Г╨╗╨╕╨╜╨│. ┬зR2 ╨╜╨╕╨║╨╛╨│╨┤╨░ ╨╜╨╡ ╨▒╤Л╨╗ ╨┐╨╛╨╝╨╡╤З╨╡╨╜ ╨║╨░╨║ superseded. ╨в╤А╨╡╨▒╤Г╨╡╤В╤Б╤П ╤А╨╡╤И╨╡╨╜╨╕╨╡ ╤З╨╡╨╗╨╛╨▓╨╡╨║╨░. Opus 4.8 (`claude-opus-4-8`). [H1144](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1144-Opus_Systema-Sanscriticum_getcourse-parity-production-spec-r29-equivalent_17.07.26.md).

## [1.25.0] - 2026-07-18

### Added
- **H1146 (W1-D5): Memrise course 6679375 export runner + validator (time-critical, irreversible).** Memrise is sunsetting community courses with no published shutdown date; an agent cannot obtain a Memrise login, so the deliverable shrinks the human's export step to two commands. [`scripts/memrise_export.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/memrise_export.py) (stdlib-only, credential from `MEMRISE_SESSION` env var, never argv; `--dry-run`) emits exactly the `manifest.json` + `level_NN.csv` contract already read by `php artisan srs:import-memrise` ([`ImportMemriseSrsDeck.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportMemriseSrsDeck.php)). [`scripts/memrise_export_validate.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/memrise_export_validate.py) checks that contract with no network and no credentials тАФ manifest parses, every declared level file exists, every CSV header contains every manifest-declared column, no empty levels тАФ proven against [`tests/fixtures/memrise_sample/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/fixtures/memrise_sample) and against both failure modes independently (removed level file, renamed CSV header). Runner is untested against live Memrise (no agent credentials) тАФ see [the destination README](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/memrise_6679375/README.md) for the honest boundary and the CourseDump2022 fallback. Sonnet 5 (`claude-sonnet-5`). [H1146](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1146-Sonnet_Systema-Sanscriticum_memrise-export-runner-validator-6679375_17.07.26.md).

## [1.24.0] - 2026-07-18

### Added
- **H1224: ┬л╨Ц╨╕╨╖╨╜╨╡╨╜╨╜╤Л╨╡ ╨┐╤А╨░╨▓╨╕╨╗╨░ ╨┤╨╗╤П ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨╛╨╗╨╛╨│╨╛╨▓┬╗ тАФ ╨╜╨╛╨▓╤Л╨╣ ╤А╨░╨╖╨┤╨╡╨╗ ╨╗╨╡╨╜╨┤╨╕╨╜╨│╨░ samskrte.ru.** ╨Э╨╛╨▓╤Л╨╣ Filament-╨▒╨╗╨╛╨║ ╨║╨╛╨╜╤Б╤В╤А╤Г╨║╤В╨╛╤А╨░ `life_rules_block` (17-╨╣ ╨▓ `LandingPageResource`): 45 ╨╝╨░╨║╤Б╨╕╨╝ ╨╕╨╖ [docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md) (H1215 v2) ╨┐╤А╨╡╨┤╨╖╨░╨┐╨╛╨╗╨╜╨╡╨╜╤Л ╨┤╨╡╤Д╨╛╨╗╤В╨╛╨╝ Repeater-╨┐╨╛╨╗╤П тАФ ╨║╤Г╤А╨░╤В╨╛╤А ╨┐╤А╨╛╤Б╤В╨╛ ╨┐╨╡╤А╨╡╤В╨░╤Б╨║╨╕╨▓╨░╨╡╤В ╨▒╨╗╨╛╨║ ╨╜╨░ ╨╗╨╡╨╜╨┤╨╕╨╜╨│, ╤В╨╡╨║╤Б╤В ╤А╨╡╨┤╨░╨║╤В╨╕╤А╤Г╨╡╤В╤Б╤П ╤З╨╡╤А╨╡╨╖ ╨░╨┤╨╝╨╕╨╜╨║╤Г. ╨а╨╡╨╜╨┤╨╡╤А тАФ ╤Б╨┐╨╗╨╛╤И╨╜╨╛╨╣ ╨┐╨╛╤В╨╛╨║ ╨▒╨╡╨╖ ╨░╨║╨║╨╛╤А╨┤╨╡╨╛╨╜╨░ (╨┐╨╛ ╨╛╨▒╤А╨░╨╖╤Ж╤Г ╤И╤Г╨╝╨░╨╜╨╛╨▓╤Б╨║╨╕╤Е Lebensregeln), ╤Б╨▓╤С╤А╨╜╤Г╤В╤Л╨╣ ╨┤╨╛ 7 ╨┐╤А╨░╨▓╨╕╨╗ ╤Б ╨║╨╜╨╛╨┐╨║╨╛╨╣ ╤А╨░╨╖╨▓╨╛╤А╨╛╤В╨░ (Alpine.js, ╤Б╤В╨╕╨╗╤М `faq_block`). ╨а╨░╨╖╨┤╨╡╨╗ ╨╗╨╡╨╜╨┤╨╕╨╜╨│╨░, ╨╜╨╡ ╨╛╤В╨┤╨╡╨╗╤М╨╜╨░╤П ╤Б╤В╤А╨░╨╜╨╕╤Ж╨░ тАФ ╤А╤Г╨╗╨╡╨╜╨╕╨╡ MG 18-07-2026 ([╨╝╨╡╤В╨░╨┤╨╛╨║](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.meta.md)). 4/4 ╤В╨╡╤Б╤В╨░ ╨╖╨╡╨╗╤С╨╜╤Л╨╡ ([`tests/Feature/LifeRulesBlockTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/LifeRulesBlockTest.php)). Sonnet 5 (`claude-sonnet-5`). [H1224](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1224-Sonnet_Systema-Sanscriticum_lebensregeln-landing-section_18.07.26.md).

## [1.23.0] - 2026-07-18

### Changed
- **H1215/H1224: ┬л╨Ц╨╕╨╖╨╜╨╡╨╜╨╜╤Л╨╡ ╨┐╤А╨░╨▓╨╕╨╗╨░┬╗ тАФ ╨╛╨┐╨╡╤А╨░╤Ж╨╕╨╛╨╜╨╜╨░╤П ╨╝╨╛╨┤╨╡╨╗╤М ╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨░ ╨▓ ╨╝╨╡╤В╨░╨┤╨╛╨║╨╡: ╨║╨▓╨░╤А╤В╨░╨╗╤М╨╜╤Л╨╣ ╤Ж╨╕╨║╨╗ ╤А╨╡╨▓╨╕╨╖╨╕╨╣, ╤В╤А╨╕ ╨╜╨╡╨┤╨╛╤Б╤В╨░╤О╤Й╨╕╤Е ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║╨░ v3, ╤А╤Г╨╗╨╡╨╜╨╕╨╡ ╨┐╤Г╨▒╨╗╨╕╨║╨░╤Ж╨╕╨╕.** ╨Я╨╛ follow-up-╤А╤Г╨╗╨╡╨╜╨╕╤П╨╝ MG 18-07-2026 (╨┐╨╛╤Б╨╗╨╡ ╨╝╨╡╤А╨┤╨╢╨░ v2): (1) ╨┤╨╛╨║╤Г╨╝╨╡╨╜╤В ╨╢╨╕╨▓╨╛╨╣, ╤А╨╡╨▓╨╕╨╖╨╕╤П ╨║╨░╨╢╨┤╤Л╨╡ ~3 ╨╝╨╡╤Б╤П╤Ж╨░ ╨┐╨╛ ╨╝╨╡╤А╨╡ ╤А╨╛╤Б╤В╨░ ╨║╨╛╤А╨┐╤Г╤Б╨░ тАФ ╨╜╤Л╨╜╨╡╤И╨╜╨╕╨╡ ~40 ╨║╤Г╤А╤Б╨╛╨▓ `Uprava/stenogrammy` ╨╗╨╕╤И╤М ╨╝╨░╨╗╨░╤П ╤З╨░╤Б╤В╤М ╨▓╤Б╨╡╤Е ╤Б╤В╨╡╨╜╨╛╨│╤А╨░╨╝╨╝ ╤И╨║╨╛╨╗╤Л; (2) ╨║╨╛╤А╨┐╤Г╤Б v3: ╤Б╤В╨╡╨╜╨╛╨│╤А╨░╨╝╨╝╤Л ╨▓╤Л╤Б╤В╤Г╨┐╨╗╨╡╨╜╨╕╨╣ 2019тАУ2022 + ╨╕╨╜╤В╨╡╤А╨▓╤М╤О ╤Б ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨╛╨╗╨╛╨│╨░╨╝╨╕ **┬л╨Ш╨┐╨╛╤Б╤В╨░╤Б╨╕ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨░┬╗** ╨╕ **┬л╨б╨░╨╜╤Б╨║╤А╨╕╤В ╨▓ ╨Т╨╡╨╜╤Б╨║╨╛╨╝ ╤Г╨╜╨╕╨▓╨╡╤А╤Б╨╕╤В╨╡╤В╨╡┬╗** (╨▓ v2 ╨╜╨╡ ╨╖╨░╨┤╨╡╨╣╤Б╤В╨▓╨╛╨▓╨░╨╜╤Л, ╤Д╨░╨╣╨╗╨╛╨▓ ╨╜╨░ ╨┤╨╕╤Б╨║╨╡ ╨╜╨╡╤В тАФ ╨┐╨╡╤А╨╡╨┤╨░╨╡╤В MG); (3) ╨┐╤Г╨▒╨╗╨╕╨║╨░╤Ж╨╕╤П ╨а╨г╨Ы╨Х╨Э╨Р: **╤А╨░╨╖╨┤╨╡╨╗ ╨╗╨╡╨╜╨┤╨╕╨╜╨│╨░** samskrte.ru, ╨╜╨╡ ╨╛╤В╨┤╨╡╨╗╤М╨╜╨░╤П ╤Б╤В╤А╨░╨╜╨╕╤Ж╨░ тАФ ╨▓╨╜╨╡╨┤╤А╨╡╨╜╨╕╨╡ ╨▓╤Л╨╜╨╡╤Б╨╡╨╜╨╛ ╨▓ [H1224](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1224-Sonnet_Systema-Sanscriticum_lebensregeln-landing-section_18.07.26.md) (Sonnet 5, `claude-sonnet-5`), ╨╖╨░╤В╨╡╨╝ DEPLOY_QUEUE. ╨Ю╨▒╨╜╨╛╨▓╨╗╨╡╨╜ [╨╝╨╡╤В╨░╨┤╨╛╨║](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.meta.md) (╨╜╨░╨╖╨╜╨░╤З╨╡╨╜╨╕╨╡, ╤А╨╡╤И╨╡╨╜╨╕╤П, ╨▒╤Н╨║╨╗╨╛╨│, ╨╛╨│╤А╨░╨╜╨╕╤З╨╡╨╜╨╕╤П, ╨╕╤Б╤В╨╛╤А╨╕╤П). Fable 5 (`claude-fable-5`).

## [1.22.0] - 2026-07-18

### Changed
- **H1215 (v2): ┬л╨Ц╨╕╨╖╨╜╨╡╨╜╨╜╤Л╨╡ ╨┐╤А╨░╨▓╨╕╨╗╨░ ╨┤╨╗╤П ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨╛╨╗╨╛╨│╨╛╨▓┬╗ тАФ ╤А╨╡╨▓╨╕╨╖╨╕╤П ╨┐╨╛ ╤Б╤В╨╡╨╜╨╛╨│╤А╨░╨╝╨╝╨░╨╝, 40 тЖТ 45 ╨╝╨░╨║╤Б╨╕╨╝.** ╨Я╨╡╤А╨▓╤Л╨╣ ╨┐╤А╨╛╨│╨╛╨╜ ╨╢╨╕╨▓╨╛╨│╨╛ ╨╝╨░╤В╨╡╤А╨╕╨░╨╗╨░ ╤И╨║╨╛╨╗╤Л ╤З╨╡╤А╨╡╨╖ ╨╝╨░╨╜╨╕╤Д╨╡╤Б╤В: 17 ╤Н╨║╤Б╤В╤А╨░╨║╤В╨╛╤А╨╛╨▓ Sonnet 5 (`claude-sonnet-5`) ╨┐╤А╨╛╤И╨╗╨╕ ~60 MB ╤Б╤В╨╡╨╜╨╛╨│╤А╨░╨╝╨╝ (`Uprava/stenogrammy` + `lecture-ui/transcription`) тАФ 28 ╨▓╨▓╨╛╨┤╨╜╤Л╤Е ╨У╨░╤Б╤Г╨╜╤Б╨░, ╨▓╤Б╨╡ 16 ╨╖╨░╨╜╤П╤В╨╕╨╣ ╨Я╨░╤А╨╕╨▒╨║╨░ ┬л╨Щ╨╛╨│╨░-╤Б╤Г╤В╤А╤Л┬╗ 2025 (╨┐╤А╨╕╨╛╤А╨╕╤В╨╡╤В MG), ╨Ъ╤Г╨╗╨╕╨║╨╛╨▓, ╨Ъ╨╗╨╡╨▒╨░╨╜╨╛╨▓, ╨С╤О╨╗╨╡╤А╨╛╨▓╨╡╨┤╨╡╨╜╨╕╨╡, ╤Б╨╕╨╜╤В╨░╨║╤Б╨╕╤Б, ╨╗╨╕╨║╨▒╨╡╨╖╤Л, ╨┐╨╛╤В╨╛╨║╨╕, ╨║╨░╨╗╨╗╨╕╨│╤А╨░╤Д╨╕╤П, ╤З╤В╨╡╨╜╨╕╨╡ (╨е╨╕╤В╨╛╨┐╨░╨┤╨╡╤И╨░/╨Э╨░╨╗╤М/╨У╨╕╤В╨░/╨г╨┐╨░╨╜╨╕╤И╨░╨┤╤Л), ╨▓╨╛╤Б╨┐╨╡╨▓╨░╨╜╨╕╨╡, ╨┤╨╡╤В╤Б╨║╨╕╨╣ ╨╕╨╜╤В╨╡╨╜╤Б╨╕╨▓; ╤Б╨╕╨╜╤В╨╡╨╖ тАФ Fable 5 (`claude-fable-5`). ╨Т╤Б╨╡ 40 ╨┐╤А╨░╨▓╨╕╨╗ v1 ╤Б╨╛╤Е╤А╨░╨╜╨╡╨╜╤Л, ~12 ╤Б╤Г╤Й╨╡╤Б╤В╨▓╨╡╨╜╨╜╨╛ ╨┐╨╡╤А╨╡╨┐╨╕╤Б╨░╨╜╤Л ╨┐╨╛ ╨╝╨░╤В╨╡╤А╨╕╨░╨╗╤Г (╨▓╤Е╨╛╨┤ ╤З╨╡╤А╨╡╨╖ ╨╜╨░╤Б╨╗╤Г╤И╨╕╨▓╨░╨╜╨╕╨╡; ┬л╨▒╨╕╨▒╨╗╨╕╨╛╤В╨╡╨║╨░ ╨╜╨░╨╕╨╖╤Г╤Б╤В╤М┬╗ ╤В╤А╨╡╨▒╤Г╨╡╤В ╨┐╨╡╤А╨╡╤Г╤З╨╡╤В╨░; ╤Б╨▓╨╛╤П ╤А╤Г╨║╨╛╨┐╨╕╤Б╨╜╨░╤П ╤В╨░╨▒╨╗╨╕╤Ж╨░ ╨┐╤А╨╛╤В╨╕╨▓ ╤З╤Г╨╢╨╛╨╣ ╨┐╨╡╤З╨░╤В╨╜╨╛╨╣; ╨╗╨╡╤Б╤В╨╜╨╕╤Ж╨░ ╤Б╨╗╨╛╨▓╨░╤А╨╡╨╣ ╤Б ╨Ъ╨╜╨░╤Г╤Н╤А╨╛╨╝; ╨┐╨╛╨┤╤Б╤В╤А╨╛╤З╨╜╨╕╨║-┬л╨┐╤А╨╛╤В╨╡╨╖┬╗ ╨Я╨░╤А╨╕╨▒╨║╨░; ┬л╨╕╨╖ ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨░ ╨▓╤Л╤И╨╗╨░ ╨╜╨░╤Г╨║╨░ ╨╛ ╤П╨╖╤Л╨║╨╡, ╨╜╨╡ ╤П╨╖╤Л╨║╨╕┬╗; ╨С╨╡╤В╨╗╨╕╨╜╨│╨║ ╨┐╨╛ ╤А╤Г╨║╨╛╨┐╨╕╤Б╤П╨╝ ╨┤╨╛ ╨║╤А╨╕╤В╨╕╤З╨╡╤Б╨║╨╕╤Е ╨╕╨╖╨┤╨░╨╜╨╕╨╣; ╤Б╨╛╨╜ ╨║╨░╨║ ╨┐╤А╨╕╨╝╨╡╤В╨░ ╨┐╨╛╨│╤А╤Г╨╢╨╡╨╜╨╕╤П), +5 ╨╜╨╛╨▓╤Л╤Е ╨╕╨╖ ╤Б╤В╨╡╨╜╨╛╨│╤А╨░╨╝╨╝ (╤А╨░╨╖╨▒╨╛╤А ╤Б╨╗╨╛╨▓╨░ ╤Б ╨║╨╛╨╜╤Ж╨░, ╤Г╤Б╨╕╨┤╤З╨╕╨▓╨╛╤Б╤В╤М 70тЖТ4, ╨▓╨╛╨╖╨▓╤А╨░╤Й╨╡╨╜╨╕╨╡ ╨┐╨╛╤Б╨╗╨╡ ╨┐╨╡╤А╨╡╤А╤Л╨▓╨░, ┬л╨▓╨╛╤А╨╛╤Е ╨║╨╜╨╕╨│┬╗, ╨┐╤А╨╛╨╖╨░ ╨┐╤А╨╡╨╢╨┤╨╡ ╤Б╤В╨╕╤Е╨╛╨▓). ╨д╨░╨║╤В-╤З╨╡╨║ ╨┐╨┐. 26/32/33 ╨╖╨░╨║╤А╤Л╤В (┬л╨▓╨╛╤Б╤М╨╝╨╕╨║╨╗╨░╤Б╤Б╨╜╨╕╨║╨╕┬╗ тЖТ ┬л╤И╨║╨╛╨╗╤М╨╜╨╕╨║╨╕┬╗ ╨┐╨╛ [Arzamas](https://arzamas.academy/mag/142-zaliznyak)/[╨Ь╨У╨г](https://msu.ru/press/smiaboutmsu/onlayn-traditsionnaya-lektsiya-akademika-andreya-zaliznyaka-o-berestyanykh-gramotakh.html)). ╨Ь╨╡╤В╨░╨┤╨╛╨║: ╨┐╤А╨╛╨▓╨╡╨╜╨░╨╜╤Б v2, ╨▒╤Н╨║╨╗╨╛╨│ (╤Б╨╗╨╡╨┤╤Г╤О╤Й╨╡╨╡ тАФ ╤Б╤В╨╡╨╜╨╛╨│╤А╨░╨╝╨╝╤Л ╨▓╤Л╤Б╤В╤Г╨┐╨╗╨╡╨╜╨╕╨╣ 2019тАУ2022 ╤Г MG, ╨╖╨░╤В╨╡╨╝ @DECIDE ╨┐╤Г╨▒╨╗╨╕╨║╨░╤Ж╨╕╤П ╨╜╨░ samskrte.ru). ╨в╨╡╨║╤Б╤В: [docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md); [H1215](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1215-Fable_Systema-Sanscriticum_lebensregeln-sanskritologov_18.07.26.md).

## [1.21.0] - 2026-07-18

### Added
- **H1215 (v1): ┬л╨Ц╨╕╨╖╨╜╨╡╨╜╨╜╤Л╨╡ ╨┐╤А╨░╨▓╨╕╨╗╨░ ╨┤╨╗╤П ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨╛╨╗╨╛╨│╨╛╨▓┬╗ тАФ 40 ╨╝╨░╨║╤Б╨╕╨╝ ╨┐╨╛ ╨╛╨▒╤А╨░╨╖╤Ж╤Г ╨и╤Г╨╝╨░╨╜╨░, ╨│╨╛╨╗╨╛╤Б╨╛╨╝ ╨Ч╨░╨╗╨╕╨╖╨╜╤П╨║╨░.** ╨Ь╨░╨╜╨╕╤Д╨╡╤Б╤В ╤И╨║╨╛╨╗╤Л samskrte.ru: ╨╢╨░╨╜╤А╨╛╨▓╨░╤П ╤А╨░╨╝╨║╨░ тАФ ┬лMusikalische Haus- und Lebensregeln┬╗ ╨и╤Г╨╝╨░╨╜╨░ (1848), ╨│╨╛╨╗╨╛╤Б тАФ RWS-╤А╨╡╨│╨╕╤Б╤В╤А╤Л [zalizniak-method](https://github.com/gasyoun/RuWritingStyles/blob/main/styles/passports/zalizniak-method.yml) + [zalizniak-shkolnikov-1](https://github.com/gasyoun/RuWritingStyles/blob/main/styles/passports/zalizniak-shkolnikov-1.yml); ╨╛╤Б╨╕: ╤Г╤Е╨╛ vs. ╨│╨╗╨░╨╖ (╤Г╤Б╤В╨╜╨░╤П ╨Ш╨╜╨┤╨╕╤П ╨┐╤А╨╛╤В╨╕╨▓ ╤В╨░╨▒╨╗╨╕╤З╨╜╨╛╨╣ ╨Х╨▓╤А╨╛╨┐╤Л тАФ ╨╛╨┐╨╕╤А╨░╨╡╤В╤Б╤П ╨╜╨░ ╨┤╨╕╨░╨│╨╜╨╛╨╖ ┬лNO AUDIO┬╗ ╨╕╨╖ [DIGITAL_SANSKRIT_PEDAGOGY_FIELD_2026.md](https://github.com/gasyoun/SanskritGrammar/blob/main/DIGITAL_SANSKRIT_PEDAGOGY_FIELD_2026.md) ┬з3.7) ┬╖ ╨╡╨╢╨╡╨┤╨╜╨╡╨▓╨╜╨╛╨╡ ╤А╨╡╨╝╨╡╤Б╨╗╨╛ ┬╖ ╨╕╨╜╤Б╤В╤А╤Г╨╝╨╡╨╜╤В╤Л ┬╖ ╨╝╨╡╤В╨╛╨┤ ┬╖ ╤Н╤В╨╛╤Б. ╨б╨┐╨╡╤Ж╨╕╤Д╨╕╨║╨░╤Ж╨╕╤П ╤Г╤В╨▓╨╡╤А╨╢╨┤╨╡╨╜╨░ ╨▓ ╨╕╨╜╤В╨╡╤А╨▓╤М╤О MG (3 ╤А╨░╤Г╨╜╨┤╨░, 11 ╨▓╨╛╨┐╤А╨╛╤Б╨╛╨▓) тАФ ╨╖╨░╤Д╨╕╨║╤Б╨╕╤А╨╛╨▓╨░╨╜╨░ ╨▓ [╨╝╨╡╤В╨░╨┤╨╛╨║╨╡](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.meta.md). ╨в╨╡╨║╤Б╤В: [docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md). ╨а╨╡╨▓╨╕╨╖╨╕╤П ╨┐╨╛ ╤Б╤В╨╡╨╜╨╛╨│╤А╨░╨╝╨╝╨░╨╝ (╨▓╤Л╤Б╤В╤Г╨┐╨╗╨╡╨╜╨╕╤П MG, ╨╖╨░╨┐╨╕╤Б╨╕ ╨║╤Г╤А╤Б╨╛╨▓, ╨╕╨╜╤В╨╡╤А╨▓╤М╤О ╤Б╨░╨╜╤Б╨║╤А╨╕╤В╨╛╨╗╨╛╨│╨╛╨▓) = [H1215](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1215-Fable_Systema-Sanscriticum_lebensregeln-sanskritologov_18.07.26.md). [PR #564](https://github.com/gasyoun/Systema-Sanscriticum/pull/564). Fable 5 (`claude-fable-5`).

## [1.20.0] - 2026-07-18

### Added
- **H1197 (Jivo-╨┐╨░╤А╨╕╤В╨╡╤В S2/5, Pillar 2): ╨┐╤А╨╛╨░╨║╤В╨╕╨▓╨╜╤Л╨╣ ╨╝╨╛╨╜╨╕╤В╨╛╤А ╨┐╨╛╤Б╨╡╤В╨╕╤В╨╡╨╗╨╡╨╣ + ╨╛╨┐╨╡╤А╨░╤В╨╛╤А ╨┐╨╕╤И╨╡╤В ╨┐╨╡╤А╨▓╤Л╨╝.** ╨Т╤В╨╛╤А╨╛╨╣ ╤Г╨╜╨╕╨║╨░╨╗╤М╨╜╤Л╨╣ ╤Б╤В╨╛╨╗╨┐ Jivo: ╨║╤Г╤А╨░╤В╨╛╤А ╨▓╨╕╨┤╨╕╤В **╨╢╨╕╨▓╨╛╨╣ ╤Б╨┐╨╕╤Б╨╛╨║ ╨┐╨╛╤Б╨╡╤В╨╕╤В╨╡╨╗╨╡╨╣ ╨╜╨░ ╤Б╨░╨╣╤В╨╡ ╤Б╨╡╨╣╤З╨░╤Б** (╨│╨╛╤А╨╛╨┤ ╨╕╨╖ S1, ╤В╨╡╨║╤Г╤Й╨░╤П ╤Б╤В╤А╨░╨╜╨╕╤Ж╨░, ╨▓╤А╨╡╨╝╤П ╨╜╨░ ╤Б╨░╨╣╤В╨╡) тАФ ╨▓╨║╨╗╤О╤З╨░╤П ╤В╨╡╤Е, ╨║╤В╨╛ ╨╡╤Й╤С ╨╜╨╕╤З╨╡╨│╨╛ ╨╜╨╡ ╨╜╨░╨┐╨╕╤Б╨░╨╗, тАФ ╨╕ ╨╝╨╛╨╢╨╡╤В **╨╜╨░╨┐╨╕╤Б╨░╤В╤М ╨┐╨╡╤А╨▓╤Л╨╝**; ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡ ╨▓╤Б╨┐╨╗╤Л╨▓╨░╨╡╤В ╨▓ ╤З╨░╤В-╨▓╨╕╨┤╨╢╨╡╤В╨╡ ╨┐╨╛╤Б╨╡╤В╨╕╤В╨╡╨╗╤П. ╨Э╨╛╨▓╨░╤П ╤Н╤Д╨╡╨╝╨╡╤А╨╜╨░╤П ╤В╨░╨▒╨╗╨╕╤Ж╨░ ([`create_support_visitor_presences_table`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_17_130000_create_support_visitor_presences_table.php)); presence-beacon [`PublicPresenceController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicPresenceController.php) `POST /support/presence` ╨░╨┐╤Б╨╡╤А╤В╨╕╤В ╤Б╤В╤А╨╛╨║╤Г ╨┐╨╛ `guest_token` (╤А╨╡╤О╨╖ H536), ╨╛╤В╨▓╨╡╤В ╨╜╨╡╤Б╤С╤В `conversation_id` тАФ ╤В╨░╨║ ╨┐╤А╨╛╨░╨║╤В╨╕╨▓ ╨║╤Г╤А╨░╤В╨╛╤А╨░ ╨┤╨╛╨╗╨╡╤В╨░╨╡╤В ╨┤╨╛ ╨╝╨╛╨╗╤З╨░╤Й╨╡╨│╨╛ ╨┐╨╛╤Б╨╡╤В╨╕╤В╨╡╨╗╤П; ╨│╨╡╨╛ ╤А╨╡╨╖╨╛╨╗╨▓╨╕╤В╤Б╤П ╤В╨╡╨╝ ╨╢╨╡ [`VisitorGeoResolver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/VisitorGeoResolver.php) (S1) ╤З╨╡╤А╨╡╨╖ [`ResolveVisitorPresenceGeoJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/ResolveVisitorPresenceGeoJob.php); [`PruneStaleVisitorPresencesJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/PruneStaleVisitorPresencesJob.php) ╨▓╤Л╨╝╨╡╤В╨░╨╡╤В ╤Г╤Б╤В╨░╤А╨╡╨▓╤И╨╕╨╡ (╨╛╨║╨╜╨░ тАФ [`config/support_presence.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/support_presence.php)). ╨Ю╨┐╨╡╤А╨░╤В╨╛╤А╤Б╨║╨░╤П ╤Б╤В╤А╨░╨╜╨╕╤Ж╨░ [`VisitorsOnline`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/VisitorsOnline.php) ┬л╨Я╨╛╤Б╨╡╤В╨╕╤В╨╡╨╗╨╕ ╨╛╨╜╨╗╨░╨╣╨╜┬╗ (╨│╨╡╨╣╤В: ╤Д╨╗╨░╨│ + ╨╜╨╡-╨┐╤А╨╡╨┐╨╛╨┤╨░╨▓╨░╤В╨╡╨╗╤М, ╨║╨░╨║ `Helpdesk`): ╨╢╨╕╨▓╨╛╨╣ ╤Б╨┐╨╕╤Б╨╛╨║ (`wire:poll`) + ╨║╨╜╨╛╨┐╨║╨░ ┬л╨Э╨░╨┐╨╕╤Б╨░╤В╤М┬╗ тАФ ╤В╤А╨╡╨┤ ╨╛╤В╨║╤А╤Л╨▓╨░╨╡╤В╤Б╤П/╨┐╨╡╤А╨╡╨╛╤В╨║╤А╤Л╨▓╨░╨╡╤В╤Б╤П (╤А╨╡╤О╨╖ `openForGuest`/`openFor`), curator-╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╡ ╨▒╤А╨╛╨┤╨║╨░╤Б╤В╨╕╤В╤Б╤П `ChatMessageSent`; ╨▓╨╕╨┤╨╢╨╡╤В [`support-chat-widget.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/support-chat-widget.blade.php) ╤И╨╗╤С╤В beacon ╤Б ╨┐╨╡╤А╨▓╨╛╨│╨╛ ╨╖╨░╤Е╨╛╨┤╨░ ╨╕ ╤А╨░╤Б╨║╤А╤Л╨▓╨░╨╡╤В╤Б╤П ╨╜╨░ ╨┐╤А╨╛╨░╨║╤В╨╕╨▓. **╨Ю╤Б╨╛╨╖╨╜╨░╨╜╨╜╨╛╨╡ ╨╛╤В╤Б╤В╤Г╨┐╨╗╨╡╨╜╨╕╨╡:** ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║ ╨┐╤А╨░╨▓╨┤╤Л тАФ beacon тЖТ ╤В╨░╨▒╨╗╨╕╤Ж╨░ (heartbeat) + `wire:poll`, ╨░ ╨╜╨╡ Reverb presence-╨║╨░╨╜╨░╨╗ (╨┤╨╡╤И╨╡╨▓╨╗╨╡ ╨┐╨╛ WS, ╨┐╨╛╨╗╨╜╨╛╤Б╤В╤М╤О ╤В╨╡╤Б╤В╨╕╤А╤Г╨╡╤В╤Б╤П ╨▒╨╡╨╖ Reverb; ╤Б╨╝. [ROADMAP ┬з3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md)). ╨С╨╛╤В╤Л ╨Э╨Х ╨┐╨╕╤И╤Г╤В ╨╗╤О╨┤╤П╨╝ ╤Б╨░╨╝╨╕ (╨┐╤А╨╕╨╜╤Ж╨╕╨┐ MG) тАФ ╨┐╤А╨╕╨│╨╗╨░╤И╨╡╨╜╨╕╨╡ ╤И╨╗╤С╤В ╤В╨╛╨╗╤М╨║╨╛ ╤З╨╡╨╗╨╛╨▓╨╡╨║. ╨Т╤Б╤С ╨╖╨░ ╤Д╨╗╨░╨│╨╛╨╝ `support_visitor_presence` (**OFF** ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О); **@DECIDE MG тАФ 152-╨д╨Ч sign-off** ╨╜╨░ ╨╛╤В╤Б╨╗╨╡╨╢╨╕╨▓╨░╨╜╨╕╨╡ ╨░╨╜╨╛╨╜╨╕╨╝╨╜╨╛╨│╨╛ ╨┐╨╛╤Б╨╡╤В╨╕╤В╨╡╨╗╤П (╨│╨╡╨╣╤В ╨┐╤А╨╛╨┤-╨▓╨║╨╗╤О╤З╨╡╨╜╨╕╤П, ╨╜╨╡ ╨▒╨╕╨╗╨┤╨░). 20 ╨╜╨╛╨▓╤Л╤Е ╤В╨╡╤Б╤В╨╛╨▓ ([`SupportVisitorPresenceTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SupportVisitorPresenceTest.php) 9 ┬╖ [`VisitorsOnlinePageTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/VisitorsOnlinePageTest.php) 9 ┬╖ +2 render) + full suite 1582 ╨╖╨╡╨╗╤С╨╜╤Л╨╡; ╨┤╨╡╨┐╨╗╨╛╨╣ тАФ [DEPLOY_QUEUE тДЦ32](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md). [PR #560](https://github.com/gasyoun/Systema-Sanscriticum/pull/560). Opus 4.8 (`claude-opus-4-8`).

## [1.19.0] - 2026-07-17

### Added
- **H1196 (Jivo-╨┐╨░╤А╨╕╤В╨╡╤В S1/5, Pillar 1): ╨│╨╡╨╛/╨│╨╛╤А╨╛╨┤ ╨┐╨╛╤Б╨╡╤В╨╕╤В╨╡╨╗╤П ╨▓╨╡╨▒-╤З╨░╤В╨░ ╨▓ ╨┐╨░╨╜╨╡╨╗╨╕ ╨║╤Г╤А╨░╤В╨╛╤А╨░.** ╨Ъ╤Г╤А╨░╤В╨╛╤А ╨▓ [Helpdesk](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Helpdesk.php) ╤В╨╡╨┐╨╡╤А╤М ╨▓╨╕╨┤╨╕╤В ┬лЁЯУН ╨У╨╛╤А╨╛╨┤, ╨б╤В╤А╨░╨╜╨░┬╗ ╨╕ ╤Б╤В╤А╨░╨╜╨╕╤Ж╤Г ╨▓╤Е╨╛╨┤╨░ ╨│╨╛╤Б╤В╤П тАФ ╤В╨╛╤В ╤Б╨░╨╝╤Л╨╣ ╨▓╨╕╨╖╨╕╤В╨╛╤А-╤Б╨╗╨╛╨╣, ╤А╨░╨┤╨╕ ╨║╨╛╤В╨╛╤А╨╛╨│╨╛ ╨┤╨╡╤А╨╢╨░╤В Jivo ╨╜╨░ samskrtam.ru. ╨Р╨┤╨┤╨╕╤В╨╕╨▓╨╜╨░╤П ╨╝╨╕╨│╤А╨░╤Ж╨╕╤П ([`add_visitor_context_to_support_conversations`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_17_120000_add_visitor_context_to_support_conversations.php)) ╨┤╨╛╨▒╨░╨▓╨╗╤П╨╡╤В `visitor_ip/city/region/country/geo_resolved_at/entry_url/referrer` ╨╜╨░ ╤В╤А╨╡╨┤; [`PublicChatController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicChatController.php) ╤Д╨╕╨║╤Б╨╕╤А╤Г╨╡╤В IP+╤Б╤В╤А╨░╨╜╨╕╤Ж╤Г+referrer ╨┐╤А╨╕ ╨┐╨╡╤А╨▓╨╛╨╝ ╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╨╕ (╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╜╨╛, ╨▒╨╡╨╖ ╨▓╨╜╨╡╤И╨╜╨╕╤Е ╨▓╤Л╨╖╨╛╨▓╨╛╨▓), ╨░ `ResolveVisitorGeoJob` тЖТ `VisitorGeoResolver` ╤А╨╡╨╖╨╛╨╗╨▓╤П╤В ╨│╨╛╤А╨╛╨┤ ╨░╤Б╨╕╨╜╤Е╤А╨╛╨╜╨╜╨╛ ╨┐╨╛ ╨┤╤А╨░╨╣╨▓╨╡╤А╤Г ╨╕╨╖ [`config/support_geo.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/support_geo.php) (`null`-╨┤╨╡╤Д╨╛╨╗╤В / `cloudflare` / `ipapi`). ╨Т╨╕╨┤╨╢╨╡╤В ╤И╨╗╤С╤В `page`. ╨Т╤Б╤С ╨╖╨░ ╤Д╨╗╨░╨│╨╛╨╝ `support_visitor_geo` (**OFF** ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О); ╨┐╤А╨╛╨▓╨░╨╣╨┤╨╡╤А ╨│╨╛╤А╨╛╨┤╨░ тАФ @DECIDE MG (╤Б╨╝. [ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md) ┬з2). 11 ╤В╨╡╤Б╤В╨╛╨▓ [`SupportVisitorGeoTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SupportVisitorGeoTest.php) + 23 ╤А╨╡╨│╤А╨╡╤Б╤Б╨╕╨╛╨╜╨╜╤Л╤Е ╤З╨░╤В-╤В╨╡╤Б╤В╨░ ╨╖╨╡╨╗╤С╨╜╤Л╨╡; ╨┤╨╡╨┐╨╗╨╛╨╣ тАФ [DEPLOY_QUEUE тДЦ31](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md). ╨Э╨╛╨▓╤Л╨╣ [`docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md) ╤Б╤В╨░╨▓╨╕╤В ╨╖╨░╨┤╨░╤З╨╕ ╨┐╨╛ ╨▓╤Б╨╡╨╝ 6 ╤В╤А╨╡╨▒╨╛╨▓╨░╨╜╨╕╤П╨╝ ╨┐╨░╤А╨╕╤В╨╡╤В╨░ (S1 ╤Б╨┤╨╡╨╗╨░╨╜; S2тАУS5 = H1197тАУH1200). Opus 4.8 (`claude-opus-4-8`).

## [1.18.1] - 2026-07-17
### Fixed
- **H1145: `config/srs.php` default restored to `false` (R-6 baseline protection).** The default had been flipped to `true` by H447 (PR #442, commit `6267d70`) for an August-2026 pilot rationale superseded by R-5/R-6 тАФ three other places (the same file's docblock, `routes/web.php` ~L260, `DEPLOY_QUEUE.md` #24) still asserted OFF-by-default, so an unpatched deploy would have put an SRS nav entry in front of every student and corrupted the R20 baseline. `tests/Feature/Srs/SrsFlagDefaultTest.php` pins `config('srs.enabled') === false` and `GET /dvaram/koloda` тЖТ 404 with no `SRS_ENABLED` in env; full SRS suite (30 tests) and full `php artisan test` (1549 tests, 4478 assertions) green. Protects the R20 baseline тАФ does not start it (that clock begins only when a human deploys `DEPLOY_QUEUE.md` #25). [PR #553](https://github.com/gasyoun/Systema-Sanscriticum/pull/553).
### Added
- **W1-D4: ╨┐╤П╤В╤М Mailable ╨╝╨░╤А╨░╤Д╨╛╨╜╨░ ╨╕╨╖ ╤А╤Г╨╗╨╡╨▓╨╛╨│╨╛ ╨┐╨░╨║╨╡╤В╨░ H1067 (H1148).** `MarathonWelcomeMail`/`Day1`/`Day2`/`Day3`/`RecordingMail` + ╤И╨░╨▒╨╗╨╛╨╜╤Л `resources/views/emails/marathon/` тАФ ╤В╨╡╨║╤Б╤В ╨┐╨╡╤А╨╡╨╜╨╡╤Б╨╡╨╜ ╨Ф╨Ю╨б╨Ы╨Ю╨Т╨Э╨Ю ╨╕╨╖ [marathon-email-sequence.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-email-sequence.md) (╨╛╨▒╤А╨░╤Й╨╡╨╜╨╕╨╡ ┬л╨▓╤Л┬╗, ╨▒╨╡╨╖ ╤Н╨╝╨╛╨┤╨╖╨╕ ╨╕ ╤Б╤А╨╛╤З╨╜╨╛╤Б╤В╨╕ тАФ ╨░╨╜╤В╨╕-urgency ╨┤╨╕╨╖╨░╨╣╨╜ ╤Б╨╛╤Е╤А╨░╨╜╨╡╨╜); ╨┐╨╗╨╡╨╣╤Б╤Е╨╛╨╗╨┤╨╡╤А╤Л ╤В╨╛╨╗╤М╨║╨╛ ╤А╤Г╨╗╨╡╨▓╤Л╨╡ ({link}/{tg_link}/{date}/{host}/{coupon}/{recording_link}); Day3 ╨╜╨╡╤Б╨╡╤В ╨╛╨▒╨░ ╤В╤А╨╡╨║-╨▓╨░╤А╨╕╨░╨╜╤В╨░ (3╨░/3╨▒). ╨Т╤Б╨╡ ╨╜╨░ ╨╛╤З╨╡╤А╨╡╨┤╨╕ `mailing`, [MarathonMailablesTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Mail/MarathonMailablesTest.php) тАФ ╤А╨╡╨╜╨┤╨╡╤А/╤В╨╡╨╝╤Л/╨╛╤З╨╡╤А╨╡╨┤╤М/╨╛╤В╤Б╤Г╤В╤Б╤В╨▓╨╕╨╡ ╨╜╨╡╤А╨░╨╖╤А╨╡╤И╨╡╨╜╨╜╤Л╤Е ╨┐╨╗╨╡╨╣╤Б╤Е╨╛╨╗╨┤╨╡╤А╨╛╨▓ ╨╕ ╤Н╨╝╨╛╨┤╨╖╨╕. **╨Ю╤В╨┐╤А╨░╨▓╨║╨░ ╤Б╨╛╨╖╨╜╨░╤В╨╡╨╗╤М╨╜╨╛ ╨╕╨╜╨╡╤А╤В╨╜╨░**: send-╤Б╨░╨╣╤В╨╛╨▓ ╨▓╨╜╨╡ `app/Mail/` ╨╜╨╡╤В тАФ ╨║╨░╨╜╨░╨╗ ╨╢╨┤╨╡╤В ESP-╨│╨╡╨╣╤В╨░ (H1147), Telegram ╨╛╤Б╤В╨░╨╡╤В╤Б╤П ╨╛╤Б╨╜╨╛╨▓╨╜╤Л╨╝; DEPLOY_QUEUE тДЦ27a. Fable 5 (`claude-fable-5`) ╨┐╨╛ ╤А╨░╨╖╤А╨╡╤И╨╡╨╜╨╕╤О MG ╨╜╨░ Sonnet-╤А╤П╨┤.

## [1.18.0] - 2026-07-17
### Added
- **GC-B3: ╤И╨╛╨▓ `WebinarProvider` (╤Б╤В╤А╨░╤Е╨╛╨▓╨║╨░ ╨╛╤В ╤Г╤Е╨╛╨┤╨░ Zoom, ╤А╤Г╨╗╨╡╨╜╨╕╨╡ R1 тАФ BigBlueButton).** ╨Ш╨╜╤В╨╡╤А╤Д╨╡╨╣╤Б ╤Б ╤В╤А╨╡╨╝╤П ╨╝╨╡╤В╨╛╨┤╨░╨╝╨╕ (createMeeting / fetchParticipants / normalizeWebhook); `ZoomService` ╤А╨╡╨░╨╗╨╕╨╖╤Г╨╡╤В ╨╡╨│╨╛ ╨▒╨╡╨╖ ╨╕╨╖╨╝╨╡╨╜╨╡╨╜╨╕╤П ╨┐╨╛╨▓╨╡╨┤╨╡╨╜╨╕╤П (╨▓╨╡╨▒╤Е╤Г╨║-╨║╨╛╨╜╤В╤А╨╛╨╗╨╗╨╡╤А ╨┐╨╛╤В╤А╨╡╨▒╨╗╤П╨╡╤В `normalizeWebhook` тАФ ╤А╨░╨╖╨▒╨╛╤А ╨▒╨░╨╣╤В-╨▓-╨▒╨░╨╣╤В ╨┐╤А╨╡╨╢╨╜╨╕╨╣); ╤Б╨║╨╡╨╗╨╡╤В `BigBlueButtonService` ╤Б ╤Д╨╛╤А╨╝╨╛╨╣ BBB API (╨▒╤А╨╛╤Б╨░╨╡╤В ╨┤╨╛ ╤А╨░╨╖╨▓╨╡╤А╤В╤Л╨▓╨░╨╜╨╕╤П Q4); ╨┐╤А╨╛╨▓╨░╨╣╨┤╨╡╤А-╨╜╨╡╨╣╤В╤А╨░╨╗╤М╨╜╤Л╨╡ ╨░╨╗╨╕╨░╤Б╤Л `meeting_*` ╨┐╨╛╨▓╨╡╤А╤Е `zoom_*` (╤А╨╡╨▓╨╡╤А╤Б╨╕╨▓╨╜╨░╤П ╨╝╨╕╨│╤А╨░╤Ж╨╕╤П, ╨▒╤Н╨║╤Д╨╕╨╗╨╗ ╨║╨╛╨┐╨╕╨╡╨╣); ╨▒╨╕╨╜╨┤╨╕╨╜╨│ ╤И╨▓╨░ ╨╜╨░ Zoom-╨┤╤А╨░╨╣╨▓╨╡╤А. ╨Р╨▓╤В╨╛-╤Б╨╛╨╖╨┤╨░╨╜╨╕╨╡ Zoom-╨▓╤Б╤В╤А╨╡╤З ╨Э╨Х ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╛ тАФ ╨╛╤Б╤В╨░╨╡╤В╤Б╤П @DECIDE GC-B1. 7 unit-╤В╨╡╤Б╤В╨╛╨▓ ╤И╨▓╨░; CI ╨╖╨╡╨╗╨╡╨╜╤Л╨╣. [PR #549](https://github.com/gasyoun/Systema-Sanscriticum/pull/549) + ╨┤╨╡╨┐╨╗╨╛╨╣-╤Б╤В╤А╨╛╨║╨░ тДЦ29 ([PR #550](https://github.com/gasyoun/Systema-Sanscriticum/pull/550), ╨╛╨▒╤Й╨╕╨╣ `php artisan migrate`). H601, Fable 5 (`claude-fable-5`).

### Added
- **GC-B3: ╤И╨╛╨▓ `WebinarProvider` (╤Б╤В╤А╨░╤Е╨╛╨▓╨║╨░ ╨╛╤В ╤Г╤Е╨╛╨┤╨░ Zoom, ╤А╤Г╨╗╨╡╨╜╨╕╨╡ R1 тАФ BigBlueButton).** ╨Ш╨╜╤В╨╡╤А╤Д╨╡╨╣╤Б ╤Б ╤В╤А╨╡╨╝╤П ╨╝╨╡╤В╨╛╨┤╨░╨╝╨╕ (createMeeting / fetchParticipants / normalizeWebhook); `ZoomService` ╤А╨╡╨░╨╗╨╕╨╖╤Г╨╡╤В ╨╡╨│╨╛ ╨▒╨╡╨╖ ╨╕╨╖╨╝╨╡╨╜╨╡╨╜╨╕╤П ╨┐╨╛╨▓╨╡╨┤╨╡╨╜╨╕╤П (╨▓╨╡╨▒╤Е╤Г╨║-╨║╨╛╨╜╤В╤А╨╛╨╗╨╗╨╡╤А ╨┐╨╛╤В╤А╨╡╨▒╨╗╤П╨╡╤В `normalizeWebhook` тАФ ╤А╨░╨╖╨▒╨╛╤А ╨▒╨░╨╣╤В-╨▓-╨▒╨░╨╣╤В ╨┐╤А╨╡╨╢╨╜╨╕╨╣); ╤Б╨║╨╡╨╗╨╡╤В `BigBlueButtonService` ╤Б ╤Д╨╛╤А╨╝╨╛╨╣ BBB API (╨▒╤А╨╛╤Б╨░╨╡╤В ╨┤╨╛ ╤А╨░╨╖╨▓╨╡╤А╤В╤Л╨▓╨░╨╜╨╕╤П Q4); ╨┐╤А╨╛╨▓╨░╨╣╨┤╨╡╤А-╨╜╨╡╨╣╤В╤А╨░╨╗╤М╨╜╤Л╨╡ ╨░╨╗╨╕╨░╤Б╤Л `meeting_*` ╨┐╨╛╨▓╨╡╤А╤Е `zoom_*` (╤А╨╡╨▓╨╡╤А╤Б╨╕╨▓╨╜╨░╤П ╨╝╨╕╨│╤А╨░╤Ж╨╕╤П, ╨▒╤Н╨║╤Д╨╕╨╗╨╗ ╨║╨╛╨┐╨╕╨╡╨╣); ╨▒╨╕╨╜╨┤╨╕╨╜╨│ ╤И╨▓╨░ ╨╜╨░ Zoom-╨┤╤А╨░╨╣╨▓╨╡╤А. ╨Р╨▓╤В╨╛-╤Б╨╛╨╖╨┤╨░╨╜╨╕╨╡ Zoom-╨▓╤Б╤В╤А╨╡╤З ╨Э╨Х ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╛ тАФ ╨╛╤Б╤В╨░╨╡╤В╤Б╤П @DECIDE GC-B1. 7 unit-╤В╨╡╤Б╤В╨╛╨▓ ╤И╨▓╨░; CI ╨╖╨╡╨╗╨╡╨╜╤Л╨╣. [PR #549](https://github.com/gasyoun/Systema-Sanscriticum/pull/549), H601, Fable 5 (`claude-fable-5`). ╨Ф╨╡╨┐╨╗╨╛╨╣: [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) тАФ ╨╛╨▒╤Й╨╕╨╣ `php artisan migrate`.

## [1.17.1] - 2026-07-17

### Added
- **H1067: marathon 28-08 cohort RU comms pack.** New [marketing/marathon-2026-08/](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08) тАФ two landing-copy variants (beginner-fear-focused / outcome-focused) + shared FAQ, a 5-email sequence (drafts only: prod SMTP broken, [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504)), and @samskrte channel posts with a publication-order table. Authoring-only: publish steps are queued as DEPLOY_QUEUE тДЦ25 (human-gated); the day 1тАУ3 bot drip in `config/marathon.php` stays canonical and is not duplicated. Testimonial slots publish only with a real quote (`MARATHON_TESTIMONIAL`). Authored by Fable 5 (`claude-fable-5`), [PR #544](https://github.com/gasyoun/Systema-Sanscriticum/pull/544).

## [1.17.0] - 2026-07-16

### Added
- **H1046: CI/CD deploy pipeline (GitHub Actions тЖТ SSH тЖТ `deploy.sh`), MG-confirm gate.** New `.github/workflows/deploy.yml` тАФ Option A of the [H478 deploy-gate decision](https://github.com/gasyoun/Uprava/blob/main/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md): every push to `main` (or manual `workflow_dispatch`) queues a run gated by a GitHub Environment (`production`) approval тАФ MG must click Approve before the runner SSHes to prod and runs the existing `sudo bash deploy.sh` (unchanged). No agent holds prod credentials; the SSH key lives only in the Environment's secrets. **Server-side setup (deploy user, narrow `sudoers`, GitHub Environment + secrets) is a separate one-time human step** тАФ see `docs/deploy.md` ┬зCI/CD and `DEPLOY_QUEUE.md` ┬зD1 тАФ until done, the workflow only accumulates harmless "Waiting" runs.

## [1.16.0] - 2026-07-16

### Added
- **H1005: RQ4 admin stats page.** New `/admin/rq4-study-dashboard` (admin/super_admin only): enrollment count + arm split, pre/post/retention-test completion counts and percentages, and how many participants are currently due a retention reminder. Built so MG can check enrollment numbers himself тАФ **he doesn't hold SSH credentials to the production server** (only the deploy contractor does, per `docs/deploy.md`), so an artisan-command-only report would still require going through the contractor every time. 3 tests in `tests/Feature/Rq4StudyDashboardTest.php`.

### Changed
- **H987 follow-up: RQ4 consent text approved by MG 15-07-2026.** No wording change тАФ `Rq4StudyController::CONSENT_TEXT` is exactly the draft reviewed in chat, only the "not finalised" doc-comment is removed. Protocol ┬з6.4 is now the last of the 4 `@DECIDE` items ruled тАФ the RQ4 study spec is fully decided; `features.rq4_study` still ships OFF by default (flipping it live is a separate, later call).

## [1.15.0] - 2026-07-15

### Added
- **H987: RQ4 study harness (on-ramp-first vs ╨в╨░╨╗╨╝╤Г╨┤-first learning-gain study).** New `/rq4-study` flow behind `features.rq4_study` (OFF by default): consent + intake (self-reported prior exposure), stratified 1:1 arm assignment via a minimisation rule (`Rq4Participant::assignArm`), a 3-phase diagnostic (pre_test/post_test/retention_test) reading the vendored `resources/data/rq4_item_bank.json` (SanskritGrammar's H984 item bank), and a `rq4:send-retention-reminders` command (scheduled daily) that queues one `ScheduledReminder` per participant whose 4-week retention window has arrived тАФ reuses the existing reminder infrastructure (H187) rather than building a new notification channel. New `rq4_participants`/`rq4_responses` tables. Draft consent text included, marked not-finalised pending MG's review (protocol ┬з6.4). 9 tests in `tests/Feature/Rq4StudyTest.php`.

## [1.14.0] - 2026-07-15

### Added
- **H962: cabinet remake Phase 0 тАФ instrumentation-first baseline (R20 gate).**
  The current (pre-hybrid) student cabinet now emits the event vocabulary of
  [`docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md)
  ┬з4 through the EXISTING `activity_events`/`ActivityTracker` pipeline (no new
  storage): server-side `cabinet.home.view`, `lesson.mark.mastered`,
  `access.renewal.complete` (new `PaymentTelemetryObserver`, self-service paid
  transition only тАФ money code untouched); client-side via first-party
  `POST /dvaram/telemetry` (whitelist `ActivityEvent::CLIENT_CABINET_EVENTS`,
  inline JS partial, declarative `data-track-*` blade attributes) for
  `cabinet.continue.click`, `course.tab.view` (surface=dashboard),
  `cabinet.homework.rework.click`, `offer.impression`/`offer.click`
  (kind=next-block, locked lessons on the course page) and
  `access.renewal.start` (debt CTAs). `lesson.view.heartbeat` and
  `cabinet.live.zoom.click` are NOT double-written тАФ the readout command
  **`php artisan cabinet:baseline`** aggregates them from their existing tables
  (`lesson_views`, `schedule_join_clicks`) under the ┬з4 names and honestly
  lists the ┬з4 events that have no current surface. No third-party trackers;
  no UX change. Baseline must run тЙе2 weeks before the hybrid ships (R20).
  (Fable 5 `claude-fable-5`, [H962](https://github.com/gasyoun/Uprava/blob/main/handoffs/H962-Sonnet_Systema-Sanscriticum_student-cabinet-remake-instrumentation-phase_15.07.26.md))

## [1.13.0] - 2026-07-15

### Added
- **H965: kosha last-mile pipeline, Hop C difficulty-score advisory consumption.**
  `/reading/kosha-demo` (same route/flag as H959's Hop A) now also reads the
  vendored `resources/data/kosha_reading_pack_difficulty.json` тАФ kosha's real
  `reading-pack-difficulty` dataset from its H949 scorer, not re-derived here тАФ
  and shows the pack's composite difficulty + four axis scores (vocab, sandhi,
  morphology, compound), plus a ranked list of all 5 scored packs
  (easiestтЖТhardest) with the current page highlighted. Purely advisory per the
  spec's Hop C ruling тАФ nothing here reorders the reader or any course. 2 new
  tests in `tests/Feature/ReadingPackTest.php` (6 total). Closes the last open
  piece of [`docs/LAST_MILE_PIPELINE_SPEC.md`](https://github.com/gasyoun/SanskritGrammar/blob/main/docs/LAST_MILE_PIPELINE_SPEC.md)
  on the Systema side тАФ Hops A, B, and C now all consumed.

## [1.12.0] - 2026-07-15

### Added
- **Student-cabinet mockup #5 тАФ direction D ┬л╨Я╤Г╤В╤М┬╗ / Journey & membership hub (H958).** Per
  M.G. ruling R28 (15-07-2026), completing the four-direction set: the cabinet renders the
  school's ladder (╨┐╨╕╤Б╤М╨╝╨╛ тЖТ ╨│╤А╨░╨╝╨╝╨░╤В╨╕╨║╨░ тЖТ ╤В╨╡╨║╤Б╤В╤Л) as a station map тАФ done/current/next/horizon
  nodes, milestones as learning-contour landmarks (never payment deadlines), the next station
  ┬л╨╖╨░╨│╨╛╤А╨░╨╡╤В╤Б╤П┬╗ ONLY after full completion of the current one (no timers, ┬л╤Б╤В╨░╨╜╤Ж╨╕╤П ╨┐╨╛╨┤╨╛╨╢╨┤╤С╤В┬╗),
  membership as path-continuity between paid stations, and an ┬л╨Т╨╜╨╡ ╨┐╤Г╤В╨╕ тАФ ╨╕ ╤Н╤В╨╛ ╨╜╨╛╤А╨╝╨░╨╗╤М╨╜╨╛┬╗
  shelf so the zig-zag student is never shamed. 3 pages (incl. the completion state with the
  lit ladder offer) on the shared design system; browser-verified, 6 screenshots
  ([docs/mockups/student-cabinet-remake/journey-membership-hub/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/journey-membership-hub)).
  Non-destructive; the production-direction pick is now the only remaining M.G. `@DECIDE`.

## [1.11.0] - 2026-07-15

### Added
- **Hybrid production spec for the cabinet remake (H961, ruling R29).** M.G. closed the
  four-direction exploration: production = hybrid тАФ B ┬л╨Ъ╤Г╤А╤Б ╨║╨░╨║ ╨┤╨╛╨╝┬╗ chassis + A's
  ┬л╨б╨╡╨│╨╛╨┤╨╜╤П┬╗-band-with-homework and recovery mode + C's ownership shelves, progress rail and
  ownership-expansion offer + D's path-in-┬л╨Я╤А╨╛╨│╤А╨╡╤Б╤Б┬╗, completion-lighting master offer rule
  and ╨▓╨╡╤Е╨╕. Binding spec with page deltas vs the B v2 reference, unified offer precedence,
  engineering bill, instrumentation-first event schema (R20 gate) and the phased sequence:
  [docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md)
  (+ sibling metadoc). Phase 0 (instrumentation baseline) queued as H962.

## [1.10.0] - 2026-07-15

### Added
- **H959: kosha last-mile pipeline, Hop A reader-as-a-service demo.** New
  `/reading/kosha-demo` route (`app/Http/Controllers/ReadingPackController.php`)
  renders the vendored feed `resources/data/kosha_reading_pack_nala_1.json`
  (kosha's `dcs-reading-pack-nala-1`) as a word-by-word reading page: each
  token is a native `<details>`/`<summary>` disclosure (no custom JS) showing
  lemma, morphology, and gloss on tap тАФ no external link or runtime lookup
  needed, every field already lives in the vendored feed. Gated by new
  `features.kosha_reader` flag (`KOSHA_READER` env, OFF by default, mirrors
  `slovar_enrichment`/`kosha_srs`) тАФ with the flag off the route 404s. 4 tests
  in `tests/Feature/ReadingPackTest.php`. Closes the reader half of
  [`docs/LAST_MILE_PIPELINE_SPEC.md`](https://github.com/gasyoun/SanskritGrammar/blob/main/docs/LAST_MILE_PIPELINE_SPEC.md)'s
  Hop A (Systema side); Hop B's SRS-deck import shipped separately (H955).

## [1.9.0] - 2026-07-15

### Added
- **Student-cabinet mockup #4 тАФ direction C ┬л╨С╨╕╨▒╨╗╨╕╨╛╤В╨╡╨║╨░┬╗ / Learning library (H957).** Per M.G.
  ruling R27 (15-07-2026): the cabinet as a personal library of ╨▓╨╗╨░╨┤╨╡╨╜╨╕╤П тАФ five shelves
  (╨Ш╨┤╤Г╤В ╤Б╨╡╨╣╤З╨░╤Б / ╨Ь╨╛╨╕ ╨╖╨░╨┐╨╕╤Б╨╕ / ╨Ш╤Б╤В╤С╨║╤И╨╕╨╡-╤Б-╨┐╤А╨╛╨┤╨╗╨╡╨╜╨╕╨╡╨╝ / ╨Ч╨░╨▓╨╡╤А╤И╤С╨╜╨╜╤Л╨╡ / ╨Ь╨░╤В╨╡╤А╨╕╨░╨╗╤Л), expiry
  ribbons, progress-as-navigation rail (Khan pattern) on the subject page, an
  ownership-expansion offer after progress, and the membership card as a native shelf-level
  slot. 3 pages on the shared design system; browser-verified (console clean, no 390px page
  overflow тАФ shelf scrollers are intentional), 6 screenshots
  ([docs/mockups/student-cabinet-remake/learning-library/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/learning-library)).
  Non-destructive; winner still an M.G. `@DECIDE`.

### Fixed
- **Mobile full-page screenshots of mockups #2/#3 regenerated:** the fixed bottom bar was
  stitched mid-page by the capture method; it now renders at the page end (screenshots only,
  no mockup-code change).

## [1.8.0] - 2026-07-15

### Added
- **Student-cabinet mockup #3 тАФ direction A ┬л╨б╨╡╨│╨╛╨┤╨╜╤П┬╗ / Today-first coach (H956).** Per M.G.
  ruling R26 (15-07-2026): the home is a numbered day plan with a fixed honest order
  (unfinished lesson тЖТ returned homework тЖТ today's live тЖТ first steps тЖТ ONE next step after a
  real progress event), ┬л╨Я╨╛╤З╨╡╨╝╤Г ╤В╨░╨║╨╛╨╣ ╨┐╨╗╨░╨╜?┬╗ transparency foldline answers the direction's
  opaque-authority risk, and a recovery state (declined payment) leads with the problem banner
  and suppresses all offers. 4 pages on the shared B-v2 design system so directions compare on
  architecture, not styling; browser-verified (console clean, no 390px overflow), 7 screenshots
  ([docs/mockups/student-cabinet-remake/today-first-coach/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/today-first-coach)).
  Non-destructive; winner still an M.G. `@DECIDE`.
- **H955: kosha last-mile pipeline, Rung B1 demo import.** New
  `php artisan srs:import-kosha-b1-demo` (`app/Console/Commands/ImportKoshaSrsDeckB1Demo.php`)
  imports the vendored feed `resources/data/kosha_srs_deck_b1_demo.json`
  (kosha manifest id `kosha-srs-deck-b1-demo` тАФ content vocabulary of the
  Nala-1 reading pack, `core_rank`-ordered, function words stripped) into one
  system Saraswati SRS deck (`kosha-b1-demo`), mirroring
  `SrsSanskritDeckSeeder`/`ImportMemriseSrsDeck`'s idempotent `firstOrCreate`
  pattern. Card insertion order == feed `rank` order (`srs_cards` has no
  `sort_rank` column yet тАФ a schema migration is deliberately deferred to a
  human-reviewed production follow-up, not built here). Gated by new
  `features.kosha_srs` flag (`KOSHA_SRS` env, OFF by default, mirrors
  `slovar_enrichment`) тАФ with the flag off the command writes nothing.
  5 tests in `tests/Feature/Srs/ImportKoshaSrsDeckB1DemoTest.php`.

## [1.7.0] - 2026-07-15

### Added
- **Student-cabinet mockup #2 тАФ ┬л╨Ъ╤Г╤А╤Б ╨║╨░╨║ ╨┤╨╛╨╝┬╗ v2 (H954, iterates H822 direction B).** Per
  M.G. rulings R21тАУR25 (14-07-2026, recorded in
  [docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md)):
  8 pages instead of 4 (+ ╨▒╨╕╨▒╨╗╨╕╨╛╤В╨╡╨║╨░ ╨╖╨░╨┐╨╕╤Б╨╡╨╣ ╤Б╨╛ ╤Б╨╗╨╛╤В╨╛╨╝ ╤З╨╗╨╡╨╜╤Б╤В╨▓╨░ ┬л╨б╨░╨╝╤Б╨║╤А╤В╨╡+┬╗ 2000 тВ╜/╨╝╨╡╤Б,
  ╨║╨░╨╗╨╡╨╜╨┤╨░╤А╤М, ╨┐╤А╨╛╨│╤А╨╡╤Б╤Б+╤Б╨╡╤А╤В╨╕╤Д╨╕╨║╨░╤В, ╨┐╨╛╨╝╨╛╤Й╤М/╤Б╨╛╨╛╨▒╤Й╨╡╨╜╨╕╤П), editorial-academism restyle, job-named
  navigation (┬л╨б╨╡╨│╨╛╨┤╨╜╤П / ╨Ъ╨░╨╗╨╡╨╜╨┤╨░╤А╤М / ╨Ч╨░╨┐╨╕╤Б╨╕ / ╨Я╤А╨╛╨│╤А╨╡╤Б╤Б / ╨Ю╨┐╨╗╨░╤В╨░ ╨╕ ╨┤╨╛╤Б╤В╤Г╨┐ / ╨Я╨╛╨╝╨╛╤Й╤М┬╗), light JS
  (hash-addressable course tabs, theme toggle, foldlines). Browser-verified: console clean on
  all 8 pages, no 390px overflow, 11 screenshots committed
  ([docs/mockups/student-cabinet-remake/course-workspace-v2/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace-v2)).
  Non-destructive; winner still an M.G. `@DECIDE`.

## [1.6.0] - 2026-07-14

### Added
- **Student-cabinet remake, first decision artifact (H822).** Evidence-led remake package:
  research ledger ([docs/STUDENT_CABINET_REMAKE_RESEARCH_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_RESEARCH_2026.md)),
  6-platform EdTech comparison ([docs/STUDENT_CABINET_EDTECH_COMPARISON_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_EDTECH_COMPARISON_2026.md)),
  20 M.G. rulings + 4 whole-cabinet architecture directions
  ([docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md)),
  and the first browser-verified static mockup тАФ direction B ┬л╨Ъ╤Г╤А╤Б ╨║╨░╨║ ╨┤╨╛╨╝┬╗ (course
  workspace), 4 linked pages, light/dark, mobile bottom-nav, console-clean, screenshots
  committed ([docs/mockups/student-cabinet-remake/course-workspace/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace)).
  Non-destructive: no production Blade/route/controller changes. Winner is an explicit
  M.G. `@DECIDE`; remaining three mockups are decision-gated.

## [1.5.0] - 2026-07-14

### Added
- **Companion metadocs for the last 16 docs тАФ UX-audits, strategy & one-offs (H891).** Third
  and final metadoc sweep (after H887's 13 roadmaps and H890's 31 manuals/specs): the 8
  `*_UX_AUDIT_2026` audits, 5 strategy/marketing docs (BUSINESS_MODEL_CANVAS,
  GROWTH_STRATEGY_2026_2027, jivo, growth-ideas-2026, zapisi-katalog-strategiya), and 3
  one-offs (deploy-checklist-audit-fixes, lead-magnet-article-first-sentence-ru,
  WIKIDATA_SAMEAS_SPOTCHECK) now each have a sibling `.meta.md`. Point-in-time reports and
  completed checklists carry a `retired`/`superseded` deprecation status; `jivo.meta.md`
  records that the doc's "current state" claims are unreliable (support-subsystem-map.md is
  ground truth). **Every `docs/*.md` in the repo now has a metadoc** (60 across H887/H890/H891).
  Docs only.
- **Companion metadocs for 31 manuals, specs, and reference docs (H890).** Second metadoc
  sweep after the 13 roadmaps (H887): every `docs/` manual (admin/student/debtors/finance/
  accountant, onboarding, cabinet-bot), spec (`*-spec`, ATTRIBUTION_FIELDS_SPEC, TZ_arzamas,
  direct-teacher-receipts, newsletter-subscribe, partner-program, revenue-recognition,
  student-unblock-access-feed), and operational/reference/security doc (deploy,
  php-8.3-upgrade, webhook-security, money-core-adversarial-review, support-subsystem-map,
  support-identity, telegram-userbot-inventory, vitrina, the two SANSKRIT_HUB indices,
  FINANCE_REVIEW_RHYTHM) now has a sibling `.meta.md` per the `/metadoc` contract. Each in its
  subject's language (ru/en). The 8 UX-audits and 5 strategy docs are a separate genre, left
  for a future sweep. Docs only.
- **Companion metadocs for all 13 roadmap docs (H887).** Every `docs/*ROADMAP*.md` /
  `docs/*_ROADMAP.md` / `docs/IMPLEMENTATION_MAP_*.md` now has a sibling `.meta.md` holding
  its purpose, audience, provenance (real git creation date + model), a ranked improvement
  backlog (each row owned by an `H###` or `parked`), limitations, intended-use/misuse,
  maintenance/sunset, deprecation status, and revision history тАФ closing the "13 roadmap docs
  carry zero metadoc coverage" gap flagged in the 13-07-2026 weekly review. Each metadoc is in
  its subject's language (ru/en). Docs only.
- **Optimisation & bottleneck backlog (H881), `docs/OPTIMISATION_BACKLOG_2026H2.md`
  (+ metadoc).** The single leverage-ranked index of what needs unblocking / speeding up /
  paying down, replacing the prior scatter across `.ai_state.md` Dev Notes and ~15 topic
  roadmaps. Every row fact-checked against `origin/main` on 13-07-2026 тАФ which surfaced that
  the Laravel-EOL row and the message-store-unification row were both already resolved (H862
  10тЖТ12; the `UnifiedMessage`/`UnifiedInboxReader` read layer from 01-07-2026), and that
  `vendor/` bloat is a non-issue. Documentation only тАФ no product change, so intentionally
  not release-cut.

### Fixed
- **Test suite no longer depends on a built frontend (H884).** `@vite` throws
  `ManifestNotFoundException` (тЖТ 500) when `public/build/manifest.json` is absent,
  which locally turned every view-rendering feature test into a false failure until
  `npm run build` was run. Hoisted `withoutVite()` from two ad-hoc per-test `setUp()`
  overrides into the base `Tests\TestCase::setUp()`, so all 235 feature tests are
  immune to a missing manifest with no build step. CI's manifest-stub is now
  belt-and-suspenders. (Fixes a ┬з2 dev-loop item from `docs/OPTIMISATION_BACKLOG_2026H2.md`.)

### Security
- **Semgrep PHP SAST promoted from advisory to a required/blocking gate (H885).**
  Cleared the 18 advisory findings that were keeping it non-blocking (H081 Part A,
  `docs/SECURITY_ROADMAP.md` Wave 3): pinned all 13 GitHub Actions `uses:` to full
  commit SHAs (supply-chain hardening, Dependabot-maintained), added a 7-day
  Dependabot `cooldown` to all three ecosystems, and removed a stray
  `index.nginx-debian.html` (nginx default page) from the repo root. `semgrep.yml`
  now runs with `--error` and no `continue-on-error`, so a new SAST finding fails
  the PR. Executes a ┬з3 tech-debt item from `docs/OPTIMISATION_BACKLOG_2026H2.md` (H881).

## [1.4.0] - 2026-07-13

### Added
- **FAQ-╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А v2 тАФ LLM-╤З╨╡╤А╨╜╨╛╨▓╨╕╨║╨╕ ╨┤╨╗╤П ╨║╨░╤В╨╡╨│╨╛╤А╨╕╨╣ D/E/F (H816 PR 1, ╤В╨╕╨║╨╡╤В S5).**
  ╨а╨░╤Б╤И╨╕╤А╤П╨╡╤В ╤Д╨░╨║╤В╨╛╨╗╨╛╨│╨╕╤З╨╡╤Б╨║╨╕╨╣ ╤Б╤Г╨│╨│╨╡╤Б╤В╨╡╤А v1 (A/B/C, ╨▒╨╡╨╖ LLM) ╨╜╨░ ╤Б╨░╨╝╤Л╨╡ ╤З╨░╤Б╤В╨╛╤В╨╜╤Л╨╡
  ┬л╤З╨╡╨╗╨╛╨▓╨╡╤З╨╡╤Б╨║╨╕╨╡┬╗ ╨║╨░╤В╨╡╨│╨╛╤А╨╕╨╕: D ┬л╨╛╨┐╨╗╨░╤В╨░/╤Ж╨╡╨╜╨░/╤В╨░╤А╨╕╤Д╤Л┬╗ (7.4% FAQ), E ┬л╨┤╨╛╤Б╤В╤Г╨┐/╨│╤А╤Г╨┐╨┐╨░/
  ╨║╨░╨▒╨╕╨╜╨╡╤В┬╗, F ┬л╨╝╨░╤В╨╡╤А╨╕╨░╨╗╤Л/╨Ф╨Ч/╤Б╨╡╤А╤В╨╕╤Д╨╕╨║╨░╤В╤Л┬╗. ╨Ф╨╡╤В╨╡╨║╤В тАФ ╨┤╨╡╤И╤С╨▓╤Л╨╝ regex-╨┐╤А╨╡╤Д╨╕╨╗╤М╤В╤А╨╛╨╝;
  ╨ж╨Ш╨д╨а╨л ╨▒╨╡╤А╤Г╤В╤Б╤П ╨╕╨╖ ╨║╨╛╨┤╨░ LMS (╤В╨░╤А╨╕╤Д ╤З╨╡╤А╨╡╨╖ `Tariff::calculateFinalPriceForUser()` тАФ
  ╨╡╨┤╨╕╨╜╤Б╤В╨▓╨╡╨╜╨╜╤Л╨╣ ╨╕╤Б╤В╨╛╤З╨╜╨╕╨║ ╨╕╤Б╤В╨╕╨╜╤Л ╨┐╨╛ ╤Ж╨╡╨╜╨╡, ╨░╨║╤В╨╕╨▓╨╜╤Л╨╡ ╨│╤А╤Г╨┐╨┐╤Л, ╤З╨╕╤Б╨╗╨╛ ╨╛╨┐╤Г╨▒╨╗╨╕╨║╨╛╨▓╨░╨╜╨╜╤Л╤Е
  ╤Г╤А╨╛╨║╨╛╨▓), ╨░ ╨▓╨╜╨╡╤И╨╜╨╕╨╣ LLM (`CuratorAi`/OpenRouter) ╨╗╨╕╤И╤М ╨д╨Ю╨а╨Ь╨г╨Ы╨Ш╨а╨г╨Х╨в ╨╕╨╖ ╨╜╨╕╤Е ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║.
  ╨Ъ╨░╨║ ╨╕ v1, ╨▒╨╛╤В ╨╜╨╕╤З╨╡╨│╨╛ ╨╜╨╡ ╨╛╤В╨┐╤А╨░╨▓╨╗╤П╨╡╤В тАФ ╤В╨╛╨╗╤М╨║╨╛ ╨╖╨░╨▓╨╛╨┤╨╕╤В pending
  `SupportAnswerSuggestion` ╨║╤Г╤А╨░╤В╨╛╤А╤Г. ╨в╤А╨╕ ╤Б╤В╤А╨░╤Е╨╛╨▓╨║╨╕: ╤Д╨╗╨░╨│ `support_ai_assist`
  (╨╕╨╜╨░╤З╨╡ ╨║╨░╤В╨╡╨│╨╛╤А╨╕╤П ╨╛╨┐╨╛╨╖╨╜╨░╨╜╨░, ╨╜╨╛ ╤З╨╡╤А╨╜╨╛╨▓╨╕╨║ ╨╜╨╡ ╤Б╤В╤А╨╛╨╕╤В╤Б╤П); ╨┤╨╜╨╡╨▓╨╜╨╛╨╣ cap LLM-╨▓╤Л╨╖╨╛╨▓╨╛╨▓
  (`MarketingSetting.support_ai_daily_cap` тЖТ ╨┤╨╡╤Д╨╛╨╗╤В `config('features.support_ai_daily_cap')`,
  ╤Б╤З╨╕╤В╨░╨╡╤В╤Б╤П ╨┐╨╛ ╤Б╨╛╨▒╤Л╤В╨╕╤П╨╝ `answer_llm_drafted`); ╨┐╤А╨╕╨▓╨░╤В╨╜╨╛╤Б╤В╤М тАФ ╤Б╤Л╤А╨╛╨╣ ╤В╨╡╨║╤Б╤В
  ╨╕╨╝╨┐╨╛╤А╤В╨╕╤А╨╛╨▓╨░╨╜╨╜╨╛╨│╨╛ Telegram-╨Ы╨б ╤Г╤Е╨╛╨┤╨╕╤В ╨▓ LLM ╤В╨╛╨╗╤М╨║╨╛ ╨┐╤А╨╕ `support_ai_include_telegram`
  (╤Д╨░╨║╤В╤Л LMS тАФ ╨▓╤Б╨╡╨│╨┤╨░). ╨Э╨╛╨▓╤Л╨╣ `SupportLlmDraftComposer`; ╨╝╨╕╨│╤А╨░╤Ж╨╕╤П
  `marketing_settings.support_ai_daily_cap` (nullable, ╨░╨┤╨┤╨╕╤В╨╕╨▓╨╜╨░╤П). ╨Т╤Б╤С ╨╖╨░ ╤Д╨╗╨░╨│╨░╨╝╨╕,
  OFF ╨┐╨╛ ╤Г╨╝╨╛╨╗╤З╨░╨╜╨╕╤О тАФ ╨┐╤А╨╛╨┤ ╨╜╨╡ ╨╖╨░╤В╤А╨╛╨╜╤Г╤В. Feature-╤В╨╡╤Б╤В╤Л ╤Б ╤Д╨╡╨╣╨║╨╛╨▓╤Л╨╝ LLM (Http::fake),
  19/19 green; ╨┐╨╛╨╗╨╜╤Л╨╣ `tests/Feature/Support` тАФ 79/79.
- **╨а╨╛╤Б╤В╨╡╤А-╨▒╨╛╤В: ╨║╤Г╤А╨░╤В╨╛╤А-╨║╨╛╨╝╨░╨╜╨┤╤Л `/╨│╤А╤Г╨┐╨┐╨░` ╨╕ `/╨║╤В╨╛` (H816 PR 3, ╤В╨╕╨║╨╡╤В S6).**
  ╨Ф╨╛╤Б╤В╤А╨░╨╕╨▓╨░╤О╤В ╨╖╨░╨│╨╗╤Г╤И╨║╤Г `/╨│╤А╤Г╨┐╨┐╨░` ╨┤╨╛ ╨╜╨░╤Б╤В╨╛╤П╤Й╨╡╨│╨╛ ╤А╨╛╤Б╤В╨╡╤А╨░ ╨┐╨╛╨▓╨╡╤А╤Е `Group::activeUsers()`:
  `/╨│╤А╤Г╨┐╨┐╨░ <╨╜╨░╨╖╨▓╨░╨╜╨╕╨╡>` тАФ ╨░╨║╤В╨╕╨▓╨╜╤Л╨╣ ╤Б╨╛╤Б╤В╨░╨▓ ╨│╤А╤Г╨┐╨┐╤Л + ╨║╤Г╤А╤Б(╤Л) + ╨┤╨╛╨╗╨│╨╛╨▓╨╛╨╣ ╨╝╨░╤А╨║╨╡╤А тЪая╕П/тЬЕ
  (╨┐╤А╨╕╤Б╤Г╤В╤Б╤В╨▓╨╕╨╡ ╨▓ `DebtorsReport`, read-only, ╨С╨Х╨Ч ╨┐╨╛╨┤╤Б╤З╤С╤В╨░ ╤Б╤Г╨╝╨╝╤Л тАФ ╨┤╨╡╨╜╨╡╨╢╨╜╨░╤П ╨╗╨╛╨│╨╕╨║╨░
  ╨╜╨╡ ╨┤╤Г╨▒╨╗╨╕╤А╤Г╨╡╤В╤Б╤П); `/╨│╤А╤Г╨┐╨┐╨░` ╨▒╨╡╨╖ ╨░╤А╨│╤Г╨╝╨╡╨╜╤В╨░ тАФ ╤Б╨┐╨╕╤Б╨╛╨║ ╨│╤А╤Г╨┐╨┐; `/╨║╤В╨╛ <╨╕╨╝╤П|@username>` тАФ
  ╨┐╨╛╨╕╤Б╨║ ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░ ╨┐╨╛ ╨╕╨╝╨╡╨╜╨╕/username/email ╤Б ╨║╨░╤А╤В╨╛╤З╨║╨╛╨╣ (╨▓ ╨║╨░╨║╨╕╤Е ╨░╨║╤В╨╕╨▓╨╜╤Л╤Е ╨│╤А╤Г╨┐╨┐╨░╤Е,
  ╨║╨░╨║╨╕╨╡ ╨║╤Г╤А╤Б╤Л). ╨в╨░ ╨╢╨╡ ╤А╨╛╨╗╤М-╨░╨▓╤В╨╛╤А╨╕╨╖╨░╤Ж╨╕╤П, ╤З╤В╨╛ ╤Г `/╨┤╨╛╨╗╨│╨╕` (S4): admin/manager/super_admin,
  ╨┐╨╛╤Б╤В╨╛╤А╨╛╨╜╨╜╨╕╨╝/╤Б╤В╤Г╨┤╨╡╨╜╤В╨░╨╝ тАФ ╤В╨╕╤И╨╕╨╜╨░. ╨Э╨╛╨▓╤Л╨╣ `App\Services\Bot\RosterBotCommand` (╨┐╨╛ ╨╛╨▒╤А╨░╨╖╤Ж╤Г
  `DebtorsBotCommand`), ╨╖╨░╨│╨╗╤Г╤И╨║╨░ `/╨│╤А╤Г╨┐╨┐╨░` ╨╕╨╖ `DebtorsBotCommand` ╤Г╨▒╤А╨░╨╜╨░. ╨з╨╕╤Б╤В╤Л╨╣
  LMS-╨╖╨░╨┐╤А╨╛╤Б: ╨▒╨╡╨╖ LLM, ╨▒╨╡╨╖ ╨╜╨╛╨▓╤Л╤Е ╨║╤А╨╡╨┤, ╨▒╨╡╨╖ ╨╝╨╕╨│╤А╨░╤Ж╨╕╨╣ тАФ ╨╜╨░ ╨┐╤А╨╛╨┤╨╡ ╤А╨░╨▒╨╛╤В╨░╨╡╤В ╤Б╤А╨░╨╖╤Г ╨┐╨╛╤Б╨╗╨╡
  ╨▓╤Л╨║╨░╤В╨░. Feature-╤В╨╡╤Б╤В╤Л `RosterBotCommandTest` 9/9; ╨┐╨╛╨╗╨╜╤Л╨╣ Bot+Webhooks ╤Б╤М╤О╤В тАФ 95/95.
- **╨Я╨╗╨░╨╜╨╕╤А╨╛╨▓╤Й╨╕╨║ ╨░╨╜╨╛╨╜╤Б╨╛╨▓ тАФ `scheduled_at` (H816 PR 2).** ╨а╨░╨╜╤М╤И╨╡ ╨░╨╜╨╛╨╜╤Б
  ╤А╨░╤Б╤Б╤Л╨╗╨░╨╗╤Б╤П ╨б╨Ш╨Э╨е╨а╨Ю╨Э╨Э╨Ю ╨┐╤А╨╕ ╤Б╨╛╨╖╨┤╨░╨╜╨╕╨╕ (`CreateAnnouncement::afterCreate`) тАФ ╨╛╤В╤Б╤О╨┤╨░
  ╨░╨▓╤А╨░╨╗ ╨┐╨╡╤А╨╡╨┤ ╨╖╨░╨┐╤Г╤Б╨║╨╛╨╝. ╨в╨╡╨┐╨╡╤А╤М ╤Г ╨░╨╜╨╛╨╜╤Б╨░ ╨╡╤Б╤В╤М `scheduled_at` (╨┐╤Г╤Б╤В╨╛ = ┬л╨╛╤В╨┐╤А╨░╨▓╨╕╤В╤М
  ╤Б╤А╨░╨╖╤Г┬╗): ╤А╨░╤Б╤Б╤Л╨╗╨║╨░ ╨┐╨╛ ╨║╨░╨╜╨░╨╗╨░╨╝ email/Telegram/VK ╤Г╤Е╨╛╨┤╨╕╤В, ╨║╨╛╨│╨┤╨░ ╨╜╨░╤Б╤В╤Г╨┐╨╕╤В ╤Б╤А╨╛╨║,
  ╨║╨╛╨╝╨░╨╜╨┤╨╛╨╣ `announcements:dispatch-due` (╨▓ `Kernel::schedule()`, ╨║╨░╨╢╨┤╤Л╨╡ 5 ╨╝╨╕╨╜╤Г╤В).
  ╨Ы╨╛╨│╨╕╨║╨░ ╤А╨░╤Б╤Б╤Л╨╗╨║╨╕ ╨▓╤Л╨╜╨╡╤Б╨╡╨╜╨░ ╨╕╨╖ Filament-╤Б╤В╤А╨░╨╜╨╕╤Ж╤Л ╨▓ ╨┐╨╡╤А╨╡╨╕╤Б╨┐╨╛╨╗╤М╨╖╤Г╨╡╨╝╤Л╨╣
  `App\Services\AnnouncementDispatcher`; ╨╕╨┤╨╡╨╝╨┐╨╛╤В╨╡╨╜╤В╨╜╨╛╤Б╤В╤М тАФ ╨┐╨╛ `dispatched_at`
  (╨╛╨┤╨╕╨╜ ╨░╨╜╨╛╨╜╤Б ╨╜╨╡ ╤Г╤Е╨╛╨┤╨╕╤В ╨┤╨▓╨░╨╢╨┤╤Л). ╨Я╨╛╨╗╨╡ ┬л╨Ч╨░╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╨░╤В╤М ╤А╨░╤Б╤Б╤Л╨╗╨║╤Г ╨╜╨░┬╗ + ╨║╨╛╨╗╨╛╨╜╨║╨░
  ┬л╨Ч╨░╨┐╨╗╨░╨╜╨╕╤А╨╛╨▓╨░╨╜╨╛┬╗ ╨▓ ╨░╨┤╨╝╨╕╨╜╨║╨╡ (╨а╨░╤Б╤Б╤Л╨╗╨║╨╕). ╨Р╨┤╨┤╨╕╤В╨╕╨▓╨╜╨░╤П ╨╝╨╕╨│╤А╨░╤Ж╨╕╤П
  `announcements.scheduled_at`/`dispatched_at` (╨╛╨▒╨╡ nullable) тАФ ╤Б╤Г╤Й╨╡╤Б╤В╨▓╤Г╤О╤Й╨╕╨╡
  ╨╜╨╡╨╝╨╡╨┤╨╗╨╡╨╜╨╜╤Л╨╡ ╤А╨░╤Б╤Б╤Л╨╗╨║╨╕ ╨╕╨┤╤Г╤В ╤В╨╡╨╝ ╨╢╨╡ ╨┐╤Г╤В╤С╨╝, ╨╜╨╕╤З╨╡╨│╨╛ ╨╜╨╡ ╨╗╨╛╨╝╨░╨╡╤В╤Б╤П. Feature-╤В╨╡╤Б╤В╤Л
  `AnnouncementSchedulerTest` тАФ 6/6 (dueтЖТ╤А╨░╤Б╤Б╤Л╨╗╨║╨░+╨┤╨╡╨┤╤Г╨┐, futureтЖТ╤В╨╕╤И╨╕╨╜╨░,
  unpublished/╨▒╨╡╨╖-╨║╨░╨╜╨░╨╗╨░тЖТ╤В╨╕╤И╨╕╨╜╨░, ╨╜╨╡╨╝╨╡╨┤╨╗╨╡╨╜╨╜╨░╤П ╤З╨╡╤А╨╡╨╖ ╨┤╨╕╤Б╨┐╨╡╤В╤З╨╡╤А).

### Changed
- **╨в╨╡╤Б╤В╤Л ╨│╨╛╨╜╤П╤О╤В╤Б╤П ╨┐╨░╤А╨░╨╗╨╗╨╡╨╗╤М╨╜╨╛ тАФ `paratest` (H868).** `brianium/paratest ^7` ╨┤╨╛╨▒╨░╨▓╨╗╨╡╨╜ ╨▓ `require-dev`; CI-╤И╨░╨│ ╨╕ ╨╗╨╛╨║╨░╨╗╤М╨╜╤Л╨╣ ╨┐╤А╨╛╨│╨╛╨╜ ╨┐╨╡╤А╨╡╨▓╨╡╨┤╨╡╨╜╤Л ╨╜╨░ `php artisan test --parallel` (8 ╨┐╤А╨╛╤Ж╨╡╤Б╤Б╨╛╨▓ ╨╗╨╛╨║╨░╨╗╤М╨╜╨╛). ╨Т╨╡╤Б╤М ╨╜╨░╨▒╨╛╤А **1503 ╤В╨╡╤Б╤В╨░ / 4312 assertions ╨╖╨╡╨╗╤С╨╜╤Л╨╡** ╨┐╨░╤А╨░╨╗╨╗╨╡╨╗╤М╨╜╨╛ тАФ parallel-safe, ╨│╨╛╨╜╨╛╨║ ╨┐╨╛ ╨╛╨▒╤Й╨╕╨╝ ╤Д╨░╨╣╨╗╨╛╨▓╤Л╨╝ ╨┐╤Г╤В╤П╨╝ ╨╜╨╡╤В. ╨б╨╛╨║╤А╨░╤Й╨░╨╡╤В ╨▓╤А╨╡╨╝╤П ╨┐╤А╨╛╨│╨╛╨╜╨░ CI (╨▒╤Л╨╗ ~12.5 ╨╝╨╕╨╜ ╨┐╨╛╤Б╨╗╨╡╨┤╨╛╨▓╨░╤В╨╡╨╗╤М╨╜╨╛) ╨╕ ╨╗╨╛╨║╨░╨╗╨╕ ╨┐╤А╨╛╨┐╨╛╤А╤Ж╨╕╨╛╨╜╨░╨╗╤М╨╜╨╛ ╤З╨╕╤Б╨╗╤Г ╤П╨┤╨╡╤А.

### Security
- **Laravel 10 тЖТ 12: ╨╖╨░╨║╤А╤Л╤В╤Л HIGH+MODERATE Dependabot-╨░╨┤╨▓╨░╨╣╨╖╨╛╤А╨╕ (H862).**
  `laravel/framework` ╨┐╨╛╨┤╨╜╤П╤В `^10.10` тЖТ `^12.63` (╨┐╨╗╤О╤Б `laravel/sanctum` 3тЖТ4,
  `phpunit/phpunit` 10тЖТ11, `nunomaduro/collision` 7тЖТ8, `symfony/css-selector`+`dom-crawler` 6тЖТ7,
  `barryvdh/laravel-dompdf` 2тЖТ3, `spatie/laravel-backup` 8тЖТ9). ╨Ч╨░╨║╤А╤Л╨▓╨░╨╡╤В
  [Dependabot #14](https://github.com/gasyoun/Systema-Sanscriticum/security/dependabot/14)
  (HIGH, GHSA-5vg9-5847-vvmq тАФ CRLF-╨╕╨╜╤К╨╡╨║╤Ж╨╕╤П ╨▓ ╨┤╨╡╤Д╨╛╨╗╤В╨╜╨╛╨╝ ╨┐╤А╨░╨▓╨╕╨╗╨╡ ╨▓╨░╨╗╨╕╨┤╨░╤Ж╨╕╨╕ `email`) ╨╕
  [#15](https://github.com/gasyoun/Systema-Sanscriticum/security/dependabot/15)
  (MODERATE, GHSA-crmm-hgp2-wgrp тАФ path confusion ╨▓╨╛ ╨▓╤А╨╡╨╝╨╡╨╜╨╜╤Л╤Е ╨┐╨╛╨┤╨┐╨╕╤Б╨░╨╜╨╜╤Л╤Е URL):
  ╤Д╨╕╨║╤Б ╤В╨╛╨╗╤М╨║╨╛ ╨▓ Laravel 11+, ╨▒╤Н╨║╨┐╨╛╤А╤В╨░ ╨┐╨╛╨┤ EOL-╨╜╤Г╤В╤Г╤О 10.x ╨╜╨╡╤В, ╨┐╨╛╤Н╤В╨╛╨╝╤Г Dependabot ╨╜╨╡ ╨╝╨╛╨│
  ╨╛╤В╨║╤А╤Л╤В╤М PR. ╨Ъ╨╗╨░╤Б╤Б╨╕╤З╨╡╤Б╨║╨╕╨╣ ╤Б╨║╨╡╨╗╨╡╤В (`bootstrap/app.php` + `Http/Kernel`) ╤Б╨╛╤Е╤А╨░╨╜╤С╨╜ тАФ
  Filament v3.3.54 ╤Г╨╢╨╡ ╨┐╨╛╨┤╨┤╨╡╤А╨╢╨╕╨▓╨░╨╡╤В Laravel 12 (╨┐╤А╤Л╨╢╨╛╨║ Filament 3тЖТ4 ╨╜╨╡ ╨╜╤Г╨╢╨╡╨╜), ╨░
  `jenssegers/agent` ╨╜╨╡ ╨╕╨╝╨╡╨╡╤В Laravel-╨║╨╛╨╜╤Б╤В╤А╨╡╨╣╨╜╤В╨░ (╨╖╨░╨╝╨╡╨╜╨░ ╨╜╨╡ ╨┐╨╛╤В╤А╨╡╨▒╨╛╨▓╨░╨╗╨░╤Б╤М). ╨Я╤А╨░╨▓╨║╨╕ ╨┐╨╛╨┤
  ╨╜╨░╤В╨╕╨▓╨╜╤Л╨╣ SQLite-DDL Laravel 11 (Doctrine DBAL ╤Г╨▒╤А╨░╨╜): ╤Б╨╜╤П╤В╨╕╨╡ FK/╨╕╨╜╨┤╨╡╨║╤Б╨░ ╨┤╨╛ `DROP COLUMN`
  ╨▓ [`2026_03_09_..._payments`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_03_09_093322_replace_landing_page_id_with_course_id_in_payments_table.php)
  ╨╕ [`2026_06_02_..._direct_ad_spends`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_06_02_000001_direct_ad_spends_to_period.php);
  ╨║╨╛╨╜╤В╤А╨░╨║╤В `Authenticatable::getAuthPasswordName()` ╨▓
  [`GuestChatUser`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Auth/GuestChatUser.php);
  Carbon 3 `diffInDays()` ╤В╨╡╨┐╨╡╤А╤М ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В float тЖТ ╨║╨░╤Б╤В ╨▓
  [`DirectAdSpend::periodDays()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DirectAdSpend.php);
  ╤Н╨║╤А╨░╨╜╨╕╤А╨╛╨▓╨░╨╜╨╕╨╡ JSON-LD `@context` (╨▓ Laravel 11 `@context` ╤Б╤В╨░╨╗╨░ Blade-╨┤╨╕╤А╨╡╨║╤В╨╕╨▓╨╛╨╣) ╨▓
  [`articles/show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/articles/show.blade.php).
  ╨г╤Б╤В╨░╤А╨╡╨▓╤И╨╕╨╡ `audit.ignore` ╨┤╨╗╤П L10-╨░╨┤╨▓╨░╨╣╨╖╨╛╤А╨╕ ╤Г╨▒╤А╨░╨╜╤Л ╨╕╨╖ `composer.json`. ╨Т╨╡╤Б╤М ╨╜╨░╨▒╨╛╤А
  **1503/1503 ╨╖╨╡╨╗╤С╨╜╤Л╨╣**, `composer audit` тАФ ╤З╨╕╤Б╤В╨╛. ╨Я╤А╨╛╨│╨╛╨╜ ╨┐╨╛╨┤ Opus 4.8 (`claude-opus-4-8`).

## [1.3.0] - 2026-07-13

### Added
- **╨а╨░╨╖╨▒╨╗╨╛╨║╨╕╤А╨╛╨▓╨║╨░ ╨╖╨░╤Б╤В╤А╤П╨▓╤И╨╡╨│╨╛ ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░ ╨╛╨┤╨╜╨╕╨╝ ╨║╨╗╨╕╨║╨╛╨╝ + ╨╗╨╡╨╜╤В╨░ ┬л╨Я╤А╨╛╨▒╨╗╨╡╨╝╤Л ╤Б╨╛ ╨▓╤Е╨╛╨┤╨╛╨╝┬╗ (H849).**
  ╨Ф╨╛ ╤Б╨╕╤Е ╨┐╨╛╤А ╨╜╨╡╤Г╨┤╨░╤З╨╜╤Л╨╡ ╨┐╨╛╨┐╤Л╤В╨║╨╕ ╨▓╤Е╨╛╨┤╨░/╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╤П ╨Э╨Ш╨У╨Ф╨Х ╨╜╨╡ ╨╗╨╛╨│╨╕╤А╨╛╨▓╨░╨╗╨╕╤Б╤М.
  ╨в╨╡╨┐╨╡╤А╤М: (1) ╨╜╨╛╨▓╨░╤П ╤В╨░╨▒╨╗╨╕╤Ж╨░ `access_attempts` ╤Б╨╛╨▒╨╕╤А╨░╨╡╤В ╨╡╨┤╨╕╨╜╨╛╨╣ ╨╗╨╡╨╜╤В╨╛╨╣ ╨╜╨╡╤Г╨┤╨░╤З╨╜╤Л╨╡
  ╨╗╨╛╨│╨╕╨╜╤Л (╤Б╨╗╤Г╤И╨░╤В╨╡╨╗╤М `Auth\Events\Failed` ╨╜╨░ `/login` ╨╕ `/shop/login`) ╨╕ ╨╖╨░╨┐╤А╨╛╤Б╤Л
  ╤Б╤Б╤Л╨╗╨║╨╕ ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╤П (`reset_sent`/`reset_not_found`/`reset_throttled`,
  ╨╗╨╛╨│╨╕╤А╤Г╤О╤В╤Б╤П ╨▓ [`PasswordResetController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PasswordResetController.php));
  (2) Filament-╤А╨╡╤Б╤Г╤А╤Б ┬л╨Я╤А╨╛╨▒╨╗╨╡╨╝╤Л ╤Б╨╛ ╨▓╤Е╨╛╨┤╨╛╨╝┬╗ ([`AccessAttemptResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/AccessAttemptResource.php))
  ╤Б ╨▒╨╡╨╣╨┤╨╢╨╡╨╝ ┬л╨╖╨░╤Б╤В╤А╤П╨▓╤И╨╕╤Е┬╗ ╨╕ ╤А╨░╨╖╨▒╨╗╨╛╨║╨╕╤А╨╛╨▓╨║╨╛╨╣ ╨╕╨╖ ╤Б╤В╤А╨╛╨║╨╕, ╨┐╨╗╤О╤Б ╨║╨╜╨╛╨┐╨║╨░ ┬л╨а╨░╨╖╨▒╨╗╨╛╨║╨╕╤А╨╛╨▓╨░╤В╤М┬╗
  ╨╜╨░ ╨║╨░╤А╤В╨╛╤З╨║╨╡ ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░ ([`UserResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/UserResource.php));
  (3) ╤А╨░╨╖╨▒╨╗╨╛╨║╨╕╤А╨╛╨▓╨║╨░ = ╤Б╨╜╤П╤В╤М IP-╤В╤А╨╛╤В╤В╨╗ + ╨▓╤Л╨┤╨░╤В╤М **╨╛╨┤╨╜╨╛╤А╨░╨╖╨╛╨▓╤Г╤О magic-╤Б╤Б╤Л╨╗╨║╤Г ╨┤╨╗╤П ╨▓╤Е╨╛╨┤╨░**
  (24 ╤З, hashed-at-rest, ╨╜╨░╨╖╨╜╨░╤З╨╡╨╜╨╕╨╡ `admin_unblock`, ╨╝╨░╤А╤И╤А╤Г╤В `/login-link/{token}`),
  ╨║╨╛╤В╨╛╤А╤Г╤О ╨░╨┤╨╝╨╕╨╜ ╨┐╨╡╤А╨╡╨┤╨░╤С╤В ╤Б╤В╤Г╨┤╨╡╨╜╤В╤Г ╨╜╨░╨┐╤А╤П╨╝╤Г╤О, ╨╝╨╕╨╜╤Г╤П ╤Б╨╗╨╛╨╝╨░╨╜╨╜╤Г╤О ╨┐╨╛╤З╤В╤Г (+ ╨╛╨┐╤Ж. ╤Б╨▒╤А╨╛╤Б
  ╨┐╨░╤А╨╛╨╗╤П) тАФ [`StudentUnblockService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Access/StudentUnblockService.php);
  (4) Telegram: ╨┐╤А╨╛╨░╨║╤В╨╕╨▓╨╜╤Л╨╣ ╨░╨╗╨╡╤А╤В ╨░╨┤╨╝╨╕╨╜╨░╨╝ ╤Б inline-╨║╨╜╨╛╨┐╨║╨╛╨╣ ┬лЁЯФУ ╨Т╤Л╤Б╨╗╨░╤В╤М ╤Б╤Б╤Л╨╗╨║╤Г┬╗
  ╨┐╤А╨╕ ╤Б╨╕╨│╨╜╨░╨╗╨╡ ┬л╨╖╨░╤Б╤В╤А╤П╨╗┬╗ (╤В╤А╨╛╤В╤В╨╗ ╨▓╨╛╤Б╤Б╤В╨░╨╜╨╛╨▓╨╗╨╡╨╜╨╕╤П / ╤Б╨╡╤А╨╕╤П ╨╜╨╡╤Г╨┤╨░╤З╨╜╤Л╤Е ╨╗╨╛╨│╨╕╨╜╨╛╨▓) +
  ╤В╨╡╨║╤Б╤В╨╛╨▓╨░╤П ╨║╨╛╨╝╨░╨╜╨┤╨░ `/unblock <email>` тАФ ╨░╨▓╤В╨╛╤А╨╕╨╖╨░╤Ж╨╕╤П ╤Б╤В╤А╨╛╨│╨╛ `super_admin`/`admin`
  ([`UnblockBotCommand`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/UnblockBotCommand.php),
  [`TelegramWebhookController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TelegramWebhookController.php)).
  ╨Я╤А╨╛╨░╨║╤В╨╕╨▓╨╜╤Л╨╡ ╨░╨╗╨╡╤А╤В╤Л ╨╕╨┤╤Г╤В ╨╜╨░ `ADMIN_TELEGRAM_ID`. ╨Э╨╡ ╤Г╤Б╤В╤А╨░╨╜╤П╨╡╤В ╨║╨╛╤А╨╜╨╡╨▓╤Г╤О ╨┐╤А╨╕╤З╨╕╨╜╤Г
  ╨╜╨╡╨┤╨╛╤Б╤В╨░╨▓╨║╨╕ ╨┐╨╕╤Б╨╡╨╝ (╨▒╨╛╨╡╨▓╨╛╨╣ SMTP) тАФ ╨╜╨╛ ╨┤╨░╤С╤В ╨░╨┤╨╝╨╕╨╜╤Г ╨╛╨▒╨╛╨╣╤В╨╕ ╨╡╤С ╨▓╤А╤Г╤З╨╜╤Г╤О. ╨Ф╨╛╨║╤Г╨╝╨╡╨╜╤В╨░╤Ж╨╕╤П:
  [`docs/student-unblock-access-feed.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-unblock-access-feed.md).

## [1.2.1] - 2026-07-13

### Fixed
- **Password-reset ┬л╨б╨╗╨╕╤И╨║╨╛╨╝ ╨╝╨╜╨╛╨│╨╛ ╨┐╨╛╨┐╤Л╤В╨╛╨║┬╗ ╨╜╨░ ╨┐╨╡╤А╨▓╨╛╨╣ ╨┐╨╛╨┐╤Л╤В╨║╨╡ (H840).** ╨С╤А╨╛╨║╨╡╤А
  Laravel ╨▓╨╛╨╖╨▓╤А╨░╤Й╨░╨╡╤В `RESET_THROTTLED`, ╨║╨╛╨│╨┤╨░ ╤Б╤Б╤Л╨╗╨║╤Г ╨┤╨╗╤П ╨▓╤Е╨╛╨┤╨░ ╤Г╨╢╨╡ ╨╛╤В╨┐╤А╨░╨▓╨╕╨╗╨╕
  ╨╝╨╡╨╜╤М╤И╨╡ ╨╝╨╕╨╜╤Г╤В╤Л ╨╜╨░╨╖╨░╨┤ (per-email ╤В╤А╨╛╤В╤В╨╗, `config/auth.php`), тАФ ╤Н╤В╨╛ ╨Э╨Х ╨┐╨╡╤А╨╡╨▒╨╛╤А.
  ╨Я╤А╨╡╨╢╨╜╤П╤П ╨║╤А╨░╤Б╨╜╨░╤П ╨╛╤И╨╕╨▒╨║╨░ ┬л╨б╨╗╨╕╤И╨║╨╛╨╝ ╨╝╨╜╨╛╨│╨╛ ╨┐╨╛╨┐╤Л╤В╨╛╨║. ╨Я╨╛╨┤╨╛╨╢╨┤╨╕╤В╨╡ ╨╝╨╕╨╜╤Г╤В╤Г┬╗ ╨┐╤Г╨│╨░╨╗╨░
  ╤Б╤В╤Г╨┤╨╡╨╜╤В╨░ ╨╜╨░ ╤Д╨░╨║╤В╨╕╤З╨╡╤Б╨║╨╕ ╨┐╨╡╤А╨▓╨╛╨╣ ╨┐╨╛╨┐╤Л╤В╨║╨╡ (╨┐╨╕╤Б╤М╨╝╨╛ ╤З╨░╤Б╤В╨╛ ╨┐╤А╨╛╤Б╤В╨╛ ╨▓ ┬л╨б╨┐╨░╨╝╨╡┬╗ ╨╕╨╗╨╕
  ╨╖╨░╨┤╨╡╤А╨╢╨░╨╗╨╛╤Б╤М). ╨в╨╡╨┐╨╡╤А╤М ╤Н╤В╨╛╤В ╤Б╨╗╤Г╤З╨░╨╣ ╨┐╨╛╨║╨░╨╖╤Л╨▓╨░╨╡╤В ╤В╨╛╤В ╨╢╨╡ ╨╖╨╡╨╗╤С╨╜╤Л╨╣ ╨▒╨╗╨╛╨║ ┬л╨╝╤Л ╤Г╨╢╨╡
  ╨╛╤В╨┐╤А╨░╨▓╨╕╨╗╨╕ ╤Б╤Б╤Л╨╗╨║╤Г тАФ ╨┐╤А╨╛╨▓╨╡╤А╤М╤В╨╡ ╨┐╨╛╤З╤В╤Г ╨╕ тАЮ╨б╨┐╨░╨╝тАЬ, ╨╜╨╡ ╨┐╤А╨╕╤И╨╗╨╛ ╨╖╨░ 5 ╨╝╨╕╨╜╤Г╤В тАФ ╨╖╨░╨┐╤А╨╛╤Б╨╕╤В╨╡
  ╤Б╨╜╨╛╨▓╨░┬╗, ╤З╤В╨╛ ╨╕ ╤Г╤Б╨┐╨╡╤И╨╜╨░╤П ╨╛╤В╨┐╤А╨░╨▓╨║╨░ ([`PasswordResetController::sendResetLink`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PasswordResetController.php)).
  UX-╨┐╤А╨░╨▓╨║╨░ ╤Д╨╛╤А╨╝╤Г╨╗╨╕╤А╨╛╨▓╨║╨╕; ╨║╨╛╤А╨╜╨╡╨▓╨░╤П ╨┐╤А╨╕╤З╨╕╨╜╨░ ╨╜╨╡╨┤╨╛╤Б╤В╨░╨▓╨║╨╕ ╨┐╨╕╤Б╨╡╨╝ (╨▒╨╛╨╡╨▓╨╛╨╣ SMTP/╨┤╨╛╨╝╨╡╨╜
  ╨╛╤В╨┐╤А╨░╨▓╨╕╤В╨╡╨╗╤П) ╨╛╤Б╤В╨░╤С╤В╤Б╤П ╨╛╤В╨┤╨╡╨╗╤М╨╜╤Л╨╝ ╤Б╨╡╤А╨▓╨╡╤А╨╜╤Л╨╝ ╨▓╨╛╨┐╤А╨╛╤Б╨╛╨╝.

## [1.2.0] - 2026-07-12

### Added
- **Native live-chat support widget (H536), Phases 1тАУ5 complete + observability.**
  Laravel Reverb WebSocket transport (`ChatMessageSent` on the private
  `support.conversation.{id}` channel, [PR #432](https://github.com/gasyoun/Systema-Sanscriticum/pull/432));
  guest identity тАФ an anonymous samskrte.ru visitor owns a thread via a session
  `guest_token` (ephemeral ownership marker, **not** a 4th external-identity
  mapping; `chat_messages.user_id` now nullable; `chat_guest` broadcasting guard),
  [PR #461](https://github.com/gasyoun/Systema-Sanscriticum/pull/461); rate-limited
  public post endpoint (`POST /chat/message`, `GET /chat/history` via
  `PublicChatController`), [PR #463](https://github.com/gasyoun/Systema-Sanscriticum/pull/463);
  storefront visitor bubble, [PR #468](https://github.com/gasyoun/Systema-Sanscriticum/pull/468);
  guest web-chat in the operator inbox with live reply,
  [PR #470](https://github.com/gasyoun/Systema-Sanscriticum/pull/470); and a
  support observability dashboard тАФ session health, sync lag, delivery rate, LLM
  volume (H597), [PR #469](https://github.com/gasyoun/Systema-Sanscriticum/pull/469).
  A guest never resolves to a `users` row (no account-takeover); output stays
  escaped via `ChatMessage::htmlForWeb()`. Live once Reverb is deployed on the host.
- **3-day diagnostic marathon ┬л╨Ъ╨╛╨╜╤Б╤Г╨╗╤М╤В╨░╤Ж╨╕╤П ╨┐╨╛ ╨╛╨╜╨╗╨░╨╣╨╜-╨║╤Г╤А╤Б╨░╨╝ ╨Ю╨а╨б┬╗ (H440), all 6 phases.**
  Landing + capture with a personal `day0_started_at` clock (anti-urgency),
  [PR #407](https://github.com/gasyoun/Systema-Sanscriticum/pull/407); drip engine
  with Day 1/2 Telegram content, [PR #410](https://github.com/gasyoun/Systema-Sanscriticum/pull/410);
  genuine tap-choice UI for Days 1/2, [PR #421](https://github.com/gasyoun/Systema-Sanscriticum/pull/421);
  paid track (тВ╜500) checkout via Tochka, [PR #415](https://github.com/gasyoun/Systema-Sanscriticum/pull/415);
  live Day-3 consultation + recording delivery, [PR #423](https://github.com/gasyoun/Systema-Sanscriticum/pull/423);
  and a 13-day evergreen warm-tail (Days 4тАУ16) that auto-stops once `paid_at` is
  set, [PR #424](https://github.com/gasyoun/Systema-Sanscriticum/pull/424).
- **Cohort-aware marathon engine (H445):** cohort core
  ([PR #436](https://github.com/gasyoun/Systema-Sanscriticum/pull/436)), a
  level-quiz for the Devanagari cohort ([PR #438](https://github.com/gasyoun/Systema-Sanscriticum/pull/438)),
  and Day-1 name-in-Devanagari for that cohort ([PR #446](https://github.com/gasyoun/Systema-Sanscriticum/pull/446)).
- Selling-layout journey layer on the homepage (H431 Phase 1): hero rebuilt around
  a three-path learning trajectory (╨Я╨╕╤Б╤М╨╝╨╛/╤З╤В╨╡╨╜╨╕╨╡ тЖТ ╨У╤А╨░╨╝╨╝╨░╤В╨╕╨║╨░ тЖТ ╨в╨╡╨║╤Б╤В╤Л/╤З╨░╨╜╤В╤Л)
  resolved to real courses, a ┬л╨Я╨╛╤З╨╡╨╝╤Г ╨╝╤Л┬╗ credentials block, and a proof block
  (years/books/crowdfunding from `config/trust.php` + real testimonial slots).
  [PR #427](https://github.com/gasyoun/Systema-Sanscriticum/pull/427)
- Configurable CRM lead stages (GC-C1): a `lead_stages` table replaces the
  hardcoded `Lead::STATUSES`/`FINAL_STATUSES`, plus a Filament drag-drop kanban
  board (`/admin/leads-board`). [PR #408](https://github.com/gasyoun/Systema-Sanscriticum/pull/408)
- SRS ┬лSaraswati┬╗ trainer suite, Phase 1 enable-and-connect (H447).
  [PR #442](https://github.com/gasyoun/Systema-Sanscriticum/pull/442)
- Sanskrit interactive exercises: a sort-into-groups engine + genders drill and
  generator (H551, [PR #441](https://github.com/gasyoun/Systema-Sanscriticum/pull/441))
  and a nounтЖФpronoun gender-agreement sort drill (H561,
  [PR #449](https://github.com/gasyoun/Systema-Sanscriticum/pull/449)).
- Consolidated attendance dashboard (GC-B2, H553).
  [PR #444](https://github.com/gasyoun/Systema-Sanscriticum/pull/444)
- Self-reported signup-source capture at registration (H476).
- Telegram support-userbot healthcheck + documented the missing `schedule:run`
  cron entry (H595, [PR #471](https://github.com/gasyoun/Systema-Sanscriticum/pull/471));
  class-link-autopost env killswitch wired (H593,
  [PR #467](https://github.com/gasyoun/Systema-Sanscriticum/pull/467)); MadelineProto
  IPC self-heal (kill a stale daemon on dead IPC instead of retrying in-process).
- Debt payment tariff keys so an installment opens only its own block and a real
  bundle tariff covers multi-block (H393). [PR #409](https://github.com/gasyoun/Systema-Sanscriticum/pull/409)
- A trial can now open a past class recording, not only an upcoming class.
- Mobile app (Android/iPhone student cabinet) roadmap 2026тАУ2027
  ([docs/ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md)):
  decision-locked plan for a **Capacitor hybrid wrapper** around the existing web
  cabinet (reuse-not-rebuild). MG rulings (12-07-2026): hybrid wrapper; MVP =
  courses/lessons/progress + lesson video + push + live chat; purchases stay on
  web (no store 30% cut); login email+pw + Telegram + VK, iOS email-only (Apple
  4.8); Google Play first, App Store later. Wave 1 (Capacitor scaffold) queued as H824.
  [PR #485](https://github.com/gasyoun/Systema-Sanscriticum/pull/485)

### Fixed
- `GET /login` for an already-authenticated user rendered the login form instead
  of redirecting; `AuthController::showLoginForm()` now short-circuits logged-in
  visitors (student тЖТ `/dvaram`, admin тЖТ `/admin`). Regression test
  `tests/Feature/LoginRedirectTest.php` (H806). [PR #480](https://github.com/gasyoun/Systema-Sanscriticum/pull/480)
- Marathon warm-tail never fabricates testimonial quotes
  ([PR #434](https://github.com/gasyoun/Systema-Sanscriticum/pull/434)); off-by-one
  in Day-5 testimonial warm-tail tests ([PR #437](https://github.com/gasyoun/Systema-Sanscriticum/pull/437));
  three red-main test fixes ([PR #450](https://github.com/gasyoun/Systema-Sanscriticum/pull/450));
  regenerated `package-lock.json` for the Reverb deps ([PR #443](https://github.com/gasyoun/Systema-Sanscriticum/pull/443)).

## [1.1.1] - 2026-07-09

### Added
- Selling-layout roadmap adopted for samskrte.ru: 13-layer teardown vs
  sanskritorium.ru and samskrtam.ru + 6-phase plan (hero trajectory,
  ┬л╨┐╨╛╤З╨╡╨╝╤Г ╨╝╤Л┬╗ + proof blocks, recorded-catalog conversion, free funnel,
  art direction, samskrtam.ru retrofit, book checkout). Spec:
  [SELLING_LAYOUT_COMPARISON_2026.md](https://github.com/gasyoun/Uprava/blob/main/custdev/SELLING_LAYOUT_COMPARISON_2026.md)
  (private hub); Phase 1 queued as H431.

## [1.1.0] - 2026-07-09

Large accumulated feature run merged to `main` (JuneтАУJuly 2026). Reconstructed
from git history on 2026-07-12 тАФ the original one-line snapshot understated ~3
weeks of shipped work.

### Added
- **Financial cockpit (╨д╨╕╨╜╨░╨╜╤Б╨╛╨▓╤Л╨╣ ╤И╤В╤Г╤А╨▓╨░╨╗).** Student unit economics тАФ LTV/CAC/
  retention/churn/payback (H256, [PR #340](https://github.com/gasyoun/Systema-Sanscriticum/pull/340));
  accrual P&L (╨Ю╨Я╨╕╨г) + Expense/opex model (H207, [PR #311](https://github.com/gasyoun/Systema-Sanscriticum/pull/311));
  accrual revenue recognition via `RevenueSchedule` (H258, [PR #370](https://github.com/gasyoun/Systema-Sanscriticum/pull/370));
  receivables & installments governance тАФ plan-fact + threshold + alert (H257);
  profit funds + delegation-KPI panel + review rhythm (H259,
  [PR #373](https://github.com/gasyoun/Systema-Sanscriticum/pull/373));
  orderтЖТpayment conversion + unclosed-orders list (H262,
  [PR #378](https://github.com/gasyoun/Systema-Sanscriticum/pull/378));
  revenue-reversal of the unrecognized balance on refund (H352,
  [PR #376](https://github.com/gasyoun/Systema-Sanscriticum/pull/376)).
- **Payments & access.** Deposit transfer between courses
  ([PR #356](https://github.com/gasyoun/Systema-Sanscriticum/pull/356)); PayPal
  overseas payment claims ([PR #278](https://github.com/gasyoun/Systema-Sanscriticum/pull/278));
  Dolyame in the payment-method badge/filter; a payment-method column & filter
  (H226); corpus SaтЖТRu glossary enrichment on `/slovar` entity pages (flag off,
  H344, [PR #372](https://github.com/gasyoun/Systema-Sanscriticum/pull/372)).
- **Debtor self-service.** Student debt pay-off Phase 1
  ([PR #293](https://github.com/gasyoun/Systema-Sanscriticum/pull/293)) and Phase 2
  тАФ multi-block, bundle, prana, partial, reschedule (H171,
  [PR #295](https://github.com/gasyoun/Systema-Sanscriticum/pull/295)).
- **Support automation.** `SupportAnswerSuggester` v1 тАФ LLM-free fact drafts of
  FAQ answers (H247/S3, [PR #339](https://github.com/gasyoun/Systema-Sanscriticum/pull/339));
  auto-post the Zoom link to the group chat before class (P0,
  [PR #333](https://github.com/gasyoun/Systema-Sanscriticum/pull/333));
  `support:topic-ranking` for self-serve prioritisation
  ([PR #301](https://github.com/gasyoun/Systema-Sanscriticum/pull/301)); scheduled
  per-student reminders + a curator approval queue.
- **Enrollment & groups.** Waitlist/intake module тАФ data layer, Filament board,
  CSV importer (H230, [PR #330](https://github.com/gasyoun/Systema-Sanscriticum/pull/330));
  group-recruitment shortfall notifications (H162); CRM assistant ergonomics тАФ
  fewer clicks, funnel guards, helpdesk tabs (H223,
  [PR #324](https://github.com/gasyoun/Systema-Sanscriticum/pull/324)).
- **Growth.** Registration/payment attribution тАФ UTM/referrer тЖТ `Lead` + birth
  year (A1, [PR #347](https://github.com/gasyoun/Systema-Sanscriticum/pull/347));
  M1 sale of recordings of completed courses (flag off,
  [PR #344](https://github.com/gasyoun/Systema-Sanscriticum/pull/344)); B2B partner
  (agent) referral program (H292, [PR #349](https://github.com/gasyoun/Systema-Sanscriticum/pull/349))
  + SEO-clean referral path `/mitram/{code}` ([PR #350](https://github.com/gasyoun/Systema-Sanscriticum/pull/350));
  payment-discipline score per student/group ([PR #305](https://github.com/gasyoun/Systema-Sanscriticum/pull/305));
  a multi-channel weekly nudge for never-logged-in students ([PR #316](https://github.com/gasyoun/Systema-Sanscriticum/pull/316));
  email-only newsletter subscribe тЖТ magic-link cabinet user (H324,
  [PR #361](https://github.com/gasyoun/Systema-Sanscriticum/pull/361)).
- **SEO.** Dictionary entity pages `/slovar` (Wave 0, noindex, H204,
  [PR #308](https://github.com/gasyoun/Systema-Sanscriticum/pull/308)); structured
  data тАФ Article author as Person + mainEntityOfPage ([PR #307](https://github.com/gasyoun/Systema-Sanscriticum/pull/307)),
  Course `hasCourseInstance` carousel ([PR #306](https://github.com/gasyoun/Systema-Sanscriticum/pull/306));
  P2 curated-core allowlist + Wikidata `sameAs` matcher (H210,
  [PR #374](https://github.com/gasyoun/Systema-Sanscriticum/pull/374)).
- **Backup & ops.** Weekly backup expanded from DB-only to DB + file storage with
  a Yandex Disk off-site destination (H364,
  [PR #377](https://github.com/gasyoun/Systema-Sanscriticum/pull/377) / [PR #343](https://github.com/gasyoun/Systema-Sanscriticum/pull/343));
  a goal check-in loop / standup rhythm for delegated leads (H376).
- **Telegram harvester (Track B).** Sync driver ([PR #286](https://github.com/gasyoun/Systema-Sanscriticum/pull/286))
  + media metadata / peer discovery / noforwards hardening ([PR #289](https://github.com/gasyoun/Systema-Sanscriticum/pull/289)).

### Fixed
- **Money-core.** Block a second pending order on the same course while a deposit
  is unspent (H071 #2, [PR #342](https://github.com/gasyoun/Systema-Sanscriticum/pull/342));
  partial deposit consumption + deposit-aware upgrade credit (H071 #9+#10);
  referral reward died from a relation shadowed by a `users.referrer` column (A1);
  reverse the referral reward on payment rollback ([PR #258](https://github.com/gasyoun/Systema-Sanscriticum/pull/258));
  reward only for a real course payment, not deposit/trial/conditional/тВ╜0
  ([PR #251](https://github.com/gasyoun/Systema-Sanscriticum/pull/251)); a canceled
  payment refunds prana + referral credit ([PR #248](https://github.com/gasyoun/Systema-Sanscriticum/pull/248)).
- **Access.** A VIP/bundle tariff unlocks lessons via `accessKey()` not the raw
  type ([PR #250](https://github.com/gasyoun/Systema-Sanscriticum/pull/250)); the
  homework-submission gate honours `LessonAccessGrant` (paid trial etc.,
  [PR #255](https://github.com/gasyoun/Systema-Sanscriticum/pull/255)).
- **Security.** Wave 3 automated defense тАФ PHP SAST + adversarial-review harness
  (H081); audit fixes тАФ fail-closed webhooks, anti-takeover checkout, verified
  email in social auth; VK-IDOR closed via a one-time link token
  ([PR #173](https://github.com/gasyoun/Systema-Sanscriticum/pull/173)).

## [1.0.1] - 2026-07-03

Foundational LMS build (MayтАУJuly 2026). Reconstructed from git history on
2026-07-12; this tag previously had no changelog section.

### Added
- **Mobile API** for the student cabinet on Sanctum personal-access tokens (`/api/v1`).
  [PR #167](https://github.com/gasyoun/Systema-Sanscriticum/pull/167)
- **Referral & prana gamification.** Referral program with a prana reward (H168,
  [PR #168](https://github.com/gasyoun/Systema-Sanscriticum/pull/168)) тЖТ money
  credit alternative ([PR #201](https://github.com/gasyoun/Systema-Sanscriticum/pull/201));
  achievement badges ([PR #204](https://github.com/gasyoun/Systema-Sanscriticum/pull/204)),
  leaderboard ([PR #202](https://github.com/gasyoun/Systema-Sanscriticum/pull/202)),
  streak rewards ([PR #206](https://github.com/gasyoun/Systema-Sanscriticum/pull/206)),
  a prana shop ([PR #207](https://github.com/gasyoun/Systema-Sanscriticum/pull/207)),
  a two-counter discount-wallet + accumulating rank ([PR #170](https://github.com/gasyoun/Systema-Sanscriticum/pull/170)),
  P2P transfer + weekly decay ([PR #171](https://github.com/gasyoun/Systema-Sanscriticum/pull/171) / [PR #180](https://github.com/gasyoun/Systema-Sanscriticum/pull/180)).
- **Social auth.** Socialite scaffold ([PR #169](https://github.com/gasyoun/Systema-Sanscriticum/pull/169))
  + VK / Yandex community drivers ([PR #208](https://github.com/gasyoun/Systema-Sanscriticum/pull/208)).
- **Webinars (Zoom).** Auto-create meetings from the schedule ([PR #194](https://github.com/gasyoun/Systema-Sanscriticum/pull/194)),
  auto-import recordings via the `recording.completed` webhook ([PR #195](https://github.com/gasyoun/Systema-Sanscriticum/pull/195)),
  attendance via participant webhooks ([PR #197](https://github.com/gasyoun/Systema-Sanscriticum/pull/197)).
- **Lecture editor.** Async pipeline ([PR #184](https://github.com/gasyoun/Systema-Sanscriticum/pull/184)),
  structural editing тАФ move/delete/split/merge ([PR #186](https://github.com/gasyoun/Systema-Sanscriticum/pull/186)),
  advisory lock + backup rollback ([PR #189](https://github.com/gasyoun/Systema-Sanscriticum/pull/189)),
  add-block ([PR #210](https://github.com/gasyoun/Systema-Sanscriticum/pull/210)).
- **Shop / course pages.** Public course landing pages, schedule block + carousel
  ([PR #187](https://github.com/gasyoun/Systema-Sanscriticum/pull/187) / [PR #192](https://github.com/gasyoun/Systema-Sanscriticum/pull/192)),
  ┬л╨Ч╨░╨┐╨╕╤Б╨░╤В╤М╤Б╤П/╨Ъ╤Г╨┐╨╕╤В╤М┬╗ CTA cards ([PR #191](https://github.com/gasyoun/Systema-Sanscriticum/pull/191)),
  Arzamas-style category chips ([PR #174](https://github.com/gasyoun/Systema-Sanscriticum/pull/174)),
  a typographic cover fallback ([PR #175](https://github.com/gasyoun/Systema-Sanscriticum/pull/175)),
  a ┬лnext lesson┬╗ card on `/dvaram` ([PR #177](https://github.com/gasyoun/Systema-Sanscriticum/pull/177)).
- **Cabinet & CRM.** In-cabinet support web-chat ([PR #165](https://github.com/gasyoun/Systema-Sanscriticum/pull/165));
  a teacher student-analytics dashboard ([PR #166](https://github.com/gasyoun/Systema-Sanscriticum/pull/166));
  stuck-student signals for curators ([PR #163](https://github.com/gasyoun/Systema-Sanscriticum/pull/163));
  segment messenger broadcast from the student list ([PR #164](https://github.com/gasyoun/Systema-Sanscriticum/pull/164));
  a bot hybrid-persona ([PR #200](https://github.com/gasyoun/Systema-Sanscriticum/pull/200));
  a read-only reactivation report ([PR #203](https://github.com/gasyoun/Systema-Sanscriticum/pull/203)).
- **Salary / teacher payouts.** Two teachers per course with independent pay terms
  + access; direct-to-teacher receipts (schema тЖТ capture тЖТ revenue exclusion тЖТ
  auto-offset in the payout calculator); currency conversion (PayPal) + teacher
  report; a block-participants dashboard.
- **Onboarding.** Email normalization + login self-check + dormant-student mailing
  ([PR #218](https://github.com/gasyoun/Systema-Sanscriticum/pull/218)); avatars
  from Telegram/VK; `@username` capture; attendance under a unified course Zoom link.

## [1.0.0] - 2026-06-13

### Added
- Added this changelog so repository-level changes have a stable home.
- Recorded the current repository purpose: Laravel-╨┐╤А╨╕╨╗╨╛╨╢╨╡╨╜╨╕╨╡: ╤Г╤З╨╡╨▒╨╜╤Л╨╣ ╨║╨░╨▒╨╕╨╜╨╡╤В, ╨╝╨░╨│╨░╨╖╨╕╨╜ ╨║╤Г╤А╤Б╨╛╨▓, ╨║╨╛╨╜╤Б╤В╤А╤Г╨║╤В╨╛╤А ╨╗╨╡╨╜╨┤╨╕╨╜╨│╨╛╨▓, ╤А╨╡╨┤╨░╨║╤В╨╛╤А ╨╗╨╡╨║╤Ж╨╕╨╣ ╨╕ ╨┐╨░╨╜╨╡╨╗╤М ╨░╨┤╨╝╨╕╨╜╨╕╤Б╤В╤А╨░╤В╨╛╤А╨░.

### Recent Git History
- 2026-05-29 ai-wip: add .pre-commit-config.yaml (yaml-only)
- 2026-05-29 ai-wip: add CodeQL SAST workflow (php, javascript)
- 2026-05-29 ai-wip: add .github/dependabot.yml for GitHub Actions auto-updates
- 2026-05-29 ai-wip: add CODE_OF_CONDUCT.md (Contributor Covenant 2.1)
- 2026-05-29 fix(ci): proper Vite manifest stub with entry keys

[Unreleased]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.81.1...HEAD
[1.81.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.81.0...v1.81.1
[1.81.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.15...v1.81.0
[1.80.15]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.14...v1.80.15
[1.80.14]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.13...v1.80.14
[1.80.13]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.12...v1.80.13
[1.80.12]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.11...v1.80.12
[1.80.11]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.10...v1.80.11
[1.80.10]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.9...v1.80.10
[1.80.9]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.8...v1.80.9
[1.80.8]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.7...v1.80.8
[1.80.7]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.6...v1.80.7
[1.80.6]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.5...v1.80.6
[1.80.5]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.4...v1.80.5
[1.80.4]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.3...v1.80.4
[1.80.3]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.2...v1.80.3
[1.80.2]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.1...v1.80.2
[1.80.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.80.0...v1.80.1
[1.80.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.79.3...v1.80.0
[1.79.3]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.79.2...v1.79.3
[1.79.2]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.79.1...v1.79.2
[1.79.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.79.0...v1.79.1
[1.79.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.78.0...v1.79.0
[1.78.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.77.0...v1.78.0
[1.76.3]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.76.2...v1.76.3
[1.76.2]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.76.1...v1.76.2
[1.76.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.76.0...v1.76.1
[1.76.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.75.0...v1.76.0
[1.75.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.74.0...v1.75.0
[1.74.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.73.0...v1.74.0
[1.73.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.72.0...v1.73.0
[1.72.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.71.0...v1.72.0
[1.71.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.70.0...v1.71.0
[1.70.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.69.0...v1.70.0
[1.69.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.68.0...v1.69.0
[1.68.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.67.0...v1.68.0
[1.67.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.66.0...v1.67.0
[1.66.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.65.0...v1.66.0
[1.65.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.64.0...v1.65.0
[1.64.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.63.0...v1.64.0
[1.63.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.62.2...v1.63.0
[1.62.2]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.62.1...v1.62.2
[1.62.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.62.0...v1.62.1
[1.62.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.61.0...v1.62.0
[1.61.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.60.0...v1.61.0
[1.60.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.59.1...v1.60.0
[1.59.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.59.0...v1.59.1
[1.59.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.58.0...v1.59.0
[1.58.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.57.0...v1.58.0
[1.57.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.56.0...v1.57.0
[1.56.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.55.0...v1.56.0
[1.55.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.54.0...v1.55.0
[1.54.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.53.0...v1.54.0
[1.53.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.52.0...v1.53.0
[1.52.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.51.0...v1.52.0
[1.51.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.50.1...v1.51.0
[1.50.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.50.0...v1.50.1
[1.50.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.49.1...v1.50.0
[1.49.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.49.0...v1.49.1
[1.49.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.48.0...v1.49.0
[1.48.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.47.0...v1.48.0
[1.47.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.46.0...v1.47.0
[1.46.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.45.0...v1.46.0
[1.45.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.44.0...v1.45.0
[1.44.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.43.0...v1.44.0
[1.43.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.42.0...v1.43.0
[1.42.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.41.0...v1.42.0
[1.41.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.40.0...v1.41.0
[1.40.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.39.0...v1.40.0
[1.39.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.38.0...v1.39.0
[1.38.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.37.0...v1.38.0
[1.37.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.36.0...v1.37.0
[1.36.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.35.0...v1.36.0
[1.35.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.34.0...v1.35.0
[1.34.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.33.0...v1.34.0
[1.33.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.32.0...v1.33.0
[1.32.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.31.0...v1.32.0
[1.31.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.30.0...v1.31.0
[1.30.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.29.0...v1.30.0
[1.29.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.28.0...v1.29.0
[1.28.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.27.0...v1.28.0
[1.27.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.26.0...v1.27.0
[1.26.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.25.0...v1.26.0
[1.25.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.24.0...v1.25.0
[1.24.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.23.0...v1.24.0
[1.23.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.22.0...v1.23.0
[1.22.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.21.0...v1.22.0
[1.21.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.20.0...v1.21.0
[1.20.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.19.0...v1.20.0
[1.19.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.18.1...v1.19.0
[1.18.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.18.0...v1.18.1
[1.18.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.17.1...v1.18.0
[1.17.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.17.0...v1.17.1
[1.17.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.16.0...v1.17.0
[1.16.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.15.0...v1.16.0
[1.15.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.14.0...v1.15.0
[1.14.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.13.0...v1.14.0
[1.13.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.12.0...v1.13.0
[1.12.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.11.0...v1.12.0
[1.11.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.10.0...v1.11.0
[1.10.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.0.0

