_Created: 23-08-2026 · Last updated: 05-09-2026_

# H3334 — Gift certificates: тариф «в подарок» + код активации — датированный отчёт

_Created: 23-08-2026 · Executor: OxAlpha (`x-preview-f-free`) · Handoff: [H3334](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3334-OxAlpha_Systema-Sanscriticum_gift-certificates-gift-tariff-activation-code_22.08.26.md)_

## Что построено

Механизм подарочных сертификатов поверх существующей тарифной модели ([MONETIZATION_PLAN_2026H2 §9](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONETIZATION_PLAN_2026H2.md)). Всё за флагом **`GIFT_CERTIFICATES` default OFF** — прод-инертно до включения человеком.

1. **Покупка** — обычный разовый чекаут в режиме «подарить» (`/checkout/{tariff}?gift=1`): та же цена/промо/прана-пайплайн `PaymentController::createPayment`, но `payments.tariff='gift'` + снимок underlying-тарифа (`gift_tariff_key` = `Tariff::accessKey()`, title, блоки bundle) в `claim_meta`. Покупателю доступ НЕ открывается: в `Payment::fireOnPaid()` ветка `isGiftCertificate()` (паттерн deposit/trial/marathon) уводит платёж из `processSuccessfulPayment`.
2. **Выпуск сертификата** по paid: строка `gift_certificates` — sha256-хэш одноразового кода (`GIFT-XXXX-XXXX-XXXX-XXXX`, 29-символьный алфавит без 0/O/1/I/L, ~79 бит; сырой код существует только в памяти и одном письме покупателю), публичный номер `GC-<random12>` для верификации, hint (последние 4 знака) для поддержки. Идемпотентно по payment_id.
3. **Активация получателем** `/gift/activate` (только залогиненный, POST throttle 10/min): под row-lock создаёт получателю обычный оплаченный Payment с ключом доступа подаренного тарифа и `amount=0`, `deposit_credit_applied=0` — группы/запись на курс/членство/письма выдаёт штатный `PaymentObserver`-контур. Доступ НЕ мимо тарифной модели структурно невозможен. Повторная активация отвергается («уже активирован»).
4. **PDF-артефакт** через готовый DomPDF-контур (`certificates/gift.blade.php`, QR с api.qrserver.com c TLS-верификацией — как в `CertificateService`). На повторной генерации PDF сырой код не печатается никогда. Скачивание: покупатель или активировавший получатель.
5. **Публичная верификация** `/gift/verify/{number}` — источник правды: статус (действителен / активирован / отозван), что подарено, номер; без персональных данных.
6. **Возврат оплаты** покупателя (paid → canceled) отзывает неактивированный сертификат (`revokeForPayment` в reversal-ветке `Payment::booted()`); активированный задним числом не трогается.
7. **Репетиция e2e**: `php artisan gift-certificates:rehearse` — вся цепочка сервисного слоя внутри одной транзакции с откатом; письма уходят в log-драйвер, очереди в null. Работает на проде без побочных эффектов.

## Evidence

### E2e-лог: покупка → код → активация → доступ (+ одноразовость)

`gift-certificates:rehearse` 23-08-2026, локальный прогон (sqlite, миграции все зелёные):

```
[PASS] 1. покупка (pending gift-платёж) — payment #1, tariff=gift, claim_meta со снимком тарифа
[PASS] 2. выпуск сертификата — number=GC-GZBHKLULHWUF, hash=sha256(код) ok, hint=DDDD
[PASS] 3. покупателю доступ НЕ открыт — групп у покупателя: 0 (ожидание 0)
[PASS] 4. активация получателем — статус=activated, получатель #2
[PASS] 5. доступ получателя через тарифную модель — группы: 1/1, записан на курс: да
[PASS] 6. одноразовость кода — повторная активация отклонена («Этот сертификат уже активирован…»); второй юзер групп не получил: да
[PASS] 7. нормализация ввода — регистр/дефисы/пробелы не влияют; разные коды не совпадают
[PASS] 8. PDF-артефакт — DomPDF отрендерил 34571 байт
[PASS] 9. отзыв при возврате оплаты — canceled → статус=revoked; активация отозванного отвергнута
Итого: 9 PASS, 0 FAIL.
```

### Тесты

`php artisan test --filter=GiftCertificateTest` — **11 passed, 46 assertions** (флаг OFF игнорирует gift=1 байт-в-байт; snapshot в claim_meta; выпуск без доступа покупателя и без сырого кода в БД; активация → группы+pivot+recipient-payment с accessKey; одноразовость; неверный код; гостевой redirect; 404 при флаге OFF; верификация без PII; отзыв при возврате; идемпотентный выпуск). Регрессия money-core: CheckoutPriceTest + ConditionalGrantReconciliationTest + AutoEnrollOnPaymentTest + Deposit/Trial + Payment/Webhook-наборы — 288+ тестов зелёные. Pint чист.

### PDF + публичная верификация

DomPDF рендерит A4 landscape бланк с QR на `/gift/verify/{number}` (шаг 8 репетиции); верификационная страница показывает статус/номинал/номер и «не найден» для чужих номеров (тест `verify_page_shows_certificate_status_without_personal_data`).

## Owner-side (после merge — по порядку)

1. Ревью цен номиналов (**money row MG**): подарок зеркалит цену underlying-тарифа; отдельные подарочные ценники/номиналы — только явным решением MG. Анти-срочность соблюдена: никаких дедлайн-механик в продающем контуре.
2. Прод-e2e по приёмке хендоффа (staff-платёж или rehearsal на проде → код → второй тестовый юзер → доступ → PDF/верификация): `GIFT_CERTIFICATES=true` в .env → `php artisan config:cache` → `php artisan gift-certificates:rehearse` → живой чекаут по ссылке.
3. Лендинг-блок «подарить» на витрине (сейчас — переключатель на чекауте): копирайт без срочности, ссылка `?gift=1`.

## Границы

- Никакого нового рекуррентного пути: подарок — разовый платёж; членство-подарок идёт через существующий `ClubMembershipService::syncFromPayment`.
- Сырой код нигде не логируется и не коммитится; Telegram-сообщение покупателю — указатель без кода.
- Filament-ресурса сертификатов нет (минимальная подача); поиск поддержки — по payment_id/code_hint/публичному номеру.

_Dr. Mārcis Gasūns_
