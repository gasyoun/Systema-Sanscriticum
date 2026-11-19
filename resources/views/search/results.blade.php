{{-- H7590 — страница полных результатов поиска /poisk/{q}.
     Канонический path-style URL (без ?q=), поэтому сама страница
     noindex,follow: поисковик не должен индексировать выдачу по любому
     запросу, только канонические страницы-результаты. --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ \App\Support\SeoTitle::render('Поиск: '.$q) }}</title>
    <meta name="description" content="Результаты поиска «{{ $q }}» по курсам, преподавателям и статьям Общества ревнителей санскрита.">
    <meta name="robots" content="noindex, follow">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">
    <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('images/icon-512.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/icon-180.png') }}">
    <link rel="manifest" href="{{ asset('manifest-site.webmanifest') }}">

    @vite('resources/css/fonts.css')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @vite('resources/js/alpine-standalone.js')
    @vite('resources/js/global-search.js')

    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Montserrat', sans-serif; }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen flex flex-col">

    <x-public-header variant="dark" />

    <main class="flex-1 container mx-auto px-4 py-10 md:py-14 max-w-5xl">

        <h1 class="text-2xl md:text-3xl font-bold mb-2">
            Поиск: <span class="text-brand">{{ $q }}</span>
        </h1>

        @php $total = $courses->count() + $teachers->count() + $articles->count(); @endphp
        <p class="text-sm text-gray-500 mb-10">
            {{ $total === 0 ? 'Ничего не найдено' : 'Найдено: '.$total }}
        </p>

        @if($total === 0)
            <div class="bg-[#111622] border border-[#1F2636] rounded-2xl p-8 text-center">
                <p class="text-slate-300 mb-4">
                    По запросу «{{ $q }}» ничего не нашлось. Попробуйте короче: «йога», «грамматика», «Костина».
                </p>
                <a href="{{ route('shop.index') }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand text-white text-sm font-bold hover:bg-brand-hover transition-all">
                    Смотреть все курсы
                </a>
            </div>
        @endif

        @foreach([
            ['items' => $courses, 'heading' => 'Курсы'],
            ['items' => $teachers, 'heading' => 'Преподаватели'],
            ['items' => $articles, 'heading' => 'Статьи'],
        ] as $group)
            @if($group['items']->isNotEmpty())
                <section class="mb-10">
                    <h2 class="text-lg font-bold mb-4">{{ $group['heading'] }}
                        <span class="text-sm text-gray-500 font-normal">({{ $group['items']->count() }})</span>
                    </h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($group['items'] as $item)
                            <a href="{{ $item['url'] }}"
                               class="block bg-[#111622] border border-[#1F2636] rounded-xl px-5 py-4 hover:border-brand transition-all group">
                                <span class="block font-semibold group-hover:text-brand transition-colors">{{ $item['title'] }}</span>
                                @if($item['hint'])
                                    <span class="block text-xs text-slate-500 mt-1">{{ $item['hint'] }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach
    </main>

    {{-- Минимальный футер в тон шапки --}}
    <footer class="bg-[#060810] border-t border-[#1F2636] py-8 text-center">
        <div class="container mx-auto px-4 flex flex-wrap justify-center items-center gap-x-4 gap-y-2 text-[13px] md:text-sm font-medium">
            @include('partials.footer-docs', ['linkClass' => 'text-gray-400 hover:text-brand transition-colors'])
            <a href="{{ route('hub.sanskritorium') }}" class="text-gray-400 hover:text-brand transition-colors">Транслитерация</a>
            <a href="{{ route('trust-moat') }}" class="text-gray-400 hover:text-brand transition-colors">Почему проверяет человек</a>
        </div>
        <p class="mt-4 text-xs text-gray-600">&copy; {{ date('Y') }} Общество ревнителей санскрита</p>
    </footer>

    @include('partials.cookie-consent')
</body>
</html>
