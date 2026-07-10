# Самообслуживание должника (Debtor self-service) — Phase 1

_Created: 04-07-2026 · Last updated: 04-07-2026_

Студент-должник гасит собственный долг **сам**, из личного кабинета, без звонка
куратору. Это студенческий контрагент менеджерского сервиса «Должники»
([`docs/debtors-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtors-manual.md)):
там куратор ведет список и подтверждает оплаты вручную — здесь студент платит сам,
а система закрывает договоренность автоматически.

## Что уже есть (не переписываем)

| Кусок | Где | Роль в Phase 1 |
|---|---|---|
| Расчет долга студента | [`app/Services/StudentDebtsService.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/StudentDebtsService.php) `forUser()` | Источник данных: блоки долга, сумма, график обещаний, признак рассрочки. **Уже** передается в дашборд студента. |
| Показ долгов | [`resources/views/student/dashboard.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/dashboard.blade.php) вкладка «Мои долги» | Карточки уже отрисованы. Единственный CTA сейчас — «К курсу». **Гап именно здесь.** |
| Чекаут по тарифу | [`app/Http/Controllers/CheckoutController.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/CheckoutController.php) + `PaymentController::createPayment` | Оплата одного `Tariff` через Точку с лояльностью/праной/промокодом. Переиспользуем для «не продлил». |
| Тариф → ключ доступа | [`app/Models/Tariff.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Tariff.php) `accessKey()` | `full` / `block_N` / `block_N_hH`. Резолвер сопоставляет блоки долга тарифам. |
| Активация реальной оплаты | [`app/Models/Payment.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Payment.php) `processSuccessfulPayment()` | Единственная точка, где реальный платеж выдает доступ. Сюда вешаем авто-закрытие обещания. |
| Закрытие обещания | [`app/Services/PromiseFulfillment.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/PromiseFulfillment.php) | `fulfil()` **создает** платеж. Нам нужен вариант «привязать УЖЕ созданный реальный платеж к обещанию» — см. `PromiseAutoFulfiller`. |

## Решения (MG, 04-07-2026)

1. **Резолюция «Оплатить» → чекаут: сопоставление с существующими тарифами.**
   Неоплаченные блоки → уже заведенные тарифы курса (`full` / `block_N` / `block_N_hH`),
   переход на существующий `/checkout/{tariff}`. Если точного тарифа нет — fallback на
   поблочные ссылки. Никакого нового платежного пути для «не продлил».
2. **Рассрочка / обещание: студент может внести следующий платеж по графику ИЛИ погасить
   всё сразу.** Обещание несет согласованную `amount` (часто со скидкой) — она не равна
   цене тарифа. Значит для рассрочки нужен promise-aware чекаут на сумму обещания с
   трекингом, какое именно обещание оплачивается.
3. **Авто-закрытие обещания.** Когда реальный (не conditional) платеж студента приходит
   по вебхуку Точки и покрывает открытое обещание — обещание автоматически становится
   `fulfilled` (переиспользуя логику `PromiseFulfillment`) + уведомляется куратор. Никаких
   висящих открытых обещаний после того, как студент сам заплатил.

## Архитектура Phase 1

### 1. `DebtPaymentResolver` (новый сервис)

Вход — строка долга из `StudentDebtsService::forUser()`. Выход — набор «вариантов оплаты»:

- **Долг без договоренности («не продлил»):**
  - если долг покрывает ВСЕ неоплаченные блоки до текущего и у курса есть активный тариф
    `full` → один вариант «Оплатить курс» → `/checkout/{fullTariff}`;
  - иначе поблочно: для каждого неоплаченного блока — тариф `block_N` (если заведен) →
    `/checkout/{blockTariff}`. Блоки без тарифа выводятся как «нет тарифа — обратитесь к
    куратору» (не молчим).
- **Долг с договоренностью (обещание/рассрочка):**
  - «Внести следующий платеж» → ближайшее непогашенное обещание (`amount` + `promise_id`);
  - «Погасить всё» → сумма всех непогашенных обещаний группы (или полная сумма долга).

Резолвер **только считает и маршрутизирует** — не создает платежей.

### 2. `DebtPaymentController` + маршруты (только для promise-суммы)

Плоские «не продлил» уходят на штатный чекаут — новый контроллер им не нужен.
Для рассрочки/обещания:

- `POST /student/debt/promise/{promise}/pay` — оплатить одно обещание;
- `POST /student/debt/course/{course}/pay-all` — погасить весь график по курсу.

Контроллер:
1. Авторизация: `promise.user_id === auth()->id()` (иначе 403).
2. Создает `pending` **реальный** `Payment` (`is_conditional = false`), `amount` = сумма
   обещания / всего графика, `linked_promise_id` = id обещания (для «всё» — id первого/
   ведущего), `tariff`/`start_block`/`end_block` из блоков долга.
3. Отправляет в Точку через `TochkaPaymentService::createPaymentWithReceipt` (как
   `PaymentController`), редиректит на `paymentLink`. Сбой → `failed` + мягкая ошибка.

> Промокод/прана к согласованной рассрочке Phase 1 не применяем — сумма уже
> зафиксирована куратором. Это осознанное упрощение, не баг.

### 3. `PromiseAutoFulfiller` (новый сервис)

Вызывается из `Payment::processSuccessfulPayment()` для **не-conditional** реального
платежа. Находит непогашенные (`active`/`expired`) обещания к закрытию:

- по `linked_promise_id` платежа (точная привязка из self-service), **или**
- по паре `(user_id, course_id)`, чьи блоки покрыты `start_block..end_block` платежа
  (случай, когда студент погасил блок через штатный `/checkout` без promise-линка).

Для каждого: `status = fulfilled`, `fulfilled_at = now()`, `fulfilled_payment_id = $payment->id`,
уведомление куратору (`CuratorNotifier::promiseFulfilled`). **Идемпотентно**: уже
`fulfilled`/`cancelled` пропускаем; conditional-платеж не триггерит (денег нет).

### 4. Фронт: CTA на карточке долга

В [`dashboard.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/dashboard.blade.php)
вкладка «Мои долги»: рядом с «К курсу» (остается вторичной ссылкой) —
основная кнопка **«Оплатить»**:

- «не продлил» → ссылка(и) на резолвнутый чекаут;
- рассрочка → «Внести N ₽» (следующий платеж, POST-форма) + «Погасить всё (M ₽)».

## Граница Phase 1 (что НЕ входит)

- **Бандл произвольных блоков в один платеж.** Плоский долг с несколькими блоками и
  только поблочными тарифами → студент платит по одному блоку за раз (несколько чекаутов).
  Единый «оплатить всё поблочно» одной суммой — Phase 2 (нужен новый платежный путь).
- **Промокод/прана на рассрочку.** Phase 2.
- **Смена графика рассрочки студентом.** Остается куратору.
- **Отзыв conditional-доступа при просрочке.** Как и раньше — вручную (см. debtors-manual §9).

## Done when

- В «Мои долги» есть рабочая кнопка «Оплатить», ведущая «не продлил» на штатный чекаут,
  а рассрочку — на promise-оплату (следующий платеж / всё).
- Реальная оплата, покрывающая обещание, авто-закрывает его (`fulfilled` +
  `fulfilled_payment_id`) и уведомляет куратора — проверено фич-тестом.
- `php artisan test` зеленый; `./vendor/bin/pint` чистый.
- Обновлены [`debtors-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtors-manual.md)
  (короткий раздел «студент платит сам») и `.ai_state.md`.

_Dr. Mārcis Gasūns_
