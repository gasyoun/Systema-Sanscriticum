# Laravel 10 → 12 upgrade — security rationale and support-window notes

_Created: 09-08-2026 · Last updated: 10-08-2026_

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

## Successor: the 12 → 13 move — audit done, two blockers found (H2506, 09-08-2026)

**H2506** (Opus 5) ran the gating package-compatibility audit as its Step 1. Verdict:
**INCONCLUSIVE-with-evidence** — the blocker is **not** core Filament (the audit's original
framing) but two specific plugins with no Laravel-13-compatible release.

Method: authoritative composer dependency resolution, not speculation —
`composer require laravel/framework:^13.0 laravel/tinker:^3.0 -W --dry-run` in an isolated
worktree, followed by isolation testing (temporarily removing suspect packages and re-running
the dry-run) to confirm exactly which packages are load-bearing for the conflict.

| Package | Constraint | Locked | Laravel 13 status | Fix path |
|---|---|---|---|---|
| `laravel/tinker` | `^2.8` | v2.11.1 | v3.0.0+ already supports `illuminate/support ^13.0` | trivial — bump constraint to `^3.0` |
| `filament/filament` (core) | `^3.0` | v3.3.54 | already supports `illuminate/contracts ^10.45\|^11.0\|^12.0\|^13.0` | none needed — core is **not** a blocker |
| `mokhosh/filament-kanban` | `^2.11` | v2.11.0 | no released version supports `illuminate/contracts` beyond `^12.0`; repo active (pushed 2026-06-22) but no `^13` release, PR, or issue | needs a fixed upstream release, a fork/patch, or a replacement package |
| `saade/filament-fullcalendar` | `^3.0` | v3.2.4 | latest **stable** caps at `illuminate/contracts ^12.0`; the only `^13.0`-capable release is `v4.0.0-beta7`, which itself requires `filament/filament ^4.0\|^5.0` | wait for a stable v4/v5-track release, or accept the Filament-major bump as this plugin's specific fix path |

Every other package in the require/require-dev block — Horizon, Reverb, Sanctum, Socialite
(+ vkontakte/yandex providers), `spatie/laravel-backup`, `danog/madelineproto`, dompdf,
`jenssegers/agent`, `flysystem-webdav`, `awcodes/filament-tiptap-editor`, and require-dev
(paratest `^7`, PHPUnit `^11`, Pint, Mockery, Collision, Ignition, Faker, Sail) — resolved
cleanly in the same dry-run. With both blocking plugins temporarily removed, the full
`laravel/framework:^13.0` + `laravel/tinker:^3.0` resolution succeeds: "Lock file operations: 0
installs, 13 updates, 2 removals" (framework v12.64.0→v13.0.0, tinker v2.11.1→v3.0.0, plus
routine patch bumps to brick/math, guzzlehttp/guzzle+promises, laravel/prompts,
league/commonmark, nesbot/carbon, symfony/console+http-foundation+http-kernel+mime+translation),
with **no security vulnerability advisories** in the resulting lock.

`mokhosh/filament-kanban` is used in 6 app files (`DealKanbanBoard.php`, `LeadKanbanBoard.php`,
`UnifiedSalesBoard.php`, `WorkQueue.php`, the `Deal` and `Lead` models); `saade/filament-fullcalendar`
in 3 (`CalendarPage.php`, `ScheduleCalendarWidget.php`, `AdminPanelProvider.php`).

Per H2506's own stop condition, the framework bump was **not** attempted — chaining the
Laravel 13 move with a Filament-major bump (needed only for the fullcalendar plugin) in one
pass was explicitly out of scope. H2506 closed **INCONCLUSIVE-with-evidence**; a narrowly
scoped successor was minted to unblock specifically these two plugins (not a blanket Filament
v4/v5 upgrade — core Filament needs no change). Deadline remains **24-02-2027** (security-fix
end for 12), not 13-08-2026 — schedulable, not urgent.

## The 12 → 13 move: shipped, both blockers cleared without a Filament major (H2529, 10-08-2026)

**H2529** (Opus 5) executed the move. Result: **Laravel 13.24.0 on PHP 8.3.32 with core
Filament unchanged at v3.3.54.** Both H2506 blockers turned out to need *no source changes at
all* — only their declared `illuminate/contracts` constraint widened to admit `^13.0`.

### Why no Filament v4 bump was needed after all

