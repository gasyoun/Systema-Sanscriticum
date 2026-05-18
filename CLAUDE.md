# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

- **Backend**: Laravel 10, PHP 8.1+
- **Frontend**: Vite 5, Tailwind CSS 4, Axios
- **Admin**: Filament v3 (two panels: `admin` at `/admin`, `editor` at `/editor`)
- **Queue**: Laravel Horizon (Redis-backed)
- **DB**: MySQL (production), SQLite in-memory (tests)
- **Docker**: Laravel Sail (`./vendor/bin/sail`)

## Commands

```bash
# Frontend
npm run dev       # Vite dev server
npm run build     # Production build

# Backend
php artisan serve
php artisan migrate
php artisan migrate:fresh --seed

# Tests (SQLite in-memory, no real DB needed)
php artisan test
php artisan test --filter=TestName
php artisan test tests/Unit/
php artisan test tests/Feature/

# Linting
./vendor/bin/pint           # Format PHP (Laravel Pint)

# Queue
php artisan horizon         # Queue monitor at /horizon
php artisan queue:work
```

## Architecture

### Dual Filament Admin Panels

Two separate Filament panels exist with distinct providers:
- `app/Providers/Filament/AdminPanelProvider.php` — full admin, guarded by `is_admin`
- `app/Providers/Filament/LectureEditorPanelProvider.php` — lecture editing only, guarded by `is_lecture_editor`

Resources live in `app/Filament/Resources/` (18 resources) and `app/Filament/Editor/`.

### Payment-Driven Access Control

There is **no manual group assignment**. The `PaymentObserver` (`app/Observers/PaymentObserver.php`) calls `Payment::grantAccess()` automatically on successful payment, which adds the user to the relevant course `Group`. Access to lessons is filtered by the user's groups at query time.

### Block-Based Course Structure

Courses have `CourseBlock` records (time-windowed sections with `starts_at`/`ends_at`). `Tariff` models can scope access to specific blocks. `Tariff::calculateFinalPriceForUser()` handles loyalty discounts (via `MarketingSetting`) and upgrade deductions (deducting what was already paid).

### Landing Page Builder

`LandingPage` stores JSON blocks in a `content` column. The catch-all route at the bottom of `routes/web.php` resolves `/{slug}` to a landing page. Block Blade components live in `resources/views/promo/blocks/`. The feature flag `config('features.upgrade_payments_enabled')` disables upgrade payment flows in config.

### Lecture Subsystem

A separate microservice-style subdirectory (`lecture-builder/`, `lecture-ui/`) is bridged via:
- `LectureBuilderClient` — HTTP client to lecture builder microservice
- `LectureAiClient` — AI-powered features
- `LecturePatcher` / `LecturePublisher` — draft lifecycle management
- `LectureDraft` model — stores draft state before publishing

### Activity Tracking

Three-layer system:
1. `TrackUserActivity` middleware — updates `users.last_activity_at` on every request
2. `ActivityTracker` service — appends to `ActivityEvent` (append-only log)
3. `LessonView` model — per-lesson open count, time spent, heartbeat timestamp (AJAX `/api/heartbeat` from the lesson player page)

### Key Domain Relationships

```
User ──< Payment >── Tariff ──> Course
User ──< UserGroup >── Group ──< LessonGroup >── Lesson
Course ──< CourseBlock
Course ──< Lesson
Teacher ──< TeacherPayout (salary models: percent | per_student | per_block | fixed)
LandingPage ──> JSON blocks
```

## External Integrations

- **Tochka Bank** — payment provider; webhook at `/api/webhooks/tochka`
- **Telegram** — bot webhook at `/api/telegram/webhook`; `User::sendTelegramMessage()`
- **VK** — webhook at `/api/vk-webhook`; `User::sendVkMessage()`
- **DomPDF** — certificate PDF generation via `CertificateService`

### Lead-magnet bots (Telegram / VK / MAX)

Отдельные webhook-эндпоинты для доставки lead-магнита, не пересекающиеся с основными:

- Telegram: `POST /api/webhooks/telegram-magnet` (header-secret `X-Telegram-Bot-Api-Secret-Token`)
- VK: `POST /api/webhooks/vk-magnet` (secret в body)
- MAX: `POST /api/webhooks/max-magnet/{secret}` (secret в **path** — Max Bot API не поддерживает header/body-секрет)

**Ротация `max_webhook_secret`:** так как у Max секрет идёт в URL, он может всплыть в логах reverse-прокси, CDN и в access-логах nginx. При любом инциденте (доступ к логам, утечка дампа БД) — перегенерировать секрет в админке `MarketingSetting` и заново запустить `php artisan max:set-magnet-webhook`. Аналогично — после смены админ-аккаунта.

Все webhook-секреты и bot-токены шифруются в БД через Eloquent `encrypted` cast (`MarketingSetting::$casts`).

## Environment Notes

- Timezone is hardcoded to `Europe/Moscow` in `config/app.php`
- Feature flags in `config/features.php` (e.g., `upgrade_payments_enabled`)
- Admin seeding credentials from `ADMIN_EMAIL` / `ADMIN_PASSWORD` env vars
- Force HTTPS is applied in `AppServiceProvider` for production
