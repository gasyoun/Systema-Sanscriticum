@extends('layouts.shop')

@section('title', 'Отзывы учеников — Общество ревнителей санскрита')

@push('head')
    <meta name="description" content="Честные отзывы учеников курсов санскрита и хинди: грамматика с М. Гасунсом, продленка с Е. Трефиловой, хинди с Е. Костиной. У каждого автора — сколько лет занимается.">
@endpush

{{--
    /otzyvy — все опубликованные отзывы бегущими колонками, как на странице входа.
    Две темы: «Тёмная» — один в один как на входе, «Светлая» — бумажная, с теми же
    эффектами; выбор помнится в браузере.

    Колонки собирает скрипт под реальную ширину (1–5 колонок, round-robin), поэтому
    каждый отзыв виден ровно в одной колонке — на «всех отзывах» ничего не теряем
    (на входе часть колонок на узком экране скрыта — там это допустимо).
    Без JS и при prefers-reduced-motion — обычная сетка всех отзывов: она же
    источник, из которого скрипт берёт карточки, и её видят поисковики.
--}}
@section('content')
@php $longBody = 260; @endphp

<style>
    /* ── Темы: одни и те же эффекты, разные значения. Тёмная = страница входа. */
    .otz-stage {
        --otz-bg: #0d0c11;
        --otz-grain-opacity: .07;
        --otz-grain-blend: overlay;
        --otz-card: rgba(255, 255, 255, .035);
        --otz-card-hover: rgba(255, 255, 255, .075);
        --otz-border: rgba(233, 196, 125, .12);
        --otz-border-hover: rgba(233, 196, 125, .35);
        --otz-halo: 0 0 7px rgba(240, 206, 140, .09), 0 0 18px rgba(233, 196, 125, .045), inset 0 0 10px rgba(240, 206, 140, .03);
        --otz-halo-hover: 0 24px 60px -12px rgba(0, 0, 0, .7), 0 0 10px rgba(240, 206, 140, .16), 0 0 26px rgba(233, 196, 125, .08), inset 0 0 12px rgba(240, 206, 140, .05);
        --otz-spot: rgba(240, 206, 140, .09);
        --otz-rim: rgba(255, 234, 190, .9);
        --otz-name: #f3eee6;
        --otz-city: rgba(243, 238, 230, .42);
        --otz-text: rgba(243, 238, 230, .68);
        --otz-accent: #e9c47d;
        --otz-avatar-bg: linear-gradient(135deg, rgba(233, 196, 125, .22), rgba(232, 92, 36, .18));
        --otz-avatar-ink: #f1d9a8;
        --otz-avatar-border: rgba(233, 196, 125, .25);
        background-color: var(--otz-bg);
        transition: background-color .5s ease;
    }
    .otz-stage[data-theme="light"] {
        --otz-bg: #f5efe4;
        --otz-grain-opacity: .22;
        --otz-grain-blend: multiply;
        --otz-card: rgba(255, 255, 255, .72);
        --otz-card-hover: #fff;
        --otz-border: rgba(176, 128, 52, .2);
        --otz-border-hover: rgba(176, 128, 52, .5);
        --otz-halo: 0 0 7px rgba(190, 140, 60, .12), 0 0 18px rgba(190, 140, 60, .07), 0 6px 18px -10px rgba(90, 60, 20, .25);
        --otz-halo-hover: 0 24px 50px -16px rgba(90, 60, 20, .4), 0 0 10px rgba(200, 145, 55, .22), 0 0 26px rgba(200, 145, 55, .12);
        --otz-spot: rgba(214, 160, 70, .13);
        --otz-rim: rgba(200, 140, 40, .85);
        --otz-name: #2a2118;
        --otz-city: rgba(42, 33, 24, .5);
        --otz-text: rgba(42, 33, 24, .78);
        --otz-accent: #a8701a;
        --otz-avatar-bg: linear-gradient(135deg, rgba(214, 170, 90, .28), rgba(232, 92, 36, .14));
        --otz-avatar-ink: #7a5212;
        --otz-avatar-border: rgba(176, 128, 52, .3);
    }
    .otz-grain {
        opacity: var(--otz-grain-opacity);
        mix-blend-mode: var(--otz-grain-blend);
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='220' height='220'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    }

    /* ── Живой режим (скрипт собрал колонки): панель фиксированной высоты, сетка скрыта. */
    .otz-stage.otz-live { height: min(80vh, 920px); min-height: 520px; overflow: hidden; }
    .otz-stage.otz-live .otz-source { display: none; }
    .otz-columns { display: none; }
    .otz-stage.otz-live .otz-columns { display: flex; }
    .otz-mask {
        -webkit-mask-image: linear-gradient(to bottom, transparent 0, #000 10%, #000 90%, transparent 100%);
                mask-image: linear-gradient(to bottom, transparent 0, #000 10%, #000 90%, transparent 100%);
    }
    .otz-col { position: relative; height: 100%; flex: 1 1 0; min-width: 0; padding: 0 .9rem; }
    .otz-track {
        animation: otz-up var(--otz-dur, 200s) linear infinite;
        animation-delay: var(--otz-delay, 0s);
        will-change: transform;
    }
    .otz-col--down .otz-track { animation-direction: reverse; }
    .otz-col:hover .otz-track, .otz-col:focus-within .otz-track { animation-play-state: paused; }
    @keyframes otz-up { from { transform: translate3d(0, 0, 0); } to { transform: translate3d(0, -50%, 0); } }

    /* ── Карточка: те же эффекты, что на входе (свет и кромка за курсором, наклон, ореол). */
    @property --otz-hot { syntax: '<number>'; inherits: true; initial-value: 0; }
    .otz-card {
        position: relative;
        background-color: var(--otz-card);
        background-image: radial-gradient(220px circle at var(--otz-mx, 50%) var(--otz-my, 0%),
            color-mix(in srgb, var(--otz-spot) calc(var(--otz-hot) * 100%), transparent), transparent 65%);
        border: 1px solid var(--otz-border);
        box-shadow: var(--otz-halo);
        -webkit-backdrop-filter: blur(10px);
                backdrop-filter: blur(10px);
        transform: perspective(800px) rotateX(var(--otz-rx, 0deg)) rotateY(var(--otz-ry, 0deg)) scale(var(--otz-scale, 1));
        transition: transform .5s cubic-bezier(.2, .8, .2, 1), background-color .4s ease,
                    border-color .4s ease, box-shadow .4s ease, --otz-hot .45s ease;
    }
    .otz-card.otz-tracking {
        transition: transform .15s ease-out, background-color .4s ease, border-color .4s ease,
                    box-shadow .4s ease, --otz-hot .45s ease;
    }
    .otz-card::after {
        content: "";
        position: absolute; inset: -1px;
        border-radius: inherit;
        padding: 1px;
        background: radial-gradient(150px circle at var(--otz-mx, 50%) var(--otz-my, 0%),
            color-mix(in srgb, var(--otz-rim) calc(var(--otz-hot) * 100%), transparent), transparent 70%);
        -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
        -webkit-mask-composite: xor;
                mask-composite: exclude;
        pointer-events: none;
    }
    .otz-live .otz-card:hover, .otz-card.otz-on, .otz-card:focus-visible {
        --otz-scale: 1.04;
        background-color: var(--otz-card-hover);
        border-color: var(--otz-border-hover);
        box-shadow: var(--otz-halo-hover);
        z-index: 10;
        outline: none;
    }
    .otz-card[aria-expanded] { cursor: pointer; }
    .otz-name   { color: var(--otz-name); }
    .otz-city   { color: var(--otz-city); }
    .otz-text   { color: var(--otz-text); }
    .otz-accent { color: var(--otz-accent); }
    .otz-avatar { background: var(--otz-avatar-bg); color: var(--otz-avatar-ink); border: 1px solid var(--otz-avatar-border); }
    .otz-body { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 7; overflow: hidden; }
    .otz-card[aria-expanded="true"] .otz-body { -webkit-line-clamp: unset; display: block; }
    .otz-card[aria-expanded="true"] .otz-more-closed,
    .otz-card[aria-expanded="false"] .otz-more-open { display: none; }
    /* В статичной сетке текст целиком — прятать там незачем. */
    .otz-source .otz-body { -webkit-line-clamp: unset; display: block; }
    .otz-source .otz-more { display: none; }

    /* ── Переключатель темы — золотая плашка переезжает, как вкладки на входе. */
    .otz-switch { display: inline-grid; grid-template-columns: 1fr 1fr; position: relative; padding: 3px; border-radius: 11px;
                  background: rgba(255, 255, 255, .04); border: 1px solid rgba(233, 196, 125, .18); }
    .otz-switch-ind { position: absolute; top: 3px; bottom: 3px; left: 3px; width: calc(50% - 3px); border-radius: 8px;
                      background: linear-gradient(135deg, rgba(233, 196, 125, .22), rgba(232, 92, 36, .14));
                      border: 1px solid rgba(233, 196, 125, .38); transition: transform .45s cubic-bezier(.2, .8, .2, 1); }
    .otz-switch[data-theme="light"] .otz-switch-ind { transform: translateX(100%); }
    .otz-switch button { position: relative; padding: .4rem .9rem; font-size: 12px; font-weight: 600; color: rgba(243, 238, 230, .55);
                         border-radius: 8px; transition: color .3s; white-space: nowrap; }
    .otz-switch button[aria-pressed="true"] { color: #f6dca6; }
    .otz-switch button:focus-visible { outline: 2px solid rgba(233, 196, 125, .5); outline-offset: 1px; }

    @media (prefers-reduced-motion: reduce) {
        .otz-card, .otz-stage, .otz-switch-ind { transition: none; }
    }
</style>

<section class="lg:mt-6" data-analytics="testimonials-library">
    <div class="container mx-auto px-4 pt-8 pb-6 flex flex-col md:flex-row md:items-end md:justify-between gap-5">
        <div class="max-w-2xl">
            <h1 class="text-3xl font-bold text-white mb-3">Отзывы учеников</h1>
            <p class="text-slate-400">Все опубликованные отзывы — без правки: и те, кому легко, и те, кто честно пишет про трудности.
                Наведите на колонку — она остановится; нажмите на длинный отзыв, чтобы прочитать целиком.</p>
            @if (config('features.student_testimonials'))
                <a href="{{ route('student.testimonial.create') }}"
                   class="inline-flex items-center gap-2 mt-4 text-sm font-bold text-[#e9c47d] hover:text-[#f6dca6]">
                    <i class="fas fa-comment-dots"></i> Учитесь у нас? Оставьте свой отзыв
                </a>
            @endif
        </div>
        <div class="otz-switch shrink-0 self-start md:self-end" role="group" aria-label="Тема отзывов" data-theme="dark">
            <span class="otz-switch-ind" aria-hidden="true"></span>
            <button type="button" data-theme-set="dark" aria-pressed="true"><i class="fas fa-moon text-[10px] mr-1"></i> Тёмная</button>
            <button type="button" data-theme-set="light" aria-pressed="false"><i class="fas fa-sun text-[10px] mr-1"></i> Светлая</button>
        </div>
    </div>

    <div class="otz-stage relative" data-theme="dark" id="otz-stage">
        <script>
            // Тема до первой отрисовки — без вспышки тёмного при выбранной светлой.
            try { if (localStorage.getItem('otzyvy-theme') === 'light') document.getElementById('otz-stage').dataset.theme = 'light'; } catch (e) {}
        </script>

        {{-- Источник: сетка всех отзывов (без JS / reduced-motion / поисковики). --}}
        <div class="otz-source container mx-auto px-4 py-8 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5 items-start" data-otz-source>
            @foreach ($testimonials as $t)
                @php $isLong = mb_strlen((string) $t->body) > $longBody; @endphp
                <figure class="otz-card rounded-2xl p-5" @if ($isLong) aria-expanded="false" role="button" tabindex="0" @endif>
                    <figcaption class="flex items-center gap-3 mb-3">
                        @if ($t->avatar_path)
                            <img src="{{ Storage::url($t->avatar_path) }}" alt="{{ $t->author_name }}" loading="lazy"
                                 class="w-9 h-9 rounded-full object-cover shrink-0">
                        @else
                            <div class="otz-avatar w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold shrink-0">
                                {{ mb_strtoupper(mb_substr((string) $t->author_name, 0, 1)) }}
                            </div>
                        @endif
                        <div class="min-w-0">
                            <div class="otz-name text-sm font-semibold truncate">{{ $t->author_name }}</div>
                            @if (filled($t->city) || $t->reviewed_at)
                                <div class="otz-city text-xs truncate">
                                    {{ collect([$t->city, $t->reviewed_at?->locale('ru')->translatedFormat('j F Y')])->filter()->implode(' · ') }}
                                </div>
                            @endif
                        </div>
                        @if ($t->rating)
                            <div class="otz-accent ml-auto flex gap-0.5 shrink-0" aria-label="Оценка {{ $t->rating }} из 5">
                                @for ($i = 1; $i <= 5; $i++)
                                    <i class="fa{{ $i <= $t->rating ? 's' : 'r' }} fa-star text-[9px]"></i>
                                @endfor
                            </div>
                        @endif
                    </figcaption>
                    <blockquote class="otz-body otz-text text-sm leading-relaxed whitespace-pre-line">{{ $t->body }}</blockquote>
                    @if ($isLong)
                        <div class="otz-more otz-accent mt-2 text-xs font-semibold">
                            <span class="otz-more-closed">Читать полностью ↓</span>
                            <span class="otz-more-open">Свернуть ↑</span>
                        </div>
                    @endif
                    @if ($t->mediaLink())
                        <a href="{{ $t->mediaLink() }}" target="_blank" rel="noopener"
                           class="otz-accent inline-flex items-center gap-1.5 mt-3 text-xs font-semibold hover:underline">
                            <i class="fas fa-play-circle"></i> Смотреть/слушать отзыв
                        </a>
                    @endif
                </figure>
            @endforeach
        </div>

        <div class="otz-columns otz-mask absolute inset-0 px-2 sm:px-6"></div>
        <div class="otz-grain absolute inset-0 pointer-events-none"></div>
    </div>
</section>
@endsection

@push('scripts')
<script>
    (function () {
        const stage = document.getElementById('otz-stage');
        if (!stage) return;
        const switcher = document.querySelector('.otz-switch');

        // ── Тема: кнопки + память в браузере.
        function setTheme(name, remember) {
            stage.dataset.theme = name;
            switcher.dataset.theme = name;
            switcher.querySelectorAll('[data-theme-set]').forEach(function (b) {
                b.setAttribute('aria-pressed', b.dataset.themeSet === name ? 'true' : 'false');
            });
            if (remember) { try { localStorage.setItem('otzyvy-theme', name); } catch (e) {} }
        }
        setTheme(stage.dataset.theme, false);
        switcher.addEventListener('click', function (e) {
            const b = e.target.closest('[data-theme-set]');
            if (b) setTheme(b.dataset.themeSet, true);
        });

        // ── Раскрыть/свернуть длинный отзыв (в колонках; в сетке текст и так целиком).
        document.addEventListener('click', function (e) {
            if (e.target.closest('a')) return;
            const card = e.target.closest('.otz-columns .otz-card[aria-expanded]');
            if (card) card.setAttribute('aria-expanded', card.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const card = e.target.closest && e.target.closest('.otz-columns .otz-card[aria-expanded]');
            if (!card || e.target !== card) return;
            e.preventDefault();
            card.setAttribute('aria-expanded', card.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
        });

        // ── Колонки: только с анимацией; reduced-motion — остаётся сетка.
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const source = stage.querySelector('[data-otz-source]');
        const box = stage.querySelector('.otz-columns');
        const cards = Array.from(source.children);
        if (cards.length < 3) return;

        // ~26 с на карточку — тот же медленный дрейф, что на входе.
        const SPEED_ADD = [0, 42, 21, 60, 12], DELAY = [0, -54, -27, -81, -15];
        function columnCount() {
            const w = stage.clientWidth;
            const n = w >= 1536 ? 5 : w >= 1200 ? 4 : w >= 900 ? 3 : w >= 560 ? 2 : 1;
            return Math.min(n, cards.length);
        }
        function hideCopy(node) {
            // Повтор для бесконечной петли: не для читалок и не для Tab.
            node.setAttribute('aria-hidden', 'true');
            node.querySelectorAll('[tabindex], a').forEach(function (el) { el.tabIndex = -1; });
        }
        let built = 0;
        function build() {
            const n = columnCount();
            if (n === built) return;
            built = n;
            box.innerHTML = '';
            const buckets = Array.from({ length: n }, function () { return []; });
            cards.forEach(function (c, i) { buckets[i % n].push(c); });
            buckets.forEach(function (bucket, c) {
                // Короткую колонку дополняем её же отзывами — лента должна быть выше панели.
                let list = bucket.slice();
                while (list.length < 5) list = list.concat(bucket);
                const col = document.createElement('div');
                col.className = 'otz-col' + (c % 2 === 1 ? ' otz-col--down' : '');
                col.style.setProperty('--otz-dur', (Math.max(130, list.length * 26) + SPEED_ADD[c]) + 's');
                col.style.setProperty('--otz-delay', DELAY[c] + 's');
                const track = document.createElement('div');
                track.className = 'otz-track';
                [false, true].forEach(function (copy) {
                    const set = document.createElement('div');
                    list.forEach(function (card, k) {
                        const wrap = document.createElement('div');
                        wrap.className = 'py-2.5';
                        wrap.appendChild(card.cloneNode(true));
                        if (copy || k >= bucket.length) hideCopy(wrap);
                        set.appendChild(wrap);
                    });
                    track.appendChild(set);
                });
                col.appendChild(track);
                box.appendChild(col);
            });
            stage.classList.add('otz-live');
        }
        build();
        let resizeTimer = 0;
        window.addEventListener('resize', function () { clearTimeout(resizeTimer); resizeTimer = setTimeout(build, 200); });

        // Раскрытая карточка меняет высоту ленты — сворачиваем, когда курсор ушёл.
        box.addEventListener('mouseout', function (e) {
            const card = e.target.closest('.otz-card[aria-expanded="true"]');
            if (card && !card.contains(e.relatedTarget)) card.setAttribute('aria-expanded', 'false');
        });

        // ── Свет, кромка и наклон за курсором — как на входе. Только мышь.
        if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
        const PAD = 10, MAX_X = 3, MAX_Y = 4;
        let frame = 0, last = null, current = null;
        function within(card, e) {
            const r = card.getBoundingClientRect();
            const x = e.clientX - r.left, y = e.clientY - r.top;
            return x > -PAD && y > -PAD && x < r.width + PAD && y < r.height + PAD ? { r: r, x: x, y: y } : null;
        }
        function release(card) {
            card.classList.remove('otz-tracking', 'otz-on');
            card.style.setProperty('--otz-hot', '0');
            card.style.setProperty('--otz-rx', '0deg');
            card.style.setProperty('--otz-ry', '0deg');
        }
        function apply() {
            frame = 0;
            let hit = current && within(current, last);
            if (!hit) {
                const next = last.target.closest && last.target.closest('.otz-columns .otz-card');
                if (current && current !== next) release(current);
                current = next || null;
                if (!current) return;
                hit = within(current, last);
                if (!hit) return;
                current.classList.add('otz-tracking', 'otz-on');
                current.style.setProperty('--otz-hot', '1');
            }
            current.style.setProperty('--otz-mx', hit.x + 'px');
            current.style.setProperty('--otz-my', hit.y + 'px');
            const cx = Math.min(Math.max(hit.x / hit.r.width, 0), 1), cy = Math.min(Math.max(hit.y / hit.r.height, 0), 1);
            current.style.setProperty('--otz-rx', ((.5 - cy) * MAX_X).toFixed(2) + 'deg');
            current.style.setProperty('--otz-ry', ((cx - .5) * MAX_Y).toFixed(2) + 'deg');
        }
        document.addEventListener('pointermove', function (e) {
            last = e;
            if (!frame) frame = requestAnimationFrame(apply);
        }, { passive: true });
        document.documentElement.addEventListener('pointerleave', function () {
            if (current) release(current);
            current = null;
        });
    })();
</script>
@endpush
