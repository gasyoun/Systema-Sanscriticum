@extends('layouts.student')
@section('title', 'Пилот Лаборатории грамматики')
@section('header', 'Пилот Лаборатории грамматики')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">
    <h1 class="text-2xl md:text-3xl font-extrabold text-[#101010] tracking-tight mb-2">
        Пилот Лаборатории грамматики
    </h1>
    <div class="w-16 h-1.5 bg-brand rounded-full mb-6"></div>

    @if($participant)
        <div class="bg-white rounded-2xl border border-gray-100 p-6">
            <p class="text-gray-700 mb-4">Вы уже дали согласие на участие в пилоте.</p>
            <a href="{{ route('student.grammar-lab.index') }}"
               class="inline-block px-5 py-2.5 rounded-lg bg-brand text-white font-semibold hover:bg-brand-hover transition-colors">
                Перейти к темам
            </a>
        </div>
    @elseif(! $eligible)
        <div class="bg-white rounded-2xl border border-gray-100 p-6">
            <p class="text-gray-700">Пилот открыт только для текущих студентов промежуточного уровня. Если вы учитесь в этой группе, напишите куратору.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-100 p-6">
            <p class="text-gray-700 mb-4 whitespace-pre-line">{{ $consentText }}</p>

            <form method="POST" action="{{ route('student.grammar-lab.pilot.enroll') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Вы уже проходили Кочергину / Зализняка?
                    </label>
                    <select name="prior_exposure" required class="w-full rounded-lg border-gray-300">
                        <option value="none">Ещё на Кочергиной или раньше</option>
                        <option value="kochergina">Кочергина пройдена, Зализняка ещё нет</option>
                        <option value="beyond">Уже знаком(а) с Зализняком</option>
                    </select>
                </div>
                <label class="flex items-start gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="consent" value="1" required class="mt-1">
                    <span>Я согласен(на) участвовать на условиях выше. Платы нет, подписка не оформляется.</span>
                </label>
                <button type="submit"
                        class="inline-block px-5 py-2.5 rounded-lg bg-brand text-white font-semibold hover:bg-brand-hover transition-colors">
                    Участвовать
                </button>
            </form>
        </div>
    @endif
</div>
@endsection
