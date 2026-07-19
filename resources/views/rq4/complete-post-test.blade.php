@extends('layouts.student')
@section('title', 'Второй тест пройден')
@section('header', 'Второй тест пройден')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">
    <div class="bg-white rounded-2xl border border-gray-100 p-6">
        <h1 class="text-2xl font-extrabold text-[#101010] mb-4">Спасибо!</h1>
        <p class="text-gray-700">
            На этом основная часть закончена. Через 4 недели мы пришлем вам напоминание пройти
            последний короткий тест — дополнительных материалов присылать не будем, это
            специально: мы измеряем, что осталось в памяти.
        </p>
    </div>
</div>
@endsection
