# Платёжная логика, волна 1: идемпотентность PayPal-заявки, возвраты в PayPal-вебхуке, guard unique-индекса обещаний, окно payout-run, зачёт аванса (Fable 5.1 `claude-fable-5-1`, 16-09-2026)

H5007 — починка шести policy-free HIGH-строк аудита [AUDIT_PAYMENT_LOGIC_CORRECTNESS_16-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/AUDIT_PAYMENT_LOGIC_CORRECTNESS_16-09-2026.md) (H4992). Флаг `features.payment_fix_wave1` (`PAYMENT_FIX_WAVE1`, по умолчанию ВЫКЛ) — по дисциплине `/money-pr-land`; merge prod-инертен.

- **H2, за флагом:** повторная PayPal-заявка того же ученика по тому же тарифу с тем же txn (или без txn — в тот же день) отклоняется валидацией, второй paid-платёж/выручка/прана не создаются ([PaypalClaimController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaypalClaimController.php)).
- **H3, за флагом:** `PAYMENT.SALE.REFUNDED` / `REVERSED` / `DENIED` переводят платёж подписки в `canceled` (штатный откат доступа, праны, реферала), commitment в `cancelled`/`failed`, при отказе подписка → `past_due` ([PaypalSubscriptionsWebhookController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Webhooks/PaypalSubscriptionsWebhookController.php)).
- **H4, за флагом:** `BILLING.SUBSCRIPTION.UPDATED` берёт статус из `resource.status`; отменённая/завершённая подписка не воскресает (журнал `rejected_resurrection`, паритет с Точкой); событие старше применённого по `update_time` не применяется; поздний `SALE.COMPLETED` активирует только из `pending_first_pay`/`past_due`.
- **H5, без флага (чистый дефект):** `Payment::reconcileConditionalGrants()` пишет `fulfilled_payment_id` ровно в одно обещание, остальные закрываются с `null` — раньше unique-индекс бросал `QueryException` внутри `fireOnPaid` и откатывал честно оплаченный платёж.
- **H6, без флага (чистый дефект):** окно `PayoutRunService` начинается с конца дня отсечки — блок, завершённый в день `since`, больше не суммируется дважды (base + prior).
- **H7:** ручная выплата «Зарплаты» → `TeacherSalaryService::recordManualPayout()` — создание + проводка + зачёт в одной транзакции; за флагом зачёт FIFO не больше суммы выплаты (как у поблочной выплаты), без флага — прежнее полное списание.
- Тесты: 12 новых (`--filter h5007`), по одному-трём на строку, флаг ВЫКЛ запинен отдельно. Вне объёма (ждут рулинга MG): H1 сверка суммы trusted-claim, H8 `GRANT_ACCESS_FAIL_CLOSED` на проде.

_Гасунс_
