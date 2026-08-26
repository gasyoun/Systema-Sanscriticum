# CLAUDE.md

_Created: 07-05-2026 · Last updated: 21-08-2026_

**Systema-Sanscriticum** is the Laravel LMS for [samskrte.ru](https://samskrte.ru)
(cabinet, shop, homework, finance, Telegram/VK bots). Org spine still applies;
this file is only repo-local always-on. Do **not** read it end-to-end — open
the section that matches the task.

## Stack

Laravel 12 / PHP 8.3 · Vite 8 + Tailwind 4 · Filament v3 (`/admin`, `/editor`) ·
Horizon/Redis · MySQL prod, SQLite tests · Sail for Docker.

## Watcher (always-on)

An external watcher **reverts uncommitted working-tree changes**. HEAD survives.
Use [`/watcher-safe-commit`](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md):
author outside the tree, land+commit in **one** shell invocation, verify vs HEAD.
Layer-1 hook auto-commits Write/Edit; shell/`cp` writes are **not** covered.
Never `git worktree remove` / `git branch -D` a worktree without
`git status --short` inside it first (H2535: untracked files have no reflog).

## Money contour (always-on)

Revenue/access diffs (Tochka/PayPal webhooks, tariffs, `Payment::grantAccess()`,
refunds) go through [`/money-pr-land`](https://github.com/gasyoun/claude-config/blob/main/commands/money-pr-land.md):
worktree off `origin/main`, feature flag **default OFF**, watcher-safe commit,
money/access tests mandatory. The PR-body marker `money-contour: no-auto-merge`
is a **flag/test reminder, not a merge ban** — `gasyoun/*` PRs merge without
reasking. Prod flag flip stays a separate ops step.

There is **no manual group assignment**. `PaymentObserver` →
`Payment::grantAccess()` adds the user to the course `Group`. Tariff keys:
`full`, `block_N`, `block_N_hH` (half). `Tariff::accessKey()` /
`Lesson::unlockingKeys()` / `Lesson::isUnlockedBy()` are the single source of
truth. Thresholds live in `config/receivables.php`, `config/profit_funds.php`,
`config/conversion.php`, `config/investment.php` — **never hardcode**.
Installment policy is a finance-lead decision (Алохомора anti-case). Rhythm:
[docs/FINANCE_REVIEW_RHYTHM.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md).

**Never grant homework review via `course_teacher`** — that pivot feeds
`TeacherSalaryService` and would pay the reviewer. Use `group_reviewer`
(`users.id`). Settlement arithmetic lives only in `MutualSettlementService`;
school revenue is untouched; settlement is deducted **before** payout.
Architecture:
[docs/ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md).

## Deploy / soft-alert (always-on)

Only [`deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh)
— never a hand `git pull` on prod. Ritual and dirty-gate:
[docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md).
OOM/cron guards:
[docs/server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md).
Soft TG «Кабинет: soft-сбой» + `auto_deploy.disabled` / tracked dirty ≠ cabinet
down. Humans: code via PR → `main` → auto-deploy; testimonials via
env/MarketingSetting; PDFs in `public/docs/*.pdf`. **Do not** edit tracked
`app/`/`config/` on the VPS. Playbook (safe/never-auto + incident log):
[docs/SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md).
Safe auto: `php artisan ops:soft-remediate` (origin-equal dirty only; never
blind fuse clear). Webhook skeleton (OFF until env):
[docs/ops/SOFT_ALERT_WEBHOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ops/SOFT_ALERT_WEBHOOK.md).
Uptime inventory:
[docs/UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md)
(EN agents) ·
[docs/UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md)
(RU humans).

## Teacher surfaces (H3219)

Admin-like staff see every teacher surface (`RoleGate::seesTeacherSurfaces()`).
To sit in a named teacher's seat (Kostina, …) use impersonation `MODE_TEACHER`
— super_admin only, flag `STAFF_IMPERSONATION`. A teacher with a card sees
**own** salary (`seesOwnSalary()`, «Моя зарплата»); school-wide payroll stays
`accounting()`. Policy:
[docs/STANDING_POLICY_ADMIN_SEES_TEACHER_SURFACES_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STANDING_POLICY_ADMIN_SEES_TEACHER_SURFACES_2026.md).

## CRM homework-pause

Student «ДЗ + больничный/застой/догоню» → **append** a dated line to
`users.note`. Do not invent a `HomeworkSubmission` status. Product + agent rule:
[docs/CRM_HOMEWORK_PAUSE_NOTE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CRM_HOMEWORK_PAUSE_NOTE_2026.md).

## Editorial style (RU copy)

Student-facing Russian follows
[Uprava/docs/SAMSKRTE_SAMSKRTAM_EDITORIAL_STYLE_GUIDE_2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/SAMSKRTE_SAMSKRTAM_EDITORIAL_STYLE_GUIDE_2026.md).
Proof figures only from
[`config/trust.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/trust.php).
Legal copy (`dpo/ip-gasuns/CONVENTIONS.md`) wins over the guide.

## Commands

```bash
npm run dev / npm run build
php artisan serve | migrate | migrate:fresh --seed
php artisan test [--filter=TestName]
./vendor/bin/pint
php artisan horizon
```

Iterate with `--filter`; full suite (~11 min) once before the PR. Pin time in
tests via `Carbon::setTestNow` — absolute-date fixtures vs `now()` filters are
time bombs (H2541).

## Architecture (routing)

| Area | Always-on fact | Essay |
|---|---|---|
| Filament | `AdminPanelProvider` (`is_admin`) vs `LectureEditorPanelProvider` (`is_lecture_editor`). Resources in `app/Filament/Resources/` + `app/Filament/Editor/`. | — |
| Access | Payment-driven groups; tariff keys above. | this file, Money |
| Finance screens | `RoleGate::finance()` pages; never hardcode thresholds. Manager scoreboard is a **separate** page (`manager_sales_report` OFF); forecast is a third page (`crm_sales_forecast` OFF). | [FINANCE_REVIEW_RHYTHM.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md) · [GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md) · [CRM_SALES_FORECAST_METHODOLOGY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CRM_SALES_FORECAST_METHODOLOGY_2026.md) |
| Groups | `forming`/`active`/`archived`; curator flips status; `groups:notify-forming-shortfall`. Preferences already live on `WaitlistEntry` — no new table. | — |
| Reviewers / settlement | `group_reviewer` ≠ `course_teacher`. Settlement before payout. | architecture doc above |
| Витрина курса (`CourseCadence`) | Курс может идти **несколькими потоками** одной программы — [`CourseCadence`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/CourseCadence.php) группирует расписание по `schedules.group_id`. **Никогда не суммируй потоки:** `total()`/`hours()` берут длиннейший поток, `progressLabel()` при нескольких потоках возвращает `null`, потоки называются построчно через `streamLines()`. Пин: [`CourseCadenceMultiStreamTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Shop/CourseCadenceMultiStreamTest.php). | — |
| Reading packs | Two frozen copies under `resources/data/` — **never hand-edit**; re-vendor from [kosha](https://github.com/gasyoun/kosha) via [`vendor_cohort_start_chteniya_packs.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_cohort_start_chteniya_packs.py) (`cohort_start_chteniya/`) and [`vendor_nala_subhashita_packs.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_nala_subhashita_packs.py) (`nala_subhashita/`). **Two gates, do not mix:** «Старт чтения» = `features.kosha_reader` **AND** `StartChteniyaCohort::hasEntitlement`; per-course `/dvaram/reading/kurs/{course}` = `CourseCohortEntitlement::hasEntitlement($user, $course)` alone (each course's own flag, default OFF) — it must never gain a `kosha_reader` dependency, or the demo route stops being independently switchable. `subhashita-beginner` is normalised to `reading_pack_v1` at READ time by [`SubhashitaReadingPackAdapter`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/SubhashitaReadingPackAdapter.php); never write a normalised copy to disk (the sha256 pin check dies). SRS import/button: «Старт чтения» only; private per-student deck; client sends **positions only**; deck definition is only [`StartChteniyaSrsDeck.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/StartChteniyaSrsDeck.php). | — |
| Marathon `/online/konsultaciya` | Visual variant (`MARATHON_LANDING_VISUAL_VARIANT`) ≠ copy variant. `?skin=` is QA-only. | — |
| Очереди / тяжёлая работа после коммита | Ничего тяжёлого на пути запроса: сборка идёт job'ой (`BuildHomeworkImagesPdfJob`, очередь `imports` на `redis-long`). **Конструктор `ShouldQueue`-Mailable выполняется В ЗАПРОСЕ** — откладывается только `handle()`/`attachments()`; не считать в `__construct()` ничего, что читает диск или БД. `dispatch()` под драйвером `sync` выполняет job инлайн → обязательную работу при постановке оборачивать в `try/catch`. Регрессию писать на `sync`, не `Queue::fake()` (тот job не выполняет и зеленеет без страховки). | [DECISION_HOMEWORK_IMAGES_PDF_OFF_REQUEST_PATH_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DECISION_HOMEWORK_IMAGES_PDF_OFF_REQUEST_PATH_2026.md) |
| Other | `LandingPage` catch-all `/{slug}`. Lecture-builder is a sidecar HTTP client. Activity: middleware + `ActivityEvent` + `LessonView` heartbeat. | — |

Schema is derivable from migrations; the Money section above carries the
always-on invariants.

## External integrations

- Tochka `/api/webhooks/tochka` · Telegram `/api/telegram/webhook` · VK `/api/vk-webhook` · DomPDF certificates.
- Lead-magnet bots: `/api/webhooks/telegram-magnet`, `/vk-magnet`, `/max-magnet/{secret}` (secret **in the path** — rotate in `MarketingSetting` after any log leak, then `php artisan max:set-magnet-webhook`). Secrets use Eloquent `encrypted` cast.
- **Новая точка отправки в Telegram** (`sendMessage`/`sendPhoto`/…) обязана идти через клейм [App\Support\TelegramSendGuard](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/TelegramSendGuard.php) ДО вызова API — иначе ретрай джоба после потерянного ответа Telegram даёт вторую идентичную копию (инцидент 24-08-2026, чат гр.61). Контракт: claim → подавлено = тихо вернуть успех; детерминированный отказ Telegram (4xx/5xx) = release + rethrow (ретрай уместен); транспортный сбой без ответа = клейм держится, ретрай подавлен (at-most-once); Redis недоступен = fail-open с громким warning. Входящие Telegram-апдейты перед обработкой дедупятся по `update_id`: `TelegramSendGuard::claimUpdate(scope, update_id)` (вебхук-ределивери и повторный приём поллером). Эталонные тесты: `SendZapisiBotMessageJobTest`, `TelegramDedupWave2Test`.

- **n8n ZOOM 1.4 доставки записей** (workflows 1EIqqNzMl5NNIxST): DOWNLOAD качает только свежий signed URL из мета-ноды «Свежая ссылка записи» с Bearer (zoomOAuth2Api) — webhook-download_token живёт ≤24ч; чистящие узлы удаляют строго …/executions/{{ \.id }}*, глобальные xecutions/* rm запрещены (26-08 стёрли бинарник параллельного исполнения); теневая копия MP4 в Drive курса стоит до загрузок и не блокируется. Правя трубу через API PUT — бэкап JSON в /root/wf_backup_pre_patch_* перед каждым PUT.

## Environment / worktrees

- Timezone `Europe/Moscow`. Flags in `config/features.php`. HTTPS forced in production.
- **A new `env()` key in `config/*.php` ⇒ regenerate the inventory in the SAME pass:** `php scripts/generate_env_inventory.php` (standalone script, no Laravel bootstrap, writes [`docs/ENVIRONMENT_VARIABLES.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ENVIRONMENT_VARIABLES.md)). The `Environment inventory` CI gate runs the same script with `--check` and **reddens `main`** when the two disagree — nothing in the PR that adds the key warns you. On 25-08-2026 six `BANK_*` keys from [#2088](https://github.com/gasyoun/Systema-Sanscriticum/pull/2088) left `main` red for **2 h 50 min while four commits landed on top of it**; fix was one command ([#2093](https://github.com/gasyoun/Systema-Sanscriticum/pull/2093)).
- Composer pins PHP 8.3 + Unix `pcntl`/`posix`; `platform-check=false` so Windows resolves the lock. Do not drop `composer check-platform-reqs` (CI + `deploy.sh --no-dev`).
- **New worktree:** [`scripts/worktree_bootstrap.ps1`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/worktree_bootstrap.ps1) (robocopy `vendor/`, ~60s). Never junction/symlink `vendor/` — PHP `__DIR__` resolves to the physical target and the worktree silently runs another tree's `app/` ([#713](https://github.com/gasyoun/Systema-Sanscriticum/issues/713)).
- This repo tracks [`CHANGELOG.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CHANGELOG.md) — **renamed from lowercase `changelog.md` to uppercase on 24-08-2026**. On Windows (`core.ignorecase=true`) `git add` with the wrong case is a silent no-op either way — take the case from `git ls-files`, then verify with `git diff --cached --name-only` ([Uprava FINDINGS §348](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md)).
- `preg_split('/\R/', ...)` without `/u` splits inside Cyrillic (`х` = `D1 85`). Use `preg_split('/\r\n|\n|\r/', ...)` (H1914).

## Operational hazards

Destructive-risk facts: [Uprava DANGER_FACTS.md](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md)
(org-private). Public-safe subset is in the generated block of
[AGENTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AGENTS.md).
Check them before anything that writes.

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues via the `gh` CLI; PRs are NOT a triage
surface (intake OFF). See `docs/agents/issue-tracker.md`.

### Triage labels

Default five-role vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`,
`ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context layout (root `CONTEXT.md` + `docs/adr/`, created lazily —
proceed silently when absent). See `docs/agents/domain.md`.

_Dr. Mārcis Gasūns_
