# Эксперимент: Ollama + hybrid RAG на GPU Ивана, цель 01-10-2026

_Created: 21-08-2026 · Last updated: 01-09-2026_

**Handoff:** [H3234 (Grok 4.6) — Deferred Ivan-GPU Ollama hybrid RAG experiment, target 01-10-2026, not Gasuns 1050](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3234-Grok_Systema-Sanscriticum_ollama-gpu-experiment-oct1_21.08.26.md)
**Issue:** [gasyoun/Systema-Sanscriticum#1633](https://github.com/gasyoun/Systema-Sanscriticum/issues/1633) этапы 3–6
**Ruling 21-08-2026:** B в проде (H3233); A — откат флагом. Вариант C / GPU **не** на машине Гасунса (GTX 1050 ~2 ГБ). Старт, когда Иван вернётся в сентябре. Живой shadow-week к **01-10-2026**.

**🟢 Гейт открыт с 01-09-2026 19:00 UTC** (см. «Last probe» ниже) — узел Ивана отвечает с `bge-m3` и `qwen3:14b`. Этапы 3→5 (миграция/провайдеры/HybridRetriever/индексация/eval/теневая генерация) ещё **не начаты** — это следующий проход, не этот (этот проход — только подтверждение живости и стемп; PHP-код этой сессией не писался, по протоколу «живость → отдельный проход на реализацию», см. приёмку в H3234). Туннель `autossh -R 11434:localhost:11434` на `.92`. Не ставить модель на `.92`. Не водить онлайн через n8n `.91`.

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
| сентябрь, Иван на месте | Иван | GPU-узел, Ollama, модели, autossh на `.92` |
| ~~тот же день после ping~~ → **следующий проход** | агент | `/go H3234` — этапы 3→5 (гейт открыт, реализация ещё не начата) |
| неделя shadow | оба | сравнение логов |
| до 01-10-2026 | человек | флип этапа 6 или оставить OpenRouter |

`/go H3234` до живого `/api/tags` — **стоп**. Это не «попробуй на 1050».

**Last probe 01-09-2026 19:00 UTC** (Claude Sonnet 5 `claude-sonnet-5`, gate-probe only, no PHP): host `samskrtam150` (`root@193.232.229.92`).
`curl -sS --max-time 3 http://127.0.0.1:11434/api/tags -o /dev/null -w 'http=%{http_code}\n'` → `http=200`, curl exit 0.
`curl -sS --max-time 5 http://127.0.0.1:11434/api/tags` body: `models` array with **`qwen3:14b`** (`family qwen3`, `parameter_size 14.8B`, `Q4_K_M`, capabilities `completion,tools,thinking`) and **`bge-m3:latest`** (`family bert`, `parameter_size 566.70M`, `F16`, capability `embedding`) — both gate-required models present.
`sudo ss -ltnp | grep 11434`: two listeners (`127.0.0.1:11434`, `[::1]:11434`), both owned by `sshd-session` (pid 4040561) — a reverse-forwarded port, not a locally-running `ollama` binary, so this satisfies the tunnel requirement without violating "не ставить модель на `.92`".
`pgrep autossh` / `pgrep ollama` on `.92` return nothing — expected, since the tunnel-initiating `autossh`/`ollama` processes run on Ivan's box, not on `.92`; `.92` only sees the forwarded `sshd-session` socket.

**This is the first probe where the gate reads open.** Prior stamps (all "down"): [PR #2145](https://github.com/gasyoun/Systema-Sanscriticum/pull/2145) (27-08), [PR #2151](https://github.com/gasyoun/Systema-Sanscriticum/pull/2151) (28-08 10:12 UTC), [PR #2155](https://github.com/gasyoun/Systema-Sanscriticum/pull/2155) (28-08 15:16 UTC), [PR #2184](https://github.com/gasyoun/Systema-Sanscriticum/pull/2184) (29-08 12:31 UTC), [PR #2205](https://github.com/gasyoun/Systema-Sanscriticum/pull/2205) (30-08 07:31 UTC). Comment: [Systema #1633](https://github.com/gasyoun/Systema-Sanscriticum/issues/1633#issuecomment-5451376796).

**Scope call for this pass:** stages 3→5 (`knowledge_chunks` migration, `EmbeddingProvider`/`OllamaEmbeddingProvider`/`NullEmbeddingProvider`, `HybridRetriever`, Horizon indexing job, `knowledge:eval`, a week of shadow-mode logging) are new production code on the path that answers real students — none of it exists yet in this repo (checked: no `HybridRetriever`, no `knowledge_chunks` migration, no `knowledge:eval` command). That is a dedicated multi-hour implementation pass in its own right, not an extension of a gate-probe stamp, and its acceptance (`hybrid ≥ BM25` on live eval, then a full week of shadow-generation logs) cannot be satisfied inside one sitting regardless. This stamp exists so the next session does not re-probe a gate that is already open — it goes straight to `/go H3234` stage 3.

A merged `docs(H3234): stamp … gate probe` PR is a measurement, not the experiment. Registry stays 🟡ᐳ🟢-gate (handoff itself stays **open**, not closed — the "gated, do not `/go`" line no longer applies, but stages 3-6 are unbuilt). Do not `handoff_close` against this stamp. `precheck_handoff.py` exit 4 on that title is a false positive (kin [Uprava FINDINGS §518](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md)). Title this stamp `docs: stamp …` (do not start with `H3234`) so the matcher does not REFUSE the next `/go`.

_Dr. Mārcis Gasūns_
