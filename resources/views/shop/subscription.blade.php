{{--
    H3916: лендинг годовой подписки на архив «в записи» (модель VedaTNG).
    Публичная страница живёт только при features.recorded_subscription = true
    (404 до включения). Все цены — из БД: сетка A (MG 06-09-2026)
    Standard 20 000 ₽/год · Профессионал 35 000 ₽/год · квартал 5 500 ₽.

    Честный анкор: сумма актуальных цен «весь курс» по архиву подписки —
    зачёркнутая цена сверху карточек. Рассрочки на запуске НЕТ (миссия H3916).

    Формулировки согласованы с ограничениями H2645 (никакого куратора/ДЗ,
    никаких обещаний пожизненного доступа): подписка даёт доступ ПОКА активна.
--}}
@extends('layouts.shop')

@section('title', 'Подписка «в записи» — весь архив факультативов')

@push('head')
    <meta name="description" content="Годовая подписка на архив факультативов «в записи»: {{ $archiveCount }} записей — все курсы архива и всё пополнение. Стандарт {{ number_format((float) $standard->where('membership_months', 12)->first()?->price ?? 0, 0, '.', ' ') }} ₽/год, Профессионал {{ number_format((float) $professional->first()?->price ?? 0, 0, '.', ' ') }} ₽/год, пробный квартал {{ number_format((float) $standard->where('membership_months', 3)->first()?->price ?? 0, 0, '.', ' ') }} ₽.">
@endpush

@section('content')
<main class="max-w-5xl mx-auto px-4 py-10">
    <p class="text-sm uppercase tracking-widest text-[#38BDF8] font-semibold mb-3">Подписка «в записи»</p>
    <h1 class="text-3xl md:text-4xl font-black leading-tight mb-4">Весь архив факультативов —<br>одной подпиской</h1>
    <p class="text-lg text-slate-300 mb-8">
        {{ $archiveCount }} записей курсов уже в архиве — и каждые полгода архив пополняется:
        завершившийся поток входит в подписку через 6 месяцев после последнего занятия.
        Смотрите в своём темпе, без расписания и дедлайнов.
    </p>

    <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 mb-12">
        <p class="text-slate-400 text-sm mb-1">Стоимость всех записей по отдельности</p>
        <p class="text-3xl md:text-4xl font-black">
            <span class="line-through text-slate-500 mr-3">{{ number_format($anchorSum, 0, '.', ' ') }} ₽</span>
            <span class="text-[#38BDF8]">от {{ number_format((float) $standard->where('membership_months', 3)->first()?->price ?? 0, 0, '.', ' ') }} ₽</span>
        </p>
        <p class="text-slate-400 text-sm mt-2">Честный анкор: сумма актуальных цен «весь курс» по {{ $archiveCount }} записям архива.</p>
    </div>

    <div class="grid md:grid-cols-3 gap-6 mb-12">
        @foreach ($tariffs as $tariff)
            @php $isPro = $tariff->membership_tier === \App\Enums\MembershipTier::Professional; @endphp
            <div class="rounded-2xl border {{ $isPro ? 'border-[#38BDF8]' : 'border-slate-800' }} bg-slate-900/60 p-6 flex flex-col">
                <h2 class="text-xl font-bold mb-1">{{ $isPro ? 'Профессионал' : 'Стандарт' }}</h2>
                <p class="text-slate-400 text-sm mb-4">
                    {{ $tariff->membership_months === 12 ? '12 месяцев' : 'пробный квартал — 3 месяца' }}
                </p>
                <p class="text-3xl font-black mb-4">{{ number_format((float) $tariff->price, 0, '.', ' ') }} ₽
                    <span class="text-base font-normal text-slate-400">{{ $tariff->membership_months === 12 ? '/ год' : '/ квартал' }}</span>
                </p>
                <ul class="text-sm text-slate-300 space-y-2 mb-6 flex-1">
                    <li>✔ Весь архив «в записи» — {{ $archiveCount }} записей</li>
                    <li>✔ Всё пополнение архива (потоки входят через 6 мес.)</li>
                    @if ($isPro)
                        <li>✔ Живые вебинары года</li>
                        <li>✔ 1 бонусный курс до 15 000 ₽</li>
                    @endif
                </ul>
                <a href="{{ route('checkout.show', $tariff) }}"
                   class="block text-center rounded-xl px-4 py-3 font-bold {{ $isPro ? 'bg-[#38BDF8] text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">
                    Оформить
                </a>
            </div>
        @endforeach
    </div>

    <section class="mb-12">
        <h2 class="text-2xl font-bold mb-4">Что входит</h2>
        <p class="text-slate-300 mb-6">Полный список записей архива — доступ открывается в личном кабинете сразу после оплаты:</p>
        <ul class="grid md:grid-cols-2 gap-x-8 gap-y-2 text-sm text-slate-300">
            @foreach ($archive as $course)
                <li>· {{ $course->title }}</li>
            @endforeach
        </ul>
    </section>

    <section class="mb-8">
        <h2 class="text-2xl font-bold mb-4">Частые вопросы</h2>
        <div class="space-y-5 text-slate-300">
            <div>
                <h3 class="font-bold text-slate-100">Это подписка на время или навсегда?</h3>
                <p>Доступ ко всему архиву работает, пока подписка активна. Окончание периода так же честно, как его начало: продлевать или нет — решаете вы.</p>
            </div>
            <div>
                <h3 class="font-bold text-slate-100">Зачтут ли мои прошлые покупки записей?</h3>
                <p>Отдельных зачётов нет. Тем, кто уже покупал записи, — скидка 5% на первый год: она применяется в корзине автоматически.</p>
            </div>
            <div>
                <h3 class="font-bold text-slate-100">Новые курсы попадут в подписку?</h3>
                <p>Да: каждый завершившийся поток входит в архив подписки через 6 месяцев после последнего занятия — правило работает автоматически, без исключений.</p>
            </div>
            <div>
                <h3 class="font-bold text-slate-100">Есть ли рассрочка?</h3>
                <p>На запуске рассрочки нет. Есть пробный квартал — чтобы познакомиться с архивом без годового платежа.</p>
            </div>
        </div>
    </section>
</main>
@endsection
