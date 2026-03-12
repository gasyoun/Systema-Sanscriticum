@extends('layouts.promo') 
@section('title', 'Общество ревнителей санскрита')

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white py-16 lg:py-24 relative overflow-hidden font-sans">
    
    <div class="absolute top-0 left-1/4 w-96 h-96 bg-[#E85C24]/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        
        <div class="text-center mb-16">
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-6">
                Общество ревнителей санскрита
            </h1>
            <p class="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto leading-relaxed mb-12">
                Платформа для глубокого изучения языка, философии и текстов. Выберите курс для начала обучения.
            </p>
            <h2 class="text-3xl font-bold text-[#E85C24]">
                Наши курсы:
            </h2>
        </div>

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

                        <div class="mt-auto pt-5 border-t border-[#1F2636]/60">
                            @php
                                // Умная сортировка: ищем полный тариф и берем цену одного (любого) блока
                                $fullTariff = $course->tariffs->where('type', '!=', 'block')->first();
                                $blockTariff = $course->tariffs->where('type', 'block')->sortBy('price')->first();
                            @endphp

                            @if($course->tariffs->count() > 0)
                                <div class="space-y-2.5 mb-5">
                                    {{-- Выводим цену за весь курс, если она есть --}}
                                    @if($fullTariff)
                                        <div class="flex items-center justify-between">
                                            <span class="text-slate-400 text-xs font-medium">Весь курс</span>
                                            <span class="font-bold text-white text-sm">{{ number_format($fullTariff->price, 0, '.', ' ') }} ₽</span>
                                        </div>
                                    @endif
                                    
                                    {{-- Выводим цену за 1 блок, если блоки существуют --}}
                                    @if($blockTariff)
                                        <div class="flex items-center justify-between">
                                            <span class="text-slate-400 text-xs font-medium">По модулям</span>
                                            <span class="font-bold text-[#38BDF8] text-sm">
                                                {{ number_format($blockTariff->price, 0, '.', ' ') }} ₽ 
                                                <span class="text-[10px] text-slate-500 font-normal">/ блок</span>
                                            </span>
                                        </div>
                                    @endif
                                </div>
                                
                                {{-- Единая кнопка, ведущая к нашим новым вкладкам на странице курса --}}
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
                    </div>
                    
                </div>
            @empty
                <div class="col-span-full text-center py-20">
                    <i class="fas fa-moon text-5xl text-slate-700 mb-4"></i>
                    <h3 class="text-2xl font-bold text-white mb-2">Звезды пока не сошлись</h3>
                    <p class="text-slate-400">Курсы находятся в стадии подготовки.</p>
                </div>
            @endforelse

        </div>

    </div>
</div>
@endsection