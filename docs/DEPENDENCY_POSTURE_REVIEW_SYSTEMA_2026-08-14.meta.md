# DEPENDENCY_POSTURE_REVIEW_SYSTEMA_2026-08-14.meta.md

_Created: 14-08-2026 · Last updated: 14-08-2026_

Companion metadoc for
[docs/DEPENDENCY_POSTURE_REVIEW_SYSTEMA_2026-08-14.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DEPENDENCY_POSTURE_REVIEW_SYSTEMA_2026-08-14.md).

## Subject

- **Document:** Wave 4 lockfile posture review (`composer.lock` + both `package-lock.json` trees + Dependabot history).
- **Purpose:** Dated evidence that no open CVE was ignored, plus the residual coverage fix (Dependabot + CI for `mobile/` and Dependabot for `lecture-builder/`).
- **Audience:** The next session that is about to re-audit dependencies or tick Wave 4's exit line.
- **Format/contract:** Verdict first; measured probe table; historical-CVE table with current lock versions; abandoned vs stale split; explicit won't-fix list.

## Provenance

- **Subject created:** 14-08-2026, [H2479 (Grok 4.6) — SECURITY Wave4: dependency posture review](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2479-Grok_Systema-Sanscriticum_security-w4-dependency-posture-review_08.08.26.md), Grok 4.6 (`grok-4.6`).
- **Parent:** [docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md) Wave 4 checkbox "Dependency posture review".
- **Checks this pass:** `composer audit --locked`; root + mobile `npm audit --package-lock-only`; Dependabot API (0 open / 40 fixed); Packagist `p2` abandoned flag for every direct Composer dep.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Replace `jenssegers/agent` (last tag 13-06-2020) | Stale UA parser; not abandoned, not a CVE | parked (won't-fix this pass; telemetry-only caller) |
| 2 | Add `pip-audit` CI for `lecture-builder/requirements.txt` | Sidecar had 11 historical pip alerts | parked (Dependabot pip now covers version + security PRs) |
| 3 | Retire `vcs` Filament forks | Branch-head installs, not tags | owned by H2529, not this doc |

## Known limitations / caveats

- Snapshot of `origin/main` at `520bbbad` (v1.89.29) on 14-08-2026. A later Dependabot Monday scan can open a new alert; this file does not auto-update.
- Packagist `abandoned` is not the same as "no commits in years". The stale table is the honest remainder.
- `lecture-builder/` is not production Laravel. A clean pip tree there does not speak to the money core.

## Intended use / known misuse

- **For:** citing why the Wave 4 dependency checkbox is ticked; seeing which historical CVEs already closed and at which lock version.
- **Misuse:** treating the empty open-CVE table as a licence to skip `composer audit` on the next upgrade; re-opening Filament 3→5 or Guzzle 7→8 under this report's name.

## Maintenance & sunset plan

- **Kept alive by:** a future posture re-run (new CVE class, or after the H2529 forks retire) appending a dated section rather than a new filename.
- **Archived/ended looks like:** Wave 4 exit closed (this item + H2480) and a newer dated review supersedes it.

## Deprecation status

`active`

## Related documents

- [docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md)
- [docs/LARAVEL_10_TO_12_UPGRADE_SECURITY_NOTES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LARAVEL_10_TO_12_UPGRADE_SECURITY_NOTES.md)
- [docs/SECURITY_ROADMAP.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.meta.md)

## Revision history

| Date | Event | Who |
|---|---|---|
| 14-08-2026 | metadoc created with the first posture review | Grok 4.6 `grok-4.6` |

_Dr. Mārcis Gasūns_
