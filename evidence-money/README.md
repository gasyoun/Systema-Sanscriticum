# Evidence money витрина (H6326)

Static money dashboard over Systema MySQL, built with [evidence.dev](https://evidence.dev) (MIT).
**SQL-first and regenerated — never hand-edited** (same philosophy as GTD_BOARD).

## Money-canon (FINDINGS §1580)

Every revenue figure here carries the full canon filter:

```sql
status = 'paid'
AND first_paid_at IS NOT NULL            -- first_paid_at, never created_at
AND tariff NOT IN ('Расход', 'salary_payout')
```

Both pseudo-payment families (`Расход` = expenses, `salary_payout` = teacher
payouts since 2025-06) MUST be excluded — otherwise a month flips negative
(Sept-2026 was "−1.68M" without the filter, real +₽722k).

## Files

- `sources/systema/*.sql` — the money-canon MIRROR (revenue by month, teacher
  payouts, per-course unit economics). Semantics frozen; changes go through a
  money-class handoff. Evidence 40.1.8 binds page queries from inline fences,
  so the executing copy lives in `pages/index.md` — keep the two textually
  identical; `bin/parity_check.sh` guards the numbers end-to-end.
- `sources/systema/connection.yaml.example` — template. The real
  `connection.yaml` is gitignored and exists only on vps92
  (`/opt/evidence-money/sources/systema/connection.yaml`, root 0600) with a
  **read-only** MySQL user (`evidence_ro`, SELECT-only on `laravel`).
- `pages/index.md` — the витрина page (executing canon SQL inline).
- `evidence.config.yaml` — Evidence project config (plugin registration).
- `bin/build.sh` — server-side rebuild (runs on vps92 from cron; fails loud
  if the fixed parity figure vanishes from the output).
- `bin/parity_check.sh` — page figure vs §1580 canon SQL for a named month
  (must be ≤ 1 RUB apart).
- `deploy/nginx-evidence-money.conf` — nginx vhost: private LAN IP + basic
  auth only (152-FZ fence).

## Server layout (vps92)

- Project: `/opt/evidence-money` (rsync of this dir, real `connection.yaml` on top)
- Static build output: `/opt/evidence-money/build`
- Nginx: `sites-available/evidence-money` → binds the private LAN IP
  (192.168.200.92:8420, no public exposure) + basic auth
  (`/etc/nginx/evidence-money.htpasswd`). From a tailnet machine:
  `ssh -L 8420:192.168.200.92:8420 root@100.85.73.83`, then
  `http://127.0.0.1:8420`. Plaintext auth note for MG:
  `/root/.evidence-money.auth` (root-only).
- Nightly rebuild: line in the managed crontab
  (`scripts/server_guards/cron/root.crontab`), 04:40 box time.

## Local check

```sh
cp sources/systema/connection.yaml.example sources/systema/connection.yaml
# fill in read-only creds, then:
npm install && npm run build   # output in build/
```
