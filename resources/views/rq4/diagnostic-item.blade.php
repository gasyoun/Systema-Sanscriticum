@extends('layouts.student')
@section('title', 'Тест: определите корень')
@section('header', 'Тест: определите корень')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">
    <h1 class="text-2xl md:text-3xl font-extrabold text-[#101010] tracking-tight mb-2">
        Определите ряд, тип и seṭ/aniṭ корня
    </h1>
    <p class="text-gray-500 mb-6">Вопрос {{ $answeredCount + 1 }} из {{ $totalCount }}</p>
    <div class="w-16 h-1.5 bg-[#E85C24] rounded-full mb-6"></div>

    <div class="bg-white rounded-2xl border border-gray-100 p-6">
        <div class="text-4xl font-serif text-center mb-6" lang="sa-Latn">{{ $item['root'] }}</div>

        <form method="POST" action="{{ route('rq4.diagnostic.submit', ['phase' => $phase]) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="item_slp1" value="{{ $item['slp1'] }}">

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Ряд</label>
                <select name="answered_ryad" required class="w-full rounded-lg border-gray-300">
                    <option value="A₁">A₁</option>
                    <option value="I₁">I₁</option>
                    <option value="U₁">U₁</option>
                    <option value="R₁">R₁</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Тип</label>
                <select name="answered_tip" required class="w-full rounded-lg border-gray-300">
                    <option value="I">I</option>
                    <option value="II">II</option>
                    <option value="III">III</option>
                    <option value="IV">IV</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Seṭ / aniṭ</label>
                <select name="answered_set" required class="w-full rounded-lg border-gray-300">
                    <option value="seṭ">seṭ</option>
                    <option value="aniṭ">aniṭ</option>
                    <option value="veṭ">veṭ</option>
                </select>
            </div>

            <button type="submit"
                    class="inline-block px-5 py-2.5 rounded-lg bg-[#E85C24] text-white font-semibold hover:bg-[#d14e1a] transition-colors">
                Ответить
            </button>
        </form>
    </div>
</div>
@endsection
