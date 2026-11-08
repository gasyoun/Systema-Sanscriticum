# Доска прописи деванагари (Excalidraw) в кабинете — постоянное хранение вместо webwhiteboard.com (H6327, OxAlpha/GLM-5.3-Flash; 10-10-2026)

_Created: 10-10-2026 · Last updated: 10-10-2026_

В кабинете появилась постоянная доска прописи `/dvaram/propisi`: @excalidraw/excalidraw
0.18.1 (лениво монтируемый React-чанк `resources/js/devanagari-board.js`, основной
Alpine/Livewire кабинет его не тянет), сцена JSON лежит в MySQL `devanagari_boards`
(«студент × занятие», cap 2 МБ с 413 сверху), автосохранение через 800 мс после
штриха и на `beforeunload`. Доска переживает перезагрузку, перезапуск браузера и
смену устройства — замена webwhiteboard.com, терявшему все доски каждые 24 часа.

Трафареты букв (15 шт: гласные + ка-варга, пунктир низкой непрозрачности для
обводки) грузятся в пикер Library из `public/libraries/devanagari-stencils.excalidrawlib`
(идемпотентный генератор `scripts/generate-devanagari-stencils.mjs`). Гайд куратора:
10-й сценарий «Доска прописи: у ученика пропали прописи» + прямой запрет давать
ученикам webwhiteboard.com (`CuratorAdminGuideCoverageTest` 9→10).

- Пруфы: playwright persistence-тест зелёный локально (нарисовать → «Сохранено» →
  свежий браузерный контекст → штрих на месте, `tests/Browser/devanagari-board-persistence.spec.mjs`);
  `DevanagariBoardPersistenceTest` 5/5; guide coverage 10 тестов PASS (1 штатный skip);
  `vite build` зелёный без конфликтов бандла; скрин доски с трафаретом «अ» —
  `tests/screenshots/h6327-propisi-board-stencil.png`; prior-art — `docs/PRIOR_ART_H6327_EXCALIDRAW.md`.
- Нюанс: «Vue» из постановки технически невозможен — у Excalidraw нет Vue-билда,
  встроен через официальный React-компонент. Шрифты esm.sh режет CSP (152-ФЗ-совместимо),
  канва откатывается на системные.
