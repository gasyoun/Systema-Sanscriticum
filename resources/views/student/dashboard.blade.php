@extends('layouts.student')

@section('title', 'Личный кабинет')
@section('header', 'Личный кабинет')

@section('content')

{{-- Добавляем x-data для управления активной вкладкой --}}
<div x-data="{ activeTab: 'courses' }" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <div class="mb-8 mt-6">
        <h2 class="text-3xl md:text-4xl font-extrabold text-[#101010] tracking-tight mb-2">Добро пожаловать, {{ auth()->user()->name }}!</h2>
        <p class="text-gray-500 text-lg">Управляйте своим обучением, материалами и оплатами.</p>
    </div>

    {{-- НАВИГАЦИЯ ПО ВКЛАДКАМ (Премиум стиль) --}}
    <div class="flex space-x-6 border-b border-gray-200 mb-10 overflow-x-auto custom-scrollbar">
        <button @click="activeTab = 'courses'" 
                :class="activeTab === 'courses' ? 'text-[#E85C24] border-b-2 border-[#E85C24] font-bold' : 'text-gray-500 hover:text-gray-800 hover:border-gray-300'" 
                class="pb-3 px-1 text-base md:text-lg whitespace-nowrap transition-all outline-none">
            <i class="fas fa-graduation-cap mr-2"></i>Мои курсы
        </button>

        <button @click="activeTab = 'dictionaries'" 
                :class="activeTab === 'dictionaries' ? 'text-[#E85C24] border-b-2 border-[#E85C24] font-bold' : 'text-gray-500 hover:text-gray-800 hover:border-gray-300'" 
                class="pb-3 px-1 text-base md:text-lg whitespace-nowrap transition-all outline-none">
            <i class="fas fa-book mr-2"></i>Словари
        </button>

        <button @click="activeTab = 'payments'" 
                :class="activeTab === 'payments' ? 'text-[#E85C24] border-b-2 border-[#E85C24] font-bold' : 'text-gray-500 hover:text-gray-800 hover:border-gray-300'" 
                class="pb-3 px-1 text-base md:text-lg whitespace-nowrap transition-all outline-none">
            <i class="fas fa-wallet mr-2"></i>Мои оплаты
        </button>
    </div>

    {{-- ========================================== --}}
    {{-- ВКЛАДКА 1: МОИ КУРСЫ (Премиум карточки)    --}}
    {{-- ========================================== --}}
    <div x-show="activeTab === 'courses'" 
         x-transition:enter="transition ease-out duration-300" 
         x-transition:enter-start="opacity-0 translate-y-4" 
         x-transition:enter-end="opacity-100 translate-y-0">
         
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8 mb-16">
            @forelse($courses as $course)
                <div class="bg-white rounded-[1.5rem] shadow-sm border border-gray-100 hover:shadow-[0_20px_40px_rgba(0,0,0,0.06)] hover:border-[#E85C24]/30 hover:-translate-y-1 transition-all duration-300 flex flex-col h-full group overflow-hidden">
                    
                    {{-- Обложка курса --}}
                    <div class="h-40 bg-[#101010] relative overflow-hidden">
                        {{-- Темный абстрактный фон с оранжевым свечением, если нет картинки --}}
                        <div class="absolute inset-0 bg-[#101010] group-hover:scale-105 transition-transform duration-700">
                            <div class="absolute top-0 right-0 w-32 h-32 bg-[#E85C24] blur-[50px] opacity-40 rounded-full -mr-10 -mt-10"></div>
                            <div class="absolute bottom-0 left-0 w-24 h-24 bg-purple-500 blur-[40px] opacity-20 rounded-full -ml-10 -mb-10"></div>
                        </div>
                        
                        {{-- Затемнение снизу для читаемости --}}
                        <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                        
                        <div class="absolute bottom-4 left-5">
                            <span class="px-3 py-1 bg-white/20 backdrop-blur-md text-white text-[10px] font-bold uppercase tracking-widest rounded-md border border-white/10">
                                Курс
                            </span>
                        </div>
                    </div>

                    {{-- Тело карточки --}}
                    <div class="p-6 md:p-8 flex-1 flex flex-col bg-white relative z-10">
                        <h3 class="text-xl font-bold text-gray-900 mb-3 group-hover:text-[#E85C24] transition-colors leading-snug">
                            {{ $course->title }}
                        </h3>
                        
                        <p class="text-gray-500 text-sm line-clamp-3 mb-6 flex-1">
                            {{ $course->description }}
                        </p>

                        @php
                            $totalLessons = $course->lessons->count();
                            $completedLessons = auth()->user()->completedLessons->whereIn('id', $course->lessons->pluck('id'))->count();
                            $percent = $totalLessons > 0 ? round(($completedLessons / $totalLessons) * 100) : 0;
                        @endphp

                        <div class="mt-auto border-t border-gray-50 pt-5">
                            {{-- Прогресс-бар --}}
                            <div class="flex justify-between items-end mb-2">
                                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Прогресс</span>
                                <span class="text-sm font-extrabold text-gray-800">{{ $percent }}%</span>
                            </div>
                            
                            <div class="bg-gray-100 rounded-full h-2 w-full overflow-hidden mb-6 shadow-inner">
                                <div class="bg-[#E85C24] h-full rounded-full transition-all duration-1000 relative overflow-hidden" style="width: {{ $percent }}%">
                                    <div class="absolute inset-0 bg-white/20 w-full h-full -skew-x-12 translate-x-full animate-[shimmer_2s_infinite]"></div>
                                </div>
                            </div>

                            {{-- Кнопка --}}
                            <a href="{{ route('student.course', $course->slug) }}" class="flex items-center justify-center w-full px-4 py-3 bg-gray-50 text-gray-900 font-bold rounded-xl group-hover:bg-[#E85C24] group-hover:text-white transition-all duration-300">
                                <span>@if($percent > 0) Продолжить @else Начать обучение @endif</span>
                                <i class="fas fa-arrow-right ml-2 text-sm opacity-50 group-hover:opacity-100 group-hover:translate-x-1 transition-all"></i>
                            </a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center py-16 bg-white rounded-[2rem] border border-dashed border-gray-200 shadow-sm">
                    <div class="w-20 h-20 mx-auto bg-gray-50 rounded-full flex items-center justify-center mb-4">
                        <i class="fas fa-books text-3xl text-gray-300"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Нет доступных курсов</h3>
                    <p class="text-gray-500 max-w-md mx-auto">Вам пока не назначены курсы. Перейдите в каталог, чтобы выбрать программу обучения.</p>
                </div>
            @endforelse
        </div>

        {{-- ДОСТИЖЕНИЯ (Сертификаты) --}}
        @if($certificates->isNotEmpty())
        <div class="mb-12">
            <h3 class="text-2xl font-extrabold text-gray-900 mb-6 flex items-center">
                Мои достижения
                <div class="ml-3 px-3 py-1 bg-yellow-50 text-yellow-600 text-sm font-bold rounded-full border border-yellow-100">{{ $certificates->count() }}</div>
            </h3>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($certificates as $cert)
                    <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm flex items-center gap-4 hover:border-yellow-400 hover:shadow-md transition-all duration-300 group">
                        
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-yellow-100 to-yellow-50 text-yellow-600 flex items-center justify-center shrink-0 border border-yellow-200 group-hover:scale-110 group-hover:shadow-[0_0_15px_rgba(234,179,8,0.3)] transition-all">
                            <i class="fas fa-certificate text-xl"></i>
                        </div>
                        
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-gray-800 truncate group-hover:text-[#E85C24] transition-colors">{{ $cert->course->title }}</p>
                            <p class="text-xs font-medium text-gray-400 mt-0.5">Выдан {{ $cert->created_at->format('d.m.Y') }}</p>
                        </div>

                        <a href="{{ route('student.certificate.download', $cert->id) }}" class="w-10 h-10 rounded-full bg-gray-50 hover:bg-[#E85C24] hover:text-white flex items-center justify-center text-gray-400 transition-colors" title="Скачать PDF">
                            <i class="fas fa-download text-sm"></i>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    {{-- ========================================== --}}
    {{-- ВКЛАДКА 2: СЛОВАРИ --}}
    {{-- ========================================== --}}
    <div x-show="activeTab === 'dictionaries'" 
         style="display: none;"
         x-transition:enter="transition ease-out duration-300" 
         x-transition:enter-start="opacity-0 translate-y-4" 
         x-transition:enter-end="opacity-100 translate-y-0">
         
         @livewire('student-dictionary')
    </div>

    {{-- ========================================== --}}
    {{-- ВКЛАДКА 3: МОИ ОПЛАТЫ --}}
    {{-- ========================================== --}}
    <div x-show="activeTab === 'payments'" 
         style="display: none;"
         x-transition:enter="transition ease-out duration-300" 
         x-transition:enter-start="opacity-0 translate-y-4" 
         x-transition:enter-end="opacity-100 translate-y-0">
         
         @livewire('student-payments')
    </div>

</div>

@endsection