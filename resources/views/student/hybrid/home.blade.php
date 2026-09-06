@extends('layouts.student')

@section('title', 'Сегодня')
@section('header', 'Сегодня')

@section('content')
@php
    /** @var \App\Services\Cabinet\RecoveryState $recovery */
    $recovery = $recovery ?? \App\Services\Cabinet\RecoveryState::normal();
    $suppressOffers = $suppressOffers ?? $recovery->suppressOffers();
    $todayBand = $todayBand ?? ['continue' => null, 'live' => null, 'homework' => null];
@endphp

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">
    <div class="mt-6">
        {{-- Flash от платёжных редиректов (анти-дубль, отбивки DebtPaymentController):
             без баннера отказ выглядит как «кнопка ничего не делает». --}}
        @include('student.partials.flash-messages')
    </div>
    <div class="mb-6 mt-6">
        <h2 class="text-3xl md:text-4xl font-extrabold text-[#101010] tracking-tight mb-1">
            Добро пожаловать, {{ auth()->user()->name }}!
        </h2>
        <p class="text-gray-500 text-base">{{ now()->timezone(config('app.timezone'))->translatedFormat('l, d F') }}</p>
    </div>

    @include('student.partials.hindi-programme-playlist-card', ['hindiPlaylist' => $hindiPlaylist ?? null])

    {{-- R29.2 recovery banner leads; offers suppressed --}}
    @if ($recovery->active)
        <section class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-5 md:p-6" aria-label="Проблема с доступом" data-cabinet-mode="recovery">
            <h3 class="text-lg font-extrabold text-red-900 mb-2">{{ $recovery->headline }}</h3>
            <p class="text-sm text-red-800/90 mb-4 leading-relaxed">{{ $recovery->detail }}</p>
            <div class="flex flex-wrap gap-3 items-center">
                @if ($recovery->ctaUrl)
                    <a href="{{ $recovery->ctaUrl }}"
                       class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-red-700 hover:bg-red-800 text-white text-sm font-bold shadow-sm">
                        {{ $recovery->ctaLabel ?? 'Исправить' }}
                    </a>
                @endif
                <a href="{{ route('student.messages') }}"
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-red-200 bg-white text-red-800 text-sm font-bold hover:border-red-400">
                    Написать куратору
                </a>
            </div>
            <p class="mt-4 text-xs text-red-700/80">
                Уже оплаченные и живые занятия, к которым у вас есть доступ, продолжают работать.
                Предложений о покупках в этом состоянии нет.
            </p>
        </section>
    @endif

    {{-- R29.1 «Сегодня» composite band --}}
    <section class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-4" aria-label="Сегодня" data-today-band>
        @if (! empty($todayBand['continue']))
            @php $c = $todayBand['continue']; @endphp
            <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" data-today-continue>
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">Продолжить</p>
                <h3 class="text-lg font-extrabold text-[#101010] mb-1">{{ $c['title'] ?? 'Обучение' }}</h3>
                <p class="text-sm text-gray-600 mb-1">{{ $c['body'] ?? '' }}</p>
                @if (! empty($c['meta']))
                    <p class="text-xs text-gray-400 mb-3">{{ $c['meta'] }}</p>
                @endif
                @if (! empty($c['cta']['url']))
                    {{-- CTA обязан уважать method: платёжные действия (bundle-долг,
                         взнос рассрочки) — POST-роуты, голая ссылка даёт 405. --}}
                    @if (($c['cta']['method'] ?? 'GET') === 'POST')
                        <form method="POST" action="{{ $c['cta']['url'] }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold"
                                    data-cabinet-event="cabinet.continue.click"
                                    data-kind="{{ $c['kind'] ?? 'lesson' }}">
                                {{ $c['cta']['label'] ?? 'Открыть' }}
                            </button>
                        </form>
                    @else
                        {{-- TAB («Открыть долги», #debts) — в hybrid-шелле вкладок нет,
                             ведём на страницу «Оплата и доступ». --}}
                        <a href="{{ ($c['cta']['method'] ?? 'GET') === 'TAB' ? route('student.access') : $c['cta']['url'] }}"
                           class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold"
                           data-cabinet-event="cabinet.continue.click"
                           data-kind="{{ $c['kind'] ?? 'lesson' }}">
                            {{ $c['cta']['label'] ?? 'Открыть' }}
                        </a>
                    @endif
                @endif
            </div>
        @endif

        @if (! empty($todayBand['live']))
            @php $live = $todayBand['live']; @endphp
            <div class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-5 shadow-sm" data-today-live>
                <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-700 mb-2">Живое занятие</p>
                <h3 class="text-lg font-extrabold text-[#101010] mb-1">{{ $live['title'] }}</h3>
                <p class="text-sm text-gray-600 mb-3">{{ $live['meta'] }}</p>
                <a href="{{ $live['url'] }}"
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-emerald-200 bg-white text-emerald-800 text-sm font-bold hover:border-emerald-400">
                    К расписанию
                </a>
            </div>
        @endif

        {{-- R29.1 third element: only when homework was returned --}}
        @if (! empty($todayBand['homework']))
            @php $hw = $todayBand['homework']; @endphp
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm md:col-span-2" data-today-homework-rework>
                <p class="text-[10px] font-bold uppercase tracking-widest text-amber-700 mb-2">Домашнее</p>
                <h3 class="text-lg font-extrabold text-[#101010] mb-1">{{ $hw['title'] }}</h3>
                <p class="text-sm text-gray-600 mb-1">{{ $hw['body'] }}</p>
                @if (! empty($hw['meta']))
                    <p class="text-xs text-gray-400 mb-3">{{ $hw['meta'] }}</p>
                @endif
                <a href="{{ $hw['cta']['url'] }}"
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-sm font-bold"
                   data-cabinet-event="cabinet.homework.rework.click">
                    {{ $hw['cta']['label'] }}
                </a>
            </div>
        @endif
    </section>

    @if (! $recovery->active)
        @include('student.partials.onboarding-checklist')
    @endif

    <div class="mb-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Мои курсы</div>

    <div class="space-y-3 mb-10">
        @forelse ($courses as $course)
            @php
                $next = $nextLessonByCourseId[$course->id] ?? null;
                $debt = $debtsByCourseId[$course->id] ?? null;
            @endphp
            <article class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm flex flex-col sm:flex-row sm:items-center gap-4 justify-between">
                <div class="min-w-0">
                    <h3 class="text-base font-extrabold text-[#101010] truncate">
                        <a href="{{ route('student.course', $course->slug) }}" class="hover:text-brand">{{ $course->title }}</a>
                    </h3>
                    <p class="text-xs text-gray-500 mt-1">
                        @if ($debt)
                            <span class="text-red-600 font-bold">требует внимания по оплате</span>
                        @elseif ($next)
                            следующий: {{ $next->title }}
                        @else
                            все доступные уроки пройдены
                        @endif
                    </p>
                </div>
                <a href="{{ route('student.course', $course->slug) }}"
                   class="shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-gray-200 text-sm font-bold text-gray-700 hover:border-brand hover:text-brand">
                    Открыть курс
                </a>
            </article>
        @empty
            <p class="text-sm text-gray-500">Пока нет активных курсов. Если вы только оплатили — доступ откроется в течение нескольких минут.</p>
        @endforelse
    </div>

    {{-- Offer slot: R29 precedence — recovery → zero offers --}}
    @unless ($suppressOffers)
        {{-- Phase 1: no ladder/ownership offers yet (Phase 2–3). Slot reserved. --}}
    @endunless

    <div class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">Инструменты</div>
    <nav class="flex flex-wrap gap-4 text-sm font-bold text-gray-500" aria-label="Учебные инструменты">
        @if (config('srs.enabled'))
            <a href="{{ route('student.srs') }}" class="hover:text-brand">Карточки</a>
        @endif
        <a href="{{ route('student.dashboard') }}#prana" class="hover:text-brand">Прана</a>
        <a href="{{ route('student.progress') }}" class="hover:text-brand">Сертификаты</a>
        <a href="{{ route('student.open-lessons') }}" class="hover:text-brand">Открытые уроки</a>
    </nav>
</div>

@include('student.partials.telemetry')
@endsection
