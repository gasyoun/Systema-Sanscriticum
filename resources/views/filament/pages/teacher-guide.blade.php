@php
    $html = $this->guideHtml();
@endphp

<x-filament-panels::page>
    @if ($html === null)
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Руководство не найдено — сообщите администратору.
        </p>
    @else
        {{-- Типографика задана здесь и ограничена этой страницей: панель собирается
             без плагина типографики, поэтому одних классов prose не хватает. --}}
        <style>
            .teacher-guide h1 { font-size: 1.5rem; font-weight: 700; margin: 2rem 0 .75rem; }
            .teacher-guide h2 { font-size: 1.25rem; font-weight: 600; margin: 1.75rem 0 .5rem; }
            .teacher-guide h3 { font-size: 1.05rem; font-weight: 600; margin: 1.25rem 0 .5rem; }
            .teacher-guide p, .teacher-guide li { line-height: 1.65; }
            .teacher-guide p { margin: .5rem 0; }
            .teacher-guide ul, .teacher-guide ol { margin: .5rem 0 .5rem 1.25rem; }
            .teacher-guide ul { list-style: disc; }
            .teacher-guide ol { list-style: decimal; }
            .teacher-guide li { margin: .25rem 0; }
            .teacher-guide a { text-decoration: underline; }
            .teacher-guide blockquote {
                border-left: 3px solid currentColor;
                opacity: .85;
                padding-left: .875rem;
                margin: .75rem 0;
            }
            .teacher-guide table { width: 100%; border-collapse: collapse; margin: .75rem 0; }
            .teacher-guide th, .teacher-guide td {
                border: 1px solid rgba(128, 128, 128, .35);
                padding: .4rem .6rem;
                text-align: left;
                vertical-align: top;
                font-size: .9rem;
            }
            .teacher-guide th { font-weight: 600; }
            .teacher-guide code { font-size: .9em; }
            .teacher-guide hr { margin: 1.5rem 0; opacity: .3; }
        </style>

        <article class="teacher-guide prose max-w-none text-gray-800 dark:text-gray-100">
            {!! $html !!}
        </article>
    @endif
</x-filament-panels::page>
