---
title: Деньги — витрина (H6326)
---

# Деньги — витрина

Сгенерировано из Systema MySQL при сборке (никогда не редактируется руками).
Канон запроса — Uprava FINDINGS §1580: `status='paid'` + `first_paid_at` +
`tariff NOT IN ('Расход','salary_payout')` — обе семьи псевдо-платежей
(расходы и выплаты учителям) исключены. Выручка и выплаты никогда не
смешиваются в одной цифре. Те же запросы в зеркале: `sources/systema/*.sql`.

```sql revenue_by_month
SELECT
  DATE_FORMAT(first_paid_at, '%Y-%m') AS month,
  COUNT(*)                            AS payments,
  ROUND(SUM(amount), 0)               AS revenue_rub
FROM payments
WHERE status = 'paid'
  AND first_paid_at IS NOT NULL
  AND tariff NOT IN ('Расход', 'salary_payout')
GROUP BY DATE_FORMAT(first_paid_at, '%Y-%m')
ORDER BY month
```

## Выручка по месяцам (канон §1580)

<DataTable data={revenue_by_month}/>

```sql teacher_payouts_by_month
SELECT
  DATE_FORMAT(first_paid_at, '%Y-%m') AS month,
  COUNT(*)                            AS payouts,
  ROUND(SUM(amount), 0)               AS payouts_rub
FROM payments
WHERE status = 'paid'
  AND first_paid_at IS NOT NULL
  AND tariff = 'salary_payout'
GROUP BY DATE_FORMAT(first_paid_at, '%Y-%m')
ORDER BY month
```

## Выплаты учителям по месяцам

<DataTable data={teacher_payouts_by_month}/>

```sql course_unit_economics
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
ORDER BY revenue_rub DESC
```

## Юнит-экономика по курсам

<DataTable data={course_unit_economics}/>
