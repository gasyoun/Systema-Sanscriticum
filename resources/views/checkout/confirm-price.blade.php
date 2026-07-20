@extends('layouts.shop')
@section('title', 'Цена изменилась')

{{--
    H1396 §1 — the explicit-confirmation surface for a lapsed promo (MG ruling
    20-07-2026). The whole failure mode being fixed is a total changing under an
    unchanged-looking CTA, so this is a deliberate, separate screen that names the new
    price and requires a distinct confirmation action before any payment is created.
--}}

@section('content')
<div class="max-w-lg mx-auto px-4 py-12 sm:py-16">
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-7 sm:p-9">
        <div class="flex items-center gap-3 mb-5">
            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-amber-50 text-amber-600" aria-hidden="true">
                <i class="fas fa-triangle-exclamation text-lg"></i>
            </span>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Цена изменилась</h1>
        </div>

        <p class="text-gray-600 leading-relaxed mb-6">
            Промокод больше не применяется&nbsp;— {{ $reason }}. Оформить заказ можно
            по актуальной цене без скидки. Со счёта спишется ровно та сумма, которую
            вы видите ниже.
        </p>

        <div class="rounded-2xl bg-gray-50 border border-gray-100 p-5 mb-7">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Новая сумма к оплате</div>
            <div class="text-4xl font-black tracking-tight text-gray-900 tabular-nums">
                {{ number_format((float) $newTotal, 0, '.', ' ') }}<span class="ml-1.5 text-2xl">₽</span>
            </div>
        </div>

        <form action="{{ route('payment.create') }}" method="POST" class="space-y-4">
            @csrf
            @foreach ($carry as $key => $value)
                @if ($value !== null && $value !== '')
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            {{-- Pins the shown total so the charge can be asserted equal to it, and
                 marks this as the deliberate confirmation, not a first submit. --}}
            <input type="hidden" name="promo_lapse_confirmed" value="1">
            <input type="hidden" name="confirmed_total" value="{{ (int) round((float) $newTotal) }}">

            <button type="submit"
                    class="w-full inline-flex items-center justify-center rounded-full bg-gray-900 px-6 py-4 text-white font-bold text-base hover:bg-black transition">
                <i class="fas fa-lock mr-2.5 opacity-90"></i>
                Оплатить {{ number_format((float) $newTotal, 0, '.', ' ') }}&nbsp;₽
            </button>
        </form>

        <a href="{{ route('checkout.show', $tariff) }}"
           class="mt-4 block text-center text-sm font-semibold text-gray-500 hover:text-gray-700">
            Вернуться к оформлению
        </a>
    </div>
</div>
@endsection
