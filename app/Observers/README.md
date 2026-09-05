_Created: 07-05-2026 · Last updated: 05-09-2026_

# app/Observers

Наблюдатели Eloquent — побочные эффекты при изменении моделей. Регистрируются в `AppServiceProvider::boot()`.

## Наблюдатели

### `PaymentObserver`
**Модель**: `Payment`

Ключевое звено в цепочке оплаты. При создании или обновлении платежа:
1. Проверяет, стал ли статус `paid` или `success` **И** сумма > 0.
2. Если да — диспатчит `SendPaymentToSheetJob` (синхронизация с Google Sheets).
3. Вызывает `Payment::processSuccessfulPayment()` — выдача доступа + welcome email.

Различает `created` и `updated` чтобы не дублировать действия при повторных webhooks.

### `ArticleViewObserver`
**Модель**: `ArticleView`

- `created`: инкрементирует `articles.views_count`.
- `deleted`: декрементирует `articles.views_count` (защита от отрицательных значений через `max(0, ...)`).

Нужен для кешируемого счетчика без запроса `COUNT(*)` на каждое открытие статьи.

### `ScheduleObserver`
**Модель**: `Schedule`

Отправляет уведомление в n8n-вебхук при изменениях расписания (для автоматической рассылки студентам).

### `LandingPageObserver`
**Модель**: `LandingPage`

Инвалидирует Redis-кеш лендинга при обновлении записи. Ключ кеша совпадает с тем, что использует `PromoController`.

---

## Регистрация

```php
// AppServiceProvider::boot()
Schedule::observe(ScheduleObserver::class);
ArticleView::observe(ArticleViewObserver::class);
Payment::observe(PaymentObserver::class);
LandingPage::observe(LandingPageObserver::class);
```

Для новых наблюдателей — добавить строку в `AppServiceProvider::boot()`.

_Dr. Mārcis Gasūns_
