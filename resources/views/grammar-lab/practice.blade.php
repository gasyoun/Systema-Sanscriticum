@extends('layouts.student')

@section('title', 'Практика: '.$topic->title_ru)
@section('header', 'Практика')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 pb-12">
    <p class="text-xs text-gray-500 mb-2">{{ $topic->title_ru }}</p>
    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ $payload['prompt_ru'] ?? '' }}</h1>
    @if(!empty($payload['prompt_iast']))
        <p class="text-sm text-gray-600 mb-4">{{ $payload['prompt_iast'] }}</p>
    @endif

    @if($errors->any())
        <p class="text-sm text-red-700 mb-4" role="alert">{{ $errors->first() }}</p>
    @endif

    @if(!empty($result))
        <div class="mb-6 px-4 py-3 rounded-xl border {{ $result['correct'] ? 'border-green-600' : 'border-red-600' }}"
             role="status">
            @if($result['correct'])
                <p class="font-bold text-green-800">Верно</p>
            @else
                <p class="font-bold text-red-800">Неверно</p>
                <p class="text-sm text-gray-700 mt-1">Правильный ответ: {{ $payload['answer'] ?? '' }}</p>
            @endif
        </div>
        <form method="post" action="{{ route('student.grammar-lab.srs', $topic->slug) }}" class="mb-6">
            @csrf
            <button type="submit"
                    class="px-3 py-2 rounded-xl bg-brand text-white text-sm font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand">
                В колоду FSRS
            </button>
        </form>
        @if(session('status') === 'added_to_srs')
            <p class="text-sm text-gray-600 mb-6">Карточка в вашей колоде «Лаборатория грамматики». Расписание ведёт существующий FSRS.</p>
        @endif
        @if(!empty($recommendation))
            <div class="mb-6 px-4 py-3 rounded-xl border border-gray-200">
                <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Дальше</p>
                <a href="{{ route('student.grammar-lab.show', $recommendation['slug']) }}"
                   class="font-bold text-gray-900 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                    {{ $recommendation['title_ru'] }}
                </a>
                <p class="text-sm text-gray-600 mt-1">{{ $recommendation['reason'] }}</p>
            </div>
        @endif
    @else
        <form method="post" action="{{ route('student.grammar-lab.attempt', $topic->slug) }}" class="mb-8">
            @csrf
            @if(!empty($payload['choices']) && is_array($payload['choices']))
                <fieldset class="space-y-2 mb-4">
                    <legend class="sr-only">Варианты ответа</legend>
                    @foreach($payload['choices'] as $choice)
                        <label class="flex items-center gap-2 px-3 py-2 rounded-xl border border-gray-200">
                            <input type="radio" name="answer" value="{{ $choice }}" required
                                   class="focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                            <span>{{ $choice }}</span>
                        </label>
                    @endforeach
                </fieldset>
            @else
                <label for="grammar-lab-answer" class="sr-only">Ответ</label>
                <input id="grammar-lab-answer" name="answer" type="text" required
                       class="w-full rounded-xl border border-gray-300 px-3 py-2 text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
            @endif
            <button type="submit"
                    class="mt-3 px-4 py-2 rounded-xl bg-brand text-white font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand">
                Проверить
            </button>
        </form>
    @endif

    @if(!empty($mastery))
        <p class="text-sm text-gray-500 mb-6">Освоение: {{ $mastery->state }}</p>
    @endif

    <p>
        <a href="{{ route('student.grammar-lab.show', $topic->slug) }}" class="text-sm text-brand underline">К теме</a>
    </p>
</div>
@endsection
