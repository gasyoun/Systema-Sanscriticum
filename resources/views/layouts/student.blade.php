<!DOCTYPE html>
<html lang="ru" class="h-full bg-[#F4F1EA]">
<head>
    <meta charset="UTF-8">
    {{-- H4118: interactive-widget=resizes-content — клавиатура сжимает viewport вместо прокрутки под неё --}}
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content">
    {{-- H4118: светлые нативные контролы (селекты/даты/скроллбары) — НЕ тёмная тема --}}
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#E85C24">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="ОРС LMS">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/icon-180.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Обучение') | ОРС LMS</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">
    {{-- preconnect к сторонним origin: экономит по одному DNS+TLS-рукопожатию каждому --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    @include('partials.tailwind-cdn')
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,opsz,wght@0,6..12,200..1000;1,6..12,200..1000&display=swap" rel="stylesheet">
    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Nunito Sans', sans-serif; }

        /* H4118: светлый color-scheme для нативных контролов (дубль мета-тега в CSS) */
        :root { color-scheme: light; }

        /* H4118: dvh-стратегия — 100vh на iPhone Safari включает адресную/нижнюю панель */
        .h-cabinet-shell { height: 100vh; }
        @supports (height: 100dvh) { .h-cabinet-shell { height: 100dvh; } }

        /* H4118: safe-area (viewport-fit=cover) — шапка и скролл-контейнер не залезают под «чёлку» и домой-полоску */
        .sa-header {
            padding-top: env(safe-area-inset-top);
            padding-left: max(1rem, env(safe-area-inset-left));
            padding-right: max(1rem, env(safe-area-inset-right));
        }
        @media (min-width: 640px) {
            .sa-header {
                padding-left: max(2rem, env(safe-area-inset-left));
                padding-right: max(2rem, env(safe-area-inset-right));
            }
        }
        .sa-main { padding-bottom: max(1rem, env(safe-area-inset-bottom)); }
        @media (min-width: 640px) {
            .sa-main { padding-bottom: max(2rem, env(safe-area-inset-bottom)); }
        }

        /* H4118: пол 16px для форм-контролов — iOS зумит страницу при фокусе в поле с computed < 16px.
           :is() даёт специфичность (0,1,1) — перекрывает Tailwind-классы вида .text-sm (0,1,0) на самом инпуте. */
        main :is(input, select, textarea) { font-size: max(1rem, 1em); }

        /* H4118: глобальные Livewire-индикаторы (прячутся livewire-стилями из head;
           ВНИМАНИЕ: в css-комментариях внутри style нельзя писать blade-директивы —
           @-синтаксис компилируется и в комментариях и ломает <style>) */
        @keyframes h4118-lw-bar { 0% { transform: translateX(-100%); } 100% { transform: translateX(400%); } }
        .lw-loading {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            height: 3px; overflow: hidden; pointer-events: none;
        }
        .lw-loading::before {
            content: ''; display: block; height: 100%; width: 25%;
            background: #e85c24; animation: h4118-lw-bar 1.1s ease-in-out infinite;
        }
        .lw-offline {
            position: fixed; top: 0; left: 0; right: 0; z-index: 101;
            background: #dc2626; color: #fff; font-size: 14px; font-weight: 700;
            text-align: center; padding: 8px 12px;
        }

        /* Красивый скролл для темного меню */
        .sidebar-scroll::-webkit-scrollbar { width: 4px; }
        .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: #3E3E45; border-radius: 10px; }
        .sidebar-scroll::-webkit-scrollbar-thumb:hover { background: #E85C24; }
        
        /* Скролл для основного контента */
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
        
        </style>

    {{-- Livewire styles — должны быть в <head>, иначе wire:loading элементы видны до загрузки JS --}}
    @livewireStyles

    @livewireScripts
    
</head>

{{-- x-data определяет, открыто ли меню при загрузке (на ПК открыто, на мобилке закрыто) --}}
<body class="h-full flex overflow-hidden bg-[#F4F1EA]" x-data="{ sidebarOpen: window.innerWidth >= 1024 }">

    {{-- H4118: глобальные Livewire-индикаторы загрузки/оффлайна (кабинет, не Filament) --}}
    <div class="lw-loading" wire:loading></div>
    <div class="lw-offline" wire:offline>Нет подключения к интернету — изменения могут не сохраниться</div>

    @php
    $menuCourses = collect();
    if (auth()->check()) {
        // БЫЛО: is_visible — курс пропадал из меню при скрытии с витрины
        // СТАЛО: is_active — меню отражает реальный доступ студента
        $menuCourses = \App\Models\Course::where('is_active', true)
            ->whereHas('groups', function ($q) {
                $q->whereIn('groups.id', auth()->user()->groups->pluck('id'));
            })
            ->get();
    }
@endphp

    {{-- ========================================== --}}
    {{-- ЗАТЕМНЕНИЕ ФОНА НА МОБИЛКЕ               --}}
    {{-- ========================================== --}}
    <div x-show="sidebarOpen" 
         x-transition.opacity 
         class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 lg:hidden" 
         @click="sidebarOpen = false" x-cloak></div>

    {{-- ========================================== --}}
    {{-- САЙДБАР (Темный премиум дизайн)          --}}
    {{-- ========================================== --}}
    <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" 
           class="fixed inset-y-0 left-0 z-50 w-[280px] bg-[#19191C] flex flex-col transition-transform duration-300 ease-in-out shadow-[10px_0_30px_rgba(0,0,0,0.15)] border-r border-[#2C2C32]" x-cloak>
        
        {{-- Кнопка закрытия для мобилок --}}
        <div class="absolute top-0 right-0 -mr-12 pt-2 lg:hidden">
            <button @click="sidebarOpen = false" class="ml-1 flex items-center justify-center h-10 w-10 rounded-full focus:outline-none">
                <i class="fas fa-times text-white text-xl"></i>
            </button>
        </div>

        {{-- Логотип --}}
        <div class="h-20 flex items-center justify-center shrink-0 border-b border-[#2C2C32] bg-[#141417]">
            <a href="{{ route('student.dashboard') }}" class="text-white text-2xl font-extrabold tracking-widest hover:text-brand transition-colors">
                ОРС<span class="text-brand">LMS</span>
            </a>
        </div>

        {{-- Навигация --}}
        <div class="flex-1 overflow-y-auto sidebar-scroll p-4 flex flex-col gap-2">
            
            {{-- Основные ссылки — R29 job-named nav when cabinet_hybrid (H1481) --}}
            @if (config('features.cabinet_hybrid'))
            <a href="{{ route('student.dashboard') }}"
               class="{{ request()->routeIs('student.dashboard') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-sun mr-3 w-5 text-center {{ request()->routeIs('student.dashboard') ? 'text-brand' : 'text-gray-500' }}"></i>
                Сегодня
            </a>
            <a href="{{ route('student.calendar') }}"
               class="{{ request()->routeIs('student.calendar') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-calendar-alt mr-3 w-5 text-center {{ request()->routeIs('student.calendar') ? 'text-brand' : 'text-gray-500' }}"></i>
                Календарь
            </a>
            <a href="{{ route('student.library') }}"
               class="{{ request()->routeIs('student.library') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-play-circle mr-3 w-5 text-center {{ request()->routeIs('student.library') ? 'text-brand' : 'text-gray-500' }}"></i>
                Записи
            </a>
            <a href="{{ route('student.progress') }}"
               class="{{ request()->routeIs('student.progress') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-chart-line mr-3 w-5 text-center {{ request()->routeIs('student.progress') ? 'text-brand' : 'text-gray-500' }}"></i>
                Прогресс
            </a>
            <a href="{{ route('student.access') }}"
               class="{{ request()->routeIs('student.access') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-credit-card mr-3 w-5 text-center {{ request()->routeIs('student.access') ? 'text-brand' : 'text-gray-500' }}"></i>
                Оплата и доступ
            </a>
            @else
            <a href="{{ route('student.dashboard') }}"
               class="{{ request()->routeIs('student.dashboard') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-th-large mr-3 w-5 text-center {{ request()->routeIs('student.dashboard') ? 'text-brand' : 'text-gray-500' }}"></i>
                Кабинет
            </a>

            <a href="{{ route('student.calendar') }}"
               class="{{ request()->routeIs('student.calendar') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-calendar-alt mr-3 w-5 text-center {{ request()->routeIs('student.calendar') ? 'text-brand' : 'text-gray-500' }}"></i>
                Расписание
            </a>

            <a href="{{ route('student.open-lessons') }}"
               class="{{ request()->routeIs('student.open-lessons') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-lock-open mr-3 w-5 text-center {{ request()->routeIs('student.open-lessons') ? 'text-brand' : 'text-gray-500' }}"></i>
                Открытые уроки
            </a>
            @endif

            <a href="{{ route('student.help') }}"
               class="{{ request()->routeIs('student.help') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-book-open mr-3 w-5 text-center {{ request()->routeIs('student.help') ? 'text-brand' : 'text-gray-500' }}"></i>
                Как пользоваться
            </a>

            {{-- H2441 — Hindi programme playlist. Hidden while the flag is OFF. --}}
            @if (config('features.hindi_programme_playlist'))
            <a href="{{ route('student.programme.hindi') }}"
               class="{{ request()->routeIs('student.programme.hindi') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-language mr-3 w-5 text-center {{ request()->routeIs('student.programme.hindi') ? 'text-brand' : 'text-gray-500' }}"></i>
                Мой хинди
            </a>
            @endif

            {{-- Карточки SRS (H211) — только при включённом флаге srs.enabled --}}
            @if (config('srs.enabled'))
            <a href="{{ route('student.srs') }}"
               class="{{ request()->routeIs('student.srs', 'student.srs.deck') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-layer-group mr-3 w-5 text-center {{ request()->routeIs('student.srs', 'student.srs.deck') ? 'text-brand' : 'text-gray-500' }}"></i>
                Карточки
            </a>

            {{-- H1487 Wave 2 — student private-deck editor --}}
            <a href="{{ route('student.srs.decks') }}"
               class="{{ request()->routeIs('student.srs.decks') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-pen-to-square mr-3 w-5 text-center {{ request()->routeIs('student.srs.decks') ? 'text-brand' : 'text-gray-500' }}"></i>
                Мои колоды
            </a>

            {{-- H447 — статистика по карточкам, тот же флаг --}}
            <a href="{{ route('student.srs.stats') }}"
               class="{{ request()->routeIs('student.srs.stats') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-chart-line mr-3 w-5 text-center {{ request()->routeIs('student.srs.stats') ? 'text-brand' : 'text-gray-500' }}"></i>
                Статистика карточек
            </a>
            @endif

            {{-- H1680 — короткие тренажёры (не FSRS), свой флаг, независимо от srs.enabled --}}
            @if (config('features.games_skill_drills'))
            <a href="{{ route('student.skill-drills') }}"
               class="{{ request()->routeIs('student.skill-drills') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-gamepad mr-3 w-5 text-center {{ request()->routeIs('student.skill-drills') ? 'text-brand' : 'text-gray-500' }}"></i>
                Тренажёры
            </a>
            @endif

            @if (config('features.grammar_lab'))
            <a href="{{ route('student.grammar-lab.index') }}"
               class="{{ request()->routeIs('student.grammar-lab.*') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-book-open mr-3 w-5 text-center {{ request()->routeIs('student.grammar-lab.*') ? 'text-brand' : 'text-gray-500' }}"></i>
                Грамматика
            </a>
            @endif

            {{-- H2482 — native VisualDCS; shown if ANY of the three flags is on --}}
            @if (config('features.visualdcs_verb') || config('features.visualdcs_nominal') || config('features.visualdcs_passage'))
            <a href="{{ route('student.visualdcs.hub') }}"
               class="{{ request()->routeIs('student.visualdcs.*') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-language mr-3 w-5 text-center {{ request()->routeIs('student.visualdcs.*') ? 'text-brand' : 'text-gray-500' }}"></i>
                VisualDCS
            </a>
            @endif

            {{-- Помощь / Сообщения (R29 job name when hybrid) --}}
            <a href="{{ route('student.messages') }}"
               class="{{ request()->routeIs('student.messages') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-life-ring mr-3 w-5 text-center {{ request()->routeIs('student.messages') ? 'text-brand' : 'text-gray-500' }}"></i>
                {{ config('features.cabinet_hybrid') ? 'Помощь' : 'Сообщения' }}
            </a>

            {{-- H2747 — обратный звонок (Phase 0/1 телефонии). Флаг OFF по умолчанию. --}}
            @if (config('features.telephony_callback_request'))
            <a href="{{ route('student.support.callback') }}"
               class="{{ request()->routeIs('student.support.callback') ? 'bg-[#2C2C32] text-white border-l-2 border-brand' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-phone mr-3 w-5 text-center {{ request()->routeIs('student.support.callback') ? 'text-brand' : 'text-gray-500' }}"></i>
                Обратный звонок
            </a>
            @endif

            {{-- БЛОК КУРСОВ (Спойлер/Аккордеон) --}}
            @if($menuCourses->isNotEmpty())
                <div x-data="{ coursesOpen: true }" class="mt-4 pt-4 border-t border-[#2C2C32]">
                    
                    {{-- Кнопка спойлера --}}
                    <button @click="coursesOpen = !coursesOpen" class="w-full flex items-center justify-between px-2 py-2 text-xs font-bold text-gray-500 uppercase tracking-widest hover:text-white transition-colors focus:outline-none group">
                        <span>Мои материалы</span>
                        <i class="fas fa-chevron-down text-[10px] transition-transform duration-300" :class="coursesOpen ? 'rotate-180 text-brand' : ''"></i>
                    </button>
                    
                    {{-- Список курсов внутри спойлера --}}
                    <div x-show="coursesOpen" x-transition.opacity class="mt-2 space-y-1">
                        @foreach($menuCourses as $c)
                            @php $isActive = request()->is('course/' . $c->slug . '*'); @endphp
                            
                            <a href="{{ route('student.course', $c->slug) }}" 
                               class="{{ $isActive ? 'bg-gradient-to-r from-brand to-[#ff7a45] text-white shadow-lg' : 'bg-[#252529] text-gray-400 hover:text-white hover:bg-[#2C2C32]' }} group flex items-center justify-between p-3 text-sm font-semibold rounded-xl transition-all border border-transparent {{ $isActive ? '' : 'hover:border-brand/30' }}">
                                
                                <div class="flex items-center truncate pr-2">
                                    <i class="{{ $isActive ? 'fas fa-book-open text-white' : 'fas fa-book text-gray-500 group-hover:text-brand' }} mr-3 shrink-0 transition-colors"></i>
                                    <span class="truncate">{{ $c->title }}</span>
                                </div>
                                
                                @if($isActive)
                                    <div class="w-2 h-2 rounded-full bg-white shrink-0 shadow-[0_0_8px_white]"></div>
                                @endif
                            </a>
                        @endforeach
                    </div>

                </div>
            @endif
        </div>
        
        {{-- Профиль пользователя (Внизу сайдбара) --}}
        <div class="border-t border-[#2C2C32] p-4 bg-[#141417] shrink-0">
            <div class="flex items-center">
                <div class="w-10 h-10 rounded-xl bg-brand flex items-center justify-center text-white font-extrabold text-sm shadow-[0_5px_15px_rgba(232,92,36,0.3)] shrink-0">
                    {{ substr(Auth::user()->name, 0, 1) }}
                </div>
                <div class="ml-3 flex-1 overflow-hidden">
                    <p class="text-sm font-bold text-white truncate">{{ Auth::user()->name }}</p>
                    <p class="text-xs text-gray-500">Студент</p>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="ml-2">
                    @csrf
                    <button type="submit" class="w-8 h-8 flex items-center justify-center rounded-lg bg-[#252529] text-gray-400 hover:text-white hover:bg-brand transition-colors" title="Выйти">
                        <i class="fas fa-sign-out-alt"></i>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- ========================================== --}}
    {{-- ОСНОВНОЙ КОНТЕНТ (Правая часть)          --}}
    {{-- ========================================== --}}
    {{-- 
        КЛЮЧЕВОЕ ИЗМЕНЕНИЕ ДЛЯ ПК: 
        При открытом меню добавляется левый отступ (lg:pl-[280px]).
        При закрытом - отступ убирается (pl-0), и контент плавно расширяется на 100% экрана!
    --}}
    {{-- H4118: min-w-0 — иначе min-content контента растягивает flex-элемент шире вьюпорта (ox=138) --}}
    <div class="min-w-0 flex flex-col flex-1 h-cabinet-shell w-full transition-all duration-300 ease-in-out"
         :class="sidebarOpen ? 'lg:pl-[280px]' : 'pl-0'">

        {{-- Верхняя шапка --}}
<header class="sa-header sticky top-0 z-10 shrink-0 h-20 bg-white border-b border-gray-200 shadow-sm flex items-center justify-between px-4 sm:px-8">
    
    <div class="flex items-center min-w-0">
        {{-- Кнопка "Гамбургер" (видна и на ПК) --}}
        <button @click="sidebarOpen = !sidebarOpen" class="w-10 h-10 mr-4 flex items-center justify-center rounded-xl bg-gray-50 text-gray-600 border border-gray-200 hover:text-brand hover:bg-gray-100 active:scale-95 transition-all shrink-0">
            <i class="fas fa-bars text-lg"></i>
        </button>
        
        {{-- Заголовок страницы --}}
        <h1 class="text-xl md:text-2xl font-extrabold text-[#1A1A1A] uppercase tracking-tight truncate">
            @yield('header')
        </h1>
    </div>

    {{-- Правая часть: контакты + магазин + соцсети + аватарка на мобилках --}}
<div class="flex items-center gap-2 md:gap-3 shrink-0">

    {{-- === БЕЙДЖ ПРАНЫ === --}}
    @auth
        @if(\App\Services\Prana\PranaSettings::isActive())
            <a href="{{ route('student.dashboard') }}#prana"
               title="Ваш баланс праны"
               class="inline-flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-3.5 h-10 rounded-xl bg-gradient-to-br from-orange-50 to-amber-50 border border-orange-100 text-brand hover:from-orange-100 hover:to-amber-100 transition-all">
                <x-prana-lotus class="w-[1.15rem] h-[1.15rem] align-middle" />
                <span class="text-sm font-extrabold tabular-nums">{{ number_format((int) (auth()->user()->prana_balance ?? 0), 0, '.', ' ') }}</span>
            </a>
        @endif
    @endauth

    {{-- === КОНТАКТЫ (телефон + email) === --}}
    <div class="hidden sm:block">
    @include('partials.contacts-bar', ['variant' => 'light'])
    </div>

    {{-- Разделитель (только если что-то слева есть) --}}
    @if(config('social.phone') || config('social.email'))
        <div class="hidden sm:block w-px h-6 bg-gray-200"></div>
    @endif

    {{-- === КНОПКА МАГАЗИНА === --}}
    @include('partials.shop-link', ['variant' => 'light'])

    {{-- === СОЦИАЛЬНЫЕ СЕТИ === --}}
    <div class="hidden sm:flex items-center gap-1.5 md:gap-2">
        @php
            $socials = array_filter([
                'vk'       => ['url' => config('social.vk'),       'icon' => 'fab fa-vk',             'title' => 'ВКонтакте',  'hover' => 'hover:bg-[#0077FF]'],
                'telegram' => ['url' => config('social.telegram'), 'icon' => 'fab fa-telegram-plane', 'title' => 'Telegram',   'hover' => 'hover:bg-[#229ED9]'],
                'facebook' => ['url' => config('social.facebook'), 'icon' => 'fab fa-facebook-f',     'title' => 'Facebook',   'hover' => 'hover:bg-[#1877F2]'],
                'website'  => ['url' => config('social.website'),  'icon' => 'fas fa-globe',          'title' => 'Наш сайт',   'hover' => 'hover:bg-brand'],
            ], fn ($s) => !empty($s['url']));
        @endphp

        @foreach($socials as $key => $social)
            <a href="{{ $social['url'] }}"
               target="_blank"
               rel="noopener noreferrer"
               title="{{ $social['title'] }}"
               class="w-9 h-9 md:w-10 md:h-10 flex items-center justify-center rounded-xl bg-gray-50 text-gray-600 border border-gray-200 {{ $social['hover'] }} hover:text-white hover:border-transparent active:scale-95 transition-all">
                <i class="{{ $social['icon'] }} text-base"></i>
            </a>
        @endforeach
    </div>
    {{-- H1488: mobile avatar removed — sidebar profile frees header width on <375px --}}
</div>
</header>

        {{-- Основная рабочая область --}}
        <main class="sa-main flex-1 overflow-y-auto overflow-x-hidden bg-[#F4F1EA] p-4 sm:p-8 relative custom-scrollbar">
            <div class="max-w-7xl mx-auto">
                @yield('content')
            </div>
        </main>
        
    </div>

    {{-- === ТРЕКИНГ АКТИВНОСТИ === --}}
    {{-- Секция для скриптов из дочерних шаблонов (например, heartbeat с уроков) --}}
    @stack('scripts')

    {{-- Baseline-телеметрия ремейка кабинета (H962): first-party, спека §4 --}}
    @include('student.partials.telemetry')

    {{-- Глобальные скрипты (если на странице инициализируется компонент lessonHeartbeat,
         он сработает автоматически через Alpine x-data) --}}
    <script src="{{ asset('js/lesson-heartbeat.js') }}?v={{ filemtime(public_path('js/lesson-heartbeat.js')) }}" defer></script>
{{-- H1488: PWA service worker — network-first navigations, offline shell fallback --}}
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register(@json(asset('sw.js')), { scope: '/' }).catch(function () {});
            });
        }
    </script>
</body>
</html>