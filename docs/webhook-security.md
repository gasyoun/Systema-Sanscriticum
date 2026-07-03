# Безопасность вебхуков — матрица доступа и fail-policy

Сводка по всем входящим вебхук-/push-эндпоинтам: чем аутентифицируется, что
происходит при **пустом** секрете (fail-closed) и где это покрыто тестом.
Составлено в ходе security-прохода (roadmap A.3); документ фиксирует текущую
посту́ру, чтобы её не пере-выводить.

> **Принцип:** входящие вебхуки fail-closed. Пустой секрет означает, что endpoint
> не настроен и должен отклонять запрос, кроме локальных/test-only послаблений,
> явно указанных ниже.

## Матрица

| Эндпоинт | Контроллер / middleware | Механизм | Пустой секрет | Деньги/доступ | Тест fail-policy |
|---|---|---|---|---|---|
| `POST /api/webhooks/tochka` | `WebhookController::handleTochkaWebhook` | RS256 JWT (публичный ключ Точки, fallback-константа), финальный статус + совпадение суммы | **fail-closed** (ключ всегда есть; невалид → 401; amount/status mismatch не открывает доступ) | 💰 выдаёт доступ | `TochkaWebhookTest` (подпись, сумма, финальный статус, идемпотентность) |
| `POST /api/sync-lessons` | `Api\LessonController::sync` → `guard()` | `X-Secret-Key` = `services.lesson_sync.secret`, `hash_equals` | **fail-closed** (пусто → 401) | 🔒 пишет уроки | общий `guard()` покрыт `LessonFromZoomTest` |
| `POST /api/lessons/from-zoom` | `Api\LessonController::storeFromZoom` → `guard()` | то же | **fail-closed** (пусто → 401) | 🔒 пишет уроки | `LessonFromZoomTest` (нет/неверный секрет → 401) |
| `POST /api/webhooks/zoom` | `Webhooks\ZoomWebhookController::handle` | `x-zm-signature` = `v0=HMAC_SHA256("v0:{ts}:{body}")`, `ZOOM_WEBHOOK_SECRET`; URL validation только при `ZOOM_URL_VALIDATION_ENABLED=true` | **fail-closed вне local/testing**; URL validation по умолчанию 403 | 🔒 пишет уроки/посещаемость | `ZoomWebhookTest` (плохая/нет подписи → 403, пустой секрет на проде → 403, URL validation default-off) |
| `POST /api/telegram/webhook` | `verify.tg.bot` (`VerifyTelegramBotWebhook`) | заголовок `X-Telegram-Bot-Api-Secret-Token`, `services.telegram.bot_webhook_secret` | **fail-closed** (пусто → 403) | уведомления | `BotWebhookSignatureTest` |
| `POST /api/vk-webhook` | `verify.vk.bot` (`VerifyVkBotWebhook`) | body-поле `secret`, `services.vk.callback_secret`; `type=confirmation` пропускается | **fail-closed** для событий (пусто → 403); confirmation пропускается для регистрации URL | уведомления | `BotWebhookSignatureTest` |
| `POST /api/webhooks/telegram-magnet[/{webhookKey}]` | `verify.tg.magnet` (`VerifyTelegramMagnetWebhook`) | `X-Telegram-Bot-Api-Secret-Token`; per-bot секрет `LandingBot.tg_webhook_secret` либо `MarketingSetting.tg_webhook_secret` | **fail-closed** (пусто → 403) | лид-магнит | `TelegramMagnetWebhookTest`, `LandingBotMagnetTest` |
| `POST /api/webhooks/vk-magnet` | `verify.vk.magnet` (`VerifyVkMagnetCallback`) | body `secret` = `MarketingSetting.vk_callback_secret`; confirmation с **anti-replay** (повтор после завершения → 403) | **fail-closed** (пусто → 403) | лид-магнит | `VkMagnetCallbackTest` (в т.ч. повторный confirmation → 403) |
| `POST /api/webhooks/max-magnet/{secret}` | `verify.max.magnet` (`VerifyMaxMagnetWebhook`) | секрет **в URL-пути** = `MarketingSetting.max_webhook_secret` (Max Bot API не поддерживает header/body-секрет) | **fail-closed** (пусто → 403) | лид-магнит | `MaxMagnetWebhookTest` |
| `POST /api/webhooks/lead-step` | `verify.n8n.leadstep` (`VerifyLeadStepWebhook`) | заголовок `X-Webhook-Secret` = `services.n8n.lead_step_secret` | **fail-closed** (пусто → 403) | трекинг лида | `LeadStepWebhookTest` |

💰 = выдаёт платный доступ · 🔒 = пишет данные доступа/контента.

## Ключевые свойства

- **Сравнение секретов — `hash_equals`** во всех middleware (constant-time, защита от timing-атак).
- **Секреты шифруются в БД.** `MarketingSetting` кастует `tg_bot_token`/`tg_webhook_secret`/`vk_access_token`/`vk_callback_secret`/`max_bot_token`/`max_webhook_secret` как `encrypted` (Eloquent cast, свойство `$casts`). Утечка дампа БД не раскрывает секреты.
- **Все деньги-/доступ-критичные и бот-вебхуки fail-closed** (Tochka, sync-lessons, from-zoom, Zoom вне local/testing, TG/VK bot events).
- **Идемпотентность** на эффектах: Tochka — `lockForUpdate` на платеже; from-zoom/zoom-recording — upsert по `(course_id, group_id, lesson_date)`; vk/max-magnet — по токену привязки.

## Секрет Max в URL — особый риск

У Max Bot API секрет идёт **в пути** (`/webhooks/max-magnet/{secret}`), т.к. API не
поддерживает header/body-секрет. Путь может попасть в access-логи nginx/CDN/прокси.
**Ротация при инциденте** (доступ к логам, утечка дампа): перегенерировать
`max_webhook_secret` в админке (`MarketingSetting`) → `php artisan max:set-magnet-webhook`.
То же — после смены админ-аккаунта. См. `CLAUDE.md` (раздел lead-magnet bots).

## Деплой-чек-лист секретов

Перед выкладкой fail-closed endpoints должны иметь реальные секреты в prod `.env`:
- `services.telegram.bot_webhook_secret` + переустановить вебхук Telegram с `secret_token`.
- `services.vk.callback_secret`.
- `ZOOM_WEBHOOK_SECRET` (+ Event Subscription на `/api/webhooks/zoom`). Для URL validation временно поставить `ZOOM_URL_VALIDATION_ENABLED=true`, пройти проверку в Zoom Marketplace и сразу вернуть `false`.

Zoom URL validation остаётся отдельным временным setup-флагом и в штатном режиме
должен быть выключен.
