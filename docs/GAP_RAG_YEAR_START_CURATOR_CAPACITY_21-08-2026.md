_Created: 21-08-2026 · Last updated: 05-09-2026_

# Gap: RAG из #1633 vs учебный год (двое в Telegram)

_Created: 21-08-2026 · Last updated: 21-08-2026 (ruling: B now, A rollback, C Ivan GPU by 01-10)_

**Model:** Grok 4.6 (`grok-4.6`)
**Issue:** [gasyoun/Systema-Sanscriticum#1633](https://github.com/gasyoun/Systema-Sanscriticum/issues/1633) (Иван, 13-08-2026) — RAG на локальных моделях: гибридный поиск (BM25 + bge-m3) в Laravel, Ollama на внешнем узле
**Comment:** [issuecomment-5365943843](https://github.com/gasyoun/Systema-Sanscriticum/issues/1633#issuecomment-5365943843)
**Prior art:** [H2448 (Grok 4.5) — Track B: Systema FAQ retrieval for Helpdesk suggester](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2448-Grok_Systema-Sanscriticum_faq-rag-support-suggester-v1_08.08.26.md) · [PLAN_DUAL_TRACK_RAG](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_DUAL_TRACK_RAG_AGENT_HUBS_AND_SYSTEMA_FAQ_2026.md)

**Вердикт:** относительно #1633 — **PARTIAL**. Полный стек (Ollama + bge-m3 + `knowledge_chunks`) **не собран**. К 1 сентября issue одно **не достаточно**: нет маршрута «простое → бот, сложное → подсказка в Telegram» и нет счётчика по 🍎.

---

## Операционный overlay (21-08-2026)

Из трёх кураторов в Telegram остались двое. Простые вопросы должен закрывать бот; на сложные — подсказки этим двоим. В ответах Горбаченко стоит 🍎; остальные человеческие исходящие считаем как Гасунс.

Это **не** спецификация #1633. #1633 — качество/приватность/локальная генерация кабинетного бота. Overlay — ёмкость двоих людей на пике сентября–октября.

---

## Два Telegram-канала (их нельзя смешивать)

| | 1. Кабинетный бот | 2. Лички саппорт-аккаунта |
|---|---|---|
| Токен / сессия | `STUDENT_TELEGRAM_BOT_TOKEN` | MadelineProto, **одна** support-сессия |
| Кто отвечает сейчас | ИИ (OpenRouter/DeepSeek) **сам** | Кураторы печатают руками |
| Куда эскалация | алерт на `ADMIN_TELEGRAM_ID` → ссылка в `/admin/dialogs` | Helpdesk-черновик, если суггестер включён |
| Куда #1633 | **сюда** | не покрыто |
| Куда H2448 | не подключён | Helpdesk only, флаг OFF |
| Где пишут люди | почти не пишут | **здесь** (15-08-2026: `outgoing_total=8670, tracked=2, delivered=0`) |

Документ кабинетного бота: [docs/cabinet-bot.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/cabinet-bot.md).

---

## Что уже есть (не строить заново)

| Кусок | Факт |
|---|---|
| BM25 + чанки + цитаты + refuse-gate на D | [`Bm25FaqRetriever`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/Faq/Bm25FaqRetriever.php) · [PR #1416](https://github.com/gasyoun/Systema-Sanscriticum/pull/1416) |
| Eval 20/20 top-3 | [docs/FAQ_RAG_EVAL_H2448.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FAQ_RAG_EVAL_H2448.md) |
| Флаг | `FAQ_RAG_SUGGESTER` default **OFF**, [DEPLOY_QUEUE №67](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) pending |
| Куда подключён | только Helpdesk-черновики. В [`BotKnowledgeBase`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/BotKnowledgeBase.php) **не входит**. `$userQuestion` «зарезервирован под будущий ретривал» |
| Кабинетный бот | `faq.md` целиком в каждый промпт (21-08-2026: **46 014** символов, под cap 60 000) · [`CuratorAi`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/CuratorAi.php) |
| Self-service без LLM | группы / расписание / ДЗ / предупреждения · [`StudentSelfService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/StudentSelfService.php) |
| Эскалация | триггер-слова «куратор / человек / помощь / админ / менеджер / оператор» → `chat_human_*` + алерт **со ссылкой в админку**, не черновик в Telegram |
| Parser'ы для этапов 3–4 issue | [`FaqCorpusParser`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/Faq/FaqCorpusParser.php), [`TranscriptParser::sentencesFromPublicFile`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/TranscriptParser.php) |
| Счётчик кто ответил | схема есть: `SupportResponderMapping` + `respondersForDate` на [`TelegramSupportAnalytics`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/TelegramSupportAnalytics.php). В проде **не работает**: [PACKET_JIVO](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PACKET_JIVO_TELEPHONY_PROVIDER_ROUTING_GATE_2026.md) — `telegram_support_messages.responder_user_id` за 30 дней = **0**. Маппинг ждёт `favicon:masha`, не 🍎 в тексте |

В дереве **нет** `app/Services/Knowledge/`, `knowledge_chunks`, `EmbeddingProvider`, `HybridRetriever`, `config/knowledge.php`, artisan `knowledge:index` / `knowledge:eval` / `knowledge:build-evalset`, флага `bot_faq_retrieval`, теста `tests/Unit/Bot/BotKnowledgeBaseTest.php`.

---

## Этапы #1633 — статус

| Этап | Статус | Нужен к 1 сентября? |
|---|---|---|
| 1. BM25 в `BotKnowledgeBase::systemPrompt` (`bot_faq_retrieval`, default OFF) | **не сделан** | **да** — качество промпта, GPU не нужен |
| 2. Золотой набор 100 Q + recall@5 / MRR | не сделан (есть только 20Q H2448) | нет для этапа 1; нужен перед выбором модели |
| 3. `knowledge_chunks` + hybrid + Ollama embed | не сделан | нет |
| 4. Корпус лекций | не сделан | нет |
| 5–6. Локальная генерация, отказ от OpenRouter | не сделан | **железо:** на Windows-машине сессии `nvidia-smi` = **GTX 1050** (~2 ГБ), не «16 ГБ+» из тела issue. Qwen3-14B q4 (~9 ГБ) + bge-m3 сюда **не влезают**. Или есть другой домашний узел, или этапы 5–6 = вариант C (GPU-VPS), или остаёмся на OpenRouter |

Запреты issue (не ставить модель на `.92`, не водить онлайн через n8n `.91`, не кормить `docs/**` студентам, не дублировать ретривер, цены только из каталога) — действительны.

---

## Overlay, которого в #1633 нет

1. **Порог простое/сложное.** Сейчас эскалация только по словам. Нет правила «BM25 выше пола → бот отправляет; ниже / деньги / нет чанка → подсказка человеку».
2. **Подсказка в Telegram, не в `/admin/dialogs`.** [`FaqRagDraftBuilder`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/Faq/FaqRagDraftBuilder.php) явно пишет «бот ничего не отправил студенту». Если отвечают из Telegram — черновик с цитатой FAQ должен приходить **туда же**.
3. **Атрибуция 🍎.** Исходящее с 🍎 → Горбаченко; остальные человеческие → Гасунс. Это **не** `favicon:`-маркер Telegram Desktop, под который заточен `SupportResponderMapping`. Одна сессия userbot → `from_id` обоих не разделит. Вешать на **текст исходящего** при синке + строка на дашборде за день/неделю.
4. **Политика автоответа на канале 2.** [ROADMAP_SUPPORT_AUTOMATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md) до сих пор: «боты НЕ отвечают студентам сами — только pending-черновики». Кабинетный бот уже ломает это правило. Для личек саппорт-аккаунта нужен явный флип (флаг default OFF).

Деньги — «сложные» по умолчанию: каталог целиком, refuse-gate на категорию D сохраняется.

---

## Минимум к учебному году (не весь #1633)

1. **Этап 1** issue: флаг `bot_faq_retrieval` → топ-K BM25 в промпт кабинетного бота. `CourseCatalogProvider::markdown()` всегда целиком. Тест `BotKnowledgeBaseTest`. Флаг OFF до смоука.
2. **Черновик сложного в Telegram** двум оставшимся (не ссылка в админку). Включать `FAQ_RAG_SUGGESTER` (№67) имеет смысл только после этого.
3. **Счётчик 🍎 / не-🍎** на синке исходящих. Не ждать заполнения `favicon:`-маппинга.

Ollama / bge-m3 / лекции — после, когда есть узел с 16 ГБ. На GTX 1050 этой сессии этапы 5–6 не стартовать.

---

## Развилка автоответа на канале 2

Кабинетный бот уже отвечает сам. Вопрос только про лички саппорт-аккаунта.

| | A — кабинетный бот + подсказки + 🍎 | B — ещё и автоответ простых в личках саппорта | C — ждать полный #1633 |
|---|---|---|---|
| Что меняется | этап 1; черновик сложного в Telegram; счётчик по яблоку | то же плюс бот пишет студенту в личку саппорт-аккаунта | железо + этапы 3–6 |
| Срок | дни, без GPU | плюс флаг и политика | блокировано на GTX 1050 |
| Риск | низкий | средний: roadmap до сих пор запрещает автоотправку здесь | не успеет к 1 сентября |
| Если никто не тикнет | этап 1 всё равно можно делать; лички остаются ручными | сентябрьский пик ложится на двоих | то же |

**Ruling 21-08-2026:** **B**. A остаётся откатом (`SUPPORT_DM_AUTO_REPLY=false`). C / GPU — не на GTX 1050 Гасунса; Иван возвращается в сентябре; пакет [docs/EXPERIMENT_OLLAMA_GPU_OCT1_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/EXPERIMENT_OLLAMA_GPU_OCT1_2026.md) + [H3234](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3234-Grok_Systema-Sanscriticum_ollama-gpu-experiment-oct1_21.08.26.md), цель 01-10-2026. Код B: [H3233](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3233-Grok_Systema-Sanscriticum_support-dm-simple-auto-reply_21.08.26.md).

_Dr. Mārcis Gasūns_
