/**
 * lecture-editor.js
 *
 * Inline-редактор лекций. Подключается контроллером
 * App\Http\Controllers\Editor\LectureDraftController::preview()
 * после <head>. Конфиг приходит через window.__LECTURE_BUILDER__:
 *   { patchUrl, assetBase, csrfToken, draftId }
 *
 * Делает редактируемыми параграфы речи (.dialog-turn[data-block-type=speech])
 * и подписи слайдов (figure[data-block-type=figure] .figure-caption).
 *
 * Каждое изменённое поле помечается в Map; при нажатии кнопки «Сохранить»
 * собирается список patches и постится на patchUrl.
 */
(function () {
    'use strict';

    const cfg = window.__LECTURE_BUILDER__;
    if (!cfg || !cfg.patchUrl) {
        console.warn('[lecture-editor] no __LECTURE_BUILDER__ config — disabling editor');
        return;
    }

    // ── Состояние ────────────────────────────────────────────────────────────
    const dirty = new Map(); // key = "sectionId|blockIndex|paraIndex|field" → {edit object}
    const original = new Map(); // тот же ключ → исходный текст (для отката)

    // ── Сбор редактируемых элементов ─────────────────────────────────────────

    function makeKey(sectionId, blockIndex, paraIndex, field) {
        return `${sectionId}|${blockIndex}|${paraIndex ?? '_'}|${field}`;
    }

    function attachEditableSpeech() {
        document.querySelectorAll('.dialog-turn[data-block-type="speech"]').forEach((turn) => {
            const sectionId = turn.dataset.sectionId;
            const blockIndex = parseInt(turn.dataset.blockIndex, 10);
            turn.querySelectorAll('.para-text').forEach((span) => {
                const paraEl = span.closest('p');
                const paraIndex = parseInt(paraEl.dataset.paraIndex, 10);
                const key = makeKey(sectionId, blockIndex, paraIndex, 'text');

                original.set(key, span.textContent);
                span.contentEditable = 'true';
                span.classList.add('le-editable');

                span.addEventListener('input', () => {
                    const value = span.textContent;
                    if (value === original.get(key)) {
                        dirty.delete(key);
                    } else {
                        dirty.set(key, {
                            section_id: sectionId,
                            block_index: blockIndex,
                            para_index: paraIndex,
                            field: 'text',
                            value,
                        });
                    }
                    updateSaveButton();
                });
            });
        });
    }

    function attachEditableFigures() {
        document.querySelectorAll('figure[data-block-type="figure"] .figure-caption').forEach((caption) => {
            const fig = caption.closest('figure');
            const sectionId = fig.dataset.sectionId;
            const blockIndex = parseInt(fig.dataset.blockIndex, 10);
            const key = makeKey(sectionId, blockIndex, null, 'caption');

            original.set(key, caption.textContent);
            caption.contentEditable = 'true';
            caption.classList.add('le-editable');

            caption.addEventListener('input', () => {
                const value = caption.textContent;
                if (value === original.get(key)) {
                    dirty.delete(key);
                } else {
                    dirty.set(key, {
                        section_id: sectionId,
                        block_index: blockIndex,
                        field: 'caption',
                        value,
                    });
                }
                updateSaveButton();
            });
        });
    }

    function attachEditableHeadings() {
        document.querySelectorAll('h2[id], h3[id]').forEach((heading) => {
            const sectionId = heading.id;
            if (!sectionId || sectionId.startsWith('slide-') || sectionId.startsWith('gterm-')) return;

            const titleSpan = document.createElement('span');
            titleSpan.className = 'le-heading-text';
            // Берём только текстовый узел до первого вложенного span (timecode-link)
            const firstTextNode = Array.from(heading.childNodes).find(
                (n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim()
            );
            if (!firstTextNode) return;

            titleSpan.textContent = firstTextNode.textContent.trim();
            heading.replaceChild(titleSpan, firstTextNode);

            const key = makeKey(sectionId, null, null, 'title');
            original.set(key, titleSpan.textContent);
            titleSpan.contentEditable = 'true';
            titleSpan.classList.add('le-editable');

            titleSpan.addEventListener('input', () => {
                const value = titleSpan.textContent;
                if (value === original.get(key)) {
                    dirty.delete(key);
                } else {
                    dirty.set(key, {
                        section_id: sectionId,
                        field: 'title',
                        value,
                    });
                }
                updateSaveButton();
            });
        });
    }

    // ── UI: floating-панель сохранения ───────────────────────────────────────

    let panel, saveBtn, statusEl;

    function buildPanel() {
        const style = document.createElement('style');
        style.textContent = `
            .le-editable { outline: 1px dashed transparent; transition: outline-color .2s; padding: 1px 2px; border-radius: 2px; }
            .le-editable:hover { outline-color: rgba(99,102,241,0.4); }
            .le-editable:focus { outline: 2px solid rgba(99,102,241,0.7); background: rgba(99,102,241,0.06); }
            #le-panel {
                position: fixed; bottom: 16px; right: 16px; z-index: 99999;
                background: #1f2937; color: #e5e7eb; padding: 10px 14px;
                border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.35);
                font: 13px/1.4 system-ui, sans-serif; display: flex; gap: 10px; align-items: center;
            }
            #le-save { background: #6366f1; color: #fff; border: 0; padding: 6px 14px;
                border-radius: 6px; cursor: pointer; font-size: 13px; }
            #le-save:disabled { background: #4b5563; cursor: not-allowed; }
            #le-save:hover:not(:disabled) { background: #4f46e5; }
            #le-status { font-size: 12px; color: #9ca3af; }
        `;
        document.head.appendChild(style);

        panel = document.createElement('div');
        panel.id = 'le-panel';
        panel.innerHTML = `
            <span id="le-status">правок: 0</span>
            <label style="font-size: 12px;">
                <input type="checkbox" id="le-rebuild" checked> ребилд
            </label>
            <button id="le-save" disabled>Сохранить</button>
        `;
        document.body.appendChild(panel);

        saveBtn = panel.querySelector('#le-save');
        statusEl = panel.querySelector('#le-status');
        saveBtn.addEventListener('click', save);
    }

    function updateSaveButton() {
        const n = dirty.size;
        statusEl.textContent = `правок: ${n}`;
        saveBtn.disabled = n === 0;
    }

    // ── Сохранение ───────────────────────────────────────────────────────────

    async function save() {
        if (dirty.size === 0) return;
        const rebuild = panel.querySelector('#le-rebuild').checked;
        const patches = Array.from(dirty.values());

        saveBtn.disabled = true;
        saveBtn.textContent = 'Сохраняю…';

        try {
            const resp = await fetch(cfg.patchUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': cfg.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ patches, rebuild }),
            });
            const body = await resp.json();
            if (!resp.ok || !body.ok) {
                throw new Error(body.error || `HTTP ${resp.status}`);
            }
            // Принять текущее состояние как новое исходное
            for (const [key, edit] of dirty) {
                const newOriginal = edit.value;
                original.set(key, newOriginal);
            }
            dirty.clear();
            statusEl.textContent = rebuild ? 'сохранено + ребилд ✓' : 'сохранено ✓';

            if (rebuild) {
                // Дать пользователю секунду увидеть статус, потом перезагрузить
                setTimeout(() => location.reload(), 800);
            }
        } catch (e) {
            console.error('[lecture-editor] save failed', e);
            statusEl.textContent = 'ошибка: ' + e.message;
            statusEl.style.color = '#fca5a5';
        } finally {
            saveBtn.textContent = 'Сохранить';
            updateSaveButton();
        }
    }

    // ── Старт ────────────────────────────────────────────────────────────────

    function init() {
        buildPanel();
        attachEditableSpeech();
        attachEditableFigures();
        attachEditableHeadings();
        updateSaveButton();
        console.info('[lecture-editor] ready, draft #' + cfg.draftId);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
