@extends('layouts.student')

@section('title', 'Тренажёры VisualDCS')
@section('header', 'Тренажёры VisualDCS')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 pb-12">
    <p class="text-sm text-gray-500 mb-6">
        Три независимых тренажёра по корпусу DCS: глагол, имя, пассаж.
        @if(($state ?? '') === 'preview' || ($state ?? '') === 'unpaid')
            Сейчас открыт публичный просмотр частотных единиц.
        @elseif(($state ?? '') === 'expired')
            Полный доступ истёк — доступен только просмотр.
        @endif
    </p>

    @if(empty($surfaces))
        <p class="text-sm text-gray-500">Тренажёры выключены.</p>
    @else
        <ul class="space-y-3">
            @foreach($surfaces as $surface => $pack)
                <li>
                    <a href="{{ route('student.visualdcs.index', $surface) }}"
                       class="flex items-center justify-between gap-4 px-4 py-3 rounded-xl bg-white border border-gray-200 hover:border-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                        <span class="font-bold text-gray-900">{{ $surface === 'verb' ? 'Глагол' : ($surface === 'nominal' ? 'Имя' : 'Пассаж') }}</span>
                        <span class="text-sm text-gray-500">{{ count($pack['items']) }} единиц</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
