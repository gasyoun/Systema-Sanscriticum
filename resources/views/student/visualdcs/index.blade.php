@extends('layouts.student')

@section('title', $surface === 'verb' ? 'Глагол' : ($surface === 'nominal' ? 'Имя' : 'Пассаж'))
@section('header', $surface === 'verb' ? 'Глагол' : ($surface === 'nominal' ? 'Имя' : 'Пассаж'))

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 pb-12">
    <p class="text-sm text-gray-500 mb-6">
        @if(in_array($state, ['preview', 'unpaid', 'expired'], true))
            Показаны только частотные единицы. Полный список откроется при действующем доступе к курсу.
        @else
            Полный каталог текущего релиза{{ $release ? ' '.$release->release_id : '' }}.
        @endif
    </p>

    @include('student.visualdcs._list', ['items' => $items, 'surface' => $surface, 'preview' => false, 'progressById' => $progressById])
</div>
@endsection
