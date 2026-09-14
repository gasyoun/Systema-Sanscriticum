# Безопасность вебхуков — матрица доступа и fail-policy

_Created: 07-07-2026 · Last updated: 16-08-2026_

Сводка по всем входящим вебхук-/push-эндпоинтам: чем аутентифицируется, что
происходит при **пустом** секрете (fail-open / fail-closed) и где это покрыто
тестом. Составлено в ходе security-прохода (roadmap A.3); **гэпов не найдено** —
документ фиксирует текущую (корректную) посту́ру, чтобы ее не пере-выводить.

> **Fail-closed is the rule.** Telegram- and VK-bot webhooks have been fail-closed
> since 02-07-2026
> ([`VerifyTelegramBotWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/VerifyTelegramBotWebhook.php),
> [`VerifyVkBotWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/VerifyVkBotWebhook.php)).
> Zoom is also fail-closed in code: empty `ZOOM_WEBHOOK_SECRET` → 503
> (`ZoomWebhookTest::missing_secret_returns_503_*`). URL-validation still answers
> the HMAC challenge **after** the secret is present — it does not skip auth when
> the secret is missing. New endpoints are fail-closed from birth (empty secret =
> 403/401). Prod secrets for Telegram / VK / Zoom / lesson-sync were **SET** on
> 16-08-2026 (H2896; values not logged).

## Матрица

| Эндпоинт | Контроллер / middleware | Механизм | Пустой секрет | Деньги/доступ | Тест fail-policy |
|---|---|---|---|---|---|
| `POST /api/webhooks/tochka` | `WebhookController::handleTochkaWebhook` | RS256 JWT (публичный ключ Точки, fallback-константа) | **fail-closed** (ключ всегда есть; невалид → 401) | 💰 выдает доступ | `TochkaWebhookTest` (подпись + идемпотентность) |
| `POST /api/sync-lessons` | `Api\LessonController::sync` → `guard()` | `X-Secret-Key` = `services.lesson_sync.secret`, `hash_equals` | **fail-closed** (пусто → 401) | 🔒 пишет уроки | общий `guard()` покрыт `LessonFromZoomTest` |
| `POST /api/lessons/from-zoom` | `Api\LessonController::storeFromZoom` → `guard()` | то же | **fail-closed** (пусто → 401) | 🔒 пишет уроки | `LessonFromZoomTest` (нет/неверный секрет → 401) |
| `POST /api/webhooks/zoom` | `Webhooks\ZoomWebhookController::handle` | `x-zm-signature` = `v0=HMAC_SHA256("v0:{ts}:{body}")`, `ZOOM_WEBHOOK_SECRET` | **fail-closed** (пусто → 503; неверная подпись → 403) | 🔒 пишет посещаемость | `ZoomWebhookTest` (`missing_secret_returns_503_*`) |
| `POST /api/telegram/webhook` | `verify.tg.bot` (`VerifyTelegramBotWebhook`) | заголовок `X-Telegram-Bot-Api-Secret-Token`, `services.telegram.bot_webhook_secret` | **fail-closed** с 02-07-2026 (пусто → 403; ранее было enforce-if-configured) | уведомления | `BotWebhookSignatureTest` |
| `POST /api/vk-webhook` | `verify.vk.bot` (`VerifyVkBotWebhook`) | body-поле `secret`, `services.vk.callback_secret`; `type=confirmation` пропускается | **fail-closed** с 02-07-2026 (пусто → 403; ранее было enforce-if-configured) | уведомления | `BotWebhookSignatureTest` |
| `POST /api/webhooks/telegram-magnet[/{webhookKey}]` | `verify.tg.magnet` (`VerifyTelegramMagnetWebhook`) | `X-Telegram-Bot-Api-Secret-Token`; per-bot секрет `LandingBot.tg_webhook_secret` либо `MarketingSetting.tg_webhook_secret` | **fail-closed** (пусто → 403) | лид-магнит | `TelegramMagnetWebhookTest`, `LandingBotMagnetTest` |
| `POST /api/webhooks/vk-magnet` | `verify.vk.magnet` (`VerifyVkMagnetCallback`) | body `secret` = `MarketingSetting.vk_callback_secret`; confirmation с **anti-replay** (повтор после завершения → 403) | **fail-closed** (пусто → 403) | лид-магнит | `VkMagnetCallbackTest` (в т.ч. повторный confirmation → 403) |
| `POST /api/webhooks/max-magnet/{secret}` | `verify.max.magnet` (`VerifyMaxMagnetWebhook`) | секрет **в URL-пути** = `MarketingSetting.max_webhook_secret` (Max Bot API не поддерживает header/body-секрет) | **fail-closed** (пусто → 403) | лид-магнит | `MaxMagnetWebhookTest` |
| `POST /api/webhooks/lead-step` | `verify.n8n.leadstep` (`VerifyLeadStepWebhook`) | заголовок `X-Webhook-Secret` = `services.n8n.lead_step_secret` | **fail-closed** (пусто → 403) | трекинг лида | `LeadStepWebhookTest` |
| `POST /api/webhooks/telegram-zapisi` | `verify.tg.zapisi` (`VerifyTelegramZapisiWebhook`) | `X-Telegram-Bot-Api-Secret-Token` = `MarketingSetting.zapisi_webhook_secret` | **fail-closed** с рождения (пусто → 403) | Track C (H164) — приватный class-booking чат, PII | `TelegramZapisiWebhookTest` |
| `POST /api/webhooks/inbound-email/{secret}` | `verify.inbound.email` (`VerifyInboundEmailWebhook`) + `throttle:30,1`; контроллер 404 при флаге OFF | секрет **в URL-пути** = `services.inbound_email.webhook_secret` (проводник пересылки не обязан уметь заголовки; ротация — новый secret в .env + правка проводника) | **fail-closed** с рождения (пусто → 403) | поддержка, не деньги: пишет chat_messages (H3462) | `InboundEmailWebhookTest` |

