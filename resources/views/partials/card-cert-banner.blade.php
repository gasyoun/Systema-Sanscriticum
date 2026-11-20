{{-- Плашка у кнопки оплаты: переход НСПК на сертификаты Минцифры (19-11-2026).
     Платёжная страница Точки отдаёт российскую цепочку, и Chrome/Safari/Edge
     без сертификатов Минцифры показывают ошибку сразу после ухода с нашего
     домена — помочь можно только ДО редиректа. Витрина /online и вкладка
     «Мои оплаты» кабинета. Текст — рулинг MG 19-11 (пост 709 в @samskrte).
     Тот же флаг, что у чекаут-подсказки (App\Support\CardCertHint): когда
     браузеры начнут доверять цепочке Минцифры — выключается одним тумблером. --}}
@if(\App\Support\CardCertHint::enabled())
    <div class="mb-6 flex items-start gap-2.5 rounded-2xl bg-amber-50 border border-amber-200/80 px-4 py-3 text-[13px] sm:text-sm leading-relaxed text-amber-900" data-testid="card-cert-banner">
        <i class="fas fa-credit-card text-amber-500 mt-0.5 shrink-0"></i>
        <span>
            <span class="font-bold">Карта не проходит или страница не открывается?</span>
            Откройте ссылку в <a href="https://browser.yandex.ru" target="_blank" rel="noopener" class="underline font-semibold">Яндекс Браузере</a> или поставьте сертификаты Минцифры (<a href="https://www.gosuslugi.ru/landing/crt" target="_blank" rel="noopener" class="underline font-semibold">gosuslugi.ru/landing/crt</a>); можно заплатить СБП-переводом через куратора.
        </span>
    </div>
@endif
