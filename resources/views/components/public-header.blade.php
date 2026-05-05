@props([
    'variant' => 'dark',
    'subtitle' => null,
])

@php
    $isDark = $variant === 'dark';

    $headerBg     = $isDark
        ? 'bg-[#0A0D14]/90 border-[#1F2636]'
        : 'bg-[#FAF8F5]/85 border-[#E85C24]/10 shadow-sm';
    $brandColor   = $isDark ? 'text-white' : 'text-[#1F1B16]';
    $subColor     = $isDark ? 'text-slate-500' : 'text-gray-500';
    $navIdle      = $isDark
        ? 'text-slate-300 hover:text-white hover:bg-[#1F2636]'
        : 'text-gray-700 hover:text-[#E85C24] hover:bg-[#E85C24]/5';
    $navActive    = $isDark
        ? 'text-white bg-[#1F2636]'
        : 'text-[#E85C24] bg-[#E85C24]/10';
    $btnPrimary   = 'bg-[#E85C24] text-white hover:bg-[#d04a15] hover:shadow-lg hover:shadow-[#E85C24]/30';
    $btnGhost     = $isDark
        ? 'bg-[#111622] border border-[#1F2636] text-white hover:border-[#E85C24]'
        : 'bg-white border border-[#E85C24]/20 text-[#1F1B16] hover:border-[#E85C24] hover:text-[#E85C24]';
    $iconLink     = $isDark ? 'text-slate-400 hover:text-[#E85C24]' : 'text-gray-500 hover:text-[#E85C24]';
    $iconColor    = $isDark ? 'text-[#E85C24]' : 'text-[#E85C24]';

    $isHome  = request()->is('/');
    $isShop  = request()->is('shop') || request()->is('shop/*');
    $isBlog  = request()->is('s') || request()->is('s/*');

    $shopUrl     = \Illuminate\Support\Facades\Route::has('shop.index')      ? route('shop.index')      : '/shop';
    $articlesUrl = \Illuminate\Support\Facades\Route::has('articles.index')  ? route('articles.index')  : '/s';
    $cabinetUrl  = \Illuminate\Support\Facades\Route::has('student.dashboard') ? route('student.dashboard') : '/cabinet';
@endphp

<header class="sticky top-0 w-full z-40 backdrop-blur-md border-b {{ $headerBg }}">
    <div class="container mx-auto px-4 py-3 md:py-4 flex justify-between items-center gap-3">

        <a href="/" class="flex items-center gap-3 group shrink-0 min-w-0">
            <img src="{{ asset('images/logo.png') }}"
                 alt="Общество ревнителей санскрита"
                 class="w-auto h-9 md:h-11 object-contain group-hover:scale-105 transition-transform duration-300 shrink-0">
            <div class="hidden sm:flex flex-col leading-tight min-w-0">
                <span class="text-sm md:text-base font-bold {{ $brandColor }} group-hover:text-[#E85C24] transition-colors truncate"
                      style="font-family: 'Charter', 'Bitstream Charter', 'Sitka Text', 'Georgia', serif;">
                    Ревнители санскрита
                </span>
                @if($subtitle)
                    <span class="text-[10px] md:text-xs {{ $subColor }} uppercase tracking-widest truncate">
                        {{ $subtitle }}
                    </span>
                @endif
            </div>
        </a>

        <nav class="hidden md:flex items-center gap-1">
            <a href="/"
               class="px-4 py-2 text-sm font-semibold rounded-lg transition-all {{ $isHome ? $navActive : $navIdle }}">
                Главная
            </a>
            <a href="{{ $shopUrl }}"
               class="px-4 py-2 text-sm font-semibold rounded-lg transition-all {{ $isShop ? $navActive : $navIdle }}">
                Все курсы
            </a>
            <a href="{{ $articlesUrl }}"
               class="px-4 py-2 text-sm font-semibold rounded-lg transition-all {{ $isBlog ? $navActive : $navIdle }}">
                Блог
            </a>
        </nav>

        <div x-data="{ mobileNav: false }" class="flex items-center gap-2 shrink-0">

            @auth
                <a href="{{ $cabinetUrl }}"
                   class="hidden sm:inline-flex items-center gap-2 px-3 md:px-4 py-2 rounded-xl text-sm font-bold transition-all {{ $btnGhost }}">
                    <svg class="w-4 h-4 {{ $iconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222"/>
                    </svg>
                    <span class="hidden md:inline">Кабинет</span>
                </a>
            @else
                <a href="{{ $shopUrl }}"
                   class="hidden sm:inline-flex items-center gap-2 px-4 md:px-5 py-2 rounded-xl text-sm font-bold transition-all {{ $btnPrimary }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    <span>Войти</span>
                </a>
            @endauth

            <button type="button"
                    @click="mobileNav = !mobileNav"
                    class="md:hidden w-10 h-10 flex items-center justify-center rounded-lg {{ $iconLink }} transition-colors"
                    aria-label="Меню">
                <svg x-show="!mobileNav" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
                <svg x-show="mobileNav" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>

            <div x-show="mobileNav" x-cloak
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 @click.outside="mobileNav = false"
                 class="md:hidden absolute top-full right-0 left-0 mt-0 border-t {{ $headerBg }} backdrop-blur-md">
                <nav class="container mx-auto px-4 py-3 flex flex-col gap-1">
                    <a href="/" class="px-4 py-2.5 rounded-lg text-sm font-semibold {{ $isHome ? $navActive : $navIdle }}">Главная</a>
                    <a href="{{ $shopUrl }}" class="px-4 py-2.5 rounded-lg text-sm font-semibold {{ $isShop ? $navActive : $navIdle }}">Все курсы</a>
                    <a href="{{ $articlesUrl }}" class="px-4 py-2.5 rounded-lg text-sm font-semibold {{ $isBlog ? $navActive : $navIdle }}">Блог</a>
                    <div class="border-t {{ $isDark ? 'border-[#1F2636]' : 'border-[#E85C24]/10' }} my-2"></div>
                    @auth
                        <a href="{{ $cabinetUrl }}" class="px-4 py-2.5 rounded-lg text-sm font-bold {{ $btnGhost }}">Личный кабинет</a>
                    @else
                        <a href="{{ $shopUrl }}" class="px-4 py-2.5 rounded-lg text-sm font-bold {{ $btnPrimary }} text-center">Войти</a>
                    @endauth
                </nav>
            </div>
        </div>
    </div>
</header>
