{{--
    152-ФЗ: баннер cookie с выбором. «Только необходимые» — сессия и CSRF,
    без счётчиков; «Принять аналитику» — запускает Метрику и пиксель VK через
    partials/analytics-gate (window.ssConsent). Молчание и «продолжая
    пользоваться» согласием не считаются, поэтому до выбора ничего не грузится.
    Переоткрыть баннер: элемент с onclick="ssConsent.reopen()" (подвалы сайта).

    Чистый JS, без Alpine: подключается и на страницах без него (вход,
    регистрация, «спасибо»). @once — повторный include ничего не дублирует.
--}}
@include('partials.analytics-gate')
@once
<div id="ss-cookie-banner" hidden role="dialog" aria-label="Настройки cookie" data-cookie-consent
     style="position:fixed;left:0;right:0;bottom:0;z-index:10050;background:rgba(10,13,20,.96);color:#e2e8f0;border-top:1px solid #1F2636;padding:12px 16px;box-shadow:0 -4px 20px rgba(0,0,0,.3);font-size:14px;line-height:1.5;">
    <div style="max-width:1200px;margin:0 auto;display:flex;flex-wrap:wrap;align-items:center;gap:12px;">
        <p style="flex:1 1 420px;margin:0;">
            Мы используем технически необходимые cookie — без них не работают вход и оплата.
            С вашего согласия подключим Яндекс&nbsp;Метрику и счётчик VK, чтобы понимать, как улучшить сайт.
            Подробнее — в <a href="{{ route('docs.show', 'privacy') }}" style="color:#E85C24;text-decoration:underline;">политике конфиденциальности</a>.
        </p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" data-cookie-reject
                    style="padding:8px 16px;border-radius:8px;border:1px solid #475569;background:transparent;color:#e2e8f0;font-weight:600;cursor:pointer;">
                Только необходимые
            </button>
            <button type="button" data-cookie-accept
                    style="padding:8px 16px;border-radius:8px;border:0;background:#E85C24;color:#fff;font-weight:600;cursor:pointer;">
                Принять аналитику
            </button>
        </div>
    </div>
</div>
<script>
(function () {
    var banner = document.getElementById('ss-cookie-banner');
    if (!banner || !window.ssConsent) return;

    function setOpen(open) {
        banner.hidden = !open;
        // Пока баннер открыт, резервируем под него место — иначе на телефоне он
        // накрывает нижний контент (на чекауте — кнопку оплаты).
        document.body.style.paddingBottom = open ? banner.offsetHeight + 'px' : '';
    }

    function choose(allow) {
        var had = window.ssConsent.analytics();
        window.ssConsent.set(allow);
        setOpen(false);
        // Отозвали ранее данное согласие — перезагрузка выгружает уже запущенные счётчики.
        if (had && !allow) window.location.reload();
    }

    banner.querySelector('[data-cookie-reject]').addEventListener('click', function () { choose(false); });
    banner.querySelector('[data-cookie-accept]').addEventListener('click', function () { choose(true); });
    document.addEventListener('ss:consent-open', function () { setOpen(true); });

    if (!window.ssConsent.decided()) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { setOpen(true); });
        } else {
            setOpen(true);
        }
    }
})();
</script>
@endonce
