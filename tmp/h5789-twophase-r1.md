# Суфлёр week-0: baseline автопрогона (Q11, row 0LD)

_Сгенерировано: 2026-10-03 19:37 · H5560/row 0LD · retrieval: **hybrid** · rerank: **llm** · корпус v1: resources/knowledge/faq.md · вопросы: classifier_corpus_2026_08.json (50 cases)_

Трек H5648 LLM-rerank: после retrieval топ-K модель SupportLlmDraftComposer-стека (CuratorAi, локальная Ollama) выбирает один чанк; токены — в леджере ниже.

## Числа week-0 (§20 п.2–3)

| Метрика | Значение | Планка |
|---|---|---|
| Доля обращений, закрытых без человека (автономный кандидат) | **82.76 %** (24/29) | растёт week-over-week |
| Precision цитат, top-1 (N=20) | **95 %** | ≥95 % (R3) |
| Precision цитат при пороге (покрытие 20/20) | 95 % | ≥95 % (R3) |
| Top-3 any-hit | 100 % | — |
| Rerank LLM | 20 вызовов · 0 фолбэков · токены 23630+557 · qwen2.5:7b-instruct | — |

## Разбор вердиктов (часть A)

- autonomous_candidate: **24**
- hint (💡, человек): **4**
- silence (деньги, без порога → финлид): **0**
- human (сертификаты, граница пилота): **1**
- excluded (small talk, вне знаменателя): **21**

### По классам

| класс | вердикты |
|---|---|
| E | autonomous_candidate: 5 |
| D | autonomous_candidate: 8 |
| F | autonomous_candidate: 4; human_certificates: 1 |
| B | autonomous_candidate: 4 |
| A | autonomous_candidate: 3 |
| C | hint_schedule_facts_offline: 4 |

## Killgate-леджер (§13)

- трек: llm_rerank_local (CuratorAi localChatWithUsage — стек суфлёра, без нового HTTP)
- токены: 24187 из потолка TBD / тикет; месячная норма: TBD (TBD-MG (z.ai Max месячная норма) — строка MG @DECIDE)
- шаги на тикет: 4 из 8
- авто-стопов: 0; write/send-инструментов: 0 (allowlist read-only)
- реранк: 20 вызовов / 0 фолбэков; глубина пула 3; токены 23630+557; модель: qwen2.5:7b-instruct

## Ограничения week-0 (честно)

- C-класс (расписание) офлайн = hint: факты расписания требуют LMS/БД — в знаменателе против авто.
- D-класс autonomous-candidate — с дисклеймером «цены только из живого каталога» (политика D-трека).
- Реранк гоняется на локальной Ollama (CuratorAi localChatWithUsage); внешний LLM-трек (OpenRouter-ключ, прод-обвязка) в прогоне не участвовал.
- Пороги retrieval — дефолтные (config support.faq_rag); калибровка порогов под 95 % — отдельный проход (faq:score-floor).
- ПДн: корпус публичный маскированный, вопросы анонимизированы — инвариант 152-ФЗ соблюдён by construction.
- Промахи реранка: см. таблицу в JSON-отчёте (rows[].rerank_pick против expected); фолбэк = порядок retrieval.

## Вердикт

Трек H5648 (LLM-rerank top-K): precision top-1 против базы 65 % (BM25/гибрид) и планки R3 ≥95 % — см. таблицу выше; детали каждого выбора — в JSON-отчёте.

Связка метрик (риск конверсии): 24 автономных кандидатов опираются на top-1
цитаты, из которых верны только 95 % — без калибровки порога (faq:score-floor)
и/или плотной ноги гибрида (H5065) доля-кандидаты не конвертируется в безопасную прод-долю.
Порядок: сначала precision ≥95 % на пороге с приемлемым покрытием, потом автономная отправка.

_Гасунс_
