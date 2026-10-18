@extends('layouts.student')

@section('title', $lesson->title)
@section('header', 'Урок ещё не открыт')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <a href="{{ route('student.course', $course->slug) }}"
       class="inline-flex items-center gap-2 text-sm font-bold text-gray-500 hover:text-brand transition-colors mb-6">
        <i class="fas fa-arrow-left text-xs"></i> {{ $course->title }}
    </a>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8 md:p-10 text-center">
        <div class="w-20 h-20 mx-auto rounded-full bg-amber-50 border border-amber-100 flex items-center justify-center mb-6">
            <i class="fas fa-hourglass-half text-3xl text-amber-500"></i>
        </div>

        <p class="text-[10px] font-bold uppercase tracking-widest text-brand mb-2">
            Урок {{ $lesson->block_number }} · {{ $course->title }}
        </p>
        <h1 class="text-2xl font-extrabold text-gray-900 leading-tight">{{ $lesson->title }}</h1>

        <p class="mt-5 text-gray-600 leading-relaxed">
            Этот урок откроется
            <b class="text-gray-900">{{ $openAt->translatedFormat('d F Y') }}</b>
            — как раз успеете закрепить предыдущий. 🙌
        </p>
        <p class="mt-2 text-sm text-gray-500">
            В день открытия пришлём уведомление на почту
            @if (auth()->user()?->email)
                (<b>{{ auth()->user()->email }}</b>)
            @endif
            — и кнопка «Приступить» появится прямо здесь.
        </p>

        <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="{{ route('student.course', $course->slug) }}"
               class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-brand text-white font-extrabold hover:opacity-90">
                <i class="fas fa-list-ul mr-1"></i> К списку уроков
            </a>
            <a href="{{ route('student.messages') }}"
               class="inline-flex items-center gap-2 px-6 py-3 rounded-xl border border-gray-200 text-gray-700 font-bold hover:border-brand hover:text-brand">
                <i class="fab fa-telegram-plane mr-1"></i> Написать куратору
            </a>
        </div>
    </div>

    <p class="text-center text-xs text-gray-400 mt-6">
        Уроки курса открываются по мере прохождения — каждые
        {{ (int) config('mini_courses.drip_days', 3) }} дня.
    </p>
</div>
@endsection
