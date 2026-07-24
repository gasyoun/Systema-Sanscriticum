@extends('layouts.student')

@section('title', 'Записи')
@section('header', 'Записи')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">
    <h2 class="text-3xl font-extrabold text-[#101010] mb-2">Записи</h2>
    <p class="text-gray-500 mb-6">
        Полки владения (Идут сейчас / Мои записи / Истёкшие / Завершённые) появятся в Phase 2.
        Сейчас — точка входа job-nav R29.0.
    </p>

    @if ($suppressOffers ?? false)
        <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-6">
            Режим восстановления: предложения о покупках скрыты, пока не решена проблема с оплатой.
        </p>
    @endif

    <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <p class="text-sm text-gray-600">
            Ваши курсы с записями доступны из
            <a href="{{ route('student.dashboard') }}" class="font-bold text-[#E85C24] hover:underline">Сегодня</a>
            и из списка курсов в боковом меню.
        </p>
    </div>
</div>
@endsection
