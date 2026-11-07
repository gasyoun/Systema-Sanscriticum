@props([
    'src' => null,
    'alt' => '',
    'width' => null,
    'height' => null,
    'sizes' => null,
    'widths' => null,
])

@php
    // Варианты ищутся по конвенции `<stem>-w<width>.webp` рядом с исходником.
    // Нет ни одного — компонент ведёт себя как обычный <img>: исходный URL,
    // никакого srcset. Это и есть безопасный деплой до первого прогона
    // `media:responsive-variants`.
    $derivatives = app(\App\Services\Media\ResponsiveDerivatives::class);
    $variantSet = is_string($src) && $src !== ''
        ? $derivatives->variantsForUrl($src, is_array($widths) ? $widths : null)
        : null;
@endphp

@if (is_string($src) && $src !== '')
    <img
        src="{{ $src }}"
        @if ($variantSet) srcset="{{ $variantSet['srcset'] }}" sizes="{{ $sizes ?? '100vw' }}" @endif
        alt="{{ $alt }}"
        @if ($width !== null) width="{{ $width }}" @endif
        @if ($height !== null) height="{{ $height }}" @endif
        {{ $attributes }}>
@endif
