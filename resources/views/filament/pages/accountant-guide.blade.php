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
            .accountant-guide h1 { font-size: 1.5rem; font-weight: 700; margin: 2rem 0 .75rem; }
            .accountant-guide h2 { font-size: 1.25rem; font-weight: 600; margin: 1.75rem 0 .5rem; }
            .accountant-guide h3 { font-size: 1.05rem; font-weight: 600; margin: 1.25rem 0 .5rem; }
            .accountant-guide p, .accountant-guide li { line-height: 1.65; }
            .accountant-guide p { margin: .5rem 0; }
            .accountant-guide ul, .accountant-guide ol { margin: .5rem 0 .5rem 1.25rem; }
            .accountant-guide ul { list-style: disc; }
            .accountant-guide ol { list-style: decimal; }
            .accountant-guide li { margin: .25rem 0; }
            .accountant-guide a { text-decoration: underline; }
            .accountant-guide blockquote {
                border-left: 3px solid currentColor;
                opacity: .85;
                padding-left: .875rem;
                margin: .75rem 0;
            }
            .accountant-guide table { width: 100%; border-collapse: collapse; margin: .75rem 0; }
            .accountant-guide th, .accountant-guide td {
                border: 1px solid rgba(128, 128, 128, .35);
                padding: .4rem .6rem;
                text-align: left;
                vertical-align: top;
                font-size: .9rem;
            }
            .accountant-guide th { font-weight: 600; }
            .accountant-guide code { font-size: .9em; }
            .accountant-guide hr { margin: 1.5rem 0; opacity: .3; }
            .accountant-guide img { max-width: 100%; height: auto; }
        </style>

        <article class="accountant-guide prose max-w-none text-gray-800 dark:text-gray-100">
            {!! $html !!}
        </article>
    @endif
</x-filament-panels::page>
