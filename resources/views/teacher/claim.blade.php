@extends('layouts.shop')

@section('title', 'Оплата напрямую преподавателю — уведомление')

@section('content')
<div class="min-h-screen bg-gradient-to-b from-gray-50 to-white py-10 md:py-16 font-sans antialiased">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="mb-6">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-950 tracking-tight">Оплата напрямую преподавателю</h1>
            <p class="mt-2 text-base text-gray-500">
                Вы перевели оплату на личный счёт преподавателя — сообщите нам здесь, и мы
                зачтём платёж. Без этой заявки оплата останется незачтённой: куратор сверяет
                поступление по выписке преподавателя, обычно в течение одного рабочего дня,
                и открывает доступ.
            </p>
        </div>

        {{-- Шаг 1: что вы уже сделали --}}
        <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100 mb-6">
            <div class="flex items-center gap-3 mb-4">
                <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-sm font-extrabold">1</span>
                <h4 class="text-base font-extrabold text-gray-900">Переведите оплату преподавателю</h4>
            </div>
            <p class="text-sm text-gray-600 leading-relaxed">
                Тариф: <span class="font-bold text-gray-900">{{ $course?->title ?? 'Курс' }} — {{ $tariff->title ?? $tariff->accessKey() }}</span>.
                Реквизиты личного счёта преподавателя вы получили от него или от куратора.
                Комиссию банка за перевод оплачивает отправитель.
            </p>
        </div>

        {{-- Шаг 2: сообщить об оплате --}}
        <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100 mb-6">
            <div class="flex items-center gap-3 mb-5">
                <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-sm font-extrabold">2</span>
                <h4 class="text-base font-extrabold text-gray-900">Сообщите об оплате</h4>
            </div>

            <form id="teacher-pay-form" action="{{ route('teacherpay.claim.store', $tariff) }}" method="POST" enctype="multipart/form-data" class="space-y-5">
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

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Кому перевели оплату <span class="text-red-500">*</span></label>
                    <select name="teacher_id" required
                            class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">
                        <option value="">— выберите преподавателя —</option>
                        @foreach($teachers as $teacher)
                        <option value="{{ $teacher->id }}" @selected(old('teacher_id') == $teacher->id)>{{ $teacher->name }}</option>
                        @endforeach
                    </select>
                </div>

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
                    <p class="mt-1 text-xs text-gray-500">Скриншот или PDF из вашего банка — ускорит сверку.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Комментарий</label>
                    <textarea name="comment" rows="3" maxlength="1000" placeholder="курс, блок, имя ученика, что-то еще важное"
                              class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition">{{ old('comment') }}</textarea>
                </div>

                <button type="submit"
                        class="w-full inline-flex justify-center items-center px-6 py-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm transition">
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
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">1.</span>
                    <span>Сразу после отправки пришлем на email подтверждение, что заявка получена.</span>
                </li>
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">2.</span>
                    <span>Куратор сверит поступление по выписке преподавателя — обычно в течение
                    одного рабочего дня — и откроет доступ. Для нового аккаунта на email придет
                    пароль от личного кабинета.</span>
                </li>
                <li class="flex gap-3">
                    <span class="shrink-0 font-bold text-gray-400">3.</span>
                    <span>Если рабочий день прошел, а доступа нет —
                    <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener" class="font-semibold text-indigo-700 hover:text-indigo-900">напишите нам в Telegram</a>,
                    обычно отвечаем в течение рабочего дня.</span>
                </li>
            </ol>
        </div>
    </div>
</div>
@endsection
