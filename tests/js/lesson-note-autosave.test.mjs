/**
 * H6302 — behavioral checks for public/js/lesson-note-autosave.js engine.
 *
 * Run: node --test tests/js/
 *
 * Файл-подрядчик DOM-независим: движок создаётся инъекцией getText/send/
 * таймеров, поэтому вся клиентская приёмка (дебаунс, single-flight порядок,
 * сетевой сбой и 419 не выдают несохранённый текст за сохранённый) гоняется
 * без браузера. DOM-обвязка (attach) покрыта рендер-хуками в
 * tests/Feature/Student/LessonNoteAutosaveTest.php.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const asset = path.join(here, '..', '..', 'public', 'js', 'lesson-note-autosave.js');

// Пакет объявлен "type": "module", поэтому .js-ассет импортируется как ESM;
// он регистрирует себя на globalThis.LessonNoteAutosave (окно в браузере).
await import(pathToFileURL(asset).toString());
const { createEngine } = globalThis.LessonNoteAutosave;

/** Ручные таймеры: tick() запускает все отложенные колбэки дебаунса. */
function manualTimers() {
    const pending = new Map();
    let seq = 0;
    return {
        setTimeoutFn: (fn) => {
            const id = ++seq;
            pending.set(id, fn);
            return id;
        },
        clearTimeoutFn: (id) => pending.delete(id),
        tick: () => {
            const fns = [...pending.values()];
            pending.clear();
            for (const fn of fns) fn();
        },
        get size() { return pending.size; },
    };
}

/** send с ручным подтверждением: сервер «получает» тексты в массив calls. */
function deferredSend() {
    const calls = [];
    const resolvers = [];
    return {
        send: (text) => {
            calls.push(text);
            return new Promise((res) => resolvers.push(res));
        },
        calls,
        resolveOk: () => resolvers.shift()({ ok: true, status: 200 }),
        resolveFail: (status) => resolvers.shift()({ ok: false, status }),
    };
}

function makeHarness({ text = '', initialText = null } = {}) {
    const timers = manualTimers();
    const net = deferredSend();
    const statuses = [];
    let nowTs = 1_000_000;
    const engine = createEngine({
        getText: () => text,
        send: net.send,
        onStatus: (st) => statuses.push(st),
        initialText: initialText === null ? text : initialText,
        debounceMs: 1200,
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
        nowFn: () => nowTs,
    });
    return {
        engine, net, timers, statuses,
        type: (s) => { text = s; engine.input(); },
        clock: (ms) => { nowTs += ms; },
        last: () => statuses[statuses.length - 1],
    };
}

test('initial clean note starts saved, no request fired', () => {
    const h = makeHarness({ text: 'заметка с сервера' });
    assert.equal(h.engine.isDirty(), false);
    assert.deepEqual(h.net.calls, []);
    assert.equal(h.last().state, 'saved');
});

test('typing alone does not send before the debounce fires', () => {
    const h = makeHarness({ text: '' });
    h.type('черновик');
    assert.deepEqual(h.net.calls, [], 'ничего не отправлено до дебаунса');
    assert.equal(h.last().state, 'dirty');
    h.timers.tick();
    assert.deepEqual(h.net.calls, ['черновик']);
    assert.equal(h.last().state, 'saving');
});

test('debounced input only sends the latest text', () => {
    const h = makeHarness({ text: '' });
    h.type('а');
    h.type('аб');
    h.type('абв');
    h.timers.tick();
    assert.deepEqual(h.net.calls, ['абв'], 'отправлен только последний текст');
});

test('success marks saved and clears dirty', async () => {
    const h = makeHarness({ text: '' });
    h.type('мысль');
    h.timers.tick();
    h.net.resolveOk();
    await flush();
    assert.equal(h.engine.isDirty(), false);
    assert.equal(h.last().state, 'saved');
    assert.ok(h.last().savedAt, 'есть метка времени сохранения');
});

