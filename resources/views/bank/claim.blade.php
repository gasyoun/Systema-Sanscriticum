@extends('layouts.shop')

@section('title', 'Уведомление об оплате банковским переводом')

@section('content')
<div class="min-h-screen bg-gradient-to-b from-gray-50 to-white py-10 md:py-16 font-sans antialiased">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="mb-6">
            <a href="{{ route('checkout.show', $tariff) }}" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 transition mb-4">
                <i class="fas fa-arrow-left mr-2 text-xs"></i> Назад к оформлению
            </a>
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-950 tracking-tight">Уведомление об оплате банковским переводом</h1>
            <p class="mt-2 text-base text-gray-500">
                Этот путь — для оплаты банковским переводом (SEPA/SWIFT) на счет
                получателя школы за рубежом, где карта РФ не работает. Автосверки
                нет: вы переводите оплату и подаете уведомление здесь. Своим
                ученикам доступ открывается сразу после отправки уведомления;
                новым — после ручной сверки, обычно в течение одного рабочего дня.
            </p>
        </div>

        {{-- Шаг 1: куда платить --}}
        <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100 mb-6">
            <div class="flex items-center gap-3 mb-4">
                <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-sm font-extrabold">1</span>
                <h4 class="text-base font-extrabold text-gray-900">Переведите оплату по реквизитам</h4>
            </div>
            <p class="text-sm text-gray-600 leading-relaxed">
                Тариф: <span class="font-bold text-gray-900">{{ $course?->title ?? 'Курс' }} — {{ $tariff->title ?? $tariff->accessKey() }}</span>.
            </p>

            @if($iban)
            <dl class="mt-4 space-y-2 rounded-2xl bg-indigo-50/60 border border-indigo-100 p-4 text-sm">
                @if($recipientName)
                <div class="flex flex-wrap gap-x-2"><dt class="text-gray-500 w-32 shrink-0">Получатель</dt><dd class="font-semibold text-gray-900">{{ $recipientName }}</dd></div>
                @endif
                <div class="flex flex-wrap gap-x-2"><dt class="text-gray-500 w-32 shrink-0">IBAN</dt><dd class="font-mono font-semibold text-gray-900 break-all">{{ $iban }}</dd></div>
                @if($bic)
                <div class="flex flex-wrap gap-x-2"><dt class="text-gray-500 w-32 shrink-0">BIC</dt><dd class="font-mono font-semibold text-gray-900">{{ $bic }}</dd></div>
                @endif
                @if($bankName)
                <div class="flex flex-wrap gap-x-2"><dt class="text-gray-500 w-32 shrink-0">Банк</dt><dd class="font-semibold text-gray-900">{{ $bankName }}</dd></div>
                @endif
            </dl>
            <p class="mt-3 text-sm text-gray-600">
                В назначении платежа укажите, пожалуйста, курс и ваше имя — так мы
                быстрее найдем поступление. Комиссию банка за перевод оплачивает
                отправитель.
            </p>
            @endif
        </div>

        {{-- Шаг 2: сообщить об оплате --}}
        <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100 mb-6">
            <div class="flex items-center gap-3 mb-5">
                <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-sm font-extrabold">2</span>
                <h4 class="text-base font-extrabold text-gray-900">Сообщите нам об оплате</h4>
            </div>

            <form id="bank-claim-form" action="{{ route('bank.claim.store', $tariff) }}" method="POST" enctype="multipart/form-data" class="space-y-5">
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

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Сумма перевода <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <input type="number" name="foreign_amount" required min="1" step="0.01" max="1000000" value="{{ old('foreign_amount') }}"
                                   placeholder="70"
                                   class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                            <select name="foreign_currency" required
                                    class="block rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-3 transition">
                                @foreach(['EUR', 'USD', 'GBP'] as $cur)
                                <option value="{{ $cur }}" @checked(old('foreign_currency', 'EUR') === $cur)>{{ $cur }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Дата оплаты <span class="text-red-500">*</span></label>
                        <input type="date" name="paid_on" required max="{{ now()->toDateString() }}" value="{{ old('paid_on', now()->toDateString()) }}"
                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Отправитель перевода <span class="text-red-500">*</span></label>
                    <input type="text" name="sender_name" required maxlength="255" value="{{ old('sender_name') }}"
                           placeholder="имя и/или номер счета отправителя"
                           class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Референция перевода</label>
                    <input type="text" name="reference" maxlength="100" value="{{ old('reference') }}"
                           placeholder="если есть (из выписки или подтверждения банка)"
                           class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Подтверждение перевода</label>
                    <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf"
                           class="block w-full text-sm text-gray-600 file:mr-3 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition">
                    <p class="mt-1 text-xs text-gray-500">Скриншот или PDF из вашего банка-отправителя — ускорит сверку.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Комментарий</label>
                    <textarea name="comment" rows="3" maxlength="1000" placeholder="курс, имя ученика, что-то еще важное"
                              class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">{{ old('comment') }}</textarea>
                </div>

                <button type="submit"
                        class="w-full inline-flex justify-center items-center px-6 py-3.5 rounded-xl bg-[#0070BA] hover:bg-[#005ea6] text-white font-bold text-sm transition">
                    Отправить уведомление
                </button>
            </form>
        </div>

        {{-- Шаг 3: что будет дальше — ожидания вместо тишины (зеркало H1292) --}}
        <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100">
            <div class="flex items-center gap-3 mb-4">
                <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-sm font-extrabold">3</span>
                <h4 class="text-base font-extrabold text-gray-900">Что будет дальше</h4>
            </div>
            <ol class="space-y-3 text-sm text-gray-600 leading-relaxed list-none">
                @auth
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">1.</span>
                    <span>Сразу после отправки пришлем на email подтверждение с деталями заявки.</span>
                </li>
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">2.</span>
                    <span>Вы наш ученик — доступ к курсу откроется сразу после отправки
                    заявки. Уроки и материалы ждут в личном кабинете; сверку перевода
                    мы сделаем после и выборочно.</span>
                </li>
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">3.</span>
                    <span>Если деньги списались — не платите повторно:
                    <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener" class="font-semibold text-indigo-700 hover:text-indigo-900">напишите нам в Telegram</a>,
                    мы проверим поступление и либо откроем доступ, либо вернем деньги.
                    Обычно отвечаем в течение рабочего дня.</span>
                </li>
                @else
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">1.</span>
                    <span>Сразу после отправки пришлем на email подтверждение, что заявка получена.</span>
                </li>
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">2.</span>
                    <span>Обычно в течение одного рабочего дня сверим поступление по выписке
                    и откроем доступ. Для нового аккаунта на email придет пароль от личного
                    кабинета. Уже учитесь у нас? Войдите в кабинет перед отправкой — тогда
                    доступ откроется сразу.</span>
                </li>
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">3.</span>
                    <span>Если рабочий день прошел, а доступа нет —
                    <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener" class="font-semibold text-indigo-700 hover:text-indigo-900">напишите нам в Telegram</a>,
                    обычно отвечаем в течение рабочего дня.</span>
                </li>
                @endauth
            </ol>
        </div>
    </div>
</div>
@endsection
