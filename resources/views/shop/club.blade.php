{{--
    Лендинг подписки на личный кабинет — ПРЕВЬЮ ДЛЯ СУПЕР-АДМИНА.

    Источник контракта — голосовой запуск MG 23-11-2026: помимо разовых
    курсов кабинет должен приносить ежемесячный доход, поэтому страница
    продаёт МЕСЯЧНУЮ лестницу Свободный / Базовый / Клуб / Top с ИИ-функциями
    в тарифах. Публичный запуск — после 1 января 2027 (цены факультативов с
    января могут пересматриваться, до этого значения не фиксируем публично).
    Гейт доступа — MembershipController::landing (isSuperAdmin, иначе 404);
    копи согласуется с голосовым MG, а не со старой лестницей H2645.

    Правила, которые пережили редизайн:
    1. Цены — из строк БД (месячные тарифы курса `club`); fallback на
       membership.tiers.*.monthly_price — только если строки нет вовсе.
       Чекаут-ссылка — только у активного тарифа.
    2. Top не продаётся (без чекаута, плашка «Позже») — отдельный go/no-go.
    3. «Проверка домашних заданий» не входит ни в один тариф — подписка
       существует, чтобы кураторов финансировать, а не расходовать.
    4. Никаких «доступ будет отозван»: продление ручное, автосписаний нет.
    5. «2 пробных ИИ-запроса» — решение MG из голосового; при переводе
       страницы в публичный запуск завести в config/membership.php.
    Годовые тарифы «в записи» (5 500/20 000/35 000) и клубные 3/12 мес
    (5 700/20 400) с витрины сняты — на странице их нет, включая цифры.
--}}
@extends('layouts.shop')

@section('title', 'Подписка на личный кабинет')

@php
    $metaDescription = 'Подписка на личный кабинет Общества ревнителей санскрита: тренажёры и игры, напоминания о платежах и грамотные ответы ИИ на основе оцифрованных грамматик школы.';
@endphp
@section('meta_description', $metaDescription)

@push('head')
    {{-- OG-стиллы H3650 сохранены: клуб и базовый уровень. --}}
    <meta property="og:title" content="Подписка на личный кабинет">
    <meta property="og:image" content="{{ asset('images/og-membership-club.webp') }}?v=h3650">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Подписка на личный кабинет — архив записей и ИИ-ответы по грамматикам">
    <meta property="og:image" content="{{ asset('images/og-membership-basic.webp') }}?v=h3650">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Базовый — улучшенный кабинет без архива видео">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ asset('images/og-membership-club.webp') }}?v=h3650">
