{{--
    Лендинг + прайсинг клубного членства (H2645). Публичный, но живёт только
    при features.club_membership = true: страница не может появиться раньше,
    чем контур H2644 реально выдаёт доступ за оплату, — иначе CTA продаёт то,
    чего система не выдаст.

    Три жёстких ограничения формулировок (спецификация H2645):
    1. НЕ говорить «доступ будет отозван» — 1 703 из 1 705 уроков это
       unlisted-ссылки YouTube, открытая ссылка продолжает работать. Текст
       «Что происходит, когда клуб заканчивается» согласован ДОСЛОВНО с
       карточкой кабинета (student/partials/club-membership.blade.php, H2644) —
       править только синхронно с ней.
    2. НЕ обещать куратора и проверку домашних заданий — клуб существует,
       чтобы кураторов финансировать, а не расходовать.
    3. НИКАКОГО третьего тарифа за 5 000 ₽ и никакого упоминания корпуса —
       на этой странице их нет вовсе.

    Цены и названия тарифов приходят из БД (курс `club` + его тарифы,
    заведённые в Filament) — на странице нет ни одной захардкоженной цены,
    чтобы прайсинг и чекаут никогда не разошлись.
--}}
@extends('layouts.shop')

@section('title', 'Клуб — вся библиотека записей')

