@extends('layouts.shop')
@section('title', 'Уведомление об оплате через PayPal')

@section('content')
<div class="min-h-screen bg-gradient-to-b from-gray-50 to-white py-10 md:py-16 font-sans antialiased">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="mb-6">
            <a href="{{ route('checkout.show', $tariff) }}" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 transition mb-4">
                <i class="fas fa-arrow-left mr-2 text-xs"></i> Назад к оформлению
            </a>
            <div class="flex items-start justify-between gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-extrabold text-gray-950 tracking-tight">Уведомление об оплате через PayPal</h1>
                    <p class="mt-2 text-base text-gray-500">
                        Этот путь — для оплаты из-за рубежа, где карта РФ не работает. PayPal не
                        поддерживает автосписание на нашей платформе, поэтому оплата идет в два шага:
                        вы переводите оплату и подаете уведомление здесь. Своим ученикам доступ
                        открывается сразу после отправки уведомления; новым — после ручной сверки,
                        обычно в течение одного рабочего дня.
                    </p>
                </div>
                <figure class="shrink-0 mt-1">
                    <img src="{{ asset('images/paypal-qr.png') }}" alt="QR-код для оплаты через PayPal"
                         class="w-24 sm:w-28 rounded-2xl border border-gray-200 bg-white p-1.5 shadow-sm">
                    <figcaption class="mt-2 max-w-[7rem] text-xs text-gray-500 text-center leading-snug">
                        Или отсканируйте QR в приложении PayPal
                    </figcaption>
                </figure>
            </div>
        </div>

