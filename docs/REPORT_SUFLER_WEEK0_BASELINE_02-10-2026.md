# Суфлёр week-0: baseline автопрогона (Q11, row 0LD)

_Сгенерировано: 2026-10-02 13:12 · H5560/row 0LD · корпус v1: resources/knowledge/faq.md · вопросы: classifier_corpus_2026_08.json (50 cases)_

Детерминированный офлайн-трек: BM25-retrieval + цитированный черновик, LLM не вызывался (токены 0).

## Числа week-0 (§20 п.2–3)

| Метрика | Значение | Планка |
|---|---|---|
| Доля обращений, закрытых без человека (автономный кандидат) | **82.76 %** (24/29) | растёт week-over-week |
| Precision цитат, top-1 (N=20) | **65 %** | ≥95 % (R3) |
| Precision цитат при пороге (покрытие 20/20) | 65 % | ≥95 % (R3) |
| Top-3 any-hit | 85 % | — |

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