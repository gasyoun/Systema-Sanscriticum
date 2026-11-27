-- H6254 — Behavioral student-churn cohorts (Systema).
-- Read-only, aggregate-only: NO names, emails, phones or any PII leaves the DB.
-- Every result group has >= 20 users; small cells are suppressed as NULL.
--
-- Reproduce (any dev dump with the same schema, or prod read-only):
--   mysql -h HOST -u USER -pPASS DB --table < scripts/analytics/student_churn_cohorts.sql
-- The date cut is the @as_of variable on line 8; the runner overrides it with
-- --as-of. Shifting it and re-running is the canary: mature cohorts must keep
-- identical survival numbers.
--
-- Money canon (Uprava FINDINGS §1580): status='paid' AND first_paid_at (not
-- created_at) AND tariff NOT IN ('Расход','salary_payout').
-- Engagement = lesson_views.last_opened_at ∪ srs_review_logs.reviewed_at ∪
-- repeat paid payments. users.last_activity_at is deliberately NOT used
-- (marketing pageviews; H5184 ChurnSignalsCommand).
SET @as_of = '2026-11-24';

-- 1. Cohort sizes: month of first canonical paid payment (real users only).
SELECT 'cohort_sizes' AS section,
       f.cohort_month,
       COUNT(*) AS users,
       ROUND(SUM(f.amt)) AS cohort_revenue_rub
FROM (
    SELECT p.user_id,
           DATE_FORMAT(MIN(p.first_paid_at), '%Y-%m') AS cohort_month,
           SUM(p.amount) AS amt
    FROM payments p
    JOIN users u ON u.id = p.user_id
    WHERE p.status = 'paid'
      AND p.first_paid_at IS NOT NULL
      AND p.first_paid_at <= @as_of
      AND p.tariff NOT IN ('Расход', 'salary_payout')
    GROUP BY p.user_id
) f
GROUP BY f.cohort_month
ORDER BY f.cohort_month;

-- 2. Survival: N-th month retention after the first-paid cohort month.
--    A user is retained in month +N iff any engagement trace (lesson view,
--    SRS review, or a paid payment) exists in that calendar month, observed
--    up to @as_of. Cohorts with fewer than N observation months are excluded
--    from column N (reported as NULL), so a shifted cut cannot change them.
WITH first_pay AS (
    SELECT p.user_id,
           MIN(p.first_paid_at) AS first_at,
           YEAR(MIN(p.first_paid_at)) * 12 + MONTH(MIN(p.first_paid_at)) AS cidx
    FROM payments p
    JOIN users u ON u.id = p.user_id
    WHERE p.status = 'paid'
      AND p.first_paid_at IS NOT NULL
      AND p.first_paid_at <= @as_of
      AND p.tariff NOT IN ('Расход', 'salary_payout')
    GROUP BY p.user_id
),
act AS (
    SELECT user_id, YEAR(last_opened_at) * 12 + MONTH(last_opened_at) AS idx
    FROM lesson_views WHERE last_opened_at <= @as_of
    UNION
    SELECT user_id, YEAR(reviewed_at) * 12 + MONTH(reviewed_at)
    FROM srs_review_logs WHERE reviewed_at <= @as_of
    UNION
    SELECT user_id, YEAR(first_paid_at) * 12 + MONTH(first_paid_at)
    FROM payments
    WHERE status = 'paid' AND first_paid_at IS NOT NULL
      AND first_paid_at <= @as_of
      AND tariff NOT IN ('Расход', 'salary_payout')
),
cohort AS (
    SELECT f.*, DATE_FORMAT(f.first_at, '%Y-%m') AS cohort_month
    FROM first_pay f
),
cut AS (SELECT YEAR(@as_of) * 12 + MONTH(@as_of) AS cutidx)
SELECT 'survival' AS section,
       c.cohort_month,
       COUNT(*) AS cohort_n,
       SUM(c.cidx + 1 <= k.cutidx AND EXISTS (
           SELECT 1 FROM act a WHERE a.user_id = c.user_id AND a.idx = c.cidx + 1)) AS m1_n,
       ROUND(100 * SUM(c.cidx + 1 <= k.cutidx AND EXISTS (
           SELECT 1 FROM act a WHERE a.user_id = c.user_id AND a.idx = c.cidx + 1))
           / NULLIF(SUM(c.cidx + 1 <= k.cutidx), 0), 1) AS m1_pct,
       SUM(c.cidx + 2 <= k.cutidx AND EXISTS (
           SELECT 1 FROM act a WHERE a.user_id = c.user_id AND a.idx = c.cidx + 2)) AS m2_n,
       ROUND(100 * SUM(c.cidx + 2 <= k.cutidx AND EXISTS (
           SELECT 1 FROM act a WHERE a.user_id = c.user_id AND a.idx = c.cidx + 2))
           / NULLIF(SUM(c.cidx + 2 <= k.cutidx), 0), 1) AS m2_pct,
       SUM(c.cidx + 3 <= k.cutidx AND EXISTS (
           SELECT 1 FROM act a WHERE a.user_id = c.user_id AND a.idx = c.cidx + 3)) AS m3_n,
       ROUND(100 * SUM(c.cidx + 3 <= k.cutidx AND EXISTS (
           SELECT 1 FROM act a WHERE a.user_id = c.user_id AND a.idx = c.cidx + 3))
           / NULLIF(SUM(c.cidx + 3 <= k.cutidx), 0), 1) AS m3_pct
