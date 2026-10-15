_Created: 07-05-2026 · Last updated: 10-10-2026_

# routes

Определения маршрутов.

## `web.php` — основные маршруты

`web.php` — тонкий файл: подключает тематические группы из `routes/web/`
(`01-checkout-and-storefront`, `02-auth-and-public-pages`, `03-student-cabinet`,
`04-technical-and-marketing`, `05-payments-and-money`, `06-editor-and-misc-public`)
и держит catch-all `/{slug}` → `PromoController@show` в самом конце. Новые маршруты
объявляются в тематических файлах **до** catch-all.

### Публичные

| Маршрут | Контроллер | Описание |
|---|---|---|
| `GET /` | замыкание в `01-checkout-and-storefront.php` | Витрина: активные лендинги/курсы (is_active + is_listed) |
| `GET /online` | `ShopController@index` | Каталог курсов (legacy `/shop` → 301) |
| `GET /k/{slug}` | `ShopController@show` | Страница курса (legacy `/online/kursy/{slug}` → 301) |
| `GET /checkout/{tariff}` | `CheckoutController@show` | Оформление заказа |
| `POST /payment/create` | `PaymentController@createPayment` | Создание платежа |
| `POST /api/webhooks/tochka` | `WebhookController@handleTochkaWebhook` | Вебхук Точки (см. таблицу api.php ниже) |
| `GET /s` | `ArticleController@index` | Блог |
| `GET /s/{slug}` | `ArticleController@show` | Статья |
| `POST /login` | `AuthController@login` | Вход |
| `POST /logout` | `AuthController@logout` | Выход |

### Личный кабинет (middleware: `auth`, `track.activity`)

| Маршрут | Описание |
|---|---|
| `GET /cabinet` | 301 → `/dvaram` (дашборд студента) |
| `GET /dvaram` | Дашборд студента |
| `GET /c/{slug}` | Уроки курса (legacy `/course/{slug}` → 301) |
| `GET /c/{slug}/u/{id}` | Плеер урока (legacy `/course/.../lesson/...` → 301) |
| `POST /c/{slug}/u/{id}/complete` | Отметить урок пройденным |
| `POST /c/{slug}/u/{id}/note` | Сохранить заметку |
| `GET /c/{slug}/materials/download` | Скачать архив материалов |
| `GET /certificate/{id}/download` | Скачать сертификат (PDF; `/download/jpg` — JPG) |
| `GET /calendar` | Расписание |
| `GET /cabinet/payments` | История платежей |
| `GET /cabinet/dictionary` | Словарь |
| `GET /telegram/connect` | Привязка Telegram |

### Admin-only (middleware: `auth`, `admin`)

| Маршрут | Описание |
|---|---|
| `GET /admin/leads/export` | Экспорт заявок CSV |

### Catch-all (ПОСЛЕДНИЙ маршрут)

```php
Route::get('/{slug}', [PromoController::class, 'show'])
```

Перехватывает любой slug и ищет `LandingPage`. **Все новые маршруты должны быть объявлены ДО этой строки.**

---

## `api.php` — API-маршруты

| Маршрут | Аутентификация | Описание |
|---|---|---|
| `POST /api/sync-lessons` | Secret key заголовок (`X-Secret-Key`) + throttle 30/мин | Синхронизация уроков (n8n) |
| `POST /api/telegram/webhook` | Telegram signature | Вебхук Telegram-бота |
| `POST /api/vk-webhook` | VK signature | Вебхук VK-бота |
| `POST /api/webhooks/tochka` | JWT (RSA) | Вебхук Точки Банка |
| `POST /api/webhooks/zoom` | подпись Zoom | Вебхук Zoom (запись/посещаемость) |
| `POST /api/heartbeat` | сессия кабинета (`auth`, web-guard) — маршрут объявлен в `routes/web/03-student-cabinet.php` | Хартбит урока |
| `GET /api/user` | `auth:sanctum` | Текущий пользователь |

---

## `console.php`

Регистрация closure-команд для `php artisan`. В текущем проекте используется минимально — основные команды в `app/Console/Commands/`.

_Dr. Mārcis Gasūns_