@push('head')
    <meta name="description" content="Клуб Общества ревнителей санскрита: вся библиотека записей курсов, полка в кабинете и тренажёры — за {{ number_format((float) $monthly->price, 0, '.', ' ') }} ₽ в месяц. Без живых потоков, без проверки домашних заданий, без сертификата — поэтому и цена такая.">
    {{-- H3650: Club + Basic share stills. Primary og:image is Club (page title). Basic 1200×630 is a second OG image for clients that pick an alternate. Home preview stays og-main-preview.jpg. --}}
    <meta property="og:title" content="Клуб — вся библиотека записей">
    <meta property="og:image" content="{{ asset('images/og-membership-club.webp') }}?v=h3650">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Клуб — вся библиотека записей">
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

        {{-- ═══════════ Заголовок ═══════════ --}}
        <div class="text-center mb-14">
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-6">
                {{ $course->title }}
            </h1>
            <p class="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto leading-relaxed mb-4">
                Вся библиотека записей наших курсов, полка в кабинете и тренажёры —
                за {{ number_format((float) $monthly->price, 0, '.', ' ') }} ₽ в месяц.
            </p>
            <p class="text-base text-slate-500 max-w-3xl mx-auto leading-relaxed mb-8">
                Без живых потоков, без проверки домашних заданий и без времени куратора —
                поэтому и цена такая. Живые курсы с преподавателем идут отдельно.
            </p>
            <div class="flex flex-wrap justify-center gap-3">
                <a href="#tarify"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-brand hover:bg-brand/90 text-white text-sm font-bold rounded-xl transition-all">
                    <i class="fas fa-box-archive"></i>
                    Выбрать срок
                </a>
                <a href="#svobodny"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-[#141A28] border border-[#1F2636] hover:border-[#38BDF8]/60 hover:bg-[#38BDF8]/5 text-[#38BDF8] text-sm font-bold rounded-xl transition-all">
                    <i class="fas fa-gift"></i>
                    Есть бесплатный уровень
                </a>
            </div>
        </div>

        {{-- ═══════════ Free / Basic / Club ═══════════ --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-20">

            {{-- Свободный --}}
            <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-8 flex flex-col">
                <div class="flex items-baseline justify-between mb-1">
                    <h2 class="text-2xl font-extrabold text-white">Свободный</h2>
                    <span class="text-2xl font-extrabold text-white">0 ₽</span>
                </div>
                <p class="text-sm text-slate-500 mb-6">Навсегда. Достаточно кабинета.</p>

                <ul class="space-y-3 mb-6">
                    <li class="flex items-start gap-3 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>
                            @if($freeLessonsCount > 0)
                                {{ $freeLessonsCount }} бесплатных уроков — открыты всем
                                на <a href="{{ url('/') }}" class="text-[#38BDF8] hover:underline">главной странице</a>, без ограничений
                            @else
                                Бесплатные уроки на <a href="{{ url('/') }}" class="text-[#38BDF8] hover:underline">главной странице</a> — без ограничений
                            @endif
                        </span>
                    </li>
                    <li class="flex items-start gap-3 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>
                            @if($freeTierOn)
                                Один урок в месяц из курса, который вы уже оплачивали, —
                                открывается в кабинете на {{ $grantDays }} дней. Ваш курс, по уроку в месяц, бесплатно.
                            @else
                                Скоро: один урок в месяц из курса, который вы уже оплачивали, —
                                будет открываться в кабинете на {{ $grantDays }} дней
                            @endif
                        </span>
                    </li>
                </ul>

                <p class="text-[11px] font-bold uppercase tracking-widest text-slate-500 mb-3">Не входит</p>
                <ul class="space-y-2.5 mb-8">
                    <li class="flex items-start gap-3 text-slate-400 text-sm leading-relaxed">
                        <i class="fas fa-xmark text-rose-400/80 mt-1"></i>
                        <span>Платные курсы, которые вы не покупали</span>
                    </li>
                    <li class="flex items-start gap-3 text-slate-400 text-sm leading-relaxed">
                        <i class="fas fa-xmark text-rose-400/80 mt-1"></i>
                        <span>Проверка домашних заданий</span>
                    </li>
                    <li class="flex items-start gap-3 text-slate-400 text-sm leading-relaxed">
                        <i class="fas fa-xmark text-rose-400/80 mt-1"></i>
                        <span>Сертификат</span>
                    </li>
                </ul>

                <div class="mt-auto">
                    @auth
                        <a href="{{ route('student.dashboard') }}"
                           class="flex items-center justify-center w-full px-4 py-3 bg-[#141A28] border border-[#1F2636] hover:border-[#38BDF8]/60 text-white text-sm font-bold rounded-xl transition-all">
                            Открыть кабинет
                        </a>
                    @else
                        <a href="#svobodny"
                           class="flex items-center justify-center w-full px-4 py-3 bg-[#141A28] border border-[#1F2636] hover:border-[#38BDF8]/60 text-white text-sm font-bold rounded-xl transition-all">
                            Завести бесплатный кабинет
                        </a>
                    @endauth
                </div>
            </div>

            {{-- Базовый --}}
            <div class="bg-[#101521] border border-[#38BDF8]/40 rounded-2xl p-8 flex flex-col">
                <div class="flex items-baseline justify-between mb-1">
                    <h2 class="text-2xl font-extrabold text-white">Базовый</h2>
                    <span class="text-2xl font-extrabold text-white">{{ number_format((float) ($basicMonthly?->price ?? 1000), 0, '.', ' ') }} ₽<span class="text-sm text-slate-400 font-bold">/мес</span></span>
                </div>
                <p class="text-sm text-slate-500 mb-6">Улучшенный кабинет без архива видео.</p>
                <ul class="space-y-3 mb-6 text-slate-300 text-sm">
                    <li><i class="fas fa-check text-emerald-400 mr-2"></i>Библиотека и стандартные упражнения</li>
                    <li><i class="fas fa-check text-emerald-400 mr-2"></i>Улучшения кабинета и льготы</li>
                    <li><i class="fas fa-xmark text-rose-400/80 mr-2"></i>Записи купленных курсов не входят</li>
                    <li><i class="fas fa-xmark text-rose-400/80 mr-2"></i>Персональные упражнения и максимум инструментов не входят</li>
                </ul>
                <a href="#tarify" class="mt-auto flex items-center justify-center w-full px-4 py-3 bg-[#141A28] border border-[#38BDF8]/40 text-white text-sm font-bold rounded-xl">Выбрать срок</a>
            </div>

            {{-- Клуб --}}
            <div class="bg-[#101521] border-2 border-brand/50 rounded-2xl p-8 flex flex-col relative">
                <div class="flex items-baseline justify-between mb-1">
                    <h2 class="text-2xl font-extrabold text-white">Клуб</h2>
                    <span class="text-2xl font-extrabold text-white">{{ number_format((float) $monthly->price, 0, '.', ' ') }} ₽<span class="text-sm text-slate-400 font-bold">/мес</span></span>
                </div>
                <p class="text-sm text-slate-500 mb-6">Квартал и год — дешевле, см. ниже.</p>

                <ul class="space-y-3 mb-6">
                    <li class="flex items-start gap-3 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        @php
                            // Русское склонение без словарей переводов: 1 курс / 2 курса / 5 курсов.
                            $n = $shelfCount % 100;
                            $n1 = $n % 10;
                            $coursesWord = ($n >= 11 && $n <= 14) ? 'курсов'
                                : ($n1 === 1 ? 'курс' : (($n1 >= 2 && $n1 <= 4) ? 'курса' : 'курсов'));
                        @endphp
                        <span>
                            Вся библиотека записей курсов@if($shelfCount > 0) — {{ $shelfCount }} {{ $coursesWord }} на полке@endif
                        </span>
                    </li>
                    <li class="flex items-start gap-3 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>Полка «Записи клуба» в кабинете — записи открываются там же, где ваши купленные курсы</span>
                    </li>
                    <li class="flex items-start gap-3 text-slate-300 text-sm leading-relaxed">
                        <i class="fas fa-check text-emerald-400 mt-1"></i>
                        <span>Тренажёры к курсам полки</span>
                    </li>
                </ul>

                <p class="text-[11px] font-bold uppercase tracking-widest text-slate-500 mb-3">Не входит</p>
                <ul class="space-y-2.5 mb-8">
                    <li class="flex items-start gap-3 text-slate-400 text-sm leading-relaxed">
                        <i class="fas fa-xmark text-rose-400/80 mt-1"></i>
                        <span>Живые потоки с преподавателем — они идут отдельно, <a href="{{ route('shop.index') }}" class="text-[#38BDF8] hover:underline">в курсах</a></span>
                    </li>
                    <li class="flex items-start gap-3 text-slate-400 text-sm leading-relaxed">
                        <i class="fas fa-xmark text-rose-400/80 mt-1"></i>
                        <span>Проверка домашних заданий и время куратора</span>
                    </li>
                    <li class="flex items-start gap-3 text-slate-400 text-sm leading-relaxed">
                        <i class="fas fa-xmark text-rose-400/80 mt-1"></i>
                        <span>Сертификат</span>
                    </li>
                </ul>

                <div class="mt-auto">
                    <a href="#tarify"
                       class="flex items-center justify-center w-full px-4 py-3 bg-brand hover:bg-brand/90 text-white text-sm font-bold rounded-xl transition-all">
                        Выбрать срок
                    </a>
                </div>
            </div>
        </div>

        {{-- ═══════════ Тарифы клуба ═══════════ --}}
        <div id="tarify" class="mb-20 scroll-mt-24">
            <h2 class="text-3xl font-extrabold text-white text-center mb-3">Сроки и цены</h2>
            <p class="text-slate-500 text-center max-w-2xl mx-auto mb-10">
                Одно и то же наполнение на любой срок. Разница только в цене месяца.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($tariffs as $tariff)
                    @php
                        $months = max(1, (int) $tariff->membership_months);
                        $perMonth = (int) round(((float) $tariff->price) / $months);
                        $tierLabel = match($tariff->membership_tier?->value) {
                            'basic' => 'Базовый',
                            'club', null => 'Клуб',
                            'top' => 'Top',
                            default => $tariff->membership_tier?->value,
                        };
                    @endphp
                    <div class="bg-[#101521] border {{ $months === 12 ? 'border-brand/50' : 'border-[#1F2636]' }} rounded-2xl p-6 flex flex-col">
                        <p class="text-[11px] font-black uppercase tracking-widest text-brand mb-2">{{ $tierLabel }}</p>
                        <h3 class="text-lg font-bold text-white mb-2">{{ $tariff->title }}</h3>
                        <div class="mb-1">
                            <span class="text-3xl font-extrabold text-white tabular-nums">{{ number_format((float) $tariff->price, 0, '.', ' ') }}</span>
                            <span class="text-slate-400 font-bold ml-1">₽</span>
                        </div>
                        @if($months > 1)
                            <p class="text-sm text-slate-500 mb-3">≈ {{ number_format($perMonth, 0, '.', ' ') }} ₽ в месяц</p>
                        @else
                            <p class="text-sm text-slate-500 mb-3">за месяц</p>
                        @endif
                        @if($tariff->description)
                            <p class="text-sm text-slate-400 leading-relaxed mb-6">{{ $tariff->description }}</p>
                        @endif
                        <a href="{{ route('checkout.show', $tariff) }}"
                           class="mt-auto flex items-center justify-center w-full px-4 py-3 {{ $months === 12 ? 'bg-brand hover:bg-brand/90 text-white' : 'bg-[#141A28] border border-[#1F2636] hover:border-brand/60 text-white' }} text-sm font-bold rounded-xl transition-all">
                            Оплатить
                        </a>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ═══════════ Как устроен клуб ═══════════ --}}
        <div class="mb-20">
            <h2 class="text-3xl font-extrabold text-white text-center mb-10">Как устроен клуб</h2>

            <div class="space-y-6 max-w-3xl mx-auto">
                <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-6">
                    <p class="text-white font-bold mb-2">Оплата вперёд, продление ручное</p>
                    <p class="text-slate-400 text-sm leading-relaxed">
                        Клуб не привязывает карту и не списывает деньги сам. Оплатили месяц,
                        квартал или год — срок идёт; продлеваете вы, а не банк. Если оплатить
                        продление заранее, новый срок прибавится к текущему, а не начнётся заново.
                    </p>
                </div>

                <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-6">
                    <p class="text-white font-bold mb-2">Отменять ничего не нужно</p>
                    <p class="text-slate-400 text-sm leading-relaxed">
                        Не продлили — клуб просто закончился в оплаченный день, никаких
                        автосписаний после.@if($cancellationOn) В кабинете есть кнопка
                        «Не продлевать»: она отключает напоминания, а оплаченный срок работает
                        до конца.@endif
                    </p>
                </div>

                {{-- Дословно согласовано с карточкой кабинета (H2644):
                     student/partials/club-membership.blade.php. Править только синхронно. --}}
                <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-6">
                    <p class="text-white font-bold mb-2">Что происходит, когда клуб заканчивается</p>
                    <p class="text-slate-400 text-sm leading-relaxed">
                        Заканчивается <strong class="text-slate-300">право доступа</strong>: клубные страницы записей перестают
                        открываться в кабинете, новые записи в полку не добавляются. Уроки, которые вы уже
                        открыли, мы у вас не отбираем — ссылки на видео продолжают работать. Купленные
                        курсы, домашние задания и сертификаты клуб не затрагивает: они остаются вашими
                        независимо от подписки.
                    </p>
                </div>
            </div>
        </div>

        {{-- ═══════════ Кому клуб не подойдёт ═══════════ --}}
        <div class="mb-20 max-w-3xl mx-auto">
            <div class="bg-[#141A28] border border-[#1F2636] rounded-2xl p-8 text-center">
                <h2 class="text-xl font-extrabold text-white mb-3">Клуб — это записи, а не курс</h2>
                <p class="text-slate-400 text-sm leading-relaxed mb-6">
                    Если вам нужны живые занятия с преподавателем, проверка домашних заданий,
                    куратор и сертификат — это курсы, и они идут отдельно от клуба.
                </p>
                <a href="{{ route('shop.index') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-[#0A0D14] border border-[#1F2636] hover:border-[#38BDF8]/60 text-[#38BDF8] text-sm font-bold rounded-xl transition-all">
                    <i class="fas fa-graduation-cap"></i>
                    Выбрать курс
                </a>
            </div>
        </div>

        {{-- ═══════════ Свободный уровень: как получить ═══════════ --}}
        <div id="svobodny" class="scroll-mt-24">
            <h2 class="text-3xl font-extrabold text-white text-center mb-3">Уровень «Свободный»</h2>
            <p class="text-slate-400 text-center max-w-2xl mx-auto leading-relaxed mb-4">
                Если вы когда-то проходили у нас курс — он не пропал.
                @if($freeTierOn)
                    Раз в месяц мы бесплатно открываем в кабинете один урок из курса,
                    который вы уже оплачивали. Ваш курс, по уроку в месяц, бесплатно.
                @else
                    Скоро мы начнём раз в месяц бесплатно открывать в кабинете один урок
                    из курса, который вы уже оплачивали. Ваш курс, по уроку в месяц, бесплатно.
                @endif
            </p>
            <p class="text-slate-500 text-sm text-center max-w-2xl mx-auto leading-relaxed mb-10">
                Ничего платить и привязывать не нужно — достаточно кабинета.
            </p>

            <div class="flex flex-col items-center gap-4">
                @auth
                    <a href="{{ route('student.dashboard') }}"
                       class="inline-flex items-center gap-2 px-8 py-3.5 bg-brand hover:bg-brand/90 text-white text-sm font-bold rounded-xl transition-all">
                        Открыть кабинет
                    </a>
                @else
                    <x-newsletter-subscribe
                        title="Завести бесплатный кабинет"
                        blurb="Оставьте email — пришлём ссылку для входа. Кабинет бесплатный, карта не нужна." />
                    <p class="text-sm text-slate-500">
                        Уже есть кабинет?
                        <a href="{{ route('login') }}" class="text-[#38BDF8] hover:underline font-bold">Войти</a>
                    </p>
                @endauth
            </div>
        </div>

    </div>
</div>
@endsection
