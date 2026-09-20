# CLAUDE.md

_Created: 07-05-2026 · Last updated: 20-09-2026 (H5176 SLA slim: duplicates removed; invariants + pins kept)_

**Systema-Sanscriticum** is the Laravel LMS for [samskrte.ru](https://samskrte.ru)
(cabinet, shop, homework, finance, Telegram/VK bots). Org spine applies; this
file is repo-local always-on only — open the section matching the task, don't
read end-to-end.

## Stack

Laravel 12/PHP 8.3 · Vite 8+Tailwind 4 · Filament v3 (`/admin`, `/editor`) ·
Horizon/Redis · MySQL prod, SQLite tests · Sail.

## Watcher (always-on)

An external watcher **reverts uncommitted working-tree changes** (HEAD
survives). Use `/watcher-safe-commit`: author outside the tree, land+commit in
**one** shell invocation, verify vs HEAD. Layer-1 hook auto-commits Write/Edit;
shell/`cp` writes aren't covered. Never `git worktree remove`/`branch -D`
without `git status --short` first.

## Money contour (always-on)

Revenue/access diffs (Tochka/PayPal webhooks, tariffs, `Payment::grantAccess()`,
refunds) go through [`/money-pr-land`](https://github.com/gasyoun/claude-config/blob/main/commands/money-pr-land.md):
worktree off `origin/main`, flag **default OFF**, money/access tests mandatory.
`money-contour: no-auto-merge` is a **reminder, not a merge ban**.

**`tariff.is_active` gates BUYING, not access.** `/checkout/{tariff}` binds the
**Tariff**, not `Course.is_visible` — a hidden course can still sell via direct
curator link, a **curator-gated sale** defined by STATE (`is_visible=false`+
`is_active`+≥1 active tariff). Hidden ⇏ unsellable — never
deactivate/hide/retire/delete on that inference. Audits:
`CatalogFamilyAudit::CLASS_CURATOR_GATED_SALE`,
`CatalogShellAudit::isCuratorGatedSale()`; pin:
[`CuratorGatedHiddenSaleTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Catalog/CuratorGatedHiddenSaleTest.php).

There is **no manual group assignment** — `PaymentObserver` →
`Payment::grantAccess()` adds the user to the course `Group`. Tariff keys:
`full`, `block_N`, `block_N_hH`; `Tariff::accessKey()` / `Lesson::unlockingKeys()`
/ `Lesson::isUnlockedBy()` are the single source of truth. Thresholds live in
`config/{receivables,profit_funds,conversion,investment}.php` — **never
hardcode**. Installment policy is a finance-lead decision. Rhythm:
[FINANCE_REVIEW_RHYTHM.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md).

**Курс-запись с нулём уроков чинится уроками, а не выдачей чужого доступа**
(доступ считается ПО КУРСУ, `block_N` из `lessons.block_number`). Лекарство —
[`catalog:mirror-recording-lessons`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MirrorRecordingLessons.php)
(H3823): пишет только в `lessons`, сухой прогон обязателен и обязан совпасть до
`--apply`, идемпотентна по `(block_number, block_half, sort_order)`. Запрещено
выдавать доступ к урокам курса B купившим курс A — только `/money-pr-land`.
Тарифы и видимость не трогать (инцидент 31-08-2026). Пин:
[`MirrorRecordingLessonsTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Catalog/MirrorRecordingLessonsTest.php).
Меняется `MirrorRecordingLessons::CARRIED` ⇒ тот же PR обновляет этот абзац и README.

**Never grant homework review via `course_teacher`** — feeds
`TeacherSalaryService`, pays the reviewer. Use `group_reviewer` (`users.id`).
Settlement arithmetic only in `MutualSettlementService`, deducted **before**
payout. [ARCHITECTURE…SETTLEMENT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md).

## Deploy / soft-alert (always-on)

Only [`deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh)
— never a hand `git pull` on prod. Ritual+gate: [deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md);
guards+playbook: [server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md) ·
[SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md).
Soft TG «Кабинет: soft-сбой» + `auto_deploy.disabled`/tracked dirty ≠ cabinet
down. **Never** edit tracked `app/`/`config/` on the VPS. Safe auto:
`php artisan ops:soft-remediate` (origin-equal dirty only). Webhook (OFF until
env): [SOFT_ALERT_WEBHOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ops/SOFT_ALERT_WEBHOOK.md).
Uptime: [EN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md)/[RU](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md).

## Teacher surfaces (H3219)

Admin-like staff see every teacher surface (`RoleGate::seesTeacherSurfaces()`).
Impersonation `MODE_TEACHER` — super_admin only, flag `STAFF_IMPERSONATION`.
Card holder sees **own** salary; school-wide payroll stays `accounting()`.
Policy: [ADMIN_SEES_TEACHER_SURFACES_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STANDING_POLICY_ADMIN_SEES_TEACHER_SURFACES_2026.md).

## CRM homework-pause

Student «ДЗ + больничный/застой/догоню» → **append** a dated line to
`users.note`. Do not invent a `HomeworkSubmission` status. Agent standing (A):
needs identity **and** homework cue ∧ life cue; «догоню» alone is not a pause
note; append only, one dated line with «не давить». Rule:
[docs/CRM_HOMEWORK_PAUSE_NOTE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CRM_HOMEWORK_PAUSE_NOTE_2026.md).

## Staff instructions (MG 18-08-2026)

A working instruction for a named employee lives **inside the interface they
work in**: a Filament page under the same `RoleGate`, lists from live queries.
Never in a public issue/repo/README (surnames next to payouts = personal +
commercial data). Task for a person → GTD row in
[Uprava](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md).
Engineering provenance may go to the repo — **anonymise people**; screenshots
with live data → private hub only. Pre-H3084 history stays as is (MG 19-08) —
[decision record](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_systema-public-history-staff-data.md).

## Editorial style (RU copy)

Student-facing Russian follows [EDITORIAL_STYLE_GUIDE_2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/SAMSKRTE_SAMSKRTAM_EDITORIAL_STYLE_GUIDE_2026.md);
proof figures from `config/trust.php`; legal copy wins.

## Commands

```bash
npm run dev / npm run build
php artisan serve | migrate | migrate:fresh --seed
php artisan test [--filter=TestName]
./vendor/bin/pint
php artisan horizon
```

Iterate with `--filter`; full suite (~11 min) before the PR. Pin time via
`Carbon::setTestNow` — absolute-date fixtures vs `now()` = time bombs.

## Architecture (routing)

| Area | Always-on fact | Essay |
|---|---|---|
| Filament | `AdminPanelProvider`(`is_admin`) vs `LectureEditorPanelProvider`(`is_lecture_editor`); `Filament/Resources/`+`Editor/`. | — |
| Access | Payment-driven groups; tariff keys above | Money |
| Finance screens | `RoleGate::finance()` pages; never hardcode thresholds. Scoreboard/forecast flags OFF, **separate** pages. | [RHYTHM](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md) · [FORECAST](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CRM_SALES_FORECAST_METHODOLOGY_2026.md) |
| Groups | `forming`/`active`/`archived`; curator flips status; `groups:notify-forming-shortfall`. | — |
| Reviewers | `group_reviewer` ≠ `course_teacher`; settlement before payout | Money above |
| Витрина курса (`CourseCadence`) | groups multi-stream courses by `schedules.group_id`. **Never sum streams:** `total()`/`hours()` take longest; `progressLabel()` `null` with >1 stream. Pin: [CourseCadenceMultiStreamTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Shop/CourseCadenceMultiStreamTest.php). | — |
| Reading packs | Frozen under `resources/data/` — never hand-edit; re-vendor via [vendor_cohort](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_cohort_start_chteniya_packs.py)/[vendor_nala](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_nala_subhashita_packs.py). «Старт чтения»=`kosha_reader` **AND** `hasEntitlement`; per-course route=`CourseCohortEntitlement` alone, OFF. | — |
| Queues | Nothing heavy on the request path — builds run as jobs (`imports`, `redis-long`). A `ShouldQueue`-Mailable's **constructor runs IN the request** — only `handle()`/`attachments()` defer. `sync` runs inline → `try/catch`; test on `sync`. | [DECISION_HOMEWORK_PDF_OFF_PATH](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DECISION_HOMEWORK_IMAGES_PDF_OFF_REQUEST_PATH_2026.md) |
| Other | Marathon `/online/konsultaciya`: `MARATHON_LANDING_VISUAL_VARIANT`≠copy, `?skin=` QA-only. `LandingPage` catch-all `/{slug}`. Activity = middleware+heartbeat. | — |

Schema derives from migrations; Money above carries the invariants.

## External integrations

- Tochka `/api/webhooks/tochka` · Telegram `/api/telegram/webhook` · VK `/api/vk-webhook` · DomPDF.
- Lead-magnet bots: `/api/webhooks/telegram-magnet`, `/vk-magnet`, `/max-magnet/{secret}` (secret **in the path** — rotate in `MarketingSetting` after a leak). Secrets use Eloquent `encrypted`.
- New Telegram send points claim via [TelegramSendGuard](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/TelegramSendGuard.php) before the API call — unclaimed retry after a lost response duplicates the send. Dedupe: `update_id` via `claimUpdate()`.
- n8n ZOOM 1.4: DOWNLOAD only via the fresh signed URL (≤24h); cleanup deletes only `…/executions/{{ \.id }}*`, never a global rm. API-PUT: back up JSON first.

## Environment / worktrees

- TZ `Europe/Moscow`; flags `config/features.php`; HTTPS forced.
- **New `env()` key ⇒ regen inventory same pass:** `php scripts/generate_env_inventory.php`; CI `--check` **silently reddens `main`** on drift ([#2088](https://github.com/gasyoun/Systema-Sanscriticum/pull/2088)).
- **New worktree:** [worktree_bootstrap.ps1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/worktree_bootstrap.ps1) (robocopy `vendor/`). Never junction/symlink `vendor/` ([#713](https://github.com/gasyoun/Systema-Sanscriticum/issues/713))
- Wrong-case `git add` is a no-op on Windows — take case from `git ls-files` ([FINDINGS §348](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md)).
- `preg_split('/\R/',...)` w/o `/u` splits Cyrillic — use `/\r\n|\n|\r/`.

## Operational hazards

Destructive-risk facts: [Uprava DANGER_FACTS.md](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md)
(org-private); public-safe subset in [AGENTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AGENTS.md)'s generated block.

## Agent skills

- **Issue tracker:** GitHub Issues via `gh`; PRs NOT triage. `docs/agents/issue-tracker.md`.
- **Triage labels:** `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. `docs/agents/triage-labels.md`.
- **Domain docs:** root `CONTEXT.md`+`docs/adr/`, lazy. `docs/agents/domain.md`.

## Memory store

Committed memory store at [`.claude/projects/Systema-Sanscriticum/memory/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/.claude/projects/Systema-Sanscriticum/memory)
— dangerous/durable facts go there, indexed in its `MEMORY.md` (H4547, `/danger-memory`).

_Dr. Mārcis Gasūns_
