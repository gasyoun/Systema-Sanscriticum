# CLAUDE.md

_Created: 07-05-2026 · Last updated: 16-09-2026_

**Systema-Sanscriticum** is the Laravel LMS for [samskrte.ru](https://samskrte.ru)
(cabinet, shop, homework, finance, Telegram/VK bots). Org spine applies; this
file is repo-local always-on only — open the section matching the task, don't
read end-to-end.

## Stack

Laravel 12/PHP 8.3 · Vite 8+Tailwind 4 · Filament v3 (`/admin`, `/editor`) ·
Horizon/Redis · MySQL prod, SQLite tests · Sail.

## Watcher (always-on)

An external watcher **reverts uncommitted working-tree changes** (HEAD
survives). Use [`/watcher-safe-commit`](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md):
author outside the tree, land+commit in **one** shell invocation, verify vs
HEAD. Layer-1 hook auto-commits Write/Edit; shell/`cp` writes aren't covered.
Never `git worktree remove`/`branch -D` without `git status --short` first
(untracked files: no reflog).

## Money contour (always-on)

Revenue/access diffs (Tochka/PayPal webhooks, tariffs, `Payment::grantAccess()`,
refunds) go through [`/money-pr-land`](https://github.com/gasyoun/claude-config/blob/main/commands/money-pr-land.md):
worktree off `origin/main`, feature flag **default OFF**, watcher-safe commit,
money/access tests mandatory. PR marker `money-contour: no-auto-merge` is a
**reminder, not a merge ban** — `gasyoun/*` PRs merge without reasking; prod
flag flip is separate.

**`tariff.is_active` gates BUYING, not access.** `/checkout/{tariff}` binds the
**Tariff**, not `Course.is_visible` — a hidden course can still sell via direct
curator link, a **curator-gated sale** defined by STATE (`is_visible=false`+
`is_active`+≥1 active tariff), never by course id. Hidden ⇏ unsellable — never
deactivate/hide/retire/delete on that inference. Audits:
`CatalogFamilyAudit::CLASS_CURATOR_GATED_SALE`,
`CatalogShellAudit::isCuratorGatedSale()`; pin:
[`CuratorGatedHiddenSaleTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Catalog/CuratorGatedHiddenSaleTest.php).
H3812/H3820: 8 green tests missed a break covering only ACCESS, not SALE —
[#2291](https://github.com/gasyoun/Systema-Sanscriticum/pull/2291) reverted it.

No manual group assignment — `PaymentObserver`→`Payment::grantAccess()` adds
the user to the course `Group`. Tariff keys: `full`, `block_N`, `block_N_hH`
(half); `Tariff::accessKey()`/`unlockingKeys()`/`isUnlockedBy()` are the
single source of truth. Thresholds in
`config/{receivables,profit_funds,conversion,investment}.php` — **never
hardcode**. Installment policy is a finance-lead decision. Rhythm:
[FINANCE_REVIEW_RHYTHM.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md).
**Курс-запись с нулём уроков чинится уроками, а не выдачей чужого доступа.** Тот же
курс 327 продан 129 раз и не имеет ни одного урока: доступ считается ПО КУРСУ
(`Lesson::unlockingKeys()` выводит `block_N` из `lessons.block_number`), поэтому пока у
курса нет своих уроков, купивший не получает ничего. Санкционированное лекарство —
[`catalog:mirror-recording-lessons {source} {target}`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MirrorRecordingLessons.php)
(H3823): пишет ТОЛЬКО в `lessons`, по умолчанию сухой прогон, идемпотентна по слоту
`(block_number, block_half, sort_order)`, отказывается работать, если у цели нет блока,
который есть у источника. **Сухой прогон обязателен и его вывод обязан совпасть с
ожиданием до `--apply`.** Запрещённая альтернатива: выдавать купившим курс A доступ к
урокам курса B — это правка money/access-контура, она идёт только через
[`/money-pr-land`](https://github.com/gasyoun/claude-config/blob/main/commands/money-pr-land.md),
а не «заодно». Тарифы и видимость команда не трогает и трогать не должна (инцидент
31-08-2026 выше). Пин:
[`MirrorRecordingLessonsTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Catalog/MirrorRecordingLessonsTest.php).
**Правило синхронности:** меняется список переносимых полей (`MirrorRecordingLessons::CARRIED`)
⇒ в том же PR обновляются этот абзац и раздел README «Курс-запись».

There is **no manual group assignment**. `PaymentObserver` →
`Payment::grantAccess()` adds the user to the course `Group`. Tariff keys:
`full`, `block_N`, `block_N_hH` (half). `Tariff::accessKey()` /
`Lesson::unlockingKeys()` / `Lesson::isUnlockedBy()` are the single source of
truth. Thresholds live in `config/receivables.php`, `config/profit_funds.php`,
`config/conversion.php`, `config/investment.php` — **never hardcode**.
Installment policy is a finance-lead decision (Алохомора anti-case). Rhythm:
[docs/FINANCE_REVIEW_RHYTHM.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md).

**Never grant homework review via `course_teacher`** — feeds
`TeacherSalaryService`, pays the reviewer. Use `group_reviewer` (`users.id`).
Settlement arithmetic only in `MutualSettlementService`, deducted **before**
payout. [ARCHITECTURE…SETTLEMENT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md).

## Deploy / soft-alert (always-on)

Only [`deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh)
— never a hand `git pull` on prod. Ritual+gate: [deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md);
OOM/cron guards: [server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md).
Soft TG «Кабинет: soft-сбой» + `auto_deploy.disabled`/tracked dirty ≠ cabinet
down. Humans: PR→`main`→auto-deploy; testimonials via env/MarketingSetting,
PDFs in `public/docs/*.pdf`. **Never** edit tracked `app/`/`config/` on the
VPS. Playbook: [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md).
Safe auto: `php artisan ops:soft-remediate` (origin-equal dirty only, never
blind fuse clear). Webhook (OFF until env): [SOFT_ALERT_WEBHOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ops/SOFT_ALERT_WEBHOOK.md).
Uptime: [EN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md)/[RU](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md).

## Teacher surfaces (H3219)

Admin-like staff see every teacher surface (`RoleGate::seesTeacherSurfaces()`).
To sit in a teacher's seat use impersonation `MODE_TEACHER` — super_admin
only, flag `STAFF_IMPERSONATION`. Card holder sees **own** salary
(`seesOwnSalary()`); school-wide payroll stays `accounting()`. Policy:
[ADMIN_SEES_TEACHER_SURFACES_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STANDING_POLICY_ADMIN_SEES_TEACHER_SURFACES_2026.md).

## CRM homework-pause

Student «ДЗ + больничный/застой/догоню» → **append** a dated line to
`users.note`. Do not invent a `HomeworkSubmission` status. Product + agent rule:
[docs/CRM_HOMEWORK_PAUSE_NOTE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CRM_HOMEWORK_PAUSE_NOTE_2026.md).
Agent standing (A): needs student identity **and** a homework cue ∧ life cue; «догоню» alone (group
transfer, schedule) is not a pause note; append only, one dated line with «не давить»; if identity is
missing ask for one identifier, never for permission to write. (Moved here from the always-loaded
claude-config `rules/crm-homework-pause-note.md`, 26-08-2026.)

## Staff instructions (MG 18-08-2026)

A working instruction for a named employee — accountant, curator, teacher, manager (checklist,
«вот что тебе нужно сделать», rows to verify) — lives **inside the interface they work in**: a Filament
page next to the working screen under the same `RoleGate`, lists built from live queries, a button from the
working screen to it. Never in a public issue, a public repo or a README: (1) a surname next to a payout
sum is personal + commercial data; (2) a copied list is stale after the first confirmation; (3) a person
who opens `/admin` twice a year will not follow a GitHub link.

- Task for a person → a GTD row in the private hub ([Uprava/GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md)), not a public issue.
- Engineering provenance (acceptance, measurement, decision) may go to the repo — **check visibility and
  anonymise people**: `gh repo view gasyoun/Systema-Sanscriticum --json visibility --jq .visibility` → `PUBLIC`.
- Admin screenshots with live data → private hub only; publicly at most from an anonymised fixture.
- Fail: памятка бухгалтеру с ФИО преподавателя в публичном репо · «задача для Марии» публичной issue.
  Pass: страница «Как размечать выплаты» в `/admin` под `RoleGate::finance()` · GTD-строка в Uprava.

The rule looks **forward** only: pre-H3084 history stays as is (MG 19-08-2026) — decision record
[Uprava/docs/DECISIONS_systema-public-history-staff-data.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_systema-public-history-staff-data.md); do not re-open it.

## Editorial style (RU copy)

Student-facing Russian follows [EDITORIAL_STYLE_GUIDE_2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/SAMSKRTE_SAMSKRTAM_EDITORIAL_STYLE_GUIDE_2026.md).
Proof figures from [`config/trust.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/trust.php);
legal copy (`dpo/ip-gasuns/CONVENTIONS.md`) wins.

## Commands

```bash
npm run dev / npm run build
php artisan serve | migrate | migrate:fresh --seed
php artisan test [--filter=TestName]
./vendor/bin/pint
php artisan horizon
```

Iterate with `--filter`; full suite (~11 min) once before the PR. Pin time via
`Carbon::setTestNow` — absolute-date fixtures vs `now()` = time bombs.

## Architecture (routing)

| Area | Always-on fact | Essay |
|---|---|---|
| Filament | `AdminPanelProvider`(`is_admin`) vs `LectureEditorPanelProvider`(`is_lecture_editor`); `Filament/Resources/`+`Editor/`. | — |
| Access | Payment-driven groups; tariff keys above | Money |
| Finance screens | `RoleGate::finance()` pages; never hardcode thresholds. Scoreboard(`manager_sales_report`)/forecast(`crm_sales_forecast`) flags OFF, **separate** pages. | [RHYTHM](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md) · [GETCOURSE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md) · [FORECAST](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CRM_SALES_FORECAST_METHODOLOGY_2026.md) |
| Groups | `forming`/`active`/`archived`; curator flips status; `groups:notify-forming-shortfall`. Prefs on `WaitlistEntry`. | — |
| Reviewers | `group_reviewer` ≠ `course_teacher`; settlement before payout | Money above |
| Витрина курса (`CourseCadence`) | [CourseCadence](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/CourseCadence.php) groups multi-stream courses by `schedules.group_id`. **Never sum streams:** `total()`/`hours()` take longest; `progressLabel()` is `null` with >1 stream; list via `streamLines()`. Pin: [CourseCadenceMultiStreamTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Shop/CourseCadenceMultiStreamTest.php). | — |
| Reading packs | Frozen under `resources/data/` — never hand-edit; re-vendor via [vendor_cohort](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_cohort_start_chteniya_packs.py)/[vendor_nala](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_nala_subhashita_packs.py) from kosha. Don't mix gates: «Старт чтения»=`features.kosha_reader` **AND** `hasEntitlement`; per-course route=`CourseCohortEntitlement` alone, OFF, no `kosha_reader` dep. `subhashita-beginner`→`reading_pack_v1` at READ via [SubhashitaReadingPackAdapter](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/SubhashitaReadingPackAdapter.php), never persisted. SRS: [StartChteniyaSrsDeck](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/StartChteniyaSrsDeck.php); client sends positions only. | — |
| Queues | Nothing heavy on the request path: build runs as a job (`BuildHomeworkImagesPdfJob`, `imports` queue, `redis-long`). A `ShouldQueue`-Mailable's **constructor runs IN the request** — only `handle()`/`attachments()` defer. `sync` runs jobs inline → wrap mandatory work in `try/catch`; test on `sync`, not `Queue::fake()`. | [DECISION_HOMEWORK_PDF_OFF_PATH](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DECISION_HOMEWORK_IMAGES_PDF_OFF_REQUEST_PATH_2026.md) |
| Other | Marathon `/online/konsultaciya`: `MARATHON_LANDING_VISUAL_VARIANT`≠copy, `?skin=` QA-only. `LandingPage` catch-all `/{slug}`. Lecture-builder = sidecar HTTP client. Activity = middleware+`ActivityEvent`/`LessonView` heartbeat. | — |

Schema derives from migrations; Money above carries the invariants.

## External integrations

- Tochka `/api/webhooks/tochka` · Telegram `/api/telegram/webhook` · VK `/api/vk-webhook` · DomPDF.
- Lead-magnet bots: `/api/webhooks/telegram-magnet`, `/vk-magnet`, `/max-magnet/{secret}` (secret **in the path** — rotate in `MarketingSetting` after a leak, `php artisan max:set-magnet-webhook`). Secrets use Eloquent `encrypted`.
- New Telegram send points must claim via [TelegramSendGuard](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/TelegramSendGuard.php) before the API call — unclaimed retry after a lost response duplicates the send. Suppressed→silent success; 4xx/5xx→release+rethrow; no-response failure→claim held, retry suppressed; Redis down→fail-open+warn. Dedupe: `update_id` via `claimUpdate()`. Tests: `SendZapisiBotMessageJobTest`, `TelegramDedupWave2Test`.
- n8n ZOOM 1.4 delivery (workflow 1EIqqNzMl5NNIxST): DOWNLOAD only via the fresh signed URL («Свежая ссылка записи», ≤24h); cleanup deletes only `…/executions/{{ \.id }}*` — never a global `executions/*` rm. API-PUT edits: back up JSON to `/root/wf_backup_pre_patch_*`.

## Environment / worktrees

- TZ `Europe/Moscow`; flags `config/features.php`; HTTPS forced.
- **New `env()` key in `config/*.php` ⇒ regen the inventory same pass:** `php scripts/generate_env_inventory.php` (writes [ENVIRONMENT_VARIABLES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ENVIRONMENT_VARIABLES.md)). CI `--check` **silently reddens `main`** on drift ([#2088](https://github.com/gasyoun/Systema-Sanscriticum/pull/2088)/[#2093](https://github.com/gasyoun/Systema-Sanscriticum/pull/2093)).
- Composer pins PHP 8.3+Unix `pcntl`/`posix`; `platform-check=false` for Windows. Keep `composer check-platform-reqs` (CI+deploy).
- **New worktree:** [worktree_bootstrap.ps1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/worktree_bootstrap.ps1) (robocopy `vendor/`). Never junction/symlink `vendor/` — silently runs another tree's `app/` ([#713](https://github.com/gasyoun/Systema-Sanscriticum/issues/713))
- Tracks [CHANGELOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CHANGELOG.md) (uppercase). Windows `core.ignorecase=true` makes a wrong-case `git add` a no-op — take case from `git ls-files`, verify via `git diff --cached --name-only` ([FINDINGS §348](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md)).
- `preg_split('/\R/',...)` w/o `/u` splits Cyrillic — use `/\r\n|\n|\r/`.

## Operational hazards

Destructive-risk facts: [Uprava DANGER_FACTS.md](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md)
(org-private); public-safe subset in [AGENTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AGENTS.md)'s
generated block. Check before writing anything.

## Agent skills

- **Issue tracker:** GitHub Issues via `gh`; PRs NOT triage. `docs/agents/issue-tracker.md`.
- **Triage labels:** `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. `docs/agents/triage-labels.md`.
- **Domain docs:** root `CONTEXT.md`+`docs/adr/`, lazy. `docs/agents/domain.md`.

## Memory store

This repo keeps a committed memory store at [`.claude/projects/Systema-Sanscriticum/memory/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/.claude/projects/Systema-Sanscriticum/memory) per the org Memory-routing rule ([`/danger-memory`](https://github.com/gasyoun/claude-config/blob/main/commands/danger-memory.md)) — write dangerous/durable facts there and index each in its `MEMORY.md` (H4547).

_Dr. Mārcis Gasūns_
