# Soft-remediate dry-run fixtures (H2187)

_Created: 02-08-2026 · Last updated: 02-08-2026_

Committed inputs for **deterministic dry-run** coverage of
`php artisan ops:soft-remediate` / `SoftAutoDeployRemediator`.
Companion of [H2148](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2148-Grok_Systema-Sanscriticum_soft-alert-auto-remediate-abc_02.08.26.md)
(command + remediator) and residual
[H2187](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2187-Grok_Systema-Sanscriticum_soft-remediate-dry-run-fixtures_02.08.26.md).

## Layout

| Path | Role |
|---|---|
| `breakers/*.txt` | Last-line contents for `storage/auto_deploy.disabled` (soft tags vs hard) |
| `expected/*.json` | Dry-run **contracts** (status, exit_code, action types) — not full tree dumps |
| PHPUnit | `tests/Feature/SoftRemediateDryRunFixturesTest.php` builds a temp git repo, seeds from these files, asserts **no mutation** on dry-run |

Git dirty/origin-equal scenarios cannot be committed as a real repo (refs drift).
Tests still materialize a temp clone and only **seed breaker + path content** from fixtures.

## Operator smoke (no prod side effects)

```sh
# Unit + fixture dry-run suite (local / CI)
php artisan test --filter=SoftRemediate

# Artisan dry-run against THIS checkout (never applies)
php artisan ops:soft-remediate --dry-run --json
```

Prod apply remains explicit: `--apply` / `--apply-breaker-clear` only after reviewing dry-run JSON.
Playbook: [docs/SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) §4.0.

_Dr. Mārcis Gasūns_
