@extends('layouts.student')

@section('title', 'Почему баланс праны уменьшился?')
@section('header', 'Помощь')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <a href="{{ route('student.dashboard') }}#prana"
       class="inline-flex items-center gap-2 text-sm font-bold text-gray-500 hover:text-brand transition-colors mb-6">
        <i class="fas fa-arrow-left text-xs"></i> Назад к пране
    </a>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-12 h-12 rounded-xl bg-orange-50 flex items-center justify-center shrink-0">
                <x-prana-lotus class="w-7 h-7" />
            </div>
            <h1 class="text-2xl font-extrabold text-gray-900 leading-tight">Почему баланс праны уменьшился?</h1>
        </div>

        <p class="text-gray-600 leading-relaxed mb-6">
            Прана списывается только по этим причинам — каждая операция видна в
            <a href="{{ route('student.dashboard') }}#prana" class="font-bold text-brand hover:underline">истории на вкладке «Прана»</a>
            (стрелка вниз — списание).
        </p>

        <ul class="space-y-4 mb-8">
            <li class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-violet-50 text-violet-600 flex items-center justify-center shrink-0 text-sm"><i class="fas fa-store"></i></div>
                <p class="text-gray-700 leading-relaxed"><span class="font-bold text-gray-900">Покупка в магазине праны.</span> Вы обменяли прану на перк — покупка видна в разделе «Мои покупки».</p>
            </li>
            <li class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 text-sm"><i class="fas fa-tag"></i></div>
                <p class="text-gray-700 leading-relaxed"><span class="font-bold text-gray-900">Скидка при оплате.</span> Вы списали прану при покупке курса или при взносе по рассрочке — она превратилась в скидку в рублях.</p>
            </li>
            <li class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center shrink-0 text-sm"><i class="fas fa-paper-plane"></i></div>
                <p class="text-gray-700 leading-relaxed"><span class="font-bold text-gray-900">Перевод другому студенту.</span> Вы поделились праной — перевод сразу отражается в истории у обоих.</p>
            </li>
            @if(config('prana.decay.enabled'))
                <li class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center shrink-0 text-sm"><i class="fas fa-fire"></i></div>
                    <p class="text-gray-700 leading-relaxed"><span class="font-bold text-gray-900">Сгорание за неактивность.</span> Если не заходить в кабинет дольше {{ (int) config('prana.decay.inactive_days') }} дней, баланс уменьшается на {{ (int) config('prana.decay.percent') }}% — заходите и учитесь, и прана сохраняется.</p>
                </li>
            @endif
        </ul>

        <div class="bg-orange-50/60 border border-orange-100 rounded-2xl p-5">
            <p class="text-sm text-gray-700 leading-relaxed">
                <span class="font-bold text-gray-900">Ранг и достижения не сгорают.</span>
                Тратится только текущий баланс — накопленная за все время прана, по которой считаются рейтинг и бейджи, не уменьшается.
            </p>
        </div>
    </div>

</div>
@endsection
