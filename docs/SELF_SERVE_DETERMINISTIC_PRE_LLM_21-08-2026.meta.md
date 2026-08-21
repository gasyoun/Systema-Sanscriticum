# SELF_SERVE_DETERMINISTIC_PRE_LLM_21-08-2026.meta.md

_Created: 21-08-2026 · Last updated: 21-08-2026_

Метадок-компаньон к [`docs/SELF_SERVE_DETERMINISTIC_PRE_LLM_21-08-2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SELF_SERVE_DETERMINISTIC_PRE_LLM_21-08-2026.md).

## Предмет

- **Документ:** карта «что сортируется без LLM» для self-serve Systema (Telegram + ORS-FAQ).
- **Аудитория:** агент, который будет расширять интенты / включать флаги; куратор, который решает, что можно отдать боту.
- **Формат:** dated brief с таблицами слоёв 0–5. Не спека фичи и не UX-аудит.

## Происхождение

- Сессия 21-08-2026, Grok 4.6 (`grok-4.6`), вопрос «углубить и расширить self-serve / детерминированная классификация до LLM».
- Опирается на уже влитый код: `SupportAnswerSuggester`, `StudentSelfService`, `AccessDiagnosticsService`, H3233 (`SupportDmAutoReply`), ORS-FAQ `wiki/topics` частоты, deflection baseline 30-07-2026.

## Ранжированный бэклог

| # | Улучшение | Зачем | Статус |
|---|---|---|---|
| 1 | Импорт «Типичные фразы» тем 04/05/06 в regex/интенты + тесты | A/B/C ловят не все живые формулировки | open |
| 2 | Photo/document → маршрут «чек» без NLP | Тема 01 = 1 из 5 диалогов | open |
| 3 | CRM-state tie-break в `categorize` | Один текст, разные двери | open |
| 4 | Интент «мои долги» / magic-link в `StudentSelfService` | Кабинет-бот всё ещё отдаёт оплату в LLM | open |
| 5 | Включить `SUPPORT_DM_AUTO_REPLY` после смоука | Код H3233 инертен (DEPLOY_QUEUE №79) | parked (прод-флаг) |
| 6 | H300 UX-аудит кабинета | Другой deliverable | не этот файл |

## Ограничения

- Частоты ORS-FAQ — снимок корпуса (3 064 диалога), не live Telegram 2026H2.
- Rollup `support:topic-ranking` не разделяет Zoom/запись; цифры deflection нельзя напрямую равнять с темами 04/05.
- Документ не включает персональные тексты переписки.

_Dr. Mārcis Gasūns_
