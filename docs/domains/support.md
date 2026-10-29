# Support — короткая карта

## Когда читать

Перед изменением Helpdesk, веб-чата, Telegram/VK/MAX-поддержки, AI-ответов, Telegram support analytics, связывания внешних контактов с пользователями и доставки ответов.

## Порядок чтения

1. [support-subsystem-map.md](../support-subsystem-map.md) — фактическая архитектура и уже существующие компоненты.
2. [support-identity.md](../support-identity.md) — если затрагивается идентичность контакта.
3. `AGENTS.md` → Telegram support analytics и Deployment Notes — для live-импорта.
4. Код и профильные тесты.

`jivo.md` — продуктовый benchmark, не источник истины о текущей реализации.

## Архитектурные инварианты

- Канальные хранилища сообщений не сливать в одну таблицу. Единый поток строится read-слоем `UnifiedInboxReader` + `UnifiedMessage` и операционным `SupportConversation`.
- `SupportConversation` — операционная reopenable thread; дневная аналитика хранится отдельно в `SupportDailyRollup`.
- Каноническая внешняя идентичность — `social_accounts`. Денормализованные `users.telegram_id/vk_id/max_user_id` сохраняются как outbound caches; не создавать четвёртую конкурирующую модель идентичности.
- Ручная привязка support contact имеет приоритет над автоматической. Автопривязка допустима только при однозначном совпадении.
- AI assist не отправляет ответы автоматически. Включение `support_ai_assist` не должно означать auto-send.
- Импортированные Telegram DM передаются внешнему LLM только при отдельном разрешении `support_ai_include_telegram`; по умолчанию контекст ограничен веб-чатом.
- Telegram support analytics — импорт истории отдельного support-аккаунта через MadelineProto, не обычный bot webhook.
- Исходящий TG-support ответ сохраняется как pending и доставляется процессом `telegram-support:sync` через `PendingSupportReplyDrainer`. MadelineProto нельзя открывать из HTTP-процесса или queue worker: единственный владелец MTProto-сессии — процесс синка.

## Точки входа в код

| Область | Файлы |
|---|---|
| Единое чтение | `app/Services/Support/UnifiedInboxReader.php`, `app/Support/UnifiedMessage.php` |
| Операционный диалог | `app/Models/SupportConversation.php`, `app/Services/Support/SupportConversationManager.php` |
| Ответ | `app/Services/Support/SupportReplyService.php`, `app/Services/Support/PendingSupportReplyDrainer.php` |
| Curator UI | `app/Filament/Pages/Helpdesk.php` |
| AI assist | `app/Services/Support/SupportAiService.php` |
| Telegram import | `app/Services/TelegramSupport/TelegramSupportSyncService.php` |
| Агрегация | `app/Services/TelegramSupport/SupportDailyRollupAggregator.php` |
| Идентичность | `app/Models/SocialAccount.php`, `docs/support-identity.md` |

## Проверки

```bash
php artisan test --filter=TelegramSupportAnalyticsTest
```

Добавь профильные тесты изменения: дедупликация импорта, manual link precedence, объединение каналов, флаги AI/privacy, маршрутизация ответа, queue failure или responder mapping.

Для sample-импорта без live Telegram:

```bash
php artisan telegram-support:sync --payload=storage/app/support-sample.json
```

Первый live login/session интерактивен и выполняется пользователем. Не выдумывать credentials и не считать sample-проверку доказательством production enablement.

## Связанные документы

- [telegram-userbot-inventory.md](../telegram-userbot-inventory.md)
- [telegram-bots-inventory.md](../telegram-bots-inventory.md)
- [cabinet-bot.md](../cabinet-bot.md)
