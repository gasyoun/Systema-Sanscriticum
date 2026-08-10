@extends('layouts.student')
@section('title', 'Тест пройден')
@section('header', 'Тест пройден')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">
    <div class="bg-white rounded-2xl border border-gray-100 p-6">
        <h1 class="text-2xl font-extrabold text-[#101010] mb-4">Спасибо! Первый тест пройден.</h1>
        <p class="text-gray-700 mb-6">
            Теперь перейдите к материалу — когда закончите его изучать, вернитесь на эту страницу
            и нажмите «Пройти второй тест».
        </p>
        <a href="{{ route('rq4.start-arm') }}"
           class="inline-block px-5 py-2.5 rounded-lg bg-brand text-white font-semibold hover:bg-brand-hover transition-colors">
            Перейти к материалу
        </a>
        <a href="{{ route('rq4.diagnostic', ['phase' => 'post_test']) }}"
           class="inline-block ml-3 px-5 py-2.5 rounded-lg border border-gray-300 text-gray-700 font-semibold hover:bg-gray-50 transition-colors">
            Пройти второй тест
        </a>
    </div>
</div>
@endsection
