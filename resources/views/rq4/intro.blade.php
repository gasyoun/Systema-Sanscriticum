@extends('layouts.student')
@section('title', 'Исследование: ряды и типы корней')
@section('header', 'Исследование: ряды и типы корней')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">
    <h1 class="text-2xl md:text-3xl font-extrabold text-[#101010] tracking-tight mb-2">
        Исследование: ряды и типы корней
    </h1>
    <div class="w-16 h-1.5 bg-brand rounded-full mb-6"></div>

    @if($participant)
        <div class="bg-white rounded-2xl border border-gray-100 p-6">
            <p class="text-gray-700 mb-4">Вы уже участвуете в исследовании.</p>
            <a href="{{ route('rq4.diagnostic', ['phase' => 'pre_test']) }}"
               class="inline-block px-5 py-2.5 rounded-lg bg-brand text-white font-semibold hover:bg-brand-hover transition-colors">
                Продолжить
            </a>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-100 p-6">
            <p class="text-gray-700 mb-4 whitespace-pre-line">{{ $consentText ?? '' }}</p>

            <form method="POST" action="{{ route('rq4.enroll') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Вы уже изучали классификацию Зализняка (ряды/типы корней)?
                    </label>
                    <select name="prior_exposure" required class="w-full rounded-lg border-gray-300">
                        <option value="none">Нет, я на этапе учебника Кочергиной (или раньше)</option>
                        <option value="kochergina">Закончил(а) учебник Кочергиной, Зализняка еще не проходил(а)</option>
                        <option value="beyond">Уже знаком(а) с классификацией Зализняка</option>
                    </select>
                </div>

                <label class="flex items-start gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="consent" value="1" required class="mt-1">
                    <span>Я согласен(на) на участие в исследовании на условиях, описанных выше.</span>
                </label>

                <button type="submit"
                        class="inline-block px-5 py-2.5 rounded-lg bg-brand text-white font-semibold hover:bg-brand-hover transition-colors">
                    Начать
                </button>
            </form>
        </div>
    @endif
</div>
@endsection
