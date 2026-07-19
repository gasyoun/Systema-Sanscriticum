@extends('layouts.shop')

@section('title', 'Консультация по онлайн-курсам Общества ревнителей санскрита')

@section('content')
<div class="max-w-2xl mx-auto py-12 px-4">

    {{-- Anti-urgency hero — no countdown, no "spots left", evergreen entry any day. --}}
    <div class="text-center mb-10">
        <h1 class="text-3xl md:text-4xl font-black text-[#1A1A1A] mb-4">
            Пойми, как устроен санскрит, и выбери свой курс
        </h1>
        <p class="text-gray-600 text-lg font-medium">
            3 дня, ~15 минут в день, в своем темпе. Личный маршрут, а не общий поток —
            начать можно в любой день.
        </p>
    </div>

    @if (session('marathon_result'))
        <div class="mb-8 p-6 bg-green-50 border border-green-200 rounded-2xl">
            <p class="font-bold text-green-800 mb-3">Вы записаны! Чтобы получить личный День 1 и День 2 в Telegram,
                нажмите кнопку ниже и запустите бота — без этого шага дни не придут.</p>
            @if (session('marathon_telegram_link'))
                <a href="{{ session('marathon_telegram_link') }}" target="_blank" rel="noopener"
                   class="inline-block bg-[#0088cc] text-white font-bold px-6 py-3 rounded-xl">
                    Продолжить в Telegram
                </a>
            @endif
            <p class="text-sm text-green-700 mt-3">Канал с общими новостями:
                <a href="{{ config('marathon.telegram_channel_url') }}" class="underline">{{ config('marathon.telegram_channel_url') }}</a></p>
        </div>

        @if (session('marathon_track') === 'paid' && ! session('marathon_paid'))
            <div class="mb-8 p-6 bg-orange-50 border border-orange-200 rounded-2xl">
                <p class="font-bold text-[#1A1A1A] mb-1">Трек «с проверкой» выбран, но еще не оплачен</p>
                <p class="text-sm text-gray-600 mb-4">
                    Оплатите {{ $paidTrackPrice }} ₽ — куратор будет проверять вашу практику Дней 1–2,
                    и вам гарантировано место на живой консультации Дня 3.
                </p>
                <form method="POST" action="{{ route('marathon.pay') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="contact" value="{{ session('marathon_contact') }}">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Email для чека</label>
                        <input type="email" name="email" required
                               class="w-full rounded-xl border-gray-200 focus:border-[#E85C24] focus:ring-[#E85C24]">
                    </div>
                    @error('email')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="w-full px-6 py-3 bg-[#E85C24] hover:bg-[#d34f1c] text-white font-extrabold rounded-xl transition-colors">
                        Оплатить {{ $paidTrackPrice }} ₽
                    </button>
                </form>
            </div>
        @endif
    @endif

    @if (session('error'))
        <div class="mb-8 p-4 bg-red-50 border border-red-200 rounded-2xl text-red-700 text-sm">
            {{ session('error') }}
        </div>
    @endif

    <form method="POST" action="{{ route('marathon.register') }}" class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8 space-y-6">
        @csrf

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-2">Что вас привлекает в санскрите?</label>
            <select name="quiz_goal" required class="w-full rounded-xl border-gray-200 focus:border-[#E85C24] focus:ring-[#E85C24]">
                <option value="">Выберите...</option>
                @foreach ($quizGoals as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-2">Формат участия</label>
            <div class="space-y-2">
                <label class="flex items-start gap-3 p-4 rounded-xl border border-gray-200 cursor-pointer">
                    <input type="radio" name="track" value="free" checked class="mt-1">
                    <span>
                        <span class="font-bold block">Бесплатно</span>
                        <span class="text-sm text-gray-500">2 дня записей + маршрут + консультация в записи.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 p-4 rounded-xl border border-gray-200 cursor-pointer">
                    <input type="radio" name="track" value="paid" class="mt-1">
                    <span>
                        <span class="font-bold block">«С проверкой» — {{ $paidTrackPrice }} ₽</span>
                        <span class="text-sm text-gray-500">
                            Куратор проверяет практику, личный разбор на живой консультации с {{ $hostName }},
                            скидка {{ $couponAmount }} ₽ на любой первый курс.
                        </span>
                    </span>
                </label>
            </div>
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-2">Контакт (телефон, email или Telegram)</label>
            <input type="text" name="contact" required class="w-full rounded-xl border-gray-200 focus:border-[#E85C24] focus:ring-[#E85C24]">
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-2">Имя (необязательно)</label>
            <input type="text" name="name" class="w-full rounded-xl border-gray-200 focus:border-[#E85C24] focus:ring-[#E85C24]">
        </div>

        <button type="submit" class="w-full px-6 py-3.5 bg-[#E85C24] hover:bg-[#d34f1c] text-white font-extrabold rounded-xl transition-colors">
            Записаться
        </button>
    </form>

</div>
@endsection
