@extends('layouts.student')

@section('title', 'Личный кабинет')
@section('header', 'Личный кабинет')

@section('content')

{{-- Добавляем x-data для управления активной вкладкой --}}
<div x-data="{ activeTab: 'courses' }" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <div class="mb-6 mt-6">
        <h2 class="text-3xl md:text-4xl font-extrabold text-[#101010] tracking-tight mb-2">Добро пожаловать, {{ auth()->user()->name }}!</h2>
        <p class="text-gray-500 text-lg">Управляйте своим обучением, материалами и оплатами.</p>
    </div>

    {{-- ========================================== --}}
    {{-- БЛОКИ БОТОВ (В ОДИН РЯД)                   --}}
    {{-- ========================================== --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        
        {{-- УМНЫЙ БЛОК TELEGRAM --}}
        <div class="h-full bg-white rounded-2xl p-5 md:p-6 border border-blue-50 shadow-[0_4px_20px_rgba(2,132,199,0.06)] flex flex-col xl:flex-row items-start xl:items-center justify-between gap-5 relative overflow-hidden">
            {{-- Декоративный фон --}}
            <div class="absolute top-0 right-0 w-40 h-40 bg-blue-400 blur-[60px] opacity-10 rounded-full -mr-10 -mt-10 pointer-events-none"></div>

            <div class="flex items-start sm:items-center gap-4 relative z-10">
                <div class="w-14 h-14 rounded-[1rem] bg-gradient-to-br from-blue-50 to-blue-100 text-[#0088cc] flex items-center justify-center shrink-0 border border-blue-200 shadow-inner">
                    {{-- Иконка Telegram (SVG) --}}
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69.01-.03.01-.14-.07-.19-.08-.05-.19-.02-.27 0-.12.03-1.96 1.25-5.54 3.67-.52.36-.99.54-1.41.53-.46-.01-1.35-.26-2.01-.48-.81-.27-1.46-.42-1.4-.88.03-.22.35-.45.96-.69 3.75-1.64 6.25-2.72 7.5-3.24 3.56-1.49 4.3-1.74 4.78-1.75.11 0 .35.03.48.14.11.08.15.22.14.36z"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900 leading-tight mb-1">Telegram-бот ОРС</h3>
                    @if(auth()->user() && auth()->user()->telegram_id)
                        <p class="text-sm text-emerald-600 font-bold flex items-center">
                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            Бот успешно подключен!
                        </p>
                    @else
                        <p class="text-sm text-gray-500 font-medium">Подключите бота, чтобы мгновенно получать доступы.</p>
                    @endif
                </div>
            </div>

            @if(!auth()->user() || !auth()->user()->telegram_id)
                <a href="{{ route('telegram.connect') }}" target="_blank" class="relative z-10 shrink-0 px-6 py-3.5 bg-[#0088cc] hover:bg-[#0077b5] text-white text-sm font-extrabold rounded-xl transition-all duration-300 shadow-[0_4px_14px_rgba(0,136,204,0.3)] hover:shadow-[0_6px_20px_rgba(0,136,204,0.4)] hover:-translate-y-0.5 flex items-center w-full sm:w-auto justify-center">
                    Подключить бота
                </a>
            @endif
        </div>

        {{-- УМНЫЙ БЛОК ВК --}}
        <div class="h-full bg-white rounded-2xl p-5 md:p-6 border border-blue-50 shadow-[0_4px_20px_rgba(0,119,255,0.06)] flex flex-col xl:flex-row items-start xl:items-center justify-between gap-5 relative overflow-hidden">
            {{-- Декоративный фон (VK Blue) --}}
            <div class="absolute top-0 right-0 w-40 h-40 bg-[#0077FF] blur-[60px] opacity-10 rounded-full -mr-10 -mt-10 pointer-events-none"></div>

            <div class="flex items-start sm:items-center gap-4 relative z-10">
                <div class="w-14 h-14 rounded-[1rem] bg-gradient-to-br from-blue-50 to-blue-100 text-[#0077FF] flex items-center justify-center shrink-0 border border-blue-200 shadow-inner">
                    {{-- Иконка VK (SVG) --}}
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24"><path d="M15.07 2H8.93C3.33 2 2 3.33 2 8.93v6.14C2 20.67 3.33 22 8.93 22h6.14c5.6 0 6.93-1.33 6.93-6.93V8.93C22 3.33 20.67 2 15.07 2zm3.33 13.91c.21.22.44.43.64.65.34.36.67.73.91 1.15.19.33.02.66-.35.66h-1.92c-.39 0-.7-.14-.95-.44-.35-.41-.7-.81-1.04-1.22-.19-.23-.39-.46-.62-.65-.24-.21-.49-.18-.68.08-.24.34-.31.73-.31 1.13 0 .4-.18.57-.59.57h-1.36c-1.63-.03-2.99-.59-4.14-1.74-1.63-1.63-2.65-3.66-3.41-5.83-.09-.25 0-.41.27-.41h1.96c.26 0 .42.14.51.39.54 1.53 1.25 2.94 2.37 4.1.18.18.35.18.47-.03.14-.26.21-.55.21-.85v-2.31c-.02-.4.18-.58.55-.58h1.07c.3 0 .43.12.48.42.04.18.04.38.04.57v1.85c0 .32.13.43.37.28.23-.15.42-.35.6-.55.45-.52.82-1.09 1.14-1.69.11-.2.25-.32.48-.32h1.9c.35 0 .47.16.38.48-.15.54-.42 1.03-.71 1.5-.39.63-.82 1.23-1.28 1.8-.26.33-.27.6 0 .93z"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900 leading-tight mb-1">ВКонтакте-бот ОРС</h3>
                    @if(auth()->user() && auth()->user()->vk_id)
                        <p class="text-sm text-emerald-600 font-bold flex items-center">
                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            Бот успешно подключен!
                        </p>
                    @else
                        <p class="text-sm text-gray-500 font-medium">Подключите бота в ВК, чтобы мгновенно получать доступы.</p>
                    @endif
                </div>
            </div>

            @if(!auth()->user() || !auth()->user()->vk_id)
                <a href="https://vk.me/club{{ env('VK_GROUP_ID') }}?ref={{ auth()->id() }}" target="_blank" class="relative z-10 shrink-0 px-6 py-3.5 bg-[#0077FF] hover:bg-[#005ce6] text-white text-sm font-extrabold rounded-xl transition-all duration-300 shadow-[0_4px_14px_rgba(0,119,255,0.3)] hover:shadow-[0_6px_20px_rgba(0,119,255,0.4)] hover:-translate-y-0.5 flex items-center w-full sm:w-auto justify-center">
                    Подключить ВК-бота
                </a>
            @endif
        </div>
        
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
         
        {{-- Идеальная сетка: 1-2-3-4 колонки. Больше 4 делать не стоит, чтобы карточки не сжимались --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mb-16">
            @forelse($courses as $course)
                <div class="bg-white rounded-2xl shadow-[0_2px_12px_rgba(0,0,0,0.04)] border border-gray-100 hover:shadow-[0_15px_35px_rgba(232,92,36,0.08)] hover:border-[#E85C24]/30 hover:-translate-y-1 transition-all duration-300 flex flex-col h-full group overflow-hidden">
                    
                    {{-- Обложка курса --}}
                    <div class="h-44 bg-[#101010] relative overflow-hidden shrink-0">
                        {{-- Темный абстрактный фон с оранжевым свечением, если нет картинки --}}
                        <div class="absolute inset-0 bg-[#101010] group-hover:scale-105 transition-transform duration-700">
                            <div class="absolute top-0 right-0 w-32 h-32 bg-[#E85C24] blur-[50px] opacity-40 rounded-full -mr-10 -mt-10"></div>
                            <div class="absolute bottom-0 left-0 w-24 h-24 bg-purple-500 blur-[40px] opacity-20 rounded-full -ml-10 -mb-10"></div>
                        </div>
                        
                        {{-- Мягкое затемнение снизу для читаемости бейджа --}}
                        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                        
                        <div class="absolute bottom-4 left-5">
                            <span class="px-2.5 py-1 bg-white/20 backdrop-blur-md text-white text-[10px] font-bold uppercase tracking-widest rounded border border-white/20">
                                Курс
                            </span>
                        </div>
                    </div>

                    {{-- Тело карточки --}}
                    <div class="p-6 flex-1 flex flex-col bg-white relative z-10">
                        <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-[#E85C24] transition-colors leading-snug line-clamp-2">
                            {{ $course->title }}
                        </h3>
                        
                        {{-- Если описание есть, выводим его. Иначе - не создаем пустую дыру --}}
                        @if(!empty($course->description))
                            <p class="text-gray-500 text-sm line-clamp-2 mb-4">
                                {{ $course->description }}
                            </p>
                        @else
                            <div class="mb-4"></div>
                        @endif

                        @php
                            $totalLessons = $course->lessons->count();
                            $completedLessons = auth()->user()->completedLessons->whereIn('id', $course->lessons->pluck('id'))->count();
                            $percent = $totalLessons > 0 ? round(($completedLessons / $totalLessons) * 100) : 0;
                        @endphp

                        {{-- Блок прогресса прижат к низу карточки благодаря mt-auto --}}
                        <div class="mt-auto pt-4 border-t border-gray-50">
                            {{-- Прогресс-бар --}}
                            <div class="flex justify-between items-end mb-2">
                                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Прогресс</span>
                                <span class="text-sm font-extrabold text-gray-800">{{ $percent }}%</span>
                            </div>
                            
                            <div class="bg-gray-100 rounded-full h-1.5 w-full overflow-hidden mb-5">
                                <div class="bg-[#E85C24] h-full rounded-full transition-all duration-1000 relative" style="width: {{ $percent }}%"></div>
                            </div>

                            {{-- Кнопка --}}
                            <a href="{{ route('student.course', $course->slug) }}" class="flex items-center justify-center w-full px-4 py-2.5 bg-gray-50 text-gray-900 text-sm font-bold rounded-xl group-hover:bg-[#E85C24] group-hover:text-white transition-all duration-300">
                                <span>@if($percent > 0) Продолжить @else Начать обучение @endif</span>
                                <i class="fas fa-arrow-right ml-2 text-xs opacity-50 group-hover:opacity-100 group-hover:translate-x-1 transition-all"></i>
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
        
    </div> {{-- Конец вкладки 1 (Мои курсы) --}}

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

</div> {{-- Конец главного x-data контейнера --}}

@endsection