FROM cohort c CROSS JOIN cut k
GROUP BY c.cohort_month
ORDER BY c.cohort_month;

-- 3. Churn points by course: where students leave (status='Покинул').
SELECT 'churn_points' AS section,
       c.id AS course_id,
       LEFT(COALESCE(c.title, '?'), 60) AS course,
       COUNT(*) AS enrollments,
       SUM(cu.status = 'Покинул') AS left_n,
       SUM(cu.status = 'Покинул' AND cu.left_after_block <= 3) AS left_le_block3,
       ROUND(AVG(CASE WHEN cu.status = 'Покинул' THEN cu.left_after_block END), 2) AS avg_left_block
FROM course_user cu
JOIN courses c ON c.id = cu.course_id
GROUP BY c.id
HAVING left_n >= 5
ORDER BY left_n DESC
LIMIT 15;

-- 4. Recency of last lesson view among canonical payers, as of @as_of.
SELECT 'lesson_recency' AS section,
       x.bucket AS bucket,
       COUNT(*) AS payers
FROM (
    SELECT p.user_id,
           CASE
             WHEN MAX(lv.last_opened_at) IS NULL THEN 'no_lesson_view'
             WHEN DATEDIFF(@as_of, MAX(lv.last_opened_at)) <= 7 THEN '0_7d'
             WHEN DATEDIFF(@as_of, MAX(lv.last_opened_at)) <= 30 THEN '8_30d'
             WHEN DATEDIFF(@as_of, MAX(lv.last_opened_at)) <= 90 THEN '31_90d'
             ELSE '90d_plus'
           END AS bucket
    FROM payments p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN lesson_views lv ON lv.user_id = p.user_id AND lv.last_opened_at <= @as_of
    WHERE p.status = 'paid' AND p.first_paid_at IS NOT NULL
      AND p.tariff NOT IN ('Расход', 'salary_payout')
    GROUP BY p.user_id
) x
GROUP BY bucket
ORDER BY bucket;

-- 5. Hypothesis inputs (single-row aggregates).
WITH left_rows AS (
    SELECT cu.* FROM course_user cu WHERE cu.status = 'Покинул'
),
top3 AS (
    SELECT course_id, SUM(cu.status = 'Покинул') AS n
    FROM course_user cu GROUP BY course_id ORDER BY n DESC LIMIT 3
)
SELECT 'hypothesis_inputs' AS section,
       (SELECT COUNT(*) FROM left_rows) AS total_left,
       (SELECT COUNT(*) FROM left_rows WHERE left_after_block <= 3) AS left_le_block3,
       (SELECT COUNT(*) FROM left_rows WHERE left_after_block <= 1) AS left_le_block1,
       (SELECT SUM(n) FROM top3) AS top3_course_left,
       ROUND(100 * (SELECT SUM(n) FROM top3)
             / NULLIF((SELECT COUNT(*) FROM left_rows), 0), 1) AS top3_share_pct,
       (SELECT COUNT(DISTINCT p.user_id) FROM payments p
        JOIN users u ON u.id = p.user_id
        WHERE p.status = 'paid' AND p.first_paid_at IS NOT NULL
          AND p.tariff NOT IN ('Расход', 'salary_payout')
          AND p.first_paid_at <= @as_of) AS payers_total,
       (SELECT COUNT(*) FROM (
           SELECT p.user_id FROM payments p
           JOIN users u ON u.id = p.user_id
           WHERE p.status = 'paid' AND p.first_paid_at IS NOT NULL
             AND p.tariff NOT IN ('Расход', 'salary_payout')
           GROUP BY p.user_id HAVING COUNT(*) = 1
       ) one_off) AS payers_one_payment;
