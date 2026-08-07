{{-- Страница возврата с оплаты — redirectUrl Точки (H1285).
     Только отображение: статус платежа здесь НЕ меняется — источник истины
     вебхук Точки (см. комментарий в PaymentController::fail() о том, почему
     GET-возврату с банка нельзя доверять). Три состояния: оплата подтверждена
     вебхуком / банк принял, ждем подтверждения / гость (сессия не пережила
     редирект через банк). --}}
@extends('layouts.shop')

@section('title', 'Оплата')

@push('head')
    {{-- Счетчики доступны, только если сессия пришла из промо-воронки
         (LeadFlashBuilder кладет yandex_id/vk_id в сессию). На прямом чекауте
         их нет — блок молчит. Паттерн — promo/thankyou.blade.php. --}}
    @if(session('yandex_id'))
        <script type="text/javascript">
           (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
           m[i].l=1*new Date();
           for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
           k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
           (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

           ym({{ session('yandex_id') }}, "init", {
                clickmap:true,
                trackLinks:true,
                accurateTrackBounce:true,
                webvisor:true
           });
        </script>
        <noscript><div><img src="https://mc.yandex.ru/watch/{{ session('yandex_id') }}" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
    @endif
    @if(session('vk_id'))
        <script type="text/javascript">
            var _tmr = window._tmr || (window._tmr = []);
            _tmr.push({id: "{{ session('vk_id') }}", type: "pageView", start: (new Date()).getTime()});
            (function (d, w, id) {
                if (d.getElementById(id)) return;
                var ts = d.createElement("script"); ts.type = "text/javascript"; ts.async = true; ts.id = id;
                ts.src = "https://top-fwz1.mail.ru/js/code.js";
                var f = function () {var s = d.getElementsByTagName("script")[0]; s.parentNode.insertBefore(ts, s);};
                if (w.opera == "[object Opera]") { d.addEventListener("DOMContentLoaded", f, false); } else { f(); }
            })(document, window, "tmr-code");
        </script>
        <noscript><div><img src="https://top-fwz1.mail.ru/counter?id={{ session('vk_id') }};js=na" style="position:absolute;left:-9999px;" alt="Top.Mail.Ru" /></div></noscript>
    @endif
@endpush

@section('content')
<div class="container mx-auto px-4 py-14 md:py-20">
    <div class="max-w-xl mx-auto">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8 md:p-10 text-gray-900 text-center">

            @auth
                @if($confirmed)
                    {{-- Состояние 1: вебхук уже подтвердил оплату --}}
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-check text-2xl text-green-600"></i>
                    </div>

                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mb-4">Оплата получена</h1>

                    @if($payment)
                        <div class="flex items-center justify-between gap-4 bg-gray-50 border border-gray-100 rounded-2xl px-5 py-4 mb-6 text-left">
                            <span class="font-bold text-gray-900">{{ $payment->course->title ?? 'Курс' }}</span>
                            <span class="shrink-0 tabular-nums font-extrabold text-gray-900">{{ number_format($payment->amount, 0, '.', ' ') }} ₽</span>
                        </div>
                    @endif

                    <p class="text-gray-600 leading-relaxed mb-2">
                        Доступ открыт — материалы уже ждут вас в личном кабинете.
                    </p>
                    <p class="text-sm text-gray-400 mb-8">
                        Кассовый чек придет на почту, указанную при оплате.
                    </p>

                    <a href="{{ route('student.dashboard') }}"
                       class="inline-flex justify-center items-center py-4 px-8 rounded-2xl shadow-lg shadow-orange-200/70 text-lg font-extrabold text-white bg-gradient-to-r from-[#E85C24] to-[#d64e1c] hover:shadow-xl hover:shadow-orange-300/60 hover:-translate-y-0.5 transition-all duration-200">
                        Перейти к обучению
                    </a>

                    {{-- Рекомендация школы (H1294): просьба в момент максимума
                         доверия — сразу после подтвержденной оплаты. Строки:
                         docs/copy/money-referral-invite-ask.md. --}}
                    <div class="mt-10 pt-6 border-t border-gray-100 text-left">
                        <p class="text-sm font-bold text-gray-700 mb-1">Порекомендовать школу</p>
                        <p class="text-sm text-gray-500 leading-relaxed">
                            Если среди ваших знакомых есть человек, которому санскрит был бы в
                            радость, — поделитесь личной ссылкой из
                            <a href="{{ route('student.dashboard') }}#referral" class="font-semibold text-[#E85C24] hover:underline">кабинета</a>.
                            Когда приглашенный впервые оплатит курс, мы зачислим вам
                            {{ number_format((int) config('referral.credit_amount', 500), 0, '.', ' ') }} ₽
                            в знак благодарности — сумма зачтется при вашей следующей покупке автоматически.
                        </p>
                    </div>
                @else
                    {{-- Состояние 2: банк принял платеж, вебхук еще не подтвердил --}}
                    <div class="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-clock text-2xl text-amber-600"></i>
                    </div>

                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mb-4">Платеж принят</h1>

                    @if($payment)
                        <div class="flex items-center justify-between gap-4 bg-gray-50 border border-gray-100 rounded-2xl px-5 py-4 mb-6 text-left">
                            <span class="font-bold text-gray-900">{{ $payment->course->title ?? 'Курс' }}</span>
                            <span class="shrink-0 tabular-nums font-extrabold text-gray-900">{{ number_format($payment->amount, 0, '.', ' ') }} ₽</span>
                        </div>
                    @endif

                    <p class="text-gray-600 leading-relaxed mb-2">
                        Банк подтверждает оплату — обычно это занимает пару минут.
                        Доступ откроется автоматически.
                    </p>
                    <p class="text-gray-600 leading-relaxed mb-2">
                        Если через 10 минут доступа всё еще нет — напишите нам в
                        <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener noreferrer" class="font-semibold text-[#E85C24] hover:underline">Telegram</a>,
                        мы разберемся.
                    </p>
                    <p class="text-sm text-gray-400 mb-8">
                        Кассовый чек придет на почту, указанную при оплате.
                    </p>

                    <a href="{{ route('student.dashboard') }}"
                       class="inline-flex justify-center items-center py-4 px-8 rounded-2xl shadow-lg shadow-orange-200/70 text-lg font-extrabold text-white bg-gradient-to-r from-[#E85C24] to-[#d64e1c] hover:shadow-xl hover:shadow-orange-300/60 hover:-translate-y-0.5 transition-all duration-200">
                        Перейти в личный кабинет
                    </a>
                @endif
            @else
                {{-- Состояние 3: гость — сессия не пережила редирект через банк --}}
                <div class="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-clock text-2xl text-amber-600"></i>
                </div>

                <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mb-4">Платеж принят</h1>

                <p class="text-gray-600 leading-relaxed mb-2">
                    Войдите в аккаунт — доступ откроется в течение пары минут.
                </p>
                <p class="text-gray-600 leading-relaxed mb-8">
                    Если через 10 минут доступа всё еще нет — напишите нам в
                    <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener noreferrer" class="font-semibold text-[#E85C24] hover:underline">Telegram</a>,
                    мы разберемся.
                </p>

                <a href="{{ route('login') }}"
                   class="inline-flex justify-center items-center py-4 px-8 rounded-2xl shadow-lg shadow-orange-200/70 text-lg font-extrabold text-white bg-gradient-to-r from-[#E85C24] to-[#d64e1c] hover:shadow-xl hover:shadow-orange-300/60 hover:-translate-y-0.5 transition-all duration-200">
                    Войти в аккаунт
                </a>
            @endauth

            <div class="mt-8">
                <a href="{{ url('/') }}" class="text-sm text-gray-400 hover:text-gray-600 transition-colors">
                    На главную
                </a>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    {{-- H2378: payment_success — session landing counter OR shop-wide counter
         (layouts/shop now includes partials/shop-metrika). --}}
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        @if(session('yandex_id'))
            if (typeof ym !== 'undefined') {
                ym({{ session('yandex_id') }}, 'reachGoal', 'payment_success');
            }
        @elseif(config('analytics.metrika.enabled') && config('analytics.metrika.shop_counter_id'))
            if (typeof window.shopReachGoal === 'function') {
                window.shopReachGoal('payment_success');
            } else if (typeof ym !== 'undefined') {
                ym({{ config('analytics.metrika.shop_counter_id') }}, 'reachGoal', 'payment_success');
            }
        @endif
        @if(session('vk_id'))
            var _tmr = window._tmr || (window._tmr = []);
            _tmr.push({ type: 'reachGoal', id: "{{ session('vk_id') }}", goal: 'payment_success' });
        @endif
    });
    </script>
@endpush
