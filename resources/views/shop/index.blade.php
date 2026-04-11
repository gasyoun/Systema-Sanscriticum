@extends('layouts.promo') 
@section('title', 'Общество ревнителей санскрита')

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white py-16 lg:py-24 relative overflow-hidden font-sans">
    
    <div class="absolute top-0 left-1/4 w-96 h-96 bg-[#E85C24]/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        
        <div class="text-center mb-10">
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-6">
                Общество ревнителей санскрита
            </h1>
            <p class="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto leading-relaxed mb-8">
                Платформа для глубокого изучения языка, философии и текстов. Выберите курс для начала обучения.
            </p>
        </div>

        {{-- ========================================== --}}
        {{-- СТИЛЬНАЯ СТРОКА ПОИСКА --}}
        {{-- ========================================== --}}
        <div class="mb-12 max-w-2xl mx-auto relative z-20">
            <form action="" method="GET" class="relative flex items-center">
                <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                    <i class="fas fa-search text-slate-500"></i>
                </div>
                
                <input 
                    type="text" 
                    name="search" 
                    value="{{ request('search') }}" 
                    placeholder="Найти курс, например: Синтаксис санскрита..." 
                    class="w-full bg-[#111622]/80 backdrop-blur-md border border-[#1F2636] text-white pl-12 pr-36 py-4 rounded-2xl focus:outline-none focus:border-[#E85C24]/70 focus:ring-1 focus:ring-[#E85C24]/70 transition-all placeholder-slate-500 shadow-[0_4px_20px_rgba(0,0,0,0.3)]"
                >
                
                <div class="absolute inset-y-0 right-2 flex items-center space-x-2">
                    @if(request('search'))
                        <a href="{{ request()->url() }}" class="text-slate-500 hover:text-[#E85C24] px-2 transition-colors" title="Сбросить поиск">
                            <i class="fas fa-times text-lg"></i>
                        </a>
                    @endif
                    
                    <button type="submit" class="bg-[#E85C24] hover:bg-[#E85C24]/90 text-white text-sm font-bold px-6 py-2.5 rounded-xl transition-all shadow-[0_0_15px_rgba(232,92,36,0.3)]">
                        Найти
                    </button>
                </div>
            </form>
        </div>
        {{-- ========================================== --}}

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8">
            
            @forelse($courses as $course)
                <div class="relative flex flex-col bg-[#111622] rounded-2xl border border-[#1F2636] hover:border-[#E85C24]/50 hover:shadow-[0_0_30px_rgba(232,92,36,0.05)] transition-all duration-300 group">
                    
                    <a href="{{ route('shop.course.show', $course->slug) }}" class="relative w-full aspect-[4/3] bg-gradient-to-br from-slate-800 to-[#0A0D14] flex items-center justify-center border-b border-[#1F2636] overflow-hidden group/img block rounded-t-2xl">
                        @if($course->image_path)
                            <img src="{{ Storage::url($course->image_path) }}" alt="{{ $course->title }}" class="absolute inset-0 w-full h-full object-cover group-hover/img:scale-105 transition-transform duration-700 opacity-80">
                            <div class="absolute inset-0 bg-gradient-to-t from-[#111622] via-transparent to-transparent opacity-80"></div>
                        @else
                            <i class="fas fa-om text-6xl text-slate-700/30 group-hover/img:scale-110 transition-transform duration-500"></i>
                        @endif
                    </a>

                    <div class="absolute top-[52%] -left-2 bg-[#E85C24] text-white text-[10px] font-black uppercase px-3 py-1.5 rounded shadow-[0_4px_15px_rgba(232,92,36,0.6)] tracking-wider z-20">
                        {{ $course->lessons_count ? $course->lessons_count . ' лекций' : 'Онлайн-курс' }}
                    </div>

                    <div class="p-6 flex flex-col flex-grow justify-between bg-[#111622] z-10 relative rounded-b-2xl">
                        <div>
                            <div class="text-[#38BDF8] text-[10px] font-black uppercase tracking-widest mb-2 flex justify-between items-center">
                                <span>Онлайн-программа</span>
                                @if($course->hours_count)
                                    <span class="text-slate-500"><i class="far fa-clock mr-1"></i>{{ $course->hours_count }}ч</span>
                                @endif
                            </div>
                            
                            <a href="{{ route('shop.course.show', $course->slug) }}" class="block">
                                <h2 class="text-xl font-bold text-white mb-3 leading-tight group-hover:text-[#E85C24] transition-colors">
                                    {{ $course->title }}
                                </h2>
                            </a>
                            
                            @if($course->description)
                                <p class="text-sm text-slate-400 line-clamp-3 leading-relaxed mb-4">
                                    {{ Str::limit(strip_tags($course->description), 100) }}
                                </p>
                            @endif
                        </div>

                        {{-- === БЛОК С ЦЕНАМИ И СКИДКАМИ === --}}
                        <div class="mt-auto pt-5 border-t border-[#1F2636]/60">
                            @php
                                $fullTariff = $course->tariffs->where('type', '!=', 'block')->first();
                                $blockTariff = $course->tariffs->where('type', 'block')->sortBy('price')->first();
                            @endphp

                            @if($course->tariffs->count() > 0)
                                <div class="space-y-2 mb-5">
                                    {{-- ТАРИФ: ВЕСЬ КУРС --}}
                                    @if($fullTariff)
                                        @php
                                            $fullFinalPrice = auth()->check() ? $fullTariff->calculateFinalPriceForUser(auth()->user()) : $fullTariff->price;
                                            $fullDiscountPercent = auth()->check() ? $fullTariff->getDiscountPercentForUser(auth()->user()) : 0;
                                        @endphp
                                        
                                        <div class="flex justify-between items-center">
                                            <span class="text-slate-400 text-xs font-medium">Весь курс</span>
                                            <div class="text-right flex items-center justify-end flex-wrap gap-x-1.5">
                                                @if($fullFinalPrice < $fullTariff->price)
                                                    <span class="text-slate-500 line-through text-[10px] decoration-slate-600/50">{{ number_format($fullTariff->price, 0, '.', ' ') }}</span>
                                                    <span class="font-bold text-[#38BDF8] text-sm">{{ number_format($fullFinalPrice, 0, '.', ' ') }} ₽</span>
                                                    
                                                    @if($fullDiscountPercent > 0)
                                                        <span class="text-[9px] text-emerald-400 font-bold uppercase tracking-wide">-{{ $fullDiscountPercent }}%</span>
                                                    @elseif($fullFinalPrice == 0)
                                                        <span class="text-[9px] text-green-400 font-bold uppercase tracking-wide">Куплено</span>
                                                    @else
                                                        <span class="text-[9px] text-indigo-400 font-bold uppercase tracking-wide">Апгрейд</span>
                                                    @endif
                                                @else
                                                    <span class="font-bold text-white text-sm">{{ number_format($fullTariff->price, 0, '.', ' ') }} ₽</span>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                    
                                    {{-- ТАРИФ: ПО МОДУЛЯМ --}}
                                    @if($blockTariff)
                                        @php
                                            $blockFinalPrice = auth()->check() ? $blockTariff->calculateFinalPriceForUser(auth()->user()) : $blockTariff->price;
                                            $blockDiscountPercent = auth()->check() ? $blockTariff->getDiscountPercentForUser(auth()->user()) : 0;
                                        @endphp
                                        
                                        <div class="flex justify-between items-center">
                                            <span class="text-slate-400 text-xs font-medium">По модулям</span>
                                            <div class="text-right flex items-center justify-end flex-wrap gap-x-1.5">
                                                @if($blockFinalPrice < $blockTariff->price)
                                                    <span class="text-slate-500 line-through text-[10px] decoration-slate-600/50">{{ number_format($blockTariff->price, 0, '.', ' ') }}</span>
                                                    <span class="font-bold text-[#38BDF8] text-sm">{{ number_format($blockFinalPrice, 0, '.', ' ') }} ₽</span>
                                                    
                                                    @if($blockDiscountPercent > 0)
                                                        <span class="text-[9px] text-emerald-400 font-bold uppercase tracking-wide">-{{ $blockDiscountPercent }}%</span>
                                                    @endif
                                                    <span class="text-[10px] text-slate-500 font-normal">/ блок</span>
                                                @else
                                                    <span class="font-bold text-[#38BDF8] text-sm">
                                                        {{ number_format($blockTariff->price, 0, '.', ' ') }} ₽ 
                                                        <span class="text-[10px] text-slate-500 font-normal">/ блок</span>
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                </div>
                                
                                <a href="{{ route('shop.course.show', $course->slug) }}#tariffs" class="flex justify-center items-center w-full py-3 px-4 bg-[#1F2636] hover:bg-[#E85C24] text-white text-xs font-bold rounded-xl transition-all duration-300 group/btn shadow-md hover:shadow-[0_0_15px_rgba(232,92,36,0.4)] hover:-translate-y-0.5">
                                    Выбрать тариф
                                    <i class="fas fa-arrow-right ml-2 opacity-0 -translate-x-2 group-hover/btn:opacity-100 group-hover/btn:translate-x-0 transition-all duration-300"></i>
                                </a>
                            @else
                                <div class="text-center bg-[#1F2636]/30 rounded-xl py-4 mt-2 border border-[#1F2636]/50">
                                    <i class="fas fa-lock text-slate-500 mb-1"></i>
                                    <div class="text-slate-400 text-xs font-medium">Набор закрыт</div>
                                </div>
                            @endif
                        </div>
                        {{-- === КОНЕЦ БЛОКА ЦЕН === --}}
                        
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center py-20">
                    <i class="fas fa-moon text-5xl text-slate-700 mb-4"></i>
                    @if(request('search'))
                        <h3 class="text-2xl font-bold text-white mb-2">Ничего не найдено</h3>
                        <p class="text-slate-400">По запросу «{{ request('search') }}» курсов не найдено. Попробуйте изменить запрос.</p>
                    @else
                        <h3 class="text-2xl font-bold text-white mb-2">Звезды пока не сошлись</h3>
                        <p class="text-slate-400">Курсы находятся в стадии подготовки.</p>
                    @endif
                </div>
            @endforelse

        </div>

        {{-- ========================================== --}}
        {{-- БЛОК ПАГИНАЦИИ --}}
        {{-- ========================================== --}}
        @if($courses->hasPages())
            <div class="mt-16 pt-8 border-t border-[#1F2636] flex justify-center">
                {{ $courses->links('partials.pagination') }}
            </div>
        @endif

    </div>
</div>
@endsection