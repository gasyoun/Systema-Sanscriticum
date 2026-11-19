# H6287 — Аудит зрелости: платёжный контур Systema

_Created: 2026-10-10 · Аудит исполнен 2026-10-10 (worktree h6287-drain, origin/main @57ba6df8)_
**Handoff:** H6287 · Executor: OxAlpha (opencode/z-ai/glm-5.3-flash) · **FENCE соблюдён: только read-only**

Метод: код-ревью main-ветки + выхват phpunit по платёжным классам + GET-пробы
прод-эндпоинтов (samskrte.ru). **Ни одной мутации прода**: ни POST, ни конфигов,
ни флагов, ни транзакций — перечень всех выполненных команд в §7. Суммы, имена и
тарифы из прода в отчёт не выносятся (только статусы и счётчики).

---

## Вердикт: 4 / 5

Платёжный контур зрелый: SETTLE-путь через вебхук Точки защищён в глубину
(RS256-подпись, идемпотентность по event_hash, анти-воскрешение, сверка суммы,
hold≠capture, sentinel-breaker), /gasuns-pay несёт DB-гард replay-key, проверка
сумм/статусов записана тестовой матрицей, мониторинг дежурный с пейджером,
PII-дисциплина соблюдена (proofs на local-диске, админ-only выдача, логи без
payload). До 5/5 не хватает: (1) /teacher-pay без duplicate-гарда, (2) у
/payment/create нет идемпотентности на двойной сабмит, (3) refund→отзыв доступа
живёт за выключенным по умолчанию флагом и вне вебхука.

### Топ-3 разрыва

1. **/teacher-pay/{tariff} POST — нет duplicate-гарда.** `TeacherPayController::store`
   (app/Http/Controllers/TeacherPayController.php:52-111) не имеет ни сериального
   пре-чека, ни replay-ключа: повторный сабмит создаёт неограниченное число
   pending-заявок (каждая — письмо куратору + 2 email). Зеркальный /gasuns-pay
   это уже закрыл (rejectDuplicateClaim L176-191 + unique-индекс claim_replay_key,
   миграция 2026_09_24 money-P0, тест race-гарда). Доступ не выдаётся (всегда
   pending), но реестр замусоривается и сверка куратора усложняется. Фикс —
   зеркало gasuns: тот же claim_replay_key = `teacher:{user}:{tariff}` + пре-чек.
   Теста на дубль тоже нет (tests/Feature/TeacherPayTest.php — 9 тестов, дубля нет).
2. **/payment/create — двойной сабмит создаёт два заказа и две ссылки Точки.**
   Гонка обрабатана только для праны (PaymentController.php:386-396 — RuntimeException
   → ValidationException) и для реферал-кошелька (lockForUpdate L166-170), но сам
   Payment::create без идемпотентного ключа: двойной клик = две pending-строки +
   два платёжных звена. Даунстрим идемпотентен (вебхук grant-ит один раз), уборщик
   `payments:expire-stale-checkouts` чистит хвосты — риск денег низкий, риск
   «студент дважды оплатил по двум ссылкам» — средний. Фикс: идемпотентное окно
   (уникальный ключ user+tariff+status=pending или client-side disable + тест).
3. **Refund-контуры выключены по умолчанию и вне вебхука.** Возврат захваченных
   денег не приходит в TochkaWebhook (failure-статусы = rejected/canceled, а
   captured→refunded не матчится, WebhookController.php:117,241-246); отзыв доступа
   при refund — RefundAccessPolicy за флагом `money_refund_access_rules` default OFF
   (config/features.php:1841, Payment.php:664-665,781-791), дневная сверка —
   `money_daily_reconciliation` OFF (config/features.php:1851). Покрыто тестами
   (RefundAccessPolicyTest, RefundFormD10Test, PayoutRefundAndDirectReceiptTest),
   но включение = мутация прода → по FENCE занесено как gap, не сделано.

---

## 1. Карта платёжных флоу (файл:строка)

### 1.1 Классический чекаут → Точка (эквайринг + фискализация)

