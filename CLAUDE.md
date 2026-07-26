# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

- **Backend**: Laravel 10, PHP 8.1+
- **Frontend**: Vite 5, Tailwind CSS 4, Axios
- **Admin**: Filament v3 (two panels: `admin` at `/admin`, `editor` at `/editor`)
- **Queue**: Laravel Horizon (Redis-backed)
- **DB**: MySQL (production), SQLite in-memory (tests)
- **Docker**: Laravel Sail (`./vendor/bin/sail`)

## Commands

```bash
# Frontend
npm run dev       # Vite dev server
npm run build     # Production build

# Backend
php artisan serve
php artisan migrate
php artisan migrate:fresh --seed

# Tests (SQLite in-memory, no real DB needed)
php artisan test
php artisan test --filter=TestName
php artisan test tests/Unit/
php artisan test tests/Feature/

# Linting
./vendor/bin/pint           # Format PHP (Laravel Pint)

# Queue
php artisan horizon         # Queue monitor at /horizon
php artisan queue:work
```

## Architecture

### Dual Filament Admin Panels

Two separate Filament panels exist with distinct providers:
- `app/Providers/Filament/AdminPanelProvider.php` — full admin, guarded by `is_admin`
- `app/Providers/Filament/LectureEditorPanelProvider.php` — lecture editing only, guarded by `is_lecture_editor`

Resources live in `app/Filament/Resources/` (18 resources) and `app/Filament/Editor/`.

### Payment-Driven Access Control

There is **no manual group assignment**. The `PaymentObserver` (`app/Observers/PaymentObserver.php`) calls `Payment::grantAccess()` automatically on successful payment, which adds the user to the relevant course `Group`. Access to lessons is filtered by the user's groups at query time.

### Block-Based Course Structure

Courses have `CourseBlock` records (time-windowed sections with `starts_at`/`ends_at`). `Tariff` models can scope access to specific blocks. Access is keyed by string tariff keys stored in `payments.tariff` and matched against lessons: `full`, `block_N` (whole block), and `block_N_hH` (half of a block, H ∈ {1,2}). A block can be sold "in halves": lessons carry `lesson.block_half` (1/2; null = not split), and `Tariff::accessKey()` / `Lesson::unlockingKeys()` / `Lesson::isUnlockedBy()` are the single source of truth for key generation and access checks. `Tariff::calculateFinalPriceForUser()` handles loyalty discounts (via `MarketingSetting`), deposit credits, and upgrade credit via `Tariff::upgradeCreditForUser()` (containment model: buying a whole block credits its already-paid halves; buying `full` credits all paid blocks/halves).

### Receivables & Installments Governance

