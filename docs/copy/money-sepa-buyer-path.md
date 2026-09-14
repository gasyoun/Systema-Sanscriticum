# SEPA/SWIFT путь оплаты (bank claim) — H3497

_Created: 25-08-2026 · Last updated: 25-08-2026_

_Исполнитель: OxAlpha (`x-preview-f-free`), 25-08-2026. Рулинг MG 25-08: третий
контур оплат — банковский перевод на внешний счёт получателя школы за рубежом
(SEPA в Австрию). Сестринская дока PayPal-пути:
[money-diaspora-paybal-buyer-path.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-diaspora-paypal-buyer-path.md)._

## Что построено

Зеркало PayPal-claim без PayPal-специфики:

| Item | Value |
|---|---|
| Флаг | `BANK_CLAIM_ENABLED` **default OFF** — маршруты `/bank/{tariff}` отвечают 404 до включения |
| Реквизиты | только из env: `BANK_RECIPIENT_NAME` / `BANK_RECIPIENT_IBAN` / `BANK_RECIPIENT_BIC` / `BANK_RECIPIENT_BANK_NAME` (не хардкод) |
| Путь ученика | шаг 1 перевод по реквизитам → шаг 2 заявка на `/bank/{tariff}`: отправитель, дата валютирования, сумма+валюта (EUR/USD/GBP), референция (типа `FT262308KY5X`), опц. скрин выписки |
| Платёж | обычный `Payment`, provider `bank_sepa`, статус `pending` (гость) / сразу `paid` (свой вошедший ученик, зеркало рулинга 22-08-2026, флаг `BANK_TRUST_EXISTING_STUDENTS`) |
| Reaper | провайдер в `MANUAL_CLAIM_PROVIDERS` — payments:expire-stale-checkouts не трогает |
| Админ | Filament → Платежи: фильтры «SEPA-заявки на проверке» / «SEPA: без сверки», кнопки «Подтвердить перевод» / «Сверка пройдена» / «Нет платежа — отменить»; скрины приватно через существующий proof-роут (disk `local`) |
| Уведомления | кураторам Telegram (`CuratorNotifier::bankClaimReceived`), админу `BankClaimReceivedMail`, студенту `BankClaimStudentAckMail` (trusted/guest варианты) |
| Тесты | `php artisan test --filter=BankClaimTest` (7: flag-off 404, форма с реквизитами, гостевой pending, trusted-paid+доступ, будущая дата отклонена, чужой email отклонён, reaper-безопасность); смежные Paypal/Invoice зелёные |

## Включение на проде (только по слову MG)

1. В prod `.env` (.92): `BANK_CLAIM_ENABLED=true` + четыре `BANK_*` переменные реквизитов.
2. `php artisan optimize:clear`.
3. Smoke: `GET /bank/{tariff_id}` гость = 200 с реквизитами; тестовая заявка → pending в Filament.

## Открытые вопросы (за MG)

- Атрибуция «ученик курса X платит получателю Лейтана»: пока платёж учится как
  обычная выручка школы; зачёт против выплат Лейтану (offset в payroll) —
  отдельное решение, в форму сознательно не зашито.
- Валютный прайс по курсам (как `foreign_block_prices` у PayPal) — добавим,
  когда утвердите суммы.

_Dr. Mārcis Gasūns_
