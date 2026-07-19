{{--
    Ссылки на все публичные документы из public/docs/ для подвала.
    Заголовки и порядок — из config/docs.php; незнакомые PDF дописываются в конец.
    Цвет ссылок настраивается через $linkClass (под тему конкретного layout'а).
--}}
@php
    $labels = config('docs', []);
    $order = array_keys($labels);

    $files = collect(glob(public_path('docs/*.pdf')))
        ->map(fn ($f) => pathinfo($f, PATHINFO_FILENAME))
        ->sortBy(function ($slug) use ($order) {
            $pos = array_search($slug, $order, true);

            return $pos === false ? PHP_INT_MAX : $pos;
        })
        ->values();

    $linkClass = $linkClass ?? 'text-gray-500 hover:text-[#E85C24] transition-colors';
@endphp

@foreach ($files as $slug)
    <a href="{{ route('docs.show', $slug) }}" class="{{ $linkClass }}">
        {{ $labels[$slug] ?? \Illuminate\Support\Str::headline($slug) }}
    </a>
@endforeach
<a href="{{ route('refund.show') }}" class="{{ $linkClass }}">Условия возврата</a>
