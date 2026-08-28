# Эксперимент: Ollama + hybrid RAG на GPU Ивана, цель 01-10-2026

_Created: 21-08-2026 · Last updated: 28-08-2026_

**Handoff:** [H3234 (Grok 4.6) — Deferred Ivan-GPU Ollama hybrid RAG experiment, target 01-10-2026, not Gasuns 1050](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3234-Grok_Systema-Sanscriticum_ollama-gpu-experiment-oct1_21.08.26.md)
**Issue:** [gasyoun/Systema-Sanscriticum#1633](https://github.com/gasyoun/Systema-Sanscriticum/issues/1633) этапы 3–6
**Ruling 21-08-2026:** B в проде (H3233); A — откат флагом. Вариант C / GPU **не** на машине Гасунса (GTX 1050 ~2 ГБ). Старт, когда Иван вернётся в сентябре. Живой shadow-week к **01-10-2026**.

**Не запускать сейчас.** Гейт: узел Ивана отвечает `GET http://127.0.0.1:11434/api/tags` с моделями `bge-m3` и `qwen3:14b` (или эквивалент q4). Туннель `autossh -R 11434:localhost:11434` на `.92`. Не ставить модель на `.92`. Не водить онлайн через n8n `.91`.

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
| 28-08-2026 | Grok 4.6 (`grok-4.6`) `/go H3234` | `.92` `/api/tags` still down (curl exit 7, `http=000`, no listener, no autossh/ollama). Stop. No PHP. |
| сентябрь, Иван на месте | Иван | GPU-узел, Ollama, модели, autossh на `.92` |
| тот же день после ping | агент (Grok) | `/go H3234` — этапы 3→5 |
| неделя shadow | оба | сравнение логов |
| до 01-10-2026 | человек | флип этапа 6 или оставить OpenRouter |

`/go H3234` до живого `/api/tags` — **стоп**. Это не «попробуй на 1050».

**Last probe 28-08-2026 10:12 UTC** (Grok 4.6 / `grok-4.6`, `/go H3234`): host `samskrtam150` (`root@193.232.229.92`). `curl -sS --max-time 3 http://127.0.0.1:11434/api/tags` → curl exit 7, `Failed to connect to 127.0.0.1 port 11434`, `http=000`. `ss -ltn`: no `:11434`. `pgrep autossh` / `pgrep ollama`: none. Gate still closed. Prior stamp: [PR #2145](https://github.com/gasyoun/Systema-Sanscriticum/pull/2145) (27-08). Comment: [Systema #1633](https://github.com/gasyoun/Systema-Sanscriticum/issues/1633#issuecomment-5442823908).

A merged `docs(H3234): stamp … gate probe (down)` PR is a measurement, not the experiment. Registry stays 🟡. Do not `handoff_close` against it. `precheck_handoff.py` exit 4 on that title is a false positive (kin [Uprava FINDINGS §518](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md)).

_Dr. Mārcis Gasūns_
