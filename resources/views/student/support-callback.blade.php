@extends('layouts.student')

@section('title', 'Обратный звонок')
@section('header', 'Обратный звонок')

@section('content')
<div class="w-full max-w-2xl mx-auto flex flex-col gap-8 font-nunito pb-20">

    <div class="bg-gradient-to-r from-white to-gray-50 rounded-[2rem] p-8 md:p-10 shadow-sm border border-gray-100">
        <h1 class="text-3xl font-black text-[#1A1A1A] mb-2 tracking-tight">Заказать обратный звонок</h1>
        <p class="text-gray-500 font-medium text-sm md:text-base">
            Оставьте номер телефона — куратор перезвонит вам в рабочее время. Мы не звоним автоматически:
            заявка попадает в очередь куратора как обычная задача.
        </p>
    </div>

    @if (session('callback_request_status'))
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-2xl p-4 font-semibold text-sm">
            {{ session('callback_request_status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-2xl p-4 font-semibold text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('student.support.callback.store') }}"
          class="bg-white rounded-[2rem] shadow-sm border border-gray-100 p-8 flex flex-col gap-5">
        @csrf

        <div>
            <label for="callback-phone" class="block text-sm font-bold text-gray-700 mb-2">Телефон</label>
            <input id="callback-phone" type="tel" name="phone" required maxlength="40"
                   value="{{ old('phone', auth()->user()->phone) }}"
                   class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand/40"
                   placeholder="+7 900 000-00-00">
        </div>

        <div>
            <label for="callback-note" class="block text-sm font-bold text-gray-700 mb-2">Комментарий (необязательно)</label>
            <textarea id="callback-note" name="note" maxlength="500" rows="3"
                      class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand/40"
                      placeholder="Когда удобно перезвонить, о чём вопрос">{{ old('note') }}</textarea>
        </div>

        <label class="flex items-start gap-3 text-sm text-gray-600 font-medium">
            <input type="checkbox" name="consent" value="1" required
                   class="mt-1 w-4 h-4 rounded border-gray-300 text-brand focus:ring-brand/40">
            <span>
                Я согласен(на) на обработку номера телефона школой для одного обратного звонка по этой заявке.
                Разговор не записывается.
            </span>
        </label>

        <button type="submit"
                class="mt-2 w-full bg-brand text-white font-bold rounded-xl px-6 py-3 text-sm hover:bg-brand/90 transition-colors">
            Заказать звонок
        </button>
    </form>
</div>
@endsection
