<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="samskrte.ru">
    <meta property="og:title" content="{{ $title }}">
    @if ($description !== '')
        <meta property="og:description" content="{{ $description }}">
    @endif
    <meta property="og:image" content="{{ $imageUrl }}">
    <meta property="og:url" content="{{ $pageUrl }}">
    <style>
        body { margin: 0; background: #faf7f2; color: #222; font: 17px/1.5 system-ui, sans-serif; }
        main { max-width: 680px; margin: 0 auto; padding: 24px 16px; }
        img { width: 100%; height: auto; border-radius: 8px; }
        p { white-space: pre-wrap; }
        a { color: #8a3b12; }
    </style>
</head>
<body>
<main>
    <img src="{{ $imageUrl }}" alt="">
    <p>{{ $text }}</p>
    <p><a href="https://samskrte.ru">samskrte.ru</a></p>
</main>
</body>
</html>
