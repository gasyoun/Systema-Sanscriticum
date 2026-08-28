# RUNBOOK: «Telegram-вход» в личный кабинет

_Created: 28-08-2026 · Last updated: 28-08-2026_

Источник требования: ORS-FAQ
[CABINET_ADOPTION_ROADMAP.md](https://github.com/gasyoun/ORS-FAQ/blob/main/CABINET_ADOPTION_ROADMAP.md)
§ P2 «Telegram-вход (бóльшая фича)» — для студентов, чья почта не доходит
(спам/фильтры): студент пишет студент-боту → получает одноразовую ссылку входа
в кабинет без email вообще.

## Что построено (repo-side, флаги OFF)

| Компонент | Файл |
|---|---|
| Флаги | [`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php) — `telegram_cabinet_login` (мастер), `telegram_cabinet_email_link` (под-режим) |
| Выдача ссылок | [`app/Services/Access/TelegramLoginService.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Access/TelegramLoginService.php) — purpose `tg_login`, TTL 15 мин, лимит 5/чат |
| Бот-логика | [`app/Services/Bot/CabinetLoginBotCommand.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/CabinetLoginBotCommand.php) — `/вход` // `/login` // `/start`; email-матч среди оплативших |
| Вебхук | [`app/Http/Controllers/TelegramWebhookController.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TelegramWebhookController.php) — ветки входа; при OFF поведение прежнее |
| Поглощение ссылки | [`app/Http/Controllers/TgLoginLinkController.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TgLoginLinkController.php) — `GET /tg-login/{token}`, принимает ТОЛЬКО `tg_login`, одноразово, 404-без-деталей |
| Тесты | [`tests/Feature/Access/TelegramCabinetLoginTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Access/TelegramCabinetLoginTest.php) — 11 тестов (флаги OFF/ON, одноразовость, чужие purpose, staff-исключение, лимит) |

Гарантии ссылки — те же, что у сброса пароля: plaintext только в чате, в БД
SHA-256 (`magic_link_tokens`), короткий TTL, атомарное гашение, назначение
гасится только на своём маршруте.

## Сценарии

1. **Уже привязанный** (`users.telegram_id` есть): пишет `/start` или `/вход`
   → бот шлёт кнопку-ссылку `/tg-login/…` (15 мин, один клик, вход с
   remember). Владение Telegram = фактор подлинности.
2. **Не привязанный** (почта не доходит — целевой кейс): присылает боту email
   заказа → матч по нормализованному email СРЕДИ ОПЛАЧИВАВШИХ
   (`payments.status=paid`), staff (admin/manager/super_admin) исключён →
   привязка `telegram_id` + ссылка входа. **За отдельным флагом** — размен
   «знает email = получит доступ» сильнее разрешённой «самопроверки входа».
3. **Куратор-ссылка** — уже существовала (H849, `/unblock` + LoginLinkNotifier
   с TG-доставкой); «Telegram-вход» её не заменяет.

Побочный эффект: как только у студентов заполняется `telegram_id`, текущая
еженедельная волна [`students:send-login-invites`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SendCabinetInvites.php)
сама начинает слать в Telegram (приоритет TG→VK→SMS→email уже в коде, H3499-волна была email-only именно из-за пустых `telegram_id`).

## Включение в проде (владелец, окна ops)

1. Проверить, что у студент-бота жив вебхук: `STUDENT_TELEGRAM_BOT_TOKEN` в
   прод-.env; если вебхук не ставлен —
   `setWebhook?url=https://samskrte.ru/api/telegram/webhook&secret_token=<TELEGRAM_BOT_WEBHOOK_SECRET>`.
2. Выкатить релиз, затем `TELEGRAM_CABINET_LOGIN=true` +
   `php artisan config:cache` (мастер-флаг; сценарий 1).
3. **@DECIDE MG:** включать ли `TELEGRAM_CABINET_EMAIL_LINK=true` +
   `config:cache` (сценарий 2 — доступ по email заказа). Пока OFF — бот
   отвечает «привяжите через сайт», поведение прежнее.
4. Smoke: от привязанного тестового студента `/вход` → ссылка → клик →
   кабинет; повторный клик по той же ссылке → 404.

## Исполнение 28-08-2026 (MG «do now», OxAlpha)

- **Сценарий 1 ВКЛЮЧЁН.** Прод уже был на merge `762b783e` (auto-deploy);
  `TELEGRAM_CABINET_LOGIN=true` + `config:cache`; tinker-проверка:
  `FLAG_ON | email_link=false`.
- **Consumer-smoke на проде — зелёный:** throwaway-юзер (без платежей,
  `login_count=1`, чтобы не зажечь first-login-нотификацию) → issued
  `tg_login` → `GET /tg-login/<token>` → **302 → /dvaram** (вход), повторный
  GET → **404**, мусорный токен → **404**; в БД `consumed=true`,
  `login_count 1→2` (Login-событие = adoption-KPI считает TG-входы); юзер и
  токен удалены, реальные аккаунты не тронуты.
- **Находка: вебхук студент-бота был осиротелен.** `getWebhookInfo` показывал
  URL на ЧУЖОМ хосте `https://103.112.71.201/api/telegram/webhook` (давний
  IP; `last_error: Connection timed out`), nginx прода не получал
  `POST /api/telegram/webhook` ВООБЩЕ — т.е. не только «Telegram-вход», но и
  весь бот-канал (ИИ-куратор, уведомления) не доставлял входящие. Секрет
  `TELEGRAM_BOT_WEBHOOK_SECRET` в .env был (64 симв.), локальный POST по
  публичному URL давал fail-closed 403 → переустановили
  `setWebhook` (url + secret_token): `getWebhookInfo` теперь показывает
  `https://samskrte.ru/api/telegram/webhook`, stale-ошибка уйдёт с первой
  доставкой. Урок: при любом бот-плече сверять `getWebhookInfo.url` с
  прод-доменом — бот молча сиротеет при переезде хоста.
- **Остался человеческий smoke (30 секунд):** написать боту `/вход` с
  привязанного аккаунта → кнопка-ссылка → кабинет. Живых привязанных
  сотрудников нет (29 привязанных — все студенты), исходный тест-месседж
  студентам не шлётся сознательно.

## Ограничения (осознанные)

- Лимит 5 ссылок на чат за 15 мин (анти-спам), TTL 15 мин.
- Email наружу не эхом: «нет адреса / не платил / staff» неразличимы.
- Легаси-привязка через сайт («Подключить Telegram») не тронута; deep-link
  `/start {token}` приоритетнее входных веток.

_Dr. Mārcis Gasūns_
