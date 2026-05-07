# config

Конфигурационные файлы Laravel. Значения берутся из `.env` через `env()`.

## Важные для разработки

### `features.php` — флаги функциональности
```php
'upgrade_payments_enabled' => env('UPGRADE_PAYMENTS_ENABLED', false)
```
Управляет включением/отключением функций без деплоя. Текущий статус:
- `upgrade_payments_enabled` — **выключен** (логика готова в `Tariff`, UI и флоу не завершены).

### `services.php` — внешние сервисы
Хранит credentials для:
- **Точка Банк** — ключи платёжного шлюза + RSA публичный ключ для верификации JWT.
- **Telegram** — токен бота.
- **VK** — ключ сообщества, секрет подтверждения.
- **n8n** — URL вебхука для Google Sheets (`payment_webhook`).
- **lecture-builder** — base URL Python-микросервиса.

### `app.php` — ядро приложения
- `timezone` = `Europe/Moscow` — **жёстко задан**, не берётся из `.env`.
- `locale` = `ru` (добавлена поддержка русского).

### `horizon.php` — очереди
Настройки воркеров по очередям:
- `default` — основные задачи.
- `tracking` — `TrackLessonViewJob` (низкий приоритет).
- `mailing` — email-рассылки.

Дашборд доступен на `/horizon` только для администраторов (настраивается в `HorizonServiceProvider`).

## Стандартные конфиги (не менялись)

| Файл | Назначение |
|---|---|
| `auth.php` | Guard `web` (session) + guard `api` (sanctum). |
| `cache.php` | Драйвер Redis для продакшна, array для тестов. |
| `database.php` | MySQL (прод) + SQLite in-memory (тесты через `phpunit.xml`). |
| `filesystems.php` | Диски: `local`, `public` (symlink в public/storage). |
| `mail.php` | SMTP-конфиг. Локально — Mailpit. |
| `queue.php` | Драйвер Redis для продакшна. |
| `sanctum.php` | TTL API-токенов. |
| `session.php` | Cookie-based сессии. |
| `dompdf.php` | Настройки PDF-генератора (кириллица, шрифты). |
| `backup.php` | Spatie Backup: пути, расписание, уведомления о статусе. |
| `social.php` | OAuth-провайдеры (подготовлен, не активирован). |
| `logging.php` | Канал `daily` (ротация логов по дням). |
| `cors.php` | CORS для API-маршрутов. |
