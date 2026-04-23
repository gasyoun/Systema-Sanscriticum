{{-- resources/views/partials/contacts-bar.blade.php --}}
@php
    $phone        = config('social.phone');
    $phoneClean   = config('social.phone_clean') ?: preg_replace('/[^+\d]/', '', (string) $phone);
    $email        = config('social.email');

    // Цветовая схема: 'light' (для светлой шапки кабинета) | 'dark' (для тёмной шапки магазина)
    $variant      = $variant ?? 'light';

    $btnBase = $variant === 'dark'
        ? 'bg-[#111622] text-slate-300 border-[#1F2636] hover:text-white hover:border-[#E85C24]'
        : 'bg-gray-50 text-gray-600 border-gray-200 hover:text-[#E85C24] hover:bg-gray-100';

    $linkText = $variant === 'dark'
        ? 'text-slate-300 hover:text-white'
        : 'text-gray-700 hover:text-[#E85C24]';
@endphp

<div class="flex items-center gap-2 md:gap-3">

    {{-- ТЕЛЕФОН --}}
    @if($phone)
        {{-- Десктоп: текст --}}
        <a href="tel:{{ $phoneClean }}"
           class="hidden lg:inline-flex items-center gap-2 text-sm font-semibold {{ $linkText }} transition-colors">
            <i class="fas fa-phone text-xs text-[#E85C24]"></i>
            <span>{{ $phone }}</span>
        </a>

        {{-- Мобилка: иконка --}}
        <a href="tel:{{ $phoneClean }}"
           title="Позвонить нам: {{ $phone }}"
           class="lg:hidden w-9 h-9 md:w-10 md:h-10 flex items-center justify-center rounded-xl border {{ $btnBase }} active:scale-95 transition-all">
            <i class="fas fa-phone text-sm"></i>
        </a>
    @endif

    {{-- EMAIL --}}
    @if($email)
        {{-- Десктоп: текст --}}
        <a href="mailto:{{ $email }}"
           class="hidden xl:inline-flex items-center gap-2 text-sm font-semibold {{ $linkText }} transition-colors">
            <i class="fas fa-envelope text-xs text-[#E85C24]"></i>
            <span>{{ $email }}</span>
        </a>

        {{-- Планшет/мобилка: иконка --}}
        <a href="mailto:{{ $email }}"
           title="Написать нам: {{ $email }}"
           class="xl:hidden w-9 h-9 md:w-10 md:h-10 flex items-center justify-center rounded-xl border {{ $btnBase }} active:scale-95 transition-all">
            <i class="fas fa-envelope text-sm"></i>
        </a>
    @endif

</div>