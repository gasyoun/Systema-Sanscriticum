@extends('layouts.student')

@section('title', $quiz->title)
@section('header', 'Квиз этапа')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito"
     x-data="{ answered: {}, get count() { return Object.values(this.answered).filter(Boolean).length } }">

    <a href="{{ route('student.course', $course->slug) }}"
       class="inline-flex items-center gap-2 text-sm font-bold text-gray-500 hover:text-brand transition-colors mb-6">
        <i class="fas fa-arrow-left text-xs"></i> {{ $course->title }}
    </a>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 md:p-8">
        <p class="text-[10px] font-bold uppercase tracking-widest text-brand mb-2">Этап {{ $quiz->block_number }}</p>
        <h1 class="text-2xl font-extrabold text-gray-900 leading-tight">{{ $quiz->title }}</h1>
        @if ($quiz->description)
            <p class="text-gray-600 mt-2 leading-relaxed">{{ $quiz->description }}</p>
        @endif
        <p class="text-sm text-gray-500 mt-2">
            Вопросов: {{ count($questions) }} · зачёт — {{ $quiz->pass_score }}% правильных ответов.
        </p>

        {{-- Лучшая попытка до отправки --}}
        @if (! $result && $bestAttempt)
            <div class="mt-4 rounded-2xl px-4 py-3 text-sm font-bold {{ $bestAttempt->passed ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-900' }}">
                Прошлая попытка: {{ $bestAttempt->score }} из {{ $bestAttempt->total }}
                {{ $bestAttempt->passed ? '— зачёт ✅' : '— можно перепройти.' }}
            </div>
        @endif

        {{-- Результат после отправки --}}
        @if ($result)
            <div class="mt-4 rounded-2xl px-4 py-4 text-sm {{ $result['passed'] ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-900' }}">
                <p class="font-extrabold text-base">
                    @if ($result['passed'])
                        🎉 Зачёт! {{ $result['score'] }} из {{ $result['total'] }} ({{ $result['percent'] }}%)
                    @else
                        {{ $result['score'] }} из {{ $result['total'] }} ({{ $result['percent'] }}%) — порог {{ $result['pass'] }}%.
                    @endif
                </p>
                <p class="mt-1 font-medium">
                    @if ($result['passed'])
                        @if ($quiz->block_number >= 5)
                            Этап пройден! Разбор ответов — ниже.
                        @else
                            Этап пройден — переходите к следующему уроку. Разбор ответов — ниже.
                        @endif
                    @else
                        Ниже — разбор: что стоить перечитать в уроке.
                    @endif
                </p>
            </div>
        @endif

        <form method="post" action="{{ route('student.course.quiz.submit', [$course->slug, $quiz->block_number]) }}"
              class="mt-6 space-y-6">
            @csrf
            @foreach ($questions as $index => $question)
                @php($qid = $question['id'])
                <fieldset class="rounded-2xl border border-gray-100 p-4"
                          :class="answered[{{ $qid }}] ? 'border-brand/40' : ''">
                    <legend class="font-extrabold text-gray-900 px-1">
                        {{ $index + 1 }}. {{ $question['prompt'] }}
                    </legend>
                    <div class="mt-3 space-y-2">
                        @foreach ($question['options'] as $key => $label)
                            <label class="flex items-start gap-2.5 text-gray-700 cursor-pointer rounded-xl px-2 py-1.5 hover:bg-orange-50/60 transition-colors">
                                <input type="radio" name="answers[{{ $qid }}]" value="{{ $key }}"
                                       class="mt-1 accent-[color:var(--brand, #f97316)]"
                                       x-model="answered[{{ $qid }}]"
                                       @checked(($answers[$qid] ?? '') === (string) $key)>
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
                                <p class="mt-3 text-sm font-medium rounded-xl px-3 py-2 {{ $row['ok'] ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">
                                    @if ($row['ok'])
                                        ✅ Верно.
                                    @else
                                        ❌ Не то.
                                    @endif
                                    @if ($row['why'])
                                        {{ $row['why'] }}
                                    @endif
                                </p>
                            @endif
                        @endforeach
                    @endif
                </fieldset>
            @endforeach

            @if (! $result)
                <div class="flex flex-wrap items-center gap-4">
                    <button type="submit"
                            class="inline-flex items-center justify-center px-6 py-3 rounded-xl bg-brand text-white font-extrabold hover:opacity-90 transition-opacity">
                        Проверить
                    </button>
                    <span class="text-sm text-gray-500" x-show="count > 0" x-cloak>
                        Отмечено <span x-text="count"></span> из {{ count($questions) }}
                    </span>
                </div>
            @else
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('student.course', $course->slug) }}"
                       class="inline-flex items-center justify-center px-6 py-3 rounded-xl bg-brand text-white font-extrabold hover:opacity-90 transition-opacity">
                        Вернуться к курсу
                    </a>
                    <a href="{{ route('student.course.quiz', [$course->slug, $quiz->block_number]) }}"
                       class="inline-flex items-center justify-center px-6 py-3 rounded-xl border border-gray-200 text-gray-700 font-bold hover:border-brand hover:text-brand transition-colors">
                        Пройти заново
                    </a>
                </div>
            @endif
        </form>
    </div>
</div>
@endsection
