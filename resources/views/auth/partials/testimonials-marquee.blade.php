{{--
    Бегущие колонки отзывов слева от формы входа (как на входе Glasp).
    Ожидает: $testimonials (Collection<Testimonial>, уже только видимые).
    Показывается только на lg+ и только если отзывов >= 3 — решает login.blade.php.

    Бесконечность без рывка: каждая колонка — лента из карточек, повторённая
    дважды, и едет на -50% своей высоты. Отступ между карточками — padding
    внутри обёртки, а не gap, иначе -50% не попадает ровно в стык.
    Интерактив: наведение на колонку ставит её на паузу, карточка чуть
    увеличивается; длинный отзыв раскрывается по клику.
--}}
@php
    // Короткий список размножаем, чтобы лента была выше экрана и не зияла дырой.
    $pool = $testimonials->values();
    while ($testimonials->isNotEmpty() && $pool->count() < 12) {
        $pool = $pool->concat($testimonials);
    }
    $columns = [[], [], []];
    foreach ($pool as $i => $t) {
        $columns[$i % 3][] = $t;
    }
    $longBody = 260;
@endphp

<style>
    .lt-col { position: relative; height: 100%; padding: 0 .75rem; }
    .lt-track {
        animation: lt-up var(--lt-dur, 60s) linear infinite;
        animation-delay: var(--lt-delay, 0s);
        will-change: transform;
    }
    .lt-col--down .lt-track { animation-direction: reverse; }
    .lt-col:hover .lt-track,
    .lt-col:focus-within .lt-track { animation-play-state: paused; }
    @keyframes lt-up {
        from { transform: translate3d(0, 0, 0); }
        to   { transform: translate3d(0, -50%, 0); }
    }
    .lt-card {
        position: relative;
        transition: transform .35s cubic-bezier(.2, .8, .2, 1), box-shadow .35s ease;
        box-shadow: 0 4px 14px rgba(0, 0, 0, .08);
    }
    .lt-card:hover, .lt-card:focus-visible {
        transform: scale(1.04);
        box-shadow: 0 18px 40px rgba(0, 0, 0, .22);
        z-index: 10;
        outline: none;
    }
    .lt-card[aria-expanded] { cursor: pointer; }
    .lt-body {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 7;
        overflow: hidden;
    }
    .lt-card[aria-expanded="true"] .lt-body { -webkit-line-clamp: unset; display: block; }
    .lt-more { transition: color .2s; }
    .lt-card[aria-expanded="true"] .lt-more-closed,
    .lt-card[aria-expanded="false"] .lt-more-open { display: none; }
    .lt-mask {
        -webkit-mask-image: linear-gradient(to bottom, transparent 0, #000 9%, #000 91%, transparent 100%);
                mask-image: linear-gradient(to bottom, transparent 0, #000 9%, #000 91%, transparent 100%);
    }
    @media (prefers-reduced-motion: reduce) {
        .lt-track { animation: none; }
        .lt-col { overflow-y: auto; }
        .lt-copy { display: none; }
        .lt-card { transition: none; }
    }
</style>

<aside data-analytics="login-testimonials" aria-label="Отзывы учеников"
       class="hidden lg:block sticky top-0 h-screen overflow-hidden"
       style="background: linear-gradient(135deg, var(--color-brand) 0%, var(--color-brand-hover) 55%, #9a3412 100%);">

    <div class="lt-mask absolute inset-0 flex px-6">
        @foreach($columns as $c => $cards)
            @php
                $dur = max(45, count($cards) * 11) + [0, 14, 7][$c];
                $delay = [0, -18, -9][$c];
            @endphp
            <div class="lt-col flex-1 min-w-0 {{ $c === 1 ? 'lt-col--down' : '' }} {{ $c === 2 ? 'hidden xl:block' : '' }}"
                 style="--lt-dur: {{ $dur }}s; --lt-delay: {{ $delay }}s;">
                <div class="lt-track">
                    @foreach([false, true] as $isCopy)
                        <div class="{{ $isCopy ? 'lt-copy' : '' }}" @if($isCopy) aria-hidden="true" @endif>
                            @foreach($cards as $t)
                                @php $isLong = mb_strlen((string) $t->body) > $longBody; @endphp
                                <div class="py-2.5">
                                    <figure class="lt-card bg-white rounded-2xl p-5 text-[#101010]"
                                            @if($isLong)
                                                aria-expanded="false" role="button"
                                                tabindex="{{ $isCopy ? '-1' : '0' }}"
                                            @endif>
                                        <figcaption class="flex items-center gap-3 mb-3">
                                            @if($t->avatar_path)
                                                <img src="{{ Storage::url($t->avatar_path) }}" alt=""
                                                     loading="lazy"
                                                     class="w-10 h-10 rounded-full object-cover shrink-0">
                                            @else
                                                <div class="w-10 h-10 rounded-full bg-brand/10 text-brand flex items-center justify-center text-sm font-bold shrink-0">
                                                    {{ mb_strtoupper(mb_substr((string) $t->author_name, 0, 1)) }}
                                                </div>
                                            @endif
                                            <div class="min-w-0">
                                                <div class="text-sm font-bold text-gray-900 truncate">{{ $t->author_name }}</div>
                                                @if(filled($t->city))
                                                    <div class="text-xs text-gray-500 truncate">{{ $t->city }}</div>
                                                @endif
                                            </div>
                                            @if($t->rating)
                                                <div class="ml-auto flex gap-0.5 text-brand shrink-0">
                                                    @for($i = 1; $i <= 5; $i++)
                                                        <i class="fa{{ $i <= $t->rating ? 's' : 'r' }} fa-star text-[10px]"></i>
                                                    @endfor
                                                </div>
                                            @endif
                                        </figcaption>
                                        <blockquote class="lt-body text-sm text-gray-700 leading-relaxed whitespace-pre-line">{{ $t->body }}</blockquote>
                                        @if($isLong)
                                            <div class="lt-more mt-2 text-xs font-bold text-brand">
                                                <span class="lt-more-closed">Читать полностью ↓</span>
                                                <span class="lt-more-open">Свернуть ↑</span>
                                            </div>
                                        @endif
                                        @if($t->media_url)
                                            <a href="{{ $t->media_url }}" target="_blank" rel="noopener"
                                               @if($isCopy) tabindex="-1" @endif
                                               class="inline-flex items-center gap-1.5 mt-3 text-xs font-bold text-brand hover:text-brand-hover">
                                                <i class="fas fa-play-circle"></i> Смотреть/слушать отзыв
                                            </a>
                                        @endif
                                    </figure>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</aside>

<script>
    // Раскрыть/свернуть длинный отзыв: клик или Enter/Пробел по карточке.
    // Клик по ссылке «Смотреть/слушать» не сворачивает карточку.
    (function () {
        function toggle(card) {
            card.setAttribute('aria-expanded', card.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
        }
        document.addEventListener('click', function (e) {
            if (e.target.closest('a')) return;
            const card = e.target.closest('.lt-card[aria-expanded]');
            if (card) toggle(card);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const card = e.target.closest && e.target.closest('.lt-card[aria-expanded]');
            if (!card || e.target !== card) return;
            e.preventDefault();
            toggle(card);
        });
    })();
</script>
