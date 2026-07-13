# Systema-Sanscriticum — Optimisation & Bottleneck Backlog (2026 H2)

_Created: 13-07-2026 · Last updated: 13-07-2026_

The single ranked index of what needs **unblocking, speeding up, or paying down** in
Systema-Sanscriticum, so the picture no longer lives only in `.ai_state.md` Dev Notes and
across ~15 topic roadmaps. Every row was fact-checked against **`origin/main`** on
13-07-2026 (model: Opus 4.8, `claude-opus-4-8`); the "Evidence" column is the check that
grounds it.
Ranked by **leverage**, not effort. This is an index, not a plan — deep plans live in the
per-topic roadmaps linked from each row.

> Sense of "optimisation" here: Systema is a transactional Laravel LMS (samskrte.ru), not a
> build/data-pipeline repo. The dominant costs are **unshipped work** and **dev-loop
> friction**, not code hot-paths. Rank accordingly.

## 1. Deploy gate — the dominant drag (owner: human decision)

A stack of `php artisan migrate` migrations is authored and merged but **unrun on prod**,
so finished features sit dark and one path 500s. Root cause is **access, not hosting**:
prod is a root VPS (Beget) but deploy creds sit only with contractor Ivan; there is no
CI-deploy.

- **Impact:** `/slovar` returns 500 until the dictionary-words SEO migration runs
  ([`2026_07_05_190000_add_seo_fields_to_dictionary_words.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_05_190000_add_seo_fields_to_dictionary_words.php)),
  plus scheduled_reminders, reminder_suggestions, intakes/waitlist, attribution columns,
  access_attempts.
- **This is not an engineering task** — the 5 options are already worked up in
  [`Uprava/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md`](https://github.com/gasyoun/Uprava/blob/main/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md)
  and the review sheet `Uprava/review/systema-sanscriticum-deploy-gate_options5_review.html`.
  A human should pick an option; everything downstream unblocks from there. Longest-standing
  block in the repo.

## 2. Local dev loop — highest-frequency time sink (owner: agent)

Every visual/test iteration pays a tax from standing Windows-dev breakage. These are the
day-to-day "optimisation" wins because they compound over every session:

- **`@vite` 500s the homepage/tests** until `public/build/manifest.json` exists — front-end
  must be built before `php artisan test` or the homepage, a recurring false "test failure".
- **MadelineProto polyfill breaks all Livewire locally** — blocks local Livewire iteration.
- **Filament custom Blade bypasses Tailwind JIT** — utility classes silently no-op in custom
  pages, so visual fixes look applied but aren't.
- **Local MySQL migration registry is corrupt** — won't migrate clean locally.
- **`git worktree` + junctioned `vendor/` once tested the wrong code and wiped real
  `vendor/`** — a real incident; the `watcher-safe-commit` procedure now guards writes here.

Fixing the top two (build-before-test guard, Livewire-local unblock) removes most of the
friction. Source: `.ai_state.md` Dev Notes.

## 3. Tech debt with a clock (owner: agent, some human-gated)

- **Semgrep SAST — being promoted to a required gate** (H885,
  [PR #509](https://github.com/gasyoun/Systema-Sanscriticum/pull/509), open). All 18 advisory
  findings were cleared (actions pinned to SHAs, Dependabot cooldown, stray nginx page
  removed) and `semgrep.yml` flipped to `--error`; the PR's Semgrep check is green. Closes
  once merged.
- **Off-site backups never actually run** until the Yandex.Disk app-password is set
  (human-gated secret). Until then there is no verified off-site copy.

## 4. Throughput surface to watch — not yet a problem (owner: agent, monitor)

Many `*:deliver-due` / detection commands run every 15 min (marathon drip, reminders,
reminder-detection LLM scan). The LLM-per-message scan is already regex-prefiltered, but it
is the surface most likely to become a cost/throughput bottleneck as volume grows toward the
**28-08-2026 cohort**. Instrument before it bites.

## 5. Tracking hygiene (owner: agent)

- **13 Systema roadmap docs carry zero metadoc coverage** (flagged 13-07-2026 weekly-review,
  [`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md)) —
  a `/metadoc` sweep is queued.
- This backlog is itself the fix for "no single optimisation index existed" — keep it current
  as items ship, and prune rows once their prod migration runs.

---

## Verified non-issues (checked 13-07-2026, do not re-investigate)

- **Laravel/PHP EOL is resolved.** `origin/main` is on
  [`"laravel/framework": "^12.61.1"`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/composer.json)
  and PHP `^8.3` — the 10→12 security upgrade merged as H862 (#505). The older
  `.ai_state.md` "Laravel 10→11 open (Wave 4)" note is stale; do not re-open it. (Beware:
  the shared main checkout may sit on a pre-upgrade feature branch showing `^10.10` — verify
  against `origin/main`, not the working tree.)
- **`vendor/` is not committed.** `git check-ignore vendor` confirms it; `/vendor` is in
  [`.gitignore`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.gitignore) line 7;
  `git ls-files vendor/` returns 0. The 28k+ files are Composer's on-disk install only — no
  repo-size problem.
- **Message-store unification is already built** (verified 13-07-2026 against `origin/main`).
  The `ChatMessage` (web) vs `TelegramSupportMessage` (Telegram) divergence is reconciled by a
  deliberate read layer, not a table merge:
  [`UnifiedMessage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/UnifiedMessage.php)
  DTO (two factories normalise direction/responder/`ai_state`/timestamp; `htmlText()` renders
  both via `SupportText::safeHtml`) +
  [`UnifiedInboxReader::forUser()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/UnifiedInboxReader.php)
  merges both stores chronologically, powering the
  [`Helpdesk`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Helpdesk.php)
  operator inbox; writes stay per-channel via the union-typed `SupportConversationManager`.
  Built 01-07-2026 (Step 2, [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md)).
  The `.ai_state.md` "not yet unified under a read layer" note is stale — do not rebuild it.
  Single-channel consumers (student-cabinet chat, public web widget, per-store analytics,
  write paths) bypass the reader **by design** — they must not pay the cross-channel merge
  cost. Genuinely-open items are feature-parity gaps (Helpdesk status tabs, web-chat topic
  taxonomy, channel badging), tracked in the subsystem map's "Actually open" table — not
  unification.

_Dr. Mārcis Gasūns_
