@extends('layouts.student')

@section('title', 'Как пользоваться')
@section('header', 'Как пользоваться')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <a href="{{ route('student.dashboard') }}"
       class="inline-flex items-center gap-2 text-sm font-bold text-gray-500 hover:text-brand transition-colors mb-6">
        <i class="fas fa-arrow-left text-xs"></i> В кабинет
    </a>

    @if ($html === null)
        <p class="text-sm text-gray-600">
            Руководство не найдено — напишите в поддержку.
        </p>
    @else
        <style>
            .student-guide h1 { font-size: 1.5rem; font-weight: 800; margin: 2rem 0 .75rem; }
            .student-guide h2 { font-size: 1.25rem; font-weight: 800; margin: 1.75rem 0 .5rem; }
            .student-guide h3 { font-size: 1.05rem; font-weight: 700; margin: 1.25rem 0 .5rem; }
            .student-guide p, .student-guide li { line-height: 1.65; }
            .student-guide p { margin: .5rem 0; }
            .student-guide ul, .student-guide ol { margin: .5rem 0 .5rem 1.25rem; }
            .student-guide ul { list-style: disc; }
            .student-guide ol { list-style: decimal; }
            .student-guide li { margin: .25rem 0; }
            .student-guide a { color: #c2410c; text-decoration: underline; }
            .student-guide blockquote {
                border-left: 3px solid #E85C24;
                background: #fff7ed;
                padding: .75rem 1rem;
                margin: .75rem 0;
                border-radius: 0 0.75rem 0.75rem 0;
            }
            .student-guide table { width: 100%; border-collapse: collapse; margin: .75rem 0; }
            .student-guide th, .student-guide td {
                border: 1px solid #e5e7eb;
                padding: .4rem .6rem;
                text-align: left;
                vertical-align: top;
                font-size: .9rem;
            }
            .student-guide th { font-weight: 700; background: #fff7ed; }
            .student-guide img { max-width: 100%; height: auto; border-radius: 0.75rem; margin: .75rem 0; border: 1px solid #e5e7eb; }
            .student-guide hr { margin: 1.5rem 0; opacity: .3; }
        </style>

        <article class="student-guide prose max-w-none bg-white rounded-3xl shadow-sm border border-gray-100 p-6 md:p-8 text-gray-800">
            {!! $html !!}
        </article>
    @endif
</div>
@endsection
