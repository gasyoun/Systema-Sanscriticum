_Created: 07-05-2026 · Last updated: 05-09-2026_

# config

Конфигурационные файлы Laravel. Значения берутся из `.env` через `env()`.

## Важные для разработки

### `features.php` — флаги функциональности
Управляет включением/отключением функций без деплоя. Сейчас флагов нет: зачет при докупке
(половина блока → целый блок, блоки → полный курс) реализован безусловно в
`Tariff::upgradeCreditForUser()`.

### `services.php` — внешние сервисы
Хранит credentials для:
- **Точка Банк** — ключи платежного шлюза + RSA публичный ключ для верификации JWT.
- **Telegram** — токен бота.
- **VK** — ключ сообщества, секрет подтверждения.
- **n8n** — URL вебхука для Google Sheets (`payment_webhook`).
- **lecture-builder** — base URL Python-микросервиса.

### `app.php` — ядро приложения
- `timezone` = `Europe/Moscow` — **жестко задан**, не берется из `.env`.
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

_Dr. Mārcis Gasūns_
