@extends('layouts.shop')

@section('title', 'Лаборатория грамматики')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-12">
    <h1 class="text-3xl font-bold text-white mb-4">Лаборатория грамматики</h1>
    <p class="text-slate-300 mb-4">
        Сопоставление Уитни и Зализняка по чередованию корней и глагольной морфологии —
        с карточками свидетельств и поиском на русском, деванагари, IAST и SLP1.
    </p>
    <p class="text-sm text-slate-400 mb-8">
        Сейчас в пакете {{ (int) $topicCount }} тем.
        Доступ открывается вместе с выбранным курсом или отдельной подпиской.
        Включение на проде — отдельный шаг, не эта страница.
    </p>
    @auth
        <a href="{{ route('student.grammar-lab.index') }}"
           class="inline-flex items-center px-4 py-2 rounded-xl bg-brand text-white font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand">
            Открыть в кабинете
        </a>
    @else
        <a href="{{ route('login') }}"
           class="inline-flex items-center px-4 py-2 rounded-xl bg-brand text-white font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand">
            Войти
        </a>
    @endauth
</div>
@endsection
