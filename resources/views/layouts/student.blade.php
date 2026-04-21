<!DOCTYPE html>
<html lang="ru" class="h-full bg-[#F4F1EA]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Обучение') | ОРС LMS</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,opsz,wght@0,6..12,200..1000;1,6..12,200..1000&display=swap" rel="stylesheet">
    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Nunito Sans', sans-serif; }
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
    
    @livewireScripts
    
</head>

{{-- x-data определяет, открыто ли меню при загрузке (на ПК открыто, на мобилке закрыто) --}}
<body class="h-full flex overflow-hidden bg-[#F4F1EA]" x-data="{ sidebarOpen: window.innerWidth >= 1024 }">
    
    @php
        $menuCourses = collect();
        if(auth()->check()) {
            $menuCourses = \App\Models\Course::where('is_visible', true)
                ->whereHas('groups', function($q) {
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
            <a href="{{ route('student.dashboard') }}" class="text-white text-2xl font-extrabold tracking-widest hover:text-[#E85C24] transition-colors">
                ОРС<span class="text-[#E85C24]">LMS</span>
            </a>
        </div>

        {{-- Навигация --}}
        <div class="flex-1 overflow-y-auto sidebar-scroll p-4 flex flex-col gap-2">
            
            {{-- Основные ссылки --}}
            <a href="{{ route('student.dashboard') }}" 
               class="{{ request()->routeIs('student.dashboard') ? 'bg-[#2C2C32] text-white border-l-2 border-[#E85C24]' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-th-large mr-3 w-5 text-center {{ request()->routeIs('student.dashboard') ? 'text-[#E85C24]' : 'text-gray-500' }}"></i> 
                Кабинет
            </a>
            
            <a href="{{ route('student.calendar') }}" 
               class="{{ request()->routeIs('student.calendar') ? 'bg-[#2C2C32] text-white border-l-2 border-[#E85C24]' : 'text-gray-400 hover:bg-[#252529] hover:text-white border-l-2 border-transparent' }} flex items-center px-4 py-3 text-sm font-bold rounded-r-xl transition-all">
                <i class="fas fa-calendar-alt mr-3 w-5 text-center {{ request()->routeIs('student.calendar') ? 'text-[#E85C24]' : 'text-gray-500' }}"></i>
                Расписание
            </a>
            {{-- Сообщения --}}
            <a href="{{ route('student.messages') }}" 
   class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all font-bold 
          {{ request()->routeIs('student.messages') ? 'bg-[#E85C24] text-white shadow-[0_4px_15px_rgba(232,92,36,0.3)]' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900' }}">
    
    <div class="relative">
        <i class="fas fa-envelope text-lg"></i>
        {{-- Красная точка-уведомление (пока фейковая, потом сделаем динамической) --}}
        <span class="absolute -top-1 -right-1.5 w-2.5 h-2.5 bg-red-500 border-2 border-white rounded-full"></span>
    </div>
    
    <span>Сообщения</span>
</a>
        
            {{-- БЛОК КУРСОВ (Спойлер/Аккордеон) --}}
            @if($menuCourses->isNotEmpty())
                <div x-data="{ coursesOpen: true }" class="mt-4 pt-4 border-t border-[#2C2C32]">
                    
                    {{-- Кнопка спойлера --}}
                    <button @click="coursesOpen = !coursesOpen" class="w-full flex items-center justify-between px-2 py-2 text-xs font-bold text-gray-500 uppercase tracking-widest hover:text-white transition-colors focus:outline-none group">
                        <span>Мои материалы</span>
                        <i class="fas fa-chevron-down text-[10px] transition-transform duration-300" :class="coursesOpen ? 'rotate-180 text-[#E85C24]' : ''"></i>
                    </button>
                    
                    {{-- Список курсов внутри спойлера --}}
                    <div x-show="coursesOpen" x-transition.opacity class="mt-2 space-y-1">
                        @foreach($menuCourses as $c)
                            @php $isActive = request()->is('course/' . $c->slug . '*'); @endphp
                            
                            <a href="{{ route('student.course', $c->slug) }}" 
                               class="{{ $isActive ? 'bg-gradient-to-r from-[#E85C24] to-[#ff7a45] text-white shadow-lg' : 'bg-[#252529] text-gray-400 hover:text-white hover:bg-[#2C2C32]' }} group flex items-center justify-between p-3 text-sm font-semibold rounded-xl transition-all border border-transparent {{ $isActive ? '' : 'hover:border-[#E85C24]/30' }}">
                                
                                <div class="flex items-center truncate pr-2">
                                    <i class="{{ $isActive ? 'fas fa-book-open text-white' : 'fas fa-book text-gray-500 group-hover:text-[#E85C24]' }} mr-3 shrink-0 transition-colors"></i>
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
                <div class="w-10 h-10 rounded-xl bg-[#E85C24] flex items-center justify-center text-white font-extrabold text-sm shadow-[0_5px_15px_rgba(232,92,36,0.3)] shrink-0">
                    {{ substr(Auth::user()->name, 0, 1) }}
                </div>
                <div class="ml-3 flex-1 overflow-hidden">
                    <p class="text-sm font-bold text-white truncate">{{ Auth::user()->name }}</p>
                    <p class="text-xs text-gray-500">Студент</p>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="ml-2">
                    @csrf
                    <button type="submit" class="w-8 h-8 flex items-center justify-center rounded-lg bg-[#252529] text-gray-400 hover:text-white hover:bg-[#E85C24] transition-colors" title="Выйти">
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
    <div class="flex flex-col flex-1 h-screen w-full transition-all duration-300 ease-in-out" 
         :class="sidebarOpen ? 'lg:pl-[280px]' : 'pl-0'">
        
        {{-- Верхняя шапка --}}
<header class="sticky top-0 z-10 shrink-0 h-20 bg-white border-b border-gray-200 shadow-sm flex items-center justify-between px-4 sm:px-8">
    
    <div class="flex items-center min-w-0">
        {{-- Кнопка "Гамбургер" (видна и на ПК) --}}
        <button @click="sidebarOpen = !sidebarOpen" class="w-10 h-10 mr-4 flex items-center justify-center rounded-xl bg-gray-50 text-gray-600 border border-gray-200 hover:text-[#E85C24] hover:bg-gray-100 active:scale-95 transition-all shrink-0">
            <i class="fas fa-bars text-lg"></i>
        </button>
        
        {{-- Заголовок страницы --}}
        <h1 class="text-xl md:text-2xl font-extrabold text-[#1A1A1A] uppercase tracking-tight truncate">
            @yield('header')
        </h1>
    </div>

    {{-- Правая часть: соцсети + аватарка на мобилках --}}
    <div class="flex items-center gap-2 md:gap-3 shrink-0">

        {{-- === СОЦИАЛЬНЫЕ СЕТИ === --}}
        <div class="hidden sm:flex items-center gap-1.5 md:gap-2">
            @php
                // Тянем конфиг один раз и фильтруем пустые
                $socials = array_filter([
                    'vk'       => ['url' => config('social.vk'),       'icon' => 'fab fa-vk',         'title' => 'ВКонтакте',  'hover' => 'hover:bg-[#0077FF]'],
                    'telegram' => ['url' => config('social.telegram'), 'icon' => 'fab fa-telegram-plane', 'title' => 'Telegram', 'hover' => 'hover:bg-[#229ED9]'],
                    'facebook' => ['url' => config('social.facebook'), 'icon' => 'fab fa-facebook-f',  'title' => 'Facebook',   'hover' => 'hover:bg-[#1877F2]'],
                    'website'  => ['url' => config('social.website'),  'icon' => 'fas fa-globe',       'title' => 'Наш сайт',   'hover' => 'hover:bg-[#E85C24]'],
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

        {{-- Аватарка для мобилок --}}
        <div class="lg:hidden">
            <div class="w-10 h-10 rounded-xl bg-[#1A1A1A] text-white flex items-center justify-center font-extrabold shadow-md">
                {{ substr(Auth::user()->name, 0, 1) }}
            </div>
        </div>
    </div>
</header>

        {{-- Основная рабочая область --}}
        <main class="flex-1 overflow-y-auto bg-[#F4F1EA] p-4 sm:p-8 relative custom-scrollbar">
            <div class="max-w-7xl mx-auto">
                @yield('content')
            </div>
        </main>
        
    </div>

</body>
</html>