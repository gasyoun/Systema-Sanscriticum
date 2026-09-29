{{--
    H5134 — сердечко «Избранное» (♥ toggle, отдельно от кнопки голоса ждуна).

    props:
      favoriteKey  — «c:{course_id}» | «w:{waitlist_slug}`
      favorited    — начальное состояние (сервер: flag OFF / гость → false)
      size         — 'sm' | 'md' (по умолчанию sm)

    Требует подключённый shop.partials.favorites-script (разметка x-data
    самодостаточна: каждая кнопка — свой Alpine-компонент). Флаг OFF —
    кнопка не рендерится вовсе.
--}}
@php
    $favoriteFlagOn = config('features.course_favorites', false);
    $favoriteActive = $favorited ?? in_array($favoriteKey, ($favoriteKeys ?? []), true);
    $heartSize = ($size ?? 'sm') === 'md' ? 'text-lg' : 'text-sm';
@endphp

@if($favoriteFlagOn)
    <button type="button"
            data-favorite="{{ $favoriteKey }}"
            x-data="courseFavorite({ key: @js($favoriteKey), active: @js($favoriteActive) })"
            x-on:click.prevent="toggle($el)"
            x-on:keydown.enter.prevent="toggle($el)"
            :aria-pressed="String(active)"
            :title="active ? 'Убрать из избранного' : 'В избранное'"
            :aria-label="active ? 'Убрать из избранного' : 'В избранное'"
            class="favorite-heart inline-flex items-center justify-center transition-colors {{ $heartSize }}"
            :class="active ? 'text-rose-400 hover:text-rose-300' : 'text-slate-500 hover:text-rose-400'">
        <i class="fa-heart" :class="active ? 'fas' : 'far'"></i>
    </button>
@endif
