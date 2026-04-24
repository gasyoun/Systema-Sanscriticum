@extends('layouts.shop')
@section('title', 'Оформление заказа')

@section('content')
<div class="min-h-screen bg-gray-50 py-10 md:py-16 font-sans antialiased">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <div class="mb-10 pb-6 border-b border-gray-100">
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-950 tracking-tighter">Оформление заказа</h1>
            <p class="mt-2 text-lg text-gray-500">Пожалуйста, проверьте данные заказа и выберите удобный способ оплаты</p>
        </div>
@guest
    <div class="mb-10">
        @include('partials.guest-purchase-warning', ['variant' => 'light'])
    </div>
@endguest
        @if($finalPrice == 0 && auth()->check())
            <div class="bg-white p-8 rounded-3xl shadow-xl shadow-gray-100/30 border border-gray-100 text-center flex flex-col items-center">
                <div class="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center mb-6 border border-green-100">
                    <i class="fas fa-check text-4xl text-green-500"></i>
                </div>
                <h2 class="text-3xl font-extrabold text-gray-900 mb-3 tracking-tight">У вас уже есть доступ!</h2>
                <p class="text-gray-500 text-base mb-8 max-w-md">Вы полностью оплатили этот тариф ранее. Можете сразу приступать к занятиям в личном кабинете.</p>
                <a href="{{ route('student.dashboard') }}" class="inline-flex justify-center items-center px-10 py-4 text-lg font-bold rounded-2xl text-white bg-indigo-600 hover:bg-indigo-700 transition-all duration-200 shadow-lg shadow-indigo-200">
                    Перейти в личный кабинет
                </a>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-12 md:gap-x-12 lg:gap-x-16">
                
                <div class="md:col-span-7 space-y-8 mb-10 md:mb-0">
                    
                    <form action="{{ route('payment.create') }}" method="POST" id="checkout-form">
    @csrf
    <input type="hidden" name="tariff_id" value="{{ $tariff->id }}">

    @guest
    
    
        <div class="bg-white p-7 rounded-3xl shadow-lg shadow-gray-100/30 border border-gray-100">
            <h4 class="text-sm font-bold text-gray-900 mb-5 uppercase tracking-wider">Ваши данные для доступа</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-5">
                
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Ваше Имя <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="name" required minlength="2" placeholder="Например, Иван" 
                           class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 focus:invalid:border-red-500 focus:invalid:ring-red-500 py-3 px-4 transition">
                </div>
                
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Email <span class="text-red-500">*</span> 
                        <small class="text-gray-500 ml-1">(сюда придет доступ)</small>
                    </label>
                    <input type="email" name="email" required placeholder="ivan@example.com" 
                           pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$"
                           class="peer block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 focus:invalid:border-red-500 focus:invalid:ring-red-500 py-3 px-4 transition">
                    
                    <p class="mt-1.5 text-xs text-red-500 hidden peer-[&:not(:placeholder-shown):invalid]:block">
                        <i class="fas fa-exclamation-circle mr-1"></i> Пожалуйста, укажите корректный email (например, name@mail.ru)
                    </p>
                </div>
                
            </div>
        </div>
    @endguest
