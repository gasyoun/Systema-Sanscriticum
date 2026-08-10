{{-- resources/views/partials/shop-link.blade.php --}}
@php
    $variant = $variant ?? 'light';

    $base = $variant === 'dark'
        ? 'bg-brand text-white hover:bg-brand-hover'
        : 'bg-brand text-white hover:bg-brand-hover';
@endphp

{{-- Десктоп: ссылка с текстом --}}
<a href="{{ route('shop.index') }}"
   class="hidden md:inline-flex items-center gap-2 px-3 lg:px-4 py-2 rounded-xl text-sm font-bold {{ $base }} hover:shadow-lg hover:shadow-brand/30 transition-all">
    <i class="fas fa-store text-xs"></i>
    <span>Магазин</span>
</a>

{{-- Мобилка: иконка --}}
<a href="{{ route('shop.index') }}"
   title="Магазин курсов"
   class="md:hidden w-9 h-9 flex items-center justify-center rounded-xl {{ $base }} active:scale-95 transition-all">
    <i class="fas fa-store text-sm"></i>
</a>