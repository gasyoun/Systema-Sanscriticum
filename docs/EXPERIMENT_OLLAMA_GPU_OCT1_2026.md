# Эксперимент: Ollama + hybrid RAG на GPU Ивана, цель 01-10-2026

_Created: 21-08-2026 · Last updated: 03-09-2026_

**Handoff:** [H3234 (Grok 4.6) — Deferred Ivan-GPU Ollama hybrid RAG experiment, target 01-10-2026, not Gasuns 1050](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3234-Grok_Systema-Sanscriticum_ollama-gpu-experiment-oct1_21.08.26.md)
**Issue:** [gasyoun/Systema-Sanscriticum#1633](https://github.com/gasyoun/Systema-Sanscriticum/issues/1633) этапы 3–6
**Ruling 21-08-2026:** B в проде (H3233); A — откат флагом. Вариант C / GPU **не** на машине Гасунса (GTX 1050 ~2 ГБ). Старт, когда Иван вернётся в сентябре. Живой shadow-week к **01-10-2026**.

**🟢 Гейт открыт с 01-09-2026 19:00 UTC** (Sonnet 5, «Last probe» ниже), подтверждён 03-09-2026 дважды (OxAlpha H3234: 10:16 и 11:15 UTC — оба раза `bge-m3:latest` + `qwen3:14b` на месте; заметка H4001 о «смене ростера на gem4-only» была транзиентной — Иван переставлял модели). **Код этапов написан двумя проходами одного дня:** dense-нога FAQ-ретривала — H4001 (Wave 3 leverage: `knowledge_chunks`, провайдеры, HybridRetriever RRF, `knowledge:index` Horizon, eval-гейты), этапы 5–6 + `knowledge:eval` — H3234 (этот проход: теневая генерация в `SupportAiReplyEvent`, локальная генерация с детерминированной деградацией, печатная eval-таблица). Все прод-флаги default OFF. Туннель `autossh -R 11434:localhost:11434` на `.92`. Не ставить модель на `.92`. Не водить онлайн через n8n `.91`.

## Зачем откладывать, а не делать на GTX 1050

Issue требует ~2,5 ГБ (bge-m3) + ~9 ГБ (Qwen3-14B q4) = не влезает в 2 ГБ. Эмбеддинги одни на 1050 теоретически спорны и не стоят отдельного контура: B закрывает сентябрь без них.

## Протокол (когда узел жив)

1. **Живость.** `curl -sS --max-time 3 http://127.0.0.1:11434/api/tags` с `.92` через туннель. Кэш 30 с.
2. **Этап 3 issue.** Миграция `knowledge_chunks` (BLOB float32, не native VECTOR). `EmbeddingProvider` + `OllamaEmbeddingProvider` + `NullEmbeddingProvider`. `HybridRetriever` поверх существующего [`Bm25FaqRetriever`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/Faq/Bm25FaqRetriever.php). Индексация — Horizon, не `cron.service`. Дедуп транскриптов sha256.
3. **Этап 2.** `knowledge:eval` recall@5 / MRR на ≥100 живых вопросов vs 20Q H2448. Приёмка: hybrid ≥ BM25.
4. **Этап 4.** Лекции отдельным флагом после FAQ-only метрики.
5. **Этап 5.** Теневая генерация: OpenRouter отвечает студенту, Ollama пишет в `SupportAiReplyEvent` неделю.
6. **Этап 6.** Флаг локальной генерации. Деградация = `StudentSelfService` + «позову куратора». **Откат в DeepSeek запрещён** (текст issue).

Вектора ~22 МБ на 5–6 тыс. чанков — Qdrant не нужен. `docs/**` не в студенческий корпус.

## Календарь

| Когда | Кто | Что |
|---|---|---|
| 21-08-2026 | этот проход | пакет + H3234, код не писать |
| 27-08-2026 | Grok 4.6 (`grok-4.6`) `/go H3234` | `.92` `/api/tags` down (curl exit 7, no listener, no autossh). Stop. No PHP. |
| 28-08-2026 10:12 UTC | Grok 4.6 (`grok-4.6`) `/go H3234` | `.92` `/api/tags` still down (curl exit 7, `http=000`, no listener, no autossh/ollama). Stop. No PHP. |
| 28-08-2026 15:16 UTC | Grok 4.6 (`grok-4.6`) `/go H3234` | `.92` `/api/tags` still down (curl exit 7, `http=000`, no listener, no autossh/ollama). Stop. No PHP. |
| 29-08-2026 12:31 UTC | Grok 4.6 (`grok-4.6`) `/go H3234` | `.92` `/api/tags` still down (curl exit 7, `http=000`, no listener, no autossh/ollama). Stop. No PHP. |
| 30-08-2026 07:31 UTC | OxAlpha (`z-ai/glm-5.3-flash`) `/go H3234` | `.92` `/api/tags` still down (curl exit 7, `http=000`, no listener, no autossh/ollama). Stop. No PHP. |
| **01-09-2026 19:00 UTC** | **Claude Sonnet 5 (`claude-sonnet-5`) `/go H3234`** | **`.92` `/api/tags` UP: `http=200`, `qwen3:14b` (Q4_K_M, 14.8B) + `bge-m3:latest` (F16, embedding). Listener owned by `sshd-session` (reverse-tunnel forward, not a local Ollama process) — matches the required tunnel shape, not the forbidden "model on .92". Gate open. Stop here: this stamp only, no PHP — stages 3→5 need their own dedicated pass (new migration + 3 provider/retriever classes + Horizon job + eval + a week of shadow logging is out of scope for a stamp-only probe pass).** |
| сентябрь, Иван на месте | Иван | GPU-узел, Ollama, модели, autossh на `.92` — **ВЫПОЛНЕНО** (жив с 01-09) |
| 03-09-2026 ~10:45 UTC | OxAlpha (`z-ai/glm-5.3-flash`) — H4001, Wave 3 leverage-плана, [PR #2351](https://github.com/gasyoun/Systema-Sanscriticum/pull/2351) | **Этап 3 (FAQ-нога) кодом:** `knowledge_chunks` (float32 LE BLOB, `faq_chunk_id` unique), `EmbeddingProvider`/`OllamaEmbeddingProvider`/`NullEmbeddingProvider`, HybridRetriever (RRF, BM25-пол), `knowledge:index` + `KnowledgeEmbedChunksJob` (Horizon, очередь imports), fresh 20Q eval-набор (PII-маскированный) + eval-гейты в тестах. Флаг `FAQ_HYBRID_RETRIEVAL` default OFF. Честная оговорка прогона: на узле в момент их живого eval виднелся только `gemma4:12b` — live-полнировка остановлена, models-ростер затем вернулся (см. 11:15 UTC). |
| 03-09-2026 10:16 / 11:15 UTC | OxAlpha (`z-ai/glm-5.3-flash`) `/go H3234` | Гейт подтверждён дважды: `curl` с `.92` → `['qwen3:14b','bge-m3:latest']`, `ss -ltn` LISTEN `127.0.0.1:11434` (reverse-туннель; autossh/ollama-процессы на стороне узла, на `.92` их и не должно быть). |
| 03-09-2026 | OxAlpha (`z-ai/glm-5.3-flash`) — H3234, этот проход | **Этапы 5–6 + этап 2 командой** поверх инфраструктуры H4001 (реюз `config/knowledge.php`, второй конфиг не заводится): теневая генерация — [OllamaShadowReplyJob](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/OllamaShadowReplyJob.php) пишет `ollama_shadow` в `SupportAiReplyEvent` РЯДОМ с онлайн-логом OpenRouter (флаг `BOT_OLLAMA_SHADOW`, default OFF); локальная генерация — [CuratorAi::localReply](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/CuratorAi.php) на OpenAI-совместимом `/v1/chat/completions` (флаг `BOT_LOCAL_GENERATION`, default OFF; туннель умер → null → детерминированный ответ + «позови куратора», OpenRouter НЕ вызывается — тест); `knowledge:eval` — печатная recall@5/MRR таблица BM25 vs гибрид на золотом наборе (дефолт 100Q H3766 B5, `--evalset` переключает на fresh 20Q), приёмка `hybrid ≥ BM25` печатается. Тесты: `--filter=Knowledge` зелёный (H4001-набор + 11 новых). |
| далее (деплой) | агент + MG | на проде: `php artisan knowledge:index` (Horizon) при `KNOWLEDGE_EMBEDDING_DRIVER=ollama` → `FAQ_HYBRID_RETRIEVAL=true` → `BOT_OLLAMA_SHADOW=true` → неделя живой тени → `knowledge:eval` вердикт |
| неделя shadow | оба | сравнение логов (`ollama_shadow` рядом с онлайн-логом) |
| до 01-10-2026 | человек | флип `BOT_LOCAL_GENERATION` (этап 6) или оставить OpenRouter |

`/go H3234` до живого `/api/tags` — **стоп**. Это не «попробуй на 1050».

**Last probe 01-09-2026 19:00 UTC** (Claude Sonnet 5 `claude-sonnet-5`, gate-probe only, no PHP): host `samskrtam150` (`root@193.232.229.92`).
`curl -sS --max-time 3 http://127.0.0.1:11434/api/tags -o /dev/null -w 'http=%{http_code}\n'` → `http=200`, curl exit 0.
`curl -sS --max-time 5 http://127.0.0.1:11434/api/tags` body: `models` array with **`qwen3:14b`** (`family qwen3`, `parameter_size 14.8B`, `Q4_K_M`, capabilities `completion,tools,thinking`) and **`bge-m3:latest`** (`family bert`, `parameter_size 566.70M`, `F16`, capability `embedding`) — both gate-required models present.
`sudo ss -ltnp | grep 11434`: two listeners (`127.0.0.1:11434`, `[::1]:11434`), both owned by `sshd-session` (pid 4040561) — a reverse-forwarded port, not a locally-running `ollama` binary, so this satisfies the tunnel requirement without violating "не ставить модель на `.92`".
`pgrep autossh` / `pgrep ollama` on `.92` return nothing — expected, since the tunnel-initiating `autossh`/`ollama` processes run on Ivan's box, not on `.92`; `.92` only sees the forwarded `sshd-session` socket.

**This is the first probe where the gate reads open.** Prior stamps (all "down"): [PR #2145](https://github.com/gasyoun/Systema-Sanscriticum/pull/2145) (27-08), [PR #2151](https://github.com/gasyoun/Systema-Sanscriticum/pull/2151) (28-08 10:12 UTC), [PR #2155](https://github.com/gasyoun/Systema-Sanscriticum/pull/2155) (28-08 15:16 UTC), [PR #2184](https://github.com/gasyoun/Systema-Sanscriticum/pull/2184) (29-08 12:31 UTC), [PR #2205](https://github.com/gasyoun/Systema-Sanscriticum/pull/2205) (30-08 07:31 UTC). Comment: [Systema #1633](https://github.com/gasyoun/Systema-Sanscriticum/issues/1633#issuecomment-5451376796).

**Scope call for this pass:** stages 3→5 (`knowledge_chunks` migration, `EmbeddingProvider`/`OllamaEmbeddingProvider`/`NullEmbeddingProvider`, `HybridRetriever`, Horizon indexing job, `knowledge:eval`, a week of shadow-mode logging) are new production code on the path that answers real students — none of it exists yet in this repo (checked: no `HybridRetriever`, no `knowledge_chunks` migration, no `knowledge:eval` command). That is a dedicated multi-hour implementation pass in its own right, not an extension of a gate-probe stamp, and its acceptance (`hybrid ≥ BM25` on live eval, then a full week of shadow-generation logs) cannot be satisfied inside one sitting regardless. This stamp exists so the next session does not re-probe a gate that is already open — it goes straight to `/go H3234` stage 3.

A merged `docs(H3234): stamp … gate probe` PR is a measurement, not the experiment. Registry stays 🟡ᐳ🟢-gate (handoff itself stays **open**, not closed — the "gated, do not `/go`" line no longer applies, but stages 3-6 are unbuilt). Do not `handoff_close` against this stamp. `precheck_handoff.py` exit 4 on that title is a false positive (kin [Uprava FINDINGS §518](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md)). Title this stamp `docs: stamp …` (do not start with `H3234`) so the matcher does not REFUSE the next `/go`.

**03-09-2026 — запись выше исчерпана собственным условием.** «stages 3-6 are unbuilt» больше не верно: FAQ-нога этапа 3 слита H4001 ([PR #2351](https://github.com/gasyoun/Systema-Sanscriticum/pull/2351)), этапы 5–6 и `knowledge:eval` слиты проходом H3234. Ограничение «не закрывать хэндофф против СТЕМПА» относилось к probe-only проходам и не запрещает закрытие после реализации: единственный остаток H3234 — живая shadow-неделя, которая начинается ПОСЛЕ деплоя и включения `BOT_OLLAMA_SHADOW` на проде (человеческий флип по стояночной политике) — это операционная фаза календаря, а не строительный хэндофф; она ведётся в [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md). Этап 4 (лекции) — за пределами DoD-буллетов H3234, отдельный шаг после FAQ-only метрик (рулинг issue); потребует расширения схемы `knowledge_chunks` под `source_type`/`audience` — осознанно НЕ начат в обход слитой схемы H4001.

_Dr. Mārcis Gasūns_
