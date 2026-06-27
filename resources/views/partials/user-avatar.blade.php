{{--
    Аватарка студента: реальное фото из TG/VK, если есть, иначе инициал-кружок.
    Параметры:
      $user — модель User (обязательно)
      $size — диаметр в px (по умолчанию 40)
--}}
@php
    $size = $size ?? 40;
    $url = $user?->avatarUrl();
    $initial = mb_substr($user?->name ?? 'С', 0, 1);
@endphp
@if($url)
    <img src="{{ $url }}" alt="{{ $user?->name }}"
         style="width: {{ $size }}px; height: {{ $size }}px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: #ffedd5;">
@else
    <div style="width: {{ $size }}px; height: {{ $size }}px; border-radius: 50%; background: #ffedd5; color: #ea580c; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: {{ round($size * 0.4) }}px; flex-shrink: 0;">{{ $initial }}</div>
@endif
