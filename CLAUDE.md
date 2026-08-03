# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

- **Backend**: Laravel 10, PHP 8.1+
- **Frontend**: Vite 5, Tailwind CSS 4, Axios
- **Admin**: Filament v3 (two panels: `admin` at `/admin`, `editor` at `/editor`)
- **Queue**: Laravel Horizon (Redis-backed)
- **DB**: MySQL (production), SQLite in-memory (tests)
- **Docker**: Laravel Sail (`./vendor/bin/sail`)

## Ops / uptime (production)

- **Agents (EN inventory + smoke + §5 runbook):**  
  [docs/UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md)  
  Better Stack team, HTTP vs heartbeat, samskrte/samskrtam/Cologne, VPS cron paths.  
  Do not re-derive from chat; tokens stay on the VPS only.
- **Humans (RU):**  
  [docs/UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md)  
  §1 students/teachers → [samskrte.ru/uptime](https://samskrte.ru/uptime) + tag `@rusamskrtam`;  
  §2 Ivan/Marcis (red monitors; Artem only via ops). Mirror: `uptime/` on GitHub Pages.
- **OS resource guards (OOM/cron):**  
  [docs/server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md)
- **Soft TG «Кабинет: soft-сбой (guards)» + `auto_deploy.disabled` / tracked dirty ≠ падение кабинета.**  
  **Человеку нужно:** код/тексты — PR → `main` → auto-deploy; отзыв — env/MarketingSetting; PDF — `public/docs/*.pdf`.  
  **Человеку не нужно:** править tracked (`config/`, `app/`, …) на проде.  
  **Primary agent playbook (catalog + safe/never-auto + incident log):**  
  [docs/SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md).  
  Safe auto: `php artisan ops:soft-remediate` (origin-equal dirty only; never blind fuse clear).  
  Webhook→issue→agent skeleton (OFF until env set): [docs/ops/SOFT_ALERT_WEBHOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ops/SOFT_ALERT_WEBHOOK.md).  
  Лестница + случай 01-08-2026 (`config/marathon_landing_copy.php`) — **по-русски** в  
  [docs/server-resource-guards.md §8.1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md) ·  
  dirty-gate: [docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md) ·  
  class: [Uprava FINDINGS §280](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md) ·  
  danger: [Uprava DANGER_FACTS Systema](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md).

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

**GC-C2 manager attribution is a SEPARATE page, not a breakdown on this one**
(H2058 residual, `docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md` §4, F5 RULED
02-08-2026). `ManagerSalesReport` ("Продажи по менеджеру",
`/admin/manager-sales-report`, flag `manager_sales_report` default **OFF**) over
`OrderPaymentConversionService::managerScoreboard()` groups the same order
denominator by **`payments.created_by_user_id`** (who created the payment row —
a blame column) — **not** `leads.assigned_to`, which is near-empty by
construction on conversion-eligible tariffs (populated only on deposit/trial/
marathon, and deposit/trial are excluded from the order denominator anyway).
Visibility: `super_admin`/`admin`/`accountant` see all rows (incl. the "Без
менеджера" unassigned bucket for self-serve/webhook orders); `manager` sees
only their own rows, via `RoleGate::managerSalesReport()`. **Do not fold this
breakdown into `OrderPaymentConversion` / its `RoleGate::finance()` gate** —
that page deliberately excludes the `manager` role (locked by test), and this
scoreboard's row-level self-scoping for managers requires its own surface.

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

### Group Reviewers (проверяющие домашек по группам)

H1729: преподаватель мог проверять домашки только тех курсов, где он **основной**
препод (`course.teacher_id`). Чтобы дать человеку проверку чужих групп, нужен был
грант, не касающийся зарплаты.

`group_reviewer` — pivot `groups` × **`users`** (не `teachers`!). Привязка к
`users.id` сознательна: `homework_submissions.reviewed_by` уже указывает туда, а
главное — со-препод в pivot `course_teacher` попадает в `Course::salaryTermsFor()`
(возвращает `['type' => $co->pivot->salary_type, ...]`, то есть **не** `null`), и
`TeacherSalaryService` начал бы начислять проверяющему ЗП с выручки чужих курсов.
**Никогда не выдавайте доступ к домашкам через `course_teacher`** — это закрыто
регрессионным тестом `grant_does_not_accrue_salary_for_the_reviewer`.

Правило видимости — `HomeworkSubmission::scopeInReviewableGroups()`, единственный
источник правды (им же питается `reviewGroupIds()` для уведомлений): у урока
проставлена `group_id` — сверяем по ней; урок общий — требуем, чтобы **нашлась**
группа из гранта, в которой состоит автор работы **И** к которой привязан курс
работы. Без второй половины проверяющий группы 60 увидел бы работы того же ученика
по чужому курсу — ученик может состоять в нескольких группах. Своя работа в свою
очередь проверки не попадает никогда.

Грант расширяет ровно две поверхности: очередь домашек (`HomeworkSubmissionResource`,
ветка `orWhere` **рядом** со «своими курсами», не вместо — препод может вести своё и
проверять чужое) и состав группы (`StudentGroupResource`). Курсы, уроки, расписание,
сертификаты и карточки учеников грантом не открываются. `AttendanceDashboard`
править не пришлось: он вообще не скоупится по `teacher_id` — любой `role=teacher`
уже видит посещаемость всех групп (предсуществующее поведение, не вводится здесь).

Уведомления — `HomeworkNotifier`: проверяющим с `notify=true` идут колокольчик
(`sendToDatabase`), письмо и Telegram (каналы в `config/homework.php`), а
преподавателю курса персональные письма **заменяются** недельной сводкой
`homework:reviewer-digest` — но только если у группы есть активный проверяющий;
курсы без проверяющих ведут себя ровно как раньше. Раздаёт гранты только админ
(действие «Проверяющие» в `GroupResource`).

### Mutual Settlement (взаимозачёт преподаватель-ученик)

H1730, волна B того же плана, что и Group Reviewers выше. Один человек платит
школе как ученик и получает от неё гонорар как преподаватель; гоняя деньги в обе
стороны, школа теряет 6 % НПД на каждом проходе. Ruling MG 27-07-2026: **зачёт
считается и вычитается ДО создания выплаты**.

`MutualSettlementService` — единственное место, где живёт арифметика (страница и
команда только рисуют). Две дисциплины, которые нельзя нарушать:

1. **Второго расчёта ЗП не заводить.** Зарплатная цифра берётся у
   `TeacherSalaryService::totalForTeacher()`; здесь только разбивка по курсам
   поверх. Список нерасходных тарифов — `TeacherSalaryService::NON_REVENUE_TARIFFS`
   (она публичная **именно поэтому**), а не своя копия: разъехавшись, две копии
   дали бы две «правды» об одних деньгах.
2. **Выручка школы не трогается.** Её платежи остаются доходом в полном объёме,
   зачёт живёт исключительно на выплатной стороне. Путь «100 % скидка ученику»
   отвергнут именно поэтому, `TeacherPayout.type=advance`/`settled_amount` — как
   искажающий отчётность по авансам.

`mutual_settlements` — снимок на блок/поток. **Фиксация ЗАМОРАЖИВАЕТ числа, а не
пересчитывает:** после `status=fixed` суммы читаются из строки, иначе поздняя
оплата задним числом сдвинула бы уже подписанную цифру. Пересмотр создаёт новую
строку и переводит прежнюю в `superseded` — история полная.

Зачёт в выплате — `TeacherSalaries`, **единственная точка касания зарплатного
контура**. Правка аддитивна: `blockPayoutTotal()` не тронут, вычет сделан по
образцу аванса (взаимозачёт, затем аванс от остатка — аванс это деньги, уже
выданные на руки, и зачитывать их сверх реально остающегося нельзя). Без акта
`afterSettlement === grossTotal`, поэтому существующие ветки считают ровно как
считали; закреплено тестом `payout_without_a_settlement_is_unchanged` и прогоном
зарплатного набора до/после (78 passed / 265 assertions, идентично).
Однократность акта — условным `update` по `payout_id` (`consume()`), а не
проверкой в коде: две параллельные выплаты один акт зачесть не могут.

Гейт страницы `/admin/mutual-settlements` — `RoleGate::accounting()`, **тот же,
что у `TeacherSalaries`**, а не `finance()`: обычный админ на зарплатный контур
не проходит. `settlement:preview` — разовая сверка, только чтение, ни одной
записи.

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

### Marathon Landing Visual Skins (`/online/konsultaciya`)

H1975: [`resources/views/marathon/show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/marathon/show.blade.php)
is a thin shell — it resolves a **visual** variant key (`a`/`b`/`c`/`d`) via
`App\Support\MarathonVisual::variantKey()` and includes
`marathon.skins.{key}.content`. This axis is independent of
`MarathonLandingCopy`'s **copy** variant (`MARATHON_LANDING_COPY_VARIANT`) — one
config controls tokens/layout, the other controls text; do not conflate them.
`MARATHON_LANDING_VISUAL_VARIANT` (env, default `b`) picks the durable variant;
`?skin=` is a **QA-only override that does not survive the register() POST →
redirect → GET cycle** (documented, not a bug — `MarathonVisual`'s own docblock
says so).

**Adding a new skin:** add the letter to `MarathonVisual::VARIANTS`, create
`resources/views/marathon/skins/{x}/content.blade.php` reading the same `$copy`/
`$days`/`$benefits`/`$faq`/`$testimonial` variables the existing skins already
consume (b/a/c share this contract; keep it), and update the stub in
`show.blade.php`'s fallback list. Each skin's own `better-interface full`
accessibility/contrast pass lives alongside the mockup in
[`marketing/marathon-2026-08/redesign/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/marketing/marathon-2026-08/redesign)
(`BETTER_INTERFACE_PASS_{A,B,C,D}_*.md`) — treat contrast pairs as **computed,
not assumed** (WCAG relative-luminance formula), same discipline all four
passes used. As of 31-07-2026: B (light island, default), A (dark-native), C
(warm paper), D (stepped Alpine wizard, H1978 — the one skin whose `quiz_goal`
field is radio cards rather than the others' `<select>`, matching its own
mockup; same field name/values) all shipped. Multi-direction is a deliberate
policy (H1966) — no single-winner pick, ship concurrent variants.

### Vendored reading packs (`resources/data/cohort_start_chteniya/`)

H2110. `/dvaram/reading{,/slug}` рендерит пакеты чтения для когорты «Старт чтения»
через `ReadingPackController`. Гейт двойной и **оба условия обязательны**:
`features.kosha_reader` (env `KOSHA_READER`, по умолчанию `false`) **И**
`StartChteniyaCohort::hasEntitlement($user)` — не купивший когорту получает 404,
а не редирект. Публичный `/reading/kosha-demo` живёт отдельно и этой правкой не
затронут.

**Данные пакета — замороженная КОПИЯ, а не источник.** `hitopadesa-0.json` +
`MANIFEST.json` вендорятся из kosha-датасета `cohort-start-chteniya-pack-freeze`
(H2109) скриптом
[`scripts/vendor_cohort_start_chteniya_packs.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_cohort_start_chteniya_packs.py),
который сверяет sha256 и размер **до** записи. Правило синхронизации: правится
пакет — правится он в [kosha](https://github.com/gasyoun/kosha), затем
перевендоривается скриптом; **руками файлы под `resources/data/cohort_start_chteniya/`
не редактируются никогда** — ручная правка расходится с манифестом молча, и
следующий прогон скрипта её затрёт. Второй пакет (`subhashita-beginner`)
сознательно не импортирован: его схема другая, и импорт «заодно» ввёл бы вторую
схему в кодовую базу.

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
- **Поднимайте свежий worktree через [`scripts/worktree_bootstrap.ps1`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/worktree_bootstrap.ps1), а не через `composer install` руками** (H1929). Полный `composer install` в новом дереве идёт **~11 минут** — измерено 30-07-2026 на правке, которая сама стоила сорока. Скрипт делает **физическую копию** `vendor/` из главного дерева (robocopy, ~60 с) и только при совпадении `composer.lock`; при расхождении честно зовёт `composer install`. Физическая копия безопасна там, где junction смертелен (см. следующий пункт): пути в `vendor/composer/autoload_*.php` считаются от `__DIR__` в момент выполнения, поэтому копия указывает на СВОЙ worktree — скрипт это ещё и проверяет отдельным пробником и падает, если автозагрузчик резолвится в чужое дерево. Он же ставит `.env` + `APP_KEY`, а `-Teardown` сносит worktree и доводит удаление до конца, когда Windows держит хэндл и `git worktree remove` оставляет осиротевший каталог.
- **Ритм тестов: `--filter` на итерации, полный набор ОДИН раз в конце** (H1929). `php artisan test` целиком — ~11 минут (2478 тестов); гонять его после каждой правки означает потерять час на пустом месте. На итерации `php artisan test --filter=<Набор>` (секунды), полный прогон — один раз перед PR и лучше в фоне; `./vendor/bin/pint --test` быстр всегда.
- **Never junction/symlink a worktree's `vendor/` to another worktree's (or the main tree's) `vendor/` to skip `composer install`.** On Windows, PHP's `__DIR__`/realpath resolution for a file accessed through an NTFS junction resolves to the junction's real physical target, not the path used to reach it — so Composer's `$baseDir` computation inside `vendor/composer/autoload_*.php` always resolves to wherever `vendor/` physically lives, never the worktree accessing it through the junction. With `"optimize-autoloader": true` in `composer.json` (this repo's default), that silently makes the whole worktree run **the vendor's owning tree's `app/` code** instead of its own — a green `php artisan test` run proves nothing about the code actually being edited. Confirmed twice in practice (25/26-07-2026, [Systema-Sanscriticum#713](https://github.com/gasyoun/Systema-Sanscriticum/issues/713)): two independent sessions each hit this, then "fixed" it locally by hand-baking their own worktree's name into the shared classmap header, silently breaking every other consumer of that vendor. Run an independent `composer install` per worktree instead. If a worktree already has a `vendor/` junction (check with `Get-Item vendor | Select LinkType,Target` in PowerShell — `LinkType` shows `Junction`), remove it (`cmd /c rmdir <path>\vendor`, not `rm -rf`) and reinstall before trusting any test output from it.

- **`preg_split('/\R/', ...)` без модификатора `/u` РЕЖЕТ русский текст посреди буквы** (H1914). `\R` в не-UTF-режиме матчит сырой байт `0x85`, а он встречается ВТОРЫМ БАЙТОМ внутри UTF-8-последовательностей кириллицы — например `х` (U+0445) кодируется как `D1 85`. В репозитории, где почти все комментарии и почти все конфиги русские, это не экзотика, а ловушка по умолчанию: разбор `scripts/server_guards.conf` разваливался на строке с комментарием. Пишите `preg_split('/\r\n|\n|\r/', ...)` — либо добавляйте `/u` осознанно.

## Operational hazard notes

Destructive-risk facts for this repo (do-not-rerun scripts, decoys, traps) are
registered centrally in an org-private hub
([Uprava DANGER_FACTS.md](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md),
org members only); the public-safe subset is mirrored in the generated block of
[AGENTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AGENTS.md). Check them
before running anything that writes.
