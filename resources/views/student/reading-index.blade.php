@extends('layouts.student')

@section('title', 'Чтение с разбором')
@section('header', 'Чтение с разбором')

@section('content')
    <p class="text-sm text-gray-400 mb-6 max-w-2xl">
        Тексты курса «Старт чтения» с пословным разбором: лемма, морфология, значение.
        Нажмите на слово прямо в тексте.
    </p>

    <ul class="space-y-3 max-w-2xl">
        @foreach($packs as $entry)
            <li>
                <a href="{{ route('student.reading.pack', $entry['slug']) }}"
                   class="flex items-baseline justify-between gap-4 px-4 py-3 rounded-xl bg-[#161b28] border border-gray-700/60 hover:border-[#E85C24]/50 transition-colors">
                    <span class="text-gray-200">{{ $entry['title'] }}</span>
                    <span class="text-gray-500 text-xs shrink-0">
                        {{ $entry['stats']['sentences'] }} предл. · {{ $entry['stats']['tokens'] }} слов
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
@endsection
