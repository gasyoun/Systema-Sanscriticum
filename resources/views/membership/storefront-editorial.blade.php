<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>Осень 2026 — календарь ОРС</title>
    {{-- H3650: autumn calendar share card. Home keeps images/og-main-preview.jpg. --}}
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="Осень 2026 — календарь ОРС">
    <meta property="og:image" content="{{ asset('images/og-autumn-calendar-h3650.webp') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Осень 2026 — календарь Общества ревнителей санскрита">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ asset('images/og-autumn-calendar-h3650.webp') }}">
    <style>
        :root{color-scheme:light;--ink:#18231d;--paper:#f7f1e4;--accent:#a3412f;--line:#d8c7a8}
        *{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:18px/1.55 Georgia,serif}
        main{max-width:980px;margin:auto;padding:64px 24px}header{border-bottom:1px solid var(--line);padding-bottom:32px}
        .eyebrow{font:700 12px/1.2 system-ui;letter-spacing:.18em;text-transform:uppercase;color:var(--accent)}
        h1{font-size:clamp(42px,8vw,84px);line-height:.95;margin:18px 0}.intro{max-width:700px;font-size:22px}
        article{display:grid;grid-template-columns:150px 1fr auto;gap:24px;padding:28px 0;border-bottom:1px solid var(--line);align-items:start}
        .date{font-weight:bold;color:var(--accent)}h2{font-size:27px;margin:0 0 8px}.meta{font:14px/1.4 system-ui;color:#5f685f}
        .buy{white-space:nowrap;background:var(--ink);color:white;padding:12px 16px;text-decoration:none;font:700 14px system-ui}
        footer{margin-top:40px;font:12px system-ui;color:#687168}@media(max-width:720px){article{grid-template-columns:1fr}.buy{justify-self:start}}
    </style>
</head>
<body><main data-storefront="samskrte" data-feed-version="{{ $feed['version'] }}">
    <header><div class="eyebrow">Общество ревнителей санскрита · календарь</div><h1>Осень<br>2026</h1><p class="intro">Даты, статусы и цены берутся из одного канонического потока Systema. Здесь — редакционный календарь: сначала время, затем курс.</p></header>
    @forelse($feed['offers'] as $offer)
        @php($commercial = collect($offer)->only(['id', 'starts_on', 'status', 'price_rub', 'tariff_id', 'tier', 'term_months']))
        <article data-offer-id="{{ $offer['id'] }}" data-commercial='@json($commercial)'>
            <div class="date">{{ \Illuminate\Support\Carbon::parse($offer['starts_on'])->translatedFormat('d F') }}</div>
            <div><h2>{{ $offer['title'] }}</h2><div class="meta">{{ $offer['status'] }} · {{ number_format($offer['price_rub'], 0, ',', ' ') }} ₽@if($offer['term_months']) · {{ $offer['term_months'] }} мес.@endif</div></div>
            <a class="buy" href="{{ $offer['checkout_urls'][$sourceSite] }}">Выбрать →</a>
        </article>
    @empty
        <p>Подтверждённые предложения пока не опубликованы.</p>
    @endforelse
    <footer>Systema feed {{ $feed['version'] }} · коммерческие поля синхронны с samskrtam.ru</footer>
</main></body></html>
