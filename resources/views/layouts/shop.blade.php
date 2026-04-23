{{-- resources/views/layouts/shop.blade.php --}}
<!DOCTYPE html>
<html lang="ru" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Магазин курсов') | Общество ревнителей санскрита</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">

    {{-- Tailwind + FontAwesome (в тон shop/index.blade.php) --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">

    {{-- Alpine для модалок/дропдаунов --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Nunito Sans', sans-serif; }

        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #1F2636; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #E85C24; }
    </style>

    @stack('head')
</head>

<body class="bg-[#0A0D14] text-slate-200 antialiased min-h-screen flex flex-col">

    {{-- ═══════════════ ШАПКА ═══════════════ --}}
    <header class="sticky top-0 w-full z-40 bg-[#0A0D14]/90 backdrop-blur-md border-b border-[#1F2636]">
        <div class="container mx-auto px-4 py-3 md:py-4 flex justify-between items-center gap-4">

            {{-- Логотип + бренд --}}
            <a href="{{ route('shop.index') }}" class="flex items-center gap-3 group shrink-0">
                <img src="{{ asset('images/logo.png') }}"
                     alt="Общество ревнителей санскрита"
                     class="w-auto h-10 md:h-12 object-contain group-hover:scale-105 transition-transform duration-300">
                <div class="hidden sm:flex flex-col leading-tight">
                    <span class="text-sm md:text-base font-bold text-white group-hover:text-[#E85C24] transition-colors"
                          style="font-family: 'Charter', 'Georgia', serif;">
                        Ревнители санскрита
                    </span>
                    <span class="text-[10px] md:text-xs text-slate-500 uppercase tracking-widest">
                        Магазин курсов
                    </span>
                </div>
            </a>

            {{-- Центральная навигация --}}
            <nav class="hidden md:flex items-center gap-1">
                <a href="{{ route('shop.index') }}"
                   class="px-4 py-2 text-sm font-semibold text-slate-300 hover:text-white hover:bg-[#1F2636] rounded-lg transition-all
                          {{ request()->routeIs('shop.*') ? 'text-white bg-[#1F2636]' : '' }}">
                    Все курсы
                </a>
                <a href="{{ route('articles.index') }}"
                   class="px-4 py-2 text-sm font-semibold text-slate-300 hover:text-white hover:bg-[#1F2636] rounded-lg transition-all">
                    Блог
                </a>
                <a href="/"
                   class="px-4 py-2 text-sm font-semibold text-slate-300 hover:text-white hover:bg-[#1F2636] rounded-lg transition-all">
                    О школе
                </a>
            </nav>

            {{-- Правый блок: Войти / Аккаунт --}}
            <div class="flex items-center gap-2 md:gap-3 shrink-0">
                
                {{-- Контакты для тёмной шапки магазина --}}
@include('partials.contacts-bar', ['variant' => 'dark'])

{{-- Разделитель --}}
@if(config('social.phone') || config('social.email'))
    <div class="hidden sm:block w-px h-6 bg-[#1F2636]"></div>
@endif
                @auth
                    <div x-data="{ userMenu: false }" class="relative" @click.outside="userMenu = false">
                        <button @click="userMenu = !userMenu"
                                class="flex items-center gap-2 px-3 md:px-4 py-2 rounded-xl bg-[#111622] border border-[#1F2636] hover:border-[#E85C24] transition-all">
                            <div class="w-7 h-7 rounded-lg bg-[#E85C24] text-white text-sm font-extrabold flex items-center justify-center shrink-0">
                                {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                            </div>
                            <span class="hidden sm:inline text-sm font-semibold text-white max-w-[140px] truncate">
                                {{ auth()->user()->name }}
                            </span>
                            <i class="fas fa-chevron-down text-xs text-slate-500 transition-transform"
                               :class="{ 'rotate-180': userMenu }"></i>
                        </button>

                        <div x-show="userMenu" x-cloak
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="absolute right-0 mt-2 w-56 bg-[#111622] rounded-xl shadow-2xl border border-[#1F2636] py-2 z-50">

                            <a href="{{ route('student.dashboard') }}"
                               class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-slate-300 hover:bg-[#1F2636] hover:text-[#E85C24] transition">
                                <i class="fas fa-graduation-cap w-4 text-center text-[#38BDF8]"></i>
                                Личный кабинет
                            </a>

                            <div class="my-1 border-t border-[#1F2636]"></div>

                            <button type="button"
                                    @click="shopLogout()"
                                    class="w-full flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-slate-300 hover:bg-red-500/10 hover:text-red-400 transition">
                                <i class="fas fa-sign-out-alt w-4 text-center"></i>
                                Выйти
                            </button>
                        </div>
                    </div>
                @else
    <button type="button"
            id="shop-login-trigger"
            class="inline-flex items-center gap-2 px-4 md:px-5 py-2.5 rounded-xl bg-[#E85C24] text-white text-sm font-bold hover:bg-[#d04a15] hover:shadow-lg hover:shadow-[#E85C24]/30 transition-all">
        <i class="fas fa-user text-xs"></i>
        <span>Войти</span>
    </button>
@endauth
            </div>

        </div>
    </header>

    {{-- ═══════════════ КОНТЕНТ ═══════════════ --}}
    <main class="flex-1">
        @yield('content')
    </main>

    {{-- ═══════════════ ФУТЕР ═══════════════ --}}
    <footer class="mt-auto bg-[#060810] border-t border-[#1F2636] py-10">
        <div class="container mx-auto px-4"
             x-data="{
                 openDoc: false, docTitle: '', docUrl: '',
                 viewDocument(title, url) {
                     this.docTitle = title;
                     this.docUrl = url;
                     this.openDoc = true;
                 }
             }">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-center text-center md:text-left">

                <div class="flex flex-col items-center md:items-start gap-3">
                    <img src="{{ asset('images/logo.png') }}" alt="" class="h-10 w-auto opacity-80">
                    <p class="text-xs text-slate-500 max-w-xs">
                        Глубокое изучение санскрита в традиции живых учителей.
                    </p>
                </div>

                <div class="flex flex-col items-center gap-3 text-sm">
                    <button @click="viewDocument('Политика конфиденциальности', '/docs/privacy.pdf')"
                            class="text-slate-400 hover:text-[#E85C24] transition-colors">
                        Политика конфиденциальности
                    </button>
                    <button @click="viewDocument('Публичная оферта', '/docs/oferta.pdf')"
                            class="text-slate-400 hover:text-[#E85C24] transition-colors">
                        Публичная оферта
                    </button>
                    <p class="text-xs text-slate-600 mt-2">
                        &copy; {{ date('Y') }} Все права защищены
                    </p>
                </div>

                <div class="flex items-center justify-center md:justify-end gap-2">
                    @php
                        $socials = array_filter([
                            ['url' => config('social.vk'),       'icon' => 'fab fa-vk',             'hover' => 'hover:bg-[#0077FF]'],
                            ['url' => config('social.telegram'), 'icon' => 'fab fa-telegram-plane', 'hover' => 'hover:bg-[#229ED9]'],
                            ['url' => config('social.website'),  'icon' => 'fas fa-globe',          'hover' => 'hover:bg-[#E85C24]'],
                        ], fn ($s) => !empty($s['url']));
                    @endphp

                    @foreach($socials as $s)
                        <a href="{{ $s['url'] }}" target="_blank" rel="noopener noreferrer"
                           class="w-10 h-10 flex items-center justify-center rounded-xl bg-[#111622] border border-[#1F2636] text-slate-400 {{ $s['hover'] }} hover:text-white hover:border-transparent transition-all">
                            <i class="{{ $s['icon'] }}"></i>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Модалка просмотра PDF (оферта/политика) --}}
            <div x-show="openDoc" x-cloak
                 class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm"
                 @click.self="openDoc = false"
                 @keydown.escape.window="openDoc = false">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl h-[85vh] flex flex-col overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200">
                        <h3 class="text-base font-bold text-gray-900" x-text="docTitle"></h3>
                        <button @click="openDoc = false" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 transition-colors">
                            <i class="fas fa-times text-gray-500"></i>
                        </button>
                    </div>
                    <iframe :src="docUrl" class="flex-1 w-full" frameborder="0"></iframe>
                </div>
            </div>
        </div>
    </footer>

    {{-- ═══════════════ МОДАЛКА ЛОГИНА + AJAX LOGOUT ═══════════════ --}}
    @include('partials.shop-login-modal')

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var trigger = document.getElementById('shop-login-trigger');
        if (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                window.dispatchEvent(new CustomEvent('open-shop-login'));
            });
        }
    });

    @auth
    function shopLogout() {
        fetch(@json(route('shop.logout')), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        }).finally(function () { window.location.reload(); });
    }
    @endauth
</script>

    @stack('scripts')
</body>
</html>