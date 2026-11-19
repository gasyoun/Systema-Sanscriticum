# CHECKLIST_MONEY_WEBHOOK_CHANGE_AND_IDEMPOTENCY_17-11-2026.md — metadoc

_Created: 17-11-2026 · Handoff: [H8396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H8396-OxAlpha_Systema-Sanscriticum_checklist-gap-census-top5_17.11.26.md)_

- **What:** чеклист изменений webhook-поверхности: глубокая секция для money-критичных (Tochka, PayPal, exam-scores) — подпись fail-closed, идемпотентность, replay-тест, ротация секрета; лёгкая секция для нотификационных транспортов; карта всех 10 роутов с указанием подписи/дедупа.
- **Method:** инвентаризация роутов/контроллеров/middleware origin/main 17-11-2026 (10 вебхуков); дефект найден при составлении: VK и vk-magnet без явного per-event дедупа — остаток заведён GTD-строкой в том же проходе (write-by-default).
- **Verifier:** соответствие карты коду проверено по свежему origin/main; PR-diff — приёмка MG (R12); replay-пункты исполняются на стенде при каждом применении чеклиста.
- **Residual:** VK/vk-magnet дедуп-починка — отдельный engineering-юнит (GTD, не в этом H).
