@extends('layouts.articles')

@section('title', 'Оплата курса — способы, рассрочка, если платёж не прошёл — FAQ')
@section('meta_description', 'Как оплатить курс ОРС: способы оплаты, рассрочка, что делать, если платёж отклонён или обещание оплаты просрочено, куда писать куратору.')

@push('head')
    <meta name="robots" content="index, follow">
@endpush

@section('content')
<div class="container mx-auto px-4 max-w-3xl py-10 md:py-14 font-nunito">

    <nav class="mb-6 text-sm text-gray-500">
        <a href="{{ url('/') }}" class="hover:text-brand transition-colors">Главная</a>
        <span class="mx-2 text-gray-300">/</span>
        <a href="{{ route('student.dashboard') }}" class="hover:text-brand transition-colors">Кабинет</a>
        <span class="mx-2 text-gray-300">/</span>
        <span class="text-gray-700">Оплата</span>
    </nav>

    <div class="flex items-center gap-3 mb-6">
        <div class="w-12 h-12 rounded-xl bg-orange-50 text-brand flex items-center justify-center shrink-0">
            <i class="fas fa-credit-card text-lg"></i>
        </div>
        <div>
            <h1 class="text-2xl md:text-3xl font-extrabold text-[#101010] leading-tight">
                Оплата курса
            </h1>
            <p class="text-sm text-gray-500 mt-0.5">Способы оплаты, рассрочка, если платёж не прошёл</p>
        </div>
    </div>

    <p class="text-gray-600 leading-relaxed mb-8">
        <span class="font-bold text-gray-900">Оплатить можно из личного кабинета</span> — на странице
        «Оплата и доступ» показаны все открытые долги и кнопка оплаты рядом с каждым.
        Если что-то не сходится или платёж не проходит — ниже частые ситуации и куда писать.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mb-3">Способы оплаты</h2>
    <ul class="space-y-2 text-gray-700 leading-relaxed mb-8">
        <li>Банковской картой — целиком или в рассрочку, прямо на странице оплаты</li>
        <li>Если сумма пугает целиком — можно оформить <strong>рассрочку</strong> вместо разового платежа</li>
    </ul>

    <h2 class="text-xl font-bold text-gray-900 mb-3">Платёж отклонён или отменён</h2>
    <p class="text-gray-700 leading-relaxed mb-2">
        Банк иногда блокирует онлайн-платёж без видимой причины (лимит, антифрод, устаревшие данные карты).
        Это не ошибка с вашей стороны — прогресс и уже оплаченные уроки никуда не делись.
    </p>
    <ol class="list-decimal list-inside space-y-2 text-gray-700 leading-relaxed mb-8">
        <li>Откройте «Оплата и доступ» в кабинете</li>
        <li>Попробуйте оплатить ещё раз — часто со второго раза проходит</li>
        <li>Не проходит снова — попробуйте другую карту или рассрочку</li>
        <li>Не получается — напишите куратору, разберёмся вместе</li>
    </ol>

    <h2 class="text-xl font-bold text-gray-900 mb-3">Просрочена договорённость об оплате</h2>
    <p class="text-gray-700 leading-relaxed mb-8">
        Если был согласован график (обещание/рассрочка) и срок прошёл — это тоже не блокирует уже оплаченные
        уроки и живые занятия группы. Зайдите в «Оплата и доступ» и внесите ближайший платёж по графику,
        либо напишите куратору — можно перенести дату.
    </p>

    <div class="bg-orange-50/80 border border-orange-100 rounded-2xl p-5 mb-8">
        <p class="font-bold text-gray-900 mb-2">Если вопрос в сумме</p>
        <p class="text-gray-700 text-sm leading-relaxed">
            Можно оформить курс в рассрочку вместо разового платежа. А если остались сомнения или вопросы
            по программе — куратор с радостью ответит лично.
        </p>
    </div>

    <p class="text-gray-600 leading-relaxed text-sm">
        Не получается — напишите куратору в <a href="https://t.me/rusamskrtam" class="text-brand underline hover:no-underline">Telegram</a>:
        email входа, курс, что видите на экране.
    </p>

    <p class="mt-8">
        <a href="{{ route('login') }}"
           class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-brand text-white font-extrabold text-sm hover:bg-brand-hover transition-colors">
            Войти в кабинет
            <i class="fas fa-arrow-right text-xs"></i>
        </a>
    </p>
</div>
@endsection
