{{--
    H2168 — the reading list of ONE cohort course (Nalopākhyāna, Subhāṣita, ...).

    Distinct from student/reading-index.blade.php, which is the «Старт чтения» pilot's
    own list: that one shows cohort-wide SRS progress, this one is render-only (the
    tap-token/SRS half is outside H2168's fence) and scoped to the course the student
    actually paid for. Packs arrive in course order with a 1-based `position`.
--}}
@extends('layouts.student')

@section('title', 'Чтение — '.$courseTitle)
@section('header', 'Чтение — '.$courseTitle)

@section('content')
    <p class="text-sm text-gray-400 mb-6 max-w-2xl">
        Тексты курса с пословным разбором: лемма, форма, значение. Нажмите на слово прямо в тексте.
        @if(count($packs) > 1)
            Тексты идут в порядке чтения курса.
        @endif
    </p>

    <ul class="space-y-3 max-w-2xl">
        @foreach($packs as $entry)
            <li>
                <a href="{{ route('student.reading.course.pack', [$course, $entry['slug']]) }}"
                   class="flex items-baseline justify-between gap-4 px-4 py-3 rounded-xl bg-[#161b28] border border-gray-700/60 hover:border-brand/50 transition-colors">
                    <span class="text-gray-200">
                        @if(count($packs) > 1)
                            <span class="text-gray-600 mr-2">{{ $entry['position'] }}.</span>
                        @endif
                        {{ $entry['title'] }}
                    </span>
                    <span class="text-gray-500 text-xs shrink-0">
                        {{ $entry['stats']['sentences'] }} предл. · {{ $entry['stats']['tokens'] }} слов
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
@endsection
