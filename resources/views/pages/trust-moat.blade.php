@extends('layouts.articles')

{{-- H5184 N10 — trust-moat: «проверка — человек, а не автопроверка».
     Паттерн — docs/vozvrat.blade.php: layouts.articles, хлебная крошка,
     robots index,follow. --}}

@section('title', 'Почему проверяет человек — кураторская проверка домашки | samskrte.ru')
@section('meta_description', 'Домашнюю работу в наших курсах читает и разбирает живой куратор: разбор, а не только балл. Почему мы сознательно не ставим автопроверку.')

@push('head')
    <meta name="robots" content="index, follow">
@endpush

@section('content')
    <div class="container mx-auto px-4 max-w-3xl py-10 md:py-14">

        {{-- Хлебная крошка --}}
        <nav class="mb-6 text-sm text-gray-500">
            <a href="{{ url('/') }}" class="hover:text-brand transition-colors">Главная</a>
            <span class="mx-2 text-gray-300">/</span>
            <span class="text-gray-700">Почему проверяет человек</span>
        </nav>

        <h1 class="text-2xl md:text-3xl font-extrabold text-[#101010] leading-tight mb-4">
            Проверка — человек, а не автопроверка
        </h1>

        <p class="text-gray-600 leading-relaxed mb-8">
            В наших курсах домашнюю работу читает и разбирает живой куратор. Это дороже конвейера — и именно поэтому работает.
        </p>

        <h2 class="text-xl font-bold text-gray-900 mb-3">Кураторский цикл</h2>
        <div class="bg-white border border-gray-200 rounded-2xl p-6 mb-6 shadow-sm">
            <p class="text-gray-700 leading-relaxed">
                Каждая работа проходит глазами преподавателя: что усвоено, где ошибка, что повторить. Ученик получает разбор, а не только балл.
            </p>
        </div>

        <h2 class="text-xl font-bold text-gray-900 mb-3">Поддержка с опорой на материалы</h2>
        <div class="bg-gray-50 border border-gray-200 rounded-2xl p-6 mb-6">
            <p class="text-gray-700 leading-relaxed">
                Вопросы учеников решаются с опорой на корпус материалов курса: ответ подтягивается из реальных занятий, а не придумывается моделью.
            </p>
        </div>

        <h2 class="text-xl font-bold text-gray-900 mb-3">Почему мы не ставим автопроверку</h2>
        <div class="bg-white border border-gray-200 rounded-2xl p-6 mb-8 shadow-sm">
            <p class="text-gray-700 leading-relaxed">
                Автопроверка дешевая и мгновенная — и она не видит, ПОЧЕМУ ученик ошибся. Мы сознательно держим человека в цикле проверки: это основа качества и нашей цены.
            </p>
        </div>

        {{-- Закрывающий CTA → каталог курсов --}}
        <div class="flex flex-col sm:flex-row gap-3 mb-3">
            <a href="{{ route('shop.index') }}"
               class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-brand text-white font-semibold hover:bg-brand-hover transition-colors shadow-sm">
                Посмотреть курсы
                <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
@endsection