@endpush

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white py-16 lg:py-24 relative overflow-hidden font-sans">

    <div class="absolute top-0 left-1/4 w-96 h-96 bg-brand/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-12 relative z-10">

        {{-- ═══════════ Превью-плашка ═══════════ --}}
        <div class="flex justify-center mb-12">
            <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-amber-400/40 bg-amber-400/10 text-amber-300 text-xs font-bold uppercase tracking-widest">
                <i class="fas fa-eye"></i>
                Превью для супер-админа · публичный запуск — после 1 января 2027
            </span>
        </div>

        {{-- ═══════════ Заголовок ═══════════ --}}
        <div class="text-center mb-16">
            <span class="block text-[#38BDF8] text-xs font-bold uppercase tracking-[0.18em] mb-4">Членство школы</span>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-6">
                Подписка на личный кабинет
            </h1>
            <p class="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto leading-relaxed mb-4">
                Помимо разовых курсов — ежемесячная подписка на кабинет: тренажёры и игры,
                напоминания о платежах и грамотные ответы ИИ на основе оцифрованных грамматик школы.
            </p>
            <p class="text-base text-slate-500 max-w-3xl mx-auto leading-relaxed">
                Ответы строятся на материалах школы, а не «что нейросеть порождает».
                Живые курсы с преподавателем идут отдельно — подписка их не заменяет.
            </p>
        </div>

        {{-- ═══════════ Месячная лестница ═══════════ --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-20">

            {{-- Свободный --}}
            <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-7 flex flex-col">
                <p class="text-[11px] font-black uppercase tracking-widest text-[#38BDF8] mb-2">Free</p>
                <h2 class="text-xl font-extrabold text-white mb-1">{{ $ladderV2 ? $labels['free'] : 'Свободный' }}</h2>
                <div class="flex items-baseline gap-1.5 mb-1">
                    <span class="text-3xl font-extrabold text-white tabular-nums">0</span>
                    <span class="text-slate-400 font-bold text-sm">₽/мес</span>
                </div>
                <p class="text-sm text-slate-500 mb-5">Навсегда. Очень урезанный.</p>
                <ul class="space-y-2.5 mb-5">
                    <li class="flex items-start gap-2.5 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>Игры и открытые уроки — без ограничений</span>
                    </li>
                    <li class="flex items-start gap-2.5 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>2 пробных ИИ-запроса</span>
                    </li>
                </ul>
                <p class="text-[11px] font-bold uppercase tracking-widest text-slate-500 mb-2.5">Не входит</p>
                <ul class="space-y-2.5 mb-7">
                    <li class="flex items-start gap-2.5 text-slate-500 text-sm leading-relaxed">
                        <i class="fas fa-xmark text-rose-400/70 mt-1"></i>
                        <span>Напоминания, когда платить</span>
                    </li>
                    <li class="flex items-start gap-2.5 text-slate-500 text-sm leading-relaxed">
                        <i class="fas fa-xmark text-rose-400/70 mt-1"></i>
                        <span>Библиотека и ответы по грамматикам</span>
                    </li>
                </ul>
                <div class="mt-auto">
                    @auth
                        <a href="{{ route('student.dashboard') }}"
                           class="flex items-center justify-center w-full px-4 py-3 bg-[#141A28] border border-[#1F2636] hover:border-[#38BDF8]/60 text-white text-sm font-bold rounded-xl transition-all">
                            Открыть кабинет
                        </a>
                    @else
                        <a href="{{ route('login') }}"
                           class="flex items-center justify-center w-full px-4 py-3 bg-[#141A28] border border-[#1F2636] hover:border-[#38BDF8]/60 text-white text-sm font-bold rounded-xl transition-all">
                            Завести бесплатный кабинет
                        </a>
                    @endauth
                </div>
            </div>

            {{-- Базовый --}}
            <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-7 flex flex-col">
                <p class="text-[11px] font-black uppercase tracking-widest text-[#38BDF8] mb-2">Basic</p>
                <h2 class="text-xl font-extrabold text-white mb-1">{{ $ladderV2 ? $labels['basic'] : 'Базовый' }}</h2>
                <div class="flex items-baseline gap-1.5 mb-1">
                    <span class="text-3xl font-extrabold text-white tabular-nums">{{ number_format((float) $basicPrice, 0, '.', ' ') }}</span>
                    <span class="text-slate-400 font-bold text-sm">₽/мес</span>
                </div>
                <p class="text-sm text-slate-500 mb-5">Кабинет под контролем.</p>
                <ul class="space-y-2.5 mb-7">
                    <li class="flex items-start gap-2.5 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>Напоминания: когда и сколько платить</span>
                    </li>
                    <li class="flex items-start gap-2.5 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>Улучшенный кабинет и льготы</span>
                    </li>
                    <li class="flex items-start gap-2.5 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>Библиотека и стандартные упражнения</span>
                    </li>
                    <li class="flex items-start gap-2.5 text-slate-500 text-sm leading-relaxed">
                        <i class="fas fa-xmark text-rose-400/70 mt-1"></i>
                        <span>ИИ-ответы по грамматикам — не входят</span>
                    </li>
                </ul>
                <div class="mt-auto">
                    @if($basicTariff && $basicTariff->is_active)
                        <a href="{{ route('checkout.show', $basicTariff) }}"
                           class="flex items-center justify-center w-full px-4 py-3 bg-[#141A28] border border-[#1F2636] hover:border-[#38BDF8]/60 text-white text-sm font-bold rounded-xl transition-all">
                            Оплатить месяц
                        </a>
                    @else
                        <span class="flex items-center justify-center w-full px-4 py-3 bg-[#0D1119] border border-[#1F2636] text-slate-500 text-sm font-bold rounded-xl">
                            Появится к запуску
                        </span>
                    @endif
                </div>
            </div>

            {{-- Клуб — продвинутый доступ --}}
            <div class="bg-[#101521] border border-[#38BDF8]/50 rounded-2xl p-7 flex flex-col relative shadow-[0_0_40px_rgba(56,189,248,0.08)]">
                <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-[#38BDF8] text-[#06283C] text-[10px] font-black uppercase tracking-widest rounded-full px-3 py-1 whitespace-nowrap">Продвинутый доступ</span>
                <p class="text-[11px] font-black uppercase tracking-widest text-[#38BDF8] mb-2 mt-1">Club</p>
                <h2 class="text-xl font-extrabold text-white mb-1">{{ $ladderV2 ? $labels['club'] : 'Клуб' }}</h2>
                <div class="flex items-baseline gap-1.5 mb-1">
                    <span class="text-3xl font-extrabold text-white tabular-nums">{{ number_format((float) $clubPrice, 0, '.', ' ') }}</span>
                    <span class="text-slate-400 font-bold text-sm">₽/мес</span>
                </div>
                <p class="text-sm text-slate-500 mb-5">Для тех, кто учится всерьёз.</p>
                <ul class="space-y-2.5 mb-7">
                    <li class="flex items-start gap-2.5 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>Всё из «Базового»</span>
                    </li>
                    <li class="flex items-start gap-2.5 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>Оцифрованные грамматики школы</span>
                    </li>
                    <li class="flex items-start gap-2.5 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-wand-magic-sparkles text-[#38BDF8] mt-1"></i>
                        <span>Грамотные ответы ИИ по всем пройденным курсам</span>
                    </li>
                </ul>
                <div class="mt-auto">
                    @if($clubTariff && $clubTariff->is_active)
                        <a href="{{ route('checkout.show', $clubTariff) }}"
                           class="flex items-center justify-center w-full px-4 py-3 bg-brand hover:bg-brand/90 text-white text-sm font-bold rounded-xl transition-all">
                            Оплатить месяц
                        </a>
                    @else
                        <span class="flex items-center justify-center w-full px-4 py-3 bg-[#0D1119] border border-[#1F2636] text-slate-500 text-sm font-bold rounded-xl">
                            Появится к запуску
                        </span>
                    @endif
                </div>
            </div>

            {{-- Top — сознательно не продаётся --}}
            <div class="bg-[#101521] border border-dashed border-[#1F2636] rounded-2xl p-7 flex flex-col relative opacity-80">
                <span class="absolute right-5 top-5 bg-amber-400/10 border border-amber-400/40 text-amber-300 text-[10px] font-black uppercase tracking-widest rounded-full px-3 py-1">Позже</span>
                <p class="text-[11px] font-black uppercase tracking-widest text-[#38BDF8] mb-2">Top</p>
                <h2 class="text-xl font-extrabold text-white mb-1">{{ $ladderV2 ? $labels['top'] : 'Максимальный' }}</h2>
                <div class="flex items-baseline gap-1.5 mb-1">
                    <span class="text-3xl font-extrabold text-white tabular-nums">{{ number_format((float) $topPrice, 0, '.', ' ') }}</span>
                    <span class="text-slate-400 font-bold text-sm">₽/мес</span>
                </div>
                <p class="text-sm text-slate-500 mb-5">Вне зависимости от пройденных курсов.</p>
                <ul class="space-y-2.5 mb-7">
                    <li class="flex items-start gap-2.5 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>Всё из «Клуба»</span>
                    </li>
                    <li class="flex items-start gap-2.5 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-video text-[#38BDF8] mt-1"></i>
                        <span>Запросы по стенограммам, презентациям и видео любого курса</span>
                    </li>
                </ul>
                <div class="mt-auto">
                    <span class="flex items-center justify-center w-full px-4 py-3 bg-[#0D1119] border border-[#1F2636] text-slate-500 text-sm font-bold rounded-xl">
                        Сознательно не продаётся
                    </span>
                </div>
            </div>
        </div>

        {{-- ═══════════ Что входит ═══════════ --}}
        <div class="mb-16">
            <h2 class="text-3xl font-extrabold text-white text-center mb-3">Что входит</h2>
            <p class="text-slate-500 text-center max-w-2xl mx-auto mb-10">
                Пробные ИИ-запросы — у всех; дальше объём доступа зависит от тарифа.
            </p>

            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[560px]">
                    <thead>
                        <tr class="border-b border-[#1F2636]">
                            <th class="text-left py-3 pr-4 text-[11px] font-black uppercase tracking-widest text-slate-500"><span class="sr-only">Возможность</span></th>
                            <th class="text-center py-3 px-2 text-[11px] font-black uppercase tracking-widest text-slate-500">Свободный</th>
                            <th class="text-center py-3 px-2 text-[11px] font-black uppercase tracking-widest text-slate-500">Базовый</th>
                            <th class="text-center py-3 px-2 text-[11px] font-black uppercase tracking-widest text-[#38BDF8]">Клуб</th>
                            <th class="text-center py-3 px-2 text-[11px] font-black uppercase tracking-widest text-slate-500">Top</th>
                        </tr>
                    </thead>
                    <tbody class="text-slate-300">
                        <tr class="border-b border-white/5">
                            <td class="py-3 pr-4">Игры и открытые уроки</td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                        </tr>
                        <tr class="border-b border-white/5">
                            <td class="py-3 pr-4">Пробные ИИ-запросы</td>
                            <td class="text-center py-3 px-2 text-white font-bold tabular-nums">2</td>
                            <td class="text-center py-3 px-2 text-white font-bold tabular-nums">2</td>
                            <td class="text-center py-3 px-2 text-white font-bold tabular-nums">2</td>
                            <td class="text-center py-3 px-2 text-white font-bold tabular-nums">2</td>
                        </tr>
                        <tr class="border-b border-white/5">
                            <td class="py-3 pr-4">Напоминания, когда платить</td>
                            <td class="text-center py-3 px-2"><i class="fas fa-xmark text-rose-400/60"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                        </tr>
                        <tr class="border-b border-white/5">
                            <td class="py-3 pr-4">Библиотека и стандартные упражнения</td>
                            <td class="text-center py-3 px-2"><i class="fas fa-xmark text-rose-400/60"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                        </tr>
                        <tr class="border-b border-white/5">
                            <td class="py-3 pr-4">ИИ-ответы на основе оцифрованных грамматик</td>
                            <td class="text-center py-3 px-2"><i class="fas fa-xmark text-rose-400/60"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-xmark text-rose-400/60"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                        </tr>
                        <tr class="border-b border-white/5">
                            <td class="py-3 pr-4">…по каким курсам</td>
                            <td class="text-center py-3 px-2 text-slate-600">—</td>
                            <td class="text-center py-3 px-2 text-slate-600">—</td>
                            <td class="text-center py-3 px-2 text-white font-bold">пройденным</td>
                            <td class="text-center py-3 px-2 text-white font-bold">любым</td>
                        </tr>
                        <tr class="border-b border-white/5">
                            <td class="py-3 pr-4">Запросы по стенограммам, презентациям и видео</td>
                            <td class="text-center py-3 px-2 text-slate-600">—</td>
                            <td class="text-center py-3 px-2 text-slate-600">—</td>
                            <td class="text-center py-3 px-2 text-slate-600">—</td>
                            <td class="text-center py-3 px-2"><i class="fas fa-check text-emerald-400"></i></td>
                        </tr>
                        <tr>
                            <td class="py-3 pr-4">Проверка домашних заданий</td>
                            <td class="text-center py-3 px-2 text-slate-600">—</td>
                            <td class="text-center py-3 px-2 text-slate-600">—</td>
                            <td class="text-center py-3 px-2 text-slate-600">—</td>
                            <td class="text-center py-3 px-2 text-slate-600">—</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ═══════════ Честные подписи ═══════════ --}}
        <div class="space-y-6 max-w-3xl mx-auto mb-16">
            <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-6">
                <p class="text-white font-bold mb-2">Оплата вперед, продление ручное</p>
                <p class="text-slate-400 text-sm leading-relaxed">
                    Подписка не привязывает карту и не списывает деньги сама. Оплачен месяц —
                    месяц работает; продлеваете вы, а не банк. Если оплатить заранее, новый срок
                    прибавится к текущему, а не начнется заново. Не продлили — подписка просто
                    закончилась в оплаченный день.
                </p>
            </div>

            <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-6">
                <p class="text-white font-bold mb-2">Проверка домашних заданий — не входит ни в один тариф</p>
                <p class="text-slate-400 text-sm leading-relaxed">
                    Принципиально: подписка существует, чтобы кураторов финансировать,
                    а не расходовать. Живые курсы с преподавателем, проверкой домашних
                    заданий и сертификатом идут отдельно.
                </p>
                <a href="{{ route('shop.index') }}"
                   class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 bg-[#0A0D14] border border-[#1F2636] hover:border-[#38BDF8]/60 text-[#38BDF8] text-sm font-bold rounded-xl transition-all">
                    <i class="fas fa-graduation-cap"></i>
                    Выбрать курс
                </a>
            </div>

            <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-6">
                <p class="text-white font-bold mb-2">Годовая линейка снята с витрины</p>
                <p class="text-slate-400 text-sm leading-relaxed">
                    Годовые тарифы подписки «в записи» и клубные квартал/год с витрины
                    убраны до отдельного решения. Если вы уже оплатили один из них —
                    он работает до конца оплаченного срока, ничего делать не нужно.
                </p>
            </div>
        </div>

    </div>
</div>
@endsection
