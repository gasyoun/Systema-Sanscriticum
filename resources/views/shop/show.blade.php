@extends('layouts.shop') 
@section('title', $course->title . ' — Общество ревнителей санскрита')

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white font-sans relative overflow-hidden">
    
    {{-- Декоративные блюры на фоне --}}
    <div class="absolute top-[-10%] left-[-10%] w-[800px] h-[800px] bg-indigo-900/10 rounded-full blur-[150px] pointer-events-none"></div>
    <div class="absolute bottom-[-10%] right-[-10%] w-[600px] h-[600px] bg-[#E85C24]/10 rounded-full blur-[150px] pointer-events-none"></div>

    {{-- ═════════════════ HERO ═════════════════ --}}
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

                    {{-- Кликабельный бейдж преподавателя --}}
@if($course->teacher)
    <a href="{{ route('shop.index', ['teacher' => $course->teacher->id]) }}"
       class="group/teacher mt-6 inline-flex items-center gap-3 px-4 py-3 rounded-xl bg-[#111622] border border-[#1F2636] hover:border-[#E85C24]/50 hover:bg-[#1A2235] transition-all duration-300 max-w-fit">
        
        {{-- Иконка-аватар --}}
        <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-[#E85C24] to-[#d04a15] flex items-center justify-center shrink-0 shadow-md shadow-[#E85C24]/20">
            <i class="fas fa-user text-white text-sm"></i>
        </div>
        
        {{-- Текст --}}
        <div class="flex flex-col leading-tight">
            <span class="text-[10px] font-black uppercase tracking-widest text-slate-500 group-hover/teacher:text-[#E85C24] transition-colors">
                Преподаватель
            </span>
            <span class="text-sm font-bold text-white">
                {{ $course->teacher->name }}
            </span>
        </div>
        
        {{-- Стрелка --}}
        <i class="fas fa-arrow-right text-xs text-slate-500 group-hover/teacher:text-[#E85C24] group-hover/teacher:translate-x-1 transition-all ml-2"></i>
    </a>
@endif

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

    {{-- ═════════════════ ОСНОВНОЙ КОНТЕНТ (одна широкая колонка) ═════════════════ --}}
    <div class="py-16 lg:py-24 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">

        {{-- ───── 1. О КУРСЕ ───── --}}
        <section class="mb-16 lg:mb-20">
            <h2 class="text-3xl font-bold text-white mb-8">О курсе</h2>
            
            <div class="prose prose-invert prose-lg prose-slate max-w-none">
                @if($course->description)
                    <div class="text-slate-300 leading-relaxed space-y-6 [&_a]:text-indigo-400 [&_a:hover]:text-indigo-300 [&_a]:underline">
                        {!! $course->description !!}
                    </div>
                @else
                    <p class="text-slate-500 italic">Подробное описание курса скоро появится.</p>
                @endif
            </div>
        </section>

        {{-- ───── 2. ТАРИФЫ ───── --}}
        @php
            $hasCurrentBlock = !empty($currentBlockNumber);
            $defaultTab = $hasCurrentBlock ? 'blocks' : 'full';
        @endphp
        <section id="tariffs" class="mb-16 lg:mb-20"
                 x-data="{ tab: '{{ $defaultTab }}' }"
                 x-init="if({{ $course->tariffs->where('type', '!=', 'block')->count() }} === 0) tab = 'blocks'">

            <h2 class="text-3xl font-bold text-white mb-8">Выберите вариант участия</h2>

            {{-- Предупреждение для гостей --}}
            <div class="mb-6 max-w-3xl">
                @include('partials.guest-purchase-warning', ['variant' => 'dark'])
            </div>

            {{-- ───── CTA: Забронировать курс (предоплата зачтётся в тариф) ───── --}}
            @php
                $courseDepositAmount = (float) ($course->deposit_amount ?? 0);
                $showDepositCta = ($deposit ?? null)?->deposit_enabled
                    && $courseDepositAmount > 0
                    && empty($purchasedKeys);
            @endphp
            @if($showDepositCta)
                @php
                    $depositAmountLabel = number_format($courseDepositAmount, 0, '.', ' ');
                @endphp
                <div class="mb-8 max-w-3xl rounded-2xl border border-[#E85C24]/30 bg-gradient-to-r from-[#E85C24]/10 to-transparent p-5 lg:p-6 flex flex-col md:flex-row md:items-center gap-4">
                    <div class="flex-1">
                        <div class="text-[10px] font-black uppercase tracking-widest text-[#E85C24] mb-1.5">
                            <i class="fas fa-bookmark mr-1"></i> Начните погружение не дожидаясь старта
                        </div>
                        <h3 class="text-lg lg:text-xl font-bold text-white mb-1">
                            Забронируйте место за {{ $depositAmountLabel }} ₽
                        </h3>
                        <p class="text-sm text-slate-400 leading-relaxed">
                            После предоплаты вы сразу получаете доступ к <span class="text-white font-semibold">открытым занятиям всей школы</span> —
                            начните погружение прямо сейчас. Сумма <span class="text-[#E85C24] font-bold">{{ $depositAmountLabel }} ₽</span>
                            будет зачтена в стоимость тарифа этого курса при последующей оплате.
                        </p>
                    </div>
                    <button type="button"
                            onclick="window.dispatchEvent(new CustomEvent('open-deposit-modal', { detail: { action: @js(route('deposit.create', $course->slug)), title: @js($course->title), amount: {{ $courseDepositAmount }} } }))"
                            class="md:flex-shrink-0 flex justify-center items-center py-3 px-5 bg-[#E85C24] hover:bg-[#d64e1c] text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-[#E85C24]/20">
                        <i class="fas fa-bookmark mr-2 text-xs"></i>
                        Забронировать
                    </button>
                </div>
            @endif

            {{-- ───── CTA: Купить пробное занятие (сумма зачтётся в тариф) ───── --}}
            @if(! empty($showTrialCta))
                @php
                    $trialAmount = (float) $course->trial_price;
                    $trialAmountLabel = number_format($trialAmount, 0, '.', ' ');
                @endphp
                <div class="mb-8 max-w-3xl rounded-2xl border border-[#38BDF8]/30 bg-gradient-to-r from-[#38BDF8]/10 to-transparent p-5 lg:p-6 flex flex-col md:flex-row md:items-center gap-4">
                    <div class="flex-1">
                        <div class="text-[10px] font-black uppercase tracking-widest text-[#38BDF8] mb-1.5">
                            <i class="fas fa-graduation-cap mr-1"></i> Попробуйте вживую перед покупкой
                        </div>
                        <h3 class="text-lg lg:text-xl font-bold text-white mb-1">
                            Пробное занятие за {{ $trialAmountLabel }} ₽
                        </h3>
                        <p class="text-sm text-slate-400 leading-relaxed">
                            @if($course->trialSchedule?->start)
                                Живое занятие <span class="text-white font-semibold">{{ $course->trialSchedule->start->translatedFormat('d F, H:i') }}</span> — ссылку на Zoom пришлём на email.
                            @else
                                Оплатите одно живое занятие, чтобы оценить подачу и формат.
                            @endif
                            Сумма <span class="text-[#38BDF8] font-bold">{{ $trialAmountLabel }} ₽</span>
                            будет зачтена в стоимость тарифа при последующей оплате.
                        </p>
                    </div>
                    <button type="button"
                            onclick="window.dispatchEvent(new CustomEvent('open-trial-modal', { detail: { action: @js(route('trial.create', $course->slug)), title: @js($course->title), amount: {{ $trialAmount }}, date: @js($course->trialSchedule?->start?->translatedFormat('d F, H:i')) } }))"
                            class="md:flex-shrink-0 flex justify-center items-center py-3 px-5 bg-[#38BDF8] hover:bg-[#2da4dd] text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-[#38BDF8]/20">
                        <i class="fas fa-graduation-cap mr-2 text-xs"></i>
                        Купить пробное
                    </button>
                </div>
            @endif

            @php
                $fullTariffs = $course->tariffs->where('type', '!=', 'block');

                // Группируем блочные тарифы по номеру блока: у одного блока может быть
                // тариф на ВЕСЬ блок и/или тарифы-половины (block_half = 1/2).
                $blockGroups = $course->tariffs->where('type', 'block')
                    ->groupBy('block_number')
                    ->map(fn ($items, $number) => [
                        'number' => (int) $number,
                        'whole'  => $items->whereNull('block_half')->first(),
                        'halves' => $items->whereNotNull('block_half')->sortBy('block_half')->values(),
                    ])
                    ->sortBy('number')
                    ->values();

                // Поднимаем актуальный блок в начало, чтобы он был сразу виден
                if (!empty($currentBlockNumber)) {
                    $blockGroups = $blockGroups
                        ->sortByDesc(fn ($g) => $g['number'] === $currentBlockNumber)
                        ->values();
                }
            @endphp

            @if($course->tariffs->count() > 0)

                {{-- Переключатель вкладок (если есть оба типа) --}}
                @if($fullTariffs->count() > 0 && $blockGroups->count() > 0)
                    <div class="inline-flex bg-[#111622] border border-[#1F2636] rounded-xl p-1 mb-8">
                        <button @click="tab = 'full'"
                                :class="tab === 'full' ? 'bg-[#1F2636] text-white shadow-md' : 'text-slate-500 hover:text-slate-300'"
                                class="px-6 py-2.5 text-sm font-bold rounded-lg transition-all duration-200">
                            Весь курс
                        </button>
                        <button @click="tab = 'blocks'"
                                :class="tab === 'blocks' ? 'bg-[#1F2636] text-white shadow-md' : 'text-slate-500 hover:text-slate-300'"
                                class="px-6 py-2.5 text-sm font-bold rounded-lg transition-all duration-200">
                            По модулям
                        </button>
                    </div>
                @endif

                {{-- ВКЛАДКА 1: ВЕСЬ КУРС --}}
                <div x-show="tab === 'full'"
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="grid grid-cols-1 gap-6 {{ $fullTariffs->count() > 1 ? 'md:grid-cols-2' : 'md:max-w-2xl md:mx-auto' }}" x-cloak>

                    @foreach($fullTariffs as $tariff)
                        @php
                            $tariffKey = $tariff->type === 'block' ? 'block_' . $tariff->block_number : 'full';
                            $isPurchased = in_array($tariffKey, $purchasedKeys, true);
                            $finalPrice = auth()->check() ? $tariff->calculateFinalPriceForUser(auth()->user()) : $tariff->price;
                            $discountPercent = auth()->check() ? $tariff->effectiveDiscountPercentForUser(auth()->user()) : 0;
                        @endphp

                        <div class="bg-gradient-to-b from-[#1A2235] to-[#111622] rounded-2xl p-6 border {{ $isPurchased ? 'border-emerald-500/50' : 'border-[#E85C24]/30 hover:border-[#E85C24] hover:-translate-y-1 hover:shadow-[0_12px_40px_-12px_rgba(232,92,36,0.35)]' }} transition-all duration-300 relative overflow-hidden group">

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

                            <div class="mb-4">
    @if($isPurchased)
        <div class="text-2xl font-black text-emerald-400">Доступ открыт</div>
        <div class="text-sm text-slate-500 mt-1">
            Оплачено: {{ number_format($tariff->price, 0, '.', ' ') }} ₽
        </div>
    @elseif($finalPrice < $tariff->price)
        <div class="flex items-end gap-3 mb-1">
            <div class="text-4xl font-black text-[#38BDF8]">
                {{ number_format($finalPrice, 0, '.', ' ') }} <span class="text-xl text-[#38BDF8]/70 font-medium">₽</span>
            </div>
            @if($discountPercent > 0)
                <span class="bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 text-xs font-black uppercase px-2 py-1 rounded mb-1.5 tracking-wider">
                    Скидка -{{ $discountPercent }}%
                </span>
            @endif
        </div>
        <div class="text-slate-500 line-through text-lg font-medium decoration-slate-600/50">
            {{ number_format($tariff->price, 0, '.', ' ') }} ₽
        </div>
        <div class="text-[11px] text-emerald-400/80 font-bold uppercase tracking-wide mt-1">
            Стоимость с учётом скидки
        </div>
    @else
        <div class="text-4xl font-black text-white">
            {{ number_format($tariff->price, 0, '.', ' ') }} <span class="text-xl text-slate-500 font-medium">₽</span>
        </div>
    @endif
</div>

                            @if($tariff->description)
                                <p class="text-sm text-slate-400 mb-6 leading-relaxed">{{ $tariff->description }}</p>
                            @endif

                            @if($isPurchased)
                                <a href="{{ route('student.course', $course->slug) }}"
                                   class="w-full flex justify-center items-center py-4 px-4 bg-emerald-500 text-white text-base font-bold rounded-xl hover:bg-emerald-600 transition-all">
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

                {{-- ВКЛАДКА 2: ПО МОДУЛЯМ (сетка 1/2/3 колонки) --}}
                <div x-show="tab === 'blocks'"
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 auto-rows-fr" x-cloak>

                    @foreach($blockGroups as $group)
                        @php
                            $number = $group['number'];
                            $whole = $group['whole'];
                            $halves = $group['halves'];

                            $wholeKey = 'block_' . $number;
                            $wholePurchased = in_array($wholeKey, $purchasedKeys, true);
                            $anyHalfPurchased = $halves->contains(fn ($h) => in_array($h->accessKey(), $purchasedKeys, true));
                            // «Перейти к блоку» доступно, если оплачен весь блок ИЛИ хотя бы одна половина.
                            $blockAccessible = $wholePurchased || $anyHalfPurchased;

                            $finalPrice = ($whole && auth()->check()) ? $whole->calculateFinalPriceForUser(auth()->user()) : ($whole->price ?? 0);
                            $discountPercent = ($whole && auth()->check()) ? $whole->effectiveDiscountPercentForUser(auth()->user()) : 0;

                            $defaultBlockTitle = 'Блок ' . $number;
                            $hasCustomTitle = $whole && $whole->title && trim($whole->title) !== $defaultBlockTitle;
                            $isCurrent = !$wholePurchased && $number === ($currentBlockNumber ?? null);

                            if ($wholePurchased) {
                                $borderClasses = 'border-emerald-500/50';
                            } elseif ($isCurrent) {
                                $borderClasses = 'border-[#E85C24] shadow-[0_0_0_1px_rgba(232,92,36,0.4),0_12px_40px_-12px_rgba(232,92,36,0.45)] hover:-translate-y-1';
                            } else {
                                $borderClasses = 'border-[#1F2636] hover:border-[#38BDF8]/60 hover:-translate-y-1 hover:shadow-[0_12px_40px_-12px_rgba(56,189,248,0.3)]';
                            }
                        @endphp

                        <div class="bg-gradient-to-b from-[#1A2235] to-[#111622] rounded-xl p-5 border {{ $borderClasses }} transition-all duration-300 group flex flex-col relative">

                            @if($isCurrent)
                                <div class="absolute -top-2.5 left-4 inline-flex items-center gap-1.5 bg-[#E85C24] text-white text-[10px] font-black uppercase px-2.5 py-1 rounded-md tracking-wider shadow-md shadow-[#E85C24]/30">
                                    <span class="relative flex h-1.5 w-1.5">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-white"></span>
                                    </span>
                                    СЕЙЧАС ИДЁТ
                                </div>
                            @endif

                            <div class="flex justify-between items-start mb-3 gap-3">
                                <div class="min-w-0 flex-1">
                                    <span class="inline-block text-[10px] font-black {{ $isCurrent ? 'text-[#E85C24] bg-[#E85C24]/10 border-[#E85C24]/30' : 'text-[#38BDF8] bg-[#38BDF8]/10 border-[#38BDF8]/20' }} px-2 py-1 rounded border {{ $hasCustomTitle ? 'mb-2' : '' }} tracking-widest uppercase">
                                        БЛОК {{ $number }}
                                    </span>
                                    @if($hasCustomTitle)
                                        <h4 class="text-base font-bold text-white leading-tight">{{ $whole->title }}</h4>
                                    @endif
                                </div>

                                @if($whole)
                                    <div class="text-right whitespace-nowrap shrink-0">
                                        @if($wholePurchased)
                                            <div class="inline-flex items-center gap-1 bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 text-xs font-black uppercase px-2.5 py-1.5 rounded tracking-wider">
                                                <i class="fas fa-check-circle"></i> Оплачено
                                            </div>
                                        @elseif($discountPercent > 0 || $finalPrice < $whole->price)
                                            <div class="text-slate-500 line-through text-xs font-medium mb-0.5 decoration-slate-600/50">
                                                {{ number_format($whole->price, 0, '.', ' ') }} ₽
                                            </div>
                                            <div class="text-xl font-black text-[#38BDF8]">
                                                {{ number_format($finalPrice, 0, '.', ' ') }} <span class="text-sm text-[#38BDF8]/70 font-medium">₽</span>
                                            </div>
                                            <div class="text-[10px] text-emerald-400 font-bold mt-1 tracking-wide uppercase">
                                                Стоимость с учётом скидки@if($discountPercent > 0) · -{{ $discountPercent }}%@endif
                                            </div>
                                        @else
                                            <div class="text-xl font-black text-white">
                                                {{ number_format($whole->price, 0, '.', ' ') }} <span class="text-sm text-slate-500 font-medium">₽</span>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            @if($whole && $whole->description)
                                <p class="text-xs text-slate-400 mb-4">{{ $whole->description }}</p>
                            @endif

                            {{-- Покупка половин блока (если такие тарифы заведены) --}}
                            @if($halves->isNotEmpty())
                                <div class="mb-4 pt-3 border-t border-[#1F2636]/80">
                                    <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2">
                                        {{ $whole ? 'Или по половинам' : 'Доступно по половинам' }}
                                    </div>
                                    <div class="space-y-2">
                                        @foreach($halves as $half)
                                            @php
                                                $halfPurchased = in_array($half->accessKey(), $purchasedKeys, true);
                                                $halfFinal = auth()->check() ? $half->calculateFinalPriceForUser(auth()->user()) : $half->price;
                                                $halfLabel = ($half->title && trim($half->title) !== $defaultBlockTitle)
                                                    ? $half->title
                                                    : $half->block_half . '-я половина';
                                            @endphp
                                            <div class="flex items-center justify-between gap-2 bg-[#0F1420]/60 rounded-lg px-3 py-2">
                                                <div class="min-w-0">
                                                    <div class="text-xs font-semibold text-slate-200 truncate">{{ $halfLabel }}</div>
                                                    @if($half->description)
                                                        <div class="text-[11px] text-slate-500 truncate">{{ $half->description }}</div>
                                                    @endif
                                                </div>
                                                <div class="shrink-0">
                                                    @if($halfPurchased)
                                                        <span class="inline-flex items-center gap-1 text-emerald-400 text-[11px] font-bold whitespace-nowrap">
                                                            <i class="fas fa-check-circle"></i> Оплачено
                                                        </span>
                                                    @else
                                                        <a href="{{ route('checkout.show', $half->id) }}"
                                                           class="inline-flex items-center gap-2 bg-[#1F2636] hover:bg-[#38BDF8] hover:text-[#0A0D14] text-white text-xs font-bold px-3 py-1.5 rounded-lg transition-colors whitespace-nowrap">
                                                            {{ number_format($halfFinal, 0, '.', ' ') }} ₽
                                                        </a>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- mt-auto прижимает кнопку к низу карточки в сетке --}}
                            <div class="mt-auto">
                                @if($blockAccessible)
                                    <a href="{{ route('student.course', $course->slug) }}"
                                       class="w-full flex justify-center items-center py-3 px-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm font-bold rounded-lg hover:bg-emerald-500 hover:text-white transition-colors">
                                        <i class="fas fa-arrow-right mr-2"></i> Перейти к блоку
                                    </a>
                                @elseif($whole)
                                    <a href="{{ route('checkout.show', $whole->id) }}"
                                       class="w-full flex justify-center items-center py-3 px-4 {{ $isCurrent ? 'bg-[#E85C24] hover:bg-[#d64e1c] text-white shadow-md shadow-[#E85C24]/20' : 'bg-[#1F2636] text-white hover:bg-[#38BDF8] hover:text-[#0A0D14]' }} text-sm font-bold rounded-lg transition-colors">
                                        {{ $halves->isNotEmpty() ? 'Оплатить блок целиком' : 'Оплатить модуль' }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

            @else
                <div class="bg-[#111622] rounded-2xl p-8 border border-[#1F2636] text-center max-w-md mx-auto">
                    <div class="w-16 h-16 bg-[#1F2636] rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-lock text-2xl text-slate-500"></i>
                    </div>
                    <h4 class="text-lg font-bold text-white mb-2">Набор закрыт</h4>
                    <p class="text-sm text-slate-400">В данный момент запись на этот курс не ведется.</p>
                </div>
            @endif
        </section>

        {{-- ───── 3. ПРЕИМУЩЕСТВА ───── --}}
        <section class="grid grid-cols-1 md:grid-cols-2 gap-6">
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
        </section>

    </div>
</div>

@include('partials.deposit-modal', ['deposit' => $deposit])
@include('partials.trial-modal')
@endsection