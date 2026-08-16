<!DOCTYPE html>
<html lang="ru" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('title', 'Словарь санскрита — Общество ревнителей санскрита')</title>
    <meta name="description" content="@yield('meta_description', 'Санскритско-русские словарные статьи: деванагари, IAST, кириллическая транслитерация и перевод.')">

    {{-- Wave 0 (SEO P2 / H204): страницы словаря по умолчанию noindex,follow —
         индексация включается отдельной волной после решения по тонкому контенту. --}}
    <meta name="robots" content="@yield('robots', 'noindex, follow')">
    <link rel="canonical" href="@yield('canonical', url()->current())">

    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Общество ревнителей санскрита">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="@yield('og_title', 'Словарь санскрита')">
    <meta property="og:description" content="@yield('meta_description', 'Санскритско-русские словарные статьи.')">
    <meta property="og:image" content="{{ asset('images/logo.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')

    <style>
        body { font-family: 'Montserrat', sans-serif; }
        .deva { font-family: 'Noto Serif Devanagari', 'Montserrat', serif; }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen relative overflow-x-hidden pb-20">

    <x-public-header variant="dark" />

    <main class="container mx-auto px-4 relative z-10 max-w-5xl pt-10 md:pt-14">
        @yield('content')
    </main>

    <footer class="mt-20 pt-10 border-t border-gray-800 text-center flex flex-col items-center container mx-auto px-4 max-w-5xl">
        <div class="mt-2 flex flex-wrap justify-center items-center gap-x-4 gap-y-2 text-[13px] md:text-sm font-medium">
            @include('partials.footer-docs', ['linkClass' => 'text-gray-400 hover:text-brand transition-colors'])
            <a href="{{ route('hub.sanskritorium') }}" class="text-gray-400 hover:text-brand transition-colors">Транслитерация</a>
        </div>
        <p class="mt-6 text-sm text-gray-600">
            &copy; {{ date('Y') }} Общество ревнителей санскрита. Все права защищены.
        </p>
        @include('partials.legal-requisites', ['class' => 'mt-3 text-center text-[12px] text-gray-600'])
    </footer>

    @include('partials.cookie-consent')
</body>
</html>
