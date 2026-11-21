/**
 * ИИ-помощник на странице занятия (features.lesson_ai) — вкладка «✦ ИИ»
 * боковой колонки урока. Функции перенесены из расширения ClassNote:
 * пересказ, вопросы для самопроверки, конспект из заметок, «объяснить
 * выделенное», вопрос по занятию.
 *
 *   <div x-data="lessonAi({ url, notesId, hasTranscript })">…</div>
 *
 * Компонент вложен в x-data .lesson-layout и пользуется его seekTo() и
 * activeTab. Сервер ставит генерацию в очередь и отдаёт токен — здесь только
 * опрос статуса (LessonAiController@poll).
 */
window.lessonAi = function (config) {
    var ERRORS = {
        'no-transcript': 'У этого занятия пока нет стенограммы — пересказ и вопросы по записи недоступны.',
        'few-notes': 'Мало заметок: напишите хотя бы несколько строк во вкладке «Заметки».',
        'no-selection': 'Выделите фрагмент текста занятия, стенограммы или заметок.',
        'short-question': 'Сформулируйте вопрос чуть подробнее.',
        'not-found': 'В стенограмме занятия не нашлось ничего похожего на этот вопрос. Попробуйте другие слова.',
        'limit': 'На сегодня запросы к ИИ закончились — завтра лимит обновится. Готовые пересказы занятий открываются без лимита.',
        'offline': 'ИИ-сервер школы сейчас не отвечает. Попробуйте через несколько минут.',
        'bad-answer': 'ИИ ответил в неожиданном формате — попробуйте ещё раз.',
        'empty': 'Нечего отправлять — текст пустой.',
        'provider': 'Не получилось получить ответ. Попробуйте ещё раз.',
        'timeout': 'ИИ отвечал слишком долго. Попробуйте ещё раз.',
        'network': 'Нет связи с сервером. Проверьте интернет и повторите.',
        'gone': 'Занятие не найдено.'
    };
    var TITLES = {
        summary: 'Пересказ занятия',
        quiz: 'Вопросы для самопроверки',
        outline: 'Конспект из заметок',
        explain: 'Объяснение',
        ask: 'Ответ по занятию'
    };
    var LOADING = {
        summary: 'ИИ читает стенограмму и пишет пересказ… Длинное занятие — до пары минут.',
        quiz: 'ИИ составляет вопросы…',
        outline: 'ИИ собирает конспект из ваших заметок…',
        explain: 'ИИ объясняет…',
        ask: 'Ищу ответ в стенограмме…'
    };
    var NOTES_MAX = 5000;

    return {
        hasTranscript: !!config.hasTranscript,
        quotaLeft: typeof config.quotaLeft === 'number' ? config.quotaLeft : null,
        quotaLimit: typeof config.quotaLimit === 'number' ? config.quotaLimit : null,
        state: 'idle', // idle | loading | done | error
        task: null,
        title: '',
        loadingText: '',
        errorText: '',
        html: '',
        md: '',
        questions: [],
        opened: {},
        cached: false,
        question: '',
        selection: '',
        notesBefore: null,
        flash: '',
        pick: { show: false, x: 0, y: 0, text: '' },
        _poll: null,
        _run: 0,

        init() {
            var self = this;
            // «✦ Объяснить» у выделения — в тексте занятия, стенограмме и заметках.
            var onSelect = function (e) {
                setTimeout(function () { self.trackSelection(e); }, 10);
            };
            document.addEventListener('mouseup', onSelect);
            document.addEventListener('keyup', onSelect);
            document.addEventListener('touchend', onSelect);
            document.addEventListener('scroll', function () { self.pick.show = false; }, true);
        },

        notesEl() {
            return document.getElementById(config.notesId);
        },

        trackSelection(e) {
            if (e && e.target && e.target.closest && e.target.closest('[data-lesson-ai-pick]')) return;
            var text = '';
            var rect = null;
            var el = document.activeElement;
            if (el && el.tagName === 'TEXTAREA' && el.closest('[data-lesson-ai-scope]')) {
                text = el.value.substring(el.selectionStart, el.selectionEnd);
                if (text.trim() && e && e.clientX) rect = { left: e.clientX, bottom: e.clientY + 8 };
                if (text.trim() && !rect) {
                    var r = el.getBoundingClientRect();
                    rect = { left: r.left + 16, bottom: r.top + 24 };
                }
            } else {
                var sel = window.getSelection();
                if (sel && sel.rangeCount && !sel.isCollapsed) {
                    var range = sel.getRangeAt(0);
                    var node = range.commonAncestorContainer;
                    var host = node.nodeType === 1 ? node : node.parentElement;
                    // Включая ответы самого ИИ: в пересказе тоже можно выделить и спросить.
                    if (host && host.closest('[data-lesson-ai-scope]')) {
                        text = sel.toString();
                        rect = range.getBoundingClientRect();
                    }
                }
            }
            text = (text || '').replace(/\s+/g, ' ').trim();
            if (text.length >= 3 && rect) {
                this.selection = text.slice(0, 4000);
                this.pick = {
                    show: true,
                    x: Math.max(8, Math.min(window.innerWidth - 150, rect.left)),
                    y: Math.min(window.innerHeight - 48, rect.bottom + 6),
                    text: this.selection
                };
            } else {
                this.pick.show = false;
            }
        },

        explainPicked() {
            var text = this.pick.text;
            this.pick.show = false;
            this.activeTab = 'ai';
            this.explain(text);
        },

        // --- запросы ---------------------------------------------------------
        summary() { this.start('summary', {}); },
        quiz() { this.start('quiz', { notes: this.notesValue() }); },
        outline() { this.start('outline', { notes: this.notesValue() }); },
        explain(text) {
            var t = (text || this.selection || '').trim();
            if (!t) {
                this.title = TITLES.explain;
                return this.fail('explain', 'no-selection');
            }
            this.start('explain', { text: t });
        },
        ask() { this.start('ask', { question: this.question.trim() }); },

        notesValue() {
            var el = this.notesEl();
            return el ? el.value : '';
        },

        start(task, body) {
            var self = this;
            var run = ++this._run;
            this.stopPoll();
            this.task = task;
            this.title = TITLES[task];
            this.state = 'loading';
            this.loadingText = LOADING[task];
            this.errorText = '';
            this.flash = '';
            this.cached = false;
            this.opened = {};
            this.notesBefore = null;

            body.task = task;
            fetch(config.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                credentials: 'same-origin',
                body: JSON.stringify(body)
            }).then(function (res) {
                return res.json().catch(function () { return { status: 'error', error: res.status === 419 ? 'session' : 'provider' }; });
            }).then(function (data) {
                if (run !== self._run) return;
                self.handle(task, data);
                if (data && data.status === 'pending' && data.token) self.poll(task, data.token, run, Date.now());
            }).catch(function () {
                if (run === self._run) self.fail(task, 'network');
            });
        },

        poll(task, token, run, startedAt) {
            var self = this;
            this._poll = setTimeout(function () {
                fetch(config.url + '/' + token, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (run !== self._run) return;
                        if (data.status === 'pending') {
                            if (Date.now() - startedAt > 8 * 60 * 1000) return self.fail(task, 'timeout');
                            return self.poll(task, token, run, startedAt);
                        }
                        self.handle(task, data);
                    })
                    .catch(function () {
                        if (run === self._run) self.poll(task, token, run, startedAt);
                    });
            }, 2500);
        },

        stopPoll() {
            if (this._poll) clearTimeout(this._poll);
            this._poll = null;
        },

        cancel() {
            this._run++;
            this.stopPoll();
            this.state = 'idle';
        },

        handle(task, data) {
            // Счётчик дневного лимита приходит в каждом ответе сервера.
            if (data && typeof data.left === 'number') this.quotaLeft = data.left;
            if (data && typeof data.limit === 'number') this.quotaLimit = data.limit;
            if (!data || data.status === 'error') {
                var code = data ? data.error : 'provider';
                if (code === 'asleep') {
                    return this.fail(task, null, 'ИИ-сервер школы работает ' + this.hours(data.hours) + ' по Москве. Загляните в это время — готовые пересказы открываются круглосуточно.');
                }
                if (code === 'session') return this.fail(task, null, 'Сессия устарела — обновите страницу.');
                return this.fail(task, code);
            }
            if (data.status !== 'done') return;
            this.state = 'done';
            this.html = data.html || '';
            this.md = data.md || '';
            this.questions = data.questions || [];
            this.cached = !!data.cached;
            var self = this;
            this.$nextTick(function () {
                var box = self.$refs.aiResult;
                if (box) box.scrollTop = 0;
            });
        },

        fail(task, code, text) {
            this.stopPoll();
            this.task = task;
            this.state = 'error';
            this.errorText = text || ERRORS[code] || ('Ошибка ИИ: ' + code);
        },

        hours(raw) {
            var m = /^(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/.exec(raw || '');
            return m ? 'с ' + m[1] + ' до ' + m[2] : 'днём';
        },

        retry() {
            if (this.task === 'explain') return this.explain(this.selection);
            if (this.task) this[this.task]();
        },

        // --- результат -------------------------------------------------------
        onResultClick(e) {
            var ts = e.target.closest('.lesson-ai-ts');
            if (ts) {
                e.preventDefault();
                this.seekTo(parseInt(ts.getAttribute('data-ts'), 10) || 0);
            }
        },

        toggleAnswer(i) {
            this.opened = Object.assign({}, this.opened, { [i]: !this.opened[i] });
        },

        plainMd() {
            return this.md.replace(/\*\*/g, '').trim();
        },

        addToNotes() {
            var el = this.notesEl();
            if (!el) return;
            var add = (TITLES[this.task] ? '— ' + TITLES[this.task] + ' (ИИ) —\n' : '') + this.plainMd();
            var next = el.value.trim() ? el.value.replace(/\s+$/, '') + '\n\n' + add : add;
            if (next.length > NOTES_MAX) {
                this.flash = 'В заметки помещается до 5000 знаков — не влезает. Скопируйте текст и сохраните у себя.';
                return;
            }
            this.writeNotes(next);
            this.flash = 'Добавлено в заметки.';
        },

        replaceNotes() {
            var el = this.notesEl();
            if (!el) return;
            var text = this.plainMd();
            if (text.length > NOTES_MAX) {
                this.flash = 'Конспект длиннее 5000 знаков — в заметки не помещается. Скопируйте его.';
                return;
            }
            this.notesBefore = el.value;
            this.writeNotes(text);
            this.flash = 'Заметки заменены конспектом.';
        },

        undoNotes() {
            if (this.notesBefore === null) return;
            this.writeNotes(this.notesBefore);
            this.notesBefore = null;
            this.flash = 'Прежние заметки возвращены.';
        },

        writeNotes(value) {
            var el = this.notesEl();
            el.value = value;
            // Автосейв заметок (lesson-note-autosave.js) слушает input.
            el.dispatchEvent(new Event('input', { bubbles: true }));
        },

        copy() {
            var self = this;
            var text = this.plainMd();
            var done = function () { self.flash = 'Скопировано.'; };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () { self.flash = 'Не удалось скопировать.'; });
            } else {
                self.flash = 'Не удалось скопировать.';
            }
        },

        csrf() {
            var m = document.querySelector('meta[name="csrf-token"]');
            return m ? m.getAttribute('content') : '';
        }
    };
};
