# REPORT — Systema always-on context slim (H2817)

_Created: 15-08-2026 · Last updated: 15-08-2026_

**Handoff:** [H2817 (Grok 4.6) — Slim Systema CLAUDE.md from 9251 tokens without dropping ops safety](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2817-Grok_Systema-Sanscriticum_claude-md-slim-tier0_15.08.26.md)

**Model:** Grok 4.6 (`grok-4.6`)

**Goal:** `CLAUDE.md` ≤ 3,000 approx. tokens · money / watcher / deploy / soft-remediate strings present · AGENTS.md generated-block regenerated, not hand-edited.

Approximate tokens = `chars / 4`, same rule as [`/claude-md-slim`](https://github.com/gasyoun/claude-config/blob/main/commands/claude-md-slim.md). Gold packet: [Uprava REPORT_UPRAVA_CONTEXT_SLIM_H2694](https://github.com/gasyoun/Uprava/blob/main/docs/REPORT_UPRAVA_CONTEXT_SLIM_H2694_14.08.26.md).

## Before / after token measurements

Measured 15-08-2026 from worktree `Systema-Sanscriticum-h2817-30436` against `origin/main` at `b80bf7a4`.

| Path | Role | Before bytes | Before ~tok | After bytes | After ~tok |
|---|---|---:|---:|---:|---:|
| `CLAUDE.md` | repo always-on (Claude) | 45,135 | 9,251 | 9,624 | 2,370 |
| `AGENTS.md` | repo always-on (Codex) | 6,271 | 1,558 | 6,346 | 1,576 |

First-screen docs the fat file told the agent to open (unchanged; they stay on-demand):

| Path | bytes | ~tok |
|---|---:|---:|
| [docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md) | 18,308 | 3,296 |
| [docs/server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md) | 60,844 | 10,455 |
| [docs/SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) | 13,328 | 3,290 |
| [docs/UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) | 14,718 | 3,604 |
| [docs/UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md) | 9,403 | 1,759 |
| [docs/CRM_HOMEWORK_PAUSE_NOTE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CRM_HOMEWORK_PAUSE_NOTE_2026.md) | 2,326 | 430 |
| [docs/ops/SOFT_ALERT_WEBHOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ops/SOFT_ALERT_WEBHOOK.md) | 4,935 | 1,224 |
| [docs/FINANCE_REVIEW_RHYTHM.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md) | 6,441 | 1,093 |

`CLAUDE.md` 9,251 → 2,370. Saving **6,881** approx. tokens. Ceiling was 3,000.

## Classification ledger (original `CLAUDE.md` sections)

| Original section | Bucket | Disposition |
|---|---|---|
| Generic “guidance to Claude Code” header | routing | Replaced with one-sentence **what this repo is** (Laravel LMS for samskrte.ru) + dated header + byline. |
| Stack | always-on routing | Kept as one line. |
| Ops / uptime — Better Stack EN | procedure | Pointer to [docs/UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md). |
| Ops / uptime — RU humans | procedure | Pointer to [docs/UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md). |
| OS resource guards | always-on routing | Pointer to [docs/server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md). |
| Soft TG + 01-08 incident novel | always-on safety + history | Pointers to [docs/SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) + `ops:soft-remediate`. Novel stays in [docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md) / [Uprava FINDINGS §280](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md). |
| Editorial style ё/вы essay | procedure | Pointer to [Uprava style guide](https://github.com/gasyoun/Uprava/blob/main/docs/SAMSKRTE_SAMSKRTAM_EDITORIAL_STYLE_GUIDE_2026.md). |
| Commands | always-on routing | Kept compact. |
| Dual Filament panels | always-on routing | One table row. |
| Payment-driven access | always-on safety | Kept condensed under Money. |
| Block / tariff keys | always-on safety | Kept (`full` / `block_N` / `block_N_hH`). |
| Receivables & installments | procedure + history | Two lines + [FINANCE_REVIEW_RHYTHM.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FINANCE_REVIEW_RHYTHM.md); Алохомора one clause. |
| Profit funds / Delegation KPI | procedure | Finance-screens table row. |
| Order→payment conversion | procedure | Pointers to [GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md) + [CRM_SALES_FORECAST_METHODOLOGY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CRM_SALES_FORECAST_METHODOLOGY_2026.md). |
| Group recruitment | procedure + history | One table row. |
| Group reviewers | always-on safety | Kept (never `course_teacher`) + [ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md). |
| Mutual settlement | always-on safety | Kept (before payout; school revenue untouched) + same architecture doc. |
| Investment model | procedure | Cut; `config/investment.php` named under never-hardcode. |
| Marathon visual skins | procedure | One table row. |
| Vendored reading packs / cohort SRS | always-on safety | Table row: never hand-edit, dual gate, positions-only, [`StartChteniyaSrsDeck.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/StartChteniyaSrsDeck.php). |
| Landing / lecture / activity | routing | One table cell each. |
| Domain relationship diagram | always-on routing | Kept. |
| External integrations + MAX path-secret | always-on safety | Kept compact. |
| Timezone / flags / HTTPS | routing | One-liners. |
| Composer platform pins | always-on safety | Kept. |
| `worktree_bootstrap.ps1` | always-on safety | Kept. |
| Test-filter rhythm | procedure | One line. |
| Never junction `vendor/` | always-on safety | Kept ([#713](https://github.com/gasyoun/Systema-Sanscriticum/issues/713)). |
| `CHANGELOG.md` lowercase trap | always-on safety | Kept ([Uprava FINDINGS §348](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md)). |
| Absolute-date fixture bomb | procedure + history | One line + [CalendarFeedTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Student/CalendarFeedTest.php). |
| `preg_split('/\R/')` | always-on safety | Kept (H1914). |
| Operational hazard notes | always-on safety | Kept + [DANGER_FACTS.md](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md). |
| *(missing)* watcher-safe-commit | always-on safety | **Added.** Fat file never named the skill. |
| *(missing)* money-contour marker | always-on safety | **Added** (`money-contour: no-auto-merge` is a reminder, not a merge ban). |
| *(missing)* CRM homework-pause | always-on | **Added** one-liner → [CRM_HOMEWORK_PAUSE_NOTE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CRM_HOMEWORK_PAUSE_NOTE_2026.md). |

## Dry routing check

Question used: “how do I land a money PR / recover a watcher revert / deploy?”

1. Money → `## Money contour` points at [`/money-pr-land`](https://github.com/gasyoun/claude-config/blob/main/commands/money-pr-land.md) + flag OFF + `money-contour` marker.
2. Watcher revert → `## Watcher` points at [`/watcher-safe-commit`](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md) (land+commit in one invocation, verify vs HEAD).
3. Deploy → `## Deploy / soft-alert` points at [`deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh) + [docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md) + `php artisan ops:soft-remediate`.

Pinned by [`scripts/test_context_budget.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/test_context_budget.py).

## Generator

`python Uprava/tools/gen_repo_context.py --repo Systema-Sanscriticum --dest <this worktree>` after the CLAUDE.md `##` TOC changed. The hand-authored AGENTS.md prefix was not edited. `--check` on the dest must print `Systema-Sanscriticum: ok`.

## Attempts

One measurement/edit round (budget: 5). First slim landed at ~2,370 tokens with every required safety string present. Stop.

_Dr. Mārcis Gasūns_
