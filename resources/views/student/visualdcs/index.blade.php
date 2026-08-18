@extends('layouts.student')

@section('title', $surface === 'verb' ? 'Глагол' : ($surface === 'nominal' ? 'Имя' : 'Пассаж'))
@section('header', $surface === 'verb' ? 'Глагол' : ($surface === 'nominal' ? 'Имя' : 'Пассаж'))

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 pb-12">
    {{-- H2869: gray-600 и тёмные ссылки с фирменным подчёркиванием — кремовый
         фон кабинета (#F4F1EA) валит gray-500 (4.2:1) и оранжевый текст
         (3.3:1) ниже WCAG AA; замер — Dusk-аудит контраста. --}}
    <p class="text-sm text-gray-600 mb-6">
        @if(in_array($state, ['preview', 'unpaid', 'expired'], true))
            Показаны только частотные единицы. Полный список откроется при действующем доступе к курсу.
        @else
            Каталог релиза{{ $release ? ' '.$release->release_id : '' }}
            — {{ $total }} единиц, страница {{ $page }}.
        @endif
    </p>

    <form method="GET" action="{{ route('student.visualdcs.index', $surface) }}" class="mb-6">
        <label for="q" class="sr-only">Поиск</label>
        <div class="flex gap-2">
            <input id="q" name="q" value="{{ $q }}" type="search"
                   class="flex-1 rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                   placeholder="корень / лемма / заголовок">
            <button type="submit" class="px-4 py-2 rounded-xl bg-brand text-white text-sm font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">Найти</button>
        </div>
    </form>

    @include('student.visualdcs._list', ['items' => $items, 'surface' => $surface, 'preview' => false, 'progressById' => $progressById])

    @if(($total ?? 0) > ($perPage ?? 50))
        <nav class="mt-6 flex items-center justify-between text-sm" aria-label="Страницы">
            @if($page > 1)
                <a class="font-bold text-gray-900 underline decoration-brand decoration-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                   href="{{ route('student.visualdcs.index', ['surface' => $surface, 'page' => $page - 1, 'q' => $q ?: null]) }}">← назад</a>
            @else
                <span></span>
            @endif
            @if(($page * $perPage) < $total)
                <a class="font-bold text-gray-900 underline decoration-brand decoration-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                   href="{{ route('student.visualdcs.index', ['surface' => $surface, 'page' => $page + 1, 'q' => $q ?: null]) }}">дальше →</a>
            @endif
        </nav>
    @endif
</div>
@endsection
