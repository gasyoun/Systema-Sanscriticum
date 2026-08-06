# Systema-Sanscriticum — Optimisation & Bottleneck Backlog (2026 H2)

_Created: 13-07-2026 · Last updated: 31-07-2026_

The single ranked index of what needs **unblocking, speeding up, or paying down** in
Systema-Sanscriticum. Origin: [H881](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H881-Opus_Systema-Sanscriticum_optimisation-backlog-2026h2_13.07.26.md)
(Opus 4.8, `claude-opus-4-8`). **Refreshed 31-07-2026** ([H2014](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2014-Grok_Systema-Sanscriticum_optimisation-kanban-refresh_31.07.26.md),
Grok 4.5 `grok-4.5`) against live prod (`root@193.232.229.92`, `/var/www/html`) and
`origin/main`.

Ranked by **leverage**, not effort. This is an index, not a plan — deep plans live in the
per-topic roadmaps linked from each row.

> Sense of "optimisation" here: Systema is a transactional Laravel LMS (samskrte.ru), not a
> build/data-pipeline repo. The dominant costs were **unshipped work** and **dev-loop
> friction**. After H1933 auto-deploy, residual drag is **flag/env human steps** and a few
> local Windows frictions — not deploy architecture.

**Status tokens:** `shipped` · `prod-pending` · `open` · `monitor` · `human-secret` · `non-issue`

---

## 1. Deploy gate — was the dominant drag — **shipped** (architecture)

