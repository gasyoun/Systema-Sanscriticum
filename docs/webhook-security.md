# Безопасность вебхуков — матрица доступа и fail-policy

Сводка по всем входящим вебхук-/push-эндпоинтам: чем аутентифицируется, что
происходит при **пустом** секрете (fail-open / fail-closed) и где это покрыто
тестом. Составлено в ходе security-прохода (roadmap A.3); **гэпов не найдено** —
документ фиксирует текущую (корректную) посту́ру, чтобы ее не пере-выводить.

> **Принцип «enforce-if-configured» (fail-open):** легаси-эндпоинты, которые были
> в проде ДО появления проверки подписи, изначально при пустом секрете пропускали
> запрос — чтобы включение секрета не сломало живой трафик. Telegram- и
> VK-бот-вебхуки переведены на **fail-closed** 02-07-2026
> ([`VerifyTelegramBotWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/VerifyTelegramBotWebhook.php),
> [`VerifyVkBotWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/VerifyVkBotWebhook.php));
> остался один легаси-эндпоинт с enforce-if-configured — Zoom (см. ниже, там это
> осознанный выбор из-за Event Subscription URL-валидации). Новые эндпоинты —
> fail-closed с рождения (пустой секрет = эндпоинт выключен, 403/401). Перевод
> оставшегося Zoom-эндпоинта в fail-closed — это деплой-действие (задать секрет),
> а не правка кода; трекается в `Uprava/GTD_NEXT_ACTIONS.md`.

## Матрица

| Эндпоинт | Контроллер / middleware | Механизм | Пустой секрет | Деньги/доступ | Тест fail-policy |
|---|---|---|---|---|---|
| `POST /api/webhooks/tochka` | `WebhookController::handleTochkaWebhook` | RS256 JWT (публичный ключ Точки, fallback-константа) | **fail-closed** (ключ всегда есть; невалид → 401) | 💰 выдает доступ | `TochkaWebhookTest` (подпись + идемпотентность) |
| `POST /api/sync-lessons` | `Api\LessonController::sync` → `guard()` | `X-Secret-Key` = `services.lesson_sync.secret`, `hash_equals` | **fail-closed** (пусто → 401) | 🔒 пишет уроки | общий `guard()` покрыт `LessonFromZoomTest` |
| `POST /api/lessons/from-zoom` | `Api\LessonController::storeFromZoom` → `guard()` | то же | **fail-closed** (пусто → 401) | 🔒 пишет уроки | `LessonFromZoomTest` (нет/неверный секрет → 401) |
| `POST /api/webhooks/zoom` | `Webhooks\ZoomWebhookController::handle` | `x-zm-signature` = `v0=HMAC_SHA256("v0:{ts}:{body}")`, `ZOOM_WEBHOOK_SECRET` | **fail-open** (enforce-if-configured) | 🔒 пишет уроки/посещаемость | `ZoomWebhookTest` (плохая/нет подписи → 403, пустой секрет → пропуск) |
| `POST /api/telegram/webhook` | `verify.tg.bot` (`VerifyTelegramBotWebhook`) | заголовок `X-Telegram-Bot-Api-Secret-Token`, `services.telegram.bot_webhook_secret` | **fail-closed** с 02-07-2026 (пусто → 403; ранее было enforce-if-configured) | уведомления | `BotWebhookSignatureTest` |
| `POST /api/vk-webhook` | `verify.vk.bot` (`VerifyVkBotWebhook`) | body-поле `secret`, `services.vk.callback_secret`; `type=confirmation` пропускается | **fail-closed** с 02-07-2026 (пусто → 403; ранее было enforce-if-configured) | уведомления | `BotWebhookSignatureTest` |
| `POST /api/webhooks/telegram-magnet[/{webhookKey}]` | `verify.tg.magnet` (`VerifyTelegramMagnetWebhook`) | `X-Telegram-Bot-Api-Secret-Token`; per-bot секрет `LandingBot.tg_webhook_secret` либо `MarketingSetting.tg_webhook_secret` | **fail-closed** (пусто → 403) | лид-магнит | `TelegramMagnetWebhookTest`, `LandingBotMagnetTest` |
| `POST /api/webhooks/vk-magnet` | `verify.vk.magnet` (`VerifyVkMagnetCallback`) | body `secret` = `MarketingSetting.vk_callback_secret`; confirmation с **anti-replay** (повтор после завершения → 403) | **fail-closed** (пусто → 403) | лид-магнит | `VkMagnetCallbackTest` (в т.ч. повторный confirmation → 403) |
| `POST /api/webhooks/max-magnet/{secret}` | `verify.max.magnet` (`VerifyMaxMagnetWebhook`) | секрет **в URL-пути** = `MarketingSetting.max_webhook_secret` (Max Bot API не поддерживает header/body-секрет) | **fail-closed** (пусто → 403) | лид-магнит | `MaxMagnetWebhookTest` |
| `POST /api/webhooks/lead-step` | `verify.n8n.leadstep` (`VerifyLeadStepWebhook`) | заголовок `X-Webhook-Secret` = `services.n8n.lead_step_secret` | **fail-closed** (пусто → 403) | трекинг лида | `LeadStepWebhookTest` |

