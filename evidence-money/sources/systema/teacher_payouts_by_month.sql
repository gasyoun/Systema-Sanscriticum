-- H6326 — Teacher payouts by month (the second pseudo-payment family from
-- FINDINGS §1580: tariff='salary_payout', present since 2025-06). Shown
-- separately so revenue and payouts never cancel inside one figure.
-- CANON MIRROR of the executing fence in pages/index.md — keep identical.
SELECT
  DATE_FORMAT(first_paid_at, '%Y-%m') AS month,
  COUNT(*)                            AS payouts,
  ROUND(SUM(amount), 0)               AS payouts_rub
FROM payments
WHERE status = 'paid'
  AND first_paid_at IS NOT NULL
  AND tariff = 'salary_payout'
GROUP BY DATE_FORMAT(first_paid_at, '%Y-%m')
ORDER BY month;
