# Changelog

All notable changes to Systema-Sanscriticum are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
(since `v1.0.1`, 2026-07-03). Keep upcoming work under `[Unreleased]`; each
release is promoted to a version, tag, and GitHub release in the same pass.
Sections dated 2026-07-09 (`[1.1.0]`) and earlier were reconstructed from git
history on 2026-07-12 (backfill) — they document work that already shipped.

## [Unreleased]

## [1.14.0] - 2026-07-15

### Added
- **H962: cabinet remake Phase 0 — instrumentation-first baseline (R20 gate).**
  The current (pre-hybrid) student cabinet now emits the event vocabulary of
  [`docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md)
  §4 through the EXISTING `activity_events`/`ActivityTracker` pipeline (no new
  storage): server-side `cabinet.home.view`, `lesson.mark.mastered`,
  `access.renewal.complete` (new `PaymentTelemetryObserver`, self-service paid
  transition only — money code untouched); client-side via first-party
  `POST /dvaram/telemetry` (whitelist `ActivityEvent::CLIENT_CABINET_EVENTS`,
  inline JS partial, declarative `data-track-*` blade attributes) for
  `cabinet.continue.click`, `course.tab.view` (surface=dashboard),
  `cabinet.homework.rework.click`, `offer.impression`/`offer.click`
  (kind=next-block, locked lessons on the course page) and
  `access.renewal.start` (debt CTAs). `lesson.view.heartbeat` and
  `cabinet.live.zoom.click` are NOT double-written — the readout command
  **`php artisan cabinet:baseline`** aggregates them from their existing tables
  (`lesson_views`, `schedule_join_clicks`) under the §4 names and honestly
  lists the §4 events that have no current surface. No third-party trackers;
  no UX change. Baseline must run ≥2 weeks before the hybrid ships (R20).
  (Fable 5 `claude-fable-5`, [H962](https://github.com/gasyoun/Uprava/blob/main/handoffs/H962-Sonnet_Systema-Sanscriticum_student-cabinet-remake-instrumentation-phase_15.07.26.md))

## [1.13.0] - 2026-07-15

### Added
- **H965: kosha last-mile pipeline, Hop C difficulty-score advisory consumption.**
  `/reading/kosha-demo` (same route/flag as H959's Hop A) now also reads the
  vendored `resources/data/kosha_reading_pack_difficulty.json` — kosha's real
  `reading-pack-difficulty` dataset from its H949 scorer, not re-derived here —
  and shows the pack's composite difficulty + four axis scores (vocab, sandhi,
  morphology, compound), plus a ranked list of all 5 scored packs
  (easiest→hardest) with the current page highlighted. Purely advisory per the
  spec's Hop C ruling — nothing here reorders the reader or any course. 2 new
  tests in `tests/Feature/ReadingPackTest.php` (6 total). Closes the last open
  piece of [`docs/LAST_MILE_PIPELINE_SPEC.md`](https://github.com/gasyoun/SanskritGrammar/blob/main/docs/LAST_MILE_PIPELINE_SPEC.md)
  on the Systema side — Hops A, B, and C now all consumed.

## [1.12.0] - 2026-07-15

### Added
- **Student-cabinet mockup #5 — direction D «Путь» / Journey & membership hub (H958).** Per
  M.G. ruling R28 (15-07-2026), completing the four-direction set: the cabinet renders the
  school's ladder (письмо → грамматика → тексты) as a station map — done/current/next/horizon
  nodes, milestones as learning-contour landmarks (never payment deadlines), the next station
  «загорается» ONLY after full completion of the current one (no timers, «станция подождёт»),
  membership as path-continuity between paid stations, and an «Вне пути — и это нормально»
  shelf so the zig-zag student is never shamed. 3 pages (incl. the completion state with the
  lit ladder offer) on the shared design system; browser-verified, 6 screenshots
  ([docs/mockups/student-cabinet-remake/journey-membership-hub/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/journey-membership-hub)).
  Non-destructive; the production-direction pick is now the only remaining M.G. `@DECIDE`.

## [1.11.0] - 2026-07-15

### Added
- **Hybrid production spec for the cabinet remake (H961, ruling R29).** M.G. closed the
  four-direction exploration: production = hybrid — B «Курс как дом» chassis + A's
  «Сегодня»-band-with-homework and recovery mode + C's ownership shelves, progress rail and
  ownership-expansion offer + D's path-in-«Прогресс», completion-lighting master offer rule
  and вехи. Binding spec with page deltas vs the B v2 reference, unified offer precedence,
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
  lemma, morphology, and gloss on tap — no external link or runtime lookup
  needed, every field already lives in the vendored feed. Gated by new
  `features.kosha_reader` flag (`KOSHA_READER` env, OFF by default, mirrors
  `slovar_enrichment`/`kosha_srs`) — with the flag off the route 404s. 4 tests
  in `tests/Feature/ReadingPackTest.php`. Closes the reader half of
  [`docs/LAST_MILE_PIPELINE_SPEC.md`](https://github.com/gasyoun/SanskritGrammar/blob/main/docs/LAST_MILE_PIPELINE_SPEC.md)'s
  Hop A (Systema side); Hop B's SRS-deck import shipped separately (H955).

## [1.9.0] - 2026-07-15

### Added
- **Student-cabinet mockup #4 — direction C «Библиотека» / Learning library (H957).** Per M.G.
  ruling R27 (15-07-2026): the cabinet as a personal library of владения — five shelves
  (Идут сейчас / Мои записи / Истёкшие-с-продлением / Завершённые / Материалы), expiry
  ribbons, progress-as-navigation rail (Khan pattern) on the subject page, an
  ownership-expansion offer after progress, and the membership card as a native shelf-level
  slot. 3 pages on the shared design system; browser-verified (console clean, no 390px page
  overflow — shelf scrollers are intentional), 6 screenshots
  ([docs/mockups/student-cabinet-remake/learning-library/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/learning-library)).
  Non-destructive; winner still an M.G. `@DECIDE`.

### Fixed
- **Mobile full-page screenshots of mockups #2/#3 regenerated:** the fixed bottom bar was
  stitched mid-page by the capture method; it now renders at the page end (screenshots only,
  no mockup-code change).

## [1.8.0] - 2026-07-15

### Added
- **Student-cabinet mockup #3 — direction A «Сегодня» / Today-first coach (H956).** Per M.G.
  ruling R26 (15-07-2026): the home is a numbered day plan with a fixed honest order
  (unfinished lesson → returned homework → today's live → first steps → ONE next step after a
  real progress event), «Почему такой план?» transparency foldline answers the direction's
  opaque-authority risk, and a recovery state (declined payment) leads with the problem banner
  and suppresses all offers. 4 pages on the shared B-v2 design system so directions compare on
  architecture, not styling; browser-verified (console clean, no 390px overflow), 7 screenshots
  ([docs/mockups/student-cabinet-remake/today-first-coach/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/today-first-coach)).
  Non-destructive; winner still an M.G. `@DECIDE`.
- **H955: kosha last-mile pipeline, Rung B1 demo import.** New
  `php artisan srs:import-kosha-b1-demo` (`app/Console/Commands/ImportKoshaSrsDeckB1Demo.php`)
  imports the vendored feed `resources/data/kosha_srs_deck_b1_demo.json`
  (kosha manifest id `kosha-srs-deck-b1-demo` — content vocabulary of the
  Nala-1 reading pack, `core_rank`-ordered, function words stripped) into one
  system Saraswati SRS deck (`kosha-b1-demo`), mirroring
  `SrsSanskritDeckSeeder`/`ImportMemriseSrsDeck`'s idempotent `firstOrCreate`
  pattern. Card insertion order == feed `rank` order (`srs_cards` has no
  `sort_rank` column yet — a schema migration is deliberately deferred to a
  human-reviewed production follow-up, not built here). Gated by new
  `features.kosha_srs` flag (`KOSHA_SRS` env, OFF by default, mirrors
  `slovar_enrichment`) — with the flag off the command writes nothing.
  5 tests in `tests/Feature/Srs/ImportKoshaSrsDeckB1DemoTest.php`.

## [1.7.0] - 2026-07-15

### Added
- **Student-cabinet mockup #2 — «Курс как дом» v2 (H954, iterates H822 direction B).** Per
  M.G. rulings R21–R25 (14-07-2026, recorded in
  [docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md)):
  8 pages instead of 4 (+ библиотека записей со слотом членства «Самскрте+» 2000 ₽/мес,
  календарь, прогресс+сертификат, помощь/сообщения), editorial-academism restyle, job-named
  navigation («Сегодня / Календарь / Записи / Прогресс / Оплата и доступ / Помощь»), light JS
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
  and the first browser-verified static mockup — direction B «Курс как дом» (course
  workspace), 4 linked pages, light/dark, mobile bottom-nav, console-clean, screenshots
  committed ([docs/mockups/student-cabinet-remake/course-workspace/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace)).
  Non-destructive: no production Blade/route/controller changes. Winner is an explicit
  M.G. `@DECIDE`; remaining three mockups are decision-gated.

## [1.5.0] - 2026-07-14

### Added
- **Companion metadocs for the last 16 docs — UX-audits, strategy & one-offs (H891).** Third
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
  maintenance/sunset, deprecation status, and revision history — closing the "13 roadmap docs
  carry zero metadoc coverage" gap flagged in the 13-07-2026 weekly review. Each metadoc is in
  its subject's language (ru/en). Docs only.
- **Optimisation & bottleneck backlog (H881), `docs/OPTIMISATION_BACKLOG_2026H2.md`
  (+ metadoc).** The single leverage-ranked index of what needs unblocking / speeding up /
  paying down, replacing the prior scatter across `.ai_state.md` Dev Notes and ~15 topic
  roadmaps. Every row fact-checked against `origin/main` on 13-07-2026 — which surfaced that
  the Laravel-EOL row and the message-store-unification row were both already resolved (H862
  10→12; the `UnifiedMessage`/`UnifiedInboxReader` read layer from 01-07-2026), and that
  `vendor/` bloat is a non-issue. Documentation only — no product change, so intentionally
  not release-cut.

### Fixed
- **Test suite no longer depends on a built frontend (H884).** `@vite` throws
  `ManifestNotFoundException` (→ 500) when `public/build/manifest.json` is absent,
  which locally turned every view-rendering feature test into a false failure until
  `npm run build` was run. Hoisted `withoutVite()` from two ad-hoc per-test `setUp()`
  overrides into the base `Tests\TestCase::setUp()`, so all 235 feature tests are
  immune to a missing manifest with no build step. CI's manifest-stub is now
  belt-and-suspenders. (Fixes a §2 dev-loop item from `docs/OPTIMISATION_BACKLOG_2026H2.md`.)

### Security
- **Semgrep PHP SAST promoted from advisory to a required/blocking gate (H885).**
  Cleared the 18 advisory findings that were keeping it non-blocking (H081 Part A,
  `docs/SECURITY_ROADMAP.md` Wave 3): pinned all 13 GitHub Actions `uses:` to full
  commit SHAs (supply-chain hardening, Dependabot-maintained), added a 7-day
  Dependabot `cooldown` to all three ecosystems, and removed a stray
  `index.nginx-debian.html` (nginx default page) from the repo root. `semgrep.yml`
  now runs with `--error` and no `continue-on-error`, so a new SAST finding fails
  the PR. Executes a §3 tech-debt item from `docs/OPTIMISATION_BACKLOG_2026H2.md` (H881).

## [1.4.0] - 2026-07-13

### Added
- **Optimisation & bottleneck backlog (H881), `docs/OPTIMISATION_BACKLOG_2026H2.md`
  (+ metadoc).** The single leverage-ranked index of what needs unblocking / speeding up /
  paying down, replacing the prior scatter across `.ai_state.md` Dev Notes and ~15 topic
  roadmaps. Every row fact-checked against `origin/main` on 13-07-2026 (which surfaced that
  the Laravel-EOL row was already resolved by H862's 10→12 upgrade, and that `vendor/` bloat
  is a non-issue). Documentation only — no product change, so intentionally not release-cut.
- **FAQ-суггестер v2 — LLM-черновики для категорий D/E/F (H816 PR 1, тикет S5).**
  Расширяет фактологический суггестер v1 (A/B/C, без LLM) на самые частотные
  «человеческие» категории: D «оплата/цена/тарифы» (7.4% FAQ), E «доступ/группа/
  кабинет», F «материалы/ДЗ/сертификаты». Детект — дешёвым regex-префильтром;
  ЦИФРЫ берутся из кода LMS (тариф через `Tariff::calculateFinalPriceForUser()` —
  единственный источник истины по цене, активные группы, число опубликованных
  уроков), а внешний LLM (`CuratorAi`/OpenRouter) лишь ФОРМУЛИРУЕТ из них черновик.
  Как и v1, бот ничего не отправляет — только заводит pending
  `SupportAnswerSuggestion` куратору. Три страховки: флаг `support_ai_assist`
  (иначе категория опознана, но черновик не строится); дневной cap LLM-вызовов
  (`MarketingSetting.support_ai_daily_cap` → дефолт `config('features.support_ai_daily_cap')`,
  считается по событиям `answer_llm_drafted`); приватность — сырой текст
  импортированного Telegram-ЛС уходит в LLM только при `support_ai_include_telegram`
  (факты LMS — всегда). Новый `SupportLlmDraftComposer`; миграция
  `marketing_settings.support_ai_daily_cap` (nullable, аддитивная). Всё за флагами,
  OFF по умолчанию — прод не затронут. Feature-тесты с фейковым LLM (Http::fake),
  19/19 green; полный `tests/Feature/Support` — 79/79.
- **Ростер-бот: куратор-команды `/группа` и `/кто` (H816 PR 3, тикет S6).**
  Достраивают заглушку `/группа` до настоящего ростера поверх `Group::activeUsers()`:
  `/группа <название>` — активный состав группы + курс(ы) + долговой маркер ⚠️/✅
  (присутствие в `DebtorsReport`, read-only, БЕЗ подсчёта суммы — денежная логика
  не дублируется); `/группа` без аргумента — список групп; `/кто <имя|@username>` —
  поиск студента по имени/username/email с карточкой (в каких активных группах,
  какие курсы). Та же роль-авторизация, что у `/долги` (S4): admin/manager/super_admin,
  посторонним/студентам — тишина. Новый `App\Services\Bot\RosterBotCommand` (по образцу
  `DebtorsBotCommand`), заглушка `/группа` из `DebtorsBotCommand` убрана. Чистый
  LMS-запрос: без LLM, без новых кред, без миграций — на проде работает сразу после
  выката. Feature-тесты `RosterBotCommandTest` 9/9; полный Bot+Webhooks сьют — 95/95.
- **Планировщик анонсов — `scheduled_at` (H816 PR 2).** Раньше анонс
  рассылался СИНХРОННО при создании (`CreateAnnouncement::afterCreate`) — отсюда
  аврал перед запуском. Теперь у анонса есть `scheduled_at` (пусто = «отправить
  сразу»): рассылка по каналам email/Telegram/VK уходит, когда наступит срок,
  командой `announcements:dispatch-due` (в `Kernel::schedule()`, каждые 5 минут).
  Логика рассылки вынесена из Filament-страницы в переиспользуемый
  `App\Services\AnnouncementDispatcher`; идемпотентность — по `dispatched_at`
  (один анонс не уходит дважды). Поле «Запланировать рассылку на» + колонка
  «Запланировано» в админке (Рассылки). Аддитивная миграция
  `announcements.scheduled_at`/`dispatched_at` (обе nullable) — существующие
  немедленные рассылки идут тем же путём, ничего не ломается. Feature-тесты
  `AnnouncementSchedulerTest` — 6/6 (due→рассылка+дедуп, future→тишина,
  unpublished/без-канала→тишина, немедленная через диспетчер).

### Changed
- **Тесты гоняются параллельно — `paratest` (H868).** `brianium/paratest ^7` добавлен в `require-dev`; CI-шаг и локальный прогон переведены на `php artisan test --parallel` (8 процессов локально). Весь набор **1503 теста / 4312 assertions зелёные** параллельно — parallel-safe, гонок по общим файловым путям нет. Сокращает время прогона CI (был ~12.5 мин последовательно) и локали пропорционально числу ядер.

### Security
- **Laravel 10 → 12: закрыты HIGH+MODERATE Dependabot-адвайзори (H862).**
  `laravel/framework` поднят `^10.10` → `^12.63` (плюс `laravel/sanctum` 3→4,
  `phpunit/phpunit` 10→11, `nunomaduro/collision` 7→8, `symfony/css-selector`+`dom-crawler` 6→7,
  `barryvdh/laravel-dompdf` 2→3, `spatie/laravel-backup` 8→9). Закрывает
  [Dependabot #14](https://github.com/gasyoun/Systema-Sanscriticum/security/dependabot/14)
  (HIGH, GHSA-5vg9-5847-vvmq — CRLF-инъекция в дефолтном правиле валидации `email`) и
  [#15](https://github.com/gasyoun/Systema-Sanscriticum/security/dependabot/15)
  (MODERATE, GHSA-crmm-hgp2-wgrp — path confusion во временных подписанных URL):
  фикс только в Laravel 11+, бэкпорта под EOL-нутую 10.x нет, поэтому Dependabot не мог
  открыть PR. Классический скелет (`bootstrap/app.php` + `Http/Kernel`) сохранён —
  Filament v3.3.54 уже поддерживает Laravel 12 (прыжок Filament 3→4 не нужен), а
  `jenssegers/agent` не имеет Laravel-констрейнта (замена не потребовалась). Правки под
  нативный SQLite-DDL Laravel 11 (Doctrine DBAL убран): снятие FK/индекса до `DROP COLUMN`
  в [`2026_03_09_..._payments`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_03_09_093322_replace_landing_page_id_with_course_id_in_payments_table.php)
  и [`2026_06_02_..._direct_ad_spends`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_06_02_000001_direct_ad_spends_to_period.php);
  контракт `Authenticatable::getAuthPasswordName()` в
  [`GuestChatUser`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Auth/GuestChatUser.php);
  Carbon 3 `diffInDays()` теперь возвращает float → каст в
  [`DirectAdSpend::periodDays()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DirectAdSpend.php);
  экранирование JSON-LD `@context` (в Laravel 11 `@context` стала Blade-директивой) в
  [`articles/show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/articles/show.blade.php).
  Устаревшие `audit.ignore` для L10-адвайзори убраны из `composer.json`. Весь набор
  **1503/1503 зелёный**, `composer audit` — чисто. Прогон под Opus 4.8 (`claude-opus-4-8`).

## [1.3.0] - 2026-07-13

### Added
- **Разблокировка застрявшего студента одним кликом + лента «Проблемы со входом» (H849).**
  До сих пор неудачные попытки входа/восстановления НИГДЕ не логировались.
  Теперь: (1) новая таблица `access_attempts` собирает единой лентой неудачные
  логины (слушатель `Auth\Events\Failed` на `/login` и `/shop/login`) и запросы
  ссылки восстановления (`reset_sent`/`reset_not_found`/`reset_throttled`,
  логируются в [`PasswordResetController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PasswordResetController.php));
  (2) Filament-ресурс «Проблемы со входом» ([`AccessAttemptResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/AccessAttemptResource.php))
  с бейджем «застрявших» и разблокировкой из строки, плюс кнопка «Разблокировать»
  на карточке студента ([`UserResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/UserResource.php));
  (3) разблокировка = снять IP-троттл + выдать **одноразовую magic-ссылку для входа**
  (24 ч, hashed-at-rest, назначение `admin_unblock`, маршрут `/login-link/{token}`),
  которую админ передаёт студенту напрямую, минуя сломанную почту (+ опц. сброс
  пароля) — [`StudentUnblockService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Access/StudentUnblockService.php);
  (4) Telegram: проактивный алерт админам с inline-кнопкой «🔓 Выслать ссылку»
  при сигнале «застрял» (троттл восстановления / серия неудачных логинов) +
  текстовая команда `/unblock <email>` — авторизация строго `super_admin`/`admin`
  ([`UnblockBotCommand`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/UnblockBotCommand.php),
  [`TelegramWebhookController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TelegramWebhookController.php)).
  Проактивные алерты идут на `ADMIN_TELEGRAM_ID`. Не устраняет корневую причину
  недоставки писем (боевой SMTP) — но даёт админу обойти её вручную. Документация:
  [`docs/student-unblock-access-feed.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-unblock-access-feed.md).

## [1.2.1] - 2026-07-13

### Fixed
- **Password-reset «Слишком много попыток» на первой попытке (H840).** Брокер
  Laravel возвращает `RESET_THROTTLED`, когда ссылку для входа уже отправили
  меньше минуты назад (per-email троттл, `config/auth.php`), — это НЕ перебор.
  Прежняя красная ошибка «Слишком много попыток. Подождите минуту» пугала
  студента на фактически первой попытке (письмо часто просто в «Спаме» или
  задержалось). Теперь этот случай показывает тот же зелёный блок «мы уже
  отправили ссылку — проверьте почту и „Спам“, не пришло за 5 минут — запросите
  снова», что и успешная отправка ([`PasswordResetController::sendResetLink`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PasswordResetController.php)).
  UX-правка формулировки; корневая причина недоставки писем (боевой SMTP/домен
  отправителя) остаётся отдельным серверным вопросом.

## [1.2.0] - 2026-07-12

### Added
- **Native live-chat support widget (H536), Phases 1–5 complete + observability.**
  Laravel Reverb WebSocket transport (`ChatMessageSent` on the private
  `support.conversation.{id}` channel, [PR #432](https://github.com/gasyoun/Systema-Sanscriticum/pull/432));
  guest identity — an anonymous samskrte.ru visitor owns a thread via a session
  `guest_token` (ephemeral ownership marker, **not** a 4th external-identity
  mapping; `chat_messages.user_id` now nullable; `chat_guest` broadcasting guard),
  [PR #461](https://github.com/gasyoun/Systema-Sanscriticum/pull/461); rate-limited
  public post endpoint (`POST /chat/message`, `GET /chat/history` via
  `PublicChatController`), [PR #463](https://github.com/gasyoun/Systema-Sanscriticum/pull/463);
  storefront visitor bubble, [PR #468](https://github.com/gasyoun/Systema-Sanscriticum/pull/468);
  guest web-chat in the operator inbox with live reply,
  [PR #470](https://github.com/gasyoun/Systema-Sanscriticum/pull/470); and a
  support observability dashboard — session health, sync lag, delivery rate, LLM
  volume (H597), [PR #469](https://github.com/gasyoun/Systema-Sanscriticum/pull/469).
  A guest never resolves to a `users` row (no account-takeover); output stays
  escaped via `ChatMessage::htmlForWeb()`. Live once Reverb is deployed on the host.
- **3-day diagnostic marathon «Консультация по онлайн-курсам ОРС» (H440), all 6 phases.**
  Landing + capture with a personal `day0_started_at` clock (anti-urgency),
  [PR #407](https://github.com/gasyoun/Systema-Sanscriticum/pull/407); drip engine
  with Day 1/2 Telegram content, [PR #410](https://github.com/gasyoun/Systema-Sanscriticum/pull/410);
  genuine tap-choice UI for Days 1/2, [PR #421](https://github.com/gasyoun/Systema-Sanscriticum/pull/421);
  paid track (₽500) checkout via Tochka, [PR #415](https://github.com/gasyoun/Systema-Sanscriticum/pull/415);
  live Day-3 consultation + recording delivery, [PR #423](https://github.com/gasyoun/Systema-Sanscriticum/pull/423);
  and a 13-day evergreen warm-tail (Days 4–16) that auto-stops once `paid_at` is
  set, [PR #424](https://github.com/gasyoun/Systema-Sanscriticum/pull/424).
- **Cohort-aware marathon engine (H445):** cohort core
  ([PR #436](https://github.com/gasyoun/Systema-Sanscriticum/pull/436)), a
  level-quiz for the Devanagari cohort ([PR #438](https://github.com/gasyoun/Systema-Sanscriticum/pull/438)),
  and Day-1 name-in-Devanagari for that cohort ([PR #446](https://github.com/gasyoun/Systema-Sanscriticum/pull/446)).
- Selling-layout journey layer on the homepage (H431 Phase 1): hero rebuilt around
  a three-path learning trajectory (Письмо/чтение → Грамматика → Тексты/чанты)
  resolved to real courses, a «Почему мы» credentials block, and a proof block
  (years/books/crowdfunding from `config/trust.php` + real testimonial slots).
  [PR #427](https://github.com/gasyoun/Systema-Sanscriticum/pull/427)
- Configurable CRM lead stages (GC-C1): a `lead_stages` table replaces the
  hardcoded `Lead::STATUSES`/`FINAL_STATUSES`, plus a Filament drag-drop kanban
  board (`/admin/leads-board`). [PR #408](https://github.com/gasyoun/Systema-Sanscriticum/pull/408)
- SRS «Saraswati» trainer suite, Phase 1 enable-and-connect (H447).
  [PR #442](https://github.com/gasyoun/Systema-Sanscriticum/pull/442)
- Sanskrit interactive exercises: a sort-into-groups engine + genders drill and
  generator (H551, [PR #441](https://github.com/gasyoun/Systema-Sanscriticum/pull/441))
  and a noun↔pronoun gender-agreement sort drill (H561,
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
- Mobile app (Android/iPhone student cabinet) roadmap 2026–2027
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
  visitors (student → `/dvaram`, admin → `/admin`). Regression test
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
  «почему мы» + proof blocks, recorded-catalog conversion, free funnel,
  art direction, samskrtam.ru retrofit, book checkout). Spec:
  [SELLING_LAYOUT_COMPARISON_2026.md](https://github.com/gasyoun/Uprava/blob/main/custdev/SELLING_LAYOUT_COMPARISON_2026.md)
  (private hub); Phase 1 queued as H431.

## [1.1.0] - 2026-07-09

Large accumulated feature run merged to `main` (June–July 2026). Reconstructed
from git history on 2026-07-12 — the original one-line snapshot understated ~3
weeks of shipped work.

### Added
- **Financial cockpit (Финансовый штурвал).** Student unit economics — LTV/CAC/
  retention/churn/payback (H256, [PR #340](https://github.com/gasyoun/Systema-Sanscriticum/pull/340));
  accrual P&L (ОПиУ) + Expense/opex model (H207, [PR #311](https://github.com/gasyoun/Systema-Sanscriticum/pull/311));
  accrual revenue recognition via `RevenueSchedule` (H258, [PR #370](https://github.com/gasyoun/Systema-Sanscriticum/pull/370));
  receivables & installments governance — plan-fact + threshold + alert (H257);
  profit funds + delegation-KPI panel + review rhythm (H259,
  [PR #373](https://github.com/gasyoun/Systema-Sanscriticum/pull/373));
  order→payment conversion + unclosed-orders list (H262,
  [PR #378](https://github.com/gasyoun/Systema-Sanscriticum/pull/378));
  revenue-reversal of the unrecognized balance on refund (H352,
  [PR #376](https://github.com/gasyoun/Systema-Sanscriticum/pull/376)).
- **Payments & access.** Deposit transfer between courses
  ([PR #356](https://github.com/gasyoun/Systema-Sanscriticum/pull/356)); PayPal
  overseas payment claims ([PR #278](https://github.com/gasyoun/Systema-Sanscriticum/pull/278));
  Dolyame in the payment-method badge/filter; a payment-method column & filter
  (H226); corpus Sa→Ru glossary enrichment on `/slovar` entity pages (flag off,
  H344, [PR #372](https://github.com/gasyoun/Systema-Sanscriticum/pull/372)).
- **Debtor self-service.** Student debt pay-off Phase 1
  ([PR #293](https://github.com/gasyoun/Systema-Sanscriticum/pull/293)) and Phase 2
  — multi-block, bundle, prana, partial, reschedule (H171,
  [PR #295](https://github.com/gasyoun/Systema-Sanscriticum/pull/295)).
- **Support automation.** `SupportAnswerSuggester` v1 — LLM-free fact drafts of
  FAQ answers (H247/S3, [PR #339](https://github.com/gasyoun/Systema-Sanscriticum/pull/339));
  auto-post the Zoom link to the group chat before class (P0,
  [PR #333](https://github.com/gasyoun/Systema-Sanscriticum/pull/333));
  `support:topic-ranking` for self-serve prioritisation
  ([PR #301](https://github.com/gasyoun/Systema-Sanscriticum/pull/301)); scheduled
  per-student reminders + a curator approval queue.
- **Enrollment & groups.** Waitlist/intake module — data layer, Filament board,
  CSV importer (H230, [PR #330](https://github.com/gasyoun/Systema-Sanscriticum/pull/330));
  group-recruitment shortfall notifications (H162); CRM assistant ergonomics —
  fewer clicks, funnel guards, helpdesk tabs (H223,
  [PR #324](https://github.com/gasyoun/Systema-Sanscriticum/pull/324)).
- **Growth.** Registration/payment attribution — UTM/referrer → `Lead` + birth
  year (A1, [PR #347](https://github.com/gasyoun/Systema-Sanscriticum/pull/347));
  M1 sale of recordings of completed courses (flag off,
  [PR #344](https://github.com/gasyoun/Systema-Sanscriticum/pull/344)); B2B partner
  (agent) referral program (H292, [PR #349](https://github.com/gasyoun/Systema-Sanscriticum/pull/349))
  + SEO-clean referral path `/mitram/{code}` ([PR #350](https://github.com/gasyoun/Systema-Sanscriticum/pull/350));
  payment-discipline score per student/group ([PR #305](https://github.com/gasyoun/Systema-Sanscriticum/pull/305));
  a multi-channel weekly nudge for never-logged-in students ([PR #316](https://github.com/gasyoun/Systema-Sanscriticum/pull/316));
  email-only newsletter subscribe → magic-link cabinet user (H324,
  [PR #361](https://github.com/gasyoun/Systema-Sanscriticum/pull/361)).
- **SEO.** Dictionary entity pages `/slovar` (Wave 0, noindex, H204,
  [PR #308](https://github.com/gasyoun/Systema-Sanscriticum/pull/308)); structured
  data — Article author as Person + mainEntityOfPage ([PR #307](https://github.com/gasyoun/Systema-Sanscriticum/pull/307)),
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
  reward only for a real course payment, not deposit/trial/conditional/₽0
  ([PR #251](https://github.com/gasyoun/Systema-Sanscriticum/pull/251)); a canceled
  payment refunds prana + referral credit ([PR #248](https://github.com/gasyoun/Systema-Sanscriticum/pull/248)).
- **Access.** A VIP/bundle tariff unlocks lessons via `accessKey()` not the raw
  type ([PR #250](https://github.com/gasyoun/Systema-Sanscriticum/pull/250)); the
  homework-submission gate honours `LessonAccessGrant` (paid trial etc.,
  [PR #255](https://github.com/gasyoun/Systema-Sanscriticum/pull/255)).
- **Security.** Wave 3 automated defense — PHP SAST + adversarial-review harness
  (H081); audit fixes — fail-closed webhooks, anti-takeover checkout, verified
  email in social auth; VK-IDOR closed via a one-time link token
  ([PR #173](https://github.com/gasyoun/Systema-Sanscriticum/pull/173)).

## [1.0.1] - 2026-07-03

Foundational LMS build (May–July 2026). Reconstructed from git history on
2026-07-12; this tag previously had no changelog section.

### Added
- **Mobile API** for the student cabinet on Sanctum personal-access tokens (`/api/v1`).
  [PR #167](https://github.com/gasyoun/Systema-Sanscriticum/pull/167)
- **Referral & prana gamification.** Referral program with a prana reward (H168,
  [PR #168](https://github.com/gasyoun/Systema-Sanscriticum/pull/168)) → money
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
  structural editing — move/delete/split/merge ([PR #186](https://github.com/gasyoun/Systema-Sanscriticum/pull/186)),
  advisory lock + backup rollback ([PR #189](https://github.com/gasyoun/Systema-Sanscriticum/pull/189)),
  add-block ([PR #210](https://github.com/gasyoun/Systema-Sanscriticum/pull/210)).
- **Shop / course pages.** Public course landing pages, schedule block + carousel
  ([PR #187](https://github.com/gasyoun/Systema-Sanscriticum/pull/187) / [PR #192](https://github.com/gasyoun/Systema-Sanscriticum/pull/192)),
  «Записаться/Купить» CTA cards ([PR #191](https://github.com/gasyoun/Systema-Sanscriticum/pull/191)),
  Arzamas-style category chips ([PR #174](https://github.com/gasyoun/Systema-Sanscriticum/pull/174)),
  a typographic cover fallback ([PR #175](https://github.com/gasyoun/Systema-Sanscriticum/pull/175)),
  a «next lesson» card on `/dvaram` ([PR #177](https://github.com/gasyoun/Systema-Sanscriticum/pull/177)).
- **Cabinet & CRM.** In-cabinet support web-chat ([PR #165](https://github.com/gasyoun/Systema-Sanscriticum/pull/165));
  a teacher student-analytics dashboard ([PR #166](https://github.com/gasyoun/Systema-Sanscriticum/pull/166));
  stuck-student signals for curators ([PR #163](https://github.com/gasyoun/Systema-Sanscriticum/pull/163));
  segment messenger broadcast from the student list ([PR #164](https://github.com/gasyoun/Systema-Sanscriticum/pull/164));
  a bot hybrid-persona ([PR #200](https://github.com/gasyoun/Systema-Sanscriticum/pull/200));
  a read-only reactivation report ([PR #203](https://github.com/gasyoun/Systema-Sanscriticum/pull/203)).
- **Salary / teacher payouts.** Two teachers per course with independent pay terms
  + access; direct-to-teacher receipts (schema → capture → revenue exclusion →
  auto-offset in the payout calculator); currency conversion (PayPal) + teacher
  report; a block-participants dashboard.
- **Onboarding.** Email normalization + login self-check + dormant-student mailing
  ([PR #218](https://github.com/gasyoun/Systema-Sanscriticum/pull/218)); avatars
  from Telegram/VK; `@username` capture; attendance under a unified course Zoom link.

## [1.0.0] - 2026-06-13

### Added
- Added this changelog so repository-level changes have a stable home.
- Recorded the current repository purpose: Laravel-приложение: учебный кабинет, магазин курсов, конструктор лендингов, редактор лекций и панель администратора.

### Recent Git History
- 2026-05-29 ai-wip: add .pre-commit-config.yaml (yaml-only)
- 2026-05-29 ai-wip: add CodeQL SAST workflow (php, javascript)
- 2026-05-29 ai-wip: add .github/dependabot.yml for GitHub Actions auto-updates
- 2026-05-29 ai-wip: add CODE_OF_CONDUCT.md (Contributor Covenant 2.1)
- 2026-05-29 fix(ci): proper Vite manifest stub with entry keys

[Unreleased]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.12.0...HEAD
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
[1.0.1]: https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.0.1
[1.0.0]: https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.0.0
