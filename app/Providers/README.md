_Created: 07-05-2026 · Last updated: 10-10-2026_

# app/Providers

Сервис-провайдеры Laravel. Точки инициализации приложения.

## `AppServiceProvider` — главный провайдер

Самый важный файл в этой папке. Содержит:

**`register()`**:
- Регистрирует синглтоны `LectureBuilderClient` и `LectureAiClient` с конфигурацией из `config/services.php`.

**`boot()`**:
- Принудительно переключает URL на HTTPS в production (`URL::forceScheme('https')`).
- Регистрирует наблюдателей (17 привязок в `boot()`): `ScheduleObserver`,
  `ArticleViewObserver`, `CourseCoverWebpObserver`, `PaymentObserver` +
  `PaymentAuditObserver`/`PaymentTelemetryObserver`/`PaymentDealBridgeObserver`,
  аудиты `IpExpenseAuditObserver`/`LeadAuditObserver`/`MessageTemplateAuditObserver`,
  `LandingPageObserver`, `SitemapCacheInvalidator` (LandingPage/Course/Article),
  `LessonObserver`, `LectureClipObserver`, `ContentCandidateObserver`.

## Filament-провайдеры

### `Filament/AdminPanelProvider`
Конфигурирует панель `/admin`:
- Регистрирует ресурсы автообнаружением (`->discoverResources()` из
  `app/Filament/Resources`, сейчас 62), виджеты, страницы.
- Устанавливает guard `web`, middleware-группу `admin`.
- Подключает плагины: Curator (медиабиблиотека), Excel (экспорт).

### `Filament/LectureEditorPanelProvider`
Конфигурирует панель `/editor`:
- Ограниченный набор ресурсов только для работы с лекциями.
- Отдельная проверка доступа: `is_lecture_editor`.

## Стандартные провайдеры

| Файл | Роль |
|---|---|
| `AuthServiceProvider` | Привязка Policy-классов к моделям (если есть). |
| `BlogAnalyticsServiceProvider` | Инициализация счетчиков аналитики для блога. |
| `BroadcastServiceProvider` | Настройка broadcasting (не используется активно). |
| `EventServiceProvider` | Маппинг событий на слушателей: `Login → UserLoginListener`, `Logout → UserLogoutListener`, `SocialiteWasCalled → VK/Yandex-драйверы socialiteproviders`. |
| `HorizonServiceProvider` | Настройка доступа к дашборду Horizon (`/horizon`). |
| `RouteServiceProvider` | `HOME` константа (`/home`), rate limiting (`api`, `livewire-update`). |
| `MessagingServiceProvider` | Синглтоны каналов доставки мессенджеров (Telegram/VK/MAX) + менеджер каналов. |

_Dr. Mārcis Gasūns_