💰 = выдает платный доступ · 🔒 = пишет данные доступа/контента.

## Ключевые свойства

- **Сравнение секретов — `hash_equals`** во всех middleware (constant-time, защита от timing-атак).
- **Секреты шифруются в БД.** `MarketingSetting` кастует `tg_bot_token`/`tg_webhook_secret`/`vk_access_token`/`vk_callback_secret`/`max_bot_token`/`max_webhook_secret` как `encrypted` (Eloquent cast, свойство `$casts`). Утечка дампа БД не раскрывает секреты.
- **Все деньги-/доступ-критичные эндпоинты fail-closed** (Tochka, sync-lessons, from-zoom), **как и легаси-бот-вебхуки** (Telegram, VK — с 02-07-2026). Fail-open остается только у Zoom — там это осознанный enforce-if-configured (Event Subscription URL-валидация).
- **Идемпотентность** на эффектах: Tochka — `lockForUpdate` на платеже; from-zoom/zoom-recording — upsert по `(course_id, group_id, lesson_date)`; vk/max-magnet — по токену привязки.

## Секрет Max в URL — особый риск

У Max Bot API секрет идет **в пути** (`/webhooks/max-magnet/{secret}`), т.к. API не
поддерживает header/body-секрет. Путь может попасть в access-логи nginx/CDN/прокси.
**Ротация при инциденте** (доступ к логам, утечка дампа): перегенерировать
`max_webhook_secret` в админке (`MarketingSetting`) → `php artisan max:set-magnet-webhook`.
То же — после смены админ-аккаунта. См. `CLAUDE.md` (раздел lead-magnet bots).

## Деплой-чек-лист (прод-секреты для уже fail-closed легаси-эндпоинтов)

Код для Telegram- и VK-бот-вебхуков уже fail-closed (правок не требуется) — до
задания прод-секретов эти два эндпоинта просто отвечают 403 всем, включая
настоящий Telegram/VK. Задать в проде (точные команды и статус — в
[`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) § «Telegram — вопросы и действия оператору», пункт T3):

- `services.telegram.bot_webhook_secret` (`TELEGRAM_BOT_WEBHOOK_SECRET`) +
  переустановить вебхук Telegram с тем же `secret_token` через Bot API `setWebhook`.
- `services.vk.callback_secret` (`VK_CALLBACK_SECRET`) + тот же секрет в настройках
  Callback API группы VK.

Остаётся один настоящий деплой-переключатель fail-open → fail-closed (код без правок,
просто задать секрет, см. выше):
- `ZOOM_WEBHOOK_SECRET` (+ Event Subscription на `/api/webhooks/zoom`).

После задания секрета соответствующий эндпоинт автоматически переходит в fail-closed
(правки кода не требуется).
