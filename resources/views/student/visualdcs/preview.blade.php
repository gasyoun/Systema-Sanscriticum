@extends('layouts.shop')

@section('title', 'Просмотр VisualDCS')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 py-10">
    <h1 class="text-2xl font-extrabold text-gray-900 mb-2">
        Просмотр: {{ $surface === 'verb' ? 'глагол' : ($surface === 'nominal' ? 'имя' : 'пассаж') }}
    </h1>
    <p class="text-sm text-gray-500 mb-6">Ограниченный набор частотных единиц. Полный тренажёр — в кабинете после оплаты курса.</p>

    @include('student.visualdcs._list', ['items' => $items, 'surface' => $surface, 'preview' => true, 'progressById' => []])
</div>
@endsection
