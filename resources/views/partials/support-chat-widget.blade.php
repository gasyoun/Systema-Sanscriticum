{{--
    Живой веб-чат поддержки — виджет посетителя (H536 Phase 4).

    Плавающая кнопка-пузырь на витрине samskrte.ru: гость или залогиненный
    студент открывает панель, пишет сообщение и получает ответ куратора без
    перезагрузки. Полностью self-contained (разметка + scoped CSS + vanilla JS),
    монтируется перед </body> в main.blade.php.

    Транспорт:
      • отправка   → POST {{ route('chat.message') }} (throttle:30,1), CSRF;
      • история    → GET  {{ route('chat.history') }} (throttle:60,1);
      • живой push  → Echo private-канал support.conversation.{id}, событие
                     .chat.message (ответы куратора, Phase 5). Echo включается
                     только когда VITE_REVERB_APP_KEY задан (guard в
                     resources/js/bootstrap.js) — до деплоя Reverb виджет
                     работает на light-poll фолбэке, ничего не ломается.

    Безопасность: сервер отдаёт уже экранированный `html` (ChatMessage::htmlForWeb,
    whitelist без атрибутов) — рендерим его, но НИКОГДА не сырой ввод посетителя.

    Контекстное приветствие по странице входа (H1198, Jivo-паритет S3): за флагом
    support_answer_suggester (тем же, что у FAQ-суггестера H247 — единый рубильник
    "AI-assist на веб-чате"), клиентский JS подменяет статичное приветствие на
    контекстное по URL-паттерну (курс vs оплата) БЕЗ обращения к серверу — паттерн
    известен сразу, `entry_url` (S1/H1196) фиксируется только сервером и только
    после первого сообщения, для приветствия ДО сообщения он не годится.
--}}
<div
    id="scw-root"
    class="scw"
    data-post-url="{{ route('chat.message') }}"
    data-history-url="{{ route('chat.history') }}"
    data-csrf="{{ csrf_token() }}"
    data-authed="{{ auth()->check() ? '1' : '0' }}"
    data-context-greeting="{{ app(\App\Services\Support\SupportAnswerSuggester::class)->isEnabled() ? '1' : '0' }}"
    aria-live="polite"
>
    <button
        id="scw-toggle"
        type="button"
        class="scw-toggle"
        aria-label="Открыть чат с поддержкой"
        aria-expanded="false"
        aria-controls="scw-panel"
    >
        <svg class="scw-toggle-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span class="scw-toggle-badge" id="scw-badge" hidden>1</span>
    </button>

    <section
        id="scw-panel"
        class="scw-panel"
        role="dialog"
        aria-label="Чат с поддержкой Общества ревнителей санскрита"
        aria-modal="false"
        hidden
    >
        <header class="scw-header">
            <div class="scw-header-title">
                <span class="scw-dot" aria-hidden="true"></span>
                Чат с поддержкой
            </div>
            <button type="button" class="scw-close" id="scw-close" aria-label="Свернуть чат">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" width="20" height="20">
                    <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </header>

        <div class="scw-messages" id="scw-messages" role="log" aria-live="polite">
            <div class="scw-intro" id="scw-intro">
                Здравствуйте! Напишите нам — ответим по курсам, оплате и занятиям.
            </div>
        </div>

        <form class="scw-form" id="scw-form" autocomplete="off">
            <input
                type="text"
                class="scw-name"
                id="scw-name"
                name="name"
                maxlength="120"
                placeholder="Ваше имя (необязательно)"
                @if(auth()->check()) hidden @endif
            >
            <div class="scw-input-row">
                <textarea
                    class="scw-text"
                    id="scw-text"
                    name="text"
                    rows="1"
                    maxlength="2000"
                    placeholder="Ваше сообщение…"
                    aria-label="Текст сообщения"
                    required
                ></textarea>
                <button type="submit" class="scw-send" id="scw-send" aria-label="Отправить">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" width="20" height="20">
                        <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
        </form>
    </section>
</div>

