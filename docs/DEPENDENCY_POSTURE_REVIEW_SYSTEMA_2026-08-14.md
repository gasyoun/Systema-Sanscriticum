# Dependency posture review — Systema Sanscriticum (Wave 4)

_Created: 14-08-2026 · Last updated: 14-08-2026_

**Handoff:** [H2479 (Grok 4.6) — SECURITY Wave4: dependency posture review](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2479-Grok_Systema-Sanscriticum_security-w4-dependency-posture-review_08.08.26.md)
**Executor:** Grok 4.6 (`grok-4.6`)
**PR:** [#1671](https://github.com/gasyoun/Systema-Sanscriticum/pull/1671)
**Tree:** `origin/main` at `520bbbad` (release [v1.89.29](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.89.29)), re-verified 14-08-2026.

## Verdict

**No open high/critical Dependabot alerts. No Composer or npm advisories on the locked trees. No Packagist-abandoned packages in `composer.lock`.** Wave 4's dependency-posture checkbox can close. The remaining Wave 4 item is the deploy-surface review ([H2480 (Grok 4.6) — SECURITY Wave4: deploy-surface review](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2480-Grok_Systema-Sanscriticum_security-w4-deploy-surface-review_08.08.26.md)), not another lockfile bump.

Every historical Dependabot CVE on this repo is **fixed**, not ignored. Actions in this pass close a coverage hole (Dependabot + CI only watched the repo-root npm lock) rather than bump majors that are already on a supported line.

## How this was measured (14-08-2026)

| Probe | Command / source | Result |
|---|---|---|
| Composer advisories + abandoned | `composer audit --locked --format json` in a worktree off `origin/main` | `advisories: []`, `abandoned: []` |
| Root npm | `npm audit --package-lock-only --json` | 0 vulnerabilities (132 deps) |
| Mobile npm | `npm audit --package-lock-only --prefix mobile` | 0 vulnerabilities (99 deps) |
| Dependabot open | `gh api repos/gasyoun/Systema-Sanscriticum/dependabot/alerts?state=open` | `[]` |
| Dependabot history | same API, all states | **40** alerts, **all `fixed`** (24 composer, 5 npm, 11 pip) |
| Lock abandoned flag | every `packages` / `packages-dev` row in [composer.lock](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/composer.lock) | 0 `abandoned` |
| Packagist abandoned | `https://repo.packagist.org/p2/{name}.json` for every direct `require` / `require-dev` name | 0 marked abandoned |
| CI already gates | [.github/workflows/ci.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/ci.yml) `composer audit --locked` + root `npm audit` | present; mobile lock was **not** gated until this PR |

`composer.json` already has `config.audit.block-insecure: true`. Dependabot security updates + vulnerability alerts have been on since Wave 1 (03-07-2026). Weekly version updates + 7-day cooldown + auto-merge of patch/minor were verified green the same day under [H2476 (Grok 4.6) — SECURITY Wave3 Dependabot auto-merge keep-green](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2476-Grok_Systema-Sanscriticum_security-w3-dependabot-auto-merge-green_08.08.26.md).

## Open CVE / advisory table

| Ecosystem | Package | CVE / GHSA | Severity | Action |
|---|---|---|---|---|
| — | — | — | — | **None open.** Fail condition "silent ignore of a known CVE" does not apply. |

## Historical Dependabot CVEs (all fixed — not ignored)

Forty alerts, numbered 1–41 with #40 unused. Grouped by package. Current lock versions were re-read on `origin/main` this pass.

| Package | Manifest | Alerts | Worst sev | Last fixed | Locked now |
|---|---|---|---|---|---|
| `phpoffice/phpspreadsheet` | [composer.lock](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/composer.lock) | #1–#5, #10 ([CVE-2026-35453](https://github.com/advisories/GHSA-6wpp-88cp-7q68), [CVE-2026-40296](https://github.com/advisories/GHSA-hrmw-qprp-wgmc), [CVE-2026-34084](https://github.com/advisories/GHSA-q4q6-r8wh-5cgh) critical, [CVE-2026-40863](https://github.com/advisories/GHSA-84wq-86v6-x5j6), [CVE-2026-40902](https://github.com/advisories/GHSA-7c6m-4442-2x6m), [CVE-2026-45034](https://github.com/advisories/GHSA-87m4-826x-3crx) critical) | critical | 03-07-2026 | **1.30.6** (patches start at 1.30.4) |
| `symfony/html-sanitizer` | composer.lock | #6, #7, #9, #12, #13 | medium | 03-07-2026 | pulled in by Filament; lock clean under `composer audit` |
| `symfony/dom-crawler` | composer.lock | #8 [CVE-2026-45071](https://github.com/advisories/GHSA-x6g4-fwcc-jj8w) | low | 03-07-2026 | **v7.4.12** |
| `filament/tables` | composer.lock | #11 [CVE-2026-48067](https://github.com/advisories/GHSA-7q3w-xqjw-g3cr) | medium | 05-07-2026 | Filament **v3.3.54** |
| `laravel/framework` | composer.lock | #14 [GHSA-5vg9-5847-vvmq](https://github.com/advisories/GHSA-5vg9-5847-vvmq) high, #15 [GHSA-crmm-hgp2-wgrp](https://github.com/advisories/GHSA-crmm-hgp2-wgrp) | high | 13-07-2026 | **v13.24.0** |
| `filament/forms` | composer.lock | #16 [CVE-2026-55409](https://github.com/advisories/GHSA-m9cv-24rx-8mv7) | high | 05-07-2026 | Filament **v3.3.54** |
| `filament/filament` | composer.lock | #17 [CVE-2026-48500](https://github.com/advisories/GHSA-44wp-g8f4-f4v5) | medium | 05-07-2026 | **v3.3.54** |
| `guzzlehttp/guzzle` | composer.lock | #31 [CVE-2026-67339](https://github.com/advisories/GHSA-94pj-82f3-465w) | medium | 21-07-2026 | **7.15.3** |
| `dompdf/dompdf` | composer.lock | #32–#37 (six CVEs, 26-07-2026) | medium | 26-07-2026 | **v3.1.6** |
| `picomatch` | [package-lock.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/package-lock.json) | #28 [CVE-2026-33671](https://github.com/advisories/GHSA-c2c7-rcm5-vvqj) high, #29 [CVE-2026-33672](https://github.com/advisories/GHSA-3v7f-55p6-f55p) | high | 04-08-2026 | pinned via `overrides` to **2.3.2** in [package.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/package.json) |
| `form-data` | package-lock.json | #30 [CVE-2026-12143](https://github.com/advisories/GHSA-hmw2-7cc7-3qxx) | high | 03-07-2026 | root `npm audit` clean |
| `brace-expansion` | [mobile/package-lock.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/mobile/package-lock.json) | #41 [CVE-2026-69152](https://github.com/advisories/GHSA-rgw5-rvv9-x895) | high | 04-08-2026 | mobile `npm audit` clean |
| `tar` | mobile/package-lock.json | #39 [GHSA-r292-9mhp-454m](https://github.com/advisories/GHSA-r292-9mhp-454m) | medium | 26-07-2026 | mobile `npm audit` clean |
| `yt-dlp` | [lecture-builder/requirements.txt](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/lecture-builder/requirements.txt) | #22, #24–#27, #38 | high | 26-07-2026 | **2026.7.4** |
| `python-dotenv` | lecture-builder/requirements.txt | #23 [CVE-2026-28684](https://github.com/advisories/GHSA-mf9w-mj56-hr94) | medium | 03-07-2026 | **1.2.2** |
| `flask` | lecture-builder/requirements.txt | #21 [CVE-2026-27205](https://github.com/advisories/GHSA-68rp-wp8r-4726) | low | 03-07-2026 | **3.1.3** |
| `Jinja2` / `jinja2` | lecture-builder/requirements.txt | #18–#20 | medium | 03-07-2026 | **3.1.6** |

Full permalinks: [Dependabot alert list](https://github.com/gasyoun/Systema-Sanscriticum/security/dependabot).

## Abandoned / stale (not the same thing)

Composer and Packagist both say **no package is marked abandoned**. Stale-but-maintained-as-latest is a different class:

| Package | Locked | Latest on Packagist 14-08-2026 | Last upstream time | Call |
|---|---|---|---|---|
| `jenssegers/agent` | v2.6.4 | v2.6.4 | **13-06-2020** | **Watch.** Not Packagist-abandoned; GitHub repo is not archived. Single production caller: [app/Services/Activity/ActivityTracker.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Activity/ActivityTracker.php) (`device_type` / `browser` / `os` on `UserSession`). Telemetry only — not an access or money boundary. Pulls `mobiledetect/mobiledetectlib` ^2.7.6. **Won't-fix this pass:** replacing it is a product change (new parser, new fingerprints), not a CVE close. Revisit if a GHSA lands or MobileDetect 2 goes unmaintained-with-CVE. |
| `socialiteproviders/yandex` | 4.1.0 | 4.1.0 | 01-12-2020 | **Won't-fix.** Still the latest tag; Yandex login works against a frozen Socialite provider. No advisory. |
| `nelexa/zip` | 4.0.2 | 4.0.2 | 17-06-2022 | **Won't-fix.** Latest tag; no advisory. |
| `mokhosh/filament-kanban` / `saade/filament-fullcalendar` | `dev-l13-compatibility` via `vcs` forks | upstream stables do not admit Laravel 13 | 10-08-2026 (fork heads) | **Already tracked** as [H2529](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LARAVEL_10_TO_12_UPGRADE_SECURITY_NOTES.md) open debt. Retire the forks when [mokhosh/filament-kanban#95](https://github.com/mokhosh/filament-kanban/pull/95) and [saade/filament-fullcalendar#280](https://github.com/saade/filament-fullcalendar/pull/280) merge and tag. Not a new finding. |
| `filament/filament` | v3.3.54 | v5.7.6 | 12-06-2026 (locked 3.x) | **Won't-fix major.** H2529 kept Filament 3 on purpose so Laravel 13 could ship without a Filament major. |
| `laravel/framework` | v13.24.0 | v13.25.0 | 11-08-2026 | **Leave to weekly Dependabot.** Same major, no advisory. Blind same-day patch bump is not this handoff. |

Majors sitting one line up (`guzzle` 8, `phpunit` 13, Symfony 8, `pxlrbt/filament-excel` v3) are **won't-fix** here. They are upgrade programmes, not posture defects.

## Coverage hole this pass closes

[.github/dependabot.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/dependabot.yml) listed only `github-actions` `/`, `npm` `/`, and `composer` `/`. GitHub **still** raised security alerts on `mobile/package-lock.json` and `lecture-builder/requirements.txt` (11 of the 40 historical alerts). Those alerts got fixed, but **version-update PRs and the 7-day cooldown never applied** to those two trees. Root CI `npm audit` likewise never saw the Capacitor lock.

This is the residual PR, not a lockfile rewrite:

1. Dependabot `npm` at `/mobile` and `pip` at `/lecture-builder`, same weekly Monday 08:00 Europe/Moscow + 7-day cooldown + PR cap 5 as the root ecosystems.
2. CI job `Mobile npm security audit` running `npm audit --package-lock-only` against `mobile/`.

`lecture-builder/` is a sidecar (Flask + yt-dlp), not the Laravel money core. Dependabot pip is enough; a `pip-audit` CI job is **not** added this pass (won't-fix / out of the lockfile brief).

## Actions

| # | Action | Status |
|---|---|---|
| A1 | Dated report (this file) | done |
| A2 | Tick Wave 4 "Dependency posture review" with this cite | done in the same PR |
| A3 | Extend Dependabot to `/mobile` and `/lecture-builder` | done in the same PR |
| A4 | Gate `mobile/package-lock.json` in CI | done in the same PR |
| A5 | Bump Laravel 13.24 → 13.25 / Filament 3 → 5 / replace `jenssegers/agent` | **won't-fix** (rationale above) |
| A6 | Retire `vcs` Filament forks | already owned by H2529; not re-opened |

## Reproduce

From a checkout of `origin/main` (or this PR):

```
composer audit --locked --format json
npm audit --package-lock-only --json
npm audit --package-lock-only --prefix mobile --json
gh api repos/gasyoun/Systema-Sanscriticum/dependabot/alerts?state=open
```

Expect empty advisories, zero npm vulnerabilities, empty open-alert list.

## What this does not close

- [H2480 (Grok 4.6) — SECURITY Wave4: deploy-surface review](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2480-Grok_Systema-Sanscriticum_security-w4-deploy-surface-review_08.08.26.md) (`deploy.sh` / `docker-compose.yml` secret echo). Wave 4 **exit** still waits on that item.
- H2529 fork-retirement once the two upstream PRs tag.
- A `jenssegers/agent` replacement, if a future session wants one.

_Dr. Mārcis Gasūns_
