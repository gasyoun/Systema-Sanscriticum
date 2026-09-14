_Created: 07-05-2026 · Last updated: 05-09-2026_

# database

Схема БД, фабрики и сидеры.

## Структура

```
database/
├── migrations/   # 99 миграций — полная история схемы
├── seeders/      # DatabaseSeeder — создание тестового администратора
└── factories/    # Eloquent-фабрики для тестов
```

## Основные таблицы и связи

```
users
 ├─< user_groups >─ groups ─< lesson_groups >─ lessons ─< courses
 ├─< payments ─> tariffs ─> courses
 ├─< certificates ─> courses
 ├─< user_sessions
 ├─< activity_events
 └─< lesson_views ─> lessons

courses
 ├─< course_blocks
 ├─< tariffs
 ├─< groups
 ├─< lessons
 └── teacher_id → teachers

landing_pages (slug catch-all)
leads (заявки с лендингов)
articles ─< article_categories
article_views (append-only)
schedules ─> groups / courses
announcements
chat_messages
dictionaries ─< dictionary_words
```

## Ключевые соглашения схемы

- **Иммутабельные логи** (`activity_events`, `article_views`) — нет `updated_at`, только `created_at`. Никогда не обновляй эти таблицы через `UPDATE`.
- **Статусы платежей** — ENUM: `pending`, `paid`, `success`. Переход `paid → success` происходит при подтверждении вебхука от банка.
- **Блочная структура курса** — `course_blocks` содержит `starts_at`, `ends_at`, `is_current`. Тарифы могут быть привязаны к конкретным блокам через `block_number`.
- **Доступ через группы** — таблица `user_groups` (user ↔ group) + `lesson_groups` (lesson ↔ group). Прямой связи user → lesson нет.
- **Slug как ключ маршрутизации** — `courses`, `groups`, `landing_pages`, `articles`, `article_categories` используют slug для URL.

## Сидер

`DatabaseSeeder` создает (или обновляет) одного администратора:
```bash
php artisan db:seed
```
Данные берутся из `.env`: `ADMIN_EMAIL`, `ADMIN_PASSWORD`.

## Добавление миграции

```bash
php artisan make:migration add_field_to_table --table=table_name
```

Имена миграций — snake_case с глаголом действия: `create_`, `add_`, `rename_`, `drop_`.

_Dr. Mārcis Gasūns_
