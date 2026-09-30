@extends('layouts.student')

@section('title', 'Оплата и доступ')
@section('header', 'Оплата и доступ')

@section('content')
@php
    /** @var \App\Services\Cabinet\RecoveryState $recovery */
    $recovery = $recovery ?? \App\Services\Cabinet\RecoveryState::normal();
@endphp

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">
    @include('student.partials.flash-messages')
    <h2 class="text-3xl font-extrabold text-[#101010] mb-2">Оплата и доступ</h2>
    <p class="text-gray-500 mb-6">Что требует внимания и что уже открыто.</p>

    @if ($recovery->active)
        <section class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-5" aria-label="Проблема с доступом" id="why">
            <h3 class="text-lg font-extrabold text-red-900 mb-2">{{ $recovery->headline }}</h3>
            <p class="text-sm text-red-800/90 leading-relaxed">{{ $recovery->detail }}</p>
        </section>
    @endif

    {{-- Always offer-suppressed on this page (R2 / B v2 access.html) --}}
    <div class="mb-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Требует внимания</div>

    @if ($debts->isEmpty() && ! $recovery->active)
        <div class="rounded-2xl border border-emerald-100 bg-emerald-50/50 p-5 mb-8">
            <p class="text-sm font-bold text-emerald-800">Сейчас нет открытых проблем с оплатой.</p>
        </div>
    @else
        <div class="space-y-3 mb-8">
            @foreach ($debts as $debt)
                @php $opts = $debtPayOptions[$debt->course_id] ?? null; @endphp
                <article id="debt-course-{{ $debt->course_id }}" class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm scroll-mt-24">
                    <h3 class="font-extrabold text-[#101010]">{{ $debt->course->title ?? 'Курс' }}</h3>
                    @if (! empty($debt->debt_label))
                        <p class="text-sm text-gray-600 mt-1">{{ $debt->debt_label }}</p>
                    @endif
                    @if (config('features.payment_recovery_cta') && is_array($opts) && ! empty($opts['next']['amount']))
                        <p class="text-sm text-gray-500 mt-1">К оплате: {{ number_format((float) $opts['next']['amount'], 0, ',', ' ') }} ₽</p>
                    @endif
                    @if (is_array($opts) && ! empty($opts['renew']))
                        <a href="{{ $opts['renew']['url'] ?? '#' }}"
                           class="mt-3 inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-brand text-white text-sm font-bold">
                            Оплатить / продлить
                        </a>
                    @elseif (is_array($opts) && ! empty($opts['next']))
                        <a href="{{ $opts['next']['url'] ?? '#' }}"
                           class="mt-3 inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-brand text-white text-sm font-bold">
                            Внести платеж
                        </a>
                    @elseif (config('features.debt_pay_per_block') && is_array($opts) && ($opts['type'] ?? null) === 'tariff')
                        {{-- Поблочный долг (features.debt_pay_per_block): всё разом ИЛИ по
                             одному блоку. Кнопки блоков — штатный чекаут тарифа block_N
                             (DebtPaymentResolver), bundle — как в старом кабинете:
                             GET на bundle-тариф или POST-фолбэк pay-bundle. --}}
                        <div class="mt-3 flex flex-wrap gap-2" data-testid="debt-pay-per-block">
                            @if (! empty($opts['full']))
                                <a href="{{ $opts['full']['url'] }}" data-track-event="access.renewal.start" data-track-kind="full"
                                   class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold">
                                    Оплатить курс
                                </a>
                            @endif
                            @if (! empty($opts['bundle']))
                                @php $bundleLabel = 'Оплатить все блоки — '.number_format((float) $opts['bundle']['amount'], 0, ',', ' ').' ₽'; @endphp
                                @if (($opts['bundle']['method'] ?? 'POST') === 'GET')
                                    <a href="{{ $opts['bundle']['url'] }}" data-track-event="access.renewal.start" data-track-kind="bundle"
                                       class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold">
                                        {{ $bundleLabel }}
                                    </a>
                                @else
                                    <form method="POST" action="{{ $opts['bundle']['url'] }}">
                                        @csrf
                                        <button type="submit" data-track-event="access.renewal.start" data-track-kind="bundle"
                                                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold">
                                            {{ $bundleLabel }}
                                        </button>
                                    </form>
                                @endif
                            @endif
                        </div>
                        @php $hasWhole = ! empty($opts['bundle']) || ! empty($opts['full']); @endphp
                        @if (! empty($opts['blocks']))
                            @if ($hasWhole)
                                <p class="mt-3 text-xs font-bold uppercase tracking-widest text-gray-400">Или по одному блоку</p>
                            @endif
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($opts['blocks'] as $b)
                                    <a href="{{ $b['url'] }}" data-track-event="access.renewal.start" data-track-kind="block"
                                       class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold {{ $hasWhole ? 'border border-brand/40 text-brand hover:bg-orange-50' : 'bg-brand hover:bg-brand-hover text-white' }}">
                                        Блок №{{ $b['number'] }}@if (isset($b['amount'])) — {{ number_format((float) $b['amount'], 0, ',', ' ') }} ₽@endif
                                    </a>
                                @endforeach
                            </div>
                        @endif
                        @if (! empty($opts['unpriced_blocks']))
                            <p class="mt-3 text-sm text-gray-500">
                                Блоки №{{ implode(', №', $opts['unpriced_blocks']) }} — оплата через
                                <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener noreferrer" class="text-brand underline hover:no-underline">куратора</a>.
                            </p>
                        @endif
                    @endif

                    {{-- H2060: FAQ payment link + curator contact + installment CTA copy, behind payment_recovery_cta --}}
                    @if (config('features.payment_recovery_cta'))
                        <div class="mt-4 pt-4 border-t border-gray-100 text-sm text-gray-600 leading-relaxed" data-testid="payment-recovery-cta">
                            <p>
                                Если вопрос в сумме — можно оформить курс в рассрочку вместо разового платежа.
                                А если остались сомнения или вопросы по программе — куратор с радостью ответит лично.
                            </p>
                            <p class="mt-2">
                                <a href="{{ route('faq.payment') }}" class="text-brand underline hover:no-underline">Как оплатить / если платеж не прошел</a>
                                &middot;
                                <a href="https://t.me/rusamskrtam" class="text-brand underline hover:no-underline">Написать куратору</a>
                            </p>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    <p class="text-xs text-gray-400">
        Промо и офферы на этой странице не показываются — сначала доступ, потом предложения (R2).
    </p>
</div>
@endsection