💰 = выдает платный доступ · 🔒 = пишет данные доступа/контента.

## Ключевые свойства

- **Сравнение секретов — `hash_equals`** во всех middleware (constant-time, защита от timing-атак).
- **Секреты шифруются в БД.** `MarketingSetting` кастует `tg_bot_token`/`tg_webhook_secret`/`vk_access_token`/`vk_callback_secret`/`max_bot_token`/`max_webhook_secret`/`zapisi_bot_token`/`zapisi_webhook_secret` как `encrypted` (Eloquent cast, свойство `$casts`). Утечка дампа БД не раскрывает секреты.
- **Все деньги-/доступ-критичные эндпоинты fail-closed** (Tochka, sync-lessons, from-zoom), **как и легаси-бот-вебхуки** (Telegram, VK — с 02-07-2026) **и Zoom** (пусто → 503). URL-validation is a signed challenge once the secret exists; it is not a fail-open gate.
- **Идемпотентность** на эффектах: Tochka — `lockForUpdate` на платеже; from-zoom/zoom-recording — upsert по `(course_id, group_id, lesson_date)`; vk/max-magnet — по токену привязки.

## Секрет Max в URL — особый риск

У Max Bot API секрет идет **в пути** (`/webhooks/max-magnet/{secret}`), т.к. API не
поддерживает header/body-секрет. Путь может попасть в access-логи nginx/CDN/прокси.
**Ротация при инциденте** (доступ к логам, утечка дампа): перегенерировать
`max_webhook_secret` в админке (`MarketingSetting`) → `php artisan max:set-magnet-webhook`.
То же — после смены админ-аккаунта. См. `CLAUDE.md` (раздел lead-magnet bots).

## Деплой-чек-лист (прод-секреты)

**Closed 16-08-2026 (H2896).** Laravel config on the live box reports
`TELEGRAM_BOT_WEBHOOK_SECRET`, `VK_CALLBACK_SECRET`, `ZOOM_WEBHOOK_SECRET`, and
`LESSON_SYNC_SECRET` as **SET** (values not logged). Unsigned live POSTs:
`/api/telegram/webhook` 403, `/api/vk-webhook` 403, `/api/webhooks/zoom` 403,
`/api/webhooks/tochka` 401, `/api/sync-lessons` 401. Empty Zoom secret would
return 503 — 403 means the secret is present and the signature check fired.

Rotate after an incident the same way as before: new env value + re-register
with the provider (`setWebhook` secret_token / VK Callback API secret / Zoom
Event Subscription secret).

_Dr. Mārcis Gasūns_
