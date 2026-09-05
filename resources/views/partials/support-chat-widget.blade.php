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
      • presence   → POST {{ route('support.presence') }} с первого захода, если
                     включён флаг support_visitor_presence (H1197, Jivo-паритет
                     Pillar 2). Куратор видит посетителя в «Посетители онлайн» и
                     может написать первым; ответ beacon'а несёт conversation_id —
                     проактив куратора долетает до молчащего посетителя, и виджет
                     раскрывается. При выключенном флаге beacon не шлётся.

    Безопасность: сервер отдаёт уже экранированный `html` (ChatMessage::htmlForWeb,
    whitelist без атрибутов) — рендерим его, но НИКОГДА не сырой ввод посетителя.

    Контекстное приветствие по странице входа (H1198, Jivo-паритет S3): за флагом
    support_answer_suggester (тем же, что у FAQ-суггестера H247 — единый рубильник
    "AI-assist на веб-чате"), клиентский JS подменяет статичное приветствие на
    контекстное по URL-паттерну (курс vs оплата) БЕЗ обращения к серверу — паттерн
    известен сразу, `entry_url` (S1/H1196) фиксируется только сервером и только
    после первого сообщения, для приветствия ДО сообщения он не годится.

    Захват лида + оффлайн-форма (H1199, Jivo-паритет S4): за флагом
    features.support_lead_capture. Телефон/почта — ВСЕГДА необязательны (роадмап
    §4 S4), отправка не блокируется ни онлайн, ни офлайн. Вне деловых часов
    (config/support_hours.php, App\Support\SupportAvailability) меняется только
    копирайт — призываем оставить почту, чтобы ответили не вживую.
--}}
@php
    $scwLeadCaptureEnabled = (bool) config('features.support_lead_capture');
    $scwIsOnline = \App\Support\SupportAvailability::isOnline();
