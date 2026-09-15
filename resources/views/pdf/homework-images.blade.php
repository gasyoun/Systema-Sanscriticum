<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 14mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #333;
            margin: 0;
        }
        .meta {
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #ccc;
        }
        .meta strong { color: #111; }
        .page {
            page-break-after: always;
            text-align: center;
        }
        .page:last-child { page-break-after: auto; }
        .page img {
            max-width: 100%;
            max-height: 240mm;
            height: auto;
        }
        .caption {
            margin-top: 6px;
            color: #666;
            font-size: 10px;
            word-break: break-all;
        }
        .skipped {
            margin: 40mm 10mm;
            padding: 12mm;
            border: 1px dashed #999;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
@foreach($pages as $i => $page)
    <div class="page">
        @if($i === 0)
            <div class="meta">
                <div><strong>Домашняя работа — картинки одним PDF</strong></div>
                @if(!empty($student))
                    <div>Студент: {{ $student }}</div>
                @endif
                @if(!empty($course))
                    <div>Курс: {{ $course }}</div>
                @endif
                @if(!empty($lesson))
                    <div>Урок: {{ $lesson }}</div>
                @endif
                <div>Страниц: {{ count($pages) }}</div>
            </div>
        @endif
        @if(!empty($page['skipped']))
            <div class="skipped">Страница пропущена: файл слишком большой для сборки PDF.
                Откройте оригинал в кабинете.</div>
        @else
            <img src="{{ $page['data_uri'] }}" alt="{{ $page['name'] ?? '' }}">
        @endif
        @if(!empty($page['name']))
            <div class="caption">{{ $page['name'] }} ({{ $i + 1 }}/{{ count($pages) }})</div>
        @endif
    </div>
@endforeach
</body>
</html>
