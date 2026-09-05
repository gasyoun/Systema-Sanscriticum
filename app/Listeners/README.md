_Created: 07-05-2026 · Last updated: 05-09-2026_

# app/Listeners

Слушатели событий Laravel Auth. Регистрируются в `EventServiceProvider`.

## `UserLoginListener`
**Событие**: `Illuminate\Auth\Events\Login`

Вызывает `ActivityTracker::handleLogin($user)`:
- Открывает новую `UserSession`.
- Обновляет `users.login_count` и `users.last_login_at`.
- Пишет событие `login` в `activity_events`.

Пропускает администраторов (`is_admin === true`) — их сессии не трекируются.

## `UserLogoutListener`
**Событие**: `Illuminate\Auth\Events\Logout`

Вызывает `ActivityTracker::handleLogout($user)`:
- Закрывает активную `UserSession`, вычисляет длительность.
- Накапливает время в `users.total_time_spent`.
- Пишет событие `logout` в `activity_events`.

Также пропускает администраторов.

---

Оба слушателя обернуты в `try/catch` внутри `ActivityTracker` — сбой трекинга не прерывает авторизацию.

_Dr. Mārcis Gasūns_