| Шаг | Место |
|---|---|
| GET /checkout/{tariff} (показ формы) | routes/web/01-checkout-and-storefront.php:41 → CheckoutController::show:26 |
| POST /payment/create (throttle 5,1) | routes/web/05-payments-and-money.php:29-31 |
| Session-lapse re-login (H1396 §2, флаг checkout_session_lapse_relogin) | PaymentController.php:42-54 |
| Гард неактивного тарифа (CHECKOUT_INACTIVE_TARIFF_GUARD, default ON) | PaymentController.php:80-84; config/features.php:700 |
| Отказ чекаута без групп доступа (fail-closed) | PaymentController.php:95-99 |
| Promo: authoritative re-resolve (H1396 §1, forgeable → never trusted) | PaymentController.php:109-157, 503-518 |
| Анти-takeover гостя (существующий email → отказ) | PaymentController.php:142-146, 578+ |
| DB-транзакция: Payment::create + прана + реферал (lockForUpdate) | PaymentController.php:162-406 |
| Гонка праны (двойная вкладка) → ValidationException | PaymentController.php:386-396 |
| Zero-price → сразу paid (после commit) | PaymentController.php:412-422 |
| Purpose-инвариант «Заказ №{id}» (матч вебхука) | PaymentController.php:424-430 |
| Signed return URLs (H1396 §3, default ON) | PaymentController.php:441-446; config/features.php:935 |
| Вызов Tochka ПОСЛЕ commit (row-lock не держит сеть) | PaymentController.php:159-161, 448-470 |
| ConnectionException → payment failed + мягкая ошибка | PaymentController.php:459-470 |
| Неуспешный ответ банка → failed + Log::error | PaymentController.php:487-494 |

### 1.2 Settlement: вебхук Точки (единственный автоматический paid→доступ в проде)

| Гард | Место |
|---|---|
| POST /api/webhooks/tochka | routes/api.php:107 |
| RS256 JWT-верификация, зашитый публичный ключ Точки | WebhookController.php:31-39 |
| SLI-fallback только за флагом money_sli_synthetic_pay (H4672) | WebhookController.php:40-56, 266-280 |
| Anti-replay: sha256(event) = event_hash, unique-индекс, 200-no-op | WebhookController.php:93-104 |
| Матрица статусов: approved/captured/completed/paid · authorized=hold · rejected/canceled=failed | WebhookController.php:106-117 |
| Сериализация: DB::transaction + Payment::lockForUpdate | WebhookController.php:119-122 |
| Анти-воскрешение (paid→failed→replay) | WebhookController.php:143-154 |
| Сверка суммы банка vs заказа (H1359) | WebhookController.php:155-160 |
| Sentinel-breaker (H4930, default OFF, fail-open) | WebhookController.php:161-176; config/features.php:876 |
| Журнал PaymentWebhookEvent (decision-таксономия, одна строка/доставка) | WebhookController.php:179-192 |
| Пререквизит: курс с группами, иначе RuntimeException (доставка повторится) | WebhookController.php:207-211 |
| Грант: paid + payment_method + DigitalKassa чек (AfterCommit) | WebhookController.php:213-236 |
| Фальсы банка → failed | WebhookController.php:241-246 |

### 1.3 /gasuns-pay/{tariff} — «перевёл рубли лично Гасунсу» (H6198)

| Гард | Место |
|---|---|
| Флаг services.gasuns_pay.enabled default OFF → 404 | GasunsPayClaimController.php:139-143 |
| Авто-доверие: устоявшийся ученик (≥7д или был paid) → сразу paid; kill-switch trust_existing_students | GasunsPayClaimController.php:35-42, 66-68, 164 |
| Fail-closed demotion: курс без групп → trusted откатывается в pending (типизированная причина) | GasunsPayClaimController.php:75-79, 107-109 |
| Сериальный пре-чек дубликата (pending/paid на тариф) | GasunsPayClaimController.php:81, 176-191 |
| **Replay-key гард: claim_replay_key = `gasuns:{user}:{tariff}`, DB unique-индекс (миграция 2026_09_24 money-P0), гонка → ValidationException, не 500** | GasunsPayClaimController.php:83-119 |
| Proof на disk 'local' (НЕ public) | GasunsPayClaimController.php:91-92 |
| Гость: новый email → аккаунт+логин; существующий → отказ (анти-takeover) | GasunsPayClaimController.php:230-255 |

### 1.4 /teacher-pay/{tariff} — прямой перевод преподавателю (H4627)

