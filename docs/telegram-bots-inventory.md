# Telegram-боты и TG-аккаунты в Systema Sanscriticum

_Created: 30-07-2026 · Last updated: 05-09-2026_

Инвентарь **Bot API-ботов**, **landing-ботов** и **userbot-аккаунта** (MadelineProto),
которые использует LMS на `samskrte.ru`.  
Соседний док только про MTProto-раннер:
[`telegram-userbot-inventory.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md).

> **Секреты.** Токены в git **не** хранятся. В `.env` / `MarketingSetting` (encrypted)
> / `landing_bots`. Ниже — только usernames, env-ключи и назначение.
>
> **Снимок прод-значений** (usernames) — 30-07-2026, `root@193.232.229.92`,
> `/var/www/html`. После ротации/смены бота обновить этот файл.

---

## 1. Схема «кто за что»

```
Студент в кабинете          →  @samskrtamru_bot     (STUDENT_TELEGRAM_BOT_*)
Lead-магнит / марафон drip  →  @samskrte_bot        (MarketingSetting.tg_bot_*)
Запись на занятие           →  @zapisi_ORSbot       (MarketingSetting.zapisi_*)
Служебные алерты LMS        →  TELEGRAM_BOT_*       (на проде: @testpodpiska12_bot)
Саппорт / harvest чатов     →  userbot @rusamskrtam (TELEGRAM_SUPPORT_*, MTProto)
«Написать в Telegram» (UX)  →  t.me/rusamskrtam     (человек/аккаунт, не LMS-бот)
Grok в «Отделе заботы»      →  @grokusaurus_bot     (ПК Марциса, не VPS)
Лендинги (отдельные)        →  landing_bots.*       (напр. @webinar_17june_bot)
```

Вебхуки Telegram с прода **напрямую** часто не доходят; входной узел:

- `TELEGRAM_WEBHOOK_BASE_URL` (прод: `https://103.112.71.201`)
- опц. `TELEGRAM_WEBHOOK_CERTIFICATE`  
  см. `config/services.php` → `telegram_webhook`, `App\Support\TelegramWebhooks`.

---

## 2. Bot API — основные боты

### 2.1. Основной служебный бот

| | |
|--|--|
| **Env** | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` |
| **Config** | `config('services.telegram.bot_token')` / `bot_username` |
| **Прод username** | `@testpodpiska12_bot` (в `.env` есть комментарий «замени на реальный» — сверить с BotFather) |
| **Webhook secret** | `TELEGRAM_BOT_WEBHOOK_SECRET` → middleware `VerifyTelegramBotWebhook` |
| **Маршрут** | `POST /api/telegram/webhook` |

**Назначение:** исходящие служебные сообщения LMS (алерты `cabinet:probe`, дайджесты,
часть curator/ops-уведомлений), чаты кураторов / маркетологов / онбординга
(`TELEGRAM_CURATORS_CHAT_ID`, `TELEGRAM_MARKETERS_CHAT_ID`, `TELEGRAM_ONBOARDING_CHAT_ID`),
`ADMIN_TELEGRAM_ID`.

### 2.2. Студенческий бот кабинета

| | |
|--|--|
| **Env** | `STUDENT_TELEGRAM_BOT_TOKEN`, `STUDENT_TELEGRAM_BOT_USERNAME` |
| **Config** | `services.telegram.student_bot_*` |
| **Прод username** | `@samskrtamru_bot` |
| **Тумблер UI** | `MarketingSetting.student_telegram_bot_enabled` |

**Назначение:** привязка Telegram в кабинете, ИИ-куратор, личные уведомления
студенту. Заведён **отдельно**, чтобы основной бот не смешивался со служебными
чатами. Пустой student-token → fallback на основной бот (обратная совместимость).

**Не путать с человеком.** Личные диалоги с преподавателями ведёт
**Марцис Гасунс** со своего аккаунта. В Telegram он **всегда Гасунс**.
`@samskrtamru_bot` — бот LMS, не он. 14-08-2026 бот отправил Костиной
ссылку на [issue #1709](https://github.com/gasyoun/Systema-Sanscriticum/issues/1709)
(~18:56 МСК); в тот же день Гасунс спросил её **лично** в Telegram.

### 2.3. Lead-магнит / марафон (`@samskrte_bot`)

| | |
|--|--|
| **Хранение** | `MarketingSetting`: `tg_bot_username`, `tg_bot_token` (encrypted), `tg_webhook_secret` |
| **Прод username** | `@samskrte_bot` |
| **Маршрут** | `POST /api/webhooks/telegram-magnet` (header `X-Telegram-Bot-Api-Secret-Token`) |

**Назначение:** лид-магниты, deep-link `magnet_token`, drip марафона Day 1–3,
публикация в канал `@samskrte` (если бот — админ канала с правом постинга).

Не путать с **каналом** [@samskrte](https://t.me/samskrte).

### 2.4. Записи занятий (`@zapisi_ORSbot`) — Track C

| | |
|--|--|
| **Хранение** | `MarketingSetting.zapisi_bot_username`, `zapisi_bot_token`, `zapisi_webhook_secret`, `zapisi_chat_id`, шаблон напоминания |
| **Флаг** | `TELEGRAM_ZAPISI_BOT_ENABLED` / `features.telegram_zapisi_bot` (прод: **true**) |
| **Прод username** | `@zapisi_ORSbot` |
| **Приём апдейтов** | штатно webhook через входной узел; аварийно long-poll `zapisi:poll` (`TELEGRAM_ZAPISI_POLL_ENABLED`, default false) |
| **Config** | `config('services.telegram_zapisi')` |

**Назначение:** чат бронирования занятий, напоминания, дашборд Filament «Записи (бот)»,
roster/harvest peer (совместно с Track B).

Privacy mode бота — снять в [@BotFather](https://t.me/BotFather) (см. DEPLOY_QUEUE №41).

### 2.5. Grok в «Отделе заботы» (`@grokusaurus_bot`) — не LMS

| | |
|--|--|
| **Username** | [@grokusaurus_bot](https://t.me/grokusaurus_bot) |
| **Где живёт** | long-poll на **ПК Марциса**, не на VPS, не в `.env` прода |
| **Чат** | «Отдел заботы \| Рабочая группа» |
| **Токен** | только `C:\Users\user\.grok\channels\telegram\.env` (не коммитить) |

**Назначение:** кураторы зовут локальный Grok Build, когда сайт / кабинет / ДЗ
лежат. Не вебхук LMS, не `@samskrtamru_bot`, не userbot `@rusamskrtam`.
Тег `@rusamskrtam` в том чате бот тоже читает как вызов себе.

**Кураторам:** [MANUAL_CURATOR_GROK_ZABOTA_BOT_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_CURATOR_GROK_ZABOTA_BOT_RU.md).

---

## 3. Боты лендингов (`landing_bots`)

Таблица `landing_bots`: отдельный TG-токен/секрет на лендинг, webhook  
`POST /api/webhooks/telegram-magnet/{webhookKey}`.

| Поле | Смысл |
|------|--------|
| `tg_bot_username` / `tg_bot_token` / `tg_webhook_secret` | Bot API (token encrypted) |
| `webhook_key` | сегмент URL |
| `n8n_forward_url` | опциональный forward в n8n |
| `is_active` | вкл/выкл |

**Снимок прода (30-07-2026):** 6 строк; с явным username:

- `@webinar_17june_bot` (active, с n8n forward)
- остальные: username `null`, токен в БД есть (legacy / без username в карточке)

---

## 4. Userbot (не Bot API)

| | |
|--|--|
| **Аккаунт** | `@rusamskrtam` (user account, MTProto) |
| **Env** | `TELEGRAM_SUPPORT_ENABLED`, `TELEGRAM_SUPPORT_API_ID`, `TELEGRAM_SUPPORT_API_HASH`, `TELEGRAM_SUPPORT_SESSION`, … |
| **Команды** | `telegram-support:sync`, `telegram-support:healthcheck`, harvest/roster (`TELEGRAM_HARVEST_*`) |
| **Сессия** | **одна** на support+harvest — **не** гонять параллельно два синка (риск `AUTH_RESTART`) |

Подробности раннера: [`telegram-userbot-inventory.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md).

