/**
 * Lesson note autosave (H6302, ticket L2 of the learning-UX audit).
 *
 * Заметки урока раньше были только plain-POST формой: ушёл со страницы до
 * нажатия «Сохранить изменения» — текст потерян. Этот скрипт добавляет к ТОМУ
 * ЖЕ эндпоинту student.lesson.note:
 *
 *   - дебаунс-автосей (~1.2 c после последнего нажатия);
 *   - видимый статус Сохранение… / Сохранено ✓ ЧЧ:ММ / Не удалось сохранить;
 *   - dirty-navigation guard (beforeunload), пока текст != последнему
 *     сохранённому сервером;
 *   - single-flight сериализацию запросов: пока один сейв в полёте, новый не
 *     отправляется — отправляется он только после завершения предыдущего и
 *     всегда с САМЫМ СВЕЖИМ текстом, поэтому «поздний старый» ответ физически
 *     не может перезаписать более новую принятую версию;
 *   - честную обработку 419 (истёкшая сессия/CSRF) и сетевых сбоев: текст
 *     остаётся в textarea, статус «не сохранено», кнопка «Повторить»;
 *   - ручной сабмит формы сохранён как fallback: если этот скрипт не
 *     загрузился, нативный POST работает как раньше (preventDefault вешается
 *     только живым скриптом).
 *
 * Приватность: заметки НЕ пишутся ни в localStorage, ни в sessionStorage —
 * состояние живёт только в памяти страницы и на сервере.
 *
 * Архитектура: createEngine() — DOM-независимая машина состояний с
 * инъекцией send/таймеров (покрыта node-тестом tests/js/), attach() — тонкая
 * DOM-обвязка для lesson.blade.php. Стиль — ES5-совместимый IIFE, как у
 * video-resume.js: скрипт грузится как статический ассет, вне Vite-сборки.
 */
