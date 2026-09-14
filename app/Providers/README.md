_Created: 07-05-2026 · Last updated: 05-09-2026_

# app/Providers

Сервис-провайдеры Laravel. Точки инициализации приложения.

## `AppServiceProvider` — главный провайдер

Самый важный файл в этой папке. Содержит:

**`register()`**:
- Регистрирует синглтоны `LectureBuilderClient` и `LectureAiClient` с конфигурацией из `config/services.php`.

**`boot()`**:
- Принудительно переключает URL на HTTPS в production (`URL::forceScheme('https')`).
- Регистрирует наблюдателей: `ScheduleObserver`, `ArticleViewObserver`, `PaymentObserver`, `LandingPageObserver`.

## Filament-провайдеры

### `Filament/AdminPanelProvider`
Конфигурирует панель `/admin`:
- Регистрирует все 18 ресурсов, виджеты, страницы.
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
| `EventServiceProvider` | Маппинг событий на слушателей: `Login → UserLoginListener`, `Logout → UserLogoutListener`. |
| `HorizonServiceProvider` | Настройка доступа к дашборду Horizon (`/horizon`). |
| `RouteServiceProvider` | Привязка `HOME` константы (`/cabinet`), rate limiting для API. |

_Dr. Mārcis Gasūns_
