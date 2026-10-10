-- H6326 — Per-course unit economics over the money-canon filter
-- (FINDINGS §1580): paid + first_paid_at + tariff NOT IN exclusion set.
-- CANON MIRROR of the executing fence in pages/index.md — keep identical.
SELECT
  p.course_id                      AS course_id,
  COALESCE(c.title, '(no course)') AS course_title,
  COUNT(*)                         AS payments,
  ROUND(SUM(p.amount), 0)          AS revenue_rub,
  ROUND(AVG(p.amount), 0)          AS avg_check_rub
FROM payments p
LEFT JOIN courses c ON c.id = p.course_id
WHERE p.status = 'paid'
  AND p.first_paid_at IS NOT NULL
  AND p.tariff NOT IN ('Расход', 'salary_payout')
GROUP BY p.course_id, c.title
ORDER BY revenue_rub DESC;
