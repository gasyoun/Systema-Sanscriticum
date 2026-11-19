{{-- H7590 — пустая форма поиска /poisk (без запроса). GET-сабмит уходит на
     /poisk?q=… и редиректится 301-м на канонический /poisk/{q}; с Alpine
     (alpine-standalone ниже) навигация сразу path-style, без промежуточного
     редиректа. --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ \App\Support\SeoTitle::render('Поиск по сайту') }}</title>
    <meta name="description" content="Поиск по курсам санскрита и хинди, преподавателям и статьям Общества ревнителей санскрита.">
    <meta name="robots" content="noindex, follow">
    <link rel="canonical" href="{{ url('/poisk') }}">
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

    <main class="flex-1 container mx-auto px-4 py-16 md:py-24 max-w-2xl text-center">

        <h1 class="text-3xl md:text-4xl font-bold mb-3">
            Поиск по <span class="text-brand">сайту</span>
        </h1>
        <p class="text-slate-400 mb-10">Курсы, преподаватели, статьи</p>

        <form action="/poisk"
              method="GET"
              x-data="{ q: '' }"
              @submit.prevent="if (q.trim()) { window.location.href = '/poisk/' + encodeURIComponent(q.trim()); }"
              class="flex items-center gap-2 px-4 py-3 rounded-2xl bg-[#111622] border border-[#1F2636] focus-within:border-brand transition-all">
            <svg class="w-5 h-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
            </svg>
            <input type="search" name="q" x-model="q" autofocus
                   autocomplete="off" spellcheck="false"
                   placeholder="Например: йога, грамматика, Костина…"
                   aria-label="Поиск по сайту"
                   class="flex-1 bg-transparent text-lg outline-none text-white placeholder-slate-500">
        </form>

        <p class="mt-6 text-xs text-gray-600">
            Клавиатура: <kbd class="px-1.5 py-0.5 rounded border border-[#1F2636]">⌘K</kbd> или <kbd class="px-1.5 py-0.5 rounded border border-[#1F2636]">/</kbd> — фокус на поиск
        </p>
    </main>

    <footer class="bg-[#060810] border-t border-[#1F2636] py-8 text-center">
        <p class="text-xs text-gray-600">&copy; {{ date('Y') }} Общество ревнителей санскрита</p>
    </footer>

    @include('partials.cookie-consent')
</body>
</html>
