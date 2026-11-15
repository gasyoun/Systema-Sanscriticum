# ADR 0003: Teacher rates are a generated canon, never hand-edited

`config/teacher_rates.php` is generated from Uprava `data/teacher_rate_timelines.json` (hash `584b7e82…`, `tools/gen_teacher_rates_config.py`, H3532). Hand-edits vanish on regen; the payout canon is era-scoped (bank slice only when a period carries `bank_slice_pct`).

Status: accepted 09-11-2026 (grill-with-docs).

Consequences: rate disputes are resolved in Uprava timelines, not in Systema code; historical recompute must respect eras or it silently rewrites payouts.

_Created: 09-11-2026 · Last updated: 09-11-2026_

_Dr. Mārcis Gasūns_