{{-- Шаг 1: куда платить --}}
        <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100 mb-6">
            <div class="flex items-center gap-3 mb-4">
                <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-sm font-extrabold">1</span>
                <h4 class="text-base font-extrabold text-gray-900">Оплатите через PayPal</h4>
            </div>
            @if($foreignPrice)
            <p class="text-sm text-gray-600 leading-relaxed">
                Тариф: <span class="font-bold text-gray-900">{{ $course?->title ?? 'Курс' }} — {{ $tariff->title ?? $tariff->accessKey() }}</span>.
                Стоимость блока — <span class="font-bold text-gray-900">{{ $foreignPrice['eur'] }} €</span> (предпочтительно)
                или <span class="font-bold text-gray-900">{{ $foreignPrice['usd'] }} $</span>.
            </p>
            @else
            <p class="text-sm text-gray-600 leading-relaxed">
                Тариф: <span class="font-bold text-gray-900">{{ $course?->title ?? 'Курс' }} — {{ $tariff->title ?? $tariff->accessKey() }}</span>.
            </p>
            @endif
            {{-- MG 23-08-2026: комиссию за перевод платит отправитель — иначе сумма
                 приходит неполной и ручная сверка расходится с заявкой. --}}
            <p class="mt-2 text-sm font-semibold text-amber-800">
                Комиссию PayPal за перевод оплачивает отправитель. Если комиссия осталась
                на получателе — сделаем пересчет и запросим доплату.
            </p>
            @if($meLink)
                <a href="{{ $meLink }}" target="_blank" rel="noopener"
                   class="mt-4 inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-[#0070BA] hover:bg-[#005ea6] text-white font-bold text-sm transition">
                    <i class="fab fa-paypal"></i> Перейти к оплате на PayPal
                </a>
            @endif
            <p class="mt-3 text-sm text-gray-600">
                Прямая ссылка для перевода:
                <a href="https://paypal.me/gasuns" target="_blank" rel="noopener"
                   class="font-semibold text-[#0070BA] hover:text-[#005ea6] underline decoration-[#0070BA]/30 hover:decoration-[#0070BA]/60">paypal.me/gasuns</a>
            </p>
            @if($recipient)
                <p class="mt-3 text-xs text-gray-500">Получатель PayPal: <span class="font-semibold text-gray-700">{{ $recipient }}</span></p>
            @endif
        </div>

        {{-- Шаг 2: сообщить об оплате --}}
        <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100 mb-6">
            <div class="flex items-center gap-3 mb-5">
                <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-sm font-extrabold">2</span>
                <h4 class="text-base font-extrabold text-gray-900">Сообщите нам об оплате</h4>
            </div>

            <form id="paypal-claim-form" action="{{ route('paypal.claim.store', $tariff) }}" method="POST" enctype="multipart/form-data" class="space-y-5">
                @csrf

                @guest
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Имя <span class="text-red-500">*</span></label>
                            <input type="text" name="name" required maxlength="255" value="{{ old('name') }}"
                                   placeholder="Как к вам обращаться"
                                   class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" required maxlength="255" value="{{ old('email') }}"
                                   placeholder="you@example.com"
                                   class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">После сверки мы пришлем пароль на email — войдете в личный кабинет.</p>
                @endguest

                {{-- H2215: paste full PayPal activity / receipt dump → auto-fill reconciliation fields. --}}
                <div id="paypal-claim-paste" class="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4 sm:p-5 space-y-3">
                    <div>
                        <label for="paypal-paste-input" class="block text-sm font-medium text-gray-800 mb-1">
                            Вставить детали из PayPal
                        </label>
                        <p class="text-xs text-gray-600 leading-relaxed">
                            Скопируйте целиком блок с деталями платежа со страницы Activity в PayPal
                            (или из письма-чека) и вставьте ниже — мы заполним поля сверки.
                            Проверьте результат: форма не отправляется сама.
                        </p>
                    </div>
                    <textarea id="paypal-paste-input" rows="4" maxlength="8000"
                              placeholder="Вставьте сюда текст из PayPal (Activity или письмо-чек)"
                              class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 text-sm transition"
                              autocomplete="off" spellcheck="false"></textarea>
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                        <button type="button" id="paypal-paste-parse"
                                class="inline-flex justify-center items-center px-4 py-2.5 rounded-xl bg-white border border-indigo-200 text-indigo-800 text-sm font-semibold hover:bg-indigo-50 transition">
                            Заполнить из вставки
                        </button>
                        <p id="paypal-paste-status" class="text-xs text-gray-500 leading-relaxed" aria-live="polite" data-kind="idle"></p>
                    </div>
                </div>

                {{-- H2017: personal PayPal has no API — admin reconciles by from + date + amount. --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">С какого PayPal платили <span class="text-red-500">*</span></label>
                    <input type="text" name="paypal_payer" required maxlength="255" value="{{ old('paypal_payer') }}"
                           placeholder="ваш PayPal-адрес (email)"
                           class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                    <p class="mt-1 text-xs text-gray-500">Email вашего PayPal-аккаунта — так мы найдем перевод в личном PayPal (не business-аккаунт).</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Дата оплаты <span class="text-red-500">*</span></label>
                        <input type="date" name="paid_on" required max="{{ now()->toDateString() }}" value="{{ old('paid_on', now()->toDateString()) }}"
                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Сколько заплатили <span class="text-red-500">*</span></label>
                        <input type="number" name="foreign_amount" required min="1" step="0.01" value="{{ old('foreign_amount') }}"
                               placeholder="Напр. 50"
                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Валюта <span class="text-red-500">*</span></label>
                    <select name="foreign_currency" required
                            class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                        {{-- MG 23-08-2026: евро по умолчанию — предпочитаемая валюта,
                             чтобы не терять на конвертации долларов в евро. --}}
                        <option value="EUR" @selected(old('foreign_currency', 'EUR') === 'EUR')>Евро (EUR, €)</option>
                        <option value="USD" @selected(old('foreign_currency', 'EUR') === 'USD')>Доллары (USD, $)</option>
                    </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Номер транзакции PayPal <span class="text-gray-400 font-normal">(необязательно)</span></label>
                    <input type="text" name="paypal_txn" maxlength="255" value="{{ old('paypal_txn') }}"
                           placeholder="Напр. 1AB23456CD789012E"
                           class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Скриншот / чек <span class="text-gray-400 font-normal">(необязательно)</span></label>
                    <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf"
                           class="block w-full text-sm text-gray-600 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition">
                    <p class="mt-1 text-xs text-gray-500">JPG, PNG или PDF, до 5 МБ. Ускоряет сверку, но достаточно тройки: аккаунт + дата + сумма.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Комментарий</label>
                    <textarea name="comment" rows="3" maxlength="1000"
                              placeholder="Что-то важное для нас (необязательно)"
                              class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">{{ old('comment') }}</textarea>
                </div>

                <button type="submit"
                        class="w-full flex justify-center items-center py-3.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white text-base font-bold rounded-xl transition-all shadow-lg shadow-indigo-200">
                    <i class="fas fa-paper-plane mr-2"></i> Отправить уведомление об оплате
                </button>
            </form>
        </div>

        {{-- Шаг 3: что будет дальше — H1292, ожидания вместо тишины --}}
        <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100">
            <div class="flex items-center gap-3 mb-4">
                <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-sm font-extrabold">3</span>
                <h4 class="text-base font-extrabold text-gray-900">Что будет дальше</h4>
            </div>
            <ol class="space-y-3 text-sm text-gray-600 leading-relaxed list-none">
                @auth
                {{-- Ruling 22-08-2026: своему ученику доступ открывается сразу,
                     сверка делается после и выборочно. --}}
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">1.</span>
                    <span>Сразу после отправки пришлем на email подтверждение с деталями заявки.</span>
                </li>
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">2.</span>
                    <span>Вы наш студент — доступ к курсу откроется сразу после отправки
                    заявки Вами. Уроки и материалы ждут в личном кабинете; сверку платежа
                    мы сделаем после и выборочно.</span>
                </li>
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">3.</span>
                    <span>Если деньги списались повторно или что-то не так —
                    <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener" class="font-semibold text-indigo-700 hover:text-indigo-900">напишите нам в Telegram</a>,
                    обычно отвечаем в течение рабочего дня. Не платите повторно — проверим
                    платеж и вернем деньги.</span>
                </li>
                @else
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">1.</span>
                    <span>Сразу после отправки пришлем на email подтверждение, что заявка получена.</span>
                </li>
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">2.</span>
                    <span>Обычно в течение одного рабочего дня сверим платеж в PayPal и откроем доступ.
                    Для нового аккаунта на email придет пароль от личного кабинета. Уже учитесь у нас?
                    Войдите в кабинет перед отправкой — тогда доступ откроется сразу.</span>
                </li>
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">3.</span>
                    <span>Если деньги списались дважды — не платите повторно: напишите нам,
                    проверим платеж и вернем деньги. Если рабочий день прошел, а доступа
                    нет —
                    <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener" class="font-semibold text-indigo-700 hover:text-indigo-900">напишите нам в Telegram</a>,
                    обычно отвечаем в течение рабочего дня.</span>
                </li>
                @endauth
            </ol>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/paypal-claim-paste.js') }}?v={{ filemtime(public_path('js/paypal-claim-paste.js')) }}" defer></script>
@endpush
