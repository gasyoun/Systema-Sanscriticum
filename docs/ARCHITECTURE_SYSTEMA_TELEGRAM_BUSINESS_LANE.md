# ARCHITECTURE — полоса Telegram Business (ответ от имени аккаунта)

_Created: 17-09-2026 · Last updated: 17-09-2026_

Индекс: [PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md) ·
соседние слои: [ARCHITECTURE_SYSTEMA_TELEGRAM_RAG_SUPPORT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TELEGRAM_RAG_SUPPORT.md),
[telegram-bots-inventory.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-bots-inventory.md).

## 1. Что это за полоса

Третья дорожка к студенту, которой раньше не было в коде:

| | Кабинетный бот | Личка userbot (MadelineProto) | **Telegram Business (новая)** |
|---|---|---|---|
| Транспорт | `STUDENT_TELEGRAM_BOT_TOKEN`, вебхук | MTProto-сессия, `telegram-support:sync` | Bot API, вебхук `/api/webhooks/telegram-business` |
| От чьего имени видит студент | бот | аккаунт школы | **аккаунт школы** (владелец подключил бота) |
| Доставка ответа | Bot API сразу | pending + дренаж внутри синка | pending + дренаж по Bot API |

Отличие от userbot-лички, ради которого полоса и существует: отвечает **аккаунт**,
а не бот, и доставка не зависит от живой MTProto-сессии — значит, ответ уходит
через секунды после вопроса, а не по минутному такту синка.

## 2. Поток данных

```
student DM ──► Telegram ──► POST /api/webhooks/telegram-business
                              │  middleware verify.tg.business (fail-closed)
                              ▼
                     ProcessTelegramBusinessUpdate (queue: webhooks)
                       ├── business_connection → telegram_business_connections
                       └── business_message
                             ├── TelegramBusinessNormalizer
                             │     (владелец по owner_telegram_user_id → outgoing;
                             │      студент → incoming; connection_id в payload)
                             ├── TelegramSupportSyncService::syncNormalizedMessages
                             │     чаты, контакты, дедуп, авто-линк, роллапы,
                             │     тех-роутер и SupportDmAutoReply
                             └── BusinessSupportReplyDrainer
                                   pending-исходящее → TelegramSendGuard::claim
                                   → sendMessage(business_connection_id)
                                   → delivered (настоящий message_id)
```

Всё, что ниже `syncNormalizedMessages`, — **существующий** конвейер поддержки: та же
лента `/admin/dialogs`, те же `SupportAiReplyEvent`, те же правила денег/доступов.

## 3. Общая база знаний (H5065)

Полоса не заводит свой retrieval. Контекст для ответа собирает
`App\Services\Support\Faq\SharedKnowledgeBase` → `KnowledgeContext`:

- **один вид контекста** для кабинетного бота, лички и Business: полный раздел
  корпуса + заголовочный путь + цитаты;
- `support.faq_rag.answer_top_k` (6) разделов и `answer_max_chars` (12000), обрезка
  **по границе раздела**, а не по середине текста;
- лексическая нога (BM25) — пол по контракту H4001; плотная нога (`bge-m3` на
  локальной модели) включается `FAQ_HYBRID_RETRIEVAL=true` и на 100-вопросном
  наборе даёт recall@5 0.89 / MRR 0.7403 против BM25 0.82 / 0.6617;
- формулировка ответа тоже может жить на локальной модели:
  `SUPPORT_DM_LLM_DRAFTS_LOCAL=true` → `CuratorAi::localChatWithUsage` (Ollama),
  вопрос студента и справка не покидают школу, стоимость ответа нулевая. Узел
  недоступен → формулировки нет (полоса уходит в шаблон/ack/подсказку), внешний
  провайдер в этом режиме не вызывается никогда (контракт H3234).

Скор для порогов читается **только** в домене BM25
(`HybridRetriever::bm25Score`). Это не стилистика: у гибрида две шкалы, и прямое
чтение `['score']` (RRF, ≈0.025) против порога категории 15.7 означало, что в день
включения плотной ноги полоса ответов замолкает на **каждом** вопросе — молча, без
падения. Замер и разбор: `docs/RUNBOOK_TELEGRAM_BUSINESS_ENABLE_2026-09-17.md` §5.

## 4. Гейты (все — fail-closed)

| Гейт | Что гасит | Где |
|---|---|---|
| `features.telegram_business_bot` | вебхук 404, джоба выходит, дренаж молчит | middleware + job + drainer |
| `TELEGRAM_BUSINESS_WEBHOOK_SECRET` | пустой секрет → 403 на каждом апдейте | `verify.tg.business` |
| `can_reply` + `is_enabled` подключения | отзыв права владельцем прекращает отправку | `TelegramBusinessConnection::usable()` || `telegram_support_accounts.auto_reply_enabled` | аккаунт полосы молчит, пока человек не разрешит | H3380-контракт, `SupportDmAutoReply` |
| категории D (деньги) и E (доступы) | вычеркнуты **в коде**, не в конфиге | `SupportDmAutoReply` |
| пороги `shadow_min_score*` | ответ ниже порога уходит куратору, не студенту | `SupportDmAutoReply` |
| `TelegramSendGuard` | дубль того же текста в тот же чат подавлен | `TelegramBusinessSender` |

Плюс классификация автора: сообщение **владельца** аккаунта (`from.id ==
owner_telegram_user_id`) помечается `outgoing` и никогда не считается вопросом
студента — иначе бот отвечал бы на собственные сообщения школы. Если строка
подключения неизвестна, сообщение **пропускается**: без владельца классификация
невозможна, и автоответ ушёл бы наугад.

Два ограничения Telegram, которые полоса не обходит, а называет: право ответа
живёт в объекте `rights` (`BusinessBotRights.can_reply`, а не в одноимённом
верхнеуровневом поле — оно осталось фолбэком), и действует оно только для
приватных чатов с входящим сообщением за последние 24 часа. Плюс в @BotFather у
бота должен быть включён Secretary Mode — иначе аккаунт вообще не сможет его
подключить.

## 5. Дедуп и разметка

- `update_id` — клейм `TelegramSendGuard::claimUpdate('business', …)`: ределивери
  вебхука не даёт второго ответа студенту.
- `(аккаунт, чат, message_id)` — уникальный индекс `telegram_support_messages`.
- Отправка — клейм `(chat_id, text)`: 4xx/5xx → release и повтор безопасен;
  транспортный сбой без ответа → клейм не отпускается (подавленный повтор дешевле
  дубля в личке).
- `deleted_business_messages` **не** удаляет историю поддержки: она уже в базе и
  участвует в аналитике.

## 6. Схема

Единственная новая таблица — `telegram_business_connections` (id подключения,
владелец, `can_reply`, `is_enabled`, права, метки времени). Существующие
support-таблицы не изменены ни одной колонкой: полоса добавлена рядом, а не
внутрь.

## 7. Что смотреть в проде

- `php artisan telegram-business:status` — флаг, токен, секрет, подключения,
  очередь; ненулевой код возврата = полоса включена, но неспособна работать.
- `php artisan support:business-drain` — досыл; штатно его дёргает джоба приёма,
  минутный слот — страховка.
- События `SupportAiReplyEvent`: `dm_auto_sent` (`kind=faq_rag|facts|template|llm_draft`),
  `dm_hinted`, `dm_llm_refused`, `dm_shadow_would_send`.

_Dr. Mārcis Gasūns_
