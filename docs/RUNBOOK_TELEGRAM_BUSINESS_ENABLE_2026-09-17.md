# RUNBOOK — включение полосы Telegram Business

_Created: 17-09-2026 · Last updated: 17-09-2026_

Слой: [ARCHITECTURE_SYSTEMA_TELEGRAM_BUSINESS_LANE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TELEGRAM_BUSINESS_LANE.md).
Всё в полосе по умолчанию **выключено**; ниже — порядок включения, проверки и откат.

## 0. Что нужно от человека (агент не может)

1. Бот в @BotFather (свой, не токен поддержки и не кабинетного бота).
2. **Включить у этого бота Secretary Mode** в @BotFather — без него Telegram не
   предложит аккаунту подключить бота к Business, и апдейтов
   `business_connection` не будет вовсе.
3. Подключить бота к аккаунту школы: **Telegram → Настройки → Business →
   Чат-боты → подключить**. Именно этот шаг даёт `business_connection` с правом
   `can_reply`; владелец видит в шапке управляемых чатов ту самую плашку
   «… управляет этим чатом» со кнопкой «Manage Bot».
4. Право ответа приходит в объекте `rights` (`BusinessBotRights.can_reply`) и
   действует **только для приватных чатов с входящим сообщением за последние
   24 часа** — это ограничение Telegram, а не наше: если студент написал сутки
   назад и молчит, отправка от имени аккаунта уже не разрешена.
5. Заполнить в прод-`.env`:

```
TELEGRAM_BUSINESS_BOT_ENABLED=true
TELEGRAM_BUSINESS_BOT_TOKEN=<токен из BotFather>
TELEGRAM_BUSINESS_WEBHOOK_SECRET=<случайная строка, ≥24 символа>
TELEGRAM_BUSINESS_BOT_USERNAME=@<username>
TELEGRAM_BUSINESS_ACCOUNT_NAME=telegram-business
```

`TELEGRAM_BUSINESS_ACCOUNT_NAME` — имя аккаунта в support-таблицах. Оно
намеренно **отдельное** от `support`: Business-ответы не должны смешиваться с
личкой userbot-аккаунта в аналитике и в дренаже.

Опционально (локальная модель для формулировки ответа — приватность и ноль
внешних вызовов):

```
SUPPORT_DM_LLM_DRAFTS_LOCAL=true        # Ollama вместо внешнего провайдера
KNOWLEDGE_OLLAMA_BASE_URL=http://127.0.0.1:11434
KNOWLEDGE_GENERATION_MODEL=qwen3:14b
```

Внешний провайдер в этом режиме не вызывается никогда: если локальный узел
молчит, формулировки просто нет — полоса уходит в шаблон/ack/подсказку куратору.

## 1. Деплой и вебхук

```bash
php artisan config:cache
php artisan migrate --force            # создаёт telegram_business_connections
php artisan telegram-business:set-webhook
php artisan telegram-business:set-webhook --info   # проверить allowed_updates
```

`allowed_updates` обязан содержать `business_connection`, `business_message`,
`edited_business_message`, `deleted_business_messages`. Без `business_connection`
полоса молча пропускает все сообщения (не знает владельца), без
`business_message` — не получает ничего.

## 2. Разрешить автоответы аккаунту полосы

Отдельный, сознательно ручной шаг (H3380-контракт: живая отправка — решение
человека):

```bash
php artisan tinker --execute="App\Models\TelegramSupportAccount::where('name','telegram-business')->update(['auto_reply_enabled'=>true]);"
```

Снять — тем же способом со значением `false`.

## 3. Смоук

```bash
php artisan telegram-business:status      # ожидаем «Полоса включена и работоспособна»
```

1. Написать боту-аккаунту с чужого телефона («куда загружать домашнее задание?»).
2. Через несколько секунд в чате должен появиться ответ **от имени аккаунта**, а
   в MySQL — строка `telegram_messages` с `direction=outgoing` и положительным
   `telegram_message_id`.
3. `php artisan support:business-drain` — счётчики `delivered`/`failed`.
4. События: `SupportAiReplyEvent` c `event_type=dm_auto_sent`, `meta.kind=faq_rag`.
5. Лента `/admin/dialogs` — вопрос виден там же, где вопросы userbot-лички.

Ожидаемые исходы «не ответил» и что они значат:

| Симптом | Причина | Что смотреть |
|---|---|---|
| вебхук 403 | секрет не совпал/пуст | `TELEGRAM_BUSINESS_WEBHOOK_SECRET` = зарегистрированный |
| вебхук 404 | флаг выключен | `TELEGRAM_BUSINESS_BOT_ENABLED` + `config:cache` |
| входящих нет, в логе `unknown business_connection_id` | не пришёл `business_connection` | подключён ли бот в Business |
| ответ стоит в очереди | снят `can_reply` или `auto_reply_enabled` | `telegram-business:status` |
| ответ не ушёл, `delivery_error` = `http 400` | Telegram отверг (чат/право) | лог + `support:business-drain` |
| студент получил подсказку куратору, а не ответ | вопрос ниже порога или категория D/E | `dm_hinted` в `SupportAiReplyEvent` |

## 4. Откат

Один флаг:

```bash
# .env: TELEGRAM_BUSINESS_BOT_ENABLED=false
php artisan config:cache
```

Дополнительно (если нужно прекратить приём апдейтов совсем):

```bash
php artisan telegram-business:set-webhook --drop
```

Уже поставленные в очередь ответы при выключенном флаге не уйдут — их досылает
`support:business-drain`, а он при флаге OFF выходит сразу. Студенту при этом
ничего не отправляется: молчание, не ошибочный ответ.

## 5. Связь с доменом порога (почему это в одном PR)

Полоса отвечает тем же `SupportDmAutoReply`, что и userbot-личка, а тот до этого
изменения читал скор слияния (`score`, RRF ≈0.025) там, где пороги выведены в
домене BM25 (15.7 для F). Практическое следствие: **включение плотной ноги
(`FAQ_HYBRID_RETRIEVAL=true`) глушило бы полосу ответов целиком** — тихо, без
падения. Теперь домен читается в одном месте (`HybridRetriever::bm25Score`), а на
это есть регрессионный тест `SupportFaqScoreDomainTest`, который падает, если
кто-то вернёт прямое чтение `['score']`.

## 6. Ритм проверки

- Первая неделя: ежедневно `telegram-business:status` + глазами по `dm_auto_sent`.
- Раз в неделю: `support:shadow-report` (какие вопросы дошли до автоответа, какие
  ушли куратору) и `knowledge:coverage --require-embedded` (база знаний
  проэмбедена и ничего не потеряно при экспорте FAQ).
- Первый же плохой ответ студенту = рулинг R9: `auto_reply_enabled=false` на
  аккаунте полосы, разбор, потом возврат.

_Dr. Mārcis Gasūns_
