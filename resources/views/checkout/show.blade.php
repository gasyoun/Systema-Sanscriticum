@extends('layouts.promo') 
@section('title', 'Оформление заказа')

@section('content')
<div class="min-h-screen bg-gray-50 py-12 lg:py-20 font-sans">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <div class="text-center mb-12">
            <h1 class="text-4xl font-extrabold text-gray-900 tracking-tight">Оформление заказа</h1>
            <p class="mt-3 text-lg text-gray-500">Остался всего один шаг до начала обучения</p>
        </div>

        <div class="bg-white rounded-3xl shadow-2xl shadow-gray-200/50 border border-gray-100 overflow-hidden flex flex-col md:flex-row">
            
            <div class="p-8 lg:p-12 md:w-1/2 bg-slate-50 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col justify-center">
                <span class="inline-flex w-max items-center px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-wider bg-indigo-100 text-indigo-800 mb-6">
                    {{ $tariff->course->title ?? 'Курс' }}
                </span>
                
                <h2 class="text-3xl lg:text-4xl font-extrabold text-gray-900 mb-4 leading-tight">
                    {{ $tariff->title }}
                </h2>
                
                @if($tariff->description)
                <p class="text-gray-600 text-base leading-relaxed mb-8">
                    {{ $tariff->description }}
                </p>
                @endif

                <div class="mt-auto">
                    <ul class="space-y-4 text-sm text-gray-700 font-medium">
                        <li class="flex items-center">
                            <div class="flex-shrink-0 w-6 h-6 rounded-full bg-green-100 flex items-center justify-center mr-3">
                                <i class="fas fa-check text-green-600 text-xs"></i>
                            </div>
                            <span>Моментальный доступ после оплаты</span>
                        </li>
                        <li class="flex items-center">
                            <div class="flex-shrink-0 w-6 h-6 rounded-full bg-green-100 flex items-center justify-center mr-3">
                                <i class="fas fa-check text-green-600 text-xs"></i>
                            </div>
                            <span>Доступ к материалам навсегда</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="p-8 lg:p-12 md:w-1/2 flex flex-col justify-center bg-white">
                
                @if($finalPrice == 0 && auth()->check())
                    <div class="text-center py-8">
                        <div class="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-6 border border-green-100">
                            <i class="fas fa-check text-4xl text-green-500"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 mb-3">У вас уже есть доступ!</h3>
                        <p class="text-gray-500 text-base mb-8">Вы полностью оплатили этот тариф ранее. Можете сразу приступать к занятиям.</p>
                        <a href="{{ route('student.dashboard') }}" class="w-full inline-flex justify-center items-center px-8 py-4 text-lg font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 transition shadow-lg shadow-indigo-200">
                            Перейти к урокам
                        </a>
                    </div>

                @else
                    <div class="w-full max-w-sm mx-auto">
                        
                        @if(!empty($isLoyal) && $isLoyal && empty($appliedPromo))
                            <div class="mb-8 bg-gradient-to-br from-orange-50 to-orange-100/30 border border-orange-100 p-5 rounded-2xl flex items-start gap-4 shadow-sm">
                                <div class="bg-[#E85C24] text-white rounded-full w-12 h-12 flex items-center justify-center flex-shrink-0 shadow-md">
                                    <i class="fas fa-crown text-lg"></i>
                                </div>
                                <div>
                                    <p class="text-base font-extrabold text-gray-900">Скидка для своих: -{{ $loyaltyPercent }}%</p>
                                    <p class="text-xs text-gray-600 mt-1 leading-relaxed">Применена автоматически, так как вы уже учились у нас.</p>
                                </div>
                            </div>
                        @endif

                        <div class="mb-8">
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Итого к оплате:</p>
                            
                            @if($finalPrice < $tariff->price && auth()->check() && empty($appliedPromo) && empty($isLoyal))
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="text-xl text-gray-400 line-through font-medium">{{ number_format($tariff->price, 0, '.', ' ') }} ₽</span>
                                    <span class="text-xs font-bold px-2.5 py-1 bg-green-100 text-green-700 rounded-lg">Скидка за прошлые покупки</span>
                                </div>
                            @endif

                            @if(!empty($appliedPromo))
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="text-xl text-gray-400 line-through font-medium">{{ number_format($tariff->price, 0, '.', ' ') }} ₽</span>
                                    <span class="text-xs font-bold px-2.5 py-1 bg-[#E85C24]/10 text-[#E85C24] rounded-lg border border-[#E85C24]/20">
                                        Код: {{ $appliedPromo->code }} (-{{ number_format($discountAmount ?? 0, 0, '.', ' ') }} ₽)
                                    </span>
                                </div>
                            @endif
                            
                            <div class="text-5xl lg:text-6xl font-black text-gray-900 tracking-tight">
                                {{ number_format($finalPrice, 0, '.', ' ') }} <span class="text-3xl text-gray-400 font-medium">₽</span>
                            </div>
                        </div>

                        <div class="mb-8">
                            @if(session('error'))
                                <div class="mb-4 text-sm text-red-600 bg-red-50 p-4 rounded-xl border border-red-100 flex items-start">
                                    <i class="fas fa-exclamation-circle mt-1 mr-2 flex-shrink-0"></i> 
                                    <span>{{ session('error') }}</span>
                                </div>
                            @endif

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
                                            Ок
                                        </button>
                                    </div>
                                </form>
                            @endif
                        </div>

                        <form action="#" method="POST">
                            @csrf
                            <button type="button" class="w-full flex justify-center items-center py-4 px-4 rounded-xl shadow-lg shadow-orange-200 text-lg font-bold text-white bg-[#E85C24] hover:bg-[#d64e1c] hover:-translate-y-0.5 transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-[#E85C24]/30">
                                <i class="fas fa-lock mr-2 opacity-80"></i>
                                К безопасной оплате
                            </button>
                        </form>
                        
                        <p class="text-center text-xs text-gray-400 mt-6 leading-relaxed">
                            Нажимая на кнопку, вы соглашаетесь с <a href="#" class="underline hover:text-gray-600">офертой</a> и <a href="#" class="underline hover:text-gray-600">политикой конфиденциальности</a>.
                        </p>
                    </div>
                @endif
            </div>
        </div>
        
        <div class="flex justify-center items-center gap-6 mt-8 opacity-60 grayscale">
            <i class="fab fa-cc-visa text-3xl"></i>
            <i class="fab fa-cc-mastercard text-3xl"></i>
            <i class="fab fa-cc-apple-pay text-3xl"></i>
        </div>

    </div>
</div>
@endsection