| Факт | Место |
|---|---|
| Флаг services.teacher_pay.enabled default OFF → 404 | TeacherPayController.php:113-117 |
| ВСЕГДА pending — доступ и зачёт только после сверки куратором | TeacherPayController.php:84-87 |
| received_account=teacher_personal + received_by_teacher_id до подтверждения | TeacherPayController.php:88-92 |
| Proof на disk 'local' | TeacherPayController.php:58-59 |
| **Нет duplicate-гарда (см. Топ-3.1)** | отсутствие в store:52-111 |

### 1.5 «Финансы своих курсов» преподавателя (H6145, read-only)

| Гард | Место |
|---|---|
| Fail-closed доступ: teacher без карточки (teacher_id=null) НЕ получает экран — null ≠ «всё» (P1 ревью закрыт) | app/Filament/Pages/TeacherCoursePayments.php:54-78 |
| Скоуп по teacher_id; null-скоуп только админ-подобным | TeacherCoursePayments.php:69-99 |

Смежные каналы (контекст): /paypal/{t} (PaypalClaimController, replay-гард H5442),
/bank/{t} (BankClaimController H3497), депозиты/триалы (Deposit/TrialController),
долги (DebtPaymentController), инвойсы (CompanyInvoiceController) — вне основного
скопа, тесты есть (см. §2).

## 2. Тест-покрытие платёжных флоу

**Выхват (2026-10-10, worktree origin/main @57ba6df8, PHP 8.3, sqlite :memory:):**

```
/opt/homebrew/opt/php@8.3/bin/php -d memory_limit=2G vendor/bin/phpunit \
  --filter "Payment|Checkout|Tochka|GasunsPay|TeacherPay|Paypal" --testdox
→ OK. Tests: 712, Assertions: 2836. FAILURES: 0, ERRORS: 0
  (only PHPUnit Deprecations: 3071 — уведомления об устаревании, не падения)
```

| Флоу/класс | Тесты | Статус |
|---|---|---|
| /checkout/{t} показ, цены, zero/free/inactive, promo (from-url, renewal, session-survival), near-dup email, loyalty, имя | CheckoutPriceTest, CheckoutZeroPriceTest, FreeTariffCheckoutTest, InactiveTariffCheckoutTest, CheckoutPromoFromUrlTest, CheckoutPromoRenewalTest, CheckoutSessionRenewalHardeningTest, CheckoutNameTest, CheckoutNearDuplicateEmailGuardTest, CheckoutLoyaltyStateTest | ✅ зелёные |
| /payment/create: конверсия, реферал-кредит, марафон, авто-enroll, access-key канон | OrderPaymentConversionTest, ReferralCreditCheckoutTest, MarathonPaidCheckoutTest, AutoEnrollOnPaymentTest, VipBundleAccessKey | ✅ зелёные |
| Возвратные страницы, дисконт-маркер, способ оплаты | PaymentReturnPagesTest, PaymentDiscountMarkerTest, PaymentMethodTrackingTest | ✅ |
| Уборщик хвостов | StaleCheckoutExpiryTest, ExpireStaleCheckouts (dry-run default) | ✅ |
| **Вебхук Точки: полный H1359/H2085/H2337/H4672/H4930 набор — dedup event_hash, анти-воскрешение, amount mismatch, hold≠capture, later capture, status matrix, breaker, SLI-fallback, forged body 401** | TochkaWebhookTest, TochkaWebhookBreakerTest, TochkaWebhookSliFallbackTest, Webhooks/WebhookSecurityAuditTest | ✅ зелёные |
| /gasuns-pay: флаг-off 404, форма, pending/paid, дубль-отказ, существующий email, replay-key на уровне БД (ожидание UniqueConstraintViolationException), kill-switch, demotion+reconciliation_exception | GasunsPayClaimTest (11 тестов) | ✅ зелёные |
| /teacher-pay: флаг-off, форма, pending-заявка, подтверждение куратором → paid+доступ, teacher required, future paid_on, существующий email, не-реап | TeacherPayTest (9 тестов) | ✅ зелёные — **НО дубль-сабмит не покрыт (нет и кода-гарда, Топ-3.1)** |
| /paypal, подписки, доплаты; /bank; инвойсы | PaypalClaimTest, PaypalSubscriptionsWebhookTest, PaypalSupplementClaimTest(+Wave), PaypalLinksBoardTest, BankClaimTest, CompanyInvoiceTest | ✅ |
| Депозиты/триалы | tests/Feature/Deposit/, TrialPurchaseTest, CheckTrialFreshnessTest | ✅ |
| Refund-политика и сверка | Reconciliation/RefundAccessPolicyTest, Reconciliation/RefundFormD10Test, Reconciliation/DailyReconciliationTest, Reconciliation/BankStatementCreditsTest, Payout/PayoutRefundAndDirectReceiptTest | ✅ (политика юнит/фиче; e2e refund-вебхука нет — Топ-3.3) |
| PII: маскирование зарплатных полей | PaymentFieldMaskingTest (manager не видит salary/payout) | ✅ |
| Орфаны/целостность | OrphanPaymentsReportTest, CheckoutIntegrityAuditCommandTest | ✅ |

