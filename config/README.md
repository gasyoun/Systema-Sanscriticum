_Created: 07-05-2026 · Last updated: 12-11-2026_

# config

Конфигурационные файлы Laravel. Значения берутся из `.env` через `env()`.

## Важные для разработки

### `tracked_links.php` — сборщик, кампании в модулях
Публичные короткие ссылки `/ga/<ключ>` и story-атрибуция. После разбивки
YBQ (рулинг MG 12-11-2026 «разбить») сам файл — тонкий сборщик (≈2.9 КБ):
таблица `$channels`, явные `require` модулей и `return`. Модули кампаний
лежат в [`resources/tracked_links/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/resources/tracked_links) —
**вне `config/`**: лоадер конфигов Laravel (рекурсивный Finder) подхватил бы
их как `tracked_links.<имя>` и выполнил без общей области видимости
(`$channels`, `$links` и т.д.). Каждый модуль дописывает свои ключи в
`$links` / `$storyCampaigns` через область видимости сборщика.

**Новая кампания:** создать `resources/tracked_links/<слаг>.php`
(шаблон — любой соседний модуль: `declare(strict_types=1)`, комментарий
с провенансом, дописка в `$links`) и добавить строку `require` в
`config/tracked_links.php`. Никогда не дописывать блоки кампаний прямо в
сборщик — он под бюджетом token-size-gate (5000 токенов) и должен им
оставаться.

### `features.php` — флаги функциональности
Управляет включением/отключением функций без деплоя. Крупный реестр флагов
(клубное членство, студент-агент, Digital Kassa и др.); зачет при докупке
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

## Стандартные конфиги

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
| `social.php` | Социальные ссылки в шапке кабинета (VK/Telegram/Facebook/сайт/телефон/email из `.env`). |
| `logging.php` | Канал `daily` (ротация логов по дням). |
| `cors.php` | CORS для API-маршрутов. |

_Dr. Mārcis Gasūns_