Installments are **not a separate model** — an installment plan is a group of
`PaymentPromise` rows sharing an `installment_group_id` (created by
`InstallmentPlanCreator`). "Receivables" (дебиторка) = the sum of **unmet**
promises (status `active`/`expired` with an `amount`). The `Debtors` page shows
*who* owes; `ReceivablesGovernanceService` + the `ReceivablesGovernance` Filament
page ("Дебиторка: план-факт", `RoleGate::finance()`) are the *control loop*:
current receivables vs a "max allowable receivables" threshold, illiquid
(overdue) share, week-over-week delta (reconstructed from the promise ledger — no
snapshot table), installment-vs-other split, and the three installment limits
(share of sales, concurrent plans, term). Thresholds and limits live in
`config/receivables.php` (env-backed) — **never hardcode them in a page/service**.
The `receivables:check` daily command alerts finance-role users when the threshold
or a limit is breached (replaces the owner's manual monitoring).

**Commercial policy for installments (условия рассрочки — first-instalment %,
number of payments, term, and the limits above) is approved by the finance lead
(финдир), not changed by sales in isolation.** This mirrors the "Алохомора"
anti-case: installments introduced without finance sign-off produced ~2M ₽ of
illiquid receivables in 3 weeks. When widening a limit in `config/receivables.php`
or `installment_limits`, treat it as a finance decision, not a routine tweak.

### Profit Funds & Delegation KPI

The management capstone of the noboring `/cases/education` plan (H259, phase D).
Two Filament pages under the "Финансы" group, both gated by `RoleGate::finance()`:

- **`ProfitFunds`** ("Фонды прибыли", `/admin/profit-funds`) over
  `ProfitFundsService`: distributes **accrual net profit** (EBITDA from the
  accrual ОПиУ, phase B) across configurable funds (default 60 % dividends / 20 %
  reserve / 10 % team / 10 % company), accumulates over a window, and reconciles
  the **reserve fund against real cash** (accumulated ДДС net flow) — an accrued
  reserve with no cash behind it is flagged. Only positive-profit months fund;
  shares/window live in `config/profit_funds.php` (env-backed, **never hardcode**).
  Fund accumulation is a management earmark, not a bank ledger (the LMS has no
  bank-balance account) — this is labeled honestly in the UI.
- **`DelegationKpi`** ("KPI делегирования", `/admin/delegation-kpi`, first in the
  group) over `DelegationKpiService`: one operator screen aggregating all four
  phases with traffic lights — A (LTV/CAC payback), B (accrual profit + deferred
  revenue), C (receivables vs threshold), D (reserve fund). Each card links to its
  detail page. The whole point is to run the business **without the owner**.

**Review rhythm is part of the deliverable, not optional.** The anti-case
"Лингвистик" shows a perfect finance plan is useless if nobody owns it and there's
no cadence. The **owner of the numbers** is the delegated finance lead
(`RoleGate::finance()`); the weekly/monthly checklist, thresholds, and the exact
person (@DECIDE MG) live in [`docs/FINANCE_REVIEW_RHYTHM.md`](docs/FINANCE_REVIEW_RHYTHM.md).
The `finance:kpi-digest` command (weekly, Mondays 09:00) pushes the KPI summary to
finance-role users so the rhythm has teeth. **БДР план-факт** is deferred until MG
picks a canonical budget template (@DECIDE) — shown as an explicit grey "awaiting
template" card, not silently dropped.

### Order→Payment Conversion (Sales Funnel)

The phase-F superstructure of the noboring `/cases/education` plan (H262). One
Filament page under the "Продажи" group, gated by `RoleGate::finance()`:

- **`OrderPaymentConversion`** ("Конверсия заказ→оплата",
  `/admin/order-payment-conversion`) over `OrderPaymentConversionService`: the
  operational funnel report from the noboring case "Нашли и обезвредили слабое
  место в продажах" (order→payment was only 47 %). An **order** = a real
  (`is_conditional=false`) `Payment` excluding accounting/micro-purchase tariffs
  (`config('conversion.excluded_tariffs')` = `Расход`/`salary_payout`/`deposit`/
  `trial`). Conversion is measured **cohort-by-creation-date**: of orders placed
  in a period, the share that reached `paid`/`success`. Shows a headline
  conversion + traffic light (green ≥ `target_pct`, yellow ≥ `warn_pct`, else
  red), week/quarter trend, per-course and per-channel (`users.utm_source`)
  breakdowns, and a **недожатые заказы** working list (pending > `unclosed_after_days`).
  The недожатые list is an **early signal before receivables** — its data source
  (pending `Payment` rows) is distinct from `ReceivablesGovernance` (unmet
  `PaymentPromise`); the two do not share logic. A yellow nav badge shows the
  недожатые count so the operator sees the leak without opening the page.
  Thresholds/windows live in `config/conversion.php` (env-backed, **never
  hardcode**). Reuses the channel derivation of `ChannelConversionReport` (which
  measures channel→paid at the *user* level monthly — a different question).

### Group Recruitment (Набор курсов)

H162: a paying student had no way to learn her forming group was under-enrolled
on the expected start day — the curator answered manually in chat. `Group` now
carries recruitment state: `status` (`forming`/`active`/`archived`), `min_size`,
`planned_start_date`, `start_date_override` (manual reschedule, priority over
planned), `recruitment_notified_at` (dedup, mirrors `Schedule.reminded_at`).
`Group::effectiveStartDate()` = `start_date_override ?? planned_start_date`;
changing the override resets `recruitment_notified_at` so the warning cycle
re-arms for the new date. `Group::isRecruited()` = `min_size` unset (size not
checked) or `activeUsers()->count() >= min_size` — status is **not** auto-flipped
on attach (many entry points: `PaymentObserver`, `GroupMembershipManager`,
Filament); the curator flips `forming → active` manually via `GroupResource`.

Daily `groups:notify-forming-shortfall` (same slot as `debts:remind`) finds
`forming` groups whose `effectiveStartDate()` lands `recruitment_notify_lead_days`
(default 2, `MarketingSetting`) days out and still under `min_size`, then:
`GroupRecruitmentNotifier::notifyShortfall()` messages the group's `activeUsers()`
via `SendMessengerAlerts` (honest status, not silence), and
`CuratorNotifier::groupUnderEnrolled()` alerts the curators chat symmetrically.
The same notifier fires immediately from `GroupResource`'s "Зафиксировать дату"
action when a curator sets `start_date_override`, instead of waiting for the next
lead-window. Toggle: `recruitment_notify_enabled` (`MarketingSetting`).

**Availability-preference collection already existed — no new table.** Before
building a planned `enrollment_preferences` table, `/prior-art` found
`WaitlistEntry.preferred_schedule`/`timezone_note` (H230, feeds off `Intake`)
already captures "кому когда удобно" at the waitlist stage, before a `Group`
exists. `GroupResource`'s "Предпочтения" action reads them straight off
`Group::intake->waitlistEntries()` (free-text list, not a parsed day×time grid —
the source data is free text) so the curator can eyeball the popular slot before
fixing a group's date.

### Investment Model (NPV / IRR / payback)

The phase-E superstructure of the noboring `/cases/education` plan (H261),
independent of the A–D core. One Filament page under the "Финансы" group, gated
by `RoleGate::finance()`:

- **`InvestmentModel`** ("Инвест-решение", `/admin/investment-model`) over
  `InvestmentModelService`: a scenario calculator for a **large forward-looking
  spend** (book print run, hire, offline point, expensive course launch), from
  the case "Юный чемпион" where a CFO talked the owner out of opening a branch by
  the numbers. Enter capex + annual additional revenue/expense (+ optional revenue
  growth %), a discount rate and horizon; get **NPV, IRR, simple + discounted
  payback, breakeven annual cash flow**, a year-by-year cash flow table, and a
  traffic-light verdict — **two scenarios side by side** (as in the case, "with
  purchase" vs "without"). The finance math (`npv`/`irr` via bisection/`annuityFactor`/
  `paybackTime`) lives in reusable static methods on the service; it moves no money
  and stores nothing (planning-only). **Live figures** (avg check/LTV, CAC, EBITDA
  margin, monthly accrual profit) are pulled from `StudentUnitEconomicsService` +
  `FinanceCockpitReport` via `defaults()` as *starting reference points* for the
  manual scenario entry — not an auto-built scenario. Discount rate, horizon, and
  acceptable-payback threshold live in `config/investment.php` (env-backed,
  **never hardcode**; @DECIDE MG: exact cost of capital, horizon+threshold, which
  spend types to model first). The page is prefilled with the published
  "Юный чемпион" worked example (14.5M capex / 160k-mo rent → IRR ~1 %, payback in
  year 6), which doubles as the correctness validation against the real case.

### Landing Page Builder

`LandingPage` stores JSON blocks in a `content` column. The catch-all route at the bottom of `routes/web.php` resolves `/{slug}` to a landing page. Block Blade components live in `resources/views/promo/blocks/`.

### Lecture Subsystem

A separate microservice-style subdirectory (`lecture-builder/`, `lecture-ui/`) is bridged via:
- `LectureBuilderClient` — HTTP client to lecture builder microservice
- `LectureAiClient` — AI-powered features
- `LecturePatcher` / `LecturePublisher` — draft lifecycle management
- `LectureDraft` model — stores draft state before publishing

### Activity Tracking

Three-layer system:
1. `TrackUserActivity` middleware — updates `users.last_activity_at` on every request
2. `ActivityTracker` service — appends to `ActivityEvent` (append-only log)
3. `LessonView` model — per-lesson open count, time spent, heartbeat timestamp (AJAX `/api/heartbeat` from the lesson player page)

### Key Domain Relationships

```
User ──< Payment >── Tariff ──> Course
User ──< UserGroup >── Group ──< LessonGroup >── Lesson
Course ──< CourseBlock
Course ──< Lesson
Teacher ──< TeacherPayout (salary models: percent | per_student | per_block | fixed)
LandingPage ──> JSON blocks
```

## External Integrations

- **Tochka Bank** — payment provider; webhook at `/api/webhooks/tochka`
- **Telegram** — bot webhook at `/api/telegram/webhook`; `User::sendTelegramMessage()`
- **VK** — webhook at `/api/vk-webhook`; `User::sendVkMessage()`
- **DomPDF** — certificate PDF generation via `CertificateService`

### Lead-magnet bots (Telegram / VK / MAX)

Отдельные webhook-эндпоинты для доставки lead-магнита, не пересекающиеся с основными:

- Telegram: `POST /api/webhooks/telegram-magnet` (header-secret `X-Telegram-Bot-Api-Secret-Token`)
- VK: `POST /api/webhooks/vk-magnet` (secret в body)
- MAX: `POST /api/webhooks/max-magnet/{secret}` (secret в **path** — Max Bot API не поддерживает header/body-секрет)

**Ротация `max_webhook_secret`:** так как у Max секрет идет в URL, он может всплыть в логах reverse-прокси, CDN и в access-логах nginx. При любом инциденте (доступ к логам, утечка дампа БД) — перегенерировать секрет в админке `MarketingSetting` и заново запустить `php artisan max:set-magnet-webhook`. Аналогично — после смены админ-аккаунта.

Все webhook-секреты и bot-токены шифруются в БД через Eloquent `encrypted` cast (`MarketingSetting::$casts`).

## Environment Notes

- Timezone is hardcoded to `Europe/Moscow` in `config/app.php`
- Feature flags in `config/features.php`
- Admin seeding credentials from `ADMIN_EMAIL` / `ADMIN_PASSWORD` env vars
- Force HTTPS is applied in `AppServiceProvider` for production
- **Never junction/symlink a worktree's `vendor/` to another worktree's (or the main tree's) `vendor/` to skip `composer install`.** On Windows, PHP's `__DIR__`/realpath resolution for a file accessed through an NTFS junction resolves to the junction's real physical target, not the path used to reach it — so Composer's `$baseDir` computation inside `vendor/composer/autoload_*.php` always resolves to wherever `vendor/` physically lives, never the worktree accessing it through the junction. With `"optimize-autoloader": true` in `composer.json` (this repo's default), that silently makes the whole worktree run **the vendor's owning tree's `app/` code** instead of its own — a green `php artisan test` run proves nothing about the code actually being edited. Confirmed twice in practice (25/26-07-2026, [Systema-Sanscriticum#713](https://github.com/gasyoun/Systema-Sanscriticum/issues/713)): two independent sessions each hit this, then "fixed" it locally by hand-baking their own worktree's name into the shared classmap header, silently breaking every other consumer of that vendor. Run an independent `composer install` per worktree instead. If a worktree already has a `vendor/` junction (check with `Get-Item vendor | Select LinkType,Target` in PowerShell — `LinkType` shows `Junction`), remove it (`cmd /c rmdir <path>\vendor`, not `rm -rf`) and reinstall before trusting any test output from it.

## Operational hazard notes

Destructive-risk facts for this repo (do-not-rerun scripts, decoys, traps) are
registered centrally in an org-private hub
([Uprava DANGER_FACTS.md](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md),
org members only); the public-safe subset is mirrored in the generated block of
[AGENTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AGENTS.md). Check them
before running anything that writes.
