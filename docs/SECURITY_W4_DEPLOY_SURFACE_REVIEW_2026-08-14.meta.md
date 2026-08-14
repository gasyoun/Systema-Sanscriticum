# SECURITY_W4_DEPLOY_SURFACE_REVIEW_2026-08-14.meta.md

_Created: 14-08-2026 · Last updated: 14-08-2026_

Companion metadoc for
[docs/SECURITY_W4_DEPLOY_SURFACE_REVIEW_2026-08-14.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_W4_DEPLOY_SURFACE_REVIEW_2026-08-14.md).

## Purpose

Dated evidence packet for SECURITY_ROADMAP Wave 4 deploy-surface: prove
`deploy.sh` / `docker-compose.yml` / CI deploy do not echo secrets, and that
prod secrets come only from a non-committed `.env`.

## Audience

Agents closing or re-opening the Wave 4 checkbox; anyone about to add an
`echo` or a compose healthcheck that interpolates `${DB_PASSWORD}`.

## Provenance

- **Handoff:** [H2480 (Grok) — SECURITY Wave4 deploy-surface review](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2480-Grok_Systema-Sanscriticum_security-w4-deploy-surface-review_08.08.26.md)
- **Executor:** Grok 4.6 (`grok-4.6`)
- **Method:** read the tracked scripts on a worktree off `origin/main`; one
  healthcheck fix; file-shape PHPUnit gate.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Healthcheck that never puts the password on argv | Process-list residual on Sail MySQL | parked (local-dev only; prod unused) |
| 2 | Re-read after any `deploy.sh` / `deploy.yml` rewrite | Script drift would silently reopen a log echo | parked (regression test covers the known shapes) |

## Limitations

- File-shape tests, not a live `docker compose config` with a real host `.env`.
- Does not audit n8n compose on `193.232.229.91` (different host, `/n8n` skill).
- Wave 4 dependency posture is H2479 ([PR #1671](https://github.com/gasyoun/Systema-Sanscriticum/pull/1671)), closed the same day; this packet only covers deploy-surface.

## Related documents

- [docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md)
- [docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md)
- [SECURITY.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/SECURITY.md)

## Revision history

| Date | Event | Who |
|---|---|---|
| 14-08-2026 | First review + healthcheck fix | Grok 4.6 `grok-4.6` |

_Dr. Mārcis Gasūns_