Тот же username часто в UX как **живой саппорт**: кнопка «Написать в Telegram» →
`https://t.me/rusamskrtam` (страница fail-оплаты и др.) — это **не** вызов Bot API.

---

## 5. Соседние «боты» (не Telegram)

| Канал | Env / настройки | Прод (30-07-2026) |
|--------|-----------------|-------------------|
| VK bot | `VK_BOT_TOKEN`, student VK flags | токен задан |
| MAX lead-magnet | `MarketingSetting.max_bot_*` | null (не настроен) |
| VK magnet | body secret | см. marketing webhooks |

---

## 6. Каналы и чаты (не боты)

| Сущность | Назначение |
|----------|------------|
| Канал `@samskrte` | публичные посты (марафон, evergreen) |
| `ADMIN_TELEGRAM_ID` | critical/soft алерты probe и др. (несколько id через запятую) |
| `TELEGRAM_CURATORS_CHAT_ID` | чат кураторов (бот должен быть в чате) |
| `TELEGRAM_MARKETERS_CHAT_ID` | лиды |
| `TELEGRAM_ONBOARDING_CHAT_ID` | первый вход / weekly digest |
| `CABINET_PROBE_TELEGRAM_CHAT_ID` | override для probe (иначе admin id) |
| `zapisi_chat_id` | peer чата бронирования @zapisi_ORSbot |

