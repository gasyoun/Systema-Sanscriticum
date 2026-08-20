@extends('layouts.student')

@section('title', 'Словарь Костиной')
@section('header', 'Словарь Костиной')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito" data-testid="hindi-kostina-dict-index">
    <div class="relative bg-white rounded-[2rem] shadow-sm border border-gray-100 p-8 md:p-10 mb-8 overflow-hidden">
        <div class="inline-flex items-center px-4 py-1.5 rounded-full text-xs font-extrabold uppercase tracking-widest bg-orange-50 text-brand mb-5">
            Словарь
        </div>
        <h1 class="text-3xl md:text-4xl font-extrabold text-[#101010] mb-3 tracking-tight leading-tight">
            Словарь Костиной
        </h1>
        <p class="text-gray-500 text-base leading-relaxed max-w-2xl">
            Слова и выражения из модулей учебника «Хинди для начинающих». Упражнения собраны из этих пар, не из расшифровки занятия.
        </p>
        @if($playlistEnabled)
            <a href="{{ route('student.programme.hindi') }}"
               class="inline-flex items-center gap-2 mt-6 text-sm font-bold text-brand hover:underline"
               data-testid="hindi-kostina-dict-back">
                ← Мой хинди
            </a>
        @endif
    </div>

    <div class="space-y-3">
        @foreach($modules as $mod)
            <a href="{{ route('student.programme.hindi.vocab.show', $mod['id']) }}"
               class="block bg-white rounded-2xl border border-gray-100 p-5 hover:border-brand/30 hover:shadow-lg transition-all"
               data-testid="hindi-kostina-dict-module"
               data-module="{{ $mod['id'] }}">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-lg font-extrabold text-gray-900">{{ $mod['id'] }} · {{ $mod['label'] }}</div>
                        <div class="text-sm text-gray-500">{{ $mod['count'] }} {{ \App\Support\Plural::ru($mod['count'], 'слово', 'слова', 'слов') }}</div>
                    </div>
                    <i class="fas fa-chevron-right text-gray-300"></i>
                </div>
            </a>
        @endforeach
    </div>
</div>
@endsection
