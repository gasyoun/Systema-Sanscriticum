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
                <article class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm">
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
                            Внести платёж
                        </a>
                    @endif

                    {{-- H2060: FAQ payment link + curator contact + installment CTA copy, behind payment_recovery_cta --}}
                    @if (config('features.payment_recovery_cta'))
                        <div class="mt-4 pt-4 border-t border-gray-100 text-sm text-gray-600 leading-relaxed" data-testid="payment-recovery-cta">
                            <p>
                                Если вопрос в сумме — можно оформить курс в рассрочку вместо разового платежа.
                                А если остались сомнения или вопросы по программе — куратор с радостью ответит лично.
                            </p>
                            <p class="mt-2">
                                <a href="{{ route('faq.payment') }}" class="text-brand underline hover:no-underline">Как оплатить / если платёж не прошёл</a>
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
