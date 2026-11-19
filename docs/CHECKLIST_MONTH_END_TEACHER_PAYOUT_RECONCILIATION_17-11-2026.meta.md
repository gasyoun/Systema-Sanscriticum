# CHECKLIST_MONTH_END_TEACHER_PAYOUT_RECONCILIATION_17-11-2026.md — metadoc

_Created: 17-11-2026 · Handoff: [H8396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H8396-OxAlpha_Systema-Sanscriticum_checklist-gap-census-top5_17.11.26.md)_

- **What:** месячная сверка выплат: `teacher_payouts` ↔ Xoom CSV ↔ Maria-sheet/ИП-книга; тройной totals-матч, FIFO-авансы с cap, уникальность `settlement_key`, три класса расхождений с владельцем.
- **Method:** входы локализованы по коду origin/main (миграция teacher_payouts, storage/imports/xoom/, бухгалтерский гайд + payout-attribution-guide); классы расхождений согласованы с контуром teacher-payout-record и маршрутами R1–R8 чеклиста H5635.
- **Verifier:** пути входов существуют на origin/main; первый реальный прогон сверки — приёмка самой записки PAYOUT_RECON_YYYY-MM.md MG (R12); дальнейшие прогоны — самоверка по чеклисту.
- **Residual:** Xoom CSV-импортёр (если нужен полуавтоматический матчинг) — отдельный юнит; сейчас сверка ручная по чеклисту.
