<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>Каталог осени 2026 — samskrtam.ru</title>
    {{-- H3650: autumn calendar share card (same still as samskrte editorial). --}}
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="Каталог осени 2026 — samskrtam.ru">
    <meta property="og:image" content="{{ asset('images/og-autumn-calendar-h3650.webp') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Осень 2026 — календарь Общества ревнителей санскрита">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ asset('images/og-autumn-calendar-h3650.webp') }}">
    <style>
        :root{--night:#101727;--card:#182238;--cyan:#56d8e7;--text:#f4f7fb;--muted:#9aabc6}
        *{box-sizing:border-box}body{margin:0;background:var(--night);color:var(--text);font:16px/1.5 Inter,system-ui,sans-serif}
        main{max-width:1180px;margin:auto;padding:54px 24px}.top{display:flex;justify-content:space-between;gap:24px;align-items:end;margin-bottom:34px}
        h1{font-size:clamp(36px,6vw,68px);line-height:1;margin:0}.version{color:var(--muted);font:12px ui-monospace,monospace}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px}.card{background:var(--card);border:1px solid #2b3852;border-radius:16px;padding:22px;display:flex;min-height:260px;flex-direction:column}
        .pill{align-self:flex-start;border:1px solid var(--cyan);color:var(--cyan);border-radius:99px;padding:4px 9px;font-size:11px;text-transform:uppercase;letter-spacing:.08em}
        h2{font-size:23px;line-height:1.2;margin:24px 0 8px}.meta{color:var(--muted)}.price{font-size:30px;font-weight:800;margin-top:auto;padding-top:28px}
        a{display:block;text-align:center;background:var(--cyan);color:#07111c;text-decoration:none;font-weight:800;border-radius:10px;padding:12px;margin-top:14px}
    </style>
</head>
<body><main data-storefront="samskrtam" data-feed-version="{{ $feed['version'] }}">
    <div class="top"><div><div class="pill">каталог</div><h1>Осень 2026</h1></div><div class="version">feed {{ $feed['version'] }}</div></div>
    <section class="grid">
        @forelse($feed['offers'] as $offer)
            @php($commercial = collect($offer)->only(['id', 'starts_on', 'status', 'price_rub', 'tariff_id', 'tier', 'term_months']))
            <article class="card" data-offer-id="{{ $offer['id'] }}" data-commercial='@json($commercial)'>
                <span class="pill">{{ $offer['kind'] }} · {{ $offer['status'] }}</span><h2>{{ $offer['title'] }}</h2>
                <div class="meta">Старт {{ \Illuminate\Support\Carbon::parse($offer['starts_on'])->format('d.m.Y') }}@if($offer['term_months']) · {{ $offer['term_months'] }} мес.@endif</div>
                <div class="price">{{ number_format($offer['price_rub'], 0, ',', ' ') }} ₽</div>
                <a href="{{ $offer['checkout_urls'][$sourceSite] }}">Открыть предложение</a>
            </article>
        @empty
            <p>Подтверждённые предложения пока не опубликованы.</p>
        @endforelse
    </section>
</main></body></html>
