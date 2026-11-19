_Created: 07-05-2026 · Last updated: 10-10-2026_

# app/Observers

Наблюдатели Eloquent — побочные эффекты при изменении моделей. Регистрируются в `AppServiceProvider::boot()`.

## Наблюдатели

### `PaymentObserver`
**Модель**: `Payment`

Синхронизация и побочные начисления поверх платежа (выдачу доступа он НЕ делает —
она в `Payment::processSuccessfulPayment()` из `static::updated` модели). При
создании или обновлении платежа:
1. Диспатчит `SendPaymentToSheetJob` (синхронизация с Google Sheets) для
   `paid`/`success` — сумму НЕ проверяет (нулевые оплаты — тоже операции); не
   синкает conditional-платежи и access-only гранты `BlockAccessMaterializer`.
2. Награждает реферера и партнера (`ReferralService`/`PartnerService`); при
   откате из `paid` (failed/canceled) — снимает награду обратно.
3. Пересобирает график признания выручки (`RevenueScheduleService::regenerateFor`),
   в т.ч. для исходного платежа при удалении платежа-возврата.

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

### Остальные наблюдатели

| Наблюдатель | Модель(и) | Роль |
|---|---|---|
| `CourseCoverWebpObserver` | `Course` | Автоперевод обложки в WebP в том же запросе (H3082). |
| `PaymentAuditObserver` | `Payment` | Аудит финансовых операций: кто и что сделал с платежом; без автора — «Система» (H4188). |
| `PaymentTelemetryObserver` | `Payment` | Телеметрия оплат для baseline ремейка кабинета (H962). |
| `PaymentDealBridgeObserver` | `Payment`, `Deal*`, `PaymentPromise` | Мост «платёж → сделка» CRM (GC-C1/H1641). |
| `IpExpenseAuditObserver` | `IpExpense` | Аудит «Расходы ИП» по конвенции PaymentAuditObserver. |
| `LeadAuditObserver` | `Lead` | Аудит операций с лидом (зеркало PaymentAuditObserver). |
| `MessageTemplateAuditObserver` | `MessageTemplate` | Аудит правок библиотеки шаблонов (H1932). |
| `SitemapCacheInvalidator` | `LandingPage`, `Course`, `Article` | Сброс кеша sitemap.xml и llms.txt при изменении контента. |
| `LessonObserver` | `Lesson` | Publish-on-lesson-publish: генерация Article/FAQ/StudyArtifact + извлечение клипов (H1547+). |
| `LectureClipObserver` | `LectureClip` | Синхронизирует `ContentCandidate type=clip` с LectureClip (H1547 Wave 1). |
| `ContentCandidateObserver` | `ContentCandidate` | Цепочки Wave 2–3: accepted → соцпост/FAQ-публикация (H1548/H1549). |

---

## Регистрация

```php
// AppServiceProvider::boot() — в порядке объявления, 17 привязок
Schedule::observe(ScheduleObserver::class);
ArticleView::observe(ArticleViewObserver::class);
Course::observe(CourseCoverWebpObserver::class);
Payment::observe(PaymentObserver::class);
Payment::observe(PaymentAuditObserver::class);
IpExpense::observe(IpExpenseAuditObserver::class);
Payment::observe(PaymentTelemetryObserver::class);
Payment::observe(PaymentDealBridgeObserver::class);
Lead::observe(LeadAuditObserver::class);
MessageTemplate::observe(MessageTemplateAuditObserver::class);
LandingPage::observe(LandingPageObserver::class);
LandingPage::observe(SitemapCacheInvalidator::class);
Course::observe(SitemapCacheInvalidator::class);
Article::observe(SitemapCacheInvalidator::class);
Lesson::observe(LessonObserver::class);
LectureClip::observe(LectureClipObserver::class);
ContentCandidate::observe(ContentCandidateObserver::class);
```

Для новых наблюдателей — добавить строку в `AppServiceProvider::boot()`.

_Dr. Mārcis Gasūns_
