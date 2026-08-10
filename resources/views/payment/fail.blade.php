{{-- Страница возврата с неудавшейся оплаты — failRedirectUrl Точки (H1285).
     Только отображение: статус платежа здесь НЕ меняется — источник истины
     вебхук Точки (см. комментарий в PaymentController::fail()). Первым блоком
     снимаем страх двойного списания (money-fear rule голосового контракта),
     затем — повтор оплаты без возврата на главную. --}}
@extends('layouts.shop')

@section('title', 'Оплата не прошла')

@section('content')
<div class="container mx-auto px-4 py-14 md:py-20">
    <div class="max-w-xl mx-auto">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8 md:p-10 text-gray-900">

            <div class="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-credit-card text-2xl text-amber-600"></i>
            </div>

            <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mb-6 text-center">
                Оплата не прошла или была отменена
            </h1>

            {{-- Страх «деньги списались» снимается первым же блоком, выше фолда --}}
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mb-6">
                <p class="text-gray-800 leading-relaxed mb-4">
                    <strong>Если деньги списались — не платите повторно:</strong>
                    напишите нам, мы проверим платеж и либо откроем доступ, либо вернем деньги.
                </p>
                <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-amber-300 bg-white text-gray-800 font-bold text-sm hover:bg-amber-100 transition-colors">
                    <i class="fab fa-telegram text-[#0088cc]"></i> Написать в Telegram
                </a>
            </div>

            <p class="text-gray-600 leading-relaxed mb-8 text-center">
                Так бывает: банк отклонил операцию, оплата была отменена или
                истек срок платежной ссылки. Попробуйте еще раз.
            </p>

            <div class="text-center">
                @if($course)
                    <a href="{{ route('shop.course.show', $course) }}"
                       class="inline-flex justify-center items-center py-4 px-8 rounded-2xl shadow-lg shadow-orange-200/70 text-lg font-extrabold text-white bg-gradient-to-r from-brand to-brand-hover hover:shadow-xl hover:shadow-orange-300/60 hover:-translate-y-0.5 transition-all duration-200">
                        Попробовать снова
                    </a>
                @else
                    <a href="{{ route('shop.index') }}"
                       class="inline-flex justify-center items-center py-4 px-8 rounded-2xl shadow-lg shadow-orange-200/70 text-lg font-extrabold text-white bg-gradient-to-r from-brand to-brand-hover hover:shadow-xl hover:shadow-orange-300/60 hover:-translate-y-0.5 transition-all duration-200">
                        Выбрать курс
                    </a>
                @endif
            </div>

            <div class="mt-8 text-center">
                <a href="{{ url('/') }}" class="text-sm text-gray-400 hover:text-gray-600 transition-colors">
                    На главную
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
