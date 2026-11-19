# Source `systema` — Systema MySQL (laravel), read-only.

Copy `connection.yaml.example` to `connection.yaml` with real values.
The real file exists only on vps92 (`/opt/evidence-money/sources/systema/connection.yaml`,
root 0600, user `evidence_ro` — SELECT-only on `laravel`).

## Where the queries live

The money-canon queries are **not** here: this Evidence version (40.1.8) binds
page queries from the project-root `queries/` directory, not from source dirs.
The canon SQL lives in `../../queries/*.sql` (single source of truth, never
duplicated):

- `revenue_by_month.sql` — FINDINGS §1580 canon revenue
- `teacher_payouts_by_month.sql`
- `course_unit_economics.sql`
