{{--
    152-ФЗ: шлюз аналитики. Яндекс Метрика, пиксель VK/Top.Mail.Ru и антибот
    BotFaqtor запускаются ТОЛЬКО после согласия посетителя «Принять аналитику»
    в баннере partials/cookie-consent. Каждый блок счётчика оборачивается в
        ssConsent.onAnalytics(function () { ... });
    — выполнится сразу, если согласие уже дано, или в момент нажатия кнопки.

    Вебвизор: перед запуском счётчиков всем полям форм ставится класс
    ym-disable-keys — Метрика не записывает ввод (ФИО, почта, телефон).

    Подключать в <head> ДО любого счётчика; @once — повторные include безопасны.
--}}
@once
<script>
window.ssConsent = window.ssConsent || (function () {
    var KEY = 'cookie_consent_v2';
    var queue = [];
    var state = null;
    try { state = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { state = null; }

    function analytics() { return !!(state && state.analytics); }

    function maskFields(root) {
        try {
            (root || document).querySelectorAll('input, textarea, select').forEach(function (el) {
                el.classList.add('ym-disable-keys');
            });
        } catch (e) {}
    }

    function startMasking() {
        maskFields(document);
        try {
            new MutationObserver(function (records) {
                records.forEach(function (r) {
                    r.addedNodes.forEach(function (n) { if (n.nodeType === 1) { maskFields(n); if (n.matches && n.matches('input, textarea, select')) n.classList.add('ym-disable-keys'); } });
                });
            }).observe(document.documentElement, { childList: true, subtree: true });
        } catch (e) {}
    }

    function run(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { maskFields(document); });
        }
        try { fn(); } catch (e) {}
    }

    function flush() {
        startMasking();
        var q = queue; queue = [];
        q.forEach(run);
    }

    if (analytics()) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startMasking); else startMasking();
    }

    return {
        KEY: KEY,
        decided: function () { return state !== null; },
        analytics: analytics,
        onAnalytics: function (fn) { if (analytics()) run(fn); else queue.push(fn); },
        set: function (allowAnalytics) {
            var had = analytics();
            state = { analytics: !!allowAnalytics, v: 2, at: new Date().toISOString() };
            try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {}
            if (state.analytics && !had) flush();
            try { document.dispatchEvent(new CustomEvent('ss:consent', { detail: state, bubbles: true })); } catch (e) {}
        },
        reopen: function () {
            try { document.dispatchEvent(new CustomEvent('ss:consent-open', { bubbles: true })); } catch (e) {}
        }
    };
})();
</script>
@endonce
