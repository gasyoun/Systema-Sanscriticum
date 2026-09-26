{{--
    Бегущие колонки отзывов фоном на весь экран входа; карточка входа парит
    над ними по центру (механика — как на входе Glasp).
    Ожидает: $testimonials (Collection<Testimonial>, уже только видимые).
    Показывается только если отзывов >= 3 — решает login.blade.php.

    Оформление: тёмная «ночная» сцена — ровный почти чёрный тёплый фон и зерно бумаги;
    отзывы — тёмное стекло, чтобы главной оставалась белая карточка входа.
    Санскрит — одна строка золотом под формой (login.blade.php), не обои.

    Бесконечность без рывка: каждая колонка — лента из карточек, повторённая
    дважды, и едет на -50% своей высоты. Отступ между карточками — padding
    внутри обёртки, а не gap, иначе -50% не попадает ровно в стык.
    Интерактив: наведение на колонку ставит её на паузу, карточка чуть
    увеличивается; длинный отзыв раскрывается по клику.
--}}
@php
    // 4 колонки: по две слева и справа, центр пуст — там парит карточка входа.
    // Телефон: две внутренние колонки под карточкой; xl+: добавляются внешние.
    // Много отзывов — раскладываем по кругу, у каждой колонки свои.
    // Мало — каждая колонка получает весь список со своим сдвигом, иначе в
    // колонке было бы 1–2 отзыва, повторённых подряд.
    $colCount = 4;
    $list = $testimonials->values()->all();
    $n = count($list);
    $columns = array_fill(0, $colCount, []);
    if ($n >= $colCount * 3) {
        foreach ($list as $i => $t) {
            $columns[$i % $colCount][] = $t;
        }
    } else {
        for ($c = 0; $c < $colCount; $c++) {
            $shift = intdiv($c * $n, $colCount) + $c;
            for ($k = 0; $k < $n; $k++) {
                $columns[$c][] = $list[($k + $shift) % $n];
            }
        }
    }
    // Лента должна быть выше экрана.
    foreach ($columns as $c => $cards) {
        $filled = $cards;
        while ($cards !== [] && count($filled) < 5) {
            $filled = array_merge($filled, $cards);
        }
        $columns[$c] = $filled;
    }
    // Порядок в DOM: левая внешняя, левая внутренняя, [пустой центр], правая внутренняя, правая внешняя.
    $colVisibility = ['hidden xl:block', '', '', 'hidden xl:block'];
    $colSpeedAdd = [0, 14, 7, 20];
    $colDelay = [0, -18, -9, -27];
    $longBody = 260;
@endphp

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tiro+Devanagari+Sanskrit&display=swap" rel="stylesheet">

