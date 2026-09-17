# Аудит корректности платёжной логики: 8 HIGH и 15 MEDIUM дефектов в PayPal-claim, PayPal-вебхуке, обещаниях/возвратах и выплатах (Fable 5.1 `claude-fable-5-1`, 16-09-2026)

Ответ на вопрос MG «Payment logic is perfect?» — нет. Read-only аудит четырёх денежных контуров, H4992. Отчёт: [docs/AUDIT_PAYMENT_LOGIC_CORRECTNESS_16-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/AUDIT_PAYMENT_LOGIC_CORRECTNESS_16-09-2026.md).

- **Tochka-контур чист** (подпись, идемпотентность по event_hash, hold≠capture, guard от воскрешения) — перепроверять не нужно, список «verified correct» в отчёте.
- **8 HIGH:** сумма PayPal-claim не сверяется с тарифом и сразу `paid` (trust по умолчанию, на проде включён); claim без идемпотентности; PayPal-вебхук молча игнорирует REFUNDED/REVERSED и воскрешает подписку из UPDATED; `reconcileConditionalGrants` пишет один `fulfilled_payment_id` в два обещания → unique-индекс откатывает оплату; блок на дне `since` считается в выплате дважды; «Зачесть аванс» списывает все авансы целиком; решение H2085 «fail closed» не включено на проде.
- **Код не менялся.** Порядок починки и два policy-вопроса для MG — в отчёте; fix-handoffs минтятся после решения.

_Гасунс_
