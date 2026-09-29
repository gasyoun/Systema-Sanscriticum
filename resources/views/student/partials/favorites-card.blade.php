{{-- H5134 — «Избранное» в кабинете: личный список сердечек (курс/анонс,
     ссылка, дата). Рендерится только когда флаг course_favorites ON и
     список непустой; флаг OFF — кабинет байт-стабилен. Удаление — тем же
     toggle-эндпоинтом, строка уходит по событию favorite-toggled. --}}
@if (config('features.course_favorites', false) && isset($favorites) && $favorites->isNotEmpty())
    <div class="mb-8" id="favorites-card" data-favorites-card
         x-data="{ remove(event) { const row = event.target.closest('[data-favorite-row]'); if (row) { row.remove(); } } }"
         x-on:favorite-toggled.document="if (! $event.detail.favorited) remove($event)">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-extrabold text-gray-900 uppercase tracking-wider">
                <i class="fas fa-heart mr-2 text-rose-500"></i>Избранное
            </h2>
            <p class="text-xs text-gray-400">Направления, которые вам интересны</p>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($favorites as $favorite)
                <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center justify-between gap-2"
                     data-favorite-row="{{ $favorite->heartKey }}">
                    <div class="min-w-0">
                        @if ($favorite->url)
                            <a href="{{ $favorite->url }}"
                               class="font-bold text-gray-900 text-sm leading-snug hover:text-brand transition-colors">
                                {{ $favorite->title }}
                            </a>
                        @else
                            <p class="font-bold text-gray-900 text-sm leading-snug">{{ $favorite->title }}</p>
                        @endif
                        <p class="text-xs text-gray-500 mt-0.5">
                            {{ $favorite->createdAt?->format('d.m.Y') }}
                        </p>
                    </div>
                    @include('shop.partials.favorite-heart', [
                        'favoriteKey' => $favorite->heartKey,
                        'favorited' => true,
                    ])
                </div>
            @endforeach
        </div>
    </div>

    @include('shop.partials.favorites-script')
@endif
