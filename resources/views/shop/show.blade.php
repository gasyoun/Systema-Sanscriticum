@extends('layouts.shop') 
@section('title', $course->title . ' — Общество ревнителей санскрита')

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white font-sans relative overflow-hidden">
    
    <div class="absolute top-[-10%] left-[-10%] w-[800px] h-[800px] bg-indigo-900/10 rounded-full blur-[150px] pointer-events-none"></div>
    <div class="absolute bottom-[-10%] right-[-10%] w-[600px] h-[600px] bg-[#E85C24]/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="relative pt-24 pb-16 lg:pt-32 lg:pb-24 border-b border-[#1F2636]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            
            <div class="flex flex-col lg:flex-row gap-12 lg:gap-20 items-center">
                <div class="w-full lg:w-1/2">
                    <div class="flex items-center gap-4 mb-6">
                        <span class="bg-[#E85C24]/20 text-[#E85C24] text-xs font-black uppercase px-3 py-1.5 rounded-full tracking-widest border border-[#E85C24]/30">
                            Онлайн-программа
                        </span>
                        
                        <div class="flex gap-4 text-sm font-bold text-slate-400">
                            @if($course->lessons_count)
                                <span class="flex items-center"><i class="fas fa-play-circle mr-2 text-indigo-400"></i> {{ $course->lessons_count }} лекций</span>
                            @endif
                            @if($course->hours_count)
                                <span class="flex items-center"><i class="far fa-clock mr-2 text-indigo-400"></i> {{ $course->hours_count }} часов</span>
                            @endif
                        </div>
                    </div>
                    
                    <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-8 leading-tight">
                        {{ $course->title }}
                    </h1>
                    
                    <div class="flex flex-wrap gap-4">
                        <a href="#tariffs" class="inline-flex justify-center items-center px-8 py-4 text-sm md:text-base font-bold rounded-xl text-white bg-[#E85C24] hover:bg-[#d64e1c] transition-all hover:-translate-y-1 shadow-[0_0_20px_rgba(232,92,36,0.3)]">
                            Выбрать тариф
                        </a>
                        <a href="{{ route('shop.index') }}" class="inline-flex justify-center items-center px-8 py-4 text-sm md:text-base font-bold rounded-xl text-white bg-[#1F2636] hover:bg-[#2A344A] transition-all">
                            Все курсы
                        </a>
                    </div>
                </div>

                <div class="w-full lg:w-1/2">
                    <div class="relative w-full aspect-video md:aspect-[4/3] rounded-3xl overflow-hidden bg-gradient-to-br from-[#111622] to-[#0A0D14] border border-[#1F2636] shadow-2xl shadow-indigo-900/20 flex items-center justify-center group">
                        
                        @if($course->image_path)
                            <img src="{{ Storage::url($course->image_path) }}" alt="{{ $course->title }}" class="absolute inset-0 w-full h-full object-cover opacity-60 mix-blend-luminosity hover:mix-blend-normal hover:opacity-100 transition-all duration-700">
                        @else
                            <i class="fas fa-om text-9xl text-slate-700/20 group-hover:scale-110 transition-transform duration-700"></i>
                        @endif
                        
                        <div class="absolute top-6 left-6 w-3 h-3 rounded-full bg-[#E85C24] animate-pulse"></div>
                        <div class="absolute bottom-6 right-6 w-24 h-24 border border-white/5 rounded-full"></div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>

    <div class="py-16 lg:py-24 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 flex flex-col lg:flex-row gap-16">
        
        <div class="w-full lg:w-2/3">
            <h2 class="text-3xl font-bold text-white mb-8">О курсе</h2>
            
            <div class="prose prose-invert prose-lg prose-slate max-w-none">
                @if($course->description)
                    <div class="text-slate-300 leading-relaxed space-y-6">
                        {!! nl2br(e($course->description)) !!}
                    </div>
                @else
                    <p class="text-slate-500 italic">Подробное описание курса скоро появится.</p>
                @endif
            </div>

            <div class="mt-16 grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-[#111622] p-6 rounded-2xl border border-[#1F2636]">
                    <i class="fas fa-infinity text-2xl text-[#38BDF8] mb-4"></i>
                    <h3 class="text-lg font-bold text-white mb-2">Вечный доступ</h3>
                    <p class="text-sm text-slate-400">Материалы курса остаются с вами навсегда. Пересматривайте лекции в любое время.</p>
                </div>
                <div class="bg-[#111622] p-6 rounded-2xl border border-[#1F2636]">
                    <i class="fas fa-laptop-house text-2xl text-[#38BDF8] mb-4"></i>
                    <h3 class="text-lg font-bold text-white mb-2">Онлайн формат</h3>
                    <p class="text-sm text-slate-400">Учитесь из любой точки мира в своем собственном темпе, без привязки к жесткому расписанию.</p>
                </div>
            </div>
        </div>

        <div class="w-full lg:w-1/3">
            <div id="tariffs" class="sticky top-24" 
                 x-data="{ tab: 'full' }" 
                 {{-- Если нет тарифов 'full', сразу открываем модули --}}
                 x-init="if({{ $course->tariffs->where('type', '!=', 'block')->count() }} === 0) tab = 'blocks'">
                 
                <h3 class="text-2xl font-bold text-white mb-6">Выберите вариант участия</h3>
                
                {{-- === ПРЕДУПРЕЖДЕНИЕ ДЛЯ ГОСТЕЙ === --}}
<div class="mb-6">
    @include('partials.guest-purchase-warning', ['variant' => 'dark'])
</div>
{{-- === /ПРЕДУПРЕЖДЕНИЕ === --}}
                
                @php
                    // Разделяем тарифы на две группы
                    $fullTariffs = $course->tariffs->where('type', '!=', 'block');
                    $blockTariffs = $course->tariffs->where('type', 'block')->sortBy('block_number');
                @endphp
                
                @if($course->tariffs->count() > 0)

                    {{-- ПЕРЕКЛЮЧАТЕЛЬ ВКЛАДОК (показываем, только если есть оба типа) --}}
                    @if($fullTariffs->count() > 0 && $blockTariffs->count() > 0)
                        <div class="flex bg-[#111622] border border-[#1F2636] rounded-xl p-1 mb-6">
                            <button @click="tab = 'full'" 
                                    :class="tab === 'full' ? 'bg-[#1F2636] text-white shadow-md' : 'text-slate-500 hover:text-slate-300'"
                                    class="flex-1 py-2.5 text-sm font-bold rounded-lg transition-all duration-200">
                                Весь курс
                            </button>
                            <button @click="tab = 'blocks'" 
                                    :class="tab === 'blocks' ? 'bg-[#1F2636] text-white shadow-md' : 'text-slate-500 hover:text-slate-300'"
                                    class="flex-1 py-2.5 text-sm font-bold rounded-lg transition-all duration-200">
                                По модулям
                            </button>
                        </div>
                    @endif

                    {{-- ВКЛАДКА 1: ВЕСЬ КУРС --}}
                    <div x-show="tab === 'full'" 
                         x-transition:enter="transition ease-out duration-300" 
                         x-transition:enter-start="opacity-0 translate-y-2" 
                         x-transition:enter-end="opacity-100 translate-y-0" 
                         class="space-y-4" x-cloak>
                         
                        @foreach($fullTariffs as $tariff)
    @php
        $tariffKey = $tariff->type === 'block' ? 'block_' . $tariff->block_number : 'full';
        $isPurchased = in_array($tariffKey, $purchasedKeys, true);
        $finalPrice = auth()->check() ? $tariff->calculateFinalPriceForUser(auth()->user()) : $tariff->price;
        $discountPercent = auth()->check() ? $tariff->getDiscountPercentForUser(auth()->user()) : 0;
    @endphp

    <div class="bg-gradient-to-b from-[#1A2235] to-[#111622] rounded-2xl p-6 border {{ $isPurchased ? 'border-emerald-500/50' : 'border-[#E85C24]/30 hover:border-[#E85C24]' }} transition-all duration-300 relative overflow-hidden group">

        {{-- Плашка статуса: Куплено / Выгодно --}}
        @if($isPurchased)
            <div class="absolute top-0 right-0 bg-emerald-500 text-white text-[10px] font-black px-4 py-1.5 rounded-bl-xl tracking-wider">
                <i class="fas fa-check-circle mr-1"></i> КУПЛЕНО
            </div>
        @else
            <div class="absolute top-0 right-0 bg-[#E85C24] text-white text-[10px] font-black px-4 py-1.5 rounded-bl-xl tracking-wider">
                ВЫГОДНО
            </div>
        @endif

        <h4 class="text-xl font-bold text-white mb-2 pr-20">{{ $tariff->title }}</h4>

        {{-- Цена --}}
        <div class="mb-4">
            @if($isPurchased)
                <div class="text-2xl font-black text-emerald-400">
                    Доступ открыт
                </div>
                <div class="text-sm text-slate-500 mt-1">
                    Оплачено: {{ number_format($tariff->price, 0, '.', ' ') }} ₽
                </div>
            @elseif($discountPercent > 0)
                {{-- Скидка по программе лояльности --}}
                <div class="flex items-end gap-3 mb-1">
                    <div class="text-4xl font-black text-[#38BDF8]">
                        {{ number_format($finalPrice, 0, '.', ' ') }} <span class="text-xl text-[#38BDF8]/70 font-medium">₽</span>
                    </div>
                    <span class="bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 text-xs font-black uppercase px-2 py-1 rounded mb-1.5 tracking-wider">
                        Скидка -{{ $discountPercent }}%
                    </span>
                </div>
                <div class="text-slate-500 line-through text-lg font-medium decoration-slate-600/50">
                    {{ number_format($tariff->price, 0, '.', ' ') }} ₽
                </div>
            @elseif($finalPrice < $tariff->price)
                {{-- Полный курс, часть блоков уже оплачена — доплата --}}
                <div class="flex items-end gap-3 mb-1">
                    <div class="text-4xl font-black text-[#38BDF8]">
                        {{ number_format($finalPrice, 0, '.', ' ') }} <span class="text-xl text-[#38BDF8]/70 font-medium">₽</span>
                    </div>
                </div>
                <div class="text-xs text-slate-400 mb-1">
                    Доплата с учётом ранее купленных блоков
                </div>
                <div class="text-slate-500 line-through text-lg font-medium decoration-slate-600/50">
                    {{ number_format($tariff->price, 0, '.', ' ') }} ₽
                </div>
            @else
                {{-- Обычная цена --}}
                <div class="text-4xl font-black text-white">
                    {{ number_format($tariff->price, 0, '.', ' ') }} <span class="text-xl text-slate-500 font-medium">₽</span>
                </div>
            @endif
        </div>

        @if($tariff->description)
            <p class="text-sm text-slate-400 mb-6 leading-relaxed">{{ $tariff->description }}</p>
        @endif

        {{-- Кнопка --}}
        @if($isPurchased)
            <a href="{{ route('student.course', $course->slug) }}"
   class="w-full flex justify-center items-center py-4 px-4 bg-emerald-500 ...">
    <i class="fas fa-arrow-right mr-2"></i> Перейти к обучению
</a>
        @else
            <a href="{{ route('checkout.show', $tariff->id) }}"
   class="w-full flex justify-center items-center py-4 px-4 bg-[#E85C24] text-white text-base font-bold rounded-xl hover:bg-[#d64e1c] hover:shadow-[0_0_20px_rgba(232,92,36,0.4)] transition-all">
    Записаться на курс
</a>
        @endif
    </div>
@endforeach
                    </div>

                    {{-- ВКЛАДКА 2: ПО МОДУЛЯМ --}}
                    <div x-show="tab === 'blocks'" 
                         x-transition:enter="transition ease-out duration-300" 
                         x-transition:enter-start="opacity-0 translate-y-2" 
                         x-transition:enter-end="opacity-100 translate-y-0" 
                         {{-- Ограничиваем высоту и добавляем красивый скролл --}}
                         class="space-y-3 max-h-[500px] overflow-y-auto pr-2 custom-scrollbar" x-cloak>
                         
                        @foreach($blockTariffs as $tariff)
    @php
        $tariffKey = 'block_' . $tariff->block_number;
        $isPurchased = in_array($tariffKey, $purchasedKeys, true);
        $finalPrice = auth()->check() ? $tariff->calculateFinalPriceForUser(auth()->user()) : $tariff->price;
        $discountPercent = auth()->check() ? $tariff->getDiscountPercentForUser(auth()->user()) : 0;
    @endphp

    <div class="bg-[#111622] rounded-xl p-5 border {{ $isPurchased ? 'border-emerald-500/50' : 'border-[#1F2636] hover:border-[#38BDF8]/50' }} transition-all duration-300 group">

        <div class="flex justify-between items-start mb-3 gap-2">
            <div>
                <span class="inline-block text-[10px] font-black text-[#38BDF8] bg-[#38BDF8]/10 px-2 py-1 rounded border border-[#38BDF8]/20 mb-2 tracking-widest uppercase">
                    БЛОК {{ $tariff->block_number }}
                </span>
                <h4 class="text-base font-bold text-white leading-tight">{{ $tariff->title }}</h4>
            </div>

            <div class="text-right whitespace-nowrap">
                @if($isPurchased)
                    <div class="inline-flex items-center gap-1 bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 text-xs font-black uppercase px-2.5 py-1.5 rounded tracking-wider">
                        <i class="fas fa-check-circle"></i> Оплачено
                    </div>
                @elseif($discountPercent > 0)
                    <div class="text-slate-500 line-through text-xs font-medium mb-0.5 decoration-slate-600/50">
                        {{ number_format($tariff->price, 0, '.', ' ') }} ₽
                    </div>
                    <div class="text-xl font-black text-[#38BDF8]">
                        {{ number_format($finalPrice, 0, '.', ' ') }} <span class="text-sm text-[#38BDF8]/70 font-medium">₽</span>
                    </div>
                    <div class="text-[10px] text-emerald-400 font-bold mt-1 tracking-wide uppercase">
                        -{{ $discountPercent }}%
                    </div>
                @else
                    <div class="text-xl font-black text-white mt-3">
                        {{ number_format($tariff->price, 0, '.', ' ') }} <span class="text-sm text-slate-500 font-medium">₽</span>
                    </div>
                @endif
            </div>
        </div>

        @if($tariff->description)
            <p class="text-xs text-slate-400 mb-4">{{ $tariff->description }}</p>
        @endif

        @if($isPurchased)
            <a href="{{ route('student.course', $course->slug) }}"
               class="w-full flex justify-center items-center py-3 px-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm font-bold rounded-lg hover:bg-emerald-500 hover:text-white transition-colors">
                <i class="fas fa-arrow-right mr-2"></i> Перейти к блоку
            </a>
        @else
            <a href="{{ route('checkout.show', $tariff->id) }}"
   class="w-full flex justify-center items-center py-3 px-4 bg-[#1F2636] text-white text-sm font-bold rounded-lg hover:bg-[#38BDF8] hover:text-[#0A0D14] transition-colors">
    Оплатить модуль
</a>
        @endif
    </div>
@endforeach
                    </div>

                @else
                    {{-- ЗАГЛУШКА, ЕСЛИ ТАРИФОВ НЕТ --}}
                    <div class="bg-[#111622] rounded-2xl p-8 border border-[#1F2636] text-center">
                        <div class="w-16 h-16 bg-[#1F2636] rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-lock text-2xl text-slate-500"></i>
                        </div>
                        <h4 class="text-lg font-bold text-white mb-2">Набор закрыт</h4>
                        <p class="text-sm text-slate-400">В данный момент запись на этот курс не ведется.</p>
                    </div>
                @endif
            </div>
        
    </div>
</div>
@endsection