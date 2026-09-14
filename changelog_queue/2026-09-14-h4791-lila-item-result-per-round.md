_Created: 14-09-2026 · Last updated: 14-09-2026_

# H4791: item_result — по раунду, не по загрузке страницы (ox-alpha, opencode `z-ai/glm-5.3-flash`, 14-09-2026)

Гэп H4692, найденный self-review: `telemetry.js` слал `item_result` один раз на загрузку (флаг `sentComplete`, в паре с `complete`), а игрок жмёт «Заново» и решает 2–5 раундов на странице — данные раундов 2..N терялись. MG просил данные по каждому упражнению.

- **Фикс:** `item_result` шлётся по КАЖДОМУ решённому раунду — identity-сравнение `window.SGX_ROUND_RESULT` с `lastResultSent` (движок создаёт свежий объект в `exposeRoundResult()` на каждый решённый раунд, «Заново» снимает `.feedback.show`). `complete` остаётся раз на загрузку — воронка H1360 не тронута.
- **Верификация — настоящий E2E в браузере (Playwright, локальный HTTP-сервер над `public/`):** 3 раунда на одной странице дали 3 × `item_result` (по 10 вопросов) и 1 × `complete`; payload `{l, r, ms, wrong}` с верными drill/band; ошибочный раунд (намеренно перепутанные пары) не засчитан, после исправления решён, `wrong` = 1 на каждую пару — счётчик трудности живой. `node --check` чист.
- [H4791](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4791-OxAlpha_Systema-Sanscriticum_lila-item-result-per-round_14.09.26.md)
_Dr. Mārcis Gasūns_
