@extends('layouts.articles')

@section('title', $title . ' — Общество ревнителей санскрита')
@section('meta_description', $title)

@push('head')
    <meta name="robots" content="index, follow">
@endpush

@section('content')
    <div class="container mx-auto px-4 max-w-5xl py-10 md:py-14">

        {{-- Хлебная крошка --}}
        <nav class="mb-6 text-sm text-gray-500">
            <a href="{{ url('/') }}" class="hover:text-[#E85C24] transition-colors">Главная</a>
            <span class="mx-2 text-gray-300">/</span>
            <span class="text-gray-700">Документы</span>
        </nav>

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
            <h1 class="text-2xl md:text-3xl font-extrabold text-[#101010] leading-tight">
                {{ $title }}
            </h1>

            <a href="{{ asset("docs/{$slug}.pdf") }}" download
               class="shrink-0 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#E85C24] text-white font-semibold hover:bg-[#d14e1a] transition-colors shadow-sm">
                <i class="fas fa-download"></i>
                Скачать PDF
            </a>
        </div>

        {{-- Встроенный просмотр PDF --}}
        <div class="rounded-2xl overflow-hidden border border-gray-200 shadow-sm bg-white">
            <iframe src="{{ asset("docs/{$slug}.pdf") }}"
                    class="w-full h-[80vh]" frameborder="0"
                    title="{{ $title }}"></iframe>
        </div>

        <p class="mt-4 text-sm text-gray-500">
            Если документ не отображается,
            <a href="{{ asset("docs/{$slug}.pdf") }}" target="_blank" rel="noopener"
               class="text-[#E85C24] underline hover:no-underline">откройте PDF в новой вкладке</a>.
        </p>
    </div>
@endsection
