_Created: 07-05-2026 · Last updated: 05-09-2026_

# routes

Определения маршрутов.

## `web.php` — основные маршруты

Маршруты перечислены в порядке объявления (важно для catch-all в конце).

### Публичные

| Маршрут | Контроллер | Описание |
|---|---|---|
| `GET /` | Redirect | → `/shop` |
| `GET /shop` | `ShopController@index` | Каталог курсов |
| `GET /shop/{slug}` | `ShopController@show` | Страница курса |
| `GET /checkout/{tariff}` | `CheckoutController@show` | Оформление заказа |
| `POST /payment` | `PaymentController@create` | Создание платежа |
| `POST /payment/webhook` | `WebhookController@handle` | Вебхук Точки |
| `GET /s` | `ArticleController@index` | Блог |
| `GET /s/{slug}` | `ArticleController@show` | Статья |
| `POST /login` | `AuthController@login` | Вход |
| `POST /logout` | `AuthController@logout` | Выход |
| `POST /lead` | `LeadController@store` | Заявка с лендинга |

### Личный кабинет (middleware: `auth`, `track.activity`)

| Маршрут | Описание |
|---|---|
| `GET /cabinet` | Дашборд студента |
| `GET /c/{slug}` | Уроки курса (legacy `/course/{slug}` → 301) |
| `GET /c/{slug}/u/{id}` | Плеер урока (legacy `/course/.../lesson/...` → 301) |
| `POST /c/{slug}/u/{id}/complete` | Отметить урок пройденным |
| `POST /c/{slug}/u/{id}/note` | Сохранить заметку |
| `GET /c/{slug}/materials/download` | Скачать архив материалов |
| `GET /k/{slug}` | Витрина курса (legacy `/online/kursy/{slug}` → 301) |
| `GET /certificate/{id}` | Скачать сертификат |
| `GET /calendar` | Расписание |
| `GET /cabinet/payments` | История платежей |
| `GET /cabinet/dictionary` | Словарь |
| `GET /telegram/connect` | Привязка Telegram |

### Admin-only (middleware: `auth`, `admin`)

| Маршрут | Описание |
|---|---|
| `GET /leads/export` | Экспорт заявок CSV |

### Catch-all (ПОСЛЕДНИЙ маршрут)

```php
Route::get('/{slug}', [PromoController::class, 'show'])
```

Перехватывает любой slug и ищет `LandingPage`. **Все новые маршруты должны быть объявлены ДО этой строки.**

---

## `api.php` — API-маршруты

| Маршрут | Аутентификация | Описание |
|---|---|---|
| `POST /api/sync-lessons` | Secret key заголовок | Синхронизация уроков |
| `POST /api/telegram/webhook` | Telegram signature | Вебхук Telegram-бота |
| `POST /api/vk-webhook` | VK signature | Вебхук VK-бота |
| `POST /api/webhooks/tochka` | JWT (RSA) | Вебхук Точки Банка |
| `POST /api/heartbeat` | `auth:sanctum` | Хартбит урока |
| `GET /api/user` | `auth:sanctum` | Текущий пользователь |

---

## `console.php`

Регистрация closure-команд для `php artisan`. В текущем проекте используется минимально — основные команды в `app/Console/Commands/`.

_Dr. Mārcis Gasūns_