| Token | Fact (31-07-2026) |
|---|---|
| **shipped** | Deploy options **A+B** ruled 16-07; **auto-deploy every 30 min** (H1933) pulls `origin/main` via `deploy.sh`. Prod HEAD tracks `main` (verified 31-07: `a4ff4325` lineage / release stream 1.80.x). |
| **shipped** | Bulk migrate backlog (dictionary SEO, reminders, intakes, …) no longer blocked on “Ivan has the only deploy path”. |
| residual | Only **decision / flag / one-shot artisan / external secret** rows remain in [`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md). |

Options sheet (historical): [`Uprava/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md`](https://github.com/gasyoun/Uprava/blob/main/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md).

---

## 2. Local dev loop — **partial**

| Item | Token | Evidence |
|---|---|---|
| `@vite` / missing `manifest.json` false-fails tests | **shipped** | H884: `withoutVite()` in base [`tests/TestCase.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/TestCase.php) `setUp()` — all feature tests build-independent. |
| MadelineProto Windows polyfill breaks Livewire JSON | **open → fix landed H2014** | Vendor `polyfill.php` `echo`s a WARNING into **every** PHP stdout on Windows → Livewire AJAX `JSON.parse` dies. Linux prod unaffected. Silence script: [`scripts/silence_madeline_windows_polyfill.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/silence_madeline_windows_polyfill.php) hooked on `composer post-autoload-dump` (idempotent). |
| Filament custom Blade bypasses Tailwind JIT | **open** | No `viteTheme()` on AdminPanelProvider — custom `resources/views/filament/pages/*` only get Filament’s precompiled utilities. Recipe: scoped `<style>` in the view, or a real Filament Vite theme (larger). |
| Local MySQL migration registry corrupt | **open** | Clone-local; fix is `migrate:fresh` on a disposable DB or repair `migrations` table — not a prod issue. |
| `git worktree` + junctioned `vendor/` wiped real vendor | **mitigated** | Process: [`/watcher-safe-commit`](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md); never junction `vendor/` across worktrees. |

---

## 3. Tech debt with a clock

| Item | Token | Evidence |
|---|---|---|
| Semgrep SAST required gate | **shipped** | H885 / [PR #509](https://github.com/gasyoun/Systema-Sanscriticum/pull/509) — `--error`, green. |
| Off-site backups (Yandex.Disk WebDAV) | **human-secret** | **Local disk OK:** `php artisan backup:list` → Laravel/`local` healthy, **7** zips, newest ~3d (as of 31-07). **`yandex_disk` UNAUTHORIZED** (Sabre HTTP 401). Needs valid `YANDEX_DISK_LOGIN` + **app password** `YANDEX_DISK_APP_PASSWORD` in prod `.env` (not the account password) — then `php artisan backup:run --only-to-disk=yandex_disk` once to verify. Config: [`config/filesystems.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/filesystems.php) `yandex_disk` + [`config/backup.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/backup.php). |

---

## 4. Throughput surface — **monitor**

Many `*:deliver-due` / detection commands every 15 min (marathon drip, reminders,
reminder-detection LLM scan). Regex prefilter on LLM path. **No measured cost baseline yet**
before the **28-08-2026** cohort. Instrument before it bites; do not “optimise” cold.

---

## 5. Tracking hygiene — **shipped**

| Item | Token | Evidence |
|---|---|---|
| 13 roadmap metadocs | **shipped** | H887 / [PR #510](https://github.com/gasyoun/Systema-Sanscriticum/pull/510). |
| This backlog itself | **shipped (refresh)** | H2014 status tokens + prod verification. |

---

## 6. CRM flag coupling (GC-C1/C3) — **shipped on prod 31-07-2026**

| Flag | Prod (31-07) | Notes |
|---|---|---|
| `CRM_COCKPIT` | **true** | WorkQueue «Моя работа сегодня» (H221). |
| `CRM_PIPELINE_BOARD` | **true** | Ruled + flipped 30-07 (MG); Deal kanban + payment bridge. |
| `CRM_FOLLOW_UP_TASKS` | **true** | **Flipped 31-07 (H2014):** fifth WorkQueue bucket. Migration `follow_up_tasks` already Ran. `WorkQueueReport::counts()` returns `follow_ups` key (0 rows empty table is fine). |
| `CRM_REMINDERS` | **false** (left alone) | Gates `leads:remind-followup` (writes to people). **Do not couple** to follow-up tasks (spec §7 F6). |

Code coupling (by design): fifth card needs **both** `crm_cockpit` and `crm_follow_up_tasks`
([`WorkQueue.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/WorkQueue.php) /
[`WorkQueueReport::followUpTasksDue()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/WorkQueueReport.php)).

---

## 7. Prod env / residual human steps (short list, verified 31-07)

| Step | Token | Verified fact |
|---|---|---|
| `SESSION_LIFETIME=1440` | **shipped** | Prod `.env` already `1440`; `config('session.lifetime')` = 1440. DEPLOY_QUEUE 419 residual for lifetime is **stale**. |
| SMTP / issue [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504) | **mostly shipped** | Prod `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.yandex.ru`, `mail:preflight` **OK**. Horizon consumes `mailing` queue. Residual if mail still fails: sender domain SPF/DKIM and real `mail:preflight --send=…` smoke — not “still on mailpit”. |
| `UPGRADE_CREDIT_REFUND_LINK` (DEPLOY №59) | **prod-pending (findir)** | Default OFF; money-contour — needs finance review before ON. Do **not** agent-flip. |
| Yandex.Disk off-site backup | **human-secret** | See §3. |
| H1067 marathon **channel** publish | **prod-pending (calendar)** | Landing copy **variant A is live** on `LandingPage` slug `konsultaciya-po-onlayn-kursam` (H1067 path / PR #815). Residual Tier-0: schedule **channel posts** (~14-08 announce via `php artisan marathon:publish-channel-posts --post=1 --live` after dry-run) + optional variant B later. Pack: [`marketing/marathon-2026-08/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/marketing/marathon-2026-08). |

---

## Verified non-issues (do not re-investigate)

- **Laravel/PHP EOL.** `origin/main` on Laravel 12 / PHP ^8.3 (H862).
- **`vendor/` not committed.** gitignored; on-disk Composer install only.
- **Message-store unification.** `UnifiedMessage` + `UnifiedInboxReader` already power Helpdesk (01-07-2026).
- **Deploy architecture / single contractor deploy path** as the §1 bottleneck — superseded by H1933 auto-deploy.
- **Semgrep advisory-only** — required since H885.

---

## What the org bottleneck is *not* anymore

§1 of this file used to be the whole-org unlock for Systema features. **It is not.**
Auto-deploy + migrate cadence cleared that. The Tier-0 revenue chokepoint for the 28-08
cohort is **comms publish residual** (channel posts / schedule), not deploy architecture —
see [Uprava/BOTTLENECKS.md](https://github.com/gasyoun/Uprava/blob/main/BOTTLENECKS.md) and
H1067 pack README.

_Dr. Mārcis Gasūns_
