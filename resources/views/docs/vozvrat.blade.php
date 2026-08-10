@extends('layouts.articles')

@section('title', 'Возврат денег — Общество ревнителей санскрита')
@section('meta_description', 'Условия возврата: 100% при отказе до начала услуги, частичный возврат после начала, деньги — в течение 10 рабочих дней. Как подать заявление.')

@push('head')
    <meta name="robots" content="index, follow">
@endpush

@section('content')
    <div class="container mx-auto px-4 max-w-3xl py-10 md:py-14">

        {{-- Хлебная крошка --}}
        <nav class="mb-6 text-sm text-gray-500">
            <a href="{{ url('/') }}" class="hover:text-brand transition-colors">Главная</a>
            <span class="mx-2 text-gray-300">/</span>
            <span class="text-gray-700">Возврат денег</span>
        </nav>

        <h1 class="text-2xl md:text-3xl font-extrabold text-[#101010] leading-tight mb-4">
            Возврат денег
        </h1>

        <p class="text-gray-600 leading-relaxed mb-8">
            Вы можете отказаться от оплаченной услуги в любое время — и до начала, и после.
            Ниже — сколько вернется и как подать заявление. Условия дословно следуют
            <a href="{{ route('docs.show', 'oferta') }}" class="text-brand underline hover:no-underline">оферте</a>
            (раздел 11 и приложение №1 «О порядке возврата денежных средств»);
            при любом расхождении текст оферты имеет приоритет.
        </p>

        {{-- Сколько вернется --}}
        <h2 class="text-xl font-bold text-gray-900 mb-3">Сколько вернется</h2>

        <div class="bg-white border border-gray-200 rounded-2xl p-6 mb-6 shadow-sm">
            <p class="font-semibold text-gray-900 mb-3">100% оплаченной суммы — если:</p>
            <ul class="list-disc pl-5 space-y-2 text-gray-700 leading-relaxed">
                <li>вы отказались до момента начала услуги;</li>
                <li>для «живого» онлайн-участия — уведомление поступило не позднее чем
                    за 1 (один) календарный день до начала;</li>
                <li>для формата «только запись» — уведомление поступило в течение
                    7 (семи) календарных дней с момента предоставления доступа, и вы
                    не приступали к просмотру (система учета не зафиксировала
                    открытия материалов);</li>
                <li>у услуги обнаружены существенные недостатки, подтвержденные в
                    установленном законом порядке, или договор иным образом
                    ненадлежаще исполнен по вине исполнителя.</li>
            </ul>
        </div>

        <div class="bg-gray-50 border border-gray-200 rounded-2xl p-6 mb-6">
            <p class="font-semibold text-gray-900 mb-2">Что считается началом услуги</p>
            <ul class="list-disc pl-5 space-y-1 text-gray-700 leading-relaxed">
                <li>«живое» онлайн-участие — дата и время проведения первого занятия
                    в соответствии с расписанием;</li>
                <li>доступ к записи — момент отправки вам доступа (ссылки, пароля)
                    к материалам.</li>
            </ul>
        </div>

        <div class="bg-white border border-gray-200 rounded-2xl p-6 mb-8 shadow-sm">
            <p class="font-semibold text-gray-900 mb-2">Если услуга уже началась</p>
            <p class="text-gray-700 leading-relaxed mb-3">
                Возврат частичный: из полной стоимости вычитается стоимость пройденных
                учебных модулей. Сумма к возврату = полная стоимость услуги −
                (стоимость одного учебного модуля × количество пройденных модулей).
                Модуль считается пройденным с момента предоставления доступа к его
                материалам (для записей) или с момента его проведения (для «живых»
                занятий). Сколько модулей в услуге и сколько из них пройдено —
                сообщим по запросу.
            </p>
            <p class="text-gray-700 leading-relaxed">
                Других удержаний нет: все расходы, связанные с осуществлением возврата,
                включая комиссии платежных систем, несет исполнитель.
            </p>
        </div>

        {{-- Как подать заявление --}}
        <h2 class="text-xl font-bold text-gray-900 mb-3">Как подать заявление</h2>

        @php
            $refundMailto = 'mailto:rusamskrtam@yandex.ru'
                . '?subject=' . rawurlencode('Заявление на возврат денежных средств')
                . '&body=' . rawurlencode("ФИО:\nНазвание оплаченной услуги:\nДата и сумма оплаты:\nПричина возврата:\nРеквизиты для возврата (номер банковской карты или счета, ФИО владельца):");
        @endphp

        <p class="text-gray-700 leading-relaxed mb-3">
            Направьте заявление на
            <a href="{{ $refundMailto }}" class="text-brand underline hover:no-underline">rusamskrtam@yandex.ru</a>.
            В заявлении укажите:
        </p>
        <ul class="list-disc pl-5 space-y-1 text-gray-700 leading-relaxed mb-6">
            <li>ФИО;</li>
            <li>наименование оплаченной услуги;</li>
            <li>дату и сумму оплаты;</li>
            <li>причину возврата;</li>
            <li>реквизиты для возврата (номер банковской карты или счета, ФИО владельца).</li>
        </ul>

        <div class="flex flex-col sm:flex-row gap-3 mb-3">
            <a href="{{ $refundMailto }}"
               class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-brand text-white font-semibold hover:bg-brand-hover transition-colors shadow-sm">
                <i class="fas fa-envelope"></i>
                Написать заявление
            </a>
            <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener"
               class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl border border-gray-300 text-gray-700 font-semibold hover:border-brand hover:text-brand transition-colors">
                <i class="fab fa-telegram"></i>
                Написать в Telegram
            </a>
        </div>
        <p class="text-sm text-gray-500 mb-8">
            Если сначала хочется просто спросить — напишите в Telegram,
            обычно отвечаем в течение рабочего дня.
        </p>

        {{-- Что дальше --}}
        <h2 class="text-xl font-bold text-gray-900 mb-3">Что происходит дальше</h2>
        <p class="text-gray-700 leading-relaxed mb-3">
            Мы рассмотрим заявление и вернем расчетную сумму в течение 10 (десяти)
            рабочих дней с момента получения корректно оформленного заявления.
            Деньги вернутся тем же способом, которым была произведена оплата,
            если не договоримся об ином.
        </p>

        <p class="text-sm text-gray-500 leading-relaxed mt-8">
            Порядок возврата опирается на статьи 32 и 32.2 Закона РФ
            «О защите прав потребителей» и полностью описан в приложении №1 к
            <a href="{{ route('docs.show', 'oferta') }}" class="underline hover:text-gray-600">публичной оферте</a>.
        </p>
    </div>
@endsection
