-- H6326 — Revenue by month. MONEY-CANON (Uprava FINDINGS §1580):
--   status = 'paid'  AND  first_paid_at (NOT created_at)  AND
--   tariff NOT IN ('Расход','salary_payout')  — both pseudo-payment
--   families (expenses + teacher payouts) MUST be excluded or a month
--   flips negative. Semantics of this file are frozen; do not edit
--   without a money-class handoff.
--
-- This file is the CANON MIRROR. Evidence 40.1.8 binds page queries from
-- inline fences only, so the executing copy lives in pages/index.md; this
-- mirror must stay textually identical (parity_check.sh 2026-09 guards the
-- numbers end-to-end). Single-source-of-truth ruling: edit BOTH or neither.
SELECT
  DATE_FORMAT(first_paid_at, '%Y-%m') AS month,
  COUNT(*)                            AS payments,
  ROUND(SUM(amount), 0)               AS revenue_rub,
  ROUND(SUM(amount), 2)               AS revenue_rub_exact
FROM payments
WHERE status = 'paid'
  AND first_paid_at IS NOT NULL
  AND tariff NOT IN ('Расход', 'salary_payout')
GROUP BY DATE_FORMAT(first_paid_at, '%Y-%m')
ORDER BY month;