<style>
    .scw { position: fixed; right: 20px; bottom: 20px; z-index: 9998; font-family: inherit; }
    .scw-toggle {
        position: relative; width: 60px; height: 60px; border-radius: 50%; border: none;
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        color: #fff; background: linear-gradient(135deg, #E85C24, #c9491a);
        box-shadow: 0 6px 20px rgba(232, 92, 36, 0.45); transition: transform .2s ease, box-shadow .2s ease;
    }
    .scw-toggle:hover { transform: scale(1.06); box-shadow: 0 8px 26px rgba(232, 92, 36, 0.6); }
    .scw-toggle:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
    .scw-toggle-icon { width: 28px; height: 28px; }
    .scw-toggle-badge {
        position: absolute; top: -2px; right: -2px; min-width: 20px; height: 20px; padding: 0 5px;
        border-radius: 10px; background: #16a34a; color: #fff; font-size: 12px; font-weight: 700;
        display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 2px #111827;
    }
    .scw-panel {
        position: absolute; right: 0; bottom: 74px; width: 360px; max-width: calc(100vw - 32px);
        height: 520px; max-height: calc(100vh - 120px); display: flex; flex-direction: column;
        background: #111827; color: #e5e7eb; border: 1px solid #1f2937; border-radius: 16px;
        overflow: hidden; box-shadow: 0 18px 50px rgba(0, 0, 0, 0.5);
        animation: scw-in .18s ease-out;
    }
    @keyframes scw-in { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
    .scw-header {
        display: flex; align-items: center; justify-content: space-between; padding: 14px 16px;
        background: linear-gradient(135deg, #E85C24, #c9491a); color: #fff;
    }
    .scw-header-title { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 15px; }
    .scw-dot { width: 9px; height: 9px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 0 3px rgba(74,222,128,.3); }
    .scw-close { background: transparent; border: none; color: #fff; cursor: pointer; padding: 4px; border-radius: 6px; display: flex; }
    .scw-close:hover { background: rgba(255,255,255,.18); }
    .scw-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px; }
    .scw-intro { font-size: 13px; color: #9ca3af; text-align: center; padding: 8px 12px; line-height: 1.5; }
    .scw-msg { max-width: 82%; padding: 9px 13px; border-radius: 14px; font-size: 14px; line-height: 1.45; word-wrap: break-word; }
    .scw-msg :where(a) { color: inherit; text-decoration: underline; }
    .scw-msg-user { align-self: flex-end; background: #E85C24; color: #fff; border-bottom-right-radius: 4px; }
    .scw-msg-op { align-self: flex-start; background: #1f2937; color: #e5e7eb; border-bottom-left-radius: 4px; }
    .scw-msg-label { display: block; font-size: 11px; font-weight: 700; color: #f59e0b; margin-bottom: 2px; }
    .scw-form { border-top: 1px solid #1f2937; padding: 10px; background: #0b1220; }
    .scw-name {
        width: 100%; margin-bottom: 8px; padding: 8px 12px; border-radius: 10px; border: 1px solid #1f2937;
        background: #111827; color: #e5e7eb; font-size: 13px;
    }
    .scw-input-row { display: flex; align-items: flex-end; gap: 8px; }
    .scw-text {
        flex: 1; resize: none; max-height: 120px; padding: 10px 12px; border-radius: 10px;
        border: 1px solid #1f2937; background: #111827; color: #e5e7eb; font-size: 14px; font-family: inherit; line-height: 1.4;
    }
    .scw-name:focus, .scw-text:focus { outline: none; border-color: #E85C24; }
    .scw-send {
        flex-shrink: 0; width: 42px; height: 42px; border-radius: 10px; border: none; cursor: pointer;
        color: #fff; background: #E85C24; display: flex; align-items: center; justify-content: center; transition: background .15s;
    }
    .scw-send:hover { background: #c9491a; }
    .scw-send:disabled { opacity: .5; cursor: default; }
    @media (max-width: 480px) {
        .scw { right: 14px; bottom: 14px; }
        .scw-panel { width: calc(100vw - 28px); height: 70vh; bottom: 70px; }
    }
</style>

<script>
(function () {
    var root = document.getElementById('scw-root');
    if (!root) return;

    var POST_URL = root.dataset.postUrl;
    var HISTORY_URL = root.dataset.historyUrl;
    var CSRF = root.dataset.csrf;
    var CONTEXT_GREETING = root.dataset.contextGreeting === '1';
    var STORE_KEY = 'scw_open';

    var toggle = document.getElementById('scw-toggle');
    var panel = document.getElementById('scw-panel');
    var closeBtn = document.getElementById('scw-close');
    var list = document.getElementById('scw-messages');
    var intro = document.getElementById('scw-intro');
    var form = document.getElementById('scw-form');
    var textEl = document.getElementById('scw-text');
    var nameEl = document.getElementById('scw-name');
    var sendBtn = document.getElementById('scw-send');
    var badge = document.getElementById('scw-badge');

    var conversationId = null;
    var seen = {};              // id -> true, дедуп сообщений
    var loaded = false;         // история подтянута
    var subscribed = false;     // Echo-подписка активна
    var pollTimer = null;       // фолбэк-опрос без Echo
    var isOpen = false;

    // Контекстное приветствие по странице входа (H1198): URL известен сразу,
    // без обращения к серверу — за флагом support_answer_suggester. Дефолтный
    // текст (уже в разметке) остаётся, если флаг выключен или страница не
    // распознана ни одним паттерном.
    function applyContextualGreeting() {
        if (!CONTEXT_GREETING || !intro) return;
        var path = (location.pathname || '').toLowerCase();
        var greeting = null;
        if (path.indexOf('/online/kursy/') === 0 || path.indexOf('/course/') === 0) {
            greeting = 'Здравствуйте! Вопрос по этому курсу — программа, расписание, преподаватель? Мы поможем разобраться.';
        } else if (path.indexOf('/checkout/') === 0 || path.indexOf('/payment/') === 0 || path.indexOf('/deposit/') === 0) {
            greeting = 'Здравствуйте! Возникли сложности с оплатой? Мы поможем разобраться.';
        }
        if (greeting) intro.textContent = greeting;
    }
    applyContextualGreeting();

    function escapeText(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function appendMessage(msg) {
        if (!msg || msg.id == null || seen[msg.id]) return;
        seen[msg.id] = true;
        if (intro) { intro.remove(); intro = null; }

        var isUser = msg.role === 'user';
        var el = document.createElement('div');
        el.className = 'scw-msg ' + (isUser ? 'scw-msg-user' : 'scw-msg-op');

        if (msg.role === 'curator') {
            var label = document.createElement('span');
            label.className = 'scw-msg-label';
            label.textContent = 'Куратор';
            el.appendChild(label);
        }

        var body = document.createElement('span');
        // Сервер уже отдал экранированный whitelist-HTML (htmlForWeb); безопасно.
        body.innerHTML = msg.html != null ? msg.html : escapeText(msg.text);
        el.appendChild(body);

        list.appendChild(el);
        list.scrollTop = list.scrollHeight;
    }

    function renderBadge(show) {
        if (!badge) return;
        badge.hidden = !show;
    }

    function subscribeLive() {
        if (subscribed || !conversationId || !window.Echo) return;
        try {
            window.Echo.private('support.conversation.' + conversationId)
                .listen('.chat.message', function (e) {
                    appendMessage(e);
                    if (!isOpen && e.role !== 'user') renderBadge(true);
                });
            subscribed = true;
            stopPolling(); // живой push вместо опроса
        } catch (err) { /* Echo не поднят — остаёмся на фолбэке */ }
    }

    function startPolling() {
        if (pollTimer || window.Echo) return; // с Echo опрос не нужен
        pollTimer = setInterval(function () { if (isOpen) loadHistory(true); }, 12000);
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    function loadHistory(silent) {
        return fetch(HISTORY_URL, {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.ok) return;
            var had = conversationId;
            conversationId = data.conversation_id || conversationId;
            (data.messages || []).forEach(appendMessage);
            loaded = true;
            if (conversationId && conversationId !== had) subscribeLive();
        })
        .catch(function () { /* сеть недоступна — не шумим */ });
    }

    function openPanel() {
        isOpen = true;
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        renderBadge(false);
        try { localStorage.setItem(STORE_KEY, '1'); } catch (e) {}
        if (!loaded) loadHistory();
        else subscribeLive();
        startPolling();
        setTimeout(function () { textEl.focus(); }, 60);
    }

    function closePanel() {
        isOpen = false;
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        try { localStorage.setItem(STORE_KEY, '0'); } catch (e) {}
    }

    function autoGrow() {
        textEl.style.height = 'auto';
        textEl.style.height = Math.min(textEl.scrollHeight, 120) + 'px';
    }

    function submit() {
        var text = (textEl.value || '').trim();
        if (text === '') return;
        var payload = { text: text };
        if (nameEl && !nameEl.hidden && nameEl.value.trim() !== '') payload.name = nameEl.value.trim();
        // Страница, с которой посетитель пишет — куратор видит контекст (H1196).
        try { if (location && location.href) payload.page = String(location.href).slice(0, 2048); } catch (e) {}

        sendBtn.disabled = true;
        fetch(POST_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF,
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (res) {
            sendBtn.disabled = false;
            if (res.status === 429) { alert('Слишком часто. Попробуйте через минуту.'); return; }
            if (!res.body || !res.body.ok) { alert('Не удалось отправить. Попробуйте ещё раз.'); return; }
            textEl.value = '';
            autoGrow();
            var had = conversationId;
            conversationId = res.body.conversation_id || conversationId;
            appendMessage(res.body.message);
            if (nameEl) { nameEl.hidden = true; } // имя спрашиваем только до первого сообщения
            if (conversationId && conversationId !== had) subscribeLive();
        })
        .catch(function () { sendBtn.disabled = false; alert('Сеть недоступна. Попробуйте ещё раз.'); });
    }

    toggle.addEventListener('click', function () { isOpen ? closePanel() : openPanel(); });
    closeBtn.addEventListener('click', closePanel);
    form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });
    textEl.addEventListener('input', autoGrow);
    textEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
    });

    // Восстанавливаем состояние панели между страницами витрины.
    var wasOpen = false;
    try { wasOpen = localStorage.getItem(STORE_KEY) === '1'; } catch (e) {}
    if (wasOpen) openPanel();
})();
</script>