@endphp
<div
    id="scw-root"
    class="scw"
    data-post-url="{{ route('chat.message') }}"
    data-history-url="{{ route('chat.history') }}"
    data-csrf="{{ csrf_token() }}"
    data-authed="{{ auth()->check() ? '1' : '0' }}"
    data-presence="{{ config('features.support_visitor_presence') ? '1' : '0' }}"
    data-presence-url="{{ route('support.presence') }}"
    data-presence-interval="{{ (int) config('support_presence.beacon_interval_seconds', 20) }}"
    data-context-greeting="{{ app(\App\Services\Support\SupportAnswerSuggester::class)->isEnabled() ? '1' : '0' }}"
    data-lead-capture="{{ $scwLeadCaptureEnabled ? '1' : '0' }}"
    data-offline="{{ ($scwLeadCaptureEnabled && ! $scwIsOnline) ? '1' : '0' }}"
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
                @if($scwLeadCaptureEnabled && ! $scwIsOnline)
                    Операторы сейчас офлайн. Оставьте, пожалуйста, e-mail — мы напишем вам, как
                    только сможем ответить. Вопрос можно оставить прямо здесь.
                @else
                    Здравствуйте! Напишите нам — ответим по курсам, оплате и занятиям.
                @endif
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
            @if($scwLeadCaptureEnabled && ! auth()->check())
                <div class="scw-contact-row" id="scw-contact-row">
                    <input
                        type="email"
                        class="scw-email"
                        id="scw-email"
                        name="email"
                        maxlength="255"
                        placeholder="{{ $scwIsOnline ? 'Почта (необязательно)' : 'Почта — напишем вам ответ' }}"
                    >
                    <input
                        type="tel"
                        class="scw-phone"
                        id="scw-phone"
                        name="phone"
                        maxlength="40"
                        placeholder="Телефон (необязательно)"
                    >
                </div>
            @endif
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
    .scw { position: fixed; right: calc(20px + env(safe-area-inset-right, 0px)); bottom: calc(20px + env(safe-area-inset-bottom, 0px)); z-index: 9998; font-family: inherit; }
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
        /* H4118: visualViewport-aware высота — --scw-vvh обновляется при клавиатуре/зуме (скрипт ниже) */
        height: 520px; max-height: calc(var(--scw-vvh, 100vh) - 120px); display: flex; flex-direction: column;
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
    .scw-contact-row { display: flex; gap: 8px; margin-bottom: 8px; }
    .scw-email, .scw-phone {
        flex: 1; min-width: 0; padding: 8px 12px; border-radius: 10px; border: 1px solid #1f2937;
        background: #111827; color: #e5e7eb; font-size: 13px;
    }
    .scw-input-row { display: flex; align-items: flex-end; gap: 8px; }
    .scw-text {
        flex: 1; resize: none; max-height: 120px; padding: 10px 12px; border-radius: 10px;
        border: 1px solid #1f2937; background: #111827; color: #e5e7eb; font-size: 14px; font-family: inherit; line-height: 1.4;
    }
    .scw-name:focus, .scw-text:focus, .scw-email:focus, .scw-phone:focus { outline: none; border-color: #E85C24; }
    /* H4118: пол 16px — iOS зумит страницу при фокусе в поле с computed < 16px */
    .scw :is(input, textarea) { font-size: max(1rem, 1em); }
    @media (max-width: 340px) { .scw-contact-row { flex-direction: column; } }
    .scw-send {
        flex-shrink: 0; width: 42px; height: 42px; border-radius: 10px; border: none; cursor: pointer;
        color: #fff; background: #E85C24; display: flex; align-items: center; justify-content: center; transition: background .15s;
    }
    .scw-send:hover { background: #c9491a; }
    .scw-send:disabled { opacity: .5; cursor: default; }
    @media (max-width: 480px) {
        .scw { right: calc(14px + env(safe-area-inset-right, 0px)); bottom: calc(14px + env(safe-area-inset-bottom, 0px)); }
        .scw-panel { width: calc(100vw - 28px); height: calc(var(--scw-vvh, 100vh) * 0.7); bottom: 70px; }
    }
</style>

<script>
(function () {
    var root = document.getElementById('scw-root');
    if (!root) return;

    // H4118: высота видимой области без клавиатуры/зума — питает .scw-panel (max-height/height).
    // Задаём на <html>, чтобы переменная жила даже до инициализации остального скрипта.
    var vv = window.visualViewport;
    var syncVvh = function () {
        if (vv) { document.documentElement.style.setProperty('--scw-vvh', Math.round(vv.height) + 'px'); }
    };
    syncVvh();
    if (vv) { vv.addEventListener('resize', syncVvh); }

    var POST_URL = root.dataset.postUrl;
    var HISTORY_URL = root.dataset.historyUrl;
    var CSRF = root.dataset.csrf;
    var CONTEXT_GREETING = root.dataset.contextGreeting === '1';
    var OFFLINE = root.dataset.offline === '1';
    var STORE_KEY = 'scw_open';

    var toggle = document.getElementById('scw-toggle');
    var panel = document.getElementById('scw-panel');
    var closeBtn = document.getElementById('scw-close');
    var list = document.getElementById('scw-messages');
    var intro = document.getElementById('scw-intro');
    var form = document.getElementById('scw-form');
    var textEl = document.getElementById('scw-text');
    var nameEl = document.getElementById('scw-name');
    var emailEl = document.getElementById('scw-email');
    var phoneEl = document.getElementById('scw-phone');
    var contactRow = document.getElementById('scw-contact-row');
    var sendBtn = document.getElementById('scw-send');
    var badge = document.getElementById('scw-badge');

    var conversationId = null;
    var seen = {};              // id -> true, дедуп сообщений
    var loaded = false;         // история подтянута
    var subscribed = false;     // Echo-подписка активна
    var pollTimer = null;       // фолбэк-опрос без Echo
    var isOpen = false;

    // Presence (H1197, Jivo-паритет Pillar 2): beacon-«я на сайте», чтобы куратор
    // видел посетителя и мог написать первым. Работает только за флагом сервера.
    var PRESENCE = root.dataset.presence === '1';
    var PRESENCE_URL = root.dataset.presenceUrl;
    var PRESENCE_INTERVAL = (parseInt(root.dataset.presenceInterval, 10) || 20) * 1000;
    var beaconTimer = null;
    var beaconStarted = false;  // первый beacon уже прошел?

    // Контекстное приветствие по странице входа (H1198): URL известен сразу,
    // без обращения к серверу — за флагом support_answer_suggester. Дефолтный
    // текст (уже в разметке) остаётся, если флаг выключен или страница не
    // распознана ни одним паттерном. Не подменяет оффлайн-копирайт (H1199) —
    // «оставьте почту» важнее контекстного приветствия, когда операторы офлайн.
    function applyContextualGreeting() {
        if (!CONTEXT_GREETING || !intro || OFFLINE) return;
        var path = (location.pathname || '').toLowerCase();
        var greeting = null;
        if (path.indexOf('/online/kursy/') === 0 || path.indexOf('/course/') === 0
            || path.indexOf('/k/') === 0 || path.indexOf('/c/') === 0) {
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

    // Presence-beacon: сообщаем серверу «посетитель на этой странице». Ответ несёт
    // conversation_id — если куратор написал первым молчащему посетителю, тред уже
    // создан: подтягиваем его и раскрываем виджет (Jivo «оператор пишет первым»).
    function beacon() {
        if (!PRESENCE || !PRESENCE_URL) return;
        var payload = {};
        try { if (location && location.href) payload.page = String(location.href).slice(0, 2048); } catch (e) {}
        fetch(PRESENCE_URL, {
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
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || data.enabled === false) { stopBeacon(); return; }   // флаг выключен — не шумим
            var firstBeacon = !beaconStarted;
            beaconStarted = true;
            // Тред появился, а у виджета его ещё нет → куратор открыл диалог.
            if (data.conversation_id && !conversationId) {
                var proactive = !firstBeacon; // появился ПОСЛЕ загрузки = проактив куратора
                loadHistory().then(function () {
                    // firstBeacon с уже существующим тредом = возврат: тихо подписаны,
                    // без авто-раскрытия; проактив = раскрываем панель с сообщением.
                    if (conversationId && proactive && !isOpen) openPanel();
                });
            }
        })
        .catch(function () { /* сеть недоступна — не шумим */ });
    }

    function startBeacon() {
        if (!PRESENCE || !PRESENCE_URL || beaconTimer) return;
        beacon(); // сразу, затем по интервалу
        beaconTimer = setInterval(beacon, PRESENCE_INTERVAL);
    }
    function stopBeacon() {
        if (beaconTimer) { clearInterval(beaconTimer); beaconTimer = null; }
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
        // Необязательные телефон/почта — захват лида (H1199, S4). Не блокируем
        // отправку, если пусто.
        if (emailEl && !emailEl.hidden && emailEl.value.trim() !== '') payload.email = emailEl.value.trim();
        if (phoneEl && !phoneEl.hidden && phoneEl.value.trim() !== '') payload.phone = phoneEl.value.trim();
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
            if (!res.body || !res.body.ok) { alert('Не удалось отправить. Попробуйте еще раз.'); return; }
            textEl.value = '';
            autoGrow();
            var had = conversationId;
            conversationId = res.body.conversation_id || conversationId;
            appendMessage(res.body.message);
            if (nameEl) { nameEl.hidden = true; } // имя спрашиваем только до первого сообщения
            if (contactRow) { contactRow.hidden = true; } // телефон/почта — тоже только до первого (H1199)
            if (conversationId && conversationId !== had) subscribeLive();
        })
        .catch(function () { sendBtn.disabled = false; alert('Сеть недоступна. Попробуйте еще раз.'); });
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

    // Presence-beacon стартует с первого захода (не только при открытой панели) —
    // чтобы проактив куратора долетел до молчащего посетителя (H1197, Pillar 2).
    startBeacon();
})();
</script>