<style>
    .lt-stage { background-color: #0d0c11; }
    /* Зерно: SVG-шум — фон не выглядит плоской заливкой. */
    .lt-grain {
        opacity: .07;
        mix-blend-mode: overlay;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='220' height='220'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    }
    .lt-col { position: relative; height: 100%; padding: 0 .9rem; }
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
    /* Как у карточки входа: свет за курсором (--lt-mx/--lt-my, --lt-hot) и наклон
       (--lt-rx/--lt-ry) ставит общий скрипт внизу; увеличение — через --lt-scale. */
    .lt-card {
        position: relative;
        background-color: rgba(255, 255, 255, .035);
        background-image: radial-gradient(220px circle at var(--lt-mx, 50%) var(--lt-my, 0%),
            rgba(240, 206, 140, calc(.09 * var(--lt-hot, 0))), transparent 65%);
        border: 1px solid rgba(233, 196, 125, .12);
        /* Ореол по краям — как у карточки входа, но меньше и слабее. */
        box-shadow: 0 0 7px rgba(240, 206, 140, .09),
                    0 0 18px rgba(233, 196, 125, .045),
                    inset 0 0 10px rgba(240, 206, 140, .03);
        -webkit-backdrop-filter: blur(10px);
                backdrop-filter: blur(10px);
        transform: perspective(800px) rotateX(var(--lt-rx, 0deg)) rotateY(var(--lt-ry, 0deg)) scale(var(--lt-scale, 1));
        transition: transform .5s cubic-bezier(.2, .8, .2, 1), background-color .4s ease,
                    border-color .4s ease, box-shadow .4s ease, --lt-hot .45s ease;
    }
    .lt-card.lt-tracking {
        transition: transform .15s ease-out, background-color .4s ease,
                    border-color .4s ease, box-shadow .4s ease, --lt-hot .45s ease;
    }
    /* Кромка вспыхивает золотом там, где рядом курсор. */
    .lt-card::after {
        content: "";
        position: absolute; inset: -1px;
        border-radius: inherit;
        padding: 1px;
        background: radial-gradient(150px circle at var(--lt-mx, 50%) var(--lt-my, 0%),
            rgba(255, 234, 190, calc(.9 * var(--lt-hot, 0))), transparent 70%);
        -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
        -webkit-mask-composite: xor;
                mask-composite: exclude;
        pointer-events: none;
    }
    .lt-card:hover, .lt-card.lt-on, .lt-card:focus-visible {
        --lt-scale: 1.04;
        background-color: rgba(255, 255, 255, .075);
        border-color: rgba(233, 196, 125, .35);
        box-shadow: 0 24px 60px -12px rgba(0, 0, 0, .7),
                    0 0 10px rgba(240, 206, 140, .16),
                    0 0 26px rgba(233, 196, 125, .08),
                    inset 0 0 12px rgba(240, 206, 140, .05);
        z-index: 10;
        outline: none;
    }
    .lt-card[aria-expanded] { cursor: pointer; }
    .lt-name   { color: #f3eee6; }
    .lt-city   { color: rgba(243, 238, 230, .42); }
    .lt-text   { color: rgba(243, 238, 230, .68); }
    .lt-accent { color: #e9c47d; }
    .lt-avatar {
        background: linear-gradient(135deg, rgba(233, 196, 125, .22), rgba(232, 92, 36, .18));
        color: #f1d9a8;
        border: 1px solid rgba(233, 196, 125, .25);
    }
    .lt-body {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 7;
        overflow: hidden;
    }
    .lt-card[aria-expanded="true"] .lt-body { -webkit-line-clamp: unset; display: block; }
    .lt-card[aria-expanded="true"] .lt-more-closed,
    .lt-card[aria-expanded="false"] .lt-more-open { display: none; }
    .lt-mask {
        -webkit-mask-image: linear-gradient(to bottom, transparent 0, #000 12%, #000 88%, transparent 100%);
                mask-image: linear-gradient(to bottom, transparent 0, #000 12%, #000 88%, transparent 100%);
    }
    /* Строка молитвы под формой — золото, одна, без повторов. */
    .lt-shloka {
        font-family: 'Tiro Devanagari Sanskrit', 'Nirmala UI', 'Kohinoor Devanagari', 'Devanagari Sangam MN', serif;
        background: linear-gradient(90deg, #b8914a, #f3dca0 45%, #c9a55a 100%);
        -webkit-background-clip: text;
                background-clip: text;
        color: transparent;
    }
    .lt-hair { background: linear-gradient(90deg, transparent, rgba(233, 196, 125, .55), transparent); }
    /* Интерактивная строка: у каждого слова свой золотой градиент (общий на строке
       пропадает у слова со своим слоем — transform/filter), блик за курсором (--lt-wx
       считается от левого края слова), слово под курсором светлеет и приподнимается. */
    .lt-verse .lt-shloka { background: none; color: inherit; padding: .35em .1em; cursor: default; }
    .lt-word {
        display: inline-block;
        background:
            radial-gradient(70px 40px at var(--lt-wx, -200px) 50%, #fff8e6 0%, rgba(255, 244, 214, 0) 100%),
            linear-gradient(90deg, #b8914a, #f3dca0 50%, #c9a55a 100%);
        -webkit-background-clip: text;
                background-clip: text;
        color: transparent;
        transition: transform .35s cubic-bezier(.2, .8, .2, 1), filter .35s ease;
        border-radius: 4px;
        outline: none;
    }
    .lt-word:hover, .lt-word:focus-visible, .lt-word.lt-word-on {
        transform: translateY(-2px);
        filter: brightness(1.35) drop-shadow(0 0 6px rgba(243, 214, 150, .45));
    }
    .lt-greet { font-size: 2rem; line-height: 1.15; letter-spacing: .01em; }
    .lt-greet .lt-word { padding: 0 .04em; }
    /* В карточке входа разбор слова — в тон подписи, без капса и разрядки. */
    .lt-dark .lt-gloss.lt-word-gloss { font-size: 14px; letter-spacing: .02em; white-space: nowrap; }
    .lt-gloss { transition: opacity .25s ease, color .25s ease; min-height: 20px; line-height: 20px; }
    .lt-gloss.lt-fade { opacity: 0; }
    .lt-gloss.lt-word-gloss { color: rgba(233, 196, 125, .8); text-transform: none; letter-spacing: .03em; font-size: 14px; }
    .lt-gloss i { font-style: italic; color: #f1d9a8; }

    /* ── Карточка входа в той же гамме (класс lt-dark ставит login.blade.php,
          только вместе с этим фоном; без отзывов — прежняя светлая карточка).
          Правила вне @layer — перебивают утилиты Tailwind без правки разметки формы. */
    /* Регистрируем --lt-hot как число — тогда свет по карточке включается/гаснет плавно. */
    @property --lt-hot { syntax: '<number>'; inherits: true; initial-value: 0; }
    .lt-dark {
        --lt-gold: #e9c47d;
        --lt-ink: #f3eee6;
        /* --lt-mx/--lt-my — позиция курсора, --lt-hot — 0/1 «курсор над карточкой» (скрипт внизу). */
        background:
            radial-gradient(280px circle at var(--lt-mx, 50%) var(--lt-my, 0%),
                rgba(240, 206, 140, calc(.10 * var(--lt-hot, 0))), transparent 65%),
            linear-gradient(180deg, rgba(30, 27, 36, .86), rgba(18, 16, 23, .9));
        transform: perspective(900px) rotateX(var(--lt-rx, 0deg)) rotateY(var(--lt-ry, 0deg));
        transition: transform .6s cubic-bezier(.2, .8, .2, 1), box-shadow .4s ease, --lt-hot .45s ease;
        -webkit-backdrop-filter: blur(18px) saturate(140%);
                backdrop-filter: blur(18px) saturate(140%);
        border: 1px solid transparent;               /* кромку рисует ::after */
        box-shadow: 0 0 14px rgba(240, 206, 140, .16),
                    0 0 36px rgba(233, 196, 125, .08),
                    inset 0 0 18px rgba(240, 206, 140, .05),
                    inset 0 1px 0 rgba(255, 255, 255, .06);
        color: var(--lt-ink);
    }
    .lt-dark.lt-tracking { transition: transform .15s ease-out, box-shadow .4s ease, --lt-hot .45s ease; }
    /* Поле в фокусе — кромка и ореол чуть ярче. */
    .lt-dark:focus-within {
        box-shadow: 0 0 18px rgba(240, 206, 140, .24),
                    0 0 48px rgba(233, 196, 125, .12),
                    inset 0 0 22px rgba(240, 206, 140, .07),
                    inset 0 1px 0 rgba(255, 255, 255, .06);
    }
    /* Свечение по краям карточки: ровная золотая кромка (::after) и мягкий
       ореол, прижатый к краям (box-shadow) — без света из-под карточки. */
    .lt-dark::after {
        content: "";
        position: absolute; inset: 0;
        border-radius: inherit;
        padding: 1px;
        background:
            radial-gradient(170px circle at var(--lt-mx, 50%) var(--lt-my, 0%),
                rgba(255, 234, 190, calc(.95 * var(--lt-hot, 0))), transparent 70%),
            linear-gradient(135deg, rgba(244, 214, 156, .75), rgba(233, 196, 125, .32) 50%, rgba(244, 214, 156, .75));
        -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
        -webkit-mask-composite: xor;
                mask-composite: exclude;
        pointer-events: none;
    }
    .lt-dark > div:first-child {           /* верхняя полоса → тонкая золотая нить */
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--lt-gold), transparent);
    }
    .lt-dark .bg-brand\/10 {               /* кружок с иконкой */
        background: linear-gradient(135deg, rgba(233, 196, 125, .2), rgba(232, 92, 36, .16));
        border: 1px solid rgba(233, 196, 125, .28);
        color: #f1d9a8;
    }
    .lt-dark h2 { color: var(--lt-ink); letter-spacing: -.01em; }
    .lt-dark .text-gray-400,
    .lt-dark .text-gray-500,
    .lt-dark .text-gray-600 { color: rgba(243, 238, 230, .5); }
    .lt-dark label.uppercase { color: rgba(233, 196, 125, .6); }
    .lt-dark input[type="email"],
    .lt-dark input[type="password"],
    .lt-dark input[type="text"],
    .lt-dark input[type="tel"] {
        background: rgba(255, 255, 255, .04);
        border-color: rgba(255, 255, 255, .1);
        color: var(--lt-ink);
    }
    .lt-dark input::placeholder { color: rgba(243, 238, 230, .28); }
    .lt-dark input[type="email"]:focus,
    .lt-dark input[type="password"]:focus,
    .lt-dark input[type="text"]:focus,
    .lt-dark input[type="tel"]:focus {
        background: rgba(255, 255, 255, .07);
        border-color: rgba(233, 196, 125, .7);
        box-shadow: 0 0 0 3px rgba(233, 196, 125, .12);
    }
    /* Автозаполнение Chrome красит поле в белый — возвращаем тёмное. */
    .lt-dark input:-webkit-autofill {
        -webkit-text-fill-color: var(--lt-ink);
        -webkit-box-shadow: 0 0 0 1000px #1d1a23 inset;
        caret-color: var(--lt-ink);
    }
    .lt-dark .fa-envelope, .lt-dark .fa-lock, .lt-dark .lt-ico { color: rgba(233, 196, 125, .45); }

    /* ── Вкладки «Войти / Регистрация»: золотая плашка переезжает под активную. */
    .lt-tabs {
        position: relative;
        display: grid;
        grid-template-columns: 1fr 1fr;
        padding: 3px;
        border-radius: 11px;
        background: rgba(255, 255, 255, .035);
        border: 1px solid rgba(233, 196, 125, .14);
    }
    .lt-tab-ind {
        position: absolute; top: 3px; bottom: 3px; left: 3px;
        width: calc(50% - 3px);
        border-radius: 8px;
        background: linear-gradient(135deg, rgba(233, 196, 125, .22), rgba(232, 92, 36, .14));
        border: 1px solid rgba(233, 196, 125, .38);
        box-shadow: 0 0 12px rgba(240, 206, 140, .12);
        transition: transform .45s cubic-bezier(.2, .8, .2, 1);
    }
    .lt-tabs[data-active="register"] .lt-tab-ind { transform: translateX(100%); }
    .lt-tabs button {
        position: relative;
        padding: .45rem .5rem;
        font-size: 12px; font-weight: 600; letter-spacing: .02em;
        color: rgba(243, 238, 230, .5);
        border-radius: 8px;
        transition: color .3s ease;
    }
    .lt-tabs button:hover { color: rgba(243, 238, 230, .8); }
    .lt-tabs button[aria-selected="true"] { color: #f6dca6; }
    .lt-tabs button:focus-visible { outline: 2px solid rgba(233, 196, 125, .5); outline-offset: 1px; }
    /* Разбор слова приветствия всплывает над вкладками, вёрстку не двигает. */
    .lt-verse:has(.lt-gloss-float) { position: relative; }
    .lt-gloss-float {
        position: absolute; left: 0; right: 0; top: calc(100% + 8px);   /* ровно посередине зазора до вкладок (mt-9) */
        pointer-events: none;
        white-space: nowrap;
    }
    /* Смена панели: высота карточки едет плавно (скрипт), содержимое проявляется. */
    .lt-dark.lt-resizing { transition: height .4s cubic-bezier(.2, .8, .2, 1), transform .6s cubic-bezier(.2, .8, .2, 1), box-shadow .4s ease; }
    .lt-pane-in { animation: lt-pane-in .45s cubic-bezier(.2, .8, .2, 1); }
    @keyframes lt-pane-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) {
        .lt-tab-ind, .lt-dark.lt-resizing { transition: none; }
        .lt-pane-in { animation: none; }
    }
    .lt-dark input[type="checkbox"] {
        -webkit-appearance: none;
                appearance: none;
        width: 15px; height: 15px;
        border: 1px solid rgba(233, 196, 125, .4);
        border-radius: 4px;
        background: rgba(255, 255, 255, .04) center / 11px no-repeat;
        cursor: pointer;
        transition: background-color .2s, border-color .2s;
    }
    .lt-dark input[type="checkbox"]:checked {
        background-color: var(--lt-gold);
        border-color: var(--lt-gold);
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath d='M3.5 8.5l3 3 6-7' fill='none' stroke='%231a1720' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    }
    .lt-dark input[type="checkbox"]:focus-visible { outline: 2px solid rgba(233, 196, 125, .5); outline-offset: 2px; }
    .lt-dark button[type="submit"] {
        background: linear-gradient(135deg, #f0803f 0%, #e85c24 50%, #c9461a 100%);
        box-shadow: 0 12px 30px -10px rgba(232, 92, 36, .7), inset 0 1px 0 rgba(255, 255, 255, .25);
        letter-spacing: .14em;
    }
    .lt-dark button[type="submit"]:hover {
        filter: brightness(1.08);
        box-shadow: 0 16px 40px -10px rgba(232, 92, 36, .85), inset 0 1px 0 rgba(255, 255, 255, .3);
    }
    /* Блик по кнопке на наведение. */
    .lt-dark button[type="submit"] { position: relative; overflow: hidden; }
    .lt-dark button[type="submit"]::after {
        content: "";
        position: absolute; top: 0; bottom: 0; left: -60%;
        width: 45%;
        background: linear-gradient(100deg, transparent, rgba(255, 255, 255, .35), transparent);
        transform: skewX(-18deg);
        pointer-events: none;
    }
    .lt-dark button[type="submit"]:hover::after { animation: lt-sheen .9s ease-out; }
    @keyframes lt-sheen { to { left: 125%; } }
    @media (prefers-reduced-motion: reduce) {
        .lt-dark { transform: none !important; }
        .lt-dark button[type="submit"]:hover::after { animation: none; }
    }
    .lt-dark .bg-gray-50\/80 {              /* подвал карточки */
        background: rgba(0, 0, 0, .22);
        border-color: rgba(255, 255, 255, .06);
    }
    .lt-dark .text-brand { color: var(--lt-gold); }
    .lt-dark a.text-brand:hover { color: #f6dca6; }
    .lt-dark .border-brand { border-color: rgba(233, 196, 125, .5); }
    .lt-dark .border-gray-200 { border-color: rgba(255, 255, 255, .1); }
    .lt-dark .bg-white { background: rgba(255, 255, 255, .04); }       /* соцкнопки */
    .lt-dark .text-gray-700 { color: rgba(243, 238, 230, .8); }
    .lt-dark .bg-green-50 { background: rgba(34, 197, 94, .1); border-color: rgba(34, 197, 94, .28); color: #86efac; }
    .lt-dark .bg-red-50   { background: rgba(239, 68, 68, .1); border-color: rgba(239, 68, 68, .3);  color: #fca5a5; }
    .lt-dark .text-red-500 { color: #fca5a5; }
    @media (prefers-reduced-motion: reduce) {
        .lt-track { animation: none; }
        .lt-col { overflow-y: auto; }
        .lt-copy { display: none; }
        .lt-card { transition: none; }
    }
</style>

<aside data-analytics="login-testimonials" aria-label="Отзывы учеников"
       class="lt-stage fixed inset-0 overflow-hidden">

    <div class="lt-mask absolute inset-0 flex px-2 sm:px-6">
        @foreach($columns as $c => $cards)
            @if ($c === 2)
                {{-- Пустой центр под карточку входа (на телефоне карточка и так на всю ширину). --}}
                <div class="hidden sm:block w-[380px] shrink-0" aria-hidden="true"></div>
            @endif
            @php
                $dur = max(130, count($cards) * 26) + $colSpeedAdd[$c] * 3; // ~26 с на карточку — медленный дрейф, читать успеваешь
                $delay = $colDelay[$c];
            @endphp
            <div class="lt-col flex-1 min-w-0 {{ $c % 2 === 1 ? 'lt-col--down' : '' }} {{ $colVisibility[$c] }}"
                 style="--lt-dur: {{ $dur }}s; --lt-delay: {{ $delay }}s;">
                <div class="lt-track">
                    @foreach([false, true] as $isCopy)
                        <div class="{{ $isCopy ? 'lt-copy' : '' }}" @if($isCopy) aria-hidden="true" @endif>
                            @foreach($cards as $t)
                                @php $isLong = mb_strlen((string) $t->body) > $longBody; @endphp
                                <div class="py-2.5">
                                    <figure class="lt-card rounded-2xl p-5"
                                            @if($isLong)
                                                aria-expanded="false" role="button"
                                                tabindex="{{ $isCopy ? '-1' : '0' }}"
                                            @endif>
                                        <figcaption class="flex items-center gap-3 mb-3">
                                            @if($t->avatar_path)
                                                <img src="{{ Storage::url($t->avatar_path) }}" alt=""
                                                     loading="lazy"
                                                     class="w-9 h-9 rounded-full object-cover shrink-0 ring-1 ring-white/15">
                                            @else
                                                <div class="lt-avatar w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold shrink-0">
                                                    {{ mb_strtoupper(mb_substr((string) $t->author_name, 0, 1)) }}
                                                </div>
                                            @endif
                                            <div class="min-w-0">
                                                <div class="lt-name text-sm font-semibold truncate">{{ $t->author_name }}</div>
                                                @if(filled($t->city) || $t->reviewed_at)
                                                    <div class="lt-city text-xs truncate">
                                                        {{ collect([$t->city, $t->reviewed_at?->locale('ru')->translatedFormat('j F Y')])->filter()->implode(' · ') }}
                                                    </div>
                                                @endif
                                            </div>
                                            @if($t->rating)
                                                <div class="lt-accent ml-auto flex gap-0.5 shrink-0">
                                                    @for($i = 1; $i <= 5; $i++)
                                                        <i class="fa{{ $i <= $t->rating ? 's' : 'r' }} fa-star text-[9px]"></i>
                                                    @endfor
                                                </div>
                                            @endif
                                        </figcaption>
                                        <blockquote class="lt-body lt-text text-sm leading-relaxed whitespace-pre-line">{{ $t->body }}</blockquote>
                                        @if($isLong)
                                            <div class="lt-accent mt-2 text-xs font-semibold">
                                                <span class="lt-more-closed">Читать полностью ↓</span>
                                                <span class="lt-more-open">Свернуть ↑</span>
                                            </div>
                                        @endif
                                        @if($t->mediaLink())
                                            <a href="{{ $t->mediaLink() }}" target="_blank" rel="noopener"
                                               @if($isCopy) tabindex="-1" @endif
                                               class="lt-accent inline-flex items-center gap-1.5 mt-3 text-xs font-semibold hover:underline">
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

    {{-- Тень по центру: отзывы вокруг формы уходят в темноту, карточка входа — главная.
         Мышь проходит насквозь. --}}
    <div class="absolute inset-0 pointer-events-none"
         style="background: radial-gradient(ellipse 32rem 40rem at 50% 42%, rgba(13, 12, 17, .95) 0%, rgba(13, 12, 17, .82) 42%, rgba(13, 12, 17, .35) 66%, transparent 84%);"></div>
    <div class="lt-grain absolute inset-0 pointer-events-none"></div>
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
        // Ушли с карточки — сворачиваем: раскрытая меняет высоту ленты,
        // и петля -50% перестаёт попадать в стык.
        document.querySelectorAll('.lt-card[aria-expanded]').forEach(function (card) {
            card.addEventListener('mouseleave', function () {
                card.setAttribute('aria-expanded', 'false');
            });
        });
    })();

    // Вкладки «Войти / Регистрация»: панели и подвалы с data-pane, плавная высота карточки.
    // #register в адресе сразу открывает регистрацию.
    document.addEventListener('DOMContentLoaded', function () {
        const tabs = document.querySelector('.lt-tabs');
        if (!tabs) return;
        const card = tabs.closest('.lt-login-card');
        const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function show(name, focus) {
            if (tabs.dataset.active === name) return;
            const from = card.offsetHeight;
            tabs.dataset.active = name;
            tabs.querySelectorAll('[data-tab]').forEach(function (b) {
                b.setAttribute('aria-selected', b.dataset.tab === name ? 'true' : 'false');
            });
            card.querySelectorAll('[data-pane]').forEach(function (p) {
                const on = p.dataset.pane === name;
                p.hidden = !on;
                if (on && p.getAttribute('role') === 'tabpanel') {
                    p.classList.remove('lt-pane-in'); void p.offsetWidth; p.classList.add('lt-pane-in');
                }
            });
            if (!still) {
                const to = card.offsetHeight;
                card.style.height = from + 'px';
                card.classList.add('lt-resizing');
                requestAnimationFrame(function () { card.style.height = to + 'px'; });
                setTimeout(function () { card.style.height = ''; card.classList.remove('lt-resizing'); }, 420);
            }
            if (focus) {
                const first = card.querySelector('[data-pane="' + name + '"] input:not([type=hidden])');
                if (first) first.focus({ preventScroll: true });
            }
        }
        tabs.addEventListener('click', function (e) {
            const b = e.target.closest('[data-tab]');
            if (b) show(b.dataset.tab, true);
        });
        tabs.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            const next = tabs.dataset.active === 'login' ? 'register' : 'login';
            show(next, false);
            tabs.querySelector('[data-tab="' + next + '"]').focus();
        });
        card.querySelectorAll('[data-tab-go]').forEach(function (b) {
            b.addEventListener('click', function () { show(b.dataset.tabGo, true); });
        });
        if (location.hash === '#register') show('register', true);
    });

    // Санскрит (молитва под формой и приветствие в карточке): блик за курсором +
    // разбор слова в подписи (hover, фокус, тап). Каждый .lt-verse — независимо.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.lt-verse').forEach(initVerse);
    });
    function initVerse(verse) {
        const line = verse.querySelector('.lt-shloka'), gloss = verse.querySelector('.lt-gloss');
        let shown = null, timer = 0;

        function setGloss(word) {
            if (shown === word) return;
            shown = word;
            clearTimeout(timer);
            gloss.classList.add('lt-fade');
            timer = setTimeout(function () {
                if (word) {
                    gloss.innerHTML = '';
                    const i = document.createElement('i');
                    i.textContent = word.dataset.iast;
                    gloss.append(i, ' — ' + word.dataset.ru);
                    gloss.classList.add('lt-word-gloss');
                } else {
                    gloss.textContent = gloss.dataset.default;
                    gloss.classList.remove('lt-word-gloss');
                }
                gloss.classList.remove('lt-fade');
            }, 160);
        }
        const words = verse.querySelectorAll('.lt-word');
        line.addEventListener('pointermove', function (e) {
            words.forEach(function (w) {
                w.style.setProperty('--lt-wx', (e.clientX - w.getBoundingClientRect().left) + 'px');
            });
        });
        line.addEventListener('pointerleave', function () {
            words.forEach(function (w) { w.style.setProperty('--lt-wx', '-200px'); });
        });
        verse.querySelectorAll('.lt-word').forEach(function (w) {
            w.addEventListener('pointerenter', function () { setGloss(w); });
            w.addEventListener('focus', function () { setGloss(w); });
            w.addEventListener('blur', function () { setGloss(null); });
            // Тач: тап показывает слово, повторный тап — возвращает строку.
            w.addEventListener('click', function () {
                if (window.matchMedia('(hover: hover)').matches) return;
                const on = w.classList.toggle('lt-word-on');
                verse.querySelectorAll('.lt-word-on').forEach(function (o) { if (o !== w) o.classList.remove('lt-word-on'); });
                setGloss(on ? w : null);
            });
        });
        line.addEventListener('pointerleave', function () {
            if (!line.contains(document.activeElement)) setGloss(null);
        });
    }

    // Карточки отвечают курсору: свет по поверхности и кромке + лёгкий наклон.
    // Одна логика для карточки входа (.lt-dark) и карточек отзывов (.lt-card).
    // Слушаем документ, а не карточку: наклонённая карточка «уезжает» из-под курсора
    // у края, и pointerleave дёргал бы наклон; держим карточку, пока курсор в её зоне + PAD.
    // Только для мыши; reduced-motion — свет есть, наклона нет.
    document.addEventListener('DOMContentLoaded', function () {
        if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
        const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const login = document.querySelector('.lt-dark');
        const TILT = { login: [4, 5, 30], review: [3, 4, 10] };   // [макс. X°, макс. Y°, запас зоны px]
        let frame = 0, last = null, current = null;

        function zone(card) { return card === login ? TILT.login : TILT.review; }
        function within(card, e) {
            const r = card.getBoundingClientRect(), pad = zone(card)[2];
            const x = e.clientX - r.left, y = e.clientY - r.top;
            return x > -pad && y > -pad && x < r.width + pad && y < r.height + pad ? { r: r, x: x, y: y } : null;
        }
        function release(card) {
            card.classList.remove('lt-tracking', 'lt-on');
            card.style.setProperty('--lt-hot', '0');
            card.style.setProperty('--lt-rx', '0deg');
            card.style.setProperty('--lt-ry', '0deg');
        }
        function apply() {
            frame = 0;
            let hit = current && within(current, last);
            if (!hit) {
                const next = login && within(login, last) ? login
                    : (last.target.closest && last.target.closest('.lt-card'));
                if (current && current !== next) release(current);
                current = next || null;
                if (!current) return;
                hit = within(current, last);
                if (!hit) return;
                current.classList.add('lt-tracking', 'lt-on');
                current.style.setProperty('--lt-hot', '1');
            }
            const z = zone(current);
            current.style.setProperty('--lt-mx', hit.x + 'px');
            current.style.setProperty('--lt-my', hit.y + 'px');
            if (still) return;
            const cx = Math.min(Math.max(hit.x / hit.r.width, 0), 1);
            const cy = Math.min(Math.max(hit.y / hit.r.height, 0), 1);
            current.style.setProperty('--lt-rx', ((.5 - cy) * z[0]).toFixed(2) + 'deg');
            current.style.setProperty('--lt-ry', ((cx - .5) * z[1]).toFixed(2) + 'deg');
        }
        document.addEventListener('pointermove', function (e) {
            last = e;
            if (!frame) frame = requestAnimationFrame(apply);
        }, { passive: true });
        document.documentElement.addEventListener('pointerleave', function () {
            if (current) release(current);
            current = null;
        });
    });
</script>
