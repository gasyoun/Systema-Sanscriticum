_Created: 07-05-2026 · Last updated: 05-09-2026_

# app/Http/Controllers

HTTP-контроллеры. Тонкие — бизнес-логика в сервисах и моделях, контроллер только связывает запрос с логикой.

## Публичные маршруты

| Файл | Маршруты | Описание |
|---|---|---|
| `AuthController.php` | `POST /login`, `POST /logout` | Вход и выход. После входа редиректит: `is_admin` → `/admin`, студент → `/cabinet`. Поддерживает AJAX-ответ для модального окна на странице магазина. |
| `ShopController.php` | `GET /shop`, `GET /shop/{slug}` | Витрина курсов с пагинацией. Страница курса: тарифы, блоки, преподаватель, уже купленные тарифы студента. |
| `CheckoutController.php` | `GET /checkout/{tariff}` | Страница оформления заказа. Применяет промокод, считает финальную цену через `Tariff::calculateFinalPriceForUser()`. |
| `PaymentController.php` | `POST /payment` | Создает платеж: находит/создает пользователя, считает цену, обращается к платежному шлюзу (Точка). |
| `PromoController.php` | `GET /{slug}` (catch-all) | Отдает лендинг по slug. Кешируется 24 часа в Redis. |
| `ArticleController.php` | `GET /s`, `GET /s/{slug}` | Блог: листинг с фильтрами, страница статьи с трекингом просмотров. |
| `LeadController.php` | `POST /lead`, `GET /leads/export` | Сохраняет заявку с UTM. Экспорт CSV только для администраторов (UTF-8 BOM для Excel). |

## Личный кабинет студента

| Файл | Маршруты | Описание |
|---|---|---|
| `StudentController.php` | `/cabinet/*` | Дашборд (мои курсы), урок, завершение урока, заметки, скачивание материалов, сертификат, история платежей, календарь, открытые уроки. |

## Интеграции

| Файл | Маршруты | Описание |
|---|---|---|
| `TelegramController.php` | `GET /telegram/connect` | Генерирует одноразовый токен, редиректит в Telegram-бот с параметром `/start {token}`. |
| `WebhookController.php` | `POST /payment/webhook` | Вебхук Точки. Верифицирует JWT с RSA-ключом, обновляет статус платежа. |

## API (`Api/`)

| Файл | Маршруты | Описание |
|---|---|---|
| `Api/HeartbeatController.php` | `POST /api/heartbeat` | AJAX от плеера урока каждые N секунд. Обновляет `LessonView`, троттлит через Redis (минимум 20 сек между обновлениями). |
| `Api/LessonController.php` | `POST /api/sync-lessons` | Синхронизация уроков из внешней системы. Аутентификация через секретный ключ в заголовке. |
| `Api/VkBotController.php` | `POST /api/vk-webhook` | VK-бот: подтверждение webhook, привязка аккаунта по `ref`, пересылка вопросов AI. |

## Редактор лекций (`Editor/`)

| Файл | Маршруты | Описание |
|---|---|---|
| `Editor/LectureDraftController.php` | `/editor/draft/{id}/preview`, `/editor/draft/{id}/asset/*` | Превью HTML лекции с инъекцией editor.js. Раздача ассетов (слайды, стили) с валидацией пути. |

## Важно для агентов

- **Catch-all роут** (`PromoController`) стоит **последним** в `routes/web.php`. Все новые конкретные маршруты должны быть зарегистрированы **до** него.
- **AJAX-логин**: `AuthController::login()` возвращает JSON `{success, redirect}` если запрос `X-Requested-With: XMLHttpRequest`. Используется в модальном окне на странице оформления заказа.
- **Доступ к урокам**: `StudentController` проверяет принадлежность к группе через `$user->groups()->whereHas('lessons', ...)`. Не дублируй эту проверку в других местах.

_Dr. Mārcis Gasūns_
