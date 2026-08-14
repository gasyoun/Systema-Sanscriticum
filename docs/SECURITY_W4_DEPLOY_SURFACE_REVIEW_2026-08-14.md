# SECURITY Wave 4 — deploy-surface review (H2480)

_Created: 14-08-2026 · Last updated: 14-08-2026_

Dated findings for the Wave 4 **Deploy-surface review** checkbox in
[docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md).
Executor: Grok 4.6 (`grok-4.6`). Every row was read from the tracked file on
`origin/main` (then this branch), not from memory.

**Scope:** [`deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh),
[`docker-compose.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docker-compose.yml)
(Sail local-dev only — production is bare-metal LXC + `deploy.sh`),
[`.github/workflows/deploy.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/deploy.yml),
[`.github/workflows/ci.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/ci.yml),
[`app/Console/Commands/WebhookSecretsPreflight.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/WebhookSecretsPreflight.php),
[`scripts/server_guards/sbin/systema-auto-deploy-run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/systema-auto-deploy-run.sh),
[`.env.example`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.env.example),
[`.gitignore`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.gitignore).

## Verdict

**PASS after one fix.** Production secrets come only from a non-committed `.env`.
`deploy.sh` and the CI deploy workflow do not echo secret values. The Sail
healthcheck used to interpolate `${DB_PASSWORD}` at compose-parse time (so
`docker compose config` would print it); that command now expands
`$MYSQL_ROOT_PASSWORD` inside the container.

Wave 4's other remaining item, [H2479 (Grok) — SECURITY Wave4 dependency posture review](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2479-Grok_Systema-Sanscriticum_security-w4-dependency-posture-review_08.08.26.md),
closed the same day ([PR #1671](https://github.com/gasyoun/Systema-Sanscriticum/pull/1671)).
With this review the Wave 4 checklist is complete.

## Pass / fail table

| Surface | Before | After | Cite |
|---|---|---|---|
| `deploy.sh` stdout / `storage/logs/deploys.log` | PASS | PASS | Script read in full. Echoes commits, smoke URL, PHP version, `whoami`, dirty paths. No `printenv`, no `cat .env`, no `set -x`, no `$DB_PASSWORD` / `*_SECRET` / `*_TOKEN` in `echo`/`printf`. |
| `deploy.sh` → `php artisan deploy:webhook-preflight` | PASS | PASS | [WebhookSecretsPreflight.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/WebhookSecretsPreflight.php) prints key **names** (`ZOOM_WEBHOOK_SECRET is required`) and `OK`/`FAILED`. Never prints config values. Covered by [tests/Feature/WebhookSecretsPreflightTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/WebhookSecretsPreflightTest.php). |
| `docker-compose.yml` service `environment:` | PASS | PASS | `${DB_PASSWORD}` / `${DB_USERNAME}` / `${DB_DATABASE}` come from the host `.env`. Compose is Sail local-dev, not the prod path. |
| `docker-compose.yml` MySQL `healthcheck.test` | **FAIL** | PASS | Was `mysqladmin ping -p${DB_PASSWORD}` (compose-time interpolation). Now `CMD-SHELL` with `$$MYSQL_ROOT_PASSWORD`. |
| `.env` tracked in git | PASS | PASS | `git ls-files` on `origin/main`: only `.env.example`. `.gitignore` lists `.env`, `.env.backup`, `.env.production`, `.env.*.local`. |
| `.env.example` values | PASS | PASS | `APP_KEY=`, `DB_PASSWORD=`, `AWS_SECRET_ACCESS_KEY=` empty. Placeholders are names, not credentials. |
| `.github/workflows/deploy.yml` | PASS | PASS | Emptiness checks on `${{ secrets.DEPLOY_* }}`; SSH key lands via `printf … > ~/.ssh/deploy_key` (never `echo`). GitHub Actions masks secret expansions in logs. |
| `.github/workflows/ci.yml` MySQL service | PASS | PASS | Dummy `systema` / `root` for the finance-job container. Not prod secrets. |
| `systema-auto-deploy-run.sh` | PASS | PASS | Logs SHAs, smoke codes, breaker reasons. Does not read or print `.env`. |

## What was fixed

The Sail MySQL healthcheck in
[docker-compose.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docker-compose.yml)
used the stock Laravel Sail form `["CMD", "mysqladmin", "ping", "-p${DB_PASSWORD}"]`.
Compose interpolates `${DB_PASSWORD}` when it **parses** the file, so:

- `docker compose config` printed the host `.env` password in the healthcheck argv
- `docker inspect` stored that same resolved string

The replacement keeps the same ping, but the password token is `$$MYSQL_ROOT_PASSWORD`
so compose writes a literal `$MYSQL_ROOT_PASSWORD` and the container shell expands
the env var already injected as `MYSQL_ROOT_PASSWORD: '${DB_PASSWORD}'`.

Regression:
[tests/Unit/DeploySurfaceSecretsTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Unit/DeploySurfaceSecretsTest.php).

## Residuals (not FAIL)

| Residual | Why it stays |
|---|---|
| `docker compose config` still shows `MYSQL_ROOT_PASSWORD` / `MYSQL_PASSWORD` under `environment:` | Inherent to passing the password into the container. Local Sail only. Prod does not use this compose file. |
| `mysqladmin` process list inside the container still sees `-p<value>` after shell expansion | Process-list leak on a localhost-bound Sail MySQL, not a deploy log. A wrapper that reads the env without argv would be a later hardening, not this unit. |
| `php artisan optimize` writes `bootstrap/cache/config.php` including secret config | Laravel default. Not a log echo. File mode is the host's. |
| GitHub Actions step-debug can expand `${{ secrets.* }}` in the rendered script | Platform still redacts known secret values in logs. Do not enable debug on `production`. |
| Wave 4 **Dependency posture** (H2479) | Closed the same day in [PR #1671](https://github.com/gasyoun/Systema-Sanscriticum/pull/1671). Not re-litigated here. |

## Sibling note

No other `docker-compose.yml` under this GitHub org checkout used the same
`-p${DB_PASSWORD}` healthcheck. Nothing to port.

_Dr. Mārcis Gasūns_
