@extends('layouts.student')

@section('title', 'Learn Your Way · '.$lesson->title)
@section('header', 'Learn Your Way')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-6 font-nunito" data-testid="lyw-pack"
     data-lyw-profile="{{ $profileLevel }}/{{ $profileInterest }}">
    <a href="{{ $backUrl }}"
       class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-brand mb-4">
        <i class="fas fa-arrow-left"></i> К уроку
    </a>

    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-gray-100 mb-4">
        <div class="flex flex-wrap items-center gap-2 text-xs font-bold mb-3">
            <span class="px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700"
                  data-testid="lyw-level">Уровень: {{ $profileLevel === 'base' ? 'база' : $profileLevel }}</span>
            <span class="px-2.5 py-1 rounded-full bg-orange-50 text-brand"
                  data-testid="lyw-interest">Интерес: {{ $profileInterest === 'base' ? 'база' : $profileInterest }}</span>
            <span class="px-2.5 py-1 rounded-full bg-gray-100 text-gray-600">{{ $manifest['source']['lesson_marker'] ?? '' }}</span>
        </div>
        <div class="prose prose-lg max-w-none text-gray-800 leading-relaxed marker:bg-indigo/10">
            {!! \App\Support\LessonPackMarkdown::toHtml($text) !!}
        </div>
    </div>

    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-gray-100" data-testid="lyw-mindmap">
        <h2 class="text-lg font-extrabold text-gray-900 mb-3">Mind map занятия</h2>
        <pre class="mermaid overflow-x-auto text-xs bg-gray-50 rounded-2xl p-4">{{ $mindmap }}</pre>
    </div>
</div>

@if(config('lyw.enabled'))
<script src="{{ asset('vendor/mermaid/mermaid.min.js') }}"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.mermaid) {
            window.mermaid.initialize({ startOnLoad: true, theme: 'neutral' });
        }
    });
</script>
@endif
@endsection
