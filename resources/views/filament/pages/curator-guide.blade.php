@php
    $html = $this->guideHtml();
@endphp

<x-filament-panels::page>
    @if ($html === null)
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Руководство не найдено — сообщите администратору.
        </p>
    @else
        <style>
            .curator-guide h1 { font-size: 1.5rem; font-weight: 700; margin: 2rem 0 .75rem; }
            .curator-guide h2 { font-size: 1.25rem; font-weight: 600; margin: 1.75rem 0 .5rem; }
            .curator-guide h3 { font-size: 1.05rem; font-weight: 600; margin: 1.25rem 0 .5rem; }
            .curator-guide p, .curator-guide li { line-height: 1.65; }
            .curator-guide p { margin: .5rem 0; }
            .curator-guide ul, .curator-guide ol { margin: .5rem 0 .5rem 1.25rem; }
            .curator-guide ul { list-style: disc; }
            .curator-guide ol { list-style: decimal; }
            .curator-guide li { margin: .25rem 0; }
            .curator-guide a { text-decoration: underline; }
            .curator-guide blockquote {
                border-left: 3px solid currentColor;
                opacity: .85;
                padding-left: .875rem;
                margin: .75rem 0;
            }
            .curator-guide table { width: 100%; border-collapse: collapse; margin: .75rem 0; }
            .curator-guide th, .curator-guide td {
                border: 1px solid rgba(128, 128, 128, .35);
                padding: .4rem .6rem;
                text-align: left;
                vertical-align: top;
                font-size: .9rem;
            }
            .curator-guide th { font-weight: 600; }
            .curator-guide code { font-size: .9em; }
            .curator-guide hr { margin: 1.5rem 0; opacity: .3; }
            .curator-guide img { max-width: 100%; height: auto; }
        </style>

        <article class="curator-guide prose max-w-none text-gray-800 dark:text-gray-100">
            {!! $html !!}
        </article>
    @endif
</x-filament-panels::page>
