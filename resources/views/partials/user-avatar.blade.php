{{--
    Аватарка студента: реальное фото из TG/VK, если есть, иначе инициал-кружок.
    Если фото не загрузилось (битый/удалённый файл) — onerror прячет <img> и
    показывает кружок с инициалом (а не растянутый alt с полным ФИО).
    Параметры:
      $user — модель User (обязательно)
      $size — диаметр в px (по умолчанию 40)
--}}
@php
    $size = $size ?? 40;
    $url = $user?->avatarUrl();
    $initial = mb_substr($user?->name ?? 'С', 0, 1);
    $circle = "width: {$size}px; height: {$size}px; border-radius: 50%; background: #ffedd5; color: #ea580c; align-items: center; justify-content: center; font-weight: bold; font-size: ".round($size * 0.4)."px; flex-shrink: 0;";
@endphp
@if($url)
    <img src="{{ $url }}" alt=""
         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
         style="width: {{ $size }}px; height: {{ $size }}px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: #ffedd5;">
    <div style="display: none; {{ $circle }}">{{ $initial }}</div>
@else
    <div style="display: flex; {{ $circle }}">{{ $initial }}</div>
@endif
