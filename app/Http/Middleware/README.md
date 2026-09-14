_Created: 07-05-2026 · Last updated: 05-09-2026_

# app/Http/Middleware

## Кастомные middleware

### `AdminMiddleware`
Проверяет флаг `is_admin` у авторизованного пользователя. Возвращает 403, если флаг не установлен.  
Применяется к маршрутам: экспорт лидов, admin-only действия вне Filament.

### `TrackUserActivity`
Обновляет `users.last_activity_at` и хартбит активной сессии (`UserSession`).  
Троттлится через Redis — выполняется не чаще одного раза в минуту на пользователя.  
**Исключение**: не применяется к эндпоинту `/api/heartbeat` (там трекинг идет через `HeartbeatController`).  
Применяется только к студентам (пропускает администраторов).

## Стандартные Laravel middleware

Остальные файлы — стандартный набор Laravel, не изменялись:

| Файл | Назначение |
|---|---|
| `Authenticate.php` | Редирект неавторизованных на `/login`. |
| `EncryptCookies.php` | Шифрование кук. |
| `RedirectIfAuthenticated.php` | Редирект авторизованных со страниц входа. |
| `TrimStrings.php` | Обрезка пробелов в полях запроса. |
| `TrustHosts.php` | Доверенные хосты. |
| `TrustProxies.php` | Доверенные прокси для корректного определения IP. |
| `ValidateSignature.php` | Проверка подписанных URL. |
| `VerifyCsrfToken.php` | CSRF-защита. |

## Регистрация в `Kernel.php`

- `auth` → `Authenticate`
- `admin` → `AdminMiddleware`
- `track.activity` → `TrackUserActivity`

Группа `web` включает все стандартные middleware автоматически.

_Dr. Mārcis Gasūns_
