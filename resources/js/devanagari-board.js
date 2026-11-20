/**
 * H6327 — постоянная доска прописи (Devanagari) в кабинете.
 *
 * @excalidraw/excalidraw — React-библиотека (официальная; «Vue» из постановки
 * задачи невозможно технически — у Excalidraw нет Vue-билда). Ставим React
 * только в ЭТОЙ точке входа: основной кабинет (Alpine/Livewire) её не тянет,
 * чанк грузится лениво через dynamic import на странице /dvaram/propisi.
 *
 * Сохранение: сцена (JSON) уходит на сервер (PUT) по debounce 800мс после
 * последнего изменения и на beforeunload. Загрузка: initialData с сервера.
 * Трафареты букв (прописи): initialData.libraryItems из
 * /libraries/devanagari-stencils.excalidrawlib — доступны в пикере Library
 * и сохраняются в localStorage после первой загрузки.
 */
import { createRoot } from 'react-dom/client';
import { createElement } from 'react';

const mount = document.getElementById('devanagari-board-root');

if (mount) {
    const sceneUrl = mount.dataset.sceneUrl;
    const saveUrl = mount.dataset.saveUrl;
    const libraryUrl = mount.dataset.libraryUrl;

    Promise.all([
        import('@excalidraw/excalidraw'),
        fetch(libraryUrl).then((r) => (r.ok ? r.json() : { libraryItems: [] })),
        fetch(sceneUrl, { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : { scene: null })),
    ])
        .then(([ExcalidrawModule, libraryPayload, scenePayload]) => {
            const Excalidraw = ExcalidrawModule.Excalidraw;

            let saveTimer = null;
            let saving = false;
            let dirty = false;

            const doSave = () => {
                if (!window.__excalidrawApi) {
                    return;
                }
                const api = window.__excalidrawApi;
                // Ручной JSON вместо serializeAsJSON: элементы как есть,
                // appState — только фон (без личных настроек — 152-ФЗ).
                const data = {
                    type: 'excalidraw',
                    version: 2,
                    source: 'https://samskrte.ru',
                    elements: api.getSceneElements(),
                    appState: { viewBackgroundColor: api.getAppState().viewBackgroundColor },
                };
                dirty = false;
                saving = true;
                fetch(saveUrl, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ scene: data }),
                })
                    .then((r) => {
                        if (!r.ok) throw new Error('save failed: ' + r.status);
                        const status = document.getElementById('board-save-status');
                        if (status) {
                            status.textContent = 'Сохранено ' + new Date().toLocaleTimeString('ru-RU');
                        }
                    })
                    .catch(() => {
                        const status = document.getElementById('board-save-status');
                        if (status) status.textContent = 'Не удалось сохранить — доска живёт в браузере, попробуйте ещё раз';
                    })
                    .finally(() => {
                        saving = false;
                        if (dirty) doSave();
                    });
            };

            const scheduleSave = () => {
                dirty = true;
                if (saving) return;
                clearTimeout(saveTimer);
                saveTimer = setTimeout(doSave, 800);
            };

            const root = createRoot(mount);

            root.render(
                createElement(Excalidraw, {
                    initialData: {
                        elements: scenePayload.scene ? scenePayload.scene.elements : [],
                        appState: { viewBackgroundColor: '#ffffff' },
                        libraryItems: libraryPayload.libraryItems || [],
                        scrollToContent: true,
                    },
                    onChange: () => scheduleSave(),
                    UIOptions: {
                        canvasActions: {
                            loadScene: false,
                            saveToActiveFile: false,
                            export: { saveFileToDisk: true },
                            saveAsImage: true,
                            clearCanvas: true,
                        },
                    },
                    excalidrawAPI: (api) => {
                        window.__excalidrawApi = api;
                        window.addEventListener('beforeunload', () => {
                            if (dirty) doSave();
                        });
                    },
                }),
            );
        })
        .catch((err) => {
            mount.innerHTML =
                '<p class="text-red-600 p-4">Доска прописи не загрузилась: ' +
                String(err && err.message ? err.message : err) +
                '</p>';
        });
}
