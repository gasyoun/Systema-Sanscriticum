# Laravel 10 → 12 upgrade — security rationale and support-window notes

_Created: 09-08-2026 · Last updated: 09-08-2026_

The **security rationale** half of SECURITY_ROADMAP Wave 4 (H2477). The upgrade itself
shipped earlier under H862; this document records *why* it landed on Laravel 12 instead of
the roadmap's 10→11 target, what proves the platform is green today, and when the next move
becomes due. Companion:
[docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md)
§ Wave 4.

## Status (verified 09-08-2026)

| Surface | Evidence |
|---|---|
| Requirement | `laravel/framework: "^12.61.1"`, `php: "^8.3"`, `config.platform.php: 8.3.0` in [composer.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/composer.json) |
| Locked | `laravel/framework` **v12.64.0** in [composer.lock](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/composer.lock) |
| Production | `php artisan --version` on `193.232.229.92:/var/www/html` → **Laravel Framework 12.64.0**, PHP **8.3.32** (live probe 09-08-2026) |
| Upgrade commit | `34fbb0c3` — "security(deps): Laravel 10->12 upgrade closing HIGH+MODERATE Dependabot advisories (H862)" ([PR #505](https://github.com/gasyoun/Systema-Sanscriticum/pull/505)) |
| Dependabot | **0** open alerts (`repos/gasyoun/Systema-Sanscriticum/dependabot/alerts?state=open`) |
| Locked-dependency audit | CI job "Composer security audit (locked dependencies)" green on `main` |

## Why 12 directly, and not the roadmap's 11

The Wave-4 item was written as "Laravel 10 to 11". Executing it literally would have shipped
the platform onto a line whose **security window had already closed**: Laravel 11 stopped
receiving security fixes on **12-03-2026**. An 11-target upgrade completed in mid-2026 would
have moved the app from one EOL line (10, security-EOL 04-02-2025) to another, spending the
whole upgrade window without buying a supported line. H862 jumped to 12 and closed the
outstanding HIGH + MODERATE Dependabot advisories in the same change.

Laravel's own upgrade posture made the bigger jump cheap rather than riskier: 12 is
explicitly a maintenance release whose focus was minimising breaking changes, so most
applications upgrade without touching application code. The residual breakage this project
actually hit was Carbon 3 typing, fixed separately (`adffea23`, "TypeError в
resolveForZoomEvent на Carbon 3 (Laravel 12)").

## Support windows (laravel.com/docs/12.x/releases, read 09-08-2026)

| Version | PHP | Released | Bug fixes until | Security fixes until |
|---|---|---|---|---|
| 10 | 8.1–8.3 | 14-02-2023 | 06-08-2024 | 04-02-2025 (EOL) |
| 11 | 8.2–8.4 | 12-03-2024 | 03-09-2025 | 12-03-2026 (EOL) |
| **12 (current)** | 8.2–8.5 | 24-02-2025 | **13-08-2026** | **24-02-2027** |
| 13 | 8.3–8.5 | Q1 2026 | Q3 2027 | Q1 2028 |

Three dated consequences, all load-bearing for Wave 4's exit criterion:

- **Bug-fix support for 12 ends 13-08-2026** — four days after this note was written. From
  that date the app is on a *security-fixes-only* line. That satisfies "runs a supported
  Laravel" but is not a steady state.
- **Security support runs to 24-02-2027**, which is the real deadline for moving to 13.
- **No PHP move is required for 13.** Laravel 13 supports PHP 8.3–8.5 and this project
  already pins 8.3 in CI, composer platform, and production — the usual blocker for a major
  Laravel bump is absent here.

## Successor: the 12 → 13 move

Minted this pass as **H2506** (Opus 5) — *SECURITY Wave4: Laravel 12 to 13 upgrade*. Its first
step is a **gating** package-compatibility audit (Filament `^3.0` and its five plugins, Horizon,
Reverb, Sanctum, Socialite, `spatie/laravel-backup`, `danog/madelineproto`, dompdf, paratest
`^7` / PHPUnit `^11`): if Filament blocks 13.x, the Filament major upgrade becomes its own
handoff rather than being forced with `--ignore-platform-reqs`. Deadline is **24-02-2027**
(security-fix end for 12), not 13-08-2026 — so the successor is schedulable, not urgent.

## What proves "money-core suite green"

CI on `main` (run of 09-08-2026 11:38Z, all green) runs the money paths on the shipped
platform via [.github/workflows/ci.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/ci.yml):

- **PHP 8.3 — tests** — `php artisan test --parallel` (paratest), SQLite in-memory.
- **MySQL 8.4 — finance/webhook transactions** — a dedicated job running the finance and
  webhook transaction tests against real MySQL, i.e. the money core on the engine production
  uses.
- **Semgrep (PHP SAST)** — `--error`, a required branch-protection check.
- Plus Laravel Pint format check, npm/composer audits, CodeQL, changelog + YAML lint.

## Caveats

- The Laravel version facts come from `composer.json` / `composer.lock` / the live prod
  probe. No local test run backs this note: this checkout has no `vendor/`, so the suite
  evidence is CI on `main`, not a local execution.
- Package-level readiness for Laravel 13 is **unverified** — Filament `^3.0`, Horizon
  `^5.46`, Reverb `^1.10`, Sanctum `^4.0`, and the Filament plugin set each need a
  compatibility check before a 13 upgrade is scheduled. That check is the first step of the
  successor handoff, not a conclusion of this one.
- The two remaining Wave-4 items (dependency posture review, deploy-surface review) are
  untouched by this note.

## References

- [docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md) § Wave 4 — the checklist this closes.
- [docs/php-8.3-upgrade.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/php-8.3-upgrade.md) — the PHP shoulder of the same platform move (superseded/historical).
- [PR #505](https://github.com/gasyoun/Systema-Sanscriticum/pull/505) — the H862 upgrade itself.
- [PR #1565](https://github.com/gasyoun/Systema-Sanscriticum/pull/1565) — H2478 Wave-4 doc-close (PHP 8.3 + Laravel 12 checklist ticks).

_Dr. Mārcis Gasūns_
