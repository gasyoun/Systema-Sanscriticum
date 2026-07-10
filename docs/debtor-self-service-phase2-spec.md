# Самообслуживание должника (Debtor self-service) — Phase 2

_Created: 04-07-2026 · Last updated: 04-07-2026_

Продолжение [`docs/debtor-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtor-self-service-spec.md)
(Phase 1, [H168](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H168-Opus_Systema-Sanscriticum_systema_debtor_self_service_F1_04.07.26.md),
[PR #293](https://github.com/gasyoun/Systema-Sanscriticum/pull/293) merged). Phase 2 закрывает
пять пробелов, найденных при code-grounded ревью Phase 1.

## Ключевой факт (упрощает #1)

Доступ к уроку гейтится по **ключу тарифа** (`payments.tariff` → `Lesson::isUnlockedBy`,
ключи `block_N` / `full`). А **долг** в [`StudentDebtsService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/StudentDebtsService.php)
считается по **диапазону** `start_block..end_block` каждого платежа (`DebtorsReport::paymentCovers`),
НЕ по ключу. Значит платеж с диапазоном 5–7 уже закрывает *долг* по 5–7; не хватает только
*ключей доступа* `block_6`/`block_7`. Поэтому sibling-строки — **access-only**, вне финансов.

## Задачи

### #1 Мульти-блочный доступ через sibling-строки (корректность)

**Баг:** платеж-договоренность (`payPromise`/`payAll`), покрывающий блоки N..M, пишется одним
ключом `block_N` → уроки блоков N+1..M остаются закрыты, хотя оплачены. Тем же дефектом болеет
менеджерский [`PromiseFulfillment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/PromiseFulfillment.php)
(один ключ на диапазон) — фикс лечит оба пути.

**Модель (решение MG): один реальный платеж + нулевые sibling-строки доступа.**
Новый `BlockAccessMaterializer`, вызываемый из `Payment::processSuccessfulPayment()` для
реального (не conditional) платежа с диапазоном >1 блока: на каждый блок N+1..M создает
`Payment(amount=0, is_conditional=false, tariff='block_X', transaction_id='access_grant_#<primary>')`
через `withoutEvents` (без рекурсии/писем/праны/реферала). Основной платеж хранит полную сумму
и свой ключ. Sibling-строки:
- **дают ключ** `block_X` → уроки открываются;
- **вне финансов:** `PaymentObserver::isSyncable()` исключает `transaction_id LIKE 'access_grant_#%'`
  (нулевые легитимные оплаты — бесплатный доступ / 100%-промокод — по-прежнему синкаются);
- **не искажают долг:** диапазон уже покрыт основным платежом; sibling нужен только для ключа;
- **идемпотентны:** повтор вебхука не плодит дубли (проверка существующего `block_X` для этого
  primary).

### #2 Бандл: один чекаут на многоблочный «не продлил»

Phase 1 гонял плоский многоблочный долг через N отдельных чекаутов Точки. Phase 2 — **один**
чекаут на весь долг: суммарная цена блоков одним платежом с диапазоном `min..max`, доступ ко
всем блокам через sibling-строки (#1). В резолвере — вариант «Оплатить всё (Σ ₽)»; поблочные
ссылки остаются как альтернатива. Цена = сумма `Tariff::calculateFinalPriceForUser` по
блокам-долга (лояльность применяется, как в обычном чекауте).

### #3 Гард от дубля pending

`DebtPaymentController` создает pending без проверки существующего. Двойной клик / back →
два payment-link Точки → двойная оплата. Добавить проверку «свежий pending этого
promise/курса существует» (по образцу `PaymentController::hasRecentPendingForUser`) → явная
ошибка вместо второго заказа.

### #4 Прана на рассрочку

Phase 1 не применял скидки к согласованной сумме. Phase 2: студент может списать **свою
прану** против суммы (лояльность/промокод остаются ВЫКЛ — куратор уже назначил цену).
Отдельная легкая страница promise-чекаута (`GET student.debt.promise.checkout`) со слайдером
праны (реюз `PranaService::maxSpendableForPrice`), сервер пересчитывает максимум сам (клиенту
не верим), списывает в транзакции; при срыве оплаты штатный `Payment::refundPranaIfSpent`
возвращает. Зеркалит `PaymentController::createPayment` без промокода.

### #5 Кастомная частичная оплата + перенос даты

- **Частичная:** на promise-чекауте студент задает сумму в диапазоне
  `[ближайший взнос, весь остаток]`. `PromiseAutoFulfiller` обобщается: закрывает **самые
  ранние** непогашенные обещания графика, чью накопленную сумму платеж покрывает целиком
  (greedy, допуск 1 ₽). Это единый механизм для next(1) / partial(K) / whole(все).
- **Перенос:** `POST student.debt.promise.reschedule` — сдвиг даты ближайшего обещания вперед
  не более чем на `DEBT_RESCHEDULE_MAX_DAYS` (дефолт 14), один раз студентом, только forward.
  Куратор уведомляется (`CuratorNotifier`). Поле-маркер `student_rescheduled_at` на обещании,
  чтобы не переносили бесконечно.

## Граница Phase 2 (что НЕ входит)

- Промокод/лояльность на рассрочку (осознанно — цена фиксирована куратором).
- Произвольная частичная оплата *плоского* блока меньше цены блока (блок атомарен по ключу —
  частичная оплата блок не открывает; частичная — только по графику рассрочки).
- Смена всего графика (число/суммы взносов) студентом — остается куратору.

## Done when

- Мульти-блочная оплата открывает ВСЕ оплаченные блоки (sibling-строки), долг закрыт, финансы
  чисты (access_grant вне синка) — фич-тест.
- Плоский многоблочный долг гасится одним чекаутом; дубль-pending отклоняется; прана-слайдер
  работает и пересчитывается на сервере; частичная закрывает ранние взносы; перенос сдвигает
  дату в пределах лимита и уведомляет куратора — всё под фич-тестами.
- `php artisan test` (затронутые чанки) зеленый; Pint чист; `debtors-manual` + `.ai_state.md`
  обновлены.

_Dr. Mārcis Gasūns_
