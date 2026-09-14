@extends('layouts.student')

@section('title', $channel === 'telegram' ? 'Привязка Telegram' : 'Привязка VK')
@section('header', $channel === 'telegram' ? 'Привязка Telegram-бота' : 'Привязка VK-бота')

@section('content')
<div class="max-w-xl mx-auto px-4 py-10 font-nunito">
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 sm:p-8">
        @php
            $isTg = $channel === 'telegram';
            $accent = $isTg ? '#0088cc' : '#0077FF';
            $actionUrl = $isTg ? route('telegram.connect.start') : route('vk.connect.start');
        @endphp

        <h2 class="text-xl font-extrabold text-[#101010] mb-2">
            @if($isTg)
                Подключите уведомления в Telegram
            @else
                Подключите уведомления во ВКонтакте
            @endif
        </h2>

        @if($connected)
            <p class="mb-5 inline-flex items-center gap-1.5 text-sm font-bold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-2.5 py-1.5">
                <i class="fas fa-check"></i> Бот уже привязан к вашему аккаунту.
            </p>
        @else
            <ul class="mb-5 space-y-1.5 text-sm text-gray-600">
                <li class="flex items-start gap-2"><i class="fas fa-bell mt-0.5 w-4 text-center shrink-0" style="color: {{ $accent }};"></i><span>Напоминания о занятиях и сроках оплат</span></li>
                <li class="flex items-start gap-2"><i class="fas fa-robot mt-0.5 w-4 text-center shrink-0" style="color: {{ $accent }};"></i><span>ИИ-куратор отвечает на вопросы об учёбе круглосуточно</span></li>
                <li class="flex items-start gap-2"><i class="fas fa-comments mt-0.5 w-4 text-center shrink-0" style="color: {{ $accent }};"></i><span>Ответы живого куратора прямо в мессенджере</span></li>
            </ul>
        @endif

        {{-- H3313: выдача токена привязки — только CSRF-защищённым POST --}}
        <form method="POST" action="{{ $actionUrl }}" target="_blank" class="inline-flex w-full">
            @csrf
            <button type="submit"
                    class="w-full px-6 py-3.5 text-white text-sm font-extrabold rounded-xl transition-all duration-300"
                    style="background-color: {{ $accent }};">
                @if($isTg)
                    Открыть Telegram и привязать
                @else
                    Открыть ВКонтакте и привязать
                @endif
            </button>
        </form>

        <p class="mt-4 text-xs text-gray-400">Кнопка откроет мессенджер в новой вкладке и безопасно свяжет его с вашим аккаунтом.</p>
    </div>
</div>
@endsection