H2506 read `saade/filament-fullcalendar`'s fix path as routing through a Filament major,
because the only `^13`-capable release (`v4.0.0-beta7`) requires `filament/filament ^4.0|^5.0`.
That framing missed a cheaper path: upstream has an open PR against the **`3.x`** branch,
[saade/filament-fullcalendar#280](https://github.com/saade/filament-fullcalendar/pull/280)
(opened 17-06-2026), which does nothing but widen the constraint — Filament 3 is retained. So
the Filament-major requirement was a property of the *v4 branch*, not of Laravel 13 support.

### Replacement packages: ruled out before patching

Step 2's preferred path (a maintained drop-in) was checked first and closed:
`scripts/h2529_fork_scan.py` scored 12 kanban candidates from Packagist. Exactly one admits
`illuminate/contracts ^13.0` — `sheavescapital/filament-kanban` v5.2 — and it requires
`filament/filament ^4.0|^5.0`, so adopting it would reintroduce the very Filament major this
handoff was scoped to avoid. Every other candidate (`relaticle/flowforge`, `wezlo`,
`invaders-xx`, `leonardo-max`, `jodeveloper`, `jessedev`, `wildsea`, `tales-virtualy`,
`alessandro-nuunes`, `rafazingano`, `sgvcode`) caps at `^12.0` or lower.

### The fix as shipped

| Package | Fork branch consumed | Upstream fix filed |
|---|---|---|
| `mokhosh/filament-kanban` | [gasyoun/filament-kanban@l13-compatibility](https://github.com/gasyoun/filament-kanban/tree/l13-compatibility) (off `main`) | [mokhosh/filament-kanban#95](https://github.com/mokhosh/filament-kanban/pull/95) — ours, awaiting maintainer |
| `saade/filament-fullcalendar` | [gasyoun/filament-fullcalendar@l13-compatibility](https://github.com/gasyoun/filament-fullcalendar/tree/l13-compatibility) (off upstream `3.x`) | [saade/filament-fullcalendar#280](https://github.com/saade/filament-fullcalendar/pull/280) — pre-existing, not ours |

Both are consumed as `vcs` repositories in `composer.json` with an aliased dev constraint
(`"dev-l13-compatibility as 2.11.0"` / `"as 3.2.4"`) so `prefer-stable` still holds for the
rest of the tree. **These forks are temporary** — when either upstream PR merges and tags,
drop the `repositories` entry and return to a normal caret constraint. That retirement is the
one piece of debt this move adds.

### The one test that broke, and why it was supposed to

`Tests\Feature\ExceptionHandlerRenderableTypeHintTest` failed on arm count: 10, expected 9.
This is the FINDINGS §228 guard doing exactly its job — Laravel 13 adds one arm to
`Handler::prepareException()`:

```php
$e instanceof OriginMismatchException => new HttpException(403, $e->getMessage(), $e),
```

That is the new `PreventRequestForgery` origin-aware CSRF check shipped in 13.x. The guard was
re-derived to 10 rather than loosened, and the banned-type assertion re-verified: our two
`renderable()` callbacks are hinted on `HttpException` and `PostTooLargeException`, neither of
which `prepareException()` converts away, so no callback became dead code. **Anyone adding a
`renderable()` callback hinted on `OriginMismatchException` from now on will be writing dead
code** — hint `HttpException` and check `getPrevious()` instead.

### Evidence

- Full suite: **3093 tests, 0 failures**, 11145 assertions, 2 skipped (`vendor/bin/phpunit`,
  SQLite in-memory, local run 10-08-2026). The 1764 PHPUnit deprecations are pre-existing —
  the count is byte-identical before and after the bump, so none are Laravel-13-induced.
- Money core: `TochkaWebhookTest|MutualSettlementTest|FinanceIdempotencyTest|FinanceLockingMySqlTest`
  → **56 passed, 1 skipped** locally (the skip is the MySQL-only locking test; CI's MySQL 8.4
  job is the real gate for it).
- Pint: `{"tool":"pint","result":"passed"}`.
- `composer update` reported **no security vulnerability advisories**. No `--ignore-platform-reqs`
  or `--ignore-package-requirements` was used at any point; resolution succeeded on the first
  attempt (the handoff allowed 5).

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

- The 10→12 section's version facts come from `composer.json` / `composer.lock` / the live
  prod probe, with CI on `main` as suite evidence — that section was written without a local
  `vendor/`. The 12→13 section (H2529) *is* backed by a local run: 3093 tests on an installed
  `vendor/`, figures quoted inline there.
- Package-level readiness for Laravel 13 was verified by H2506 and the move then **shipped**
  under H2529 — see the 12→13 section. Both plugin blockers are cleared via temporary
  constraint-widening forks; core Filament stayed on `^3.0` / v3.3.54 throughout.
- **Open debt from H2529:** the two `vcs` fork entries in `composer.json`. They must be
  retired once [mokhosh/filament-kanban#95](https://github.com/mokhosh/filament-kanban/pull/95)
  and [saade/filament-fullcalendar#280](https://github.com/saade/filament-fullcalendar/pull/280)
  merge and tag. Until then the app tracks two branch heads rather than immutable tags, so a
  force-push in either fork would change what installs — the forks are under `gasyoun`
  precisely so no third party can do that.
- The two remaining Wave-4 items (dependency posture review, deploy-surface review) are
  untouched by this note.

## References

- [docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md) § Wave 4 — the checklist this closes.
- [docs/php-8.3-upgrade.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/php-8.3-upgrade.md) — the PHP shoulder of the same platform move (superseded/historical).
- [PR #505](https://github.com/gasyoun/Systema-Sanscriticum/pull/505) — the H862 upgrade itself.
- [PR #1565](https://github.com/gasyoun/Systema-Sanscriticum/pull/1565) — H2478 Wave-4 doc-close (PHP 8.3 + Laravel 12 checklist ticks).

_Dr. Mārcis Gasūns_
