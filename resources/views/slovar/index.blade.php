@extends('layouts.slovar')

@section('title', ($activeDictionary?->name ? $activeDictionary->name.' — ' : '').'Словарь санскрита — Общество ревнителей санскрита')
@section('og_title', 'Словарь санскрита')

@push('head')
    @include('partials.schema-breadcrumbs', ['crumbs' => [
        ['name' => 'Главная', 'url' => url('/')],
        ['name' => 'Словарь', 'url' => route('slovar.index')],
    ]])
@endpush

@section('content')
    <div class="text-center mb-12">
        <h1 class="text-4xl md:text-5xl font-extrabold mb-4 tracking-tight">Словарь санскрита</h1>
        <div class="w-24 h-1 bg-[#E85C24] mx-auto mb-6 rounded-full"></div>
        <p class="text-lg text-gray-300 max-w-2xl mx-auto">
            Санскритско-русские словарные статьи: деванагари, IAST, кириллическая транслитерация и перевод.
        </p>
    </div>

    {{-- Фильтр по словарям --}}
    <div class="flex flex-wrap justify-center gap-2 mb-10">
        <a href="{{ route('slovar.index') }}"
           class="px-4 py-2 rounded-xl text-sm font-bold transition-colors {{ ! $activeDictionary ? 'bg-[#E85C24] text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">
            Все словари
        </a>
        @foreach($dictionaries as $dictionary)
            <a href="{{ route('slovar.index', ['dict' => $dictionary->id]) }}"
               class="px-4 py-2 rounded-xl text-sm font-bold transition-colors {{ $activeDictionary?->id === $dictionary->id ? 'bg-[#E85C24] text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">
                {{ $dictionary->name }}
                <span class="text-xs opacity-60">{{ $dictionary->words_count }}</span>
            </a>
        @endforeach
    </div>

    {{-- Список слов --}}
    @if($words->count() > 0)
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
            @foreach($words as $word)
                <a href="{{ route('slovar.show', $word->slug) }}"
                   class="block bg-[#161b28] border border-gray-700/60 rounded-xl px-4 py-3 hover:border-[#E85C24]/60 hover:-translate-y-0.5 transition-all">
                    @if($word->devanagari)
                        <div class="deva text-lg text-white leading-tight">{{ $word->devanagari }}</div>
                    @endif
                    <div class="text-sm text-[#E85C24] font-semibold truncate">{{ $word->iast ?: $word->cyrillic }}</div>
                </a>
            @endforeach
        </div>

        <div class="mt-12 flex justify-center">
            {{ $words->links() }}
        </div>
    @else
        <div class="text-center py-20 bg-gray-800/50 rounded-2xl border border-gray-700">
            <p class="text-gray-400 text-lg">Словарные статьи скоро появятся.</p>
        </div>
    @endif
@endsection
