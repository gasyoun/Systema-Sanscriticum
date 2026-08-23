{{-- Кнопка «Оплатить через PayPal» на чекауте. Ведет на форму-заявку
     (PaypalClaimController). Показывается, только если фича включена
     (config services.paypal.enabled). Требует переменную $tariff.
     Копия и тайминги — H1292, docs/copy/money-diaspora-paypal-buyer-path.md. --}}
@if(config('services.paypal.enabled'))
    <div class="mb-6 bg-white border border-gray-200 rounded-2xl p-4 sm:p-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="text-sm text-gray-600 leading-relaxed">
                <span class="font-bold text-gray-900">Оплата из-за рубежа?</span>
                Оплатите через PayPal и сообщите нам. Уже учитесь у нас и войдете в кабинет —
                доступ откроется сразу; новым ученикам — после сверки платежа, обычно
                в течение одного рабочего дня.
            </div>
            <a href="{{ route('paypal.claim.show', $tariff) }}"
               class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-indigo-200 text-indigo-700 font-bold text-sm hover:bg-indigo-50 transition">
                <i class="fab fa-paypal"></i> Оплатить через PayPal
            </a>
        </div>
        <p class="mt-2 text-xs text-gray-500">
            PayPal не поддерживает автосписание на нашей платформе, поэтому каждый платеж
            сверяем вручную. Подтверждение заявки придет на email.
        </p>
    </div>
@endif