---

## 7. Маршруты вебхуков (TG)

| URL | Бот / поток |
|-----|-------------|
| `POST /api/telegram/webhook` | кабинетный / student webhook (secret header) |
| `POST /api/webhooks/telegram-magnet` | глобальный lead-магнит (`MarketingSetting.tg_*`) |
| `POST /api/webhooks/telegram-magnet/{webhookKey}` | landing bot |
| zapisi webhook path | через входной узел / MarketingSetting (см. deploy checklist) |

Матрица секретов: [`webhook-security.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md).

---

## 8. Регистрация / ротация

```bash
# общий helper (см. artisan list telegram: / max: / zapisi)
php artisan telegram:set-magnet-webhook   # магнит
# student / main — по runbook в docs/deploy-checklist-audit-fixes.md

# после смены TELEGRAM_WEBHOOK_BASE_URL — перерегистрировать ВСЕ боты,
# иначе «мёртвый» edge (инцидент @zapisi_ORSbot 22.07.2026).
```

Ротация: новый token в BotFather → `.env` или Filament Marketing settings →
`config:clear` → setWebhook с актуальным `base_url` + secret.

---

## 9. Чеклист «какой бот сломался»

| Симптом | Куда смотреть |
|---------|----------------|
| Нет пушей студенту / не привязывается TG | `@samskrtamru_bot`, student token, webhook |
| Не приходит магнит / drip марафона | `@samskrte_bot`, `tg_webhook_secret`, schedule `marathon:deliver-*` |
| Нет сообщений в «Записи» | `@zapisi_ORSbot`, edge URL, privacy mode, poll vs webhook |
| Нет алертов probe / CSRF digest | `TELEGRAM_BOT_TOKEN`, `ADMIN_TELEGRAM_ID` / `CABINET_PROBE_TELEGRAM_*` |
| Helpdesk не тянет ЛС | userbot session, `TELEGRAM_SUPPORT_ENABLED`, `telegram-support:sync` |
| `AUTH_RESTART` | два клиента на одной session (support + harvest / внешний демон) |

---

## 10. Связанные файлы

| Файл | Роль |
|------|------|
| [`config/services.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/services.php) | `telegram`, `telegram_support`, `telegram_harvest`, `telegram_zapisi`, `telegram_webhook` |
| [`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php) | `telegram_zapisi_bot` и др. |
| `app/Models/MarketingSetting.php` | `tg_*`, `zapisi_*`, student bot flags |
| `app/Models/LandingBot.php` | per-landing bots |
| [`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) | T1–T5, №41 zapisi, №13 marathon bot |
| [`docs/webhook-security.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md) | fail-policy вебхуков |

---

_Dr. Mārcis Gasūns / ops inventory — сверка с продом 30-07-2026._

_Dr. Mārcis Gasūns_
