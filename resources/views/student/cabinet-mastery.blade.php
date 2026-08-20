@extends('layouts.student')

@section('title', $bank['title'])
@section('header', 'Проверка кабинета')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <a href="{{ route('student.dashboard') }}"
       class="inline-flex items-center gap-2 text-sm font-bold text-gray-500 hover:text-brand transition-colors mb-6">
        <i class="fas fa-arrow-left text-xs"></i> В кабинет
    </a>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 md:p-8">
        <h1 class="text-2xl font-extrabold text-gray-900 leading-tight">{{ $bank['title'] }}</h1>
        <p class="text-gray-600 mt-2 leading-relaxed">{{ $bank['intro'] }}</p>
        <p class="text-sm text-gray-500 mt-1">Зачёт — {{ $bank['pass'] }} из {{ count($questions) }}.</p>

        @if ($result)
            <div class="mt-4 rounded-2xl px-4 py-3 text-sm font-bold {{ $result['passed'] ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-900' }}">
                {{ $result['score'] }} из {{ $result['total'] }}
                @if ($result['passed'])
                    — зачёт.
                @else
                    — порог {{ $result['pass'] }}. Ниже — почему.
                @endif
            </div>
        @endif

        <form method="post" action="{{ route('student.cabinet-mastery.submit') }}" class="mt-6 space-y-6">
            @csrf
            @foreach ($questions as $index => $question)
                @php($qid = $question['id'])
                <fieldset>
                    <legend class="font-extrabold text-gray-900">{{ $index + 1 }}. {{ $question['prompt'] }}</legend>
                    <div class="mt-3 space-y-2">
                        @foreach ($question['options'] as $key => $label)
                            <label class="flex items-start gap-2 text-gray-700 cursor-pointer">
                                <input type="radio" name="answers[{{ $qid }}]" value="{{ $key }}"
                                       class="mt-1"
                                       @checked(($answers[$qid] ?? '') === $key)>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('answers.'.$qid)
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @if ($result)
                        @foreach ($result['details'] as $row)
                            @if ($row['id'] === $qid)
                                <p class="mt-2 text-sm {{ $row['ok'] ? 'text-green-700' : 'text-red-700' }}">
                                    @if ($row['ok'])
                                        Верно.
                                    @else
                                        Не то. {{ $row['why'] }}
                                    @endif
                                </p>
                            @endif
                        @endforeach
                    @endif
                </fieldset>
            @endforeach

            <button type="submit"
                    class="inline-flex items-center justify-center px-5 py-3 rounded-xl bg-brand text-white font-extrabold hover:opacity-90">
                Проверить
            </button>
        </form>
    </div>
</div>
@endsection
