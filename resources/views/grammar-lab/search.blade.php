@extends('layouts.student')

@section('title', 'Поиск — Лаборатория грамматики')
@section('header', 'Поиск')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 pb-12">
    <form method="get" action="{{ route('student.grammar-lab.search') }}" class="mb-6" role="search">
        <label for="grammar-lab-q" class="sr-only">Запрос</label>
        <div class="flex gap-2">
            <input id="grammar-lab-q" name="q" type="search" value="{{ $q }}" autofocus
                   class="flex-1 rounded-xl border border-gray-300 px-3 py-2 text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
            <button type="submit"
                    class="px-4 py-2 rounded-xl bg-brand text-white font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand">
                Найти
            </button>
        </div>
    </form>

    <p class="text-xs text-gray-500 mb-4">
        @if($semantic)
            Гибридный поиск (лексика + вектор).
        @else
            Лексический поиск (BM25 и точные алиасы). Семантический слой выключен.
        @endif
    </p>

    @if($q === '')
        <p class="text-sm text-gray-500">Введите запрос — например <em>гуна</em>, <em>guṇa</em> или <em>set</em>.</p>
    @elseif($hits === [])
        <p class="text-sm text-gray-500" role="status">Ничего не нашлось по «{{ $q }}».</p>
    @else
        <ol class="space-y-3">
            @foreach($hits as $hit)
                <li>
                    <a href="{{ route('student.grammar-lab.show', $hit['slug']) }}"
                       class="block px-4 py-3 rounded-xl bg-white border border-gray-200 hover:border-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                        <span class="font-bold text-gray-900">{{ $hit['title_ru'] }}</span>
                        <span class="block text-xs text-gray-500 mt-1">{{ implode(' · ', $hit['sources']) }}</span>
                        <span class="block text-sm text-gray-600 mt-2">{{ $hit['snippet'] }}</span>
                    </a>
                </li>
            @endforeach
        </ol>
    @endif

    <p class="mt-6">
        <a href="{{ route('student.grammar-lab.index') }}" class="text-sm text-brand underline">Ко всем темам</a>
    </p>
</div>
@endsection
