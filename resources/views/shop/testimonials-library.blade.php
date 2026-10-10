@extends('layouts.shop')

@section('title', 'Отзывы учеников — Общество ревнителей санскрита')

@push('head')
    <meta name="description" content="Честные отзывы учеников курсов санскрита и хинди: грамматика с М. Гасунсом, продленка с Е. Трефиловой, хинди с Е. Костиной. У каждого автора — сколько лет занимается.">
    @vite('resources/css/fonts.css')
@endpush

{{--
    /otzyvy — все опубликованные отзывы «как у Glasp» (левая половина glasp.co/signup):
    градиент индиго → голубой, белые карточки без тени, колонки по 288 px медленно едут вверх.
    Строго как у Glasp: текст целиком, без эффектов при наведении, лента не останавливается,
    без звёзд оценки. Шапка и подвал сайта остаются (layouts.shop).

    Колонки собирает скрипт под ширину (floor(ширина / 288), по кругу — каждый отзыв ровно в
    одной колонке, на телефоне одна колонка). Все колонки едут с одной скоростью (~5 px/с, как
    у Glasp) бесшовно: колонка повторена дважды, длительность = высота набора / скорость.
    Без JS и при prefers-reduced-motion — статичная сетка всех отзывов на том же фоне: она же
    источник для скрипта, её видят поисковики и читалки.
--}}
@section('content')
<style>
    .glx-stage {
        position: relative;
        background: linear-gradient(to right, #818CF8, #60A5FA);
        font-family: Inter, 'Inter Fallback', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    }
    .glx-stage.glx-live { height: 100vh; min-height: 560px; overflow: hidden; }

    /* Сетка-источник (без JS / reduced-motion / поисковики): CSS-колонки по 288 px, как у Glasp. */
    .glx-source { columns: 288px; column-gap: 0; max-width: 1728px; margin: 0 auto; padding: 20px 0 0; }
    .glx-source .glx-item { break-inside: avoid; }
    .glx-live .glx-source { display: none; }

    .glx-columns { display: none; }
    .glx-live .glx-columns { position: absolute; inset: 0; display: flex; justify-content: center; }
    .glx-col { flex: 0 0 var(--glx-col-w, 288px); width: var(--glx-col-w, 288px); }
    .glx-track { animation: glx-up var(--glx-dur, 90s) linear infinite; animation-delay: var(--glx-delay, 0s); will-change: transform; }
    @keyframes glx-up { from { transform: translate3d(0, 0, 0); } to { transform: translate3d(0, -50%, 0); } }

    /* Карточка — значения сняты с Glasp: белая, радиус 16, паддинг 20, без тени и рамки. */
    .glx-item { padding: 0 10px 20px; }
    .glx-card { background: #fff; border-radius: 16px; padding: 20px; color: #111827; }
    .glx-head { display: flex; align-items: center; gap: 12px; }
    .glx-avatar { width: 48px; height: 48px; border-radius: 9999px; object-fit: cover; flex: 0 0 48px; }
    .glx-initial { display: flex; align-items: center; justify-content: center; background: #EEF2FF; color: #4F46E5; font-size: 18px; font-weight: 500; }
    .glx-name { font-size: 16px; line-height: 20px; font-weight: 500; color: #111827; }
    .glx-sub { font-size: 12px; line-height: 17px; color: #6B7280; }
    .glx-body { margin-top: 16px; font-size: 14px; line-height: 20px; color: #111827; white-space: pre-line; overflow-wrap: anywhere; }
    .glx-media { display: inline-flex; align-items: center; gap: 6px; margin-top: 12px; font-size: 13px; font-weight: 500; color: #4F46E5; }
    .glx-media:hover { text-decoration: underline; }
</style>

<section data-analytics="testimonials-library">
    <h1 class="sr-only">Отзывы учеников</h1>

    <div class="glx-stage" id="glx-stage">
        <div class="glx-source" data-glx-source>
            @foreach ($testimonials as $t)
                <div class="glx-item">
                    <figure class="glx-card">
                        <figcaption class="glx-head">
                            @if ($t->avatar_path)
                                <img src="{{ Storage::url($t->avatar_path) }}" alt="{{ $t->author_name }}" loading="lazy" class="glx-avatar">
                            @else
                                <div class="glx-avatar glx-initial" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $t->author_name, 0, 1)) }}</div>
                            @endif
                            <div class="min-w-0">
                                <div class="glx-name truncate">{{ $t->author_name }}</div>
                                @if (filled($t->city) || $t->reviewed_at)
                                    <div class="glx-sub">
                                        {{ collect([$t->city, $t->reviewed_at?->locale('ru')->translatedFormat('j F Y')])->filter()->implode(' · ') }}
                                    </div>
                                @endif
                            </div>
                        </figcaption>
                        <blockquote class="glx-body">{{ $t->body }}</blockquote>
                        @if ($t->mediaLink())
                            <a href="{{ $t->mediaLink() }}" target="_blank" rel="noopener" class="glx-media">
                                <i class="fas fa-play-circle"></i> Смотреть/слушать отзыв
                            </a>
                        @endif
                    </figure>
                </div>
            @endforeach
        </div>

        <div class="glx-columns"></div>
    </div>
</section>
@endsection

@push('scripts')
<script>
    (function () {
        const stage = document.getElementById('glx-stage');
        if (!stage || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const source = stage.querySelector('[data-glx-source]');
        const box = stage.querySelector('.glx-columns');
        const items = Array.from(source.children);
        if (items.length < 2) return;

        const COL_W = 288;       // колонка Glasp: карточка 268 + поля 10 + 10
        const SPEED = 5.3;       // px/с — как у Glasp (30% от 1576 px за 90 с)

        // Колонки заполняют ширину от края до края, как у Glasp: число — по ширине с
        // округлением, ширина колонки растягивается (≈288 px).
        function columnCount() {
            return Math.max(1, Math.min(items.length, Math.round(stage.clientWidth / COL_W)));
        }
        function hideCopy(node) {
            // Повтор для бесконечной петли: не для читалок и не для Tab.
            node.setAttribute('aria-hidden', 'true');
            node.querySelectorAll('a').forEach(function (a) { a.tabIndex = -1; });
        }
        // Длительность каждой колонки — от её высоты: все едут с одной скоростью.
        function timeColumns(randomStart) {
            box.querySelectorAll('.glx-col').forEach(function (col) {
                const setHeight = col.querySelector('.glx-set').offsetHeight;
                const dur = Math.max(20, setHeight / SPEED);
                col.style.setProperty('--glx-dur', dur.toFixed(1) + 's');
                if (randomStart) col.style.setProperty('--glx-delay', (-(Math.random() * dur)).toFixed(1) + 's');
            });
        }

        let built = 0;
        function build() {
            const n = columnCount();
            if (n === built) return;
            built = n;
            box.innerHTML = '';
            // Одна колонка на узком экране — на всю ширину, но не шире 420.
            const colW = n === 1 ? Math.min(stage.clientWidth, 420) : Math.floor(stage.clientWidth / n);
            const buckets = Array.from({ length: n }, function () { return []; });
            items.forEach(function (item, i) { buckets[i % n].push(item); });
            stage.classList.add('glx-live');
            buckets.forEach(function (bucket) {
                // Короткую колонку дополняем её же отзывами — набор должен быть выше экрана.
                let list = bucket.slice();
                while (list.length < 4) list = list.concat(bucket);
                const col = document.createElement('div');
                col.className = 'glx-col';
                col.style.setProperty('--glx-col-w', colW + 'px');
                const track = document.createElement('div');
                track.className = 'glx-track';
                [false, true].forEach(function (copy) {
                    const set = document.createElement('div');
                    set.className = 'glx-set';
                    list.forEach(function (item, k) {
                        const clone = item.cloneNode(true);
                        if (copy || k >= bucket.length) hideCopy(clone);
                        set.appendChild(clone);
                    });
                    track.appendChild(set);
                });
                col.appendChild(track);
                box.appendChild(col);
            });
            timeColumns(true);
        }

        build();
        // Шрифт Inter приходит позже — высоты меняются, пересчитываем скорость без нового старта.
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { timeColumns(false); });
        let resizeTimer = 0;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { build(); timeColumns(false); }, 200);
        });
    })();
</script>
@endpush
