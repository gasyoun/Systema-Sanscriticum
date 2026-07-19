@extends('layouts.shop')

@section('title', 'Ваш результат — Консультация по онлайн-курсам ОРС')

@section('content')
<div class="max-w-2xl mx-auto py-12 px-4">
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8 text-center">
        <p class="text-2xl mb-2">📊</p>
        <p class="font-bold text-[#1A1A1A] mb-1">Ваш результат: {{ $score }} из {{ $total }}</p>
        <p class="text-sm text-gray-500">Дальше — День 1 марафона, начнем с того, что вам уже понятно.</p>
    </div>
</div>
@endsection
