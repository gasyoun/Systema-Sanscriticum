_Created: 14-09-2026 · Last updated: 14-09-2026_

# H4692: пер-вопросная телеметрия трудности /lila — item_result (ms до связки + wrong-проверки) + games:difficulty (ox-alpha, opencode `z-ai/glm-5.3-flash`, 14-09-2026)

Запрос MG 14-09: игры не собирают время и трудности по конкретным вопросам («над каким вопросом он дольше всего думал») — а это ценные данные. Воронка H1360 (start/complete/gate/item_seen/round) таймингов не несла.

- **Клиент (`public/lila/match/engine.js`):** таймер раунда (`performance.now()` с фолбэком), per-pair статистика — ms от начала раунда до ПЕРВОЙ связки пары + счётчик «Проверить»-прессов, где пара была ✕. На решённом раунде движок выставляет `window.SGX_ROUND_RESULT = {hints, items:[{l, r, ms, wrong}]}` — тот же opt-in-паттерн, что `SGX_SEEN_ITEMS` (H1680); сброс «Заново» обнуляет таймер.
- **Пересылка (`public/lila/telemetry.js`):** увидев `.feedback.show` (тот же complete-сигнал), один раз шлёт `event=item_result` с клиентскими капами (40 items, ms 0..3 600 000, wrong 0..50). Нет SGX_ROUND_RESULT — нет запроса, поведение match-страниц не меняется.
- **Приёмник (`GameTelemetryController` + `GameEvent::ITEM_RESULT`):** whitelist-валидация — l/r slug ≤160 (контент тренажёра, без PII; R20-контракт не расширяется), ms/wrong зажаты, hints нормализован 0|1; мусор дропается, пусто → payload=null. anon_id и серверный user_id — как у остальных событий.
- **Отчёт:** `php artisan games:difficulty {--days=30} {--limit=25}` — на каждый (drill, band, вопрос): раунды, медиана/среднее ms до связки, доля раундов с ошибочной проверкой; самые трудные сверху (`GameEvent::difficulty()`, PHP-агрегация — строка на раунд). Данные «кто» — anon_id (+ user_id для залогиненных) уже в каждой строке game_events.
- **Тесты:** `GamesItemResultTelemetryTest` 7/7 (persist, cap-40, clamp ms/wrong/hints, дроп односторонних, junk→null, медиана/wrong-rate агрегат, сортировка hardest-first); весь `--filter=Games` — 40/40 (264 assertions), Pint чистый. WORKTREE-ЛОВУШКА: свежий worktree без gitignored `.env` даёт по warning на тест (`file_get_contents(...\.env)`) — фикс: скопировать `.env` из общего дерева, тесты 40 passed.
- **Покрытие:** все 11 match-страниц (/lila ligatures top-10/50/200, iast-cyrillic, kochergina-l1, ru-sa-*, verb-roots) — движковый крючок один. Follow-up (отдельные юниты): те же крючки в sort/cloze/table движках; видимый игроку таймер — решение MG.
- [H4692](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4692-OxAlpha_Systema-Sanscriticum_lila-item-result-telemetry_14.09.26.md)
_Dr. Mārcis Gasūns_