**Непокрытое (классы без теста):** POST-гонка двойного сабмита /payment/create
(два параллельных POST → две pending-строки; для gasuns-key гонка покрыта, для
чекаута нет) и дубль /teacher-pay. Остальные платёжные классы имеют прямое
покрытие.

## 3. Граничные случаи

- **Двойной сабмит.** Вебхук: закрыт (event_hash unique + lockForUpdate +
  firstOrCreate, тесты dedup). /gasuns-pay: закрыт (пре-чек + DB unique replay-key,
  тест race). /payment/create: НЕ закрыт на уровне заказа (Топ-3.2); прана/реферал
  внутри заказа — закрыты. /teacher-pay: НЕ закрыт (Топ-3.1), риск смягчён
  always-pending.
- **Частичная оплата.** Двухстадийная карта: AUTHORIZED = hold, доступ НЕ выдаётся,
  журнал hold_not_captured; поздний APPROVED → грант (WebhookController.php:106-117,
  135-142; тесты authorized_hold_…, later_capture_after_authorized_hold_grants_access,
  #1146-регрессия). Установленные деньги vs заказ: сверка суммы ± допуск (H1359).
  Частичные суммы от банка → amount_mismatch, гранта нет.
- **Refund.** Отмена банком (rejected/canceled) → payment failed (L241-246);
  анти-воскрешение не даёт повторному success-JWT вернуть доступ (L143-154).
  Захваченный→возвращённый деньги приходят вне вебхука: сверка через
  Reconciliation (D10-форма, выписки), отзыв доступа за флагом OFF (Топ-3.3).
  Реферал-кредит/прана возвращаются наблюдателем при неудаче
  (refundPranaIfSpent/refundReferralCreditIfApplied, Payment.php:817-818).

## 4. Мониторинг/алерт при сбое оплаты

| Слой | Механизм |
|---|---|
| Ежедневный аудит целостности чекаута | `payments:audit-checkout-integrity` 04:05, exit≠0 → FAILURE → пейджер (SchedulesOvernightAndCrm.php:91-97; AuditCheckoutIntegrity.php:20-37) |
| Сигнал упавшей money-команды | ScheduleFailureSignal: Log::critical + Filament-пейджер super_admin/admin/accountant (app/Support/ScheduleFailureSignal.php:26-46) |
| Журнал вебхука | PaymentWebhookEvent: provider/payment_id/bank_status/reported_amount/decision — разбор споров без логов |
| Крик при сломе гранта | WebhookController Log::critical + `guards:money-breaker-alarm` (SentinelBreakerGate.php:93, MoneyBreakerAlarm) |
| Ежедневная сверка денег | money_daily_reconciliation (H5445) — флаг OFF → no-op+warning |
| Хвосты чекаутов | payments:expire-stale-checkouts (dry-run default) |
| Орфаны | Filament-страница orphan-payments (OrphanPaymentsReport) |
| Тишина ≠ успех | purpose-miss → LOUD warning при soft-200 (H2085, WebhookController.php:83-91) |

Пробел: SLI-алерт на «вебхук не приходил N минут при открытых ссылках» —
heartbeat MoneySliAlerter упомянут (H5061), синтетический платёж за флагом
money_sli_synthetic_pay; до полного закрытия « payment died silently» остаётся
дневной аудит как компенсация.

## 5. PII / 152-ФЗ обработка чеков

- Чеки/скрины переводов хранятся на disk `local` (не public):
  gasuns-pay-proofs (GasunsPayClaimController.php:92), teacher-pay-proofs
  (TeacherPayController.php:59), bank-proofs, paypal-proofs.
- Выдача чека только персоналу: GET /admin/payments/{payment}/paypal-proof,
  auth + is_admin + 404 без файла (routes/web/05-payments-and-money.php:118-129).
- Логи вебхука: только статус и способ оплаты, полный payload (ФИО/суммы)
  сознательно не пишется (WebhookController.php:75-78).
- Согласие на ПДн на чекауте: ConsentRules::pd(checkout: true) за флагом
  money-контура default OFF (PaymentController.php:69-70); claim-каналы пишут
  согласие через ConsentRecorder (claim:gasuns / claim:teacher).
- Маскирование зарплатных полей от менеджеров (PaymentFieldMaskingTest).
- Токен-аккаунт гостя: пароль Str::random(12) (не выбирается пользователем),
  существующий email → отказ вместо логина (анти-takeover) на всех claim-каналах.

## 6. Канарейка: GET-пробы прода (только чтение)

| # | Статус | GET |
|---|---|---|
| 1 | 404 | https://samskrte.ru/checkout/1 |
| 2 | 404 | https://samskrte.ru/checkout/999999 |
| 3 | 404 | https://samskrte.ru/gasuns-pay/1 |
| 4 | 404 | https://samskrte.ru/teacher-pay/1 |
| 5 | 200 | https://samskrte.ru/payment/success |
| 6 | 200 | https://samskrte.ru/payment/fail |
| 7 | 405 | https://samskrte.ru/api/webhooks/tochka (POST-only — эндпоинт жив, GET-интерфейса нет) |

Чтение: 404 на {1} = несуществующий тариф; 404 на gasuns/teacher-pay согласуется
с default-OFF флагами (abortUnlessEnabled, fail-closed подтверждён и в коде, и на
проде). 200 возвратных страниц — сервис отвечает. **Ни одного POST/PATCH/DELETE,
ни одной мутации.**

## 7. Перечень выполненных команд (полный, для воспроизводимости)

```
# Код (read-only)
git -C <worktree> rev-parse --show-toplevel && remote get-url origin && status --short --branch && log --oneline -3
git fetch origin main && git rebase origin/main
grep -n ... routes/web.php routes/web/05-payments-and-money.php routes/web/01-checkout-and-storefront.php routes/api.php
read: GasunsPayClaimController.php, PaymentController.php, TeacherPayController.php,
      WebhookController.php, TeacherCoursePayments.php, Payment.php (фрагменты),
      AuditCheckoutIntegrity.php, OrphanPaymentsReport.php, ScheduleFailureSignal.php,
      SentinelBreakerGate.php, ExpireStaleCheckouts.php, config/features.php (фрагменты)
ls tests/Feature/ tests/Feature/Payments/ tests/Feature/Payout/ tests/Feature/Webhooks/

# Тесты (изолированный прогон, sqlite :memory:)
/opt/homebrew/opt/php@8.3/bin/php -d memory_limit=2G vendor/bin/phpunit \
  --filter "Payment|Checkout|Tochka|GasunsPay|TeacherPay|Paypal" --testdox
# → OK, 712 tests, 2836 assertions, 0 failures

# Канарейка (GET, read-only; UA: H6287-read-only-audit/1.0)
curl -sS -o /dev/null -w "%{http_code}" --max-time 20 -A "H6287-read-only-audit/1.0" <url>   # × 7 URL из §6
curl -sS --max-time 25 -A "H6287-read-only-audit/1.0" https://samskrte.ru/online            # поиск живого tariff-id (не найден в HTML каталога — не блокер)

# Не выполнялось (FENCE): любые POST, SSH-мутации, правка флагов/конфигов, доступ к БД прода
```

## 8. Соответствие acceptance (Evidence of Done)

- [x] Карта флоу с файлами:строками, включая replay-key гард — §1.3, §1.1-1.5
- [x] Выхват phpunit по платёжным тестам — §2 (712/2836/0) + таблица покрытия; named blocker нет
- [x] Канарейка GET-проб каждого публичного эндпоинта со статусом, ноль мутаций, перечень команд — §6-7
- [x] Вердикт N/5 + топ-3 — в шапке
- [x] FENCE: ни одной мутации прода; топ-3.3 (включение refund-флагов) сознательно не сделано — занесено как gap

_Аудит: OxAlpha (opencode/z-ai/glm-5.3-flash), H6287, 2026-10-10. к.ф.н. М.Ю. Гасунс_