</form>

                    <div class="bg-white p-7 rounded-3xl shadow-lg shadow-gray-100/30 border border-gray-100">
                        @if(!empty($appliedPromo))
                            <form action="{{ route('checkout.promo.remove', $tariff->id) }}" method="POST" class="flex items-center justify-between bg-green-50 p-4 rounded-xl border border-green-200">
                                @csrf
                                <div class="flex items-center">
                                    <i class="fas fa-check-circle text-green-500 mr-3 text-lg"></i>
                                    <span class="text-sm font-bold text-green-800">Промокод применен</span>
                                </div>
                                <button type="submit" class="text-sm font-medium text-gray-500 hover:text-red-500 transition px-2 py-1">Отменить</button>
                            </form>
                        @else
                            <form action="{{ route('checkout.promo', $tariff->id) }}" method="POST">
                                @csrf
                                <div class="relative flex items-center">
                                    <i class="fas fa-ticket-alt absolute left-4 text-gray-400"></i>
                                    <input type="text" name="code" placeholder="Промокод (если есть)" 
                                        class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm uppercase pl-11 pr-24 py-3.5 transition bg-gray-50 focus:bg-white">
                                    <button type="submit" class="absolute right-1.5 top-1.5 bottom-1.5 inline-flex justify-center items-center px-4 border border-transparent text-sm font-bold rounded-lg text-indigo-700 bg-indigo-100 hover:bg-indigo-200 transition">
                                        Применить
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>

                    <div class="bg-white p-7 rounded-3xl shadow-lg shadow-gray-100/30 border border-gray-100">
                        <button type="submit" form="checkout-form" class="w-full flex justify-center items-center py-4.5 px-6 rounded-xl shadow-lg shadow-orange-200 text-xl font-bold text-white bg-[#E85C24] hover:bg-[#d64e1c] hover:-translate-y-0.5 transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-[#E85C24]/30">
                            <i class="fas fa-lock mr-2.5 opacity-80"></i>
                            К безопасной оплате на {{ number_format($finalPrice, 0, '.', ' ') }} ₽
                        </button>
                        
                        <p class="text-center text-xs text-gray-400 mt-5 leading-relaxed">
                            Нажимая на кнопку, вы соглашаетесь с <a href="#" class="underline hover:text-gray-600">офертой</a> и <a href="#" class="underline hover:text-gray-600">политикой конфиденциальности</a>.
                        </p>
                    </div>

                </div>

                
                <div class="md:col-span-5 relative">
                    
                    <div class="md:sticky md:top-6 space-y-8">
                        
                        <div class="bg-white p-7 rounded-3xl shadow-xl shadow-gray-100/30 border border-gray-100">
                            <span class="inline-flex w-max items-center px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-wider bg-indigo-100 text-indigo-800 mb-5">
                                {{ $tariff->course->title ?? 'Курс' }}
                            </span>
                            
                            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900 mb-4 tracking-tight leading-tight">
                                {{ $tariff->title }}
                            </h2>
                            
                            @if($tariff->description)
                            <p class="text-gray-600 text-sm leading-relaxed mb-6">
                                {{ $tariff->description }}
                            </p>
                            @endif

                            <ul class="space-y-3.5 text-sm text-gray-700 font-medium border-t border-gray-100 pt-6">
                                <li class="flex items-center">
                                    <div class="flex-shrink-0 w-6 h-6 rounded-full bg-green-100 flex items-center justify-center mr-3">
                                        <i class="fas fa-check text-green-600 text-xs"></i>
                                    </div>
                                    <span>Доступ к материалам сразу после оплаты</span>
                                </li>
                                <li class="flex items-center">
                                    <div class="flex-shrink-0 w-6 h-6 rounded-full bg-green-100 flex items-center justify-center mr-3">
                                        <i class="fas fa-check text-green-600 text-xs"></i>
                                    </div>
                                    <span>Обучение в личном кабинете студента</span>
                                </li>
                            </ul>
                        </div>

                        <div class="bg-indigo-950 p-8 rounded-3xl text-white shadow-2xl shadow-indigo-100">
                            
                            @if(!empty($isLoyal) && $isLoyal && empty($appliedPromo))
                                <div class="mb-6 bg-white/5 border border-white/10 p-4 rounded-xl flex items-center gap-4 shadow-inner">
                                    <div class="bg-[#E85C24] text-white rounded-full w-10 h-10 flex items-center justify-center flex-shrink-0 shadow-md">
                                        <i class="fas fa-crown text-base"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-extrabold text-white">Скидка для своих: -{{ $tariff->getDiscountPercentForUser(auth()->user()) }}%</p>
                                        <p class="text-xs text-indigo-200 mt-0.5 leading-relaxed">Применена автоматически (уже учились у нас)</p>
                                    </div>
                                </div>
                            @endif

                            <p class="text-xs font-bold text-indigo-300 uppercase tracking-wider mb-2">Итого к оплате:</p>
                                
                            @if($finalPrice < $tariff->price)
                                <div class="flex items-baseline gap-3 mb-2 border-b border-indigo-900/50 pb-3">
                                    <span class="text-3xl lg:text-4xl font-black tracking-tight text-white">
                                        {{ number_format($finalPrice, 0, '.', ' ') }} <span class="text-xl text-indigo-300 font-medium">₽</span>
                                    </span>
                                    <span class="text-lg text-indigo-400 line-through font-medium">{{ number_format($tariff->price, 0, '.', ' ') }} ₽</span>
                                </div>
                            @else
                                <div class="text-5xl lg:text-6xl font-black text-white tracking-tight mb-2">
                                    {{ number_format($finalPrice, 0, '.', ' ') }} <span class="text-3xl text-indigo-300 font-medium">₽</span>
                                </div>
                            @endif
                        </div>

                    </div>
                </div>

            </div>
        @endif
        
        
    </div>
</div>
@endsection