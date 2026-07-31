@extends('layouts.shop')
@section('title', 'Счет для компании')

@section('content')
<div class="min-h-screen bg-gradient-to-b from-gray-50 to-white py-10 md:py-16 font-sans antialiased">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="mb-6">
            <a href="{{ route('checkout.show', $tariff) }}" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 transition mb-4">
                <i class="fas fa-arrow-left mr-2 text-xs"></i> Назад к оформлению
            </a>
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-950 tracking-tight">Счет для компании</h1>
            <p class="mt-2 text-base text-gray-500">
                Для оплаты от организации или ИП. Мы сформируем счет с реквизитами школы;
                после банковского перевода сверим поступление и откроем доступ — обычно
                в течение одного-двух рабочих дней.
            </p>
        </div>

        <div class="mb-8 bg-amber-50 border border-amber-200 text-amber-900 rounded-2xl p-4 text-sm leading-relaxed">
            Если деньги уже ушли со счета — не платите повторно: напишите нам, мы проверим
            поступление и либо откроем доступ, либо вернем деньги.
        </div>

        @if($errors->any())
            <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-sm font-medium">
                <ul class="list-disc pl-5 space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100 mb-6">
            <p class="text-sm text-gray-600 leading-relaxed mb-5">
                Тариф: <span class="font-bold text-gray-900">{{ $course?->title ?? 'Курс' }} — {{ $tariff->title ?? $tariff->accessKey() }}</span>.
                Сумма счета: <span class="font-bold text-gray-900">{{ number_format($price, 0, '.', ' ') }} ₽</span>.
            </p>

            <form action="{{ route('invoice.claim.store', $tariff) }}" method="POST" class="space-y-5">
                @csrf

                @guest
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Контактное имя <span class="text-red-500">*</span></label>
                            <input type="text" name="name" required maxlength="255" value="{{ old('name') }}"
                                   class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 py-3 px-4">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" required maxlength="255" value="{{ old('email') }}"
                                   class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 py-3 px-4">
                        </div>
                    </div>
                @endguest

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Название организации / ИП <span class="text-red-500">*</span></label>
                    <input type="text" name="company_name" required maxlength="255" value="{{ old('company_name') }}"
                           placeholder="ООО «…» / ИП Иванов И.И."
                           class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 py-3 px-4">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ИНН <span class="text-red-500">*</span></label>
                        <input type="text" name="inn" required inputmode="numeric" pattern="\d{10}|\d{12}" value="{{ old('inn') }}"
                               placeholder="10 или 12 цифр"
                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 py-3 px-4">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">КПП</label>
                        <input type="text" name="kpp" inputmode="numeric" pattern="\d{9}" value="{{ old('kpp') }}"
                               placeholder="9 цифр (для ООО)"
                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 py-3 px-4">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Юридический адрес <span class="text-red-500">*</span></label>
                    <textarea name="legal_address" required rows="2" maxlength="500"
                              class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 py-3 px-4">{{ old('legal_address') }}</textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Телефон</label>
                    <input type="text" name="contact_phone" maxlength="40" value="{{ old('contact_phone') }}"
                           class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 py-3 px-4">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Комментарий</label>
                    <textarea name="comment" rows="2" maxlength="1000"
                              class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 py-3 px-4">{{ old('comment') }}</textarea>
                </div>

                <button type="submit"
                        class="w-full flex justify-center items-center py-3.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white text-base font-bold rounded-xl transition-all shadow-lg shadow-emerald-200">
                    <i class="fas fa-file-invoice mr-2"></i> Сформировать счет
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
