@extends('layouts.shop')

@section('title', 'Просмотр VisualDCS')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 py-10">
    {{-- H2869: витринный layout тёмный (#0A0D14) — gray-900/gray-500 на нём
         проваливали WCAG AA (1.10:1 и 4.02:1 по замеру Dusk-аудита). --}}
    <h1 class="text-2xl font-extrabold text-white mb-2">
        Просмотр: {{ $surface === 'verb' ? 'глагол' : ($surface === 'nominal' ? 'имя' : 'пассаж') }}
    </h1>
    <p class="text-sm text-slate-300 mb-6">Ограниченный набор частотных единиц. Полный тренажёр — в кабинете после оплаты курса.</p>

    @include('student.visualdcs._list', ['items' => $items, 'surface' => $surface, 'preview' => true, 'progressById' => []])
</div>
@endsection