(function (root) {
    'use strict';

    /**
     * DOM-независимый движок автосейва заметки.
     *
     * opts:
     *   getText() -> string       текущий текст (источник истины — textarea)
     *   send(text) -> Promise<{ok: boolean, status?: number}>
     *                             POST заметки; reject = сетевой сбой
     *   onStatus(st) -> void      колбэк статуса:
     *                             st = {state, dirty, savedAt, error}
     *                             state: 'saved'|'dirty'|'saving'|'error'
     *   initialText string        сервер-рендер начальной заметки (базалайн)
     *   debounceMs number         задержка после ввода (default 1200)
     *   setTimeoutFn/clearTimeoutFn/nowFn — инъекция для тестов
     */
    function createEngine(opts) {
        var getText = opts.getText;
        var send = opts.send;
        var onStatus = opts.onStatus || function () {};
        var debounceMs = opts.debounceMs === undefined ? 1200 : opts.debounceMs;
        var setT = opts.setTimeoutFn || function (fn, ms) { return setTimeout(fn, ms); };
        var clearT = opts.clearTimeoutFn || function (t) { clearTimeout(t); };
        var now = opts.nowFn || function () { return Date.now(); };

        var lastSaved = opts.initialText !== undefined ? opts.initialText : getText();
        var savedAt = null;
        var timer = null;
        var inFlight = false;
        var queued = false;
        var lastError = null;
        var destroyed = false;

        function isDirty() {
            return getText() !== lastSaved;
        }

        function emit() {
            var state;
            if (inFlight) {
                state = 'saving';
            } else if (lastError) {
                state = 'error';
            } else if (isDirty()) {
                state = 'dirty';
            } else {
                state = 'saved';
            }
            onStatus({ state: state, dirty: isDirty(), savedAt: savedAt, error: lastError });
        }

        /** Перезапустить дебаунс-таймер (по input или вручную). */
        function schedule() {
            if (destroyed) {
                return;
            }
            lastError = null; // новый ввод снимает прошлую ошибку и пробует снова
            if (timer !== null) {
                clearT(timer);
            }
            timer = setT(function () {
                timer = null;
                save();
            }, debounceMs);
            emit();
        }

        /**
         * Немедленный сейв (по таймеру, кнопке «Повторить» или ручному
         * сабмиту). Если запрос уже в полёте — ставим queued: после его
         * завершения отправится СВЕЖИЙ текст. Так порядок записи на сервере
         * совпадает с порядком отправки, и старый ответ не может прийти
         * позже нового (single-flight).
         */
        function save() {
            if (destroyed) {
                return Promise.resolve(false);
            }
            if (timer !== null) {
                clearT(timer);
                timer = null;
            }
            if (inFlight) {
                queued = true;
                return Promise.resolve(false);
            }
            var snapshot = getText();
            if (snapshot === lastSaved && !lastError) {
                emit();
                return Promise.resolve(true);
            }
            inFlight = true;
            lastError = null;
            emit();

            // send вызывается синхронно: запрос уходит на сервер в момент
            // save(), а не в отложенной микрозадаче (важно для порядка
            // single-flight). Синхронный throw провайдера → rejection.
            var p;
            try {
                p = Promise.resolve(send(snapshot));
            } catch (err) {
                p = Promise.reject(err);
            }
            return p.then(
                    function (res) {
                        if (destroyed) {
                            return;
                        }
                        if (res && res.ok) {
                            lastSaved = snapshot;
                            savedAt = now();
                        } else {
                            lastError = { status: res && res.status ? res.status : 0 };
                        }
                        settle();
                    },
                    function () {
                        if (destroyed) {
                            return;
                        }
                        // Сетевой сбой: текст не трогаем, остаётся dirty.
                        lastError = { status: 0 };
                        settle();
                    }
                );
        }

        function settle() {
            inFlight = false;
            // После успеха: если за время полёта снова набирали текст —
            // немедленно отправляем свежий. После ошибки авто-ретраев нет
            // (не долбим мёртвую сеть/419): ждём ввода или кнопку «Повторить».
            var requeue = queued && !lastError;
            queued = false;
            if (requeue) {
                save();
            } else {
                emit();
            }
        }

        function destroy() {
            destroyed = true;
            if (timer !== null) {
                clearT(timer);
                timer = null;
            }
        }

        // Стартовый статус без лишнего emit-шума: если заметка уже есть и
        // совпадает с сервером, молча считаемся сохранёнными.
        emit();

        return {
            input: schedule,
            save: save,
            isDirty: isDirty,
            destroy: destroy,
        };
    }

    /**
     * DOM-обвязка для lesson.blade.php.
     *
     * config:
     *   textarea, form       — элементы формы заметок
     *   statusEl             — элемент строки статуса (aria-live)
     *   retryEl              — кнопка «Повторить» (hidden, пока нет ошибки)
     *   url string           — POST student.lesson.note
     *   csrf string          — токен (meta или _token формы)
     *   fetchFn              — инъекция fetch для тестов
     */
    function attach(config) {
        var textarea = config.textarea;
        var form = config.form;
        var statusEl = config.statusEl;
        var retryEl = config.retryEl;
        var url = config.url;
        var csrf = config.csrf;
        var fetchFn = config.fetchFn || root.fetch.bind(root);

        function send(text) {
            var body = new URLSearchParams();
            body.set('notes', text);
            return fetchFn(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
                body: body.toString(),
                credentials: 'same-origin',
            }).then(function (res) {
                // 2xx (в т.ч. JSON {saved:true}) — успех; 419/403/422/5xx —
                // неуспех, текст остаётся dirty.
                return { ok: res.ok, status: res.status };
            });
        }

        function clock(ts) {
            var d = new Date(ts);
            var hh = ('0' + d.getHours()).slice(-2);
            var mm = ('0' + d.getMinutes()).slice(-2);
            return hh + ':' + mm;
        }

        var STATUS_CLASS = 'text-[11px] font-semibold truncate';

        function render(st) {
            if (statusEl) {
                if (st.state === 'saving') {
                    statusEl.textContent = 'Сохранение…';
                    statusEl.className = STATUS_CLASS + ' text-yellow-700/80';
                } else if (st.state === 'saved') {
                    statusEl.textContent = st.savedAt ? 'Сохранено ✓ ' + clock(st.savedAt) : 'Сохранено ✓';
                    statusEl.className = STATUS_CLASS + ' text-emerald-700/80';
                } else if (st.state === 'dirty') {
                    statusEl.textContent = 'Есть несохранённые изменения…';
                    statusEl.className = STATUS_CLASS + ' text-yellow-800/70';
                } else {
                    statusEl.textContent = st.error && st.error.status === 419
                        ? 'Сессия истекла — текст не потерян. Нажмите «Повторить» или сохраните через кнопку ниже.'
                        : 'Не удалось сохранить — текст не потерян.';
                    statusEl.className = STATUS_CLASS + ' text-red-600';
                }
            }
            if (retryEl) {
                retryEl.classList.toggle('hidden', st.state !== 'error');
            }
        }

        var engine = createEngine({
            getText: function () { return textarea.value; },
            send: send,
            onStatus: render,
            initialText: textarea.value,
            debounceMs: 1200,
        });

        textarea.addEventListener('input', function () { engine.input(); });

        if (retryEl) {
            retryEl.addEventListener('click', function () { engine.save(); });
        }

        // Ручной сабмит остаётся рабочим fallback-ом: preventDefault вешаем
        // только здесь (живым JS), значит без скрипта форма постится как
        // раньше. С живым JS — сейвим через fetch без перезагрузки страницы.
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            engine.save();
        });

        // Dirty-navigation guard: предупреждаем уход со страницы, пока есть
        // несохранённый текст. После успешного сохранения guard молчит.
        root.addEventListener('beforeunload', function (e) {
            if (engine.isDirty()) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        return engine;
    }

    root.LessonNoteAutosave = {
        createEngine: createEngine,
        attach: attach,
    };
})(typeof window !== 'undefined' ? window : globalThis);