test('rejected send keeps text dirty with error status and no savedAt', async () => {
    let rejecter;
    const net = {
        send: () => new Promise((_, rej) => { rejecter = rej; }),
        calls: [],
    };
    net.send = (text) => {
        net.calls.push(text);
        return new Promise((_, rej) => { rejecter = rej; });
    };
    const timers = manualTimers();
    const statuses = [];
    const engine = createEngine({
        getText: () => 'важная мысль',
        send: net.send,
        onStatus: (st) => statuses.push(st),
        initialText: '', // сервер ещё не видел этот текст
        debounceMs: 1200,
        ...timers,
    });
    engine.input();
    timers.tick();
    rejecter(new TypeError('Failed to fetch'));
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(engine.isDirty(), true, 'текст остаётся несохранённым');
    const last = statuses[statuses.length - 1];
    assert.equal(last.state, 'error');
    assert.equal(last.savedAt, null, 'несохранённый текст не выдаётся за сохранённый');
    assert.deepEqual(net.calls, ['важная мысль']);
});

test('HTTP 419 (expired session) keeps text dirty with error status', async () => {
    const h = makeHarness({ text: '' });
    h.type('черновик при истёкшей сессии');
    h.timers.tick();
    h.net.resolveFail(419);
    await flush();
    assert.equal(h.engine.isDirty(), true);
    assert.equal(h.last().state, 'error');
    assert.equal(h.last().error.status, 419);
    assert.equal(h.last().savedAt, null);
});

test('retry after failure eventually saves the same text', async () => {
    const h = makeHarness({ text: 'вторая попытка', initialText: '' });
    h.engine.save();
    h.net.resolveFail(500);
    await flush();
    assert.equal(h.last().state, 'error');
    h.engine.save(); // кнопка «Повторить»
    assert.deepEqual(h.net.calls, ['вторая попытка', 'вторая попытка']);
    h.net.resolveOk();
    await flush();
    assert.equal(h.last().state, 'saved');
    assert.equal(h.engine.isDirty(), false);
});

test('delayed older save cannot overwrite a newer accepted version', async () => {
    const h = makeHarness({ text: 'v1', initialText: '' }); // сервер пока пуст
    h.engine.save(); // v1 в полёте
    assert.deepEqual(h.net.calls, ['v1']);

    h.type('v2'); // набираем новее ПОКА v1 в полёте — уходит в очередь
    h.timers.tick(); // дебаунс срабатывает во время полёта v1
    assert.deepEqual(h.net.calls, ['v1'], 'новый запрос не отправлен, пока старый в полёте');

    h.net.resolveOk(); // v1 принят
    await flush();
    // Пока v2 не подтверждён — статус НЕ saved (нет ложного «Сохранено»).
    assert.notEqual(h.last().state, 'saved', 'нет ложного «сохранено» для более нового текста');
    assert.deepEqual(h.net.calls, ['v1', 'v2'], 'после v1 немедленно отправлен свежий v2');

    h.net.resolveOk();
    await flush();
    assert.equal(h.last().state, 'saved');
    assert.equal(h.engine.isDirty(), false);
    // Инвариант порядка записи: последним на сервер ушёл v2.
    assert.equal(h.net.calls[h.net.calls.length - 1], 'v2');
});

test('typing during a successful flight re-sends the newer text', async () => {
    const h = makeHarness({ text: '' });
    h.type('короткая');
    h.timers.tick();
    h.type('короткая, потом дополненная'); // пока запрос в полёте
    h.timers.tick(); // дебаунс-очередь
    h.net.resolveOk();
    await flush();
    assert.deepEqual(h.net.calls, ['короткая', 'короткая, потом дополненная']);
    h.net.resolveOk();
    await flush();
    assert.equal(h.last().state, 'saved');
});

test('no auto-retry hammering after failure even if input arrived during flight', async () => {
    const h = makeHarness({ text: 'x', initialText: '' }); // сервер пока пуст
    h.engine.save(); // 'x' в полёте
    h.type('xy');
    h.timers.tick(); // queued во время полёта
    h.net.resolveFail(419);
    await flush();
    // Ошибка не авто-ретраится: остаётся ровно один отправленный запрос.
    assert.deepEqual(h.net.calls, ['x']);
    assert.equal(h.last().state, 'error');
    // Следующий ввод снимает ошибку и пробует снова.
    h.type('xyz');
    h.timers.tick();
    assert.deepEqual(h.net.calls, ['x', 'xyz']);
});

function flush() {
    // Промис-цепочки движка: два микро-тика достаточно для then-обработчиков.
    return Promise.resolve().then(() => Promise.resolve()).then(() => Promise.resolve());
}
