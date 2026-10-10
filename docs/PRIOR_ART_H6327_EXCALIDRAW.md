# Prior-art note — H6327 Excalidraw embed (10-10-2026)

_Прочитано до постройки (контракт handoff H6327): официальный репозиторий + продовые встраивания._

- **[excalidraw/excalidraw](https://github.com/excalidraw/excalidraw)** — README + `docs/` по встраиванию.
  Takeaway: официальный способ встроить в не-React приложение — React-компонент
  `Excalidraw` через `createRoot` в своей точке входа; Vue-билда не существует
  (постановка задачи говорила «Vue» — технически возможен только React; React
  поставлен точечно и не трогает основной Alpine/Livewire кабинет). `initialData`
  принимает `elements` + `libraryItems`; сцена сериализуется в `{type:"excalidraw",
  version:2, elements:[...]}` — ровно то, что лежит в `devanagari_boards.scene`.
- **[zsviczian/obsidian-excalidraw-plugin](https://github.com/zsviczian/obsidian-excalidraw-plugin)** —
  крупнейшее продовое встраивание Excalidraw в не-React хост (Obsidian/Electron).
  Takeaway: сцены хранит как файлы с JSON-объектом excalidraw-формата и
  переживает закрытие/переоткрытие — та же модель «сцена JSON на диске/в БД +
  ленивая загрузка чанка».
- **[excalidraw/excalidraw-vscode](https://github.com/excalidraw/excalidraw-vscode)** —
  официальное расширение VS Code (webview-встраивание). Takeaway: standalone
  точка входа на страницу, `excalidrawAPI`-колбэк для доступа к `getSceneElements`
  /`updateScene`; UIOptions.canvasActions.gate для отключения локальных
  load/save (у нас loadScene:false — источник сцены только сервер).

Также сверены npm-пировые зависимости `@excalidraw/excalidraw@0.18.1`
(react 17/18/19; поставлен 19.3 — совместимо) и note про шрифты: Excalidraw
пытается тянуть шрифты с esm.sh — CSP сайта это блокирует (152-ФЗ-совместимо),
канва откатывается на системные шрифты; для курсивных трафаретов хватает
системного рендера.

_Вывод: свой билд не нужен — официальный npm-пакет + ленивый vite-чанк покрывают
всё; фейл-условие «конфликт бандла» не сработало (vite build зелёный)